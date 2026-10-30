/**
 * Hall layout types (plan section 7.1) and table geometry: the TypeScript
 * mirror of app/Services/Seating/schemas/hall-layout.json and of the geometry
 * in app/Services/Seating/HallLayoutData.php. Keep all three in sync;
 * tests/Fixtures/hall-geometry.json holds the expected chairs for both sides.
 *
 * Backward compatible: phase 7A layouts have only round tables and none of
 * the optional keys (obstacles, labels, kids, label, entrance rotation).
 */

export type TableShape = 'round' | 'rect' | 'sofa' | 'row';
export type SectionId = 'mixed' | 'men' | 'women' | 'vip';
export type ObstacleKind = 'column' | 'buffet' | 'obstacle';
export type Rotation = 0 | 90;

export interface Point {
    x: number;
    y: number;
}

export interface Rect {
    x: number;
    y: number;
    w: number;
    h: number;
}

export interface HallSection {
    id: SectionId;
    label: string;
    area: Rect;
}

interface TableBase {
    id: string;
    section: SectionId;
    x: number;
    y: number;
    seats: number;
    /** chair radius */
    chairR: number;
    /** a kids table (the auto-seater puts children here) */
    kids?: boolean;
    /** a name shown under the number ("Family", "Bride's friends") */
    label?: string;
}

export interface RoundTable extends TableBase {
    shape: 'round';
    /** table radius */
    r: number;
    /** radius of the ring the chairs sit on */
    ring: number;
}

interface LongTable extends TableBase {
    /** length (along the chairs) */
    w: number;
    /** depth */
    h: number;
    rotation: Rotation;
    /** chair centres in seat order, computed from the shape */
    chairs: Point[];
}

/** A banquet table: chairs on both sides (or the front only), optionally one at each end. */
export interface RectTable extends LongTable {
    shape: 'rect';
    sides: 'both' | 'one';
    heads: boolean;
}

/** A sofa / lounge: seats along its front. */
export interface SofaTable extends LongTable {
    shape: 'sofa';
}

/** A straight row of chairs (e.g. the VIP row). */
export interface RowTable extends LongTable {
    shape: 'row';
}

export type HallTable = RoundTable | RectTable | SofaTable | RowTable;

export interface Entrance {
    x: number;
    y: number;
    w: number;
    rotation?: 90;
}

export interface Obstacle extends Rect {
    id: string;
    kind: ObstacleKind;
    label?: string;
}

export interface HallLabel {
    id: string;
    text: string;
    x: number;
    y: number;
    size: number;
}

export interface HallLayout {
    id: string;
    name: string;
    size: { w: number; h: number };
    stage?: Rect & { label: string };
    danceFloor?: Rect;
    entrances: Entrance[];
    sections: HallSection[];
    tables: HallTable[];
    obstacles?: Obstacle[];
    labels?: HallLabel[];
}

export const MAX_SEATS = 40;
export const CHAIR_GAP = 2;

export const round1 = (v: number) => Math.round(v * 10) / 10;

const spread = (k: number, n: number, len: number) => -len / 2 + (len * (k + 0.5)) / n;

/**
 * Chair centres of a table in seat order (seat_index 0 first); same rules as
 * HallLayoutData::chairPositions. A table turned 90° has its "top" on the right.
 */
export type TableInput = RoundTable | Omit<RectTable, 'chairs'> | Omit<SofaTable, 'chairs'> | Omit<RowTable, 'chairs'>;

export function chairPositions(table: TableInput): Point[] {
    const { x, y, seats } = table;

    if (table.shape === 'round') {
        return Array.from({ length: seats }, (_, c) => {
            const a = -Math.PI / 2 + (c * 2 * Math.PI) / seats;
            return { x: round1(x + table.ring * Math.cos(a)), y: round1(y + table.ring * Math.sin(a)) };
        });
    }

    const { w, h, chairR } = table;
    const local: [number, number][] = [];

    if (table.shape === 'rect') {
        const gap = chairR + CHAIR_GAP;
        const heads = table.heads && seats >= 3 ? 2 : 0;
        const side = seats - heads;
        const back = table.sides === 'one' ? 0 : Math.ceil(side / 2);
        const front = side - back;
        for (let k = 0; k < back; k++) local.push([spread(k, back, w), -(h / 2 + gap)]);
        if (heads) local.push([w / 2 + gap, 0]);
        for (let k = front - 1; k >= 0; k--) local.push([spread(k, front, w), h / 2 + gap]);
        if (heads) local.push([-(w / 2 + gap), 0]);
    } else if (table.shape === 'sofa') {
        for (let k = 0; k < seats; k++) local.push([spread(k, seats, w), h * 0.12]);
    } else {
        for (let k = 0; k < seats; k++) local.push([spread(k, seats, w), 0]);
    }

    const turned = table.rotation === 90;
    return local.map(([lx, ly]) => (turned ? { x: round1(x - ly), y: round1(y + lx) } : { x: round1(x + lx), y: round1(y + ly) }));
}

/** The chairs of any table: stored ones for long tables (recomputed when missing), computed for round ones. */
export function chairsOf(table: HallTable): Point[] {
    if (table.shape !== 'round' && table.chairs?.length === table.seats) return table.chairs;
    return chairPositions(table);
}

/** Half the width and height a table takes with its chairs, after rotation (HallLayoutData::extent). */
export function extent(table: HallTable): [number, number] {
    if (table.shape === 'round') {
        const e = table.ring + table.chairR;
        return [e, e];
    }
    let hw = table.w / 2;
    let hh = table.h / 2;
    if (table.shape === 'rect') {
        const reach = table.chairR * 2 + CHAIR_GAP;
        hw += table.heads && table.seats >= 3 ? reach : 0;
        hh += reach;
    } else if (table.shape === 'row') {
        hh = Math.max(hh, table.chairR);
    }
    return table.rotation === 90 ? [hh, hw] : [hw, hh];
}

/** The table top's size after rotation, without chairs ([2r, 2r] for round tables). */
export function bodySize(table: HallTable): [number, number] {
    if (table.shape === 'round') return [table.r * 2, table.r * 2];
    return table.rotation === 90 ? [table.h, table.w] : [table.w, table.h];
}

/** Bounding box of a table with its chairs. */
export function tableBox(table: HallTable): Rect {
    const [hw, hh] = extent(table);
    return { x: table.x - hw, y: table.y - hh, w: hw * 2, h: hh * 2 };
}

/** Recompute the chairs of a long table after its size, seats, sides or rotation changed. */
export function withChairs<T extends HallTable>(table: T): T {
    if (table.shape === 'round') return table;
    return { ...table, chairs: chairPositions(table) };
}

export const isKidsTable = (table: HallTable) => table.kids === true;
