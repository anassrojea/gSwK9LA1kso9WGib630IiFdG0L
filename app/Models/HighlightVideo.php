<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One build of an event's highlight video (phase 8D, plan section 14.5),
 * made by App\Jobs\BuildHighlightVideo. queued → rendering → ready | failed.
 *
 * formats: ["vertical", "landscape"]
 * outputs: {vertical: {key, poster, width, height, duration, bytes}, ...}
 */
class HighlightVideo extends Model
{
    use HasUuids;

    public const STATUSES = ['queued', 'rendering', 'ready', 'failed'];

    protected $fillable = [
        'event_id', 'requested_by', 'music_track_id', 'status', 'outputs', 'formats', 'photo_count', 'clip_count', 'error',
        'started_at', 'finished_at',
    ];

    protected $attributes = ['status' => 'queued'];

    protected function casts(): array
    {
        return [
            'outputs' => 'array',
            'formats' => 'array',
            'photo_count' => 'integer',
            'clip_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function musicTrack(): BelongsTo
    {
        return $this->belongsTo(MusicTrack::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isBusy(): bool
    {
        return in_array($this->status, ['queued', 'rendering'], true);
    }
}
