<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * "Send a rose" counter, one row per event.
 */
class Rose extends Model
{
    use HasFactory;

    protected $primaryKey = 'event_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['event_id', 'count'];

    protected function casts(): array
    {
        return ['count' => 'integer'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Atomic upsert-increment; returns the new count.
     */
    public static function send(Event $event): int
    {
        $now = now();

        DB::table('roses')->upsert(
            [['event_id' => $event->getKey(), 'count' => 1, 'created_at' => $now, 'updated_at' => $now]],
            ['event_id'],
            ['count' => DB::raw('roses.count + 1'), 'updated_at' => $now],
        );

        return (int) static::query()->whereKey($event->getKey())->value('count');
    }
}
