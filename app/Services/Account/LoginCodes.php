<?php

namespace App\Services\Account;

use App\Models\LoginCode;
use App\Models\User;
use App\Notifications\LoginCodeNotification;
use App\Services\Digits;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Six-digit codes sent by email (plan section 15.1, "email login codes"):
 *
 *   - only an HMAC of the code is stored, with a 10-minute expiry and 5 tries
 *   - sending a new code invalidates the older ones for that email and purpose
 *   - sends are throttled per email and per IP address
 *   - a code is used once
 *
 * The caller decides what a verified code does (sign in, delete the account,
 * confirm a new address). For sign-in the same response goes out whether or
 * not the email has an account, so the form can't be used to find accounts.
 */
class LoginCodes
{
    public const DECAY_SECONDS = 600;

    public static function enabled(): bool
    {
        return (bool) config('account.login_codes.enabled', true);
    }

    public static function normalize(string $email): string
    {
        return Str::lower(trim($email));
    }

    /**
     * Send a fresh code to $email. Throws a ValidationException on the "email"
     * field when throttled (the same message whether the account exists or not).
     */
    public function send(string $email, string $purpose, ?string $ip, ?string $locale = null): void
    {
        $email = self::normalize($email);
        $this->throttle($email, $purpose, $ip);

        $code = (string) random_int(0, 999_999);
        $code = str_pad($code, 6, '0', STR_PAD_LEFT);

        LoginCode::query()->where('email', $email)->where('purpose', $purpose)->whereNull('used_at')->delete();

        LoginCode::query()->create([
            'email' => $email,
            'purpose' => $purpose,
            'code_hash' => $this->hash($email, $purpose, $code),
            'expires_at' => now()->addMinutes((int) config('account.login_codes.ttl_minutes', 10)),
            'ip' => $ip,
        ]);

        $locale ??= User::query()->where('email', $email)->value('locale') ?? app()->getLocale();

        Notification::route('mail', $email)->notify((new LoginCodeNotification($code, $purpose))->locale($locale));
    }

    /**
     * Check a code and use it up. Wrong codes count against the code's tries;
     * an expired, used-up or exhausted code never verifies.
     *
     * @throws ValidationException on "code"
     */
    public function verify(string $email, string $purpose, string $code): void
    {
        $email = self::normalize($email);
        $code = preg_replace('/\D+/', '', Digits::toWestern($code)) ?? '';
        $max = (int) config('account.login_codes.max_attempts', 5);

        $row = LoginCode::query()
            ->where('email', $email)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->latest()
            ->first();

        if (! $row || $row->expires_at->isPast()) {
            throw ValidationException::withMessages(['code' => trans('app.auth.code.expired')]);
        }

        if ($row->attempts >= $max) {
            throw ValidationException::withMessages(['code' => trans('app.auth.code.too_many_attempts')]);
        }

        if (strlen($code) !== 6 || ! hash_equals($row->code_hash, $this->hash($email, $purpose, $code))) {
            $row->increment('attempts');

            throw ValidationException::withMessages(['code' => $row->attempts >= $max
                ? trans('app.auth.code.too_many_attempts')
                : trans('app.auth.code.wrong')]);
        }

        // single use: the conditional update makes two simultaneous submits of one code count once
        $used = LoginCode::query()->whereKey($row->getKey())->whereNull('used_at')->update(['used_at' => now()]);
        if ($used !== 1) {
            throw ValidationException::withMessages(['code' => trans('app.auth.code.expired')]);
        }
    }

    private function hash(string $email, string $purpose, string $code): string
    {
        return hash_hmac('sha256', "{$purpose}|{$email}|{$code}", (string) config('app.key'));
    }

    private function throttle(string $email, string $purpose, ?string $ip): void
    {
        $keys = [
            "login-code:email:{$purpose}:".sha1($email) => (int) config('account.login_codes.per_email', 3),
            'login-code:ip:'.($ip ?? 'none') => (int) config('account.login_codes.per_ip', 10),
        ];

        foreach ($keys as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages(['email' => trans('app.auth.code.throttled', [
                    'minutes' => Digits::localize((int) ceil(RateLimiter::availableIn($key) / 60)),
                ])]);
            }
        }

        foreach (array_keys($keys) as $key) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
        }
    }
}
