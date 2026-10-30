import type { ReactNode, SVGProps } from 'react';

import { bodySize, chairsOf, tableBox, type HallLayout, type HallTable, type Obstacle } from '@/lib/hall-layout';
import { useT } from '@/lib/i18n';
import { tableNumber } from '@/lib/seating/plan';
import { cn } from '@/lib/utils';

/**
 * SVG pieces of a hall shared by the planner (HallSvg) and the hall designer:
 * the floor (sections, kosha, dance floor, entrances, obstacles), table shapes
 * with their chairs, and text labels. Every shape of App\Services\Seating\HallLayoutData.
 */

const SECTION_STYLE: Record<string, string> = {
    men: 'fill-sky-50/70 stroke-sky-200',
    women: 'fill-rose-50/70 stroke-rose-200',
    vip: 'fill-amber-50/70 stroke-amber-300',
    mixed: 'fill-zinc-50/40 stroke-zinc-200',
};

export function sectionClass(id: string) {
    return SECTION_STYLE[id] ?? SECTION_STYLE.mixed;
}

/** Sections, kosha, dance floor, entrances and obstacles (non-interactive). */
export function HallFloor({ layout, showMixed = false }: { layout: HallLayout; showMixed?: boolean }) {
    const { t } = useT();
    const sectionLabel = (id: string, fallback: string) => (id === 'mixed' ? (showMixed ? t('seating.section.mixed') : '') : t(`seating.section.${id}`) || fallback);

    return (
        <>
            {layout.sections.map((s) =>
                s.id === 'mixed' && !showMixed ? null : (
                    <g key={s.id} data-section={s.id}>
                        <rect x={s.area.x} y={s.area.y} width={s.area.w} height={s.area.h} rx={10} className={sectionClass(s.id)} strokeDasharray="6 5" />
                        <text x={s.area.x + s.area.w / 2} y={s.area.y - 8} textAnchor="middle" className="fill-zinc-500 text-[13px] font-semibold">
                            {sectionLabel(s.id, s.label)}
                        </text>
                    </g>
                ),
            )}
            {layout.stage && <Stage rect={layout.stage} />}
            {layout.danceFloor && <DanceFloor rect={layout.danceFloor} />}
            {layout.entrances?.map((e, i) => <EntranceShape key={i} entrance={e} />)}
            {layout.obstacles?.map((o) => <ObstacleShape key={o.id} obstacle={o} />)}
        </>
    );
}

export function Stage({ rect, className }: { rect: { x: number; y: number; w: number; h: number }; className?: string }) {
    const { t } = useT();
    return (
        <g className={className}>
            <rect x={rect.x} y={rect.y} width={rect.w} height={rect.h} rx={10} className="fill-amber-100 stroke-amber-400" strokeWidth={1.5} />
            <text x={rect.x + rect.w / 2} y={rect.y + rect.h / 2 + 5} textAnchor="middle" className="pointer-events-none fill-amber-900 text-[15px] font-bold">
                {t('seating.hall.stage')}
            </text>
        </g>
    );
}

export function DanceFloor({ rect, labelled = false }: { rect: { x: number; y: number; w: number; h: number }; labelled?: boolean }) {
    const { t } = useT();
    return (
        <g>
            <rect x={rect.x} y={rect.y} width={rect.w} height={rect.h} rx={8} className="fill-none stroke-zinc-300" strokeDasharray="4 5" />
            {labelled && (
                <text x={rect.x + rect.w / 2} y={rect.y + rect.h / 2 + 4} textAnchor="middle" className="pointer-events-none fill-zinc-400 text-[12px]">
                    {t('halls.elements.dance')}
                </text>
            )}
        </g>
    );
}

export function EntranceShape({ entrance }: { entrance: HallLayout['entrances'][number] }) {
    const { t } = useT();
    const vertical = entrance.rotation === 90;
    return (
        <g>
            <rect x={entrance.x} y={entrance.y} width={vertical ? 10 : entrance.w} height={vertical ? entrance.w : 10} rx={4} className="fill-amber-400" />
            {vertical ? (
                <text x={entrance.x + 18} y={entrance.y + entrance.w / 2} textAnchor="middle" dominantBaseline="middle" className="pointer-events-none fill-zinc-500 text-[11px]" transform={`rotate(90 ${entrance.x + 18} ${entrance.y + entrance.w / 2})`}>
                    {t('seating.hall.entrance')}
                </text>
            ) : (
                <text x={entrance.x + entrance.w / 2} y={entrance.y - 5} textAnchor="middle" className="pointer-events-none fill-zinc-500 text-[11px]">
                    {t('seating.hall.entrance')}
                </text>
            )}
        </g>
    );
}

export function ObstacleShape({ obstacle: o }: { obstacle: Obstacle }) {
    const { t } = useT();
    const name = o.label ?? (o.kind === 'buffet' ? t('halls.elements.buffet') : '');
    return (
        <g data-obstacle={o.kind}>
            {o.kind === 'column' ? (
                <ellipse cx={o.x + o.w / 2} cy={o.y + o.h / 2} rx={o.w / 2} ry={o.h / 2} className="fill-zinc-300 stroke-zinc-400" />
            ) : (
                <rect x={o.x} y={o.y} width={o.w} height={o.h} rx={4} className={o.kind === 'buffet' ? 'fill-orange-50 stroke-orange-300' : 'fill-zinc-200 stroke-zinc-400'} strokeDasharray={o.kind === 'obstacle' ? '3 3' : undefined} />
            )}
            {name && (
                <text x={o.x + o.w / 2} y={o.y + o.h / 2 + 4} textAnchor="middle" className="pointer-events-none fill-zinc-600 text-[11px]">
                    {name}
                </text>
            )}
        </g>
    );
}

export function HallLabels({ layout }: { layout: HallLayout }) {
    return (
        <>
            {layout.labels?.map((l) => (
                <text key={l.id} x={l.x} y={l.y} textAnchor="middle" dominantBaseline="middle" className="pointer-events-none fill-zinc-600 font-semibold" style={{ fontSize: l.size }} data-label={l.id}>
                    {l.text}
                </text>
            ))}
        </>
    );
}

export interface ChairLook {
    fill?: string;
    stroke?: string;
    strokeWidth?: number;
    title?: string;
    props?: SVGProps<SVGCircleElement>;
}

/**
 * A table of any shape with its chairs and number. `chair(i)` styles chair i;
 * `tone` colours the table top (empty, used, full, flagged).
 */
export function TableShape({
    table,
    chair,
    tone = 'empty',
    taken,
    showCount = true,
    halo,
}: {
    table: HallTable;
    chair?: (i: number) => ChairLook;
    tone?: 'empty' | 'used' | 'full' | 'flagged';
    taken?: number;
    showCount?: boolean;
    /** a highlight around the table: selected (primary), hinted (green), drop target */
    halo?: 'selected' | 'hinted' | 'over' | null;
}) {
    const { n } = useT();
    const chairs = chairsOf(table);
    const [bw, bh] = bodySize(table);
    const topClass = cn(
        't-top',
        tone === 'flagged' ? 'fill-red-50 stroke-red-500' : tone === 'full' ? 'fill-amber-50 stroke-amber-500' : tone === 'used' ? 'fill-rose-50 stroke-rose-300' : table.kids ? 'fill-emerald-50 stroke-emerald-400' : 'fill-zinc-50 stroke-zinc-300',
    );
    const strokeWidth = tone === 'flagged' ? 2.5 : 1.5;
    const fontSize = table.shape === 'round' ? Math.max(9, Math.min(24, table.r * 0.95)) : Math.max(9, Math.min(20, Math.min(bw, bh) * 0.7));
    const turned = table.shape !== 'round' && table.rotation === 90;
    const textColor = tone === 'flagged' ? 'fill-red-700' : 'fill-zinc-800';

    let body: ReactNode = null;
    if (table.shape === 'round') {
        body = <circle cx={table.x} cy={table.y} r={table.r} className={topClass} strokeWidth={strokeWidth} />;
    } else if (table.shape === 'rect') {
        body = <rect x={table.x - bw / 2} y={table.y - bh / 2} width={bw} height={bh} rx={3} className={topClass} strokeWidth={strokeWidth} />;
    } else if (table.shape === 'sofa') {
        body = (
            <>
                <rect x={table.x - bw / 2} y={table.y - bh / 2} width={bw} height={bh} rx={Math.min(8, Math.min(bw, bh) / 3)} className={topClass} strokeWidth={strokeWidth} />
                {turned ? (
                    <rect x={table.x + bw / 2 - bw * 0.28} y={table.y - bh / 2} width={bw * 0.28} height={bh} rx={3} className="fill-zinc-200/80" />
                ) : (
                    <rect x={table.x - bw / 2} y={table.y - bh / 2} width={bw} height={bh * 0.28} rx={3} className="fill-zinc-200/80" />
                )}
            </>
        );
    }

    let number: ReactNode;
    if (table.shape === 'row') {
        const first = chairs[0] ?? { x: table.x, y: table.y };
        number = (
            <text
                x={turned ? table.x : first.x - table.chairR - 9}
                y={turned ? first.y - table.chairR - 7 : table.y}
                textAnchor="middle"
                dominantBaseline="middle"
                className={cn('pointer-events-none text-[11px] font-bold', textColor)}
            >
                {n(tableNumber(table.id))}
            </text>
        );
    } else {
        number = (
            <text x={table.x} y={table.y + fontSize * 0.1} textAnchor="middle" dominantBaseline="middle" className={cn('pointer-events-none font-bold', textColor)} style={{ fontSize }}>
                {n(tableNumber(table.id))}
            </text>
        );
    }

    const box = tableBox(table);
    const showSub = showCount && taken !== undefined && (table.shape === 'round' ? table.r >= 16 : table.shape === 'rect' && Math.min(bw, bh) >= 24);

    return (
        <>
            {halo && <TableHalo table={table} kind={halo} />}
            {table.shape === 'sofa' && body}
            {chairs.map((c, i) => {
                const look = chair?.(i) ?? {};
                return (
                    <circle
                        key={i}
                        cx={c.x}
                        cy={c.y}
                        r={table.shape === 'sofa' ? table.chairR * 0.8 : table.chairR}
                        fill={look.fill ?? '#ffffff'}
                        stroke={look.stroke ?? '#b4a7ac'}
                        strokeWidth={look.strokeWidth ?? 1.2}
                        data-chair={i}
                        {...look.props}
                    >
                        {look.title && <title>{look.title}</title>}
                    </circle>
                );
            })}
            {table.shape !== 'sofa' && body}
            {number}
            {showSub && (
                <text x={table.x} y={table.y + fontSize * 0.85} textAnchor="middle" dominantBaseline="middle" className="pointer-events-none fill-zinc-500" style={{ fontSize: Math.max(7, fontSize * 0.45) }}>
                    {n(taken ?? 0)}/{n(table.seats)}
                </text>
            )}
            {table.kids && <KidsBadge table={table} />}
            {table.label && (
                <text x={table.x} y={box.y + box.h + 10} textAnchor="middle" className="pointer-events-none fill-zinc-500 text-[10px]">
                    {table.label}
                </text>
            )}
        </>
    );
}

/** A highlight around a table: selected (primary), hinted (green) or a drop target (dashed). */
export function TableHalo({ table, kind }: { table: HallTable; kind: 'selected' | 'hinted' | 'over' }) {
    const cls = kind === 'hinted' ? 'fill-emerald-500/5 stroke-emerald-500' : kind === 'over' ? 'fill-primary/10 stroke-primary' : 'fill-primary/5 stroke-primary';
    const dash = kind === 'over' ? '5 4' : undefined;
    if (table.shape === 'round') {
        return <circle cx={table.x} cy={table.y} r={table.ring + table.chairR + (kind === 'over' ? 6 : 4)} className={cls} strokeWidth={2} strokeDasharray={dash} />;
    }
    const box = tableBox(table);
    return <rect x={box.x - 4} y={box.y - 4} width={box.w + 8} height={box.h + 8} rx={8} className={cls} strokeWidth={2} strokeDasharray={dash} />;
}

function KidsBadge({ table }: { table: HallTable }) {
    const box = tableBox(table);
    const x = box.x + box.w - 2;
    const y = box.y + 2;
    return (
        <g className="pointer-events-none" data-kids>
            <circle cx={x} cy={y} r={7} className="fill-emerald-500" />
            <text x={x} y={y + 0.5} textAnchor="middle" dominantBaseline="middle" className="fill-white text-[9px] font-bold">
                ★
            </text>
        </g>
    );
}
