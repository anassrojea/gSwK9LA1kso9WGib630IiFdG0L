<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory, HasUuids;

    public const TYPES = ['venue', 'planner', 'company'];

    protected $fillable = ['name', 'type', 'logo_url', 'plan_id', 'white_label'];

    protected function casts(): array
    {
        return ['white_label' => 'array'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'org_members', 'org_id', 'user_id')
            ->using(OrgMember::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'org_id');
    }

    public function hallLayouts(): HasMany
    {
        return $this->hasMany(HallLayout::class, 'org_id');
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class, 'org_id');
    }
}
