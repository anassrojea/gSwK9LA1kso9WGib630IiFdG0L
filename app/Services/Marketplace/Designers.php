<?php

namespace App\Services\Marketplace;

use App\Models\AuditLog;
use App\Models\DesignerProfile;
use App\Models\Template;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Designer accounts (plan section 2): a user applies (pending) and agrees to the
 * marketplace terms; an admin approves, suspends or sets the commission. Every change
 * is audit-logged. Suspending takes the designer's templates off sale (Template::onSale)
 * without touching invitations that already use them.
 */
class Designers
{
    /**
     * @param  array{display_name_ar: string, display_name_en: string, bio_ar?: ?string, bio_en?: ?string, portfolio_links?: list<string>, payout_details?: array<string, ?string>, country?: ?string, accept_terms: bool}  $data
     */
    public function apply(User $user, array $data): DesignerProfile
    {
        if (! ($data['accept_terms'] ?? false)) {
            throw ValidationException::withMessages(['accept_terms' => trans('marketplace.errors.terms')]);
        }

        return DB::transaction(function () use ($user, $data) {
            $profile = DesignerProfile::query()->where('user_id', $user->getKey())->lockForUpdate()->first() ?? new DesignerProfile(['user_id' => $user->getKey()]);
            $new = ! $profile->exists;

            $profile->fill([
                'display_name_ar' => $data['display_name_ar'],
                'display_name_en' => $data['display_name_en'],
                'bio_ar' => $data['bio_ar'] ?? null,
                'bio_en' => $data['bio_en'] ?? null,
                'portfolio_links' => array_values(array_filter($data['portfolio_links'] ?? [])),
                'country' => isset($data['country']) ? strtoupper($data['country']) : null,
                'terms_accepted_at' => $profile->terms_accepted_at ?? now(),
                'terms_version' => config('marketplace.terms_version'),
            ]);
            // the form never shows saved details back: empty fields keep what is stored
            $payout = array_filter((array) ($data['payout_details'] ?? []), fn ($v) => filled($v));
            if ($payout !== []) {
                $profile->payout_details = $payout;
            }
            $profile->slug ??= $this->slug($data['display_name_en'] ?: $data['display_name_ar']);
            $profile->save();

            AuditLog::record($new ? 'designer.applied' : 'designer.profile_updated', $profile, ['terms_version' => $profile->terms_version], $user);

            return $profile;
        });
    }

    public function approve(DesignerProfile $profile, User $admin): void
    {
        $this->ensureAdmin($admin);
        $profile->forceFill(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $admin->getKey(), 'status_reason' => null])->save();
        AuditLog::record('designer.approved', $profile, [], $admin);
    }

    public function suspend(DesignerProfile $profile, User $admin, string $reason): void
    {
        $this->ensureAdmin($admin);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => trans('validation.required', ['attribute' => 'reason'])]);
        }
        $profile->forceFill(['status' => 'suspended', 'status_reason' => $reason])->save();
        AuditLog::record('designer.suspended', $profile, ['reason' => $reason, 'templates' => Template::query()->where('designer_id', $profile->user_id)->count()], $admin);
    }

    /**
     * @param  ?int  $bp  basis points (7000 = 70 %); null: the default from config
     */
    public function setCommission(DesignerProfile $profile, ?int $bp, User $admin): void
    {
        $this->ensureAdmin($admin);
        if ($bp !== null && ($bp < 0 || $bp > 10000)) {
            throw ValidationException::withMessages(['commission_bp' => trans('marketplace.errors.commission')]);
        }
        $before = $profile->commission_bp;
        $profile->forceFill(['commission_bp' => $bp])->save();
        AuditLog::record('designer.commission_changed', $profile, ['from' => $before, 'to' => $bp], $admin);
    }

    /** An approved designer's profile, or a 403 (the portal's gate). */
    public static function approvedProfile(User $user): DesignerProfile
    {
        $profile = $user->designerProfile()->first();
        if (! $profile?->isApproved()) {
            throw new AuthorizationException(trans('marketplace.errors.not_designer'));
        }

        return $profile;
    }

    private function slug(string $name): string
    {
        $base = Str::slug($name) ?: 'designer';
        $base = Str::limit($base, 50, '');
        $slug = $base;
        while (DesignerProfile::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        return $slug;
    }

    private function ensureAdmin(User $user): void
    {
        if (! $user->isAdmin()) {
            throw new AuthorizationException(trans('app.payments.errors.admins_only'));
        }
    }
}
