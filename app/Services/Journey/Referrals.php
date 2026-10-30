<?php

namespace App\Services\Journey;

use App\Models\AccountCredit;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Referral;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Referrals (phase 8D, plan section 14.7).
 *
 *   1. Every user has a link /r/{code} (code made on first use). It is also
 *      credited on the "صمّم دعوتك" footer of their invitations when they opt in
 *      (users.referral_footer): /r/design-yours?e=… finds the event's owner.
 *   2. Either sets a cookie for config journey.referrals.cookie_days.
 *   3. Signing up with the cookie records a referral (pending), or "blocked"
 *      when it looks like the referrer signing up again: the same email once
 *      normalised, the same phone, or the same IP and the same email domain.
 *   4. The referred user's first paid order (total above zero) that is paid or
 *      approved gives the referrer a credit (journey.referrals.reward, in the
 *      order's currency), at most monthly_cap per calendar month ("capped"
 *      after that). The referred user gets welcome_percent off that first
 *      order's plan (CheckoutExtras), unless a better discount applies.
 */
class Referrals
{
    /** No 0/O or 1/I: codes are read aloud and typed. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const CODE_PATTERN = '[A-HJ-NP-Z2-9]{8}';

    public function __construct(private Credits $credits) {}

    public static function enabled(): bool
    {
        return (bool) config('journey.referrals.enabled', true);
    }

    public function codeFor(User $user): string
    {
        if ($user->referral_code) {
            return $user->referral_code;
        }

        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (User::query()->where('referral_code', $code)->exists());

        $user->forceFill(['referral_code' => $code])->save();

        return $code;
    }

    public function link(User $user): string
    {
        return route('referral.visit', $this->codeFor($user));
    }

    public function referrer(?string $code): ?User
    {
        $code = strtoupper(trim((string) $code));

        return preg_match('/^'.self::CODE_PATTERN.'$/', $code) ? User::query()->where('referral_code', $code)->first() : null;
    }

    public function cookie(User $referrer): Cookie
    {
        return cookie(
            (string) config('journey.referrals.cookie', 'daawa_ref'),
            $this->codeFor($referrer),
            (int) config('journey.referrals.cookie_days', 30) * 24 * 60,
        );
    }

    /**
     * A new account signed up with a referral code (UserObserver).
     */
    public function attach(User $user, ?string $code, ?string $ip): ?Referral
    {
        if (! static::enabled()) {
            return null;
        }
        $referrer = $this->referrer($code);
        if (! $referrer || $referrer->is($user)) {
            return null;
        }
        if ($existing = Referral::query()->where('referred_user_id', $user->getKey())->first()) {
            return $existing;
        }

        $self = $this->looksLikeSelf($referrer, $user, $ip);
        $referral = Referral::query()->create([
            'referrer_user_id' => $referrer->getKey(),
            'referred_user_id' => $user->getKey(),
            'code' => $referrer->referral_code,
            'ip' => $ip,
            'reward_status' => $self ? Referral::BLOCKED : Referral::PENDING,
            'reason' => $self,
        ]);

        AuditLog::record($self ? 'referral.blocked' : 'referral.signed_up', $referral, array_filter(['reason' => $self]));

        return $referral;
    }

    /**
     * Why this sign-up is probably the referrer themselves, or null.
     */
    public function looksLikeSelf(User $referrer, User $user, ?string $ip): ?string
    {
        if ($referrer->email && $user->email && static::normalizeEmail($referrer->email) === static::normalizeEmail($user->email)) {
            return 'same_email';
        }
        if ($referrer->phone && $user->phone && $referrer->phone === $user->phone) {
            return 'same_phone';
        }

        $sameDomain = $referrer->email && $user->email
            && Str::lower(Str::after($referrer->email, '@')) === Str::lower(Str::after($user->email, '@'));
        if ($sameDomain && $ip && in_array($ip, $this->knownIps($referrer), true)) {
            return 'same_ip_and_domain';
        }

        return null;
    }

    /**
     * "r.a.n.a+test@gmail.com" and "rana@gmail.com" are one mailbox.
     */
    public static function normalizeEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', Str::lower(trim($email)), 2), 2, '');
        $local = Str::before($local, '+');
        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return $local.'@'.$domain;
    }

    /**
     * An order changed to paid or approved (OrderObserver): reward the referrer on the first paid one.
     */
    public function orderFulfilled(Order $order): void
    {
        if (! static::enabled() || ! $order->user_id || $order->total_cents <= 0 || ! $order->isFulfilled()) {
            return;
        }

        DB::transaction(function () use ($order) {
            $referral = Referral::query()->where('referred_user_id', $order->user_id)->where('reward_status', Referral::PENDING)->lockForUpdate()->first();
            if (! $referral) {
                return;
            }

            $earlier = Order::query()->where('user_id', $order->user_id)->whereKeyNot($order->getKey())
                ->whereIn('status', [...Order::FULFILLED, 'refunded'])->where('total_cents', '>', 0)->exists();
            if ($earlier) {
                $referral->update(['reward_status' => Referral::VOID, 'reason' => 'not_first_order']);

                return;
            }

            $amount = (int) (config("journey.referrals.reward.{$order->currency}") ?? 0);
            $month = CarbonImmutable::now()->startOfMonth();
            $thisMonth = Referral::query()->where('referrer_user_id', $referral->referrer_user_id)
                ->where('reward_status', Referral::REWARDED)->where('rewarded_at', '>=', $month)->count();

            if ($thisMonth >= (int) config('journey.referrals.monthly_cap', 10) || $amount <= 0) {
                $referral->update(['reward_status' => Referral::CAPPED, 'reason' => $amount <= 0 ? 'no_reward_currency' : 'monthly_cap', 'reward_order_id' => $order->getKey()]);
                AuditLog::record('referral.capped', $referral, ['order' => $order->code]);

                return;
            }

            $this->credits->add($referral->referrer_user_id, $order->currency, $amount, AccountCredit::REFERRAL_REWARD, ['referral_id' => $referral->getKey(), 'order_id' => $order->getKey()]);
            $referral->update(['reward_status' => Referral::REWARDED, 'rewarded_at' => now(), 'reward_order_id' => $order->getKey(), 'reason' => null]);
            AuditLog::record('referral.rewarded', $referral, ['order' => $order->code, 'amount' => $amount, 'currency' => $order->currency]);
        });
    }

    /**
     * The referred user's first order gets the welcome discount: referred (not blocked), nothing paid yet.
     */
    public function welcomeEligible(User $user): bool
    {
        return static::enabled()
            && Referral::query()->where('referred_user_id', $user->getKey())->where('reward_status', Referral::PENDING)->exists()
            && ! Order::query()->where('user_id', $user->getKey())->whereIn('status', [...Order::FULFILLED, 'refunded'])->where('total_cents', '>', 0)->exists();
    }

    /**
     * @return array{signups: int, rewarded: int, pending: int, capped: int}
     */
    public function stats(User $user): array
    {
        $counts = Referral::query()->where('referrer_user_id', $user->getKey())
            ->selectRaw('reward_status, count(*) as n')->groupBy('reward_status')->pluck('n', 'reward_status');

        return [
            'signups' => (int) $counts->except([Referral::BLOCKED])->sum(),
            'rewarded' => (int) ($counts[Referral::REWARDED] ?? 0),
            'pending' => (int) ($counts[Referral::PENDING] ?? 0),
            'capped' => (int) ($counts[Referral::CAPPED] ?? 0),
        ];
    }

    /**
     * IPs the referrer is known from: their sign-up and their sessions.
     *
     * @return list<string>
     */
    private function knownIps(User $referrer): array
    {
        $ips = DB::table('sessions')->where('user_id', $referrer->getKey())->whereNotNull('ip_address')->pluck('ip_address')->all();
        if ($referrer->signup_ip) {
            $ips[] = $referrer->signup_ip;
        }

        return array_values(array_unique($ips));
    }
}
