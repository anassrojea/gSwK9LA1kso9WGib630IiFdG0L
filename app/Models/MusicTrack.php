<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A royalty-free track the admin uploaded for highlight videos (phase 8D).
 * The app ships no music: hosts pick one of these, or none. The file lives on
 * the media disk under music/.
 */
class MusicTrack extends Model
{
    use HasUuids;

    protected $fillable = ['title', 'artist', 'license', 'storage_key', 'duration', 'active', 'sort'];

    protected function casts(): array
    {
        return ['duration' => 'integer', 'active' => 'boolean', 'sort' => 'integer'];
    }
}
