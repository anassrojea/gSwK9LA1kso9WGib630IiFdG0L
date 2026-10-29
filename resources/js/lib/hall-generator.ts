/**
 * Hall layout generator (plan sections 7.1 and 7.2), ported from the plan
 * page (docs/samples/index.html). Same algorithm as app/Services/HallGenerator.php,
 * so the seating editor can preview instantly and the server stays the source of truth.
 */

export type HallLayoutMode = 'mixed' | 'split';
export type TableShape = 'round';

export interface Rect {
    x: number;
    y: number;
    w: number;
    h: number;
}

export interface HallSection {
    id: 'mixed' | 'men' | 'women';
    label: string;
    area: Rect;
}

export interface HallTable {
    id: string;
    section: HallSection['id'];
    shape: TableShape;
    x: number;
    y: number;
    seats: number;
    /** table radius */
    r: number;
    /** chair radius */
    chairR: number;
    /** radius of the ring the chairs sit on */
    ring: number;
}

export interface HallLayout {
    id: string;
    name: string;
    size: { w: number; h: number };
    stage: Rect & { label: string };
    danceFloor: Rect;
    entrances: { x: number; y: number; w: number }[];
    sections: HallSection[];
    tables: HallTable[];
}

export const HALL_WIDTH = 800;
export const HALL_HEIGHT = 580;
const AREA_TOP = 190;
const AREA_BOTTOM_MARGIN = 40;
export const MAX_TABLES = 200;
export const MAX_CHAIRS = 24;

const round1 = (v: number) => Math.round(v * 10) / 10;

export interface GenerateOptions {
    tables: number;
    chairs: number;
    layout?: HallLayoutMode;
    shape?: TableShape;
    name?: string;
    id?: string;
}

export function generateHall({ tables, chairs, layout = 'mixed', shape = 'round', name = 'Main hall', id }: GenerateOptions): HallLayout {
    if (!Number.isInteger(tables) || tables < 1 || tables > MAX_TABLES) throw new RangeError(`tables must be between 1 and ${MAX_TABLES}`);
    if (!Number.isInteger(chairs) || chairs < 1 || chairs > MAX_CHAIRS) throw new RangeError(`chairs must be between 1 and ${MAX_CHAIRS}`);

    const top = AREA_TOP;
    const height = HALL_HEIGHT - AREA_BOTTOM_MARGIN - top;

    let sections: HallSection[];
    let counts: Record<string, number>;

    if (layout === 'split') {
        const half = Math.ceil(tables / 2);
        sections = [
            { id: 'men', label: 'Men', area: { x: 24, y: top, w: 360, h: height } },
            { id: 'women', label: 'Women', area: { x: 416, y: top, w: 360, h: height } },
        ];
        counts = { men: half, women: tables - half };
    } else {
        sections = [{ id: 'mixed', label: 'Guests', area: { x: 24, y: top, w: 752, h: height } }];
        counts = { mixed: tables };
    }

    const placed: HallTable[] = [];
    for (const section of sections) {
        placed.push(...placeTables(counts[section.id], section.area, placed.length, section.id, chairs, shape));
    }

    return {
        id: id ?? `hall_${Date.now().toString(36)}`,
        name,
        size: { w: HALL_WIDTH, h: HALL_HEIGHT },
        stage: { x: 270, y: 24, w: 260, h: 62, label: 'Kosha' },
        danceFloor: { x: 310, y: 100, w: 180, h: 70 },
        entrances: [{ x: 360, y: HALL_HEIGHT - 14, w: 80 }],
        sections,
        tables: placed,
    };
}

/**
 * cols = round(sqrt(n × w / h)), rows = ceil(n / cols), last row centered.
 * Chair radius = 7.5% of the cell, ring = half the cell minus the chair radius,
 * table radius inside the ring.
 */
function placeTables(n: number, area: Rect, startIndex: number, section: HallSection['id'], chairs: number, shape: TableShape): HallTable[] {
    if (n === 0) return [];

    const cols = Math.max(1, Math.round(Math.sqrt((n * area.w) / area.h)));
    const rows = Math.ceil(n / cols);
    const cw = area.w / cols;
    const ch = area.h / rows;
    const cell = Math.min(cw, ch);

    const chairR = Math.max(3.2, Math.min(9, cell * 0.075));
    const ring = cell / 2 - chairR - 3;
    const tableR = Math.max(8, ring - chairR * 1.7);

    return Array.from({ length: n }, (_, i) => {
        const row = Math.floor(i / cols);
        const col = i % cols;
        const inRow = Math.min(cols, n - row * cols);
        const offset = ((cols - inRow) * cw) / 2;

        return {
            id: `T${startIndex + i + 1}`,
            section,
            shape,
            x: round1(area.x + offset + cw * col + cw / 2),
            y: round1(area.y + ch * row + ch / 2),
            seats: chairs,
            r: round1(tableR),
            chairR: round1(chairR),
            ring: round1(ring),
        };
    });
}

/** Chair centers around a round table, starting at 12 o'clock. */
export function chairPositions(table: HallTable): { x: number; y: number }[] {
    return Array.from({ length: table.seats }, (_, c) => {
        const a = -Math.PI / 2 + (c * 2 * Math.PI) / table.seats;
        return { x: round1(table.x + table.ring * Math.cos(a)), y: round1(table.y + table.ring * Math.sin(a)) };
    });
}
