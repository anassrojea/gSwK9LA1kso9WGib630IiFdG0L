<?php

namespace App\Observers;

use App\Models\User;
use App\Services\Journey\Referrals;
use Throwable;

/**
 * Sign-up through a referral link (phase 8D): every way of creating an
 * account (the form, Google, an email code) creates a User, so the referral
 * cookie is read here. Also keeps the sign-up IP for the self-referral check.
 * Registered in JourneyServiceProvider.
 */
class ReferralSignups
{
    public function __construct(private Referrals $referrals) {}

    public function creating(User $user): void
    {
        // a web request (not a seeder or console command)
        if (request()->route() !== null) {
            $user->signup_ip ??= request()->ip();
        }
    }

    public function created(User $user): void
    {
        if (request()->route() === null) {
            return;
        }

        $code = request()->cookie((string) config('journey.referrals.cookie', 'daawa_ref'));
        if (! is_string($code) || $code === '') {
            return;
        }

        try {
            $this->referrals->attach($user, $code, request()->ip());
        } catch (Throwable $e) {
            // a referral problem never blocks a sign-up
            report($e);
        }
    }
}
