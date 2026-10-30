<?php

namespace App\Models;

use App\Services\Account\GoogleAuth;
use App\Services\Account\LoginCodes;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;

/**
 * MustVerifyEmail: a password sign-up gets a verification link and must use it
 * before paying or publishing (App\Services\Account\EmailVerification) and before
 * reaching /admin (the panel's ->emailVerification()). Google and email-code
 * accounts are verified when they sign in; changing the email with a password
 * makes it unverified again and sends a new link (UpdateUserProfileInformation).
 */
class User extends Authenticatable implements FilamentUser, HasLocalePreference, MustVerifyEmail
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
            'deletion_requested_at' => 'immutable_datetime',
            // phase 8D (App\Services\Journey): referral footer opt-in, marketing email opt-out
            'referral_footer' => 'boolean',
            'marketing_opt_out' => 'boolean',
        ];
    }

    /**
     * Mail (order approved, rejected, expired) goes out in the host's language.
     */
    public function preferredLocale(): string
    {
        return $this->locale ?: config('app.locale');
    }

    public function hasPassword(): bool
    {
        return filled($this->getAuthPassword());
    }

    /**
     * The ways this account can sign in now: password, google, email_code.
     * The account area never removes the last one (App\Http\Controllers\Account\SecurityController).
     *
     * @return list<string>
     */
    public function signInMethods(): array
    {
        return array_values(array_filter([
            $this->hasPassword() ? 'password' : null,
            $this->google_id && GoogleAuth::enabled() ? 'google' : null,
            filled($this->email) && LoginCodes::enabled() ? 'email_code' : null,
        ]));
    }

    /**
     * Unlinking Google must leave another way in (a password or email codes).
     */
    public function canUnlinkGoogle(): bool
    {
        return $this->google_id !== null && array_diff($this->signInMethods(), ['google']) !== [];
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

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** Phase 8C: the designer application (marketplace), if any. */
    public function designerProfile(): HasOne
    {
        return $this->hasOne(DesignerProfile::class);
    }

    /** An approved, not suspended, designer. */
    public function isDesigner(): bool
    {
        return $this->designerProfile?->isApproved() ?? false;
    }

    /** Phase 8C: the vendor listing, if any. */
    public function vendorListing(): HasOne
    {
        return $this->hasOne(VendorListing::class);
    }

    /** Phase 8D: credit ledger, bundle credits and the referrals this user made. */
    public function accountCredits(): HasMany
    {
        return $this->hasMany(AccountCredit::class);
    }

    public function bundleCredits(): HasMany
    {
        return $this->hasMany(BundleCredit::class);
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'referrer_user_id');
    }
}
