/**
 * The hall designer's editing logic (phase 8B, plan section 5 "Hall designer"),
 * pure so it runs the same in the browser and in node:test. Every function
 * returns a new layout; the component keeps the undo history.
 *
 * Elements are addressed by a Ref: tables, obstacles and labels by id,
 * sections by section id, entrances by index, the kosha and the dance floor
 * by "stage" / "dance". The server normalizes and validates every save
 * (App\Services\Seating\HallLayoutData), so this only has to keep the layout sensible.
 */
import {
    chairPositions,
    extent,
    MAX_SEATS,
    round1,
    tableBox,
    withChairs,
    type HallLabel,
    type HallLayout,
    type HallSection,
    type HallTable,
    type Obstacle,
    type ObstacleKind,
    type Rect,
    type SectionId,
    type TableShape,
} from '../hall-layout.ts';

export type ElementKind = 'table' | 'obstacle' | 'label' | 'section' | 'stage' | 'dance' | 'entrance';

export interface Ref {
    kind: ElementKind;
    id: string;
}

export const refKey = (r: Ref) => `${r.kind}:${r.id}`;
export const sameRef = (a: Ref, b: Ref) => a.kind === b.kind && a.id === b.id;

export const ROUND_MAX_SEATS = 24;
export const ENTRANCE_DEPTH = 10;

export const snapTo = (v: number, grid: number) => (grid > 0 ? Math.round(v / grid) * grid : v);

const clamp = (v: number, min: number, max: number) => Math.max(min, Math.min(max, v));

/** Bounding box of an element (a table with its chairs). */
export function elementBox(layout: HallLayout, ref: Ref): Rect | null {
    switch (ref.kind) {
        case 'table': {
            const t = layout.tables.find((x) => x.id === ref.id);
            return t ? tableBox(t) : null;
        }
        case 'obstacle': {
            const o = layout.obstacles?.find((x) => x.id === ref.id);
            return o ? { x: o.x, y: o.y, w: o.w, h: o.h } : null;
        }
        case 'label': {
            const l = layout.labels?.find((x) => x.id === ref.id);
            if (!l) return null;
            const w = Math.max(l.size, [...l.text].length * l.size * 0.58);
            return { x: l.x - w / 2, y: l.y - l.size * 0.6, w, h: l.size * 1.2 };
        }
        case 'section': {
            const s = layout.sections.find((x) => x.id === ref.id);
            return s ? { ...s.area } : null;
        }
        case 'stage':
            return layout.stage ? { x: layout.stage.x, y: layout.stage.y, w: layout.stage.w, h: layout.stage.h } : null;
        case 'dance':
            return layout.danceFloor ? { ...layout.danceFloor } : null;
        case 'entrance': {
            const e = layout.entrances[Number(ref.id)];
            if (!e) return null;
            return e.rotation === 90 ? { x: e.x, y: e.y, w: ENTRANCE_DEPTH, h: e.w } : { x: e.x, y: e.y, w: e.w, h: ENTRANCE_DEPTH };
        }
    }
}

/** Every element of the layout, drawing order (sections first, labels last). */
export function allRefs(layout: HallLayout): Ref[] {
    return [
        ...layout.sections.map((s) => ({ kind: 'section' as const, id: s.id })),
        ...(layout.stage ? [{ kind: 'stage' as const, id: 'stage' }] : []),
        ...(layout.danceFloor ? [{ kind: 'dance' as const, id: 'dance' }] : []),
        ...layout.entrances.map((_, i) => ({ kind: 'entrance' as const, id: String(i) })),
        ...(layout.obstacles ?? []).map((o) => ({ kind: 'obstacle' as const, id: o.id })),
        ...layout.tables.map((t) => ({ kind: 'table' as const, id: t.id })),
        ...(layout.labels ?? []).map((l) => ({ kind: 'label' as const, id: l.id })),
    ];
}

const intersects = (a: Rect, b: Rect, slack = 1) => a.x + slack < b.x + b.w && b.x + slack < a.x + a.w && a.y + slack < b.y + b.h && b.y + slack < a.y + a.h;

/** Elements whose box falls inside a marquee rectangle (sections are never caught by a marquee). */
export function refsInRect(layout: HallLayout, rect: Rect): Ref[] {
    return allRefs(layout).filter((r) => {
        if (r.kind === 'section') return false;
        const b = elementBox(layout, r);
        return b !== null && b.x >= rect.x && b.y >= rect.y && b.x + b.w <= rect.x + rect.w && b.y + b.h <= rect.y + rect.h;
    });
}

/**
 * Move elements by (dx, dy), each kept inside the hall. With a grid, the
 * first element's corner lands on the grid and the others move by the same amount.
 */
export function moveElements(layout: HallLayout, refs: Ref[], dx: number, dy: number, grid = 0): HallLayout {
    if (refs.length === 0) return layout;
    const lead = elementBox(layout, refs[0]);
    if (lead && grid > 0) {
        dx = snapTo(lead.x + dx, grid) - lead.x;
        dy = snapTo(lead.y + dy, grid) - lead.y;
    }
    // the whole selection stops at the walls together
    const boxes = refs.map((r) => elementBox(layout, r)).filter((b): b is Rect => b !== null);
    if (boxes.length) {
        const minX = Math.min(...boxes.map((b) => b.x));
        const minY = Math.min(...boxes.map((b) => b.y));
        const maxX = Math.max(...boxes.map((b) => b.x + b.w));
        const maxY = Math.max(...boxes.map((b) => b.y + b.h));
        dx = clamp(dx, -minX, layout.size.w - maxX);
        dy = clamp(dy, -minY, layout.size.h - maxY);
    }
    dx = round1(dx);
    dy = round1(dy);
    if (dx === 0 && dy === 0) return layout;

    const keys = new Set(refs.map(refKey));
    const has = (kind: ElementKind, id: string) => keys.has(`${kind}:${id}`);
    const mv = <T extends { x: number; y: number }>(o: T): T => ({ ...o, x: round1(o.x + dx), y: round1(o.y + dy) });

    return {
        ...layout,
        tables: layout.tables.map((t) => (has('table', t.id) ? withChairs(mv(t)) : t)),
        obstacles: layout.obstacles?.map((o) => (has('obstacle', o.id) ? mv(o) : o)),
        labels: layout.labels?.map((l) => (has('label', l.id) ? mv(l) : l)),
        sections: layout.sections.map((s) => (has('section', s.id) ? { ...s, area: mv(s.area) } : s)),
        stage: layout.stage && has('stage', 'stage') ? mv(layout.stage) : layout.stage,
        danceFloor: layout.danceFloor && has('dance', 'dance') ? mv(layout.danceFloor) : layout.danceFloor,
        entrances: layout.entrances.map((e, i) => (has('entrance', String(i)) ? mv(e) : e)),
    };
}

/** Remove elements. The last table stays (a hall needs one); sections keep at least one. */
export function deleteElements(layout: HallLayout, refs: Ref[]): HallLayout {
    const keys = new Set(refs.map(refKey));
    const has = (kind: ElementKind, id: string) => keys.has(`${kind}:${id}`);
    let tables = layout.tables.filter((t) => !has('table', t.id));
    if (tables.length === 0) tables = layout.tables.slice(0, 1);
    let sections = layout.sections.filter((s) => !has('section', s.id));
    if (sections.length === 0) sections = [{ id: 'mixed', label: 'Guests', area: { x: 0, y: 0, w: layout.size.w, h: layout.size.h } }];
    const known = new Set(sections.map((s) => s.id));
    const fallback = sections[0].id;

    return {
        ...layout,
        sections,
        tables: tables.map((t) => (known.has(t.section) ? t : { ...t, section: fallback })),
        obstacles: layout.obstacles?.filter((o) => !has('obstacle', o.id)),
        labels: layout.labels?.filter((l) => !has('label', l.id)),
        stage: has('stage', 'stage') ? undefined : layout.stage,
        danceFloor: has('dance', 'dance') ? undefined : layout.danceFloor,
        entrances: layout.entrances.filter((_, i) => !has('entrance', String(i))),
    };
}

/** "T12" → 12; 0 for ids without a number. */
const idNumber = (id: string) => {
    const m = /(\d+)/.exec(id);
    return m ? Number(m[1]) : 0;
};

/** The next free table id: T{highest number + 1}. */
export function nextTableId(layout: HallLayout, extra: string[] = []): string {
    const used = new Set([...layout.tables.map((t) => t.id), ...extra]);
    let n = Math.max(0, ...[...used].map(idNumber)) + 1;
    while (used.has(`T${n}`)) n++;
    return `T${n}`;
}

function nextId(prefix: string, taken: string[]): string {
    const used = new Set(taken);
    let n = taken.length + 1;
    while (used.has(`${prefix}${n}`)) n++;
    return `${prefix}${n}`;
}

/** Copies of the elements, 20 units down and to the right, with new ids; the copies come back selected. */
export function duplicateElements(layout: HallLayout, refs: Ref[], offset = 20): { layout: HallLayout; refs: Ref[] } {
    let next = layout;
    const out: Ref[] = [];
    for (const ref of refs) {
        if (ref.kind === 'table') {
            const t = next.tables.find((x) => x.id === ref.id);
            if (!t) continue;
            const id = nextTableId(next);
            next = { ...next, tables: [...next.tables, withChairs({ ...t, id, x: t.x + offset, y: t.y + offset })] };
            out.push({ kind: 'table', id });
        } else if (ref.kind === 'obstacle') {
            const o = next.obstacles?.find((x) => x.id === ref.id);
            if (!o) continue;
            const id = nextId('O', (next.obstacles ?? []).map((x) => x.id));
            next = { ...next, obstacles: [...(next.obstacles ?? []), { ...o, id, x: o.x + offset, y: o.y + offset }] };
            out.push({ kind: 'obstacle', id });
        } else if (ref.kind === 'label') {
            const l = next.labels?.find((x) => x.id === ref.id);
            if (!l) continue;
            const id = nextId('L', (next.labels ?? []).map((x) => x.id));
            next = { ...next, labels: [...(next.labels ?? []), { ...l, id, x: l.x + offset, y: l.y + offset }] };
            out.push({ kind: 'label', id });
        } else if (ref.kind === 'entrance' && next.entrances.length < 4) {
            const e = next.entrances[Number(ref.id)];
            if (!e) continue;
            next = { ...next, entrances: [...next.entrances, { ...e, x: e.x + offset }] };
            out.push({ kind: 'entrance', id: String(next.entrances.length - 1) });
        }
    }
    // keep the copies inside the hall
    return { layout: out.length ? moveElements(next, out, 0, 0) : next, refs: out };
}

const swapRect = <T extends Rect>(r: T): T => {
    const cx = r.x + r.w / 2;
    const cy = r.y + r.h / 2;
    return { ...r, w: r.h, h: r.w, x: round1(cx - r.h / 2), y: round1(cy - r.w / 2) };
};

/** Turn elements 90° around their centre (long tables, entrances, obstacles, the kosha, the dance floor). */
export function rotateElements(layout: HallLayout, refs: Ref[]): HallLayout {
    const keys = new Set(refs.map(refKey));
    const has = (kind: ElementKind, id: string) => keys.has(`${kind}:${id}`);
    const turned: HallLayout = {
        ...layout,
        tables: layout.tables.map((t) => (has('table', t.id) && t.shape !== 'round' ? withChairs({ ...t, rotation: t.rotation === 90 ? 0 : 90 }) : t)),
        obstacles: layout.obstacles?.map((o) => (has('obstacle', o.id) ? swapRect(o) : o)),
        stage: layout.stage && has('stage', 'stage') ? swapRect(layout.stage) : layout.stage,
        danceFloor: layout.danceFloor && has('dance', 'dance') ? swapRect(layout.danceFloor) : layout.danceFloor,
        entrances: layout.entrances.map((e, i) => {
            if (!has('entrance', String(i))) return e;
            const { rotation, ...rest } = e;
            return rotation === 90 ? rest : { ...rest, rotation: 90 as const };
        }),
    };
    return moveElements(turned, refs, 0, 0);
}

/** Resize a box-like element (or a long table: w = length, h = depth, before rotation). */
export function resizeElement(layout: HallLayout, ref: Ref, w: number, h: number): HallLayout {
    const W = (v: number) => round1(clamp(v, 10, layout.size.w));
    const H = (v: number) => round1(clamp(v, 4, layout.size.h));
    switch (ref.kind) {
        case 'table':
            return {
                ...layout,
                tables: layout.tables.map((t) => {
                    if (t.id !== ref.id) return t;
                    if (t.shape === 'round') {
                        const r = round1(clamp(w / 2, 6, 120));
                        return { ...t, r, ring: round1(r + t.chairR * 1.7) };
                    }
                    return withChairs({ ...t, w: W(w), h: H(h) });
                }),
            };
        case 'obstacle':
            return { ...layout, obstacles: layout.obstacles?.map((o) => (o.id === ref.id ? { ...o, w: W(w), h: H(h) } : o)) };
        case 'section':
            return { ...layout, sections: layout.sections.map((s) => (s.id === ref.id ? { ...s, area: { ...s.area, w: W(w), h: H(h) } } : s)) };
        case 'stage':
            return layout.stage ? { ...layout, stage: { ...layout.stage, w: W(w), h: H(h) } } : layout;
        case 'dance':
            return layout.danceFloor ? { ...layout, danceFloor: { ...layout.danceFloor, w: W(w), h: H(h) } } : layout;
        case 'entrance':
            return { ...layout, entrances: layout.entrances.map((e, i) => (String(i) === ref.id ? { ...e, w: W(Math.max(w, h)) } : e)) };
        case 'label':
            return { ...layout, labels: layout.labels?.map((l) => (l.id === ref.id ? { ...l, size: round1(clamp(h / 1.2, 8, 48)) } : l)) };
    }
}

export type NewTableKind = 'round' | 'rect' | 'sofa' | 'row' | 'kids';

/** A new table centred on (x, y): round (8), banquet (10), sofa (3), VIP row (10 chairs) or a kids table (round, 8). */
export function makeTable(kind: NewTableKind, id: string, x: number, y: number, section: SectionId): HallTable {
    const chairR = 7;
    switch (kind) {
        case 'round':
        case 'kids': {
            const ring = 34;
            return { id, section, shape: 'round', x, y, seats: 8, r: round1(ring - chairR * 1.7), chairR, ring, ...(kind === 'kids' ? { kids: true } : {}) };
        }
        case 'rect':
            return withChairs({ id, section, shape: 'rect', x, y, seats: 10, w: 110, h: 26, rotation: 0, sides: 'both', heads: false, chairR, chairs: [] });
        case 'sofa':
            return withChairs({ id, section, shape: 'sofa', x, y, seats: 3, w: 72, h: 28, rotation: 0, chairR: 9, chairs: [] });
        case 'row':
            return withChairs({ id, section, shape: 'row', x, y, seats: 10, w: 10 * (chairR * 2 + 4), h: chairR * 2 + 4, rotation: 0, chairR, chairs: [] });
    }
}

/** The section an (x, y) point falls in, else the first section. */
export function sectionAt(layout: HallLayout, x: number, y: number): SectionId {
    const hit = [...layout.sections].reverse().find((s) => x >= s.area.x && x <= s.area.x + s.area.w && y >= s.area.y && y <= s.area.y + s.area.h);
    return (hit ?? layout.sections[0]).id;
}

export function addTable(layout: HallLayout, kind: NewTableKind, x: number, y: number): { layout: HallLayout; ref: Ref } {
    const id = nextTableId(layout);
    let section = sectionAt(layout, x, y);
    if (kind === 'row' && layout.sections.some((s) => s.id === 'vip')) section = 'vip';
    const table = makeTable(kind, id, x, y, section);
    const next = { ...layout, tables: [...layout.tables, table] };
    const ref = { kind: 'table' as const, id };
    return { layout: moveElements(next, [ref], 0, 0), ref };
}

export function addObstacle(layout: HallLayout, kind: ObstacleKind, x: number, y: number): { layout: HallLayout; ref: Ref } {
    const id = nextId('O', (layout.obstacles ?? []).map((o) => o.id));
    const [w, h] = kind === 'column' ? [24, 24] : kind === 'buffet' ? [140, 36] : [60, 40];
    const o: Obstacle = { id, kind, x: round1(x - w / 2), y: round1(y - h / 2), w, h };
    const ref = { kind: 'obstacle' as const, id };
    return { layout: moveElements({ ...layout, obstacles: [...(layout.obstacles ?? []), o] }, [ref], 0, 0), ref };
}

export function addLabel(layout: HallLayout, text: string, x: number, y: number): { layout: HallLayout; ref: Ref } {
    const id = nextId('L', (layout.labels ?? []).map((l) => l.id));
    const l: HallLabel = { id, text, x: round1(x), y: round1(y), size: 14 };
    return { layout: { ...layout, labels: [...(layout.labels ?? []), l] }, ref: { kind: 'label', id } };
}

/** Add a section (men, women, mixed or VIP) as a rectangle; each kind once. */
export function addSection(layout: HallLayout, id: SectionId, labels: Record<SectionId, string>): { layout: HallLayout; ref: Ref } | null {
    if (layout.sections.some((s) => s.id === id)) return null;
    const w = Math.round(layout.size.w / 3);
    const h = Math.round(layout.size.h / 3);
    const area = { x: Math.round((layout.size.w - w) / 2), y: Math.round((layout.size.h - h) / 2), w, h };
    const section: HallSection = { id, label: labels[id], area };
    return { layout: { ...layout, sections: [...layout.sections, section] }, ref: { kind: 'section', id } };
}

export function addStage(layout: HallLayout, label = 'Kosha'): { layout: HallLayout; ref: Ref } {
    const w = 240;
    const stage = layout.stage ?? { x: round1((layout.size.w - w) / 2), y: 24, w, h: 60, label };
    return { layout: { ...layout, stage }, ref: { kind: 'stage', id: 'stage' } };
}

export function addDance(layout: HallLayout): { layout: HallLayout; ref: Ref } {
    const w = 180;
    const danceFloor = layout.danceFloor ?? { x: round1((layout.size.w - w) / 2), y: 100, w, h: 70 };
    return { layout: { ...layout, danceFloor }, ref: { kind: 'dance', id: 'dance' } };
}

export function addEntrance(layout: HallLayout): { layout: HallLayout; ref: Ref } | null {
    if (layout.entrances.length >= 4) return null;
    const entrance = { x: round1(layout.size.w / 2 - 40), y: layout.size.h - 14, w: 80 };
    return { layout: { ...layout, entrances: [...layout.entrances, entrance] }, ref: { kind: 'entrance', id: String(layout.entrances.length) } };
}

export interface TablePatch {
    id?: string;
    label?: string;
    seats?: number;
    section?: SectionId;
    kids?: boolean;
    w?: number;
    h?: number;
    r?: number;
    rotation?: 0 | 90;
    sides?: 'both' | 'one';
    heads?: boolean;
    shape?: TableShape;
}

/**
 * Change a table from the inspector. A round table's ring grows with its
 * seats so the chairs never touch. Returns null when the new id is taken.
 */
export function updateTable(layout: HallLayout, id: string, patch: TablePatch): HallLayout | null {
    const table = layout.tables.find((t) => t.id === id);
    if (!table) return layout;
    if (patch.id !== undefined && patch.id !== id && (!/^[A-Za-z0-9_-]{1,16}$/.test(patch.id) || layout.tables.some((t) => t.id === patch.id))) return null;

    let next: HallTable = table;
    if (patch.shape && patch.shape !== table.shape) {
        next = { ...makeTable(patch.shape, table.id, table.x, table.y, table.section), seats: Math.min(table.seats, patch.shape === 'round' ? ROUND_MAX_SEATS : MAX_SEATS) } as HallTable;
        if (table.kids) next = { ...next, kids: true };
        if (table.label) next = { ...next, label: table.label };
    }

    const max = next.shape === 'round' ? ROUND_MAX_SEATS : MAX_SEATS;
    const seats = patch.seats !== undefined ? clamp(Math.round(patch.seats) || 1, 1, max) : next.seats;
    next = { ...next, seats };
    if (patch.id !== undefined) next = { ...next, id: patch.id };
    if (patch.section !== undefined) next = { ...next, section: patch.section };
    if (patch.label !== undefined) {
        const label = patch.label.trim().slice(0, 24);
        const { label: _old, ...rest } = next;
        void _old;
        next = (label ? { ...rest, label } : rest) as HallTable;
    }
    if (patch.kids !== undefined) {
        const { kids: _old, ...rest } = next;
        void _old;
        next = (patch.kids ? { ...rest, kids: true } : rest) as HallTable;
    }

    if (next.shape === 'round') {
        const r = patch.r !== undefined ? round1(clamp(patch.r, 6, 120)) : next.r;
        // chairs around the table need about 2.4 chair radii each
        const minRing = (seats * next.chairR * 2.4) / (2 * Math.PI);
        // resizing the table sets the ring; more seats only ever widen it
        const ring = round1(patch.r !== undefined ? Math.max(r + next.chairR * 1.4, minRing) : Math.max(next.ring, minRing, r + next.chairR * 1.4));
        next = { ...next, r: Math.min(r, round1(ring - next.chairR * 1.2)), ring };
    } else {
        if (patch.w !== undefined) next = { ...next, w: round1(clamp(patch.w, 10, layout.size.w)) };
        if (patch.h !== undefined) next = { ...next, h: round1(clamp(patch.h, 4, layout.size.h)) };
        if (patch.rotation !== undefined) next = { ...next, rotation: patch.rotation };
        if (next.shape === 'rect') {
            if (patch.sides !== undefined) next = { ...next, sides: patch.sides };
            if (patch.heads !== undefined) next = { ...next, heads: patch.heads };
        }
        if (next.shape === 'row' && patch.seats !== undefined && patch.w === undefined) next = { ...next, w: round1(seats * (next.chairR * 2 + 4)) };
        next = withChairs(next);
    }

    const tables = layout.tables.map((t) => (t.id === id ? next : t));
    return moveElements({ ...layout, tables }, [{ kind: 'table', id: next.id }], 0, 0);
}

/**
 * Number tables T1, T2 … in reading order: rows from the top, each row left
 * to right (right to left for an Arabic hall). Two tables are in the same row
 * when their centres are closer vertically than half the smaller table.
 *
 * @return the layout and old id → new id for every table whose id changed
 */
export function renumberTables(layout: HallLayout, { rtl = false } = {}): { layout: HallLayout; renames: Record<string, string> } {
    const sorted = [...layout.tables].sort((a, b) => a.y - b.y || a.x - b.x);
    const rows: HallTable[][] = [];
    for (const t of sorted) {
        const row = rows[rows.length - 1];
        const ref = row?.[0];
        const tol = ref ? Math.min(extent(ref)[1], extent(t)[1]) * 0.5 + 2 : 0;
        if (row && Math.abs(t.y - ref.y) <= tol) row.push(t);
        else rows.push([t]);
    }
    const order = rows.flatMap((row) => row.sort((a, b) => (rtl ? b.x - a.x : a.x - b.x) || a.y - b.y));
    const renames: Record<string, string> = {};
    const ids = new Map<string, string>();
    order.forEach((t, i) => {
        const id = `T${i + 1}`;
        ids.set(t.id, id);
        if (t.id !== id) renames[t.id] = id;
    });
    return {
        layout: { ...layout, tables: order.map((t) => ({ ...t, id: ids.get(t.id)! })) },
        renames,
    };
}

export interface Overlap {
    a: Ref;
    b: Ref;
}

/** Tables (with their chairs) that overlap each other or an obstacle, the kosha or the dance floor. Warned, never blocked. */
export function overlaps(layout: HallLayout): Overlap[] {
    const tables = layout.tables.map((t) => ({ ref: { kind: 'table' as const, id: t.id }, box: tableBox(t) }));
    const fixed = [
        ...(layout.obstacles ?? []).map((o) => ({ ref: { kind: 'obstacle' as const, id: o.id }, box: { x: o.x, y: o.y, w: o.w, h: o.h } })),
        ...(layout.stage ? [{ ref: { kind: 'stage' as const, id: 'stage' }, box: layout.stage as Rect }] : []),
        ...(layout.danceFloor ? [{ ref: { kind: 'dance' as const, id: 'dance' }, box: layout.danceFloor }] : []),
    ];
    const out: Overlap[] = [];
    for (let i = 0; i < tables.length; i++) {
        for (let j = i + 1; j < tables.length; j++) if (intersects(tables[i].box, tables[j].box)) out.push({ a: tables[i].ref, b: tables[j].ref });
        for (const f of fixed) if (intersects(tables[i].box, f.box)) out.push({ a: tables[i].ref, b: f.ref });
    }
    return out;
}

/** Table ids used more than once (the server refuses those). */
export function duplicateIds(layout: HallLayout): string[] {
    const seen = new Set<string>();
    const dup = new Set<string>();
    for (const t of layout.tables) (seen.has(t.id) ? dup : seen).add(t.id);
    return [...dup];
}

/** Totals for the designer's status bar. */
export function totals(layout: HallLayout) {
    return {
        tables: layout.tables.length,
        seats: layout.tables.reduce((s, t) => s + t.seats, 0),
        kids: layout.tables.filter((t) => t.kids).reduce((s, t) => s + t.seats, 0),
    };
}

/** Change the hall's size; everything stays inside it. */
export function resizeHall(layout: HallLayout, w: number, h: number): HallLayout {
    const size = { w: round1(clamp(w, 100, 2000)), h: round1(clamp(h, 100, 2000)) };
    const next = { ...layout, size };
    return allRefs(next).reduce((l, r) => moveElements(l, [r], 0, 0), next);
}

/** Recompute the chairs of every long table (e.g. after loading a layout saved by an older client). */
export function freshChairs(layout: HallLayout): HallLayout {
    return { ...layout, tables: layout.tables.map((t) => (t.shape === 'round' ? t : { ...t, chairs: chairPositions(t) })) };
}

/**
 * Track table renames across edits: origin maps each current table id to the
 * id it had when the hall was loaded (new tables have none). Returns the
 * renames to send with the save: original id → current id.
 */
export function renamesFrom(origin: Record<string, string>): Record<string, string> {
    const out: Record<string, string> = {};
    for (const [current, original] of Object.entries(origin)) if (current !== original) out[original] = current;
    return out;
}

export function applyRenames(origin: Record<string, string>, renames: Record<string, string>): Record<string, string> {
    const out: Record<string, string> = {};
    for (const [current, original] of Object.entries(origin)) out[renames[current] ?? current] = original;
    return out;
}
