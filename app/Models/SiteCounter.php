<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Anonymous daily counters (table site_counters), e.g. "design_yours" clicks
 * per event. Nothing about the visitor is stored.
 */
class SiteCounter extends Model
{
    public const DESIGN_YOURS = 'design_yours';

    public $timestamps = false;

    protected $fillable = ['name', 'subject', 'day', 'count'];

    protected function casts(): array
    {
        return ['day' => 'date', 'count' => 'integer'];
    }

    public static function bump(string $name, ?string $subject = null): void
    {
        DB::table('site_counters')->upsert(
            [['name' => $name, 'subject' => (string) $subject, 'day' => now()->toDateString(), 'count' => 1]],
            ['name', 'day', 'subject'],
            ['count' => DB::raw('site_counters.count + 1')],
        );
    }

    public static function total(string $name, ?string $subject = null): int
    {
        return (int) static::query()->where('name', $name)
            ->when($subject !== null, fn ($q) => $q->where('subject', $subject))
            ->sum('count');
    }
}
