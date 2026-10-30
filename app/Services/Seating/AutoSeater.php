<?php

namespace App\Services\Seating;

/**
 * Auto-seat (plan sections 7.3 and 14.3): a first proposal the host then
 * adjusts. Pure and deterministic: the same hall, families and seats always
 * give the same plan, so a preview is exactly what "Apply" saves.
 *
 *  1. Pools: on a split hall, men's families go to men's tables and women's
 *     families to women's tables; families without a section join the pool
 *     with the most room left. A mixed hall is one pool.
 *  2. Each pool gets an even target per table: ceil(people / tables).
 *  3. Families are placed biggest first (then most kids, then name). Each one
 *     goes to a table it fits at whole, preferring, in order: staying under
 *     the target (even fill), a table that already has children when the
 *     family brings children (and one without when it does not), the emptiest
 *     table, then hall order.
 *  4. A family bigger than any free table is split over the tables with the
 *     most free chairs; whoever still has no chair is reported as unplaced.
 *
 * Seats fill in chair order (lowest free seat index first).
 */
class AutoSeater
{
    public const MODE_ALL = 'all';

    public const MODE_FILL = 'fill';

    /**
     * @param  array  $layout  hall layout (plan section 7.1)
     * @param  list<array{id: string, name: string, section: ?string, people: int, kids: int}>  $families
     * @param  list<array{household_id: string, table_id: string, seat_index: int}>  $existing
     * @param  string  $mode  "all": start over; "fill": keep current seats, place families with none yet
     * @return array{assignments: list<array{household_id: string, table_id: string, seat_index: int}>, placed: int, unplaced: list<array{id: string, name: string, missing: int}>}
     */
    public function plan(array $layout, array $families, array $existing = [], string $mode = self::MODE_ALL): array
    {
        $tables = [];
        foreach (array_values($layout['tables'] ?? []) as $index => $t) {
            $tables[$t['id']] = [
                'id' => $t['id'],
                'section' => $t['section'] ?? 'mixed',
                'seats' => (int) $t['seats'],
                'index' => $index,
                'taken' => [],
                'kids' => 0,
            ];
        }

        $byId = [];
        foreach ($families as $family) {
            $byId[$family['id']] = $family;
        }

        $kept = [];
        $seated = [];
        if ($mode === self::MODE_FILL) {
            foreach ($existing as $a) {
                $table = $a['table_id'];
                if (! isset($tables[$table], $byId[$a['household_id']]) || $a['seat_index'] >= $tables[$table]['seats'] || isset($tables[$table]['taken'][$a['seat_index']])) {
                    continue;
                }
                $tables[$table]['taken'][$a['seat_index']] = true;
                if (! isset($seated[$a['household_id']]) && $byId[$a['household_id']]['kids'] > 0) {
                    $tables[$table]['kids'] += $byId[$a['household_id']]['kids'];
                }
                $seated[$a['household_id']] = true;
                $kept[] = ['household_id' => $a['household_id'], 'table_id' => $table, 'seat_index' => (int) $a['seat_index']];
            }
        }

        $toPlace = array_values(array_filter($families, fn ($f) => $f['people'] > 0 && ! isset($seated[$f['id']])));
        usort($toPlace, [static::class, 'compareFamilies']);

        $pools = $this->pools($tables, $toPlace);

        $new = [];
        $unplaced = [];
        $placed = 0;
        foreach ($pools as $pool) {
            $ids = $pool['tables'];
            $occupied = array_sum(array_map(fn ($id) => count($tables[$id]['taken']), $ids));
            $demand = array_sum(array_map(fn ($f) => $f['people'], $pool['families']));
            $target = $ids === [] ? 0 : (int) ceil(($occupied + $demand) / count($ids));

            foreach ($pool['families'] as $family) {
                $left = $this->placeFamily($tables, $ids, $family, $target, $new);
                if ($left > 0) {
                    $unplaced[] = ['id' => $family['id'], 'name' => $family['name'], 'missing' => $left];
                }
                if ($left < $family['people']) {
                    $placed++;
                }
            }
        }

        $assignments = array_merge($kept, $new);
        $order = array_flip(array_keys($tables));
        usort($assignments, fn ($a, $b) => [$order[$a['table_id']], $a['seat_index']] <=> [$order[$b['table_id']], $b['seat_index']]);

        return ['assignments' => $assignments, 'placed' => $placed, 'unplaced' => $unplaced];
    }

    /**
     * Biggest family first, then most children, then name and id (stable for equal names).
     */
    public static function compareFamilies(array $a, array $b): int
    {
        return [$b['people'], $b['kids'], $a['name'], $a['id']] <=> [$a['people'], $a['kids'], $b['name'], $b['id']];
    }

    /**
     * @return list<array{tables: list<string>, families: list<array>}>
     */
    private function pools(array $tables, array $families): array
    {
        $sections = [];
        foreach ($tables as $t) {
            $key = in_array($t['section'], ['men', 'women'], true) ? $t['section'] : 'other';
            $sections[$key][] = $t['id'];
        }

        $split = isset($sections['men']) || isset($sections['women']);
        if (! $split) {
            return [['tables' => array_keys($tables), 'families' => $families]];
        }

        $pools = [];
        foreach (['men', 'women', 'other'] as $key) {
            if (isset($sections[$key])) {
                $free = array_sum(array_map(fn ($id) => $tables[$id]['seats'] - count($tables[$id]['taken']), $sections[$key]));
                $pools[$key] = ['tables' => $sections[$key], 'families' => [], 'room' => $free];
            }
        }

        $floating = [];
        foreach ($families as $family) {
            $section = $family['section'] ?? null;
            if ($section !== null && isset($pools[$section])) {
                $pools[$section]['families'][] = $family;
                $pools[$section]['room'] -= $family['people'];
            } else {
                $floating[] = $family;
            }
        }

        // families without a section (or whose section has no tables) go where there is most room
        foreach ($floating as $family) {
            $best = null;
            foreach ($pools as $key => $pool) {
                if ($best === null || $pool['room'] > $pools[$best]['room']) {
                    $best = $key;
                }
            }
            $pools[$best]['families'][] = $family;
            $pools[$best]['room'] -= $family['people'];
        }

        foreach ($pools as &$pool) {
            usort($pool['families'], [static::class, 'compareFamilies']);
        }

        return array_values(array_map(fn ($p) => ['tables' => $p['tables'], 'families' => $p['families']], $pools));
    }

    /**
     * @param  list<string>  $ids
     * @return int people still without a chair
     */
    private function placeFamily(array &$tables, array $ids, array $family, int $target, array &$out): int
    {
        $people = $family['people'];
        $best = null;
        $bestKey = null;

        foreach ($ids as $id) {
            $t = $tables[$id];
            $load = count($t['taken']);
            if ($people > $t['seats'] - $load) {
                continue;
            }
            $kidsKey = $family['kids'] > 0 ? ($t['kids'] > 0 ? 0 : 1) : ($t['kids'] > 0 ? 1 : 0);
            $key = [$load + $people <= max($target, $people) ? 0 : 1, $kidsKey, $load, $t['index']];
            if ($bestKey === null || $key < $bestKey) {
                $best = $id;
                $bestKey = $key;
            }
        }

        if ($best !== null) {
            $this->take($tables[$best], $family, $people, $out);

            return 0;
        }

        // too big for any one table: split over the tables with the most free chairs
        $order = $ids;
        usort($order, fn ($a, $b) => [count($tables[$a]['taken']) - $tables[$a]['seats'], $tables[$a]['index']] <=> [count($tables[$b]['taken']) - $tables[$b]['seats'], $tables[$b]['index']]);
        $left = $people;
        foreach ($order as $id) {
            if ($left === 0) {
                break;
            }
            $free = $tables[$id]['seats'] - count($tables[$id]['taken']);
            if ($free > 0) {
                $n = min($free, $left);
                $this->take($tables[$id], $family, $n, $out);
                $left -= $n;
            }
        }

        return $left;
    }

    private function take(array &$table, array $family, int $count, array &$out): void
    {
        for ($seat = 0; $seat < $table['seats'] && $count > 0; $seat++) {
            if (! isset($table['taken'][$seat])) {
                $table['taken'][$seat] = true;
                $out[] = ['household_id' => $family['id'], 'table_id' => $table['id'], 'seat_index' => $seat];
                $count--;
            }
        }
        $table['kids'] += $family['kids'];
    }
}
