/**
 * The hall designer's editing logic (resources/js/lib/halls/designer.ts):
 * adding shapes, moving with snap, duplicate, rotate, delete, renumbering in
 * reading order (RTL too), overlap warnings and rename tracking.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { generateHall } from '../../resources/js/lib/hall-generator.ts';
import { chairPositions, tableBox } from '../../resources/js/lib/hall-layout.ts';
import {
    addLabel,
    addObstacle,
    addSection,
    addTable,
    applyRenames,
    deleteElements,
    duplicateElements,
    duplicateIds,
    moveElements,
    nextTableId,
    overlaps,
    refsInRect,
    renamesFrom,
    renumberTables,
    rotateElements,
    totals,
    updateTable,
} from '../../resources/js/lib/halls/designer.ts';
import { hallMap } from '../../resources/js/invite/day.js';
import { computeWarnings } from '../../resources/js/lib/seating/plan.ts';

const base = () => generateHall({ tables: 6, chairs: 8, id: 'h' });
const labels = { mixed: 'Guests', men: 'Men', women: 'Women', vip: 'VIP' } as const;

test('adds every shape with a unique id and its chairs', () => {
    let hall = base();
    for (const kind of ['round', 'rect', 'sofa', 'row', 'kids'] as const) {
        const r = addTable(hall, kind, 400, 300);
        hall = r.layout;
        const t = hall.tables.find((x) => x.id === r.ref.id)!;
        assert.equal(t.id, `T${hall.tables.length}`);
        if (t.shape !== 'round') assert.deepEqual(t.chairs, chairPositions(t));
    }
    assert.deepEqual(duplicateIds(hall), []);
    assert.equal(hall.tables.at(-1)?.kids, true);
    assert.deepEqual(
        hall.tables.slice(6).map((t) => t.shape),
        ['round', 'rect', 'sofa', 'row', 'round'],
    );
});

test('moves a selection together, snapped to the grid and kept inside the hall', () => {
    const hall = base();
    const refs = [
        { kind: 'table' as const, id: 'T1' },
        { kind: 'table' as const, id: 'T2' },
    ];
    const before = hall.tables.slice(0, 2).map(tableBox);
    const moved = moveElements(hall, refs, 13, 7, 10);
    const lead = tableBox(moved.tables[0]);
    assert.equal(lead.x % 10, 0);
    assert.equal(lead.y % 10, 0);
    // both moved by the same amount
    const d0 = moved.tables[0].x - hall.tables[0].x;
    assert.equal(Math.round((moved.tables[1].x - hall.tables[1].x) * 10) / 10, Math.round(d0 * 10) / 10);
    assert.ok(before[0].x !== lead.x);

    const far = moveElements(hall, refs, -5000, -5000);
    for (const t of far.tables.slice(0, 2)) assert.ok(tableBox(t).x >= 0 && tableBox(t).y >= 0);
});

test('duplicates with new ids and deletes, keeping at least one table', () => {
    const hall = base();
    const dup = duplicateElements(hall, [{ kind: 'table', id: 'T3' }]);
    assert.equal(dup.layout.tables.length, 7);
    assert.deepEqual(dup.refs, [{ kind: 'table', id: 'T7' }]);
    assert.equal(nextTableId(dup.layout), 'T8');

    const all = hall.tables.map((t) => ({ kind: 'table' as const, id: t.id }));
    assert.equal(deleteElements(hall, all).tables.length, 1);
    assert.equal(deleteElements(hall, [{ kind: 'stage', id: 'stage' }]).stage, undefined);
});

test('rotates long tables, obstacles and entrances in 90° steps', () => {
    let hall = addTable(base(), 'rect', 400, 300).layout;
    hall = addObstacle(hall, 'buffet', 600, 500).layout;
    const refs = [
        { kind: 'table' as const, id: 'T7' },
        { kind: 'obstacle' as const, id: 'O1' },
        { kind: 'entrance' as const, id: '0' },
    ];
    const once = rotateElements(hall, refs);
    const t = once.tables.find((x) => x.id === 'T7')!;
    assert.equal(t.shape === 'rect' && t.rotation, 90);
    assert.deepEqual([once.obstacles![0].w, once.obstacles![0].h], [36, 140]);
    assert.equal(once.entrances[0].rotation, 90);
    const twice = rotateElements(once, refs);
    assert.equal(twice.entrances[0].rotation, undefined);
    const back = twice.tables.find((x) => x.id === 'T7')!;
    assert.equal(back.shape === 'rect' && back.rotation, 0);
});

test('edits a table from the inspector: seats, id, kids, shape', () => {
    const hall = base();
    const grown = updateTable(hall, 'T1', { seats: 24 })!;
    const t1 = grown.tables[0];
    assert.equal(t1.seats, 24);
    const old = hall.tables[0];
    assert.ok(t1.shape === 'round' && old.shape === 'round' && t1.ring > old.ring, 'the ring grows with the seats');

    assert.equal(updateTable(hall, 'T1', { id: 'T2' }), null, 'ids stay unique');
    const renamed = updateTable(hall, 'T1', { id: 'T40', kids: true, label: ' Kids ' })!;
    assert.equal(renamed.tables[0].id, 'T40');
    assert.equal(renamed.tables[0].kids, true);
    assert.equal(renamed.tables[0].label, 'Kids');

    const banquet = updateTable(hall, 'T2', { shape: 'rect', seats: 30, heads: true })!;
    const t2 = banquet.tables[1];
    assert.equal(t2.shape, 'rect');
    assert.equal(t2.seats, 30);
    assert.equal(t2.shape === 'rect' && t2.chairs.length, 30);
    assert.equal(updateTable(hall, 'T3', { seats: 99 })!.tables[2].seats, 24, 'round tables keep the generator limit');
});

test('renumbers in reading order, left to right or right to left', () => {
    const hall = base();
    const shuffled = { ...hall, tables: [...hall.tables].reverse().map((t, i) => ({ ...t, id: `X${i}` })) };
    const ltr = renumberTables(shuffled);
    assert.deepEqual(
        ltr.layout.tables.map((t) => [t.id, t.x, t.y]),
        hall.tables.map((t) => [t.id, t.x, t.y]),
    );
    const inRow = hall.tables.filter((t) => t.y === hall.tables[0].y).length; // 4 + 2
    const rtl = renumberTables(hall, { rtl: true });
    const row1 = rtl.layout.tables.slice(0, inRow);
    for (let i = 1; i < inRow; i++) assert.ok(row1[i - 1].x > row1[i].x);
    assert.equal(rtl.renames.T1, `T${inRow}`);
    assert.equal(rtl.renames[`T${inRow}`], 'T1');
    assert.equal(renumberTables(hall).renames.T1, undefined, 'already in order: nothing changes');
});

test('tracks renames for the save, through several edits', () => {
    let origin = { T1: 'T1', T2: 'T2', T3: 'T3' } as Record<string, string>;
    origin = applyRenames(origin, { T1: 'T3', T3: 'T1' });
    origin = applyRenames(origin, { T3: 'T9' });
    assert.deepEqual(renamesFrom(origin), { T1: 'T9', T3: 'T1' });
});

test('warns about overlaps (never blocks) and counts seats', () => {
    const hall = base();
    assert.deepEqual(overlaps(hall), []);
    const crowded = moveElements(hall, [{ kind: 'table', id: 'T2' }], hall.tables[0].x - hall.tables[1].x + 10, 0);
    assert.deepEqual(overlaps(crowded)[0], { a: { kind: 'table', id: 'T1' }, b: { kind: 'table', id: 'T2' } });
    const onStage = moveElements(hall, [{ kind: 'table', id: 'T1' }], 400 - hall.tables[0].x, 60 - hall.tables[0].y);
    assert.ok(overlaps(onStage).some((o) => o.b.kind === 'stage'));
    assert.deepEqual(totals(addTable(hall, 'kids', 100, 100).layout), { tables: 7, seats: 56, kids: 8 });
});

test('adds sections, labels and finds elements in a marquee', () => {
    const withVip = addSection(base(), 'vip', labels)!;
    assert.equal(withVip.layout.sections.at(-1)?.id, 'vip');
    assert.equal(addSection(withVip.layout, 'vip', labels), null);
    const row = addTable(withVip.layout, 'row', 50, 50);
    assert.equal(row.layout.tables.at(-1)?.section, 'vip', 'a new VIP row joins the VIP section');

    const labelled = addLabel(base(), 'Bar', 700, 120).layout;
    const caught = refsInRect(labelled, { x: 0, y: 0, w: 800, h: 580 });
    assert.ok(caught.some((r) => r.kind === 'label'));
    assert.ok(!caught.some((r) => r.kind === 'section'));
});

test('the guest mini-map draws banquet tables as rectangles', () => {
    const svg = hallMap({ w: 800, h: 580, stage: null, mine: '2', tables: [['1', 100, 100, 20], ['2', 300, 200, 15, 120, 58]] });
    assert.match(svg, /<circle class="m-t" cx="100" cy="100" r="20"\/>/);
    assert.match(svg, /<rect class="m-mine" x="238" y="169" width="124" height="62"/);
});

test('children at a kids table do not split their family (same rule as SeatingWarnings.php)', () => {
    const hall = generateHall({ tables: 3, chairs: 4, id: 'h' });
    hall.tables[2] = { ...hall.tables[2], kids: true };
    const families = [{ id: 'a', name: 'A', section: null, people: 3, kids: 1, seats: 3, meal: null, tags: [] }];
    const seats = [
        { household_id: 'a', table_id: 'T1', seat_index: 0 },
        { household_id: 'a', table_id: 'T1', seat_index: 1 },
        { household_id: 'a', table_id: 'T3', seat_index: 0 },
    ];
    assert.deepEqual(computeWarnings(hall, families, seats), []);
    const split = computeWarnings(hall, families, [...seats.slice(0, 2), { household_id: 'a', table_id: 'T2', seat_index: 0 }]);
    assert.equal(split[0]?.type, 'split');
});
