<?php

use App\Services\HallGenerator;
use App\Services\Seating\AutoSeater;
use App\Services\Seating\SeatingWarnings;

/*
| Auto-seat with kids tables and VIP sections (phase 8B).
*/

function p8bFamily(string $id, int $people, int $kids = 0, ?string $section = null, array $tags = []): array
{
    return ['id' => $id, 'name' => "Family {$id}", 'section' => $section, 'people' => $people, 'kids' => $kids, 'tags' => $tags];
}

/** household => table => people */
function p8bSeating(array $assignments): array
{
    $out = [];
    foreach ($assignments as $a) {
        $out[$a['household_id']][$a['table_id']] = ($out[$a['household_id']][$a['table_id']] ?? 0) + 1;
    }

    return $out;
}

/** A mixed hall of 4 round tables of 8, T4 a kids table, plus a VIP row V1 of 6. */
function p8bHall(bool $kids = true, bool $vip = true): array
{
    $hall = (new HallGenerator)->generate(4, 8, 'mixed');
    if ($kids) {
        $hall['tables'][3]['kids'] = true;
    }
    if ($vip) {
        $hall['sections'][] = ['id' => 'vip', 'label' => 'VIP', 'area' => ['x' => 200, 'y' => 90, 'w' => 400, 'h' => 50]];
        $hall['tables'][] = ['id' => 'V1', 'section' => 'vip', 'shape' => 'row', 'x' => 400, 'y' => 115, 'seats' => 6, 'w' => 108, 'h' => 18, 'rotation' => 0, 'chairR' => 7];
    }

    return $hall;
}

it('seats the children of each family at the kids table and the adults together', function () {
    $families = [p8bFamily('a', 5, 2), p8bFamily('b', 4, 1), p8bFamily('c', 3)];
    $plan = (new AutoSeater)->plan(p8bHall(vip: false), $families);
    $seating = p8bSeating($plan['assignments']);

    expect($seating['a']['T4'])->toBe(2)
        ->and(array_sum($seating['a']))->toBe(5)
        ->and($seating['b']['T4'])->toBe(1)
        // the adults of a family stay at one table
        ->and(count(array_diff_key($seating['a'], ['T4' => 1])))->toBe(1)
        ->and(count(array_diff_key($seating['b'], ['T4' => 1])))->toBe(1)
        // a family without children never sits at the kids table, and no adult does
        ->and($seating['c'])->not->toHaveKey('T4')
        ->and($plan['unplaced'])->toBe([])
        ->and($plan['placed'])->toBe(3);

    // children at a kids table do not count as a split family
    $warnings = SeatingWarnings::compute(p8bHall(vip: false), $families, $plan['assignments']);
    expect(collect($warnings)->where('type', 'split'))->toBeEmpty();
});

it('keeps children with their family when the kids table is full', function () {
    $families = [p8bFamily('a', 9, 6), p8bFamily('b', 4, 3)];
    $plan = (new AutoSeater)->plan(p8bHall(vip: false), $families);
    $seating = p8bSeating($plan['assignments']);

    // the kids table has 8 chairs: 6 + 2 of b's 3 children; the third stays with b
    expect($seating['a']['T4'])->toBe(6)
        ->and($seating['b']['T4'])->toBe(2)
        ->and(array_sum($seating['b']))->toBe(4)
        ->and($plan['unplaced'])->toBe([]);
});

it('puts families tagged VIP in the VIP section and nobody else', function () {
    $families = [p8bFamily('vip1', 3, tags: ['VIP']), p8bFamily('vip2', 2, tags: ['vip', 'Family']), p8bFamily('x', 6), p8bFamily('y', 5)];
    $plan = (new AutoSeater)->plan(p8bHall(kids: false), $families);
    $seating = p8bSeating($plan['assignments']);

    expect($seating['vip1'])->toBe(['V1' => 3])
        ->and($seating['vip2'])->toBe(['V1' => 2])
        ->and($seating['x'])->not->toHaveKey('V1')
        ->and($seating['y'])->not->toHaveKey('V1');
});

it('seats VIP families elsewhere when the VIP section is full', function () {
    $families = [p8bFamily('big', 5, tags: ['VIP']), p8bFamily('more', 4, tags: ['VIP'])];
    $plan = (new AutoSeater)->plan(p8bHall(kids: false), $families);
    $seating = p8bSeating($plan['assignments']);

    expect($seating['big'])->toBe(['V1' => 5])
        ->and($seating['more'])->not->toHaveKey('V1')
        ->and(array_sum($seating['more']))->toBe(4)
        ->and($plan['unplaced'])->toBe([]);
});

it('seats a hall without kids or VIP tables exactly as before', function () {
    $families = [p8bFamily('a', 5, 2), p8bFamily('b', 4, 1, tags: ['VIP']), p8bFamily('c', 3)];
    $hall = p8bHall(kids: false, vip: false);

    $tagged = (new AutoSeater)->plan($hall, $families);
    $plain = (new AutoSeater)->plan($hall, array_map(fn ($f) => array_diff_key($f, ['tags' => 1]), $families));

    expect($tagged)->toBe($plain);
});

it('fills banquet tables, sofas and rows by their seat count', function () {
    $hall = (new HallGenerator)->generate(2, 12, 'mixed', 'rect');
    $hall['tables'][] = ['id' => 'S1', 'section' => 'mixed', 'shape' => 'sofa', 'x' => 700, 'y' => 500, 'seats' => 3, 'w' => 70, 'h' => 28, 'rotation' => 0, 'chairR' => 9];
    $plan = (new AutoSeater)->plan($hall, [p8bFamily('a', 12), p8bFamily('b', 10), p8bFamily('c', 3)]);
    $seating = p8bSeating($plan['assignments']);

    expect($seating['a'])->toHaveCount(1)->and(array_values($seating['a']))->toBe([12])
        ->and($plan['unplaced'])->toBe([])
        ->and(max(array_column($plan['assignments'], 'seat_index')))->toBe(11);
});
