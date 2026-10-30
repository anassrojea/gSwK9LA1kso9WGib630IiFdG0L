/**
 * Hall layout generator (plan sections 7.1 and 7.2), ported from the plan
 * page (docs/samples/index.html). Same algorithm as app/Services/HallGenerator.php,
 * so the seating editor can preview instantly and the server stays the source of truth
 * (tests/Fixtures/hall-geometry.json holds the expected output of both).
 *
 * Types and table geometry live in ./hall-layout.ts (re-exported here).
 */
import { chairPositions, round1, type HallLayout, type HallSection, type HallTable, type RectTable, type Rect, type RoundTable } from './hall-layout.ts';

export * from './hall-layout.ts';

export type HallLayoutMode = 'mixed' | 'split';
export type GeneratorShape = 'round' | 'rect';

export const HALL_WIDTH = 800;
export const HALL_HEIGHT = 580;
const AREA_TOP = 190;
const AREA_BOTTOM_MARGIN = 40;
export const MAX_TABLES = 200;
export const MAX_CHAIRS = 24;

export interface GenerateOptions {
    tables: number;
    chairs: number;
    layout?: HallLayoutMode;
    shape?: GeneratorShape;
    name?: string;
    id?: string;
}

export function generateHall({ tables, chairs, layout = 'mixed', shape = 'round', name = 'Main hall', id }: GenerateOptions): HallLayout {
    if (!Number.isInteger(tables) || tables < 1 || tables > MAX_TABLES) throw new RangeError(`tables must be between 1 and ${MAX_TABLES}`);
    if (!Number.isInteger(chairs) || chairs < 1 || chairs > MAX_CHAIRS) throw new RangeError(`chairs must be between 1 and ${MAX_CHAIRS}`);
    if (shape !== 'round' && shape !== 'rect') throw new RangeError('shape must be round or rect');

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
        const n = counts[section.id];
        placed.push(...(shape === 'rect' ? placeBanquet(n, section.area, placed.length, section.id, chairs) : placeTables(n, section.area, placed.length, section.id, chairs)));
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
function placeTables(n: number, area: Rect, startIndex: number, section: HallSection['id'], chairs: number): RoundTable[] {
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
            shape: 'round' as const,
            x: round1(area.x + offset + cw * col + cw / 2),
            y: round1(area.y + ch * row + ch / 2),
            seats: chairs,
            r: round1(tableR),
            chairR: round1(chairR),
            ring: round1(ring),
        };
    });
}

/**
 * Banquet tables: cols = round(sqrt(n × w / 2h)), table length 80% of the cell,
 * depth 30% of it (at least 14), chairs on both sides.
 */
function placeBanquet(n: number, area: Rect, startIndex: number, section: HallSection['id'], chairs: number): RectTable[] {
    if (n === 0) return [];

    const cols = Math.max(1, Math.round(Math.sqrt((n * area.w) / (area.h * 2))));
    const rows = Math.ceil(n / cols);
    const cw = area.w / cols;
    const ch = area.h / rows;

    const perSide = Math.ceil(chairs / 2);
    const w = cw * 0.8;
    const h = Math.max(14, ch * 0.3);
    const chairR = Math.max(3.2, Math.min(9, (w / perSide) * 0.38, (ch / 2 - h / 2 - 4) / 2));

    return Array.from({ length: n }, (_, i) => {
        const row = Math.floor(i / cols);
        const col = i % cols;
        const inRow = Math.min(cols, n - row * cols);
        const offset = ((cols - inRow) * cw) / 2;

        const table: Omit<RectTable, 'chairs'> = {
            id: `T${startIndex + i + 1}`,
            section,
            shape: 'rect',
            x: round1(area.x + offset + cw * col + cw / 2),
            y: round1(area.y + ch * row + ch / 2),
            seats: chairs,
            w: round1(w),
            h: round1(h),
            rotation: 0,
            sides: 'both',
            heads: false,
            chairR: round1(chairR),
        };
        return { ...table, chairs: chairPositions(table) };
    });
}
