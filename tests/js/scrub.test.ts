/**
 * Browser error reports are scrubbed like the server's (App\Support\SentryScrubber,
 * tests/Feature/Site/MonitoringTest.php). Run: npm run test:js
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { MASK, scrubText, scrubValue } from '../../resources/js/lib/scrub.ts';

test('masks phone numbers in Western and Arabic-Indic digits, and emails', () => {
    assert.equal(scrubText('call +966 50 123 4567 or sara@example.com'), `call ${MASK} or ${MASK}`);
    assert.equal(scrubText('رقم ٠٥٠١٢٣٤٥٦٧'), `رقم ${MASK}`);
    assert.equal(scrubText('00963912345678'), MASK);
});

test('leaves dates, times, order codes and UUIDs alone', () => {
    const text = '2026-11-03 20:30:00 DW-4821 2900 9b2f6c1e-1a2b-4c3d-8e9f-0a1b2c3d4e5f';
    assert.equal(scrubText(text), text);
});

test('drops values under sensitive keys, recursively', () => {
    assert.deepEqual(scrubValue({ phone: '0912345678', nested: { email: 'a@b.co', seats: 2 }, note: 'x 0501234567', ok: true }), {
        phone: MASK,
        nested: { email: MASK, seats: 2 },
        note: `x ${MASK}`,
        ok: true,
    });
});
