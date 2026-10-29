<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Generates a hall layout (plan section 7.1) from a table count, chairs per
 * table and a mixed or split (men / women) layout. Port of the generator on
 * the plan page (docs/samples/index.html); resources/js/lib/hall-generator.ts
 * is the same algorithm for the browser and must stay in sync.
 */
class HallGenerator
{
    public const WIDTH = 800;

    public const HEIGHT = 580;

    private const AREA_TOP = 190;

    private const AREA_BOTTOM_MARGIN = 40;

    public const MAX_TABLES = 200;

    public const MAX_CHAIRS = 24;

    /**
     * @return array{id: string, name: string, size: array, stage: array, danceFloor: array, entrances: array, sections: array, tables: array}
     */
    public function generate(
        int $tables,
        int $chairs,
        string $layout = 'mixed',
        string $shape = 'round',
        string $name = 'Main hall',
        ?string $id = null,
    ): array {
        if ($tables < 1 || $tables > self::MAX_TABLES) {
            throw new InvalidArgumentException('tables must be between 1 and '.self::MAX_TABLES);
        }
        if ($chairs < 1 || $chairs > self::MAX_CHAIRS) {
            throw new InvalidArgumentException('chairs must be between 1 and '.self::MAX_CHAIRS);
        }
        if (! in_array($layout, ['mixed', 'split'], true)) {
            throw new InvalidArgumentException('layout must be mixed or split');
        }
        if ($shape !== 'round') {
            throw new InvalidArgumentException('only round tables are supported for now');
        }

        $top = self::AREA_TOP;
        $height = self::HEIGHT - self::AREA_BOTTOM_MARGIN - $top;

        if ($layout === 'split') {
            $half = (int) ceil($tables / 2);
            $sections = [
                ['id' => 'men', 'label' => 'Men', 'area' => ['x' => 24, 'y' => $top, 'w' => 360, 'h' => $height]],
                ['id' => 'women', 'label' => 'Women', 'area' => ['x' => 416, 'y' => $top, 'w' => 360, 'h' => $height]],
            ];
            $counts = ['men' => $half, 'women' => $tables - $half];
        } else {
            $sections = [
                ['id' => 'mixed', 'label' => 'Guests', 'area' => ['x' => 24, 'y' => $top, 'w' => 752, 'h' => $height]],
            ];
            $counts = ['mixed' => $tables];
        }

        $placed = [];
        foreach ($sections as $section) {
            $placed = array_merge($placed, $this->placeTables(
                $counts[$section['id']], $section['area'], count($placed), $section['id'], $chairs, $shape,
            ));
        }

        return [
            'id' => $id ?? 'hall_'.substr(md5($name.$tables.$chairs.$layout), 0, 8),
            'name' => $name,
            'size' => ['w' => self::WIDTH, 'h' => self::HEIGHT],
            'stage' => ['x' => 270, 'y' => 24, 'w' => 260, 'h' => 62, 'label' => 'Kosha'],
            'danceFloor' => ['x' => 310, 'y' => 100, 'w' => 180, 'h' => 70],
            'entrances' => [['x' => 360, 'y' => self::HEIGHT - 14, 'w' => 80]],
            'sections' => $sections,
            'tables' => $placed,
        ];
    }

    /**
     * cols = round(sqrt(n × w / h)), rows = ceil(n / cols), last row centered.
     * Chair radius = 7.5% of the cell, ring = half the cell minus the chair
     * radius, table radius inside the ring.
     */
    private function placeTables(int $n, array $area, int $startIndex, string $section, int $chairs, string $shape): array
    {
        if ($n === 0) {
            return [];
        }

        $cols = max(1, (int) round(sqrt($n * $area['w'] / $area['h'])));
        $rows = (int) ceil($n / $cols);
        $cw = $area['w'] / $cols;
        $ch = $area['h'] / $rows;
        $cell = min($cw, $ch);

        $chairR = max(3.2, min(9, $cell * 0.075));
        $ring = $cell / 2 - $chairR - 3;
        $tableR = max(8, $ring - $chairR * 1.7);

        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $row = intdiv($i, $cols);
            $col = $i % $cols;
            $inRow = min($cols, $n - $row * $cols);
            $offset = ($cols - $inRow) * $cw / 2;

            $out[] = [
                'id' => 'T'.($startIndex + $i + 1),
                'section' => $section,
                'shape' => $shape,
                'x' => round($area['x'] + $offset + $cw * $col + $cw / 2, 1),
                'y' => round($area['y'] + $ch * $row + $ch / 2, 1),
                'seats' => $chairs,
                'r' => round($tableR, 1),
                'chairR' => round($chairR, 1),
                'ring' => round($ring, 1),
            ];
        }

        return $out;
    }
}
