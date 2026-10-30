/**
 * The Zod schema (editor) must agree with the JSON Schema (server) on the
 * same fixtures as tests/Feature/InvitationConfigTest.php. Run: npm run test:js
 */
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { test } from 'node:test';

import { CONFIG_VERSION, KINDS, OCCASIONS, OCCASIONS_V1, SECTION_TYPES, upgradeConfig, validateConfig } from '../../resources/js/lib/config-schema.ts';
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

/* ---------- schema v2 (phase 8D), same fixtures as tests/Feature/Journey/ConfigV2Test.php ---------- */

const exampleV2 = () => read('tests/Fixtures/config-example-v2.json');

test('accepts the v2 example (new occasion, kind, thank_you_gallery)', () => {
    assert.deepEqual(validateConfig(exampleV2()), { success: true, data: exampleV2() });
});

test('keeps v1 documents valid as v1, and upgrades them by changing only the version', () => {
    const v1 = example();
    assert.equal(validateConfig(v1).success, true);
    const upgraded = upgradeConfig(v1);
    assert.deepEqual(upgraded, { ...v1, version: 2 });
    assert.equal(validateConfig(upgraded).success, true);
    assert.deepEqual(upgradeConfig(exampleV2()), exampleV2());
});

const badV1: Record<string, [(c: any) => void, string]> = {
    'a v2 occasion in a v1 document': [(c) => (c.occasion = 'katb_kitab'), '/occasion'],
    'kind in a v1 document': [(c) => (c.kind = 'invitation'), '/'],
    'a thank-you gallery in a v1 document': [(c) => c.sections.push({ type: 'thank_you_gallery', message: 'شكراً' }), '/sections/11'],
};

const badV2: Record<string, [(c: any) => void, string]> = {
    'unknown kind': [(c) => (c.kind = 'flyer'), '/kind'],
    'unknown occasion': [(c) => (c.occasion = 'party'), '/occasion'],
    'thank-you gallery without a message': [(c) => delete c.sections[11].message, '/sections/11'],
    'thank-you gallery with 25 photos': [(c) => (c.sections[11].photos = Array.from({ length: 25 }, () => ({ src: '/storage/a.jpg' }))), '/sections/11'],
    'thank-you photo over http': [(c) => (c.sections[11].photos[0].src = 'http://evil.test/x.jpg'), '/sections/11'],
    'thank-you signature too long': [(c) => (c.sections[11].signature = 'x'.repeat(121)), '/sections/11'],
    'unknown top-level key': [(c) => (c.script = 'alert(1)'), '/'],
};

const versions = [
    ['v1', badV1, example],
    ['v2', badV2, exampleV2],
] as const;

for (const [label, cases, make] of versions) {
    for (const [name, [mutate, pointer]] of Object.entries(cases)) {
        test(`${label}: rejects ${name}`, () => {
            const config = make();
            mutate(config);
            const result = validateConfig(config);
            assert.equal(result.success, false);
            assert.ok(
                !result.success && Object.keys(result.errors).some((k) => k === pointer || k.startsWith(pointer === '/' ? '/' : `${pointer}/`)),
                `expected an error at ${pointer}, got ${!result.success && Object.keys(result.errors)}`,
            );
        });
    }
}

test('the occasion, kind and section lists match the JSON Schemas', () => {
    const v1 = read('app/Services/InvitationConfig/schemas/v1.json');
    const v2 = read('app/Services/InvitationConfig/schemas/v2.json');
    assert.deepEqual([...OCCASIONS_V1], v1.properties.occasion.enum);
    assert.deepEqual([...OCCASIONS], v2.properties.occasion.enum);
    assert.deepEqual([...KINDS], v2.properties.kind.enum);
    assert.deepEqual([...SECTION_TYPES], v2.properties.sections.items.properties.type.enum);
    assert.equal(v2.properties.version.const, CONFIG_VERSION);
});
