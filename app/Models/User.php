<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;

class User extends Authenticatable implements FilamentUser, HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use Billable, HasFactory, HasUuids, Notifiable;

    public const ROLE_USER = 'user';

    public const ROLE_ADMIN = 'admin';

    // role is deliberately not fillable: admins are promoted explicitly, never through a form
    protected $fillable = ['name', 'email', 'phone', 'password', 'google_id', 'locale', 'country', 'currency'];

    protected $hidden = ['password', 'remember_token'];

    protected $attributes = ['role' => self::ROLE_USER, 'locale' => 'ar'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'trial_ends_at' => 'datetime',
        ];
    }

    /**
     * Mail (order approved, rejected, expired) goes out in the host's language.
     */
    public function preferredLocale(): string
    {
        return $this->locale ?: config('app.locale');
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin' && $this->isAdmin();
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'owner_id');
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'org_members', 'user_id', 'org_id')
            ->using(OrgMember::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * The user's role in an organization, or null when not a member.
     */
    public function roleIn(Organization|string|null $organization): ?string
    {
        if ($organization === null) {
            return null;
        }

        $orgId = $organization instanceof Organization ? $organization->getKey() : $organization;

        return OrgMember::query()
            ->where('org_id', $orgId)
            ->where('user_id', $this->getKey())
            ->value('role');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
