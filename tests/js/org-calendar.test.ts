/**
 * The venue calendar helpers (resources/js/lib/org/calendar.ts, phase 8A).
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { byDay, inMonth, localDay, monthGrid, shiftMonth } from '../../resources/js/lib/org/calendar.ts';

test('moves between months across years', () => {
    assert.equal(shiftMonth('2026-11', 1), '2026-12');
    assert.equal(shiftMonth('2026-12', 1), '2027-01');
    assert.equal(shiftMonth('2026-01', -1), '2025-12');
});

test('builds whole weeks starting on Saturday or Sunday', () => {
    // November 2026 starts on a Sunday
    const saturday = monthGrid('2026-11', 6);
    assert.ok(saturday.every((week) => week.length === 7));
    assert.equal(saturday[0][0], '2026-10-31');
    assert.equal(saturday[0][1], '2026-11-01');
    assert.ok(saturday.at(-1)!.includes('2026-11-30'));

    const sunday = monthGrid('2026-11', 0);
    assert.equal(sunday[0][0], '2026-11-01');
    assert.equal(sunday.length, 5);
});

test("places events on their own time zone's date", () => {
    // 21:30 UTC on Nov 5 is already Nov 6 in Riyadh (UTC+3)
    assert.equal(localDay({ starts_at: '2026-11-05T21:30:00Z', timezone: 'Asia/Riyadh' }), '2026-11-06');
    assert.equal(localDay({ starts_at: '2026-11-05T21:30:00Z', timezone: 'UTC' }), '2026-11-05');
    assert.equal(localDay({ starts_at: null, timezone: 'Asia/Riyadh' }), null);
});

test('groups by day in time order, undated last', () => {
    const days = byDay([
        { id: 'b', starts_at: '2026-11-06T18:00:00Z', timezone: 'UTC' },
        { id: 'a', starts_at: '2026-11-06T09:00:00Z', timezone: 'UTC' },
        { id: 'c', starts_at: null, timezone: null },
    ]);
    assert.deepEqual(days.get('2026-11-06')!.map((e) => e.id), ['a', 'b']);
    assert.deepEqual(days.get('')!.map((e) => e.id), ['c']);
    assert.ok(inMonth('2026-11-06', '2026-11'));
    assert.ok(!inMonth('2026-10-31', '2026-11'));
});
