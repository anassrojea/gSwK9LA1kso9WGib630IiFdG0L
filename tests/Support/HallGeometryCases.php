<?php

namespace Tests\Support;

/**
 * Inputs of the PHP ↔ TypeScript geometry parity test. The expected output is
 * tests/Fixtures/hall-geometry.json; tests/Unit/HallGeometryTest.php checks
 * the PHP side against it (and can rewrite it with HALL_FIXTURE_WRITE=1), and
 * tests/js/hall-layout.test.ts checks resources/js/lib/hall-layout.ts and
 * hall-generator.ts against the same file.
 */
class HallGeometryCases
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function tables(): array
    {
        $base = ['section' => 'mixed', 'x' => 200, 'y' => 150, 'chairR' => 7];

        return [
            'round 8' => $base + ['id' => 'T1', 'shape' => 'round', 'seats' => 8, 'r' => 18, 'ring' => 30],
            'round 1' => $base + ['id' => 'T2', 'shape' => 'round', 'seats' => 1, 'r' => 10, 'ring' => 20],
            'banquet 10 both' => $base + ['id' => 'T3', 'shape' => 'rect', 'seats' => 10, 'w' => 120, 'h' => 30, 'rotation' => 0, 'sides' => 'both', 'heads' => false],
            'banquet 9 heads turned' => $base + ['id' => 'T4', 'shape' => 'rect', 'seats' => 9, 'w' => 100, 'h' => 26, 'rotation' => 90, 'sides' => 'both', 'heads' => true],
            'banquet 6 one side' => $base + ['id' => 'T5', 'shape' => 'rect', 'seats' => 6, 'w' => 110, 'h' => 24, 'rotation' => 0, 'sides' => 'one', 'heads' => false],
            'banquet 7 one side heads turned' => $base + ['id' => 'T6', 'shape' => 'rect', 'seats' => 7, 'w' => 90, 'h' => 24, 'rotation' => 90, 'sides' => 'one', 'heads' => true],
            'banquet 2 heads ignored' => $base + ['id' => 'T7', 'shape' => 'rect', 'seats' => 2, 'w' => 40, 'h' => 20, 'rotation' => 0, 'sides' => 'both', 'heads' => true],
            'sofa 3' => $base + ['id' => 'S1', 'shape' => 'sofa', 'seats' => 3, 'w' => 70, 'h' => 28, 'rotation' => 0, 'chairR' => 9],
            'sofa 4 turned' => $base + ['id' => 'S2', 'shape' => 'sofa', 'seats' => 4, 'w' => 90, 'h' => 30, 'rotation' => 90],
            'vip row 12' => $base + ['id' => 'V1', 'shape' => 'row', 'seats' => 12, 'w' => 216, 'h' => 18, 'rotation' => 0, 'section' => 'vip'],
            'row 5 turned' => $base + ['id' => 'V2', 'shape' => 'row', 'seats' => 5, 'w' => 90, 'h' => 18, 'rotation' => 90],
        ];
    }

    /**
     * @return array<string, array{tables: int, chairs: int, layout: string, shape: string}>
     */
    public static function generator(): array
    {
        return [
            'round split 20×8' => ['tables' => 20, 'chairs' => 8, 'layout' => 'split', 'shape' => 'round'],
            'round mixed 33×10' => ['tables' => 33, 'chairs' => 10, 'layout' => 'mixed', 'shape' => 'round'],
            'banquet mixed 20×10' => ['tables' => 20, 'chairs' => 10, 'layout' => 'mixed', 'shape' => 'rect'],
            'banquet split 21×8' => ['tables' => 21, 'chairs' => 8, 'layout' => 'split', 'shape' => 'rect'],
            'banquet mixed 7×12' => ['tables' => 7, 'chairs' => 12, 'layout' => 'mixed', 'shape' => 'rect'],
            'banquet one 1×2' => ['tables' => 1, 'chairs' => 2, 'layout' => 'mixed', 'shape' => 'rect'],
        ];
    }

    public static function fixturePath(): string
    {
        return dirname(__DIR__).'/Fixtures/hall-geometry.json';
    }
}
