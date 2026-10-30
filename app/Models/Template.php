<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        return [
            'default_config' => 'array',
            'active' => 'boolean',
            'sort' => 'integer',
            'admin_edited_at' => 'immutable_datetime',
            // phase 8C, marketplace templates (designer_id set); see App\Services\Marketplace
            'prices' => 'array',
            'included_in_signature' => 'boolean',
            'published_at' => 'immutable_datetime',
            'sales_count' => 'integer',
        ];
    }

    /** A designer's template sold in the marketplace (not the house catalog). */
    public function isMarketplace(): bool
    {
        return $this->designer_id !== null;
    }

    /** Sold for money: a marketplace template with at least one price. */
    public function isPaidMarketplace(): bool
    {
        return $this->isMarketplace() && array_filter($this->prices ?? []) !== [];
    }

    public function designer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'designer_id');
    }

    public function designerProfile(): BelongsTo
    {
        return $this->belongsTo(DesignerProfile::class, 'designer_id', 'user_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(TemplateRevision::class)->orderByDesc('number');
    }

    public function liveRevision(): BelongsTo
    {
        return $this->belongsTo(TemplateRevision::class, 'live_revision_id');
    }

    /**
     * @param  Builder<Template>  $query
     */
    public function scopeMarketplace(Builder $query): void
    {
        $query->whereNotNull('designer_id');
    }

    /**
     * Live in the marketplace: published, active, and its designer not suspended.
     *
     * @param  Builder<Template>  $query
     */
    public function scopeOnSale(Builder $query): void
    {
        $query->whereNotNull('designer_id')
            ->where('marketplace_status', 'published')
            ->where('active', true)
            ->whereHas('designerProfile', fn ($q) => $q->where('status', 'approved'));
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
