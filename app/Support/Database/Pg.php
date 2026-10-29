<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL-only DDL that the schema builder has no fluent API for: check
 * constraints, text[] columns and GIN indexes. Each helper is a no-op on
 * other drivers so the migrations still run on SQLite for quick experiments.
 */
class Pg
{
    public static function active(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * @param  list<string>  $values
     */
    public static function checkIn(string $table, string $column, array $values, bool $nullable = false): void
    {
        $list = implode(', ', array_map(fn ($v) => "'".str_replace("'", "''", $v)."'", $values));
        $expr = "{$column} in ({$list})";

        self::check($table, "{$table}_{$column}_check", $nullable ? "{$column} is null or {$expr}" : $expr);
    }

    public static function check(string $table, string $name, string $expression): void
    {
        if (self::active()) {
            DB::statement("alter table {$table} add constraint {$name} check ({$expression})");
        }
    }

    public static function textArray(string $table, string $column): void
    {
        if (self::active()) {
            DB::statement("alter table {$table} add column {$column} text[] not null default '{}'");
        } else {
            DB::statement("alter table {$table} add column {$column} text not null default '[]'");
        }
    }

    public static function gin(string $table, string $column): void
    {
        if (self::active()) {
            DB::statement("create index {$table}_{$column}_gin on {$table} using gin ({$column})");
        }
    }
}
