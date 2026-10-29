<?php

use App\Services\HallGenerator;

it('returns the requested table and chair counts', function (int $tables, int $chairs, string $layout) {
    $hall = (new HallGenerator)->generate($tables, $chairs, $layout);

    expect($hall['tables'])->toHaveCount($tables)
        ->and(array_sum(array_column($hall['tables'], 'seats')))->toBe($tables * $chairs)
        ->and(array_unique(array_column($hall['tables'], 'seats')))->toBe([$chairs])
        ->and(array_column($hall['tables'], 'id'))->toBe(array_map(fn ($i) => "T{$i}", range(1, $tables)));
})->with([
    'one table' => [1, 8, 'mixed'],
    'plan page default' => [20, 8, 'mixed'],
    'odd split' => [21, 10, 'split'],
    'large' => [120, 12, 'split'],
    'max chairs' => [5, HallGenerator::MAX_CHAIRS, 'mixed'],
]);

it('outputs the layout schema from plan section 7.1', function () {
    $hall = (new HallGenerator)->generate(20, 8, 'split', name: 'Al Noor Ballroom', id: 'hall_al_noor_main');

    expect($hall)->toHaveKeys(['id', 'name', 'size', 'stage', 'danceFloor', 'entrances', 'sections', 'tables'])
        ->and($hall['id'])->toBe('hall_al_noor_main')
        ->and($hall['size'])->toBe(['w' => 800, 'h' => 580])
        ->and(array_column($hall['sections'], 'id'))->toBe(['men', 'women'])
        ->and($hall['tables'][0])->toHaveKeys(['id', 'section', 'shape', 'x', 'y', 'seats']);
});

it('splits tables between men and women, extra table to the men', function () {
    $hall = (new HallGenerator)->generate(21, 8, 'split');
    $bySection = array_count_values(array_column($hall['tables'], 'section'));

    expect($bySection)->toBe(['men' => 11, 'women' => 10]);
});

it('keeps every table inside its section area', function (int $tables, string $layout) {
    $hall = (new HallGenerator)->generate($tables, 8, $layout);
    $areas = array_combine(array_column($hall['sections'], 'id'), array_column($hall['sections'], 'area'));

    foreach ($hall['tables'] as $table) {
        $a = $areas[$table['section']];
        expect($table['x'])->toBeGreaterThan($a['x'])->toBeLessThan($a['x'] + $a['w'])
            ->and($table['y'])->toBeGreaterThan($a['y'])->toBeLessThan($a['y'] + $a['h'])
            ->and($table['r'])->toBeLessThan($table['ring']);
    }
})->with([[1, 'mixed'], [20, 'mixed'], [33, 'split'], [200, 'mixed']]);

it('rejects impossible requests', function (int $tables, int $chairs, string $layout) {
    (new HallGenerator)->generate($tables, $chairs, $layout);
})->throws(InvalidArgumentException::class)->with([
    [0, 8, 'mixed'],
    [HallGenerator::MAX_TABLES + 1, 8, 'mixed'],
    [10, 0, 'mixed'],
    [10, 8, 'diagonal'],
]);
