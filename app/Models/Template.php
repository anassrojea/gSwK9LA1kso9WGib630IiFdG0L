<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Opening + theme + default sections for an occasion. Source JSON lives in
 * templates/ and is seeded into the database (plan section 17).
 */
class Template extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'occasion', 'name_ar', 'name_en', 'tier', 'opening_id', 'theme_id', 'default_config', 'sort', 'active'];

    protected function casts(): array
    {
        return ['default_config' => 'array', 'active' => 'boolean', 'sort' => 'integer', 'admin_edited_at' => 'immutable_datetime'];
    }

    public function opening(): BelongsTo
    {
        return $this->belongsTo(Opening::class);
    }

    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }
}
