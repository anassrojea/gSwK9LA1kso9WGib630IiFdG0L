/**
 * The Zod schema (editor) must agree with the JSON Schema (server) on the
 * same fixtures as tests/Feature/InvitationConfigTest.php. Run: npm run test:js
 */
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { test } from 'node:test';

import { validateConfig } from '../../resources/js/lib/config-schema.ts';
import { generateHall } from '../../resources/js/lib/hall-generator.ts';

const root = new URL('../../', import.meta.url);
const read = (path: string) => JSON.parse(readFileSync(new URL(path, root), 'utf8'));
const example = () => read('tests/Fixtures/config-example.json');

test('accepts the plan section 6.2 example', () => {
    assert.deepEqual(validateConfig(example()), { success: true, data: example() });
});

test('accepts every template in templates/invitations', () => {
    for (const file of readdirSync(new URL('templates/invitations/', root))) {
        const result = validateConfig(read(`templates/invitations/${file}`).default_config);
        assert.equal(result.success, true, `${file}: ${JSON.stringify(!result.success && result.errors)}`);
    }
});

const bad: Record<string, [(c: any) => void, string]> = {
    'unsupported version': [(c) => (c.version = 99), '/version'],
    'unknown opening': [(c) => (c.opening.id = 'confetti-cannon'), '/opening/id'],
    'color that is not hex': [(c) => (c.theme.colors.bg = 'red'), '/theme/colors/bg'],
    'missing theme color': [(c) => delete c.theme.colors.accent, '/theme/colors'],
    'font with css injection': [(c) => (c.theme.fonts.body = 'Amiri;}</style>'), '/theme/fonts/body'],
    'unknown top-level key': [(c) => (c.script = 'alert(1)'), '/'],
    'no sections': [(c) => (c.sections = []), '/sections'],
    'unknown section type': [(c) => c.sections.push({ type: 'fireworks' }), '/sections/11'],
    'section with an unknown property': [(c) => (c.sections[0].onclick = 'x'), '/sections/0'],
    'date that is not a date': [(c) => (c.sections[3].start = 'next Thursday'), '/sections/3'],
    'program time out of range': [(c) => (c.sections[5].items[0].time = '25:00'), '/sections/5'],
    'latitude out of range': [(c) => (c.sections[6].lat = 123), '/sections/6'],
    'dress code color not hex': [(c) => c.sections[7].colors.push('pink'), '/sections/7'],
    'gallery photo over http': [(c) => c.sections.push({ type: 'gallery', photos: [{ src: 'http://evil.test/x.jpg' }] }), '/sections/11'],
    'meal options that repeat': [(c) => (c.sections[9].mealOptions = ['لحم', 'لحم']), '/sections/9'],
    'dress code with neither colors nor note': [(c) => (c.sections[7] = { type: 'dress_code' }), '/sections/7'],
};

for (const [name, [mutate, pointer]] of Object.entries(bad)) {
    test(`rejects ${name}`, () => {
        const config = example();
        mutate(config);
        const result = validateConfig(config);
        assert.equal(result.success, false);
        assert.ok(
            !result.success && Object.keys(result.errors).some((k) => k === pointer || k.startsWith(pointer === '/' ? '/' : `${pointer}/`)),
            `expected an error at ${pointer}, got ${!result.success && Object.keys(result.errors)}`,
        );
    });
}

test('hall generator (TS) returns the requested table and chair counts', () => {
    for (const [tables, chairs, layout] of [[1, 8, 'mixed'], [20, 8, 'mixed'], [21, 10, 'split'], [120, 12, 'split']] as const) {
        const hall = generateHall({ tables, chairs, layout });
        assert.equal(hall.tables.length, tables);
        assert.equal(hall.tables.reduce((sum, t) => sum + t.seats, 0), tables * chairs);
    }
    const split = generateHall({ tables: 21, chairs: 8, layout: 'split' });
    assert.equal(split.tables.filter((t) => t.section === 'men').length, 11);
});
