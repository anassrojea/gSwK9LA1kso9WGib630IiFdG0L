<?php

use App\Services\HallGenerator;
use App\Services\Seating\HallLayoutData;
use Tests\Support\HallGeometryCases;

/*
| PHP ↔ TypeScript parity: chairs of every shape and generated halls are
| checked against tests/Fixtures/hall-geometry.json, which
| tests/js/hall-layout.test.ts checks resources/js/lib/hall-layout.ts against.
| After an intended geometry change: HALL_FIXTURE_WRITE=1 php artisan test --filter=HallGeometry
*/

function hallGeometryActual(): array
{
    $tables = [];
    foreach (HallGeometryCases::tables() as $name => $table) {
        $tables[$name] = ['input' => $table, 'chairs' => HallLayoutData::chairPositions($table), 'extent' => HallLayoutData::extent($table)];
    }

    $generator = [];
    foreach (HallGeometryCases::generator() as $name => $case) {
        $generator[$name] = ['input' => $case, 'output' => (new HallGenerator)->generate($case['tables'], $case['chairs'], $case['layout'], $case['shape'], id: 'hall_fixture')];
    }

    return ['tables' => $tables, 'generator' => $generator];
}

it('matches the shared geometry fixture', function () {
    $actual = json_decode(json_encode(hallGeometryActual()), true);

    if (getenv('HALL_FIXTURE_WRITE')) {
        file_put_contents(HallGeometryCases::fixturePath(), json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
    }

    $expected = json_decode((string) file_get_contents(HallGeometryCases::fixturePath()), true);
    expect($actual)->toEqual($expected);
});

it('places one chair per seat for every shape and rotation', function (array $table) {
    $chairs = HallLayoutData::chairPositions($table);
    [$hw, $hh] = HallLayoutData::extent($table);

    expect($chairs)->toHaveCount($table['seats']);
    foreach ($chairs as $c) {
        // every chair stands inside the table's extent
        expect(abs($c['x'] - $table['x']))->toBeLessThanOrEqual($hw + 0.1)
            ->and(abs($c['y'] - $table['y']))->toBeLessThanOrEqual($hh + 0.1);
    }
    // no two chairs on the same spot
    expect(collect($chairs)->map(fn ($c) => $c['x'].','.$c['y'])->unique())->toHaveCount($table['seats']);
})->with(fn () => array_map(fn ($t) => [$t], HallGeometryCases::tables()));

it('turns a table by swapping its axes', function () {
    $flat = ['id' => 'T1', 'section' => 'mixed', 'shape' => 'rect', 'x' => 100, 'y' => 100, 'seats' => 4, 'w' => 80, 'h' => 20, 'rotation' => 0, 'sides' => 'both', 'heads' => false, 'chairR' => 6];
    $turned = ['rotation' => 90] + $flat;

    expect(HallLayoutData::extent($flat))->toBe(array_reverse(HallLayoutData::extent($turned)))
        ->and(HallLayoutData::bodySize($turned))->toBe([20.0, 80.0]);

    // the back side (seats 0, 1) is above a flat table and to the right of a turned one
    $a = HallLayoutData::chairPositions($flat);
    $b = HallLayoutData::chairPositions($turned);
    expect($a[0]['y'])->toBeLessThan(100.0)->and($a[0]['x'])->toBeLessThan($a[1]['x'])
        ->and($b[0]['x'])->toBeGreaterThan(100.0)->and($b[0]['y'])->toBeLessThan($b[1]['y']);
});

it('puts the heads of a banquet table at its ends', function () {
    $t = ['id' => 'T1', 'section' => 'mixed', 'shape' => 'rect', 'x' => 100, 'y' => 100, 'seats' => 6, 'w' => 80, 'h' => 20, 'rotation' => 0, 'sides' => 'both', 'heads' => true, 'chairR' => 6];
    $chairs = HallLayoutData::chairPositions($t);

    // 2 back, right head, 2 front, left head
    expect($chairs[2])->toBe(['x' => 148.0, 'y' => 100.0])
        ->and($chairs[5])->toBe(['x' => 52.0, 'y' => 100.0]);
});
