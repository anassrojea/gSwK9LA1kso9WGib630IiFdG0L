<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Media extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'media';

    /** the host's own wizard uploads (cover, gallery, music) */
    public const SOURCE_HOST = 'host';

    /** guests' album uploads (plan section 11) */
    public const SOURCE_GUEST = 'guest';

    /** video guestbook messages (GuestbookEntry kind "video"): not part of the album */
    public const SOURCE_GUESTBOOK = 'guestbook';

    protected $fillable = [
        'event_id', 'household_id', 'kind', 'storage_key', 'thumb_key', 'preview_key', 'width', 'height', 'bytes', 'hidden',
        'source', 'mime', 'duration', 'uploader_name', 'approved_at',
    ];

    protected $attributes = ['source' => self::SOURCE_HOST, 'hidden' => false];

    protected function casts(): array
    {
        return ['hidden' => 'boolean', 'width' => 'integer', 'height' => 'integer', 'bytes' => 'integer', 'duration' => 'integer', 'approved_at' => 'immutable_datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * @param  Builder<Media>  $query
     */
    public function scopeAlbum(Builder $query): void
    {
        $query->where('source', self::SOURCE_GUEST)->whereIn('kind', ['photo', 'video']);
    }

    /**
     * What guests and the wall may see: not hidden by the host.
     *
     * @param  Builder<Media>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('hidden', false);
    }
}
