<?php

namespace App\Models;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A venue's own photo or logo (phase 8B), on the media disk under orgs/{org_id}/.
 * Public: it is shown on the venue's directory page.
 */
class OrgMedia extends Model
{
    use HasFactory, HasUuids;

    public const KIND_LOGO = 'logo';

    public const KIND_PHOTO = 'photo';

    protected $table = 'org_media';

    protected $fillable = ['org_id', 'kind', 'storage_key', 'width', 'height', 'bytes', 'sort', 'caption'];

    protected function casts(): array
    {
        return ['width' => 'integer', 'height' => 'integer', 'bytes' => 'integer', 'sort' => 'integer'];
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.media_disk', 'public'));
    }

    public function url(): string
    {
        return static::disk()->url($this->storage_key);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    protected static function booted(): void
    {
        static::deleted(function (OrgMedia $media) {
            static::disk()->delete($media->storage_key);
        });
    }
}
