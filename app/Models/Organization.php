<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A venue, planner or company (plan section 2). plan_id is the plan its
 * events get while its subscription is live (App\Services\Org\OrgSubscriptions
 * keeps it in sync; null without one). white_label: {brand_color, sender_name}
 * (phase 8A, App\Services\Org\WhiteLabel).
 */
class Organization extends Model
{
    use HasFactory, HasUuids;

    public const TYPES = ['venue', 'planner', 'company'];

    protected $fillable = ['name', 'type', 'logo_url', 'logo_path', 'plan_id', 'white_label', 'city', 'contact_phone', 'contact_email'];

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

    public function memberships(): HasMany
    {
        return $this->hasMany(OrgMember::class, 'org_id');
    }

    /**
     * The one owner (App\Services\Org\OrgTeam keeps there being exactly one).
     */
    public function owner(): ?User
    {
        return $this->members()->wherePivot('role', OrgMember::ROLE_OWNER)->first();
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

    public function subscriptions(): HasMany
    {
        return $this->hasMany(OrgSubscription::class, 'org_id')->orderByDesc('created_at');
    }

    /**
     * The live subscription (trialing, active or past_due), if any.
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(OrgSubscription::class, 'org_id')->whereIn('status', OrgSubscription::LIVE)->orderByDesc('created_at');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(OrgInvitation::class, 'org_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'org_id');
    }
}
