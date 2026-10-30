/**
 * Door app helpers: reading the token from a scanned code, warnings (same
 * rules as DoorDesk.php), offline entries and sync results.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { addLocalCheckin, applySync, parseToken, searchGuests, suggestedPeople, uuid, warnings, type DoorGuest, type QueuedScan } from '../../resources/js/lib/door/desk.ts';

const guest = (over: Partial<DoorGuest> = {}): DoorGuest => ({
    id: 'h1',
    token: 'AbCdEf123456',
    name: 'عائلة أحمد',
    seats: 4,
    status: 'attending',
    people: 3,
    kids: 1,
    section: 'men',
    table: '7',
    in: 0,
    checkins: [],
    first_in_at: null,
    ...over,
});

test('reads the token from a personal link or a bare token, and nothing else', () => {
    assert.equal(parseToken('https://daawa.link/i/AbCdEf123456'), 'AbCdEf123456');
    assert.equal(parseToken('http://127.0.0.1:8020/i/AbCdEf123456?open=1'), 'AbCdEf123456');
    assert.equal(parseToken('  AbCdEf123456 '), 'AbCdEf123456');
    assert.equal(parseToken('https://evil.example/x'), null);
    assert.equal(parseToken('WIFI:S:hall;T:WPA;;'), null);
});

test('no warning when the confirmed people walk in', () => {
    assert.deepEqual(warnings(guest(), 3), []);
    assert.equal(suggestedPeople(guest()), 3);
});

test('warns on a second entry, over the seat limit, partial and extra entries, and no reply', () => {
    const inside = addLocalCheckin([guest()], 'AbCdEf123456', 2, new Date('2026-12-10T19:05:00'), 'Ahmad')[0];
    const w = warnings(inside, 3);
    assert.deepEqual(w.map((x) => x.type), ['already_in', 'over_limit']);
    assert.equal((w[0] as { by: string }).by, 'Ahmad');
    assert.equal(suggestedPeople(inside), 1);

    assert.deepEqual(warnings(guest(), 1).map((x) => x.type), ['partial']);
    assert.deepEqual(warnings(guest(), 4).map((x) => x.type), ['extra']);
    assert.deepEqual(warnings(guest({ status: 'no_reply', people: null }), 2), [{ type: 'not_attending', status: 'no_reply' }]);
});

test('sync results empty the queue and keep conflicts for review', () => {
    const queue: QueuedScan[] = ['a', 'b', 'c', 'd'].map((id) => ({ scan_id: id, token: 'AbCdEf123456', people_in: 1, scanned_at: '', confirm: true, known_in: 0 }));
    const out = applySync(queue, [
        { scan_id: 'a', token: 'x', status: 'synced' },
        { scan_id: 'b', token: 'x', status: 'conflict', reason: 'checked_in_elsewhere' },
        { scan_id: 'c', token: 'x', status: 'invalid' },
    ]);
    assert.deepEqual(out.queue.map((s) => s.scan_id), ['d']);
    assert.deepEqual(out.conflicts.map((s) => s.scan_id), ['b']);
    assert.equal(out.synced, 1);
});

test('finds families by name and makes valid scan ids', () => {
    assert.deepEqual(searchGuests([guest()], 'احمد').map((g) => g.id), ['h1']);
    assert.deepEqual(searchGuests([guest()], ''), []);
    assert.match(uuid(), /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
});
