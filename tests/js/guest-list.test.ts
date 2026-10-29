/**
 * The wizard's live guest-list preview must read pasted lines exactly like
 * App\Services\Wizard\GuestListParser (same fixture as the Pest test).
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

import { parseGuestLine, parseGuestList } from '../../resources/js/lib/guest-list.ts';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/guest-list.json', import.meta.url), 'utf8')) as {
    lines: [string, string, number][];
    skipped: string[];
};

test('reads names and seat counts like the server', () => {
    for (const [line, name, seats] of fixture.lines) {
        assert.deepEqual(parseGuestLine(line), { name, seats }, JSON.stringify(line));
    }
});

test('skips empty lines', () => {
    for (const line of fixture.skipped) {
        assert.equal(parseGuestLine(line), null, JSON.stringify(line));
    }
    assert.equal(parseGuestList('أم فيصل - 3\n\n\nKhaled Ali, 2\r\n').length, 2);
});
