/**
 * Seating planner helpers: dropping families on tables, moving one person,
 * warnings (same rules as SeatingWarnings.php), filters and table nudging.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { generateHall } from '../../resources/js/lib/hall-generator.ts';
import {
    computeWarnings,
    filterFamilies,
    invalidSeats,
    moveSeat,
    nudgeTable,
    seatFamily,
    seatOne,
    summary,
    tableNumber,
    unassignSeat,
    type Assignment,
    type Family,
} from '../../resources/js/lib/seating/plan.ts';

const fam = (id: string, people: number, section: Family['section'] = null, kids = 0): Family => ({ id, name: `عائلة ${id}`, section, people, kids, seats: people, meal: null, tags: [] });
const hall = generateHall({ tables: 4, chairs: 4, layout: 'split', id: 'h' }); // T1, T2 men · T3, T4 women

test('a dropped family fills the free chairs of the table in order', () => {
    const families = [fam('a', 3), fam('b', 2)];
    let seats: Assignment[] = [{ household_id: 'b', table_id: 'T1', seat_index: 1 }];
    const result = seatFamily(hall, families, seats, 'a', 'T1');
    seats = result.assignments;

    assert.deepEqual(seats.filter((s) => s.household_id === 'a').map((s) => s.seat_index), [0, 2, 3]);
    assert.equal(result.missing, 0);
});

test('a family moves whole to the new table; who does not fit stays without a chair', () => {
    const families = [fam('a', 3), fam('b', 3)];
    let seats = seatFamily(hall, families, [], 'b', 'T2').assignments;
    seats = seatFamily(hall, families, seats, 'a', 'T1').assignments;
    const result = seatFamily(hall, families, seats, 'a', 'T2');

    assert.equal(result.missing, 2);
    assert.equal(result.assignments.filter((s) => s.household_id === 'a' && s.table_id === 'T1').length, 0);
    assert.equal(result.assignments.filter((s) => s.household_id === 'a').length, 1);
});

test('one person moves to another chair, swapping with whoever sat there', () => {
    const seats: Assignment[] = [
        { household_id: 'a', table_id: 'T1', seat_index: 0 },
        { household_id: 'b', table_id: 'T2', seat_index: 3 },
    ];
    const moved = moveSeat(seats, { table: 'T1', seat: 0 }, { table: 'T2', seat: 3 });

    assert.deepEqual(moved.map((s) => [s.household_id, s.table_id, s.seat_index]), [['a', 'T2', 3], ['b', 'T1', 0]]);
    assert.deepEqual(unassignSeat(moved, 'T2', 3).map((s) => s.household_id), ['b']);
});

test('seatOne never seats more people than a family brings', () => {
    const families = [fam('a', 1)];
    const once = seatOne(families, [], 'a', 'T1', 2);
    assert.equal(once.length, 1);
    assert.equal(seatOne(families, once, 'a', 'T1', 3).length, 1);
});

test('warnings: over capacity, split families, sections, unseated and stale seats', () => {
    const families = [fam('w', 3, 'women'), fam('m', 1, 'men'), fam('n', 2)];
    const seats: Assignment[] = [
        { household_id: 'w', table_id: 'T1', seat_index: 0 },
        { household_id: 'w', table_id: 'T3', seat_index: 0 },
        { household_id: 'm', table_id: 'T1', seat_index: 1 },
        { household_id: 'm', table_id: 'T1', seat_index: 9 },
        { household_id: 'gone', table_id: 'T3', seat_index: 1 },
    ];
    const w = computeWarnings(hall, families, seats);
    const of = (type: string) => w.filter((x) => x.type === type);

    assert.deepEqual(of('over_capacity').map((x) => (x as { table: string }).table), ['T1']);
    assert.deepEqual(of('section_mismatch').map((x) => (x as { household: string }).household), ['w']);
    assert.deepEqual(of('split').map((x) => (x as { tables: string[] }).tables), [['T1', 'T3']]);
    assert.deepEqual(of('stale').map((x) => (x as { household: string }).household).sort(), ['gone', 'm']);
    assert.deepEqual(of('unseated').map((x) => [(x as { household: string }).household, (x as { count: number }).count]), [['w', 1], ['n', 2]]);

    // the seats the server would refuse
    assert.deepEqual(invalidSeats(hall, families, seats).map((s) => `${s.household_id}@${s.table_id}#${s.seat_index}`), ['m@T1#9', 'gone@T3#1']);
});

test('no warnings for a clean plan', () => {
    const families = [fam('a', 2, 'men'), fam('b', 4, 'women')];
    let seats = seatFamily(hall, families, [], 'a', 'T1').assignments;
    seats = seatFamily(hall, families, seats, 'b', 'T3').assignments;
    assert.deepEqual(computeWarnings(hall, families, seats), []);
    assert.deepEqual(summary(families, seats, hall), { people: 6, seated: 6, chairs: 16, unseated: 0 });
});

test('filters by Arabic name (ignoring hamza and ta marbuta), section and seat status', () => {
    const families = [
        { ...fam('1', 2, 'men'), name: 'عائلة أحمد' },
        { ...fam('2', 2, 'women'), name: 'أسرة فاطمة' },
        { ...fam('3', 1), name: 'Omar' },
    ];
    const seats: Assignment[] = [{ household_id: '1', table_id: 'T1', seat_index: 0 }];
    const ids = (f: Family[]) => f.map((x) => x.id);

    assert.deepEqual(ids(filterFamilies(families, seats, { query: 'احمد', section: 'all', seated: 'all' })), ['1']);
    assert.deepEqual(ids(filterFamilies(families, seats, { query: 'فاطمه', section: 'all', seated: 'all' })), ['2']);
    assert.deepEqual(ids(filterFamilies(families, seats, { query: '', section: 'none', seated: 'all' })), ['3']);
    assert.deepEqual(ids(filterFamilies(families, seats, { query: '', section: 'all', seated: 'seated' })), ['1']);
    assert.deepEqual(ids(filterFamilies(families, seats, { query: '', section: 'all', seated: 'unseated' })), ['1', '2', '3']);
});

test('table numbers and nudging stay inside the hall', () => {
    assert.equal(tableNumber('T07'), '7');
    assert.equal(tableNumber('VIP'), 'VIP');

    const moved = nudgeTable(hall, 'T1', -5000, 10);
    const t = moved.tables[0];
    assert.equal(t.x, Math.round((t.ring + t.chairR) * 10) / 10);
    assert.equal(t.y, hall.tables[0].y + 10);
    assert.equal(moved.tables[1], hall.tables[1]);
});
