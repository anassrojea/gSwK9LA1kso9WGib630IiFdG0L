/**
 * Hall geometry parity with PHP: chairs of every shape and generated halls
 * must match tests/Fixtures/hall-geometry.json, which tests/Unit/HallGeometryTest.php
 * checks app/Services/Seating/HallLayoutData.php and HallGenerator.php against.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

import { generateHall, type GenerateOptions } from '../../resources/js/lib/hall-generator.ts';
import { chairPositions, extent, tableBox, withChairs, type HallTable, type TableInput } from '../../resources/js/lib/hall-layout.ts';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/hall-geometry.json', import.meta.url), 'utf8')) as {
    tables: Record<string, { input: TableInput; chairs: { x: number; y: number }[]; extent: [number, number] }>;
    generator: Record<string, { input: Required<Pick<GenerateOptions, 'tables' | 'chairs' | 'layout' | 'shape'>>; output: unknown }>;
};

test('chair positions match PHP for every shape and rotation', () => {
    for (const [name, c] of Object.entries(fixture.tables)) {
        assert.deepEqual(chairPositions(c.input), c.chairs, name);
        assert.deepEqual(extent({ ...c.input, chairs: [] } as HallTable), c.extent, name);
    }
});

test('the generator matches PHP, round and banquet', () => {
    assert.ok(Object.keys(fixture.generator).length >= 5);
    for (const [name, c] of Object.entries(fixture.generator)) {
        assert.deepEqual(generateHall({ ...c.input, id: 'hall_fixture' }), c.output, name);
    }
});

test('banquet tables from the generator stay inside their section and apart', () => {
    for (const layout of ['mixed', 'split'] as const) {
        const hall = generateHall({ tables: 24, chairs: 10, layout, shape: 'rect' });
        const areas = new Map(hall.sections.map((s) => [s.id, s.area]));
        const boxes = hall.tables.map(tableBox);
        hall.tables.forEach((t, i) => {
            const a = areas.get(t.section)!;
            const b = boxes[i];
            assert.ok(b.x >= a.x - 0.5 && b.x + b.w <= a.x + a.w + 0.5, `${t.id} x`);
            assert.ok(b.y >= a.y - 0.5 && b.y + b.h <= a.y + a.h + 0.5, `${t.id} y`);
            assert.equal(t.shape === 'rect' && t.chairs.length, 10);
        });
        for (let i = 0; i < boxes.length; i++)
            for (let j = i + 1; j < boxes.length; j++) {
                const [p, q] = [boxes[i], boxes[j]];
                assert.ok(p.x + p.w <= q.x + 0.5 || q.x + q.w <= p.x + 0.5 || p.y + p.h <= q.y + 0.5 || q.y + q.h <= p.y + 0.5, `${i} overlaps ${j}`);
            }
    }
});

test('withChairs recomputes a turned table', () => {
    const t = generateHall({ tables: 1, chairs: 6, shape: 'rect' }).tables[0];
    assert.equal(t.shape, 'rect');
    if (t.shape !== 'rect') return;
    const turned = withChairs({ ...t, rotation: 90 });
    assert.notDeepEqual(turned.chairs, t.chairs);
    // turning swaps the axes around the centre
    assert.deepEqual(
        turned.chairs.map((c) => [Math.round(c.x - t.x), Math.round(c.y - t.y)]),
        t.chairs.map((c) => [Math.round(-(c.y - t.y)), Math.round(c.x - t.x)]),
    );
});
