<?php

namespace App\Services\Seating;

/**
 * Auto-seat (plan sections 7.3 and 14.3): a first proposal the host then
 * adjusts. Pure and deterministic: the same hall, families and seats always
 * give the same plan, so a preview is exactly what "Apply" saves.
 *
 *  0. Kids tables (phase 8B, a table marked `kids`): the children of each
 *     family (biggest family first) go to the kids tables while there is room,
 *     a family's children together when they fit at one. Kids tables take no
 *     adults; children who do not fit stay with their family.
 *  1. Pools: VIP tables (the "vip" section, phase 8B) are kept for families
 *     tagged VIP, who go there first while they fit. On a split hall, men's
 *     families go to men's tables and women's families to women's tables;
 *     families without a section join the pool with the most room left. A
 *     mixed hall is one pool.
 *  2. Each pool gets an even target per table: ceil(people / tables).
 *  3. Families are placed biggest first (then most kids, then name). Each one
 *     goes to a table it fits at whole, preferring, in order: staying under
 *     the target (even fill), a table that already has children when the
 *     family brings children (and one without when it does not), the emptiest
 *     table, then hall order.
 *  4. A family bigger than any free table is split over the tables with the
 *     most free chairs; whoever still has no chair is reported as unplaced.
 *
 * A hall without kids tables or VIP tables is seated exactly as in phase 7A.
 * Seats fill in chair order (lowest free seat index first).
 */
class AutoSeater
{
    public const MODE_ALL = 'all';

    public const MODE_FILL = 'fill';

    /** The household tag that marks a VIP family (any case). */
    public const VIP_TAG = 'vip';

    /**
     * @param  array  $layout  hall layout (plan section 7.1)
     * @param  list<array{id: string, name: string, section: ?string, people: int, kids: int, tags?: list<string>}>  $families
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
                'forKids' => HallLayoutData::isKidsTable($t),
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

        $new = [];
        $unplaced = [];
        $placedIds = [];

        // 0. children to the kids tables
        $kidsTables = array_keys(array_filter($tables, fn ($t) => $t['forKids']));
        if ($kidsTables !== []) {
            foreach ($toPlace as &$family) {
                $kids = min($family['kids'], $family['people']);
                if ($kids <= 0) {
                    continue;
                }
                $seatedKids = $this->placeKids($tables, $kidsTables, $family, $kids, $new);
                if ($seatedKids > 0) {
                    $placedIds[$family['id']] = true;
                    $family['people'] -= $seatedKids;
                    $family['kids'] -= $seatedKids;
                }
            }
            unset($family);
            $toPlace = array_values(array_filter($toPlace, fn ($f) => $f['people'] > 0));
        }

        $adultTables = array_filter($tables, fn ($t) => ! $t['forKids']);
        foreach ($this->pools($adultTables, $toPlace) as $pool) {
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
                    $placedIds[$family['id']] = true;
                }
            }
        }

        $assignments = array_merge($kept, $new);
        $order = array_flip(array_keys($tables));
        usort($assignments, fn ($a, $b) => [$order[$a['table_id']], $a['seat_index']] <=> [$order[$b['table_id']], $b['seat_index']]);

        return ['assignments' => $assignments, 'placed' => count($placedIds), 'unplaced' => $unplaced];
    }

    /**
     * Biggest family first, then most children, then name and id (stable for equal names).
     */
    public static function compareFamilies(array $a, array $b): int
    {
        return [$b['people'], $b['kids'], $a['name'], $a['id']] <=> [$a['people'], $a['kids'], $b['name'], $b['id']];
    }

    public static function isVip(array $family): bool
    {
        foreach ($family['tags'] ?? [] as $tag) {
            if (is_string($tag) && mb_strtolower(trim($tag)) === self::VIP_TAG) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{tables: list<string>, families: list<array>}>
     */
    private function pools(array $tables, array $families): array
    {
        $vipPool = null;
        $vipIds = array_keys(array_filter($tables, fn ($t) => $t['section'] === 'vip'));
        if ($vipIds !== []) {
            $room = array_sum(array_map(fn ($id) => $tables[$id]['seats'] - count($tables[$id]['taken']), $vipIds));
            $vipFamilies = [];
            $rest = [];
            foreach ($families as $family) {
                if (static::isVip($family) && $family['people'] <= $room) {
                    $vipFamilies[] = $family;
                    $room -= $family['people'];
                } else {
                    $rest[] = $family;
                }
            }
            $vipPool = ['tables' => $vipIds, 'families' => $vipFamilies];
            $families = $rest;
            $tables = array_diff_key($tables, array_flip($vipIds));
        }

        $pools = $this->sectionPools($tables, $families);

        return $vipPool ? [$vipPool, ...$pools] : $pools;
    }

    /**
     * @return list<array{tables: list<string>, families: list<array>}>
     */
    private function sectionPools(array $tables, array $families): array
    {
        if ($tables === []) {
            // only VIP (or kids) tables: nobody else has a chair
            return $families === [] ? [] : [['tables' => [], 'families' => $families]];
        }

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
     * A family's children at the kids tables: together at the emptiest table
     * that has room for all of them, else spread over the tables with most room.
     *
     * @param  list<string>  $ids
     * @return int children seated
     */
    private function placeKids(array &$tables, array $ids, array $family, int $kids, array &$out): int
    {
        $best = null;
        foreach ($ids as $id) {
            $free = $tables[$id]['seats'] - count($tables[$id]['taken']);
            if ($free >= $kids && ($best === null || [count($tables[$id]['taken']), $tables[$id]['index']] < [count($tables[$best]['taken']), $tables[$best]['index']])) {
                $best = $id;
            }
        }
        if ($best !== null) {
            $this->take($tables[$best], $family, $kids, $out, $kids);

            return $kids;
        }

        $order = $ids;
        usort($order, fn ($a, $b) => [count($tables[$a]['taken']) - $tables[$a]['seats'], $tables[$a]['index']] <=> [count($tables[$b]['taken']) - $tables[$b]['seats'], $tables[$b]['index']]);
        $seated = 0;
        foreach ($order as $id) {
            $free = $tables[$id]['seats'] - count($tables[$id]['taken']);
            if ($free > 0 && $seated < $kids) {
                $n = min($free, $kids - $seated);
                $this->take($tables[$id], $family, $n, $out, $n);
                $seated += $n;
            }
        }

        return $seated;
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

    private function take(array &$table, array $family, int $count, array &$out, ?int $kids = null): void
    {
        for ($seat = 0; $seat < $table['seats'] && $count > 0; $seat++) {
            if (! isset($table['taken'][$seat])) {
                $table['taken'][$seat] = true;
                $out[] = ['household_id' => $family['id'], 'table_id' => $table['id'], 'seat_index' => $seat];
                $count--;
            }
        }
        $table['kids'] += $kids ?? $family['kids'];
    }
}
