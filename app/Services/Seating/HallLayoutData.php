<?php

namespace App\Services\Seating;

use App\Services\HallGenerator;
use Illuminate\Validation\ValidationException;

/**
 * Checks and cleans a hall layout document (plan section 7.1) sent by the
 * seating planner or the venue's hall editor, after the host nudged tables
 * by dragging. Only known keys survive, numbers are clamped to the hall and
 * table ids stay unique, so seat_assignments.table_id always points at a table.
 */
class HallLayoutData
{
    public const MAX_SIZE = 2000;

    public const MAX_NAME = 120;

    private const SECTION_IDS = ['mixed', 'men', 'women'];

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
        foreach (array_slice((array) ($layout['sections'] ?? []), 0, 3) as $section) {
            $id = is_array($section) ? ($section['id'] ?? null) : null;
            if (! in_array($id, self::SECTION_IDS, true) || isset($sections[$id])) {
                continue;
            }
            $sections[$id] = [
                'id' => $id,
                'label' => static::text($section['label'] ?? null, 40) ?? ucfirst($id),
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

            $seats = filter_var($table['seats'] ?? null, FILTER_VALIDATE_INT);
            if ($seats === false || $seats < 1 || $seats > HallGenerator::MAX_CHAIRS) {
                static::fail("layout.tables.{$i}.seats", 'invalid');
            }

            $section = in_array($table['section'] ?? null, array_keys($sections), true) ? $table['section'] : array_key_first($sections);
            $chairR = static::number($table['chairR'] ?? null, 2, 30) ?? 7;
            $ring = static::number($table['ring'] ?? null, 6, 200) ?? 30;

            $tables[] = [
                'id' => $id,
                'section' => $section,
                'shape' => 'round',
                'x' => round(static::number($table['x'] ?? null, 0, $w) ?? $w / 2, 1),
                'y' => round(static::number($table['y'] ?? null, 0, $h) ?? $h / 2, 1),
                'seats' => $seats,
                'r' => round(static::number($table['r'] ?? null, 4, 200) ?? max(8, $ring - $chairR * 1.7), 1),
                'chairR' => round($chairR, 1),
                'ring' => round($ring, 1),
            ];
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
            ])
            ->values()
            ->all();

        return $out;
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
