<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a marketplace template: draft → in_review → approved | rejected; an approved
 * revision replaced by a newer one is superseded. App\Services\Marketplace\TemplateReview.
 */
class TemplateRevision extends Model
{
    use HasUuids;

    public const STATUSES = ['draft', 'in_review', 'approved', 'rejected', 'superseded'];

    protected $fillable = [
        'template_id', 'number', 'name_ar', 'name_en', 'description_ar', 'description_en', 'occasion', 'opening_id', 'theme_id',
        'default_config', 'prices', 'included_in_signature', 'status', 'rejection_reason', 'submitted_at', 'reviewed_at', 'reviewed_by',
    ];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'default_config' => 'array',
            'prices' => 'array',
            'included_in_signature' => 'boolean',
            'number' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function opening(): BelongsTo
    {
        return $this->belongsTo(Opening::class);
    }

    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Still editable by the designer (a rejected one goes back to draft when edited). */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }
}
