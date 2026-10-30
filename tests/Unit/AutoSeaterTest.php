<?php

use App\Services\HallGenerator;
use App\Services\Seating\AutoSeater;
use App\Services\Seating\SeatingWarnings;

function p7aHall(int $tables, int $chairs, string $layout = 'mixed'): array
{
    return (new HallGenerator)->generate($tables, $chairs, $layout);
}

function p7aFamily(string $id, int $people, int $kids = 0, ?string $section = null): array
{
    return ['id' => $id, 'name' => "Family {$id}", 'section' => $section, 'people' => $people, 'kids' => $kids];
}

/** @return array<string, array<string, int>> household => table => people */
function p7aSeating(array $assignments): array
{
    $out = [];
    foreach ($assignments as $a) {
        $out[$a['household_id']][$a['table_id']] = ($out[$a['household_id']][$a['table_id']] ?? 0) + 1;
    }

    return $out;
}

function p7aLoads(array $assignments): array
{
    $out = [];
    foreach ($assignments as $a) {
        $out[$a['table_id']] = ($out[$a['table_id']] ?? 0) + 1;
    }
    ksort($out);

    return $out;
}

it('keeps every family together at one table when they fit', function () {
    $families = [p7aFamily('a', 4), p7aFamily('b', 3), p7aFamily('c', 2), p7aFamily('d', 5), p7aFamily('e', 1)];
    $plan = (new AutoSeater)->plan(p7aHall(3, 8), $families);

    foreach (p7aSeating($plan['assignments']) as $byTable) {
        expect($byTable)->toHaveCount(1);
    }
    expect($plan['unplaced'])->toBe([])
        ->and($plan['placed'])->toBe(5)
        ->and(count($plan['assignments']))->toBe(15);
});

it('fills tables evenly instead of packing the first ones', function () {
    $families = array_map(fn ($i) => p7aFamily("f{$i}", 2), range(1, 6));
    $plan = (new AutoSeater)->plan(p7aHall(3, 8), $families);

    expect(array_values(p7aLoads($plan['assignments'])))->toBe([4, 4, 4]);
});

it('respects the men and women sections and puts unsectioned families where there is room', function () {
    $families = [
        p7aFamily('m1', 3, section: 'men'), p7aFamily('m2', 3, section: 'men'),
        p7aFamily('w1', 4, section: 'women'), p7aFamily('w2', 2, section: 'women'),
        p7aFamily('x', 2),
    ];
    $layout = p7aHall(4, 6, 'split'); // T1, T2 men; T3, T4 women
    $plan = (new AutoSeater)->plan($layout, $families);

    $sectionOf = collect($layout['tables'])->pluck('section', 'id');
    foreach ($plan['assignments'] as $a) {
        $family = collect($families)->firstWhere('id', $a['household_id']);
        if ($family['section']) {
            expect($sectionOf[$a['table_id']])->toBe($family['section']);
        }
    }
    expect(SeatingWarnings::compute($layout, $families, $plan['assignments']))->toBe([]);
});

it('puts families with children at the same table where possible', function () {
    $families = [p7aFamily('k1', 2, 1), p7aFamily('a1', 2), p7aFamily('k2', 2, 1), p7aFamily('a2', 2), p7aFamily('k3', 2, 2), p7aFamily('a3', 2)];
    $plan = (new AutoSeater)->plan(p7aHall(2, 6), $families);

    $tableOf = collect(p7aSeating($plan['assignments']))->map(fn ($t) => array_key_first($t));
    expect($tableOf['k1'])->toBe($tableOf['k2'])->toBe($tableOf['k3'])
        ->and($tableOf['a1'])->not->toBe($tableOf['k1']);
});

it('is deterministic: the same input always gives the same plan, whatever the input order', function () {
    $families = [p7aFamily('a', 3, 1), p7aFamily('b', 2), p7aFamily('c', 4, 2, 'men'), p7aFamily('d', 1, section: 'women'), p7aFamily('e', 5)];
    $seater = new AutoSeater;
    $layout = p7aHall(4, 6, 'split');

    $first = $seater->plan($layout, $families);
    expect($seater->plan($layout, $families))->toBe($first)
        ->and($seater->plan($layout, array_reverse($families)))->toBe($first);
});

it('splits a family only when no table can hold it, and reports who did not fit', function () {
    $plan = (new AutoSeater)->plan(p7aHall(2, 4), [p7aFamily('big', 6), p7aFamily('small', 3)]);

    expect(p7aSeating($plan['assignments'])['big'])->toHaveCount(2)
        ->and($plan['unplaced'])->toBe([['id' => 'small', 'name' => 'Family small', 'missing' => 1]]);
});

it('keeps current seats in fill mode and seats only families without one', function () {
    $layout = p7aHall(2, 4);
    $existing = [
        ['household_id' => 'a', 'table_id' => 'T2', 'seat_index' => 0],
        ['household_id' => 'a', 'table_id' => 'T2', 'seat_index' => 1],
    ];
    $plan = (new AutoSeater)->plan($layout, [p7aFamily('a', 2), p7aFamily('b', 2)], $existing, AutoSeater::MODE_FILL);

    $seats = p7aSeating($plan['assignments']);
    expect($seats['a'])->toBe(['T2' => 2])
        ->and($seats['b'])->toBe(['T1' => 2]);
});

it('fills chairs in order from the first free seat', function () {
    $plan = (new AutoSeater)->plan(p7aHall(1, 6), [p7aFamily('a', 3)]);

    expect(array_column($plan['assignments'], 'seat_index'))->toBe([0, 1, 2]);
});

it('computes warnings: over capacity, split, sections, unseated and stale seats', function () {
    $layout = p7aHall(2, 2, 'split'); // T1 men, T2 women, 2 chairs each
    $families = [p7aFamily('w', 3, section: 'women'), p7aFamily('m', 1, section: 'men'), p7aFamily('n', 2)];
    $assignments = [
        ['household_id' => 'w', 'table_id' => 'T1', 'seat_index' => 0],
        ['household_id' => 'w', 'table_id' => 'T2', 'seat_index' => 0],
        ['household_id' => 'm', 'table_id' => 'T1', 'seat_index' => 1],
        ['household_id' => 'm', 'table_id' => 'T1', 'seat_index' => 5],
        ['household_id' => 'gone', 'table_id' => 'T2', 'seat_index' => 1],
    ];

    $types = collect(SeatingWarnings::compute($layout, $families, $assignments))->groupBy('type');

    expect($types['over_capacity']->pluck('table')->all())->toBe(['T1'])
        ->and($types['section_mismatch']->first())->toMatchArray(['table' => 'T1', 'household' => 'w', 'section' => 'men'])
        ->and($types['split']->first())->toMatchArray(['household' => 'w', 'tables' => ['T1', 'T2']])
        ->and($types['stale']->pluck('household')->sort()->values()->all())->toBe(['gone', 'm'])
        ->and($types['unseated']->pluck('count', 'household')->all())->toBe(['w' => 1, 'n' => 2]);
});
