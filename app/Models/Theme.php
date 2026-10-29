<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Theme extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'name_ar', 'name_en', 'tier', 'tokens', 'fonts', 'preview_url', 'designer_id', 'active'];

    protected function casts(): array
    {
        return ['tokens' => 'array', 'fonts' => 'array', 'active' => 'boolean', 'admin_edited_at' => 'immutable_datetime'];
    }

    public function designer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'designer_id');
    }

    public function templates(): HasMany
    {
        return $this->hasMany(Template::class);
    }

    /**
     * The theme block of an invitation config (plan section 6.2).
     */
    public function toConfig(): array
    {
        $tokens = $this->tokens;

        return array_filter([
            'id' => $this->id,
            'colors' => $tokens['colors'] ?? [],
            'fonts' => $this->fonts,
            'radius' => $tokens['radius'] ?? null,
        ], fn ($v) => $v !== null);
    }
}
