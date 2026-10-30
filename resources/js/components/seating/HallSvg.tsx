import { useDroppable } from '@dnd-kit/core';
import { memo, useRef, type KeyboardEvent, type PointerEvent as ReactPointerEvent, type ReactNode } from 'react';

import { HallFloor, HallLabels, TableHalo, TableShape } from '@/components/halls/shapes';
import { type HallLayout, type HallTable } from '@/lib/hall-layout';
import { useT } from '@/lib/i18n';
import { seatKey, tableNumber, type Assignment } from '@/lib/seating/plan';
import { cn } from '@/lib/utils';

/** Distinct, print-friendly colours for families on the hall. */
const PALETTE = ['#A8325A', '#2F6F9F', '#3C8D5A', '#C27C0E', '#7B4FA6', '#1F8A8A', '#B5523B', '#5A6B2E', '#C2437E', '#44619E'];

export const familyColor = (index: number) => PALETTE[((index % PALETTE.length) + PALETTE.length) % PALETTE.length];

export interface HallSvgProps {
    layout: HallLayout;
    mode: 'view' | 'seat' | 'edit';
    /** seatKey(table, seat) → assignment */
    seats?: Map<string, Assignment>;
    families?: Map<string, { name: string; color: string }>;
    selectedSeat?: { table: string; seat: number } | null;
    selectedTable?: string | null;
    /** tables to flag (warnings) */
    flagged?: Set<string>;
    /** tables to emphasise (e.g. where the selected family sits, or where it fits) */
    hinted?: Set<string>;
    onChairClick?: (table: string, seat: number) => void;
    onTableClick?: (table: string) => void;
    onTableMove?: (table: string, x: number, y: number) => void;
    onTableNudge?: (table: string, dx: number, dy: number) => void;
    className?: string;
    label?: string;
}

/**
 * The hall (plan section 7.1) as SVG. In "seat" mode every table is a drop
 * target for families (dnd-kit) and chairs are clickable; in "edit" mode
 * tables are dragged with the pointer or nudged with the arrow keys.
 */
export const HallSvg = memo(function HallSvg(props: HallSvgProps) {
    const { layout, mode } = props;
    const svgRef = useRef<SVGSVGElement>(null);
    const drag = useRef<{ id: string; dx: number; dy: number; pointer: number } | null>(null);

    const toHall = (clientX: number, clientY: number) => {
        const svg = svgRef.current;
        const matrix = svg?.getScreenCTM();
        if (!svg || !matrix) return { x: 0, y: 0 };
        const point = new DOMPoint(clientX, clientY).matrixTransform(matrix.inverse());
        return { x: point.x, y: point.y };
    };

    const onPointerDown = (table: HallTable) => (e: ReactPointerEvent<SVGGElement>) => {
        if (mode !== 'edit' || e.button !== 0) return;
        const p = toHall(e.clientX, e.clientY);
        drag.current = { id: table.id, dx: table.x - p.x, dy: table.y - p.y, pointer: e.pointerId };
        (e.currentTarget as Element).setPointerCapture(e.pointerId);
        props.onTableClick?.(table.id);
    };
    const onPointerMove = (e: ReactPointerEvent<SVGGElement>) => {
        const d = drag.current;
        if (!d || d.pointer !== e.pointerId) return;
        const p = toHall(e.clientX, e.clientY);
        props.onTableMove?.(d.id, p.x + d.dx, p.y + d.dy);
    };
    const onPointerUp = (e: ReactPointerEvent<SVGGElement>) => {
        if (drag.current?.pointer === e.pointerId) drag.current = null;
    };

    const onTableKey = (table: HallTable) => (e: KeyboardEvent<SVGGElement>) => {
        if (mode === 'edit') {
            const step = e.shiftKey ? 20 : 5;
            const moves: Record<string, [number, number]> = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
            const move = moves[e.key];
            if (move) {
                e.preventDefault();
                props.onTableNudge?.(table.id, move[0], move[1]);
                return;
            }
        }
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            props.onTableClick?.(table.id);
        }
    };

    return (
        <svg
            ref={svgRef}
            viewBox={`0 0 ${layout.size.w} ${layout.size.h}`}
            className={cn('block h-auto w-full touch-manipulation select-none', props.className)}
            role="group"
            aria-label={props.label ?? layout.name}
            data-hall
            data-mode={mode}
        >
            <rect x={1} y={1} width={layout.size.w - 2} height={layout.size.h - 2} rx={14} className="fill-white stroke-zinc-300" strokeWidth={2} />
            <HallFloor layout={layout} />
            {layout.tables.map((table) => {
                const body = (
                    <TableBody
                        table={table}
                        mode={mode}
                        seats={props.seats}
                        families={props.families}
                        selectedSeat={props.selectedSeat}
                        selected={props.selectedTable === table.id}
                        flagged={props.flagged?.has(table.id) ?? false}
                        hinted={props.hinted?.has(table.id) ?? false}
                        onChairClick={props.onChairClick}
                    />
                );
                const common = {
                    table,
                    mode,
                    onKeyDown: onTableKey(table),
                    onClick: mode === 'edit' ? undefined : () => props.onTableClick?.(table.id),
                    onPointerDown: onPointerDown(table),
                    onPointerMove,
                    onPointerUp,
                    seats: props.seats,
                };
                return mode === 'seat' ? (
                    <DroppableTable key={table.id} {...common}>
                        {body}
                    </DroppableTable>
                ) : (
                    <PlainTable key={table.id} {...common}>
                        {body}
                    </PlainTable>
                );
            })}
            <HallLabels layout={layout} />
        </svg>
    );
});

interface TableGroupProps {
    table: HallTable;
    mode: HallSvgProps['mode'];
    seats?: Map<string, Assignment>;
    onKeyDown: (e: KeyboardEvent<SVGGElement>) => void;
    onClick?: () => void;
    onPointerDown: (e: ReactPointerEvent<SVGGElement>) => void;
    onPointerMove: (e: ReactPointerEvent<SVGGElement>) => void;
    onPointerUp: (e: ReactPointerEvent<SVGGElement>) => void;
    children: ReactNode;
}

function useTableLabel(table: HallTable, seats?: Map<string, Assignment>) {
    const { t, n } = useT();
    let taken = 0;
    for (let i = 0; i < table.seats; i++) if (seats?.has(seatKey(table.id, i))) taken++;
    return `${t('seating.table.label', { n: n(tableNumber(table.id)) })}، ${n(taken)}/${n(table.seats)}`;
}

function PlainTable({ table, mode, seats, onKeyDown, onClick, onPointerDown, onPointerMove, onPointerUp, children }: TableGroupProps) {
    const label = useTableLabel(table, seats);
    return (
        <g
            tabIndex={mode === 'view' ? -1 : 0}
            role={mode === 'view' ? undefined : 'button'}
            aria-label={label}
            className={cn('outline-none', mode === 'edit' && 'cursor-grab active:cursor-grabbing')}
            onKeyDown={onKeyDown}
            onClick={onClick}
            onPointerDown={onPointerDown}
            onPointerMove={onPointerMove}
            onPointerUp={onPointerUp}
            onPointerCancel={onPointerUp}
            data-table={table.id}
        >
            {children}
        </g>
    );
}

function DroppableTable({ table, seats, onKeyDown, onClick, children }: TableGroupProps) {
    const { setNodeRef, isOver } = useDroppable({ id: `table:${table.id}`, data: { table: table.id } });
    const label = useTableLabel(table, seats);
    return (
        <g
            ref={setNodeRef as unknown as (el: SVGGElement | null) => void}
            tabIndex={0}
            role="button"
            aria-label={label}
            className={cn('cursor-pointer outline-none', isOver && '[&_.t-top]:stroke-primary [&_.t-top]:stroke-[4]')}
            onKeyDown={onKeyDown}
            onClick={onClick}
            data-table={table.id}
            data-over={isOver || undefined}
        >
            {isOver && <TableHalo table={table} kind="over" />}
            {children}
        </g>
    );
}

function TableBody({
    table,
    mode,
    seats,
    families,
    selectedSeat,
    selected,
    flagged,
    hinted,
    onChairClick,
}: {
    table: HallTable;
    mode: HallSvgProps['mode'];
    seats?: Map<string, Assignment>;
    families?: Map<string, { name: string; color: string }>;
    selectedSeat?: { table: string; seat: number } | null;
    selected: boolean;
    flagged: boolean;
    hinted: boolean;
    onChairClick?: (table: string, seat: number) => void;
}) {
    let taken = 0;
    for (let i = 0; i < table.seats; i++) if (seats?.has(seatKey(table.id, i))) taken++;
    const full = taken >= table.seats;

    return (
        <TableShape
            table={table}
            taken={taken}
            tone={flagged ? 'flagged' : full ? 'full' : taken > 0 ? 'used' : 'empty'}
            halo={selected ? 'selected' : hinted ? 'hinted' : null}
            chair={(i) => {
                const a = seats?.get(seatKey(table.id, i));
                const fam = a ? families?.get(a.household_id) : undefined;
                const isSelected = selectedSeat?.table === table.id && selectedSeat.seat === i;
                return {
                    fill: fam?.color ?? '#ffffff',
                    stroke: isSelected ? '#111827' : fam ? fam.color : '#b4a7ac',
                    strokeWidth: isSelected ? 3 : 1.2,
                    title: fam?.name,
                    props: {
                        className: cn(mode === 'seat' && 'cursor-pointer hover:stroke-zinc-900'),
                        onClick:
                            mode === 'seat'
                                ? (e) => {
                                      e.stopPropagation();
                                      onChairClick?.(table.id, i);
                                  }
                                : undefined,
                        'data-taken': a ? a.household_id : undefined,
                    },
                };
            }}
        />
    );
}
