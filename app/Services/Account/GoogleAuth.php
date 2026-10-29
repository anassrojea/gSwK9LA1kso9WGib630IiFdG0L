<?php

namespace App\Services\Account;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialUser;

/**
 * "Continue with Google" (Socialite). Who a Google account signs in as:
 *
 *   1. the user with that google_id
 *   2. else the user with the same email, linked only when Google says the
 *      address is verified (otherwise anyone could claim an account by
 *      creating a Google account for someone else's address)
 *   3. else a new user, email marked verified, in the visitor's language
 */
class GoogleAuth
{
    public static function enabled(): bool
    {
        return filled(config('services.google.client_id'));
    }

    public static function emailVerified(SocialUser $google): bool
    {
        $raw = method_exists($google, 'getRaw') ? $google->getRaw() : [];
        $flag = $raw['email_verified'] ?? $raw['verified_email'] ?? false;

        return $flag === true || $flag === 'true' || $flag === 1 || $flag === '1';
    }

    /**
     * @return array{0: User, 1: bool} the user and whether it was just created
     *
     * @throws GoogleSignInRefused
     */
    public function resolve(SocialUser $google): array
    {
        $googleId = (string) $google->getId();
        $email = LoginCodes::normalize((string) $google->getEmail());

        if ($googleId === '') {
            throw new GoogleSignInRefused('failed');
        }

        if ($user = User::query()->where('google_id', $googleId)->first()) {
            return [$user, false];
        }

        if ($email === '') {
            throw new GoogleSignInRefused('no_email');
        }

        if ($user = User::query()->whereRaw('lower(email) = ?', [$email])->first()) {
            if (! self::emailVerified($google)) {
                throw new GoogleSignInRefused('unverified');
            }

            $user->forceFill(['google_id' => $googleId, 'email_verified_at' => $user->email_verified_at ?? now()])->save();

            return [$user, false];
        }

        if (! self::emailVerified($google)) {
            throw new GoogleSignInRefused('unverified');
        }

        $user = new User([
            'name' => Str::limit(trim((string) $google->getName()) ?: Str::before($email, '@'), 255, ''),
            'email' => $email,
            'google_id' => $googleId,
            'locale' => app()->getLocale(),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return [$user, true];
    }

    /**
     * Link a Google account to the signed-in user (Security tab).
     *
     * @throws GoogleSignInRefused when that Google account belongs to someone else
     */
    public function link(User $user, SocialUser $google): void
    {
        $googleId = (string) $google->getId();

        $owner = User::query()->where('google_id', $googleId)->first();
        if ($owner && ! $owner->is($user)) {
            throw new GoogleSignInRefused('taken');
        }

        $user->forceFill(['google_id' => $googleId])->save();
    }
}
