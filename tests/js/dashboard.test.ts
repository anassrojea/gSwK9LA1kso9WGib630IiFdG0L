/**
 * Host dashboard helpers: status / tag / search filters, live row updates,
 * relative "last opened" times and tag parsing.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { countByStatus, filterGuests, fold, parseTags, percent, relativeTime, upsertGuest, type GuestRow } from '../../resources/js/lib/dashboard/guests.ts';

const row = (id: string, name: string, status: GuestRow['status'], tags: string[] = [], phone: string | null = null): GuestRow => ({
    id,
    name,
    phone,
    seats: 2,
    tags,
    status,
    people: status === 'attending' ? 2 : null,
    kids: null,
    note: null,
    meal: null,
    allergies: null,
    responded_at: null,
    table: null,
    open_count: 0,
    first_opened_at: null,
    last_opened_at: null,
    last_reminded_at: null,
    checked_in_at: null,
    people_in: null,
    link: `https://daawa.link/i/${id}`,
    whatsapp_url: 'https://wa.me/?text=x',
});

const guests = [
    row('a', 'أم فيصل', 'not_opened', ['جيران']),
    row('b', 'عائلة خالد العلي', 'opened', ['أقارب'], '+962791234567'),
    row('c', 'Sara & Omar', 'attending', ['work', 'أقارب']),
    row('d', 'عائلة الزين', 'declined'),
];
const ids = (list: GuestRow[]) => list.map((g) => g.id);

test('filters by status', () => {
    assert.deepEqual(ids(filterGuests(guests, { status: 'attending', tag: 'all', query: '' })), ['c']);
    assert.deepEqual(ids(filterGuests(guests, { status: 'not_opened', tag: 'all', query: '' })), ['a']);
    assert.deepEqual(ids(filterGuests(guests, { status: 'all', tag: 'all', query: '' })), ['a', 'b', 'c', 'd']);
});

test('filters by tag and status together', () => {
    assert.deepEqual(ids(filterGuests(guests, { status: 'all', tag: 'أقارب', query: '' })), ['b', 'c']);
    assert.deepEqual(ids(filterGuests(guests, { status: 'opened', tag: 'أقارب', query: '' })), ['b']);
});

test('searches names loosely (hamza, ta marbuta) and phones by digits', () => {
    assert.deepEqual(ids(filterGuests(guests, { status: 'all', tag: 'all', query: 'ام فيصل' })), ['a']);
    assert.deepEqual(ids(filterGuests(guests, { status: 'all', tag: 'all', query: 'عائله' })), ['b', 'd']);
    assert.deepEqual(ids(filterGuests(guests, { status: 'all', tag: 'all', query: 'sara' })), ['c']);
    assert.deepEqual(ids(filterGuests(guests, { status: 'all', tag: 'all', query: '٧٩١٢٣' })), ['b']);
    assert.equal(fold('  إِيمان  '), 'ايمان');
});

test('counts families per status', () => {
    assert.deepEqual(countByStatus(guests), { not_opened: 1, opened: 1, attending: 1, declined: 1 });
});

test('replaces a row from a broadcast in place, or appends a new one', () => {
    const updated = upsertGuest(guests, { ...guests[1], status: 'attending' });
    assert.equal(updated[1].status, 'attending');
    assert.equal(updated.length, 4);
    assert.equal(guests[1].status, 'opened'); // the old list is untouched
    assert.deepEqual(ids(upsertGuest(guests, row('e', 'New', 'not_opened'))), ['a', 'b', 'c', 'd', 'e']);
});

test('writes "last opened" relative to now, with Arabic-Indic digits in Arabic', () => {
    const now = Date.parse('2026-10-29T12:00:00Z');
    assert.equal(relativeTime('2026-10-29T09:00:00Z', 'en', now), '3 hours ago');
    assert.equal(relativeTime('2026-10-28T12:00:00Z', 'en', now), 'yesterday');
    assert.equal(relativeTime('2026-10-29T11:59:50Z', 'en', now), 'now');
    const ar = relativeTime('2026-10-29T09:00:00Z', 'ar', now);
    assert.match(ar, /٣/);
    assert.doesNotMatch(ar, /3/);
});

test('parses typed tags', () => {
    assert.deepEqual(parseTags('أقارب، أصدقاء, work ,أقارب'), ['أقارب', 'أصدقاء', 'work']);
    assert.deepEqual(parseTags(' '), []);
    assert.equal(parseTags('a,b,c,d', 2).length, 2);
});

test('percent never divides by zero', () => {
    assert.equal(percent(1, 4), 25);
    assert.equal(percent(0, 0), 0);
});
