<?php

namespace App\Services\Seating;

use App\Services\HallGenerator;
use Illuminate\Validation\ValidationException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * The hall layout document (plan section 7.1) and the single source of truth
 * for table shapes, their capacity and where their chairs stand. Used by the
 * hall designer, the seating planner, the auto-seater, the guest mini-map and
 * the printables; resources/js/lib/hall-layout.ts is the same geometry for the
 * browser and must stay in sync (tests/Fixtures/hall-geometry.json checks both).
 *
 * normalize() checks and cleans a layout sent by the planner or the designer:
 * only known keys survive, numbers are clamped to the hall and table ids stay
 * unique, so seat_assignments.table_id always points at a table. The result is
 * then checked against schemas/hall-layout.json.
 *
 * Shapes (all backward compatible: a layout with only round tables comes out
 * exactly as before):
 *
 *  round  x, y = centre; r (table), ring (radius the chairs sit on), chairR
 *  rect   banquet table: x, y = centre; w = length, h = depth, rotation 0|90,
 *         sides "both" | "one" (chairs on the front only), heads (a seat at each end)
 *  sofa   a lounge: w × h, seats along its front
 *  row    a straight row of chairs (e.g. the VIP row), no table
 *
 * Non-round tables carry the computed `chairs` [{x, y}] in seat order. Any
 * table may be a kids table (`kids: true`, used by the auto-seater).
 */
class HallLayoutData
{
    public const MAX_SIZE = 2000;

    public const MAX_NAME = 120;

    /** Seats of one table, sofa or row (round tables keep the generator's limit). */
    public const MAX_SEATS = 40;

    public const SHAPES = ['round', 'rect', 'sofa', 'row'];

    public const SECTION_IDS = ['mixed', 'men', 'women', 'vip'];

    public const OBSTACLE_KINDS = ['column', 'buffet', 'obstacle'];

    public const MAX_OBSTACLES = 60;

    public const MAX_LABELS = 40;

    public const SCHEMA_ID = 'https://daawa.app/schemas/hall-layout.json';

    /** Room between a table's edge and its chairs. */
    public const CHAIR_GAP = 2;

    private static ?Validator $validator = null;

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function normalize(mixed $layout, ?string $name = null): array
    {
        if (! is_array($layout)) {
            static::fail('layout', 'invalid');
        }

        $w = static::number($layout['size']['w'] ?? null, 100, self::MAX_SIZE) ?? static::fail('layout.size', 'invalid');
        $h = static::number($layout['size']['h'] ?? null, 100, self::MAX_SIZE) ?? static::fail('layout.size', 'invalid');

        $sections = [];
        foreach (array_slice((array) ($layout['sections'] ?? []), 0, count(self::SECTION_IDS)) as $section) {
            $id = is_array($section) ? ($section['id'] ?? null) : null;
            if (! in_array($id, self::SECTION_IDS, true) || isset($sections[$id])) {
                continue;
            }
            $sections[$id] = [
                'id' => $id,
                'label' => static::text($section['label'] ?? null, 40) ?? ($id === 'vip' ? 'VIP' : ucfirst($id)),
                'area' => static::rect($section['area'] ?? null, $w, $h) ?? ['x' => 0, 'y' => 0, 'w' => $w, 'h' => $h],
            ];
        }
        if ($sections === []) {
            $sections['mixed'] = ['id' => 'mixed', 'label' => 'Guests', 'area' => ['x' => 0, 'y' => 0, 'w' => $w, 'h' => $h]];
        }

        $tables = [];
        $ids = [];
        $raw = (array) ($layout['tables'] ?? []);
        if ($raw === [] || count($raw) > HallGenerator::MAX_TABLES) {
            static::fail('layout.tables', 'count');
        }
        foreach (array_values($raw) as $i => $table) {
            if (! is_array($table)) {
                static::fail("layout.tables.{$i}", 'invalid');
            }
            $id = is_string($table['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,16}$/', $table['id']) ? $table['id'] : null;
            if ($id === null || isset($ids[$id])) {
                static::fail("layout.tables.{$i}.id", 'invalid');
            }
            $ids[$id] = true;

            $shape = in_array($table['shape'] ?? 'round', self::SHAPES, true) ? ($table['shape'] ?? 'round') : 'round';
            $maxSeats = $shape === 'round' ? HallGenerator::MAX_CHAIRS : self::MAX_SEATS;
            $seats = filter_var($table['seats'] ?? null, FILTER_VALIDATE_INT);
            if ($seats === false || $seats < 1 || $seats > $maxSeats) {
                static::fail("layout.tables.{$i}.seats", 'invalid');
            }

            $section = in_array($table['section'] ?? null, array_keys($sections), true) ? $table['section'] : array_key_first($sections);
            $x = round(static::number($table['x'] ?? null, 0, $w) ?? $w / 2, 1);
            $y = round(static::number($table['y'] ?? null, 0, $h) ?? $h / 2, 1);

            if ($shape === 'round') {
                $chairR = static::number($table['chairR'] ?? null, 2, 30) ?? 7;
                $ring = static::number($table['ring'] ?? null, 6, 200) ?? 30;
                $out = [
                    'id' => $id,
                    'section' => $section,
                    'shape' => 'round',
                    'x' => $x,
                    'y' => $y,
                    'seats' => $seats,
                    'r' => round(static::number($table['r'] ?? null, 4, 200) ?? max(8, $ring - $chairR * 1.7), 1),
                    'chairR' => round($chairR, 1),
                    'ring' => round($ring, 1),
                ];
            } else {
                $chairR = round(static::number($table['chairR'] ?? null, 2, 30) ?? 7, 1);
                $defaultW = $shape === 'row' ? $seats * ($chairR * 2 + 4) : max(40, ceil($seats / ($shape === 'rect' ? 2 : 1)) * ($chairR * 2 + 6));
                $out = [
                    'id' => $id,
                    'section' => $section,
                    'shape' => $shape,
                    'x' => $x,
                    'y' => $y,
                    'seats' => $seats,
                    'w' => round(static::number($table['w'] ?? null, 10, $w) ?? min($w, $defaultW), 1),
                    'h' => round(static::number($table['h'] ?? null, 4, $h) ?? ($shape === 'rect' ? 30 : ($shape === 'sofa' ? 28 : $chairR * 2 + 4)), 1),
                    'rotation' => (int) ($table['rotation'] ?? 0) === 90 ? 90 : 0,
                ];
                if ($shape === 'rect') {
                    $out['sides'] = ($table['sides'] ?? 'both') === 'one' ? 'one' : 'both';
                    $out['heads'] = filter_var($table['heads'] ?? false, FILTER_VALIDATE_BOOL);
                }
                $out['chairR'] = $chairR;
                $out['chairs'] = static::chairPositions($out);
            }

            if (filter_var($table['kids'] ?? false, FILTER_VALIDATE_BOOL)) {
                $out['kids'] = true;
            }
            if ($label = static::text($table['label'] ?? null, 24)) {
                $out['label'] = $label;
            }

            $tables[] = $out;
        }

        $out = [
            'id' => static::text($layout['id'] ?? null, 64) ?? 'hall_'.substr(md5(json_encode($tables)), 0, 8),
            'name' => static::text($name ?? ($layout['name'] ?? null), self::MAX_NAME) ?? 'Main hall',
            'size' => ['w' => $w, 'h' => $h],
            'sections' => array_values($sections),
            'tables' => $tables,
        ];

        if ($stage = static::rect($layout['stage'] ?? null, $w, $h)) {
            $out['stage'] = $stage + ['label' => static::text($layout['stage']['label'] ?? null, 40) ?? 'Kosha'];
        }
        if ($dance = static::rect($layout['danceFloor'] ?? null, $w, $h)) {
            $out['danceFloor'] = $dance;
        }
        $out['entrances'] = collect((array) ($layout['entrances'] ?? []))
            ->take(4)
            ->filter(fn ($e) => is_array($e))
            ->map(fn ($e) => [
                'x' => static::number($e['x'] ?? null, 0, $w) ?? 0,
                'y' => static::number($e['y'] ?? null, 0, $h) ?? $h - 14,
                'w' => static::number($e['w'] ?? null, 10, $w) ?? 80,
            ] + ((int) ($e['rotation'] ?? 0) === 90 ? ['rotation' => 90] : []))
            ->values()
            ->all();

        if ($obstacles = static::obstacles($layout['obstacles'] ?? null, $w, $h)) {
            $out['obstacles'] = $obstacles;
        }
        if ($labels = static::labels($layout['labels'] ?? null, $w, $h)) {
            $out['labels'] = $labels;
        }

        static::assertSchema($out);

        return $out;
    }

    /**
     * Chair centres of a table in seat order (seat_index 0 first).
     *
     *  round  around the table from 12 o'clock, clockwise
     *  rect   clockwise from the top-left: the back side left to right, the right
     *         head, the front side right to left, the left head ("one" side: front only)
     *  sofa   along the front of the seat, left to right
     *  row    one line, left to right
     *
     * A table turned 90° has its "top" on the right.
     *
     * @return list<array{x: float, y: float}>
     */
    public static function chairPositions(array $table): array
    {
        $seats = max(0, (int) ($table['seats'] ?? 0));
        $x = (float) $table['x'];
        $y = (float) $table['y'];
        $shape = $table['shape'] ?? 'round';

        if ($shape === 'round') {
            $ring = (float) ($table['ring'] ?? 30);
            $out = [];
            for ($c = 0; $c < $seats; $c++) {
                $a = -M_PI / 2 + $c * 2 * M_PI / $seats;
                $out[] = ['x' => static::round1($x + $ring * cos($a)), 'y' => static::round1($y + $ring * sin($a))];
            }

            return $out;
        }

        $w = (float) ($table['w'] ?? 60);
        $h = (float) ($table['h'] ?? 30);
        $chairR = (float) ($table['chairR'] ?? 7);
        $local = [];

        if ($shape === 'rect') {
            $gap = $chairR + self::CHAIR_GAP;
            $heads = ! empty($table['heads']) && $seats >= 3 ? 2 : 0;
            $side = $seats - $heads;
            $back = ($table['sides'] ?? 'both') === 'one' ? 0 : (int) ceil($side / 2);
            $front = $side - $back;
            for ($k = 0; $k < $back; $k++) {
                $local[] = [static::spread($k, $back, $w), -($h / 2 + $gap)];
            }
            if ($heads) {
                $local[] = [$w / 2 + $gap, 0];
            }
            for ($k = $front - 1; $k >= 0; $k--) {
                $local[] = [static::spread($k, $front, $w), $h / 2 + $gap];
            }
            if ($heads) {
                $local[] = [-($w / 2 + $gap), 0];
            }
        } elseif ($shape === 'sofa') {
            for ($k = 0; $k < $seats; $k++) {
                $local[] = [static::spread($k, $seats, $w), $h * 0.12];
            }
        } else {
            for ($k = 0; $k < $seats; $k++) {
                $local[] = [static::spread($k, $seats, $w), 0];
            }
        }

        $turned = (int) ($table['rotation'] ?? 0) === 90;

        return array_map(fn (array $p) => $turned
            ? ['x' => static::round1($x - $p[1]), 'y' => static::round1($y + $p[0])]
            : ['x' => static::round1($x + $p[0]), 'y' => static::round1($y + $p[1])], $local);
    }

    /**
     * Half the width and height a table takes with its chairs, after rotation.
     *
     * @return array{0: float, 1: float}
     */
    public static function extent(array $table): array
    {
        $shape = $table['shape'] ?? 'round';
        if ($shape === 'round') {
            $e = (float) ($table['ring'] ?? 30) + (float) ($table['chairR'] ?? 7);

            return [$e, $e];
        }

        $w = (float) ($table['w'] ?? 60) / 2;
        $h = (float) ($table['h'] ?? 30) / 2;
        $chairR = (float) ($table['chairR'] ?? 7);
        if ($shape === 'rect') {
            $reach = $chairR * 2 + self::CHAIR_GAP;
            $w += ! empty($table['heads']) && (int) $table['seats'] >= 3 ? $reach : 0;
            $h += $reach;
        } elseif ($shape === 'row') {
            $h = max($h, $chairR);
        }

        return (int) ($table['rotation'] ?? 0) === 90 ? [$h, $w] : [$w, $h];
    }

    /**
     * The table top's size after rotation (no chairs): [w, h]; round tables [2r, 2r].
     *
     * @return array{0: float, 1: float}
     */
    public static function bodySize(array $table): array
    {
        if (($table['shape'] ?? 'round') === 'round') {
            $d = 2 * (float) ($table['r'] ?? 14);

            return [$d, $d];
        }

        $w = (float) ($table['w'] ?? 60);
        $h = (float) ($table['h'] ?? 30);

        return (int) ($table['rotation'] ?? 0) === 90 ? [$h, $w] : [$w, $h];
    }

    /**
     * One decimal, rounded exactly like JavaScript's Math.round(v * 10) / 10
     * (PHP's round() differs on ties such as 238.15), so the browser and the
     * server always agree on a chair's position.
     */
    public static function round1(float $value): float
    {
        return floor($value * 10 + 0.5) / 10;
    }

    /** "T7" → "7"; ids without a number are shown as they are. */
    public static function tableNumber(string $tableId): string
    {
        return preg_match('/(\d+)/', $tableId, $m) ? ltrim($m[1], '0') ?: '0' : $tableId;
    }

    /**
     * @return array<string, array{id: string, section: string, seats: int}>
     */
    public static function tablesById(array $layout): array
    {
        return collect($layout['tables'] ?? [])->keyBy('id')->all();
    }

    public static function totalSeats(array $layout): int
    {
        return (int) collect($layout['tables'] ?? [])->sum('seats');
    }

    /** True when the hall is split into men's and women's sections. */
    public static function isSplit(array $layout): bool
    {
        return collect($layout['tables'] ?? [])->pluck('section')->intersect(['men', 'women'])->isNotEmpty();
    }

    public static function isKidsTable(array $table): bool
    {
        return ! empty($table['kids']);
    }

    /**
     * Seats that a new version of the hall no longer has, per table id: a
     * table removed, or given fewer seats. [table id => first missing seat index]
     *
     * @return array<string, int>
     */
    public static function lostSeats(array $old, array $new): array
    {
        $now = static::tablesById($new);
        $lost = [];
        foreach ($old['tables'] ?? [] as $t) {
            $keep = isset($now[$t['id']]) ? (int) $now[$t['id']]['seats'] : 0;
            if ($keep < (int) $t['seats']) {
                $lost[$t['id']] = $keep;
            }
        }

        return $lost;
    }

    /**
     * Schema errors of a layout (schemas/hall-layout.json), JSON pointer => messages.
     *
     * @return array<string, list<string>>
     */
    public static function schemaErrors(array $layout): array
    {
        $result = static::validator()->validate(json_decode(json_encode($layout, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)), self::SCHEMA_ID);
        if ($result->isValid()) {
            return [];
        }

        return collect((new ErrorFormatter)->format($result->error(), true))
            ->mapWithKeys(fn ($messages, $pointer) => [($pointer === '' ? '/' : $pointer) => array_values((array) $messages)])
            ->all();
    }

    public static function schemaPath(): string
    {
        return __DIR__.'/schemas/hall-layout.json';
    }

    private static function assertSchema(array $layout): void
    {
        $errors = static::schemaErrors($layout);
        if ($errors !== []) {
            $pointer = array_key_first($errors);
            throw ValidationException::withMessages(['layout'.str_replace('/', '.', rtrim($pointer, '/')) => trans('seating.errors.layout_invalid')]);
        }
    }

    private static function validator(): Validator
    {
        if (static::$validator === null) {
            static::$validator = new Validator;
            static::$validator->setMaxErrors(10);
            static::$validator->resolver()->registerFile(self::SCHEMA_ID, static::schemaPath());
        }

        return static::$validator;
    }

    /** Centre of the k-th of n evenly spaced seats along a side of length len. */
    private static function spread(int $k, int $n, float $len): float
    {
        return -$len / 2 + $len * ($k + 0.5) / $n;
    }

    /**
     * @return list<array{id: string, kind: string, x: float, y: float, w: float, h: float, label?: string}>
     */
    private static function obstacles(mixed $raw, float $w, float $h): array
    {
        $out = [];
        $ids = [];
        foreach (array_slice(array_values((array) $raw), 0, self::MAX_OBSTACLES) as $o) {
            if (! is_array($o) || ! ($rect = static::rect($o, $w, $h))) {
                continue;
            }
            $id = static::elementId($o['id'] ?? null, 'O', $ids);
            $item = ['id' => $id, 'kind' => in_array($o['kind'] ?? null, self::OBSTACLE_KINDS, true) ? $o['kind'] : 'obstacle'] + $rect;
            if ($label = static::text($o['label'] ?? null, 40)) {
                $item['label'] = $label;
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @return list<array{id: string, text: string, x: float, y: float, size: float}>
     */
    private static function labels(mixed $raw, float $w, float $h): array
    {
        $out = [];
        $ids = [];
        foreach (array_slice(array_values((array) $raw), 0, self::MAX_LABELS) as $l) {
            $text = is_array($l) ? static::text($l['text'] ?? null, 60) : null;
            if ($text === null) {
                continue;
            }
            $out[] = [
                'id' => static::elementId($l['id'] ?? null, 'L', $ids),
                'text' => $text,
                'x' => round(static::number($l['x'] ?? null, 0, $w) ?? $w / 2, 1),
                'y' => round(static::number($l['y'] ?? null, 0, $h) ?? $h / 2, 1),
                'size' => round(static::number($l['size'] ?? null, 8, 48) ?? 14, 1),
            ];
        }

        return $out;
    }

    /** A valid, unique element id; a missing or repeated one gets the next free "{prefix}{n}". */
    private static function elementId(mixed $id, string $prefix, array &$taken): string
    {
        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{1,16}$/', $id) || isset($taken[$id])) {
            $n = count($taken) + 1;
            while (isset($taken[$prefix.$n])) {
                $n++;
            }
            $id = $prefix.$n;
        }
        $taken[$id] = true;

        return $id;
    }

    private static function number(mixed $value, float $min, float $max): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }
        $value = (float) $value;

        return is_finite($value) ? max($min, min($max, $value)) : null;
    }

    private static function rect(mixed $rect, float $w, float $h): ?array
    {
        if (! is_array($rect)) {
            return null;
        }
        $x = static::number($rect['x'] ?? null, 0, $w);
        $y = static::number($rect['y'] ?? null, 0, $h);
        $rw = static::number($rect['w'] ?? null, 1, $w);
        $rh = static::number($rect['h'] ?? null, 1, $h);

        return $x === null || $y === null || $rw === null || $rh === null ? null : ['x' => $x, 'y' => $y, 'w' => $rw, 'h' => $rh];
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private static function fail(string $key, string $reason): never
    {
        throw ValidationException::withMessages([$key => trans("seating.errors.layout_{$reason}")]);
    }
}
