import {
    DndContext,
    DragOverlay,
    KeyboardSensor,
    MouseSensor,
    pointerWithin,
    rectIntersection,
    TouchSensor,
    useSensor,
    useSensors,
    type CollisionDetection,
    type DragEndEvent,
    type KeyboardCoordinateGetter,
} from '@dnd-kit/core';
import { Head, Link } from '@inertiajs/react';
import { Armchair, ArrowLeft, Check, CloudOff, FileDown, Loader2, Minus, Plus, Printer, QrCode, RotateCcw, Sparkles, TriangleAlert, UserMinus, Users, Wand2, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

import { FamilyList, type FamilyRow } from '@/components/seating/FamilyList';
import { familyColor, HallSvg } from '@/components/seating/HallSvg';
import { HallSetup } from '@/components/seating/HallSetup';
import { MealCounts } from '@/components/seating/MealCounts';
import { WarningsPanel } from '@/components/seating/WarningsPanel';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Toaster } from '@/components/ui/sonner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/AppLayout';
import type { HallLayout } from '@/lib/hall-layout';
import { useT } from '@/lib/i18n';
import { errorMessage, sendJson, statusOf } from '@/lib/seating/api';
import {
    computeWarnings,
    filterFamilies,
    invalidSeats,
    iso,
    personLabels,
    moveSeat,
    seatedCounts,
    seatFamily,
    seatKey,
    seatMap,
    seatOne,
    summary,
    tableNumber,
    tablesOf,
    unassignFamily,
    unassignSeat,
    type Assignment,
    type FamilyFilters,
    type Section,
} from '@/lib/seating/plan';
import { cn } from '@/lib/utils';
import type { AutoSeatResult, EventHall, HallSummary, PlannerProps } from '@/types/seating';

type Selection = { kind: 'family'; id: string } | { kind: 'seat'; table: string; seat: number } | null;
type SaveState = 'saved' | 'saving' | 'error' | 'blocked';

const strip = (list: Assignment[]) => list.map(({ household_id, table_id, seat_index }) => ({ household_id, table_id, seat_index }));

/** Arrow keys jump from table to table while a family is picked up with the keyboard. */
const tableCoordinates: KeyboardCoordinateGetter = (event, { context }) => {
    const dirs: Record<string, [number, number]> = { ArrowUp: [0, -1], ArrowDown: [0, 1], ArrowLeft: [-1, 0], ArrowRight: [1, 0] };
    const dir = dirs[event.code];
    const { collisionRect, droppableRects, droppableContainers, over } = context;
    if (!dir || !collisionRect) return undefined;
    event.preventDefault();

    const cx = collisionRect.left + collisionRect.width / 2;
    const cy = collisionRect.top + collisionRect.height / 2;
    let best: { left: number; top: number; width: number; height: number } | null = null;
    let bestScore = Infinity;
    for (const container of droppableContainers.getEnabled()) {
        const rect = droppableRects.get(container.id);
        if (!rect) continue;
        const x = rect.left + rect.width / 2 - cx;
        const y = rect.top + rect.height / 2 - cy;
        // not over a table yet: go to the nearest one, whatever the direction
        const along = over ? x * dir[0] + y * dir[1] : 1;
        if (along <= 4) continue;
        const score = over ? along + Math.abs(x * dir[1] - y * dir[0]) * 2 : Math.hypot(x, y);
        if (score < bestScore) {
            best = rect;
            bestScore = score;
        }
    }
    if (!best) return undefined;
    return { x: best.left + best.width / 2 - collisionRect.width / 2, y: best.top + best.height / 2 - collisionRect.height / 2 };
};

/** The pointer must be on a table; the keyboard overlay just has to touch one. */
const collision: CollisionDetection = (args) => (args.pointerCoordinates ? pointerWithin(args) : rectIntersection(args));

/**
 * GET /dashboard/events/{event}/seating: the seating planner (plan section 7.3).
 */
export default function Planner(props: PlannerProps) {
    return props.locked ? <Locked {...props} /> : <SeatingPlanner {...props} />;
}

function Locked({ event, urls }: PlannerProps) {
    const { t } = useT();
    return (
        <AppLayout>
            <Head title={t('seating.title')} />
            <div className="mx-auto grid max-w-xl gap-5" data-seating-locked>
                <Link href={urls.dashboard} className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1 text-sm">
                    <ArrowLeft className="size-4 rtl:rotate-180" /> <bdi>{event.title}</bdi>
                </Link>
                <section className="grid gap-4 rounded-2xl border bg-white p-6 text-center">
                    <Armchair className="text-primary mx-auto size-10" />
                    <h1 className="text-xl font-bold">{t('seating.locked.title')}</h1>
                    <p className="text-muted-foreground">{t('seating.locked.text')}</p>
                    <Button asChild className="mx-auto h-11">
                        <Link href={urls.upgrade}>
                            <Sparkles /> {t('seating.locked.cta')}
                        </Link>
                    </Button>
                </section>
            </div>
        </AppLayout>
    );
}

function SaveBadge({ state, onRetry }: { state: SaveState; onRetry: () => void }) {
    const { t } = useT();
    const map = {
        saved: { icon: <Check className="size-3.5" />, text: t('seating.save.saved'), cls: 'bg-emerald-50 text-emerald-800' },
        saving: { icon: <Loader2 className="size-3.5 animate-spin" />, text: t('seating.save.saving'), cls: 'bg-muted text-muted-foreground' },
        error: { icon: <CloudOff className="size-3.5" />, text: t('seating.save.error'), cls: 'bg-red-50 text-red-800' },
        blocked: { icon: <CloudOff className="size-3.5" />, text: t('seating.save.blocked'), cls: 'bg-amber-50 text-amber-900' },
    }[state];
    return (
        <span className={cn('inline-flex h-8 items-center gap-1.5 rounded-full px-3 text-xs font-medium', map.cls)} role="status" aria-live="polite" data-save={state}>
            {map.icon} {map.text}
            {state === 'error' && (
                <button type="button" className="underline" onClick={onRetry}>
                    {t('seating.save.retry')}
                </button>
            )}
        </span>
    );
}

function SeatingPlanner(props: PlannerProps) {
    const { t, tc, n, sep, locale } = useT();
    const urls = props.urls;

    const [hall, setHall] = useState<EventHall | null>(props.hall ?? null);
    const [halls, setHalls] = useState<HallSummary[]>(props.halls ?? []);
    const [families, setFamilies] = useState(props.families ?? []);
    const [assignments, setAssignments] = useState<Assignment[]>(props.assignments ?? []);
    const [saveState, setSaveState] = useState<SaveState>('saved');
    const [preview, setPreview] = useState<AutoSeatResult | null>(null);
    const [selection, setSelection] = useState<Selection>(null);
    const [focusTable, setFocusTable] = useState<string | null>(null);
    const [filters, setFilters] = useState<FamilyFilters>({ query: '', section: 'all', seated: 'all' });
    const [tab, setTab] = useState(props.hall ? 'seating' : 'hall');
    const [busy, setBusy] = useState(false);
    const [dragging, setDragging] = useState<string | null>(null);
    const [zoom, setZoom] = useState(1);
    const [autoOpen, setAutoOpen] = useState(false);
    // the venue changed the hall (phase 8B): shown until the host changes the seats
    const [showChange, setShowChange] = useState(true);

    const layout: HallLayout | null = hall?.layout ?? null;
    const saved = useRef<Assignment[]>(props.assignments ?? []);
    const version = useRef(0);
    const dirty = useRef(false);

    // what the hall shows: the auto-seat proposal while it is previewed
    const shown = preview?.assignments ?? assignments;
    const invalid = useMemo(() => invalidSeats(layout, families, assignments), [layout, families, assignments]);
    const warnings = useMemo(() => computeWarnings(layout, families, shown), [layout, families, shown]);
    const seats = useMemo(() => seatMap(shown), [shown]);
    const counts = useMemo(() => seatedCounts(shown), [shown]);
    const labels = useMemo(() => personLabels(layout, families, shown), [layout, families, shown]);
    const totals = useMemo(() => summary(families, shown, layout), [families, shown, layout]);
    const colors = useMemo(() => new Map(families.map((f, i) => [f.id, familyColor(i)])), [families]);
    const familyMap = useMemo(() => new Map(families.map((f) => [f.id, { name: f.name, color: colors.get(f.id) ?? '#999' }])), [families, colors]);
    const flagged = useMemo(() => new Set(warnings.flatMap((w) => (w.type === 'over_capacity' || w.type === 'section_mismatch' ? [w.table] : []))), [warnings]);
    const rows: FamilyRow[] = useMemo(
        () =>
            filterFamilies(families, shown, filters).map((family) => ({
                family,
                color: colors.get(family.id) ?? '#999',
                seated: counts.get(family.id) ?? 0,
                tables: tablesOf(layout, shown, family.id),
            })),
        [families, shown, filters, colors, counts, layout],
    );
    const selectedFamily = selection?.kind === 'family' ? families.find((f) => f.id === selection.id) : undefined;
    const hinted = useMemo(() => {
        if (!selectedFamily || !layout) return undefined;
        return new Set(tablesOf(layout, shown, selectedFamily.id));
    }, [selectedFamily, layout, shown]);

    const update = useCallback((next: Assignment[]) => {
        setShowChange(false);
        dirty.current = true;
        version.current++;
        setAssignments(next);
    }, []);

    // optimistic autosave: the plan changes at once, the server confirms (or the last saved plan comes back)
    useEffect(() => {
        if (!dirty.current || !urls.seats) return;
        if (invalid.length > 0) {
            setSaveState('blocked');
            return;
        }
        setSaveState('saving');
        const mine = version.current;
        const timer = setTimeout(async () => {
            try {
                const data = await sendJson<{ assignments: Assignment[] }>('put', urls.seats!, { assignments: strip(assignments) });
                saved.current = data.assignments;
                if (version.current === mine) {
                    dirty.current = false;
                    setSaveState('saved');
                }
            } catch (e) {
                if (statusOf(e) === 422) {
                    toast.error(errorMessage(e, t('seating.errors.save')));
                    dirty.current = false;
                    setAssignments(saved.current);
                    setSaveState('saved');
                } else {
                    setSaveState('error');
                }
            }
        }, 600);
        return () => clearTimeout(timer);
    }, [assignments, invalid.length, urls.seats, t]);

    useEffect(() => {
        if (saveState === 'saved') return;
        const warn = (e: BeforeUnloadEvent) => e.preventDefault();
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [saveState]);

    const familyName = (id: string) => families.find((f) => f.id === id)?.name ?? '';

    const dropFamily = (familyId: string, tableId: string) => {
        if (!layout || preview) return;
        const result = seatFamily(layout, families, assignments, familyId, tableId);
        update(result.assignments);
        const name = iso(familyName(familyId));
        if (result.missing > 0) toast.warning(t('seating.drag.full', { table: n(tableNumber(tableId)), count: result.missing, name }));
        else toast.success(t('seating.drag.drop', { name, table: n(tableNumber(tableId)) }));
        setSelection(null);
    };

    const onChair = (table: string, seat: number) => {
        if (preview) return;
        const here = seats.get(seatKey(table, seat));
        if (selection?.kind === 'seat') {
            if (selection.table === table && selection.seat === seat) setSelection(null);
            else {
                update(moveSeat(assignments, { table: selection.table, seat: selection.seat }, { table, seat }));
                setSelection(null);
            }
            return;
        }
        if (selection?.kind === 'family' && !here) {
            const next = seatOne(families, assignments, selection.id, table, seat);
            if (next !== assignments) update(next);
            return;
        }
        if (here) {
            setSelection({ kind: 'seat', table, seat });
            setFocusTable(table);
        } else setFocusTable(table);
    };

    const onTable = (table: string) => {
        if (selection?.kind === 'family') dropFamily(selection.id, table);
        else setFocusTable((current) => (current === table ? null : table));
    };

    const onSection = async (id: string, section: Section | null) => {
        const before = families;
        setFamilies((list) => list.map((f) => (f.id === id ? { ...f, section } : f)));
        try {
            await sendJson('patch', urls.section!, { household_id: id, section });
        } catch (e) {
            setFamilies(before);
            toast.error(errorMessage(e, t('seating.errors.save')));
        }
    };

    const applyHall = (data: { hall: EventHall; halls: HallSummary[]; assignments: Assignment[]; dropped: number }) => {
        setHall(data.hall);
        setHalls(data.halls);
        dirty.current = false;
        saved.current = data.assignments;
        setAssignments(data.assignments);
        setPreview(null);
        setSelection(null);
        setFocusTable(null);
        toast.success(t('seating.hall.saved'));
        if (data.dropped > 0) toast.warning(tc('seating.hall.dropped', data.dropped));
    };

    const hallCall = async (body: object): Promise<boolean> => {
        setBusy(true);
        try {
            applyHall(await sendJson('put', urls.hall!, body));
            return true;
        } catch (e) {
            toast.error(errorMessage(e, t('seating.errors.save')));
            return false;
        } finally {
            setBusy(false);
        }
    };

    const runAuto = async (mode: 'all' | 'fill') => {
        setBusy(true);
        try {
            const result = await sendJson<AutoSeatResult>('post', urls.auto!, { mode });
            setPreview(result);
            setAutoOpen(false);
            setSelection(null);
            setTab('seating');
        } catch (e) {
            toast.error(errorMessage(e, t('seating.errors.save')));
        } finally {
            setBusy(false);
        }
    };

    const sensors = useSensors(
        useSensor(MouseSensor, { activationConstraint: { distance: 6 } }),
        useSensor(TouchSensor, { activationConstraint: { delay: 220, tolerance: 8 } }),
        useSensor(KeyboardSensor, { coordinateGetter: tableCoordinates }),
    );

    const tableOf = (id: string | number | undefined | null) => (id ? String(id).replace(/^table:/, '') : '');
    const nameOf = (id: string | number) => iso(familyName(String(id).replace(/^family:/, '')));

    const focus = focusTable && layout ? layout.tables.find((x) => x.id === focusTable) : undefined;
    const selectedSeatPerson = selection?.kind === 'seat' ? seats.get(seatKey(selection.table, selection.seat)) : undefined;

    return (
        <AppLayout wide>
            <Head title={`${t('seating.title')} · ${props.event.title}`} />
            <Toaster />
            <DndContext
                sensors={sensors}
                collisionDetection={collision}
                onDragStart={({ active }) => setDragging(String(active.id).replace(/^family:/, ''))}
                onDragCancel={() => setDragging(null)}
                onDragEnd={({ active, over }: DragEndEvent) => {
                    setDragging(null);
                    const table = tableOf(over?.id);
                    if (table) dropFamily(String(active.id).replace(/^family:/, ''), table);
                }}
                accessibility={{
                    screenReaderInstructions: { draggable: t('seating.list.drag_hint') },
                    announcements: {
                        onDragStart: ({ active }) => t('seating.drag.start', { name: nameOf(active.id) }),
                        onDragOver: ({ active, over }) => (over ? t('seating.drag.over', { name: nameOf(active.id), table: n(tableNumber(tableOf(over.id))) }) : t('seating.drag.none', { name: nameOf(active.id) })),
                        onDragEnd: ({ active, over }) => (over ? t('seating.drag.drop', { name: nameOf(active.id), table: n(tableNumber(tableOf(over.id))) }) : t('seating.drag.cancel', { name: nameOf(active.id) })),
                        onDragCancel: ({ active }) => t('seating.drag.cancel', { name: nameOf(active.id) }),
                    },
                }}
            >
                <div className="grid gap-4" data-planner>
                    <header className="grid gap-3">
                        <Link href={urls.dashboard} className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1 text-sm">
                            <ArrowLeft className="size-4 rtl:rotate-180" /> <bdi>{props.event.title}</bdi>
                        </Link>
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="grid gap-1">
                                <h1 className="text-2xl font-bold">{t('seating.title')}</h1>
                                <p className="text-muted-foreground flex flex-wrap items-center gap-2 text-sm" data-totals>
                                    <Users className="size-4" />
                                    {t('seating.list.seated_count', { seated: totals.seated, total: totals.people })}
                                    {layout && (
                                        <span>
                                            {sep}
                                            {t('seating.hall.seats', { count: totals.chairs })}
                                        </span>
                                    )}
                                </p>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <SaveBadge state={saveState} onRetry={() => update([...assignments])} />
                                <Button variant="outline" className="h-9" disabled={!layout || families.length === 0 || busy || !!preview} onClick={() => setAutoOpen(true)} data-auto-seat>
                                    <Wand2 /> {t('seating.auto.button')}
                                </Button>
                                {layout && (
                                    <>
                                        <Button asChild variant="outline" className="h-9">
                                            <a href={urls.chart_pdf} target="_blank" rel="noopener" data-pdf="chart">
                                                <Printer /> {t('seating.pdf.chart')}
                                            </a>
                                        </Button>
                                        <Button asChild variant="outline" className="h-9">
                                            <a href={urls.cards_pdf} target="_blank" rel="noopener" data-pdf="cards">
                                                <FileDown /> {t('seating.pdf.cards')}
                                            </a>
                                        </Button>
                                        {urls.find_pdf && (
                                            <Button asChild variant="outline" className="h-9">
                                                <a href={urls.find_pdf} target="_blank" rel="noopener" data-pdf="find">
                                                    <QrCode /> {t('venues.find.poster_button')}
                                                </a>
                                            </Button>
                                        )}
                                    </>
                                )}
                            </div>
                        </div>
                    </header>

                    {props.hall_change && showChange && (
                        <p className="flex flex-wrap items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950" role="status" data-hall-changed>
                            <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                            <span>
                                <b>{t('halls.changed.title')}</b>
                                {sep}
                                {tc('halls.changed.text', props.hall_change.count, {
                                    date: new Intl.DateTimeFormat(locale === 'ar' ? 'ar-u-nu-arab' : 'en-GB', { dateStyle: 'medium' }).format(new Date(props.hall_change.at)),
                                })}
                            </span>
                        </p>
                    )}

                    <Tabs value={tab} onValueChange={setTab}>
                        <TabsList className="max-w-sm">
                            <TabsTrigger value="seating" disabled={!layout}>
                                {t('seating.tabs.seating')}
                            </TabsTrigger>
                            <TabsTrigger value="hall">{t('seating.tabs.hall')}</TabsTrigger>
                        </TabsList>

                        <TabsContent value="hall">
                            <HallSetup
                                hall={hall}
                                halls={halls}
                                busy={busy}
                                limits={props.limits}
                                hallsUrl={urls.halls}
                                hasSeats={assignments.length > 0}
                                onUseSaved={(id) => hallCall({ hall_id: id })}
                                onSaveLayout={(l, name) => hallCall({ layout: l, name })}
                            />
                        </TabsContent>

                        <TabsContent value="seating">
                            {layout && (
                                <div className="grid items-start gap-4 lg:grid-cols-[21rem_1fr]">
                                    <aside className="grid gap-4 lg:sticky lg:top-4 lg:max-h-[calc(100svh-2rem)] lg:overflow-y-auto lg:pe-1">
                                        <div className="max-h-[46svh] overflow-y-auto rounded-xl border bg-zinc-50/60 p-2 lg:max-h-none lg:overflow-visible lg:border-0 lg:bg-transparent lg:p-0">
                                        <FamilyList
                                            rows={rows}
                                            total={families.length}
                                            filters={filters}
                                            onFilters={setFilters}
                                            selectedId={selection?.kind === 'family' ? selection.id : null}
                                            onSelect={(id) => setSelection((s) => (s?.kind === 'family' && s.id === id ? null : { kind: 'family', id }))}
                                            onSection={onSection}
                                            disabled={!!preview}
                                        />
                                        </div>
                                        {props.meals && <MealCounts meals={props.meals} className="hidden lg:grid" />}
                                    </aside>

                                    <div className="grid min-w-0 gap-3">
                                        {preview && (
                                            <div className="border-primary bg-primary/5 flex flex-wrap items-center justify-between gap-3 rounded-xl border-2 p-3" role="status" data-preview>
                                                <div className="grid gap-0.5 text-sm">
                                                    <span className="font-semibold">{t('seating.auto.preview', { placed: preview.placed })}</span>
                                                    {preview.unplaced.length > 0 && <span className="text-amber-800">{tc('seating.auto.unplaced', preview.unplaced.length)}</span>}
                                                </div>
                                                <div className="flex gap-2">
                                                    <Button variant="outline" onClick={() => setPreview(null)} data-discard>
                                                        <X /> {t('seating.auto.discard')}
                                                    </Button>
                                                    <Button
                                                        onClick={() => {
                                                            update(preview.assignments);
                                                            setPreview(null);
                                                            toast.success(t('seating.auto.applied'));
                                                        }}
                                                        data-apply
                                                    >
                                                        <Check /> {t('seating.auto.apply')}
                                                    </Button>
                                                </div>
                                            </div>
                                        )}

                                        <WarningsPanel
                                            warnings={warnings}
                                            unseated={totals.unseated}
                                            invalid={preview ? 0 : invalid.length}
                                            onFix={() => update(assignments.filter((a) => !invalid.includes(a)))}
                                            onTable={(table) => setFocusTable(table)}
                                            onFamily={(id) => setSelection({ kind: 'family', id })}
                                        />

                                        {selectedFamily && !preview && (
                                            <p className="bg-primary text-primary-foreground flex items-center justify-between gap-2 rounded-lg px-3 py-2 text-sm" role="status" data-selected-family>
                                                <span>{t('seating.list.selected', { name: iso(selectedFamily.name) })}</span>
                                                <span className="flex gap-1">
                                                    {(counts.get(selectedFamily.id) ?? 0) > 0 && (
                                                        <Button size="sm" variant="secondary" className="h-7" onClick={() => update(unassignFamily(assignments, selectedFamily.id))}>
                                                            <UserMinus /> {t('seating.list.unseat_family')}
                                                        </Button>
                                                    )}
                                                    <Button size="sm" variant="secondary" className="h-7" onClick={() => setSelection(null)} aria-label={t('seating.person.cancel')}>
                                                        <X />
                                                    </Button>
                                                </span>
                                            </p>
                                        )}

                                        {selection?.kind === 'seat' && selectedSeatPerson && (
                                            <p className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-zinc-900 px-3 py-2 text-sm text-white" role="status" data-selected-seat>
                                                <span>{t('seating.person.selected', { name: iso(labels.get(seatKey(selection.table, selection.seat)) || familyName(selectedSeatPerson.household_id)) })}</span>
                                                <span className="flex gap-1">
                                                    <Button
                                                        size="sm"
                                                        variant="secondary"
                                                        className="h-7"
                                                        onClick={() => {
                                                            update(unassignSeat(assignments, selection.table, selection.seat));
                                                            setSelection(null);
                                                        }}
                                                        data-unassign
                                                    >
                                                        <UserMinus /> {t('seating.person.unassign')}
                                                    </Button>
                                                    <Button size="sm" variant="secondary" className="h-7" onClick={() => setSelection(null)}>
                                                        {t('seating.person.cancel')}
                                                    </Button>
                                                </span>
                                            </p>
                                        )}

                                        <div className="relative rounded-xl border bg-white">
                                            <div className="absolute end-2 top-2 z-10 flex gap-1">
                                                <Button size="icon" variant="outline" className="size-8" onClick={() => setZoom((z) => Math.max(1, z - 0.5))} disabled={zoom <= 1} aria-label="−">
                                                    <Minus />
                                                </Button>
                                                <Button size="icon" variant="outline" className="size-8" onClick={() => setZoom((z) => Math.min(3, z + 0.5))} disabled={zoom >= 3} aria-label="+">
                                                    <Plus />
                                                </Button>
                                            </div>
                                            <div className="overflow-auto p-2">
                                                <div style={{ width: `${zoom * 100}%` }}>
                                                    <HallSvg
                                                        layout={layout}
                                                        mode={preview ? 'view' : 'seat'}
                                                        seats={seats}
                                                        families={familyMap}
                                                        selectedSeat={selection?.kind === 'seat' ? selection : null}
                                                        selectedTable={focusTable}
                                                        flagged={flagged}
                                                        hinted={hinted}
                                                        onChairClick={onChair}
                                                        onTableClick={onTable}
                                                        label={t('seating.hall.title')}
                                                    />
                                                </div>
                                            </div>
                                        </div>

                                        {focus && (
                                            <TablePanel
                                                table={focus}
                                                seats={seats}
                                                labels={labels}
                                                colors={colors}
                                                selectedFamily={selectedFamily?.name}
                                                readOnly={!!preview}
                                                onSeatFamily={() => selectedFamily && dropFamily(selectedFamily.id, focus.id)}
                                                onSelectSeat={(seat) => setSelection({ kind: 'seat', table: focus.id, seat })}
                                                onRemove={(seat) => update(unassignSeat(assignments, focus.id, seat))}
                                                onClose={() => setFocusTable(null)}
                                            />
                                        )}
                                        {props.meals && <MealCounts meals={props.meals} className="lg:hidden" />}
                                    </div>
                                </div>
                            )}
                        </TabsContent>
                    </Tabs>
                </div>

                <DragOverlay dropAnimation={null}>
                    {dragging && (
                        <div className="bg-primary text-primary-foreground pointer-events-none inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-sm font-semibold shadow-lg" data-drag-overlay>
                            <Users className="size-4" />
                            <bdi>{familyName(dragging)}</bdi>
                            <span className="opacity-80">({tc('seating.list.people', families.find((f) => f.id === dragging)?.people ?? 0)})</span>
                        </div>
                    )}
                </DragOverlay>
            </DndContext>

            <Dialog open={autoOpen} onOpenChange={setAutoOpen}>
                <DialogContent closeLabel={t('seating.hall.cancel')}>
                    <DialogHeader>
                        <DialogTitle>{t('seating.auto.title')}</DialogTitle>
                        <DialogDescription>{t('seating.auto.text')}</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Button className="h-11 justify-start" disabled={busy} onClick={() => runAuto('all')} data-auto="all">
                            <Wand2 /> {t('seating.auto.all')}
                        </Button>
                        <Button variant="outline" className="h-11 justify-start" disabled={busy || assignments.length === 0} onClick={() => runAuto('fill')} data-auto="fill">
                            <RotateCcw /> {t('seating.auto.fill')}
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

function TablePanel({
    table,
    seats,
    labels,
    colors,
    selectedFamily,
    readOnly,
    onSeatFamily,
    onSelectSeat,
    onRemove,
    onClose,
}: {
    table: HallLayout['tables'][number];
    seats: Map<string, Assignment>;
    labels: Map<string, string>;
    colors: Map<string, string>;
    selectedFamily?: string;
    readOnly: boolean;
    onSeatFamily: () => void;
    onSelectSeat: (seat: number) => void;
    onRemove: (seat: number) => void;
    onClose: () => void;
}) {
    const { t, n } = useT();
    const chairs = Array.from({ length: table.seats }, (_, i) => ({ i, a: seats.get(seatKey(table.id, i)) }));
    const free = chairs.filter((c) => !c.a).length;

    return (
        <section className="grid gap-3 rounded-xl border bg-white p-4" aria-labelledby="table-panel-title" data-table-panel={table.id}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 id="table-panel-title" className="font-bold">
                    {t('seating.table.label', { n: n(tableNumber(table.id)) })}
                    {table.section !== 'mixed' && <span className="text-muted-foreground ms-2 text-sm font-normal">({t(`seating.section.${table.section}`)})</span>}
                </h2>
                <div className="flex items-center gap-2">
                    <span className="text-muted-foreground text-sm">{free > 0 ? t('seating.table.free', { count: free }) : t('seating.table.full')}</span>
                    {selectedFamily && !readOnly && free > 0 && (
                        <Button size="sm" onClick={onSeatFamily}>
                            {t('seating.table.drop', { name: iso(selectedFamily) })}
                        </Button>
                    )}
                    <Button size="icon" variant="ghost" className="size-8" onClick={onClose} aria-label={t('seating.person.cancel')}>
                        <X />
                    </Button>
                </div>
            </div>
            <ol className="grid gap-1 sm:grid-cols-2">
                {chairs.map(({ i, a }) => (
                    <li key={i} className="flex items-center justify-between gap-2 rounded-md border px-2 py-1.5 text-sm">
                        <span className="flex min-w-0 items-center gap-2">
                            <span className="size-3 shrink-0 rounded-full border" style={{ background: a ? colors.get(a.household_id) : 'white' }} aria-hidden />
                            <span className="text-muted-foreground shrink-0 text-xs">{t('seating.table.chair', { n: i + 1 })}</span>
                            <span className="truncate">{a ? <bdi>{labels.get(seatKey(table.id, i))}</bdi> : '—'}</span>
                        </span>
                        {a && !readOnly && (
                            <span className="flex shrink-0 gap-1">
                                <Button size="sm" variant="ghost" className="h-7 px-2" onClick={() => onSelectSeat(i)}>
                                    {t('seating.person.move')}
                                </Button>
                                <Button size="icon" variant="ghost" className="size-7" onClick={() => onRemove(i)} aria-label={t('seating.person.unassign')}>
                                    <UserMinus />
                                </Button>
                            </span>
                        )}
                    </li>
                ))}
            </ol>
        </section>
    );
}
