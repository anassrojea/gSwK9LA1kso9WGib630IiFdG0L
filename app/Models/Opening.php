<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A scroll-driven intro module (resources/js/openings/{id}.js).
 */
class Opening extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'name_ar', 'name_en', 'tier', 'module_path', 'preview_url', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function templates(): HasMany
    {
        return $this->hasMany(Template::class);
    }
}
