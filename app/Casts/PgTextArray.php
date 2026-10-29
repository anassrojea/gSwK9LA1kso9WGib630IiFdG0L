<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * PostgreSQL text[] <-> PHP list<string>. Falls back to JSON on other
 * drivers (see App\Support\Database\Pg::textArray()).
 */
class PgTextArray implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (str_starts_with($value, '[')) {
            return json_decode($value, true) ?? [];
        }

        $inner = substr($value, 1, -1);
        if ($inner === '') {
            return [];
        }

        // Postgres array literal: elements are bare or double-quoted with backslash escapes
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|([^,]+)/', $inner, $matches, PREG_SET_ORDER);

        return array_map(
            fn ($m) => isset($m[2]) && $m[2] !== '' ? $m[2] : stripcslashes($m[1]),
            $matches,
        );
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $items = array_values(array_map('strval', (array) ($value ?? [])));

        if ($model->getConnection()->getDriverName() !== 'pgsql') {
            return [$key => json_encode($items, JSON_UNESCAPED_UNICODE)];
        }

        $escaped = array_map(fn ($v) => '"'.addcslashes($v, '"\\').'"', $items);

        return [$key => '{'.implode(',', $escaped).'}'];
    }
}
