<?php

use App\Services\HallGenerator;
use App\Services\Seating\HallLayoutData;
use App\Services\Seating\HallTemplates;
use Illuminate\Validation\ValidationException;

/*
| The hall layout JSON (phase 8B): new shapes, obstacles, labels and the VIP
| section, normalized and checked against schemas/hall-layout.json.
*/

function p8bMixedLayout(): array
{
    $layout = (new HallGenerator)->generate(4, 8, 'mixed', name: 'Mixed');
    $layout['sections'][] = ['id' => 'vip', 'label' => 'VIP', 'area' => ['x' => 200, 'y' => 90, 'w' => 400, 'h' => 60]];
    $layout['tables'][] = ['id' => 'T5', 'section' => 'mixed', 'shape' => 'rect', 'x' => 400, 'y' => 520, 'seats' => 14, 'w' => 220, 'h' => 30, 'rotation' => 90, 'sides' => 'both', 'heads' => true, 'chairR' => 7, 'kids' => true, 'label' => ' Kids <b>table</b> '];
    $layout['tables'][] = ['id' => 'S1', 'section' => 'mixed', 'shape' => 'sofa', 'x' => 60, 'y' => 300, 'seats' => 3, 'w' => 80, 'h' => 30, 'rotation' => 0, 'chairs' => [['x' => 1, 'y' => 1]]];
    $layout['tables'][] = ['id' => 'V1', 'section' => 'vip', 'shape' => 'row', 'x' => 400, 'y' => 120, 'seats' => 10, 'rotation' => 45];
    $layout['obstacles'] = [
        ['kind' => 'column', 'x' => 10, 'y' => 10, 'w' => 20, 'h' => 20],
        ['id' => 'O1', 'kind' => 'buffet', 'x' => 600, 'y' => 20, 'w' => 150, 'h' => 40, 'label' => 'Buffet'],
        ['id' => 'O1', 'kind' => 'stairs', 'x' => 700, 'y' => 500, 'w' => 30, 'h' => 30],
    ];
    $layout['labels'] = [['text' => 'Bar', 'x' => 700, 'y' => 300], ['text' => '   ', 'x' => 1, 'y' => 1]];
    $layout['entrances'][] = ['x' => 790, 'y' => 300, 'w' => 60, 'rotation' => 90];

    return $layout;
}

it('normalizes every new shape and computes its chairs on the server', function () {
    $layout = HallLayoutData::normalize(p8bMixedLayout());
    $tables = HallLayoutData::tablesById($layout);

    expect($tables['T5'])->toMatchArray(['shape' => 'rect', 'rotation' => 90, 'heads' => true, 'kids' => true, 'label' => 'Kids table'])
        ->and($tables['T5']['chairs'])->toHaveCount(14)
        ->and($tables['T5']['chairs'])->toBe(HallLayoutData::chairPositions($tables['T5']))
        // chairs sent by the client are ignored
        ->and($tables['S1']['chairs'])->toHaveCount(3)->not->toContain(['x' => 1, 'y' => 1])
        // a rotation other than 0 / 90 is 0; a row gets a default length
        ->and($tables['V1']['rotation'])->toBe(0)->and($tables['V1']['section'])->toBe('vip')->and($tables['V1']['w'])->toBeGreaterThan(100.0)
        ->and(array_column($layout['sections'], 'id'))->toBe(['mixed', 'vip'])
        // obstacles get unique ids, unknown kinds become "obstacle"
        ->and(array_column($layout['obstacles'], 'id'))->toBe(['O1', 'O2', 'O3'])
        ->and(array_column($layout['obstacles'], 'kind'))->toBe(['column', 'buffet', 'obstacle'])
        // empty labels are dropped
        ->and($layout['labels'])->toBe([['id' => 'L1', 'text' => 'Bar', 'x' => 700.0, 'y' => 300.0, 'size' => 14.0]])
        ->and($layout['entrances'][1])->toBe(['x' => 790.0, 'y' => 300.0, 'w' => 60.0, 'rotation' => 90])
        ->and(HallLayoutData::schemaErrors($layout))->toBe([])
        ->and(HallLayoutData::totalSeats($layout))->toBe(4 * 8 + 14 + 3 + 10);
});

it('keeps round tables free of the new keys', function () {
    $layout = HallLayoutData::normalize((new HallGenerator)->generate(3, 6));

    foreach ($layout['tables'] as $t) {
        expect(array_keys($t))->toBe(['id', 'section', 'shape', 'x', 'y', 'seats', 'r', 'chairR', 'ring']);
    }
    expect($layout)->not->toHaveKeys(['obstacles', 'labels']);
});

it('allows up to 40 seats at a banquet table but keeps round tables to 24', function () {
    $layout = (new HallGenerator)->generate(1, 8);
    $layout['tables'][] = ['id' => 'T2', 'shape' => 'rect', 'x' => 400, 'y' => 400, 'seats' => 40, 'w' => 500, 'h' => 30];
    expect(HallLayoutData::normalize($layout)['tables'][1]['seats'])->toBe(40);

    $layout['tables'][1]['seats'] = 41;
    expect(fn () => HallLayoutData::normalize($layout))->toThrow(ValidationException::class);

    $layout['tables'][1] = ['id' => 'T2', 'shape' => 'round', 'x' => 400, 'y' => 400, 'seats' => 30];
    expect(fn () => HallLayoutData::normalize($layout))->toThrow(ValidationException::class);
});

it('rejects documents that do not follow the schema', function (array $layout, string $pointer) {
    expect(array_keys(HallLayoutData::schemaErrors($layout)))->toContain($pointer);
})->with([
    'unknown shape' => [['id' => 'h', 'name' => 'H', 'size' => ['w' => 800, 'h' => 600], 'sections' => [['id' => 'mixed', 'label' => 'G', 'area' => ['x' => 0, 'y' => 0, 'w' => 800, 'h' => 600]]], 'entrances' => [], 'tables' => [['id' => 'T1', 'section' => 'mixed', 'shape' => 'hexagon', 'x' => 1, 'y' => 1, 'seats' => 4, 'chairR' => 7]]], '/tables/0/shape'],
    'banquet without chairs' => [['id' => 'h', 'name' => 'H', 'size' => ['w' => 800, 'h' => 600], 'sections' => [['id' => 'mixed', 'label' => 'G', 'area' => ['x' => 0, 'y' => 0, 'w' => 800, 'h' => 600]]], 'entrances' => [], 'tables' => [['id' => 'T1', 'section' => 'mixed', 'shape' => 'rect', 'x' => 1, 'y' => 1, 'seats' => 4, 'chairR' => 7, 'w' => 60, 'h' => 20, 'rotation' => 0]]], '/tables/0'],
    'round table with banquet keys' => [['id' => 'h', 'name' => 'H', 'size' => ['w' => 800, 'h' => 600], 'sections' => [['id' => 'mixed', 'label' => 'G', 'area' => ['x' => 0, 'y' => 0, 'w' => 800, 'h' => 600]]], 'entrances' => [], 'tables' => [['id' => 'T1', 'section' => 'mixed', 'shape' => 'round', 'x' => 1, 'y' => 1, 'seats' => 4, 'chairR' => 7, 'r' => 10, 'ring' => 20, 'w' => 4]]], '/tables/0'],
    'unknown section' => [['id' => 'h', 'name' => 'H', 'size' => ['w' => 800, 'h' => 600], 'sections' => [['id' => 'kids', 'label' => 'G', 'area' => ['x' => 0, 'y' => 0, 'w' => 800, 'h' => 600]]], 'entrances' => [], 'tables' => [['id' => 'T1', 'section' => 'mixed', 'shape' => 'round', 'x' => 1, 'y' => 1, 'seats' => 4, 'chairR' => 7, 'r' => 10, 'ring' => 20]]], '/sections/0/id'],
]);

it('ships starter halls that follow the schema', function () {
    foreach (app(HallTemplates::class)->all() as $key => $template) {
        expect(HallLayoutData::schemaErrors($template['layout']))->toBe([], $key)
            ->and(HallLayoutData::normalize($template['layout']))->toEqual($template['layout']);
    }
    $garden = app(HallTemplates::class)->garden();
    expect(collect($garden['tables'])->pluck('shape')->unique()->sort()->values()->all())->toBe(['rect', 'round', 'row', 'sofa'])
        ->and(collect($garden['tables'])->where('kids', true))->toHaveCount(1);
});

it('says which seats a new version of the hall no longer has', function () {
    $old = (new HallGenerator)->generate(3, 8);
    $new = $old;
    unset($new['tables'][2]);
    $new['tables'][1]['seats'] = 6;

    expect(HallLayoutData::lostSeats($old, $new))->toBe(['T2' => 6, 'T3' => 0]);
});
