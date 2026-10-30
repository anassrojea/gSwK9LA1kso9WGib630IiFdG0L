<?php

namespace App\Services\Seating;

/**
 * What the seating planner flags (plan section 7.3). Same rules as
 * resources/js/lib/seating/plan.ts (computeWarnings), which runs live in the
 * browser while the host drags; this one serves the first page load, the
 * PDFs and the tests.
 *
 *  over_capacity     a table holds more people than chairs (or a seat it no longer has)
 *  split             a family sits at more than one table
 *  section_mismatch  a men's table holds a women's family, or the other way round
 *  unseated          a confirmed family has people without a chair
 *  stale             seats for a family that no longer comes, or more seats than people
 */
class SeatingWarnings
{
    /**
     * @param  list<array{id: string, name: string, section: ?string, people: int}>  $families  confirmed families
     * @param  list<array{household_id: string, table_id: string, seat_index: int}>  $assignments
     * @return list<array{type: string, table?: string, household?: string, name?: string, count?: int, seats?: int, tables?: list<string>, section?: string}>
     */
    public static function compute(?array $layout, array $families, array $assignments): array
    {
        $tables = $layout ? HallLayoutData::tablesById($layout) : [];
        $byId = [];
        foreach ($families as $f) {
            $byId[$f['id']] = $f;
        }

        $perTable = [];
        $perFamily = [];
        $outOfRange = [];
        foreach ($assignments as $a) {
            $perTable[$a['table_id']][] = $a;
            $perFamily[$a['household_id']][$a['table_id']] = ($perFamily[$a['household_id']][$a['table_id']] ?? 0) + 1;
            $table = $tables[$a['table_id']] ?? null;
            if ($table === null || $a['seat_index'] >= $table['seats']) {
                $outOfRange[$a['table_id']] = true;
            }
        }

        $warnings = [];

        foreach ($perTable as $tableId => $rows) {
            $seats = $tables[$tableId]['seats'] ?? 0;
            if (count($rows) > $seats || isset($outOfRange[$tableId])) {
                $warnings[] = ['type' => 'over_capacity', 'table' => (string) $tableId, 'count' => count($rows), 'seats' => $seats];
            }
        }

        foreach ($perTable as $tableId => $rows) {
            $section = $tables[$tableId]['section'] ?? null;
            if (! in_array($section, ['men', 'women'], true)) {
                continue;
            }
            $seen = [];
            foreach ($rows as $a) {
                $family = $byId[$a['household_id']] ?? null;
                if ($family && $family['section'] && $family['section'] !== $section && ! isset($seen[$family['id']])) {
                    $seen[$family['id']] = true;
                    $warnings[] = ['type' => 'section_mismatch', 'table' => (string) $tableId, 'household' => $family['id'], 'name' => $family['name'], 'section' => $section];
                }
            }
        }

        foreach ($perFamily as $householdId => $byTable) {
            $family = $byId[$householdId] ?? null;
            $count = array_sum($byTable);
            if ($family === null || $count > $family['people']) {
                $warnings[] = ['type' => 'stale', 'household' => (string) $householdId, 'name' => $family['name'] ?? null, 'count' => $count];

                continue;
            }
            if (count($byTable) > 1) {
                $warnings[] = ['type' => 'split', 'household' => (string) $householdId, 'name' => $family['name'], 'tables' => array_map('strval', array_keys($byTable))];
            }
        }

        foreach ($families as $family) {
            $seated = array_sum($perFamily[$family['id']] ?? []);
            if ($seated < $family['people']) {
                $warnings[] = ['type' => 'unseated', 'household' => $family['id'], 'name' => $family['name'], 'count' => $family['people'] - $seated];
            }
        }

        return $warnings;
    }
}
