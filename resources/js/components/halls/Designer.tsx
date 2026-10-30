import {
    Armchair,
    Circle,
    Columns3,
    Copy,
    DoorOpen,
    Grid3x3,
    Hand,
    ListOrdered,
    Magnet,
    Maximize,
    Minus,
    Music,
    Plus,
    Redo2,
    RectangleHorizontal,
    RotateCw,
    Rows3,
    Shapes,
    Smile,
    SquareDashed,
    Trash2,
    TriangleAlert,
    Type,
    Undo2,
    UtensilsCrossed,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode, type PointerEvent as ReactPointerEvent } from 'react';

import { DanceFloor, EntranceShape, ObstacleShape, sectionClass, Stage, TableShape } from '@/components/halls/shapes';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Switch } from '@/components/ui/switch';
import type { HallLayout, HallTable, ObstacleKind, Rect, SectionId } from '@/lib/hall-layout';
import {
    addDance,
    addEntrance,
    addLabel,
    addObstacle,
    addSection,
    addStage,
    addTable,
    allRefs,
    applyRenames,
    deleteElements,
    duplicateElements,
    duplicateIds,
    elementBox,
    moveElements,
    overlaps as findOverlaps,
    refKey,
    refsInRect,
    renumberTables,
    resizeElement,
    resizeHall,
    rotateElements,
    sameRef,
    totals as hallTotals,
    updateTable,
    type NewTableKind,
    type Ref,
    type TablePatch,
} from '@/lib/halls/designer';
import { useT } from '@/lib/i18n';
import { tableNumber } from '@/lib/seating/plan';
import { cn } from '@/lib/utils';

/** What the designer edits: the layout and, per current table id, the id it had when loaded (for renames on save). */
export interface DesignerState {
    layout: HallLayout;
    origin: Record<string, string>;
}

interface History {
    past: DesignerState[];
    present: DesignerState;
    future: DesignerState[];
}

type Drag =
    | { mode: 'move'; pointer: number; start: { x: number; y: number }; base: DesignerState; refs: Ref[]; moved: boolean }
    | { mode: 'resize'; pointer: number; start: { x: number; y: number }; base: DesignerState; ref: Ref; box: Rect; moved: boolean }
    | { mode: 'pan'; pointer: number; client: { x: number; y: number }; view: Rect }
    | { mode: 'marquee'; pointer: number; start: { x: number; y: number }; additive: boolean };

const HISTORY_LIMIT = 100;
const SECTION_IDS: SectionId[] = ['men', 'women', 'mixed', 'vip'];

const isTyping = (target: EventTarget | null) => target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));

/**
 * The hall designer (plan section 5, V3 "Hall designer"): round and banquet
 * tables, sofas, VIP rows, kids tables, the kosha, dance floor, entrances,
 * columns, buffets, obstacles, text labels and sections. Drag, rotate, resize,
 * duplicate, delete, multi-select, snap, undo/redo, zoom/pan and keyboard
 * editing, with an inspector for the selected element and overlap checks.
 * The layout is checked again on the server when it is saved.
 */
export function HallDesigner({ initial, onChange, toolbarEnd }: { initial: HallLayout; onChange: (state: DesignerState, renames: Record<string, string>) => void; toolbarEnd?: ReactNode }) {
    const { t, tc, n, dir, locale } = useT();
    const [history, setHistory] = useState<History>(() => ({
        past: [],
        present: { layout: initial, origin: Object.fromEntries(initial.tables.map((tb) => [tb.id, tb.id])) },
        future: [],
    }));
    const { layout } = history.present;
    const [selection, setSelection] = useState<Ref[]>([]);
    const [snap, setSnap] = useState(true);
    const [showGrid, setShowGrid] = useState(true);
    const [grid] = useState(10);
    const [panMode, setPanMode] = useState(false);
    const [rtl, setRtl] = useState(dir === 'rtl');
    const [view, setView] = useState<Rect>({ x: -20, y: -20, w: initial.size.w + 40, h: initial.size.h + 40 });
    const [marquee, setMarquee] = useState<Rect | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const svgRef = useRef<SVGSVGElement>(null);
    const drag = useRef<Drag | null>(null);
    const spaceDown = useRef(false);
    // focus that comes from a click must not reset a Shift+click selection
    const pointerFocus = useRef(false);

    // tell the page (save button, unsaved state)
    const onChangeRef = useRef(onChange);
    onChangeRef.current = onChange;
    useEffect(() => {
        const renames: Record<string, string> = {};
        for (const [current, original] of Object.entries(history.present.origin)) if (current !== original) renames[original] = current;
        onChangeRef.current(history.present, renames);
    }, [history.present]);

    const commit = useCallback((next: DesignerState | ((s: DesignerState) => DesignerState), base?: DesignerState) => {
        setHistory((h) => {
            const value = typeof next === 'function' ? next(h.present) : next;
            if (value.layout === h.present.layout && value.origin === h.present.origin && !base) return h;
            return { past: [...h.past, base ?? h.present].slice(-HISTORY_LIMIT), present: value, future: [] };
        });
    }, []);
    /** Live change while dragging: shown at once, committed on pointer up. */
    const preview = useCallback((value: DesignerState) => setHistory((h) => ({ ...h, present: value })), []);

    const setLayout = useCallback((fn: (l: HallLayout) => HallLayout) => commit((s) => ({ ...s, layout: fn(s.layout) })), [commit]);

    const undo = useCallback(() => {
        setHistory((h) => (h.past.length ? { past: h.past.slice(0, -1), present: h.past[h.past.length - 1], future: [h.present, ...h.future] } : h));
    }, []);
    const redo = useCallback(() => {
        setHistory((h) => (h.future.length ? { past: [...h.past, h.present], present: h.future[0], future: h.future.slice(1) } : h));
    }, []);

    // keep the selection pointing at things that exist (after undo, delete …)
    useEffect(() => {
        setSelection((sel) => {
            const known = new Set(allRefs(layout).map(refKey));
            const kept = sel.filter((r) => known.has(refKey(r)));
            return kept.length === sel.length ? sel : kept;
        });
    }, [layout]);

    const toHall = (clientX: number, clientY: number) => {
        const matrix = svgRef.current?.getScreenCTM();
        if (!matrix) return { x: 0, y: 0 };
        const p = new DOMPoint(clientX, clientY).matrixTransform(matrix.inverse());
        return { x: p.x, y: p.y };
    };

    const viewCentre = () => ({ x: Math.min(layout.size.w - 40, Math.max(40, view.x + view.w / 2)), y: Math.min(layout.size.h - 40, Math.max(40, view.y + view.h / 2)) });

    // ── adding ─────────────────────────────────────────────────────────────
    const add = (make: (l: HallLayout, x: number, y: number) => { layout: HallLayout; ref: Ref } | null) => {
        const c = viewCentre();
        const result = make(layout, c.x, c.y);
        if (!result) return;
        commit((s) => ({ ...s, layout: result.layout }));
        setSelection([result.ref]);
    };
    const addTableKind = (kind: NewTableKind) => add((l, x, y) => addTable(l, kind, x, y));
    const sectionLabels = Object.fromEntries(SECTION_IDS.map((id) => [id, t(`seating.section.${id}`)])) as Record<SectionId, string>;

    // ── editing the selection ──────────────────────────────────────────────
    const removeSelected = useCallback(() => {
        if (!selection.length) return;
        commit((s) => {
            const next = deleteElements(s.layout, selection);
            const ids = new Set(next.tables.map((tb) => tb.id));
            return { layout: next, origin: Object.fromEntries(Object.entries(s.origin).filter(([id]) => ids.has(id))) };
        });
        setSelection([]);
    }, [selection, commit]);

    const duplicateSelected = useCallback(() => {
        if (!selection.length) return;
        const result = duplicateElements(layout, selection);
        if (!result.refs.length) return;
        commit((s) => ({ ...s, layout: result.layout }));
        setSelection(result.refs);
    }, [layout, selection, commit]);

    const rotateSelected = useCallback(() => {
        if (selection.length) setLayout((l) => rotateElements(l, selection));
    }, [selection, setLayout]);

    const nudge = useCallback(
        (dx: number, dy: number) => {
            if (selection.length) setLayout((l) => moveElements(l, selection, dx, dy));
        },
        [selection, setLayout],
    );

    const renumber = () => {
        const { layout: next, renames } = renumberTables(layout, { rtl });
        commit((s) => ({ layout: next, origin: applyRenames(s.origin, renames) }));
        setSelection((sel) => sel.map((r) => (r.kind === 'table' && renames[r.id] ? { ...r, id: renames[r.id] } : r)));
        flash(t('halls.designer.renumber_done'));
    };

    const patchTables = (ids: string[], patch: TablePatch): boolean => {
        let next = layout;
        for (const id of ids) {
            const changed = updateTable(next, id, patch);
            if (!changed) return false;
            next = changed;
        }
        commit((s) => {
            let origin = s.origin;
            if (patch.id !== undefined && ids.length === 1 && patch.id !== ids[0]) {
                origin = applyRenames(origin, { [ids[0]]: patch.id });
            }
            return { layout: next, origin };
        });
        if (patch.id !== undefined && ids.length === 1) setSelection([{ kind: 'table', id: patch.id }]);
        return true;
    };

    const flash = (text: string) => {
        setNotice(text);
        window.setTimeout(() => setNotice((v) => (v === text ? null : v)), 2500);
    };

    // ── zoom and pan ───────────────────────────────────────────────────────
    const zoomBy = useCallback(
        (factor: number, around?: { x: number; y: number }) => {
            setView((v) => {
                const w = Math.min(layout.size.w * 3, Math.max(layout.size.w / 8, v.w / factor));
                const h = (w * v.h) / v.w;
                const c = around ?? { x: v.x + v.w / 2, y: v.y + v.h / 2 };
                return { x: c.x - ((c.x - v.x) * w) / v.w, y: c.y - ((c.y - v.y) * h) / v.h, w, h };
            });
        },
        [layout.size.w],
    );
    const fit = useCallback(() => setView({ x: -20, y: -20, w: layout.size.w + 40, h: layout.size.h + 40 }), [layout.size.w, layout.size.h]);
    const zoom = Math.round(((layout.size.w + 40) / view.w) * 100);

    useEffect(() => {
        const svg = svgRef.current;
        if (!svg) return;
        const onWheel = (e: WheelEvent) => {
            e.preventDefault();
            if (e.ctrlKey || e.metaKey) {
                zoomBy(e.deltaY < 0 ? 1.15 : 1 / 1.15, toHall(e.clientX, e.clientY));
            } else {
                const scale = view.w / svg.clientWidth;
                setView((v) => ({ ...v, x: v.x + e.deltaX * scale, y: v.y + e.deltaY * scale }));
            }
        };
        svg.addEventListener('wheel', onWheel, { passive: false });
        return () => svg.removeEventListener('wheel', onWheel);
    });

    // ── keyboard ───────────────────────────────────────────────────────────
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (isTyping(e.target)) return;
            const mod = e.ctrlKey || e.metaKey;
            const key = e.key.toLowerCase();
            if (e.key === ' ') {
                spaceDown.current = true;
                if (e.target === document.body || svgRef.current?.contains(e.target as Node)) e.preventDefault();
                return;
            }
            if (mod && key === 'z') {
                e.preventDefault();
                if (e.shiftKey) redo();
                else undo();
            } else if (mod && key === 'y') {
                e.preventDefault();
                redo();
            } else if (mod && key === 'd') {
                e.preventDefault();
                duplicateSelected();
            } else if (mod && key === 'a') {
                e.preventDefault();
                setSelection(layout.tables.map((tb) => ({ kind: 'table', id: tb.id })));
            } else if (e.key === 'Delete' || e.key === 'Backspace') {
                if (selection.length) {
                    e.preventDefault();
                    removeSelected();
                }
            } else if (e.key === 'Escape') {
                setSelection([]);
            } else if (!mod && key === 'r' && selection.length) {
                e.preventDefault();
                rotateSelected();
            } else if (e.key.startsWith('Arrow') && selection.length) {
                e.preventDefault();
                const step = e.shiftKey ? grid * 5 : e.altKey ? 1 : snap ? grid : 5;
                // the arrow keys move things where they point, in both languages
                const moves: Record<string, [number, number]> = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
                const m = moves[e.key];
                if (m) nudge(m[0], m[1]);
            }
        };
        const onKeyUp = (e: KeyboardEvent) => {
            if (e.key === ' ') spaceDown.current = false;
        };
        window.addEventListener('keydown', onKey);
        window.addEventListener('keyup', onKeyUp);
        return () => {
            window.removeEventListener('keydown', onKey);
            window.removeEventListener('keyup', onKeyUp);
        };
    }, [undo, redo, duplicateSelected, removeSelected, rotateSelected, nudge, selection, layout.tables, grid, snap]);

    // ── pointer ────────────────────────────────────────────────────────────
    const startMove = (ref: Ref) => (e: ReactPointerEvent) => {
        if (e.button !== 0 || panMode || spaceDown.current) return;
        e.stopPropagation();
        pointerFocus.current = true;
        window.setTimeout(() => (pointerFocus.current = false), 0);
        let refs = selection;
        const already = selection.some((r) => sameRef(r, ref));
        if (e.shiftKey || e.ctrlKey || e.metaKey) {
            refs = already ? selection.filter((r) => !sameRef(r, ref)) : [...selection, ref];
            setSelection(refs);
            if (already) return;
        } else if (!already) {
            refs = [ref];
            setSelection(refs);
        } else {
            // the grabbed element leads, so it is the one that lands on the grid
            refs = [ref, ...selection.filter((r) => !sameRef(r, ref))];
        }
        svgRef.current?.setPointerCapture(e.pointerId);
        drag.current = { mode: 'move', pointer: e.pointerId, start: toHall(e.clientX, e.clientY), base: history.present, refs, moved: false };
    };

    const startResize = (ref: Ref, box: Rect) => (e: ReactPointerEvent) => {
        if (e.button !== 0) return;
        e.stopPropagation();
        svgRef.current?.setPointerCapture(e.pointerId);
        drag.current = { mode: 'resize', pointer: e.pointerId, start: toHall(e.clientX, e.clientY), base: history.present, ref, box, moved: false };
    };

    const onBackgroundDown = (e: ReactPointerEvent<SVGSVGElement>) => {
        svgRef.current?.focus({ preventScroll: true });
        if (panMode || spaceDown.current || e.button === 1) {
            svgRef.current?.setPointerCapture(e.pointerId);
            drag.current = { mode: 'pan', pointer: e.pointerId, client: { x: e.clientX, y: e.clientY }, view };
            return;
        }
        if (e.button !== 0) return;
        svgRef.current?.setPointerCapture(e.pointerId);
        const additive = e.shiftKey || e.ctrlKey || e.metaKey;
        if (!additive) setSelection([]);
        drag.current = { mode: 'marquee', pointer: e.pointerId, start: toHall(e.clientX, e.clientY), additive };
    };

    const onPointerMove = (e: ReactPointerEvent<SVGSVGElement>) => {
        const d = drag.current;
        if (!d || d.pointer !== e.pointerId) return;
        if (d.mode === 'pan') {
            const svg = svgRef.current!;
            const scale = d.view.w / svg.clientWidth;
            setView({ ...d.view, x: d.view.x - (e.clientX - d.client.x) * scale, y: d.view.y - (e.clientY - d.client.y) * scale });
            return;
        }
        const p = toHall(e.clientX, e.clientY);
        if (d.mode === 'marquee') {
            setMarquee({ x: Math.min(d.start.x, p.x), y: Math.min(d.start.y, p.y), w: Math.abs(p.x - d.start.x), h: Math.abs(p.y - d.start.y) });
            return;
        }
        const dx = p.x - d.start.x;
        const dy = p.y - d.start.y;
        if (!d.moved && Math.hypot(dx, dy) < 2) return;
        d.moved = true;
        if (d.mode === 'move') {
            preview({ ...d.base, layout: moveElements(d.base.layout, d.refs, dx, dy, snap ? grid : 0) });
        } else {
            const snapV = (v: number) => (snap ? Math.max(grid, Math.round(v / grid) * grid) : v);
            const table = d.ref.kind === 'table' ? d.base.layout.tables.find((tb) => tb.id === d.ref.id) : undefined;
            if (table && table.shape !== 'round') {
                // a long table's size is its length × depth before rotation; chairs stick out of the box
                const turned = table.rotation === 90;
                const len = (turned ? table.h : table.w) + (turned ? dy : dx) * 2;
                const dep = (turned ? table.w : table.h) + (turned ? dx : dy) * 2;
                preview({ ...d.base, layout: resizeElement(d.base.layout, d.ref, snapV(turned ? dep : len), snapV(turned ? len : dep)) });
            } else if (table) {
                preview({ ...d.base, layout: resizeElement(d.base.layout, d.ref, snapV(table.r * 2 + Math.max(dx, dy) * 2), 0) });
            } else {
                preview({ ...d.base, layout: resizeElement(d.base.layout, d.ref, snapV(d.box.w + dx), snapV(d.box.h + dy)) });
            }
        }
    };

    const onPointerUp = (e: ReactPointerEvent<SVGSVGElement>) => {
        const d = drag.current;
        if (!d || d.pointer !== e.pointerId) return;
        drag.current = null;
        if (d.mode === 'marquee') {
            if (marquee && marquee.w > 3 && marquee.h > 3) {
                const caught = refsInRect(layout, marquee);
                setSelection((sel) => (d.additive ? [...sel, ...caught.filter((r) => !sel.some((s) => sameRef(s, r)))] : caught));
            }
            setMarquee(null);
            return;
        }
        if ((d.mode === 'move' || d.mode === 'resize') && d.moved) commit(history.present, d.base);
    };

    // ── derived ────────────────────────────────────────────────────────────
    const overlapList = useMemo(() => findOverlaps(layout), [layout]);
    const dupes = useMemo(() => duplicateIds(layout), [layout]);
    const sums = hallTotals(layout);
    const overlapping = useMemo(() => new Set(overlapList.flatMap((o) => [refKey(o.a), refKey(o.b)])), [overlapList]);
    const selectedKeys = new Set(selection.map(refKey));
    const single = selection.length === 1 ? selection[0] : null;
    const singleBox = single ? elementBox(layout, single) : null;
    const scale = view.w / Math.max(1, svgRef.current?.clientWidth ?? 900);
    const handle = Math.max(6, 9 * scale);

    const describe = (r: Ref) =>
        r.kind === 'table' ? t('halls.checks.thing.table', { n: n(tableNumber(r.id)) }) : r.kind === 'obstacle' ? t('halls.checks.thing.obstacle') : r.kind === 'stage' ? t('halls.checks.thing.stage') : t('halls.checks.thing.dance');

    const elementProps = (ref: Ref, label: string) => ({
        onPointerDown: startMove(ref),
        tabIndex: 0,
        role: 'button' as const,
        'aria-label': label,
        'aria-pressed': selectedKeys.has(refKey(ref)),
        onFocus: () => {
            if (!selectedKeys.has(refKey(ref)) && !drag.current && !pointerFocus.current) setSelection([ref]);
        },
        className: cn('outline-none', panMode ? 'cursor-grab' : 'cursor-move'),
        'data-element': refKey(ref),
    });

    return (
        <div className="grid gap-3" data-designer>
            {/* toolbar */}
            <div className="flex flex-wrap items-center gap-1.5 rounded-xl border bg-white p-2" role="toolbar" aria-label={t('halls.designer.title')}>
                <ToolGroup label={t('halls.designer.tables')}>
                    <Tool icon={<Circle />} label={t('halls.elements.round')} onClick={() => addTableKind('round')} data="round" />
                    <Tool icon={<RectangleHorizontal />} label={t('halls.elements.rect')} onClick={() => addTableKind('rect')} data="rect" />
                    <Tool icon={<Armchair />} label={t('halls.elements.sofa')} onClick={() => addTableKind('sofa')} data="sofa" />
                    <Tool icon={<Rows3 />} label={t('halls.elements.row')} onClick={() => addTableKind('row')} data="row" />
                    <Tool icon={<Smile />} label={t('halls.elements.kids')} onClick={() => addTableKind('kids')} data="kids" />
                </ToolGroup>
                <ToolGroup label={t('halls.designer.room')}>
                    <Tool icon={<Shapes />} label={t('halls.elements.stage')} onClick={() => add((l) => addStage(l))} disabled={!!layout.stage} data="stage" />
                    <Tool icon={<Music />} label={t('halls.elements.dance')} onClick={() => add((l) => addDance(l))} disabled={!!layout.danceFloor} data="dance" />
                    <Tool icon={<DoorOpen />} label={t('halls.elements.entrance')} onClick={() => add((l) => addEntrance(l))} disabled={layout.entrances.length >= 4} data="entrance" />
                    <Tool icon={<Columns3 />} label={t('halls.elements.column')} onClick={() => add((l, x, y) => addObstacle(l, 'column', x, y))} data="column" />
                    <Tool icon={<UtensilsCrossed />} label={t('halls.elements.buffet')} onClick={() => add((l, x, y) => addObstacle(l, 'buffet', x, y))} data="buffet" />
                    <Tool icon={<TriangleAlert />} label={t('halls.elements.obstacle')} onClick={() => add((l, x, y) => addObstacle(l, 'obstacle', x, y))} data="obstacle" />
                    <Tool icon={<Type />} label={t('halls.elements.label')} onClick={() => add((l, x, y) => addLabel(l, t('halls.elements.label_default'), x, y))} data="label" />
                </ToolGroup>
                <ToolGroup label={t('halls.designer.sections')}>
                    {SECTION_IDS.map((id) => (
                        <Button
                            key={id}
                            size="sm"
                            variant="ghost"
                            className="h-8 px-2"
                            disabled={layout.sections.some((s) => s.id === id)}
                            onClick={() => add((l) => addSection(l, id, sectionLabels))}
                            data-add-section={id}
                        >
                            <SquareDashed /> {sectionLabels[id]}
                        </Button>
                    ))}
                </ToolGroup>
                <span className="bg-border mx-1 hidden h-6 w-px sm:block" aria-hidden />
                <Tool icon={<Undo2 className="rtl:-scale-x-100" />} label={t('halls.designer.undo')} onClick={undo} disabled={!history.past.length} data="undo" />
                <Tool icon={<Redo2 className="rtl:-scale-x-100" />} label={t('halls.designer.redo')} onClick={redo} disabled={!history.future.length} data="redo" />
                <Tool icon={<Copy />} label={t('halls.designer.duplicate')} onClick={duplicateSelected} disabled={!selection.length} data="duplicate" />
                <Tool icon={<RotateCw />} label={t('halls.designer.rotate')} onClick={rotateSelected} disabled={!selection.length} data="rotate" />
                <Tool icon={<Trash2 />} label={t('halls.designer.delete')} onClick={removeSelected} disabled={!selection.length} data="delete" />
                <span className="bg-border mx-1 hidden h-6 w-px sm:block" aria-hidden />
                <Tool icon={<Magnet />} label={t('halls.designer.snap')} onClick={() => setSnap((v) => !v)} pressed={snap} data="snap" />
                <Tool icon={<Grid3x3 />} label={t('halls.designer.grid')} onClick={() => setShowGrid((v) => !v)} pressed={showGrid} data="grid" />
                <Tool icon={<Hand />} label={t('halls.designer.pan')} onClick={() => setPanMode((v) => !v)} pressed={panMode} data="pan" />
                <Tool icon={<Minus />} label={t('halls.designer.zoom_out')} onClick={() => zoomBy(1 / 1.25)} data="zoom-out" />
                <span className="text-muted-foreground w-12 text-center text-xs tabular-nums" aria-live="polite">
                    {n(zoom)}
                    {locale === 'ar' ? '٪' : '%'}
                </span>
                <Tool icon={<Plus />} label={t('halls.designer.zoom_in')} onClick={() => zoomBy(1.25)} data="zoom-in" />
                <Tool icon={<Maximize />} label={t('halls.designer.fit')} onClick={fit} data="fit" />
                <span className="bg-border mx-1 hidden h-6 w-px sm:block" aria-hidden />
                <Button size="sm" variant="outline" className="h-8" onClick={renumber} data-renumber>
                    <ListOrdered /> {t('halls.designer.renumber')}
                </Button>
                <label className="flex items-center gap-1.5 text-xs">
                    <Switch checked={rtl} onCheckedChange={setRtl} aria-label={t('halls.designer.renumber_rtl')} data-renumber-rtl />
                    {t('halls.designer.renumber_rtl')}
                </label>
                {toolbarEnd && <span className="ms-auto flex items-center gap-2">{toolbarEnd}</span>}
            </div>

            <div className="grid items-start gap-3 xl:grid-cols-[1fr_19rem]">
                <div className="grid gap-2">
                    <div className="relative overflow-hidden rounded-xl border bg-zinc-100" dir="ltr">
                        <svg
                            ref={svgRef}
                            viewBox={`${view.x} ${view.y} ${view.w} ${view.h}`}
                            className={cn('block aspect-[800/580] h-auto w-full touch-none select-none outline-none', panMode ? 'cursor-grab' : 'cursor-crosshair')}
                            role="application"
                            aria-label={t('halls.designer.canvas')}
                            tabIndex={-1}
                            onPointerDown={onBackgroundDown}
                            onPointerMove={onPointerMove}
                            onPointerUp={onPointerUp}
                            onPointerCancel={onPointerUp}
                            data-canvas
                        >
                            <defs>
                                <pattern id="designer-grid" width={grid} height={grid} patternUnits="userSpaceOnUse">
                                    <path d={`M ${grid} 0 L 0 0 0 ${grid}`} fill="none" className="stroke-zinc-200" strokeWidth={0.5} />
                                </pattern>
                            </defs>
                            <rect x={0} y={0} width={layout.size.w} height={layout.size.h} rx={6} className="fill-white stroke-zinc-400" strokeWidth={1.5} />
                            {showGrid && <rect x={0} y={0} width={layout.size.w} height={layout.size.h} fill="url(#designer-grid)" className="pointer-events-none" />}

                            {layout.sections.map((s) => {
                                const ref: Ref = { kind: 'section', id: s.id };
                                return (
                                    <g key={s.id}>
                                        <rect x={s.area.x} y={s.area.y} width={s.area.w} height={s.area.h} rx={10} className={cn(sectionClass(s.id), 'pointer-events-none')} strokeDasharray="6 5" />
                                        {/* a section is picked by its name tag, so the floor inside stays free for selecting */}
                                        <g {...elementProps(ref, s.label)}>
                                            <rect x={s.area.x + 6} y={s.area.y + 6} width={Math.min(s.area.w - 12, Math.max(60, s.label.length * 8 + 16))} height={20} rx={5} className="fill-white/90 stroke-zinc-300" />
                                            <text x={s.area.x + 14} y={s.area.y + 20} className="pointer-events-none fill-zinc-600 text-[12px] font-semibold">
                                                {s.label}
                                            </text>
                                        </g>
                                    </g>
                                );
                            })}
                            {layout.stage && (
                                <g {...elementProps({ kind: 'stage', id: 'stage' }, t('halls.elements.stage'))}>
                                    <Stage rect={layout.stage} />
                                </g>
                            )}
                            {layout.danceFloor && (
                                <g {...elementProps({ kind: 'dance', id: 'dance' }, t('halls.elements.dance'))}>
                                    <rect x={layout.danceFloor.x} y={layout.danceFloor.y} width={layout.danceFloor.w} height={layout.danceFloor.h} className="fill-transparent" />
                                    <DanceFloor rect={layout.danceFloor} labelled />
                                </g>
                            )}
                            {layout.entrances.map((e, i) => (
                                <g key={i} {...elementProps({ kind: 'entrance', id: String(i) }, t('halls.elements.entrance'))}>
                                    <EntranceShape entrance={e} />
                                </g>
                            ))}
                            {layout.obstacles?.map((o) => (
                                <g key={o.id} {...elementProps({ kind: 'obstacle', id: o.id }, o.label ?? t(`halls.elements.${o.kind}`))}>
                                    <ObstacleShape obstacle={o} />
                                </g>
                            ))}
                            {layout.tables.map((tb) => {
                                const ref: Ref = { kind: 'table', id: tb.id };
                                const flagged = overlapping.has(refKey(ref)) || dupes.includes(tb.id);
                                return (
                                    <g key={tb.id} {...elementProps(ref, `${t('seating.table.label', { n: n(tableNumber(tb.id)) })}، ${n(tb.seats)}`)} data-table={tb.id} data-shape={tb.shape}>
                                        <TableShape table={tb} tone={flagged ? 'flagged' : 'empty'} showCount={false} />
                                    </g>
                                );
                            })}
                            {layout.labels?.map((l) => (
                                <g key={l.id} {...elementProps({ kind: 'label', id: l.id }, l.text)}>
                                    <text x={l.x} y={l.y} textAnchor="middle" dominantBaseline="middle" className="fill-zinc-700 font-semibold" style={{ fontSize: l.size }}>
                                        {l.text}
                                    </text>
                                </g>
                            ))}

                            {/* selection */}
                            {selection.map((r) => {
                                const b = elementBox(layout, r);
                                return b ? <rect key={refKey(r)} x={b.x - 3} y={b.y - 3} width={b.w + 6} height={b.h + 6} rx={4} className="fill-sky-500/5 stroke-sky-600 pointer-events-none" strokeWidth={1.5 * scale} strokeDasharray={`${4 * scale} ${3 * scale}`} /> : null;
                            })}
                            {single && singleBox && single.kind !== 'label' && (
                                <rect
                                    x={singleBox.x + singleBox.w + 3 - handle / 2}
                                    y={singleBox.y + singleBox.h + 3 - handle / 2}
                                    width={handle}
                                    height={handle}
                                    rx={2}
                                    className="cursor-nwse-resize fill-white stroke-sky-600"
                                    strokeWidth={1.5 * scale}
                                    onPointerDown={startResize(single, singleBox)}
                                    data-resize
                                />
                            )}
                            {marquee && <rect x={marquee.x} y={marquee.y} width={marquee.w} height={marquee.h} className="pointer-events-none fill-sky-500/10 stroke-sky-500" strokeWidth={scale} strokeDasharray={`${4 * scale} ${3 * scale}`} data-marquee />}
                        </svg>
                        {notice && (
                            <p className="absolute start-3 bottom-3 rounded-lg bg-zinc-900 px-3 py-1.5 text-xs text-white" role="status">
                                {notice}
                            </p>
                        )}
                    </div>
                    <p className="text-muted-foreground flex flex-wrap items-center justify-between gap-2 text-xs">
                        <span>{t('halls.designer.hint')}</span>
                        <span className="font-medium text-zinc-700" data-totals>
                            {t('halls.designer.totals', { tables: sums.tables, seats: sums.seats })}
                            {sums.kids > 0 && ` · ${t('halls.designer.kids_seats', { count: sums.kids })}`}
                            {selection.length > 0 && ` · ${tc('halls.designer.selected', selection.length)}`}
                        </span>
                    </p>
                </div>

                <aside className="grid gap-3">
                    <Inspector
                        layout={layout}
                        selection={selection}
                        onTables={patchTables}
                        onLayout={(fn) => setLayout(fn)}
                        sectionLabels={sectionLabels}
                    />
                    <Checks overlaps={overlapList} dupes={dupes} describe={describe} onPick={(refs) => setSelection(refs)} />
                </aside>
            </div>
        </div>
    );
}

function ToolGroup({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex items-center gap-0.5 rounded-lg border bg-zinc-50 p-0.5" role="group" aria-label={label}>
            {children}
        </div>
    );
}

function Tool({ icon, label, onClick, disabled, pressed, data }: { icon: ReactNode; label: string; onClick: () => void; disabled?: boolean; pressed?: boolean; data?: string }) {
    return (
        <Button
            type="button"
            size="icon"
            variant="ghost"
            className={cn('size-8', pressed && 'bg-primary/10 text-primary')}
            onClick={onClick}
            disabled={disabled}
            aria-label={label}
            title={label}
            aria-pressed={pressed}
            data-tool={data}
        >
            {icon}
        </Button>
    );
}

function NumberField({ id, label, value, min, max, step = 1, onCommit }: { id: string; label: string; value: number; min: number; max: number; step?: number; onCommit: (v: number) => void }) {
    const [text, setText] = useState(String(value));
    useEffect(() => setText(String(value)), [value]);
    const commit = () => {
        const v = Number(text.replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d))));
        if (Number.isFinite(v)) onCommit(Math.max(min, Math.min(max, v)));
        else setText(String(value));
    };
    return (
        <div className="grid gap-1">
            <Label htmlFor={id} className="text-xs">
                {label}
            </Label>
            <Input
                id={id}
                inputMode="decimal"
                value={text}
                onChange={(e) => setText(e.target.value)}
                onBlur={commit}
                onKeyDown={(e) => {
                    if (e.key === 'Enter') commit();
                    if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                        e.preventDefault();
                        onCommit(Math.max(min, Math.min(max, value + (e.key === 'ArrowUp' ? step : -step))));
                    }
                }}
                className="h-8"
                dir="ltr"
            />
        </div>
    );
}

function TextField({ id, label, value, max, onCommit, error }: { id: string; label: string; value: string; max: number; onCommit: (v: string) => boolean | void; error?: string }) {
    const [text, setText] = useState(value);
    const [bad, setBad] = useState(false);
    useEffect(() => {
        setText(value);
        setBad(false);
    }, [value]);
    const commit = () => {
        if (text === value) return;
        const ok = onCommit(text);
        setBad(ok === false);
    };
    return (
        <div className="grid gap-1">
            <Label htmlFor={id} className="text-xs">
                {label}
            </Label>
            <Input id={id} value={text} maxLength={max} onChange={(e) => setText(e.target.value)} onBlur={commit} onKeyDown={(e) => e.key === 'Enter' && commit()} className="h-8" aria-invalid={bad} />
            {bad && error && <p className="text-destructive text-xs">{error}</p>}
        </div>
    );
}

function Inspector({
    layout,
    selection,
    onTables,
    onLayout,
    sectionLabels,
}: {
    layout: HallLayout;
    selection: Ref[];
    onTables: (ids: string[], patch: TablePatch) => boolean;
    onLayout: (fn: (l: HallLayout) => HallLayout) => void;
    sectionLabels: Record<SectionId, string>;
}) {
    const { t, tc, dir } = useT();
    const tables = selection.filter((r) => r.kind === 'table').map((r) => layout.tables.find((tb) => tb.id === r.id)).filter((x): x is HallTable => !!x);
    const single = selection.length === 1 ? selection[0] : null;
    const sectionOptions = layout.sections.map((s) => (
        <option key={s.id} value={s.id}>
            {s.label}
        </option>
    ));

    let body: ReactNode;
    if (selection.length === 0) {
        body = (
            <>
                <p className="text-muted-foreground text-xs">{t('halls.inspector.nothing')}</p>
                <div className="grid grid-cols-2 gap-2">
                    <NumberField id="hall-w" label={t('halls.inspector.width')} value={layout.size.w} min={100} max={2000} step={10} onCommit={(v) => onLayout((l) => resizeHall(l, v, l.size.h))} />
                    <NumberField id="hall-h" label={t('halls.inspector.height')} value={layout.size.h} min={100} max={2000} step={10} onCommit={(v) => onLayout((l) => resizeHall(l, l.size.w, v))} />
                </div>
            </>
        );
    } else if (single?.kind === 'table' && tables[0]) {
        const tb = tables[0];
        const set = (patch: TablePatch) => onTables([tb.id], patch);
        body = (
            <div className="grid gap-2.5" data-inspector-table={tb.id}>
                <div className="grid grid-cols-2 gap-2">
                    <TextField id="t-number" label={t('halls.inspector.number')} value={tableNumber(tb.id)} max={6} error={t('halls.inspector.number_taken')} onCommit={(v) => set({ id: /^\d+$/.test(v.trim()) ? `T${Number(v.trim())}` : v.trim() || tb.id })} />
                    <NumberField id="t-seats" label={t('halls.inspector.seats')} value={tb.seats} min={1} max={tb.shape === 'round' ? 24 : 40} onCommit={(v) => set({ seats: v })} />
                </div>
                <TextField id="t-label" label={t('halls.inspector.label')} value={tb.label ?? ''} max={24} onCommit={(v) => set({ label: v })} />
                <div className="grid gap-1">
                    <Label htmlFor="t-shape" className="text-xs">
                        {t('halls.inspector.shape')}
                    </Label>
                    <NativeSelect id="t-shape" dir={dir} value={tb.shape} onChange={(e) => set({ shape: e.target.value as HallTable['shape'] })} className="h-8">
                        {(['round', 'rect', 'sofa', 'row'] as const).map((s) => (
                            <option key={s} value={s}>
                                {t(`halls.elements.${s}`)}
                            </option>
                        ))}
                    </NativeSelect>
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="t-section" className="text-xs">
                        {t('halls.inspector.section')}
                    </Label>
                    <NativeSelect id="t-section" dir={dir} value={tb.section} onChange={(e) => set({ section: e.target.value as SectionId })} className="h-8">
                        {sectionOptions}
                    </NativeSelect>
                </div>
                {tb.shape === 'round' ? (
                    <NumberField id="t-r" label={t('halls.inspector.size')} value={tb.r} min={6} max={120} onCommit={(v) => set({ r: v })} />
                ) : (
                    <>
                        <div className="grid grid-cols-2 gap-2">
                            <NumberField id="t-w" label={t('halls.inspector.length')} value={tb.w} min={10} max={layout.size.w} step={5} onCommit={(v) => set({ w: v })} />
                            <NumberField id="t-h" label={t('halls.inspector.depth')} value={tb.h} min={4} max={layout.size.h} step={2} onCommit={(v) => set({ h: v })} />
                        </div>
                        <SwitchRow id="t-rot" label={t('halls.inspector.rotation')} checked={tb.rotation === 90} onChange={(v) => set({ rotation: v ? 90 : 0 })} />
                        {tb.shape === 'rect' && (
                            <>
                                <div className="grid gap-1">
                                    <Label htmlFor="t-sides" className="text-xs">
                                        {t('halls.inspector.sides')}
                                    </Label>
                                    <NativeSelect id="t-sides" dir={dir} value={tb.sides} onChange={(e) => set({ sides: e.target.value as 'both' | 'one' })} className="h-8">
                                        <option value="both">{t('halls.inspector.sides_both')}</option>
                                        <option value="one">{t('halls.inspector.sides_one')}</option>
                                    </NativeSelect>
                                </div>
                                <SwitchRow id="t-heads" label={t('halls.inspector.heads')} checked={tb.heads} onChange={(v) => set({ heads: v })} />
                            </>
                        )}
                    </>
                )}
                <SwitchRow id="t-kids" label={t('halls.inspector.kids')} hint={t('halls.inspector.kids_hint')} checked={!!tb.kids} onChange={(v) => set({ kids: v })} />
            </div>
        );
    } else if (tables.length > 1 && tables.length === selection.length) {
        const ids = tables.map((x) => x.id);
        body = (
            <div className="grid gap-2.5">
                <p className="text-xs font-medium">{t('halls.inspector.multi')}</p>
                <div className="grid gap-1">
                    <Label htmlFor="m-section" className="text-xs">
                        {t('halls.inspector.section')}
                    </Label>
                    <NativeSelect id="m-section" dir={dir} value={tables.every((x) => x.section === tables[0].section) ? tables[0].section : ''} onChange={(e) => e.target.value && onTables(ids, { section: e.target.value as SectionId })} className="h-8">
                        <option value="">—</option>
                        {sectionOptions}
                    </NativeSelect>
                </div>
                <SwitchRow id="m-kids" label={t('halls.inspector.kids')} checked={tables.every((x) => x.kids)} onChange={(v) => onTables(ids, { kids: v })} />
            </div>
        );
    } else if (single?.kind === 'obstacle') {
        const o = layout.obstacles?.find((x) => x.id === single.id);
        if (o) {
            const set = (patch: Partial<typeof o>) => onLayout((l) => ({ ...l, obstacles: l.obstacles?.map((x) => (x.id === o.id ? { ...x, ...patch } : x)) }));
            body = (
                <div className="grid gap-2.5">
                    <div className="grid gap-1">
                        <Label htmlFor="o-kind" className="text-xs">
                            {t('halls.inspector.kind')}
                        </Label>
                        <NativeSelect id="o-kind" dir={dir} value={o.kind} onChange={(e) => set({ kind: e.target.value as ObstacleKind })} className="h-8">
                            {(['column', 'buffet', 'obstacle'] as const).map((k) => (
                                <option key={k} value={k}>
                                    {t(`halls.elements.${k}`)}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <TextField id="o-label" label={t('halls.inspector.label')} value={o.label ?? ''} max={40} onCommit={(v) => set({ label: v.trim() || undefined })} />
                    <div className="grid grid-cols-2 gap-2">
                        <NumberField id="o-w" label={t('halls.inspector.width')} value={o.w} min={4} max={layout.size.w} step={5} onCommit={(v) => set({ w: v })} />
                        <NumberField id="o-h" label={t('halls.inspector.height')} value={o.h} min={4} max={layout.size.h} step={5} onCommit={(v) => set({ h: v })} />
                    </div>
                </div>
            );
        }
    } else if (single?.kind === 'label') {
        const lb = layout.labels?.find((x) => x.id === single.id);
        if (lb) {
            const set = (patch: Partial<typeof lb>) => onLayout((l) => ({ ...l, labels: l.labels?.map((x) => (x.id === lb.id ? { ...x, ...patch } : x)) }));
            body = (
                <div className="grid gap-2.5">
                    <TextField id="l-text" label={t('halls.inspector.text')} value={lb.text} max={60} onCommit={(v) => (v.trim() ? set({ text: v.trim() }) : false)} />
                    <NumberField id="l-size" label={t('halls.inspector.text_size')} value={lb.size} min={8} max={48} onCommit={(v) => set({ size: v })} />
                </div>
            );
        }
    } else if (single?.kind === 'section') {
        const s = layout.sections.find((x) => x.id === single.id);
        if (s) {
            const setArea = (patch: Partial<Rect>) => onLayout((l) => ({ ...l, sections: l.sections.map((x) => (x.id === s.id ? { ...x, area: { ...x.area, ...patch } } : x)) }));
            body = (
                <div className="grid gap-2.5">
                    <p className="text-xs font-medium">{sectionLabels[s.id]}</p>
                    <TextField id="s-label" label={t('halls.inspector.section_label')} value={s.label} max={40} onCommit={(v) => (v.trim() ? onLayout((l) => ({ ...l, sections: l.sections.map((x) => (x.id === s.id ? { ...x, label: v.trim() } : x)) })) : false)} />
                    <div className="grid grid-cols-2 gap-2">
                        <NumberField id="s-w" label={t('halls.inspector.width')} value={s.area.w} min={20} max={layout.size.w} step={10} onCommit={(v) => setArea({ w: v })} />
                        <NumberField id="s-h" label={t('halls.inspector.height')} value={s.area.h} min={20} max={layout.size.h} step={10} onCommit={(v) => setArea({ h: v })} />
                    </div>
                </div>
            );
        }
    } else if (single?.kind === 'stage' && layout.stage) {
        const st = layout.stage;
        body = (
            <div className="grid gap-2.5">
                <TextField id="st-label" label={t('halls.inspector.stage_label')} value={st.label} max={40} onCommit={(v) => (v.trim() ? onLayout((l) => (l.stage ? { ...l, stage: { ...l.stage, label: v.trim() } } : l)) : false)} />
                <div className="grid grid-cols-2 gap-2">
                    <NumberField id="st-w" label={t('halls.inspector.width')} value={st.w} min={20} max={layout.size.w} step={10} onCommit={(v) => onLayout((l) => resizeElement(l, { kind: 'stage', id: 'stage' }, v, st.h))} />
                    <NumberField id="st-h" label={t('halls.inspector.height')} value={st.h} min={10} max={layout.size.h} step={5} onCommit={(v) => onLayout((l) => resizeElement(l, { kind: 'stage', id: 'stage' }, st.w, v))} />
                </div>
            </div>
        );
    }

    return (
        <section className="grid gap-3 rounded-xl border bg-white p-3" aria-labelledby="inspector-title" data-inspector>
            <h2 id="inspector-title" className="text-sm font-bold">
                {selection.length === 0 ? t('halls.inspector.hall') : selection.length === 1 ? t('halls.inspector.title') : tc('halls.designer.selected', selection.length)}
            </h2>
            {body ?? <p className="text-muted-foreground text-xs">{tc('halls.designer.selected', selection.length)}</p>}
        </section>
    );
}

function SwitchRow({ id, label, hint, checked, onChange }: { id: string; label: string; hint?: string; checked: boolean; onChange: (v: boolean) => void }) {
    return (
        <div className="flex items-start justify-between gap-3">
            <Label htmlFor={id} className="grid gap-0.5 text-xs">
                <span>{label}</span>
                {hint && <span className="text-muted-foreground font-normal">{hint}</span>}
            </Label>
            <Switch id={id} checked={checked} onCheckedChange={onChange} />
        </div>
    );
}

function Checks({ overlaps, dupes, describe, onPick }: { overlaps: { a: Ref; b: Ref }[]; dupes: string[]; describe: (r: Ref) => string; onPick: (refs: Ref[]) => void }) {
    const { t, n } = useT();
    const shown = overlaps.slice(0, 6);
    return (
        <section className="grid gap-2 rounded-xl border bg-white p-3" aria-labelledby="checks-title" data-checks>
            <h2 id="checks-title" className="text-sm font-bold">
                {t('halls.checks.title')}
            </h2>
            {overlaps.length === 0 && dupes.length === 0 ? (
                <p className="text-xs text-emerald-700">{t('halls.checks.ok')}</p>
            ) : (
                <ul className="grid gap-1 text-xs">
                    {dupes.map((id) => (
                        <li key={id} className="text-destructive">
                            {t('halls.checks.duplicate', { n: n(tableNumber(id)) })}
                        </li>
                    ))}
                    {shown.map((o) => (
                        <li key={`${refKey(o.a)}|${refKey(o.b)}`}>
                            <button type="button" className="text-start text-amber-800 underline-offset-2 hover:underline" onClick={() => onPick([o.a, o.b])} data-overlap>
                                {t('halls.checks.overlap', { a: describe(o.a), b: describe(o.b) })}
                            </button>
                        </li>
                    ))}
                    {overlaps.length > shown.length && <li className="text-muted-foreground">{t('halls.checks.more', { count: overlaps.length - shown.length })}</li>}
                </ul>
            )}
            {overlaps.length > 0 && <p className="text-muted-foreground text-[11px]">{t('halls.checks.note')}</p>}
        </section>
    );
}
