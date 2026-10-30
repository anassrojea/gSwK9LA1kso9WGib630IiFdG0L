<?php

namespace App\Services\Journey;

use App\Models\BundleCredit;
use App\Models\DiscountCode;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use App\Services\Digits;
use Illuminate\Validation\ValidationException;

/**
 * What the family journey adds to an event's checkout (phase 8D), applied by
 * CheckoutService::place to the priced lines, in this order:
 *
 *   1. bundle credit  the host chose a credit: a "bundle_credit" line pays the plan line in full.
 *                     It never combines with a code or an automatic reduction.
 *   2. reduction      otherwise the better automatic percent off the plan line — "loyalty"
 *                     (journey.loyalty, a returning customer: a paid plan on another event) or
 *                     "referral_welcome" (a referred user's first order) — competes with the
 *                     discount code: whichever takes off more applies, never both.
 *   3. account credit the host ticked "use my credit": a "referral_credit" line takes up to
 *                     the available balance in the charged currency off what is left.
 *
 * Negative lines keep the order's total = sum of lines − discount (+ VAT), so
 * invoices list them like any line. Twin of resources/js/lib/journey.ts for the display.
 */
class CheckoutExtras
{
    public function __construct(
        private BundleCredits $bundles,
        private Credits $credits,
        private Referrals $referrals,
    ) {}

    /**
     * A returning customer: a paid or approved plan on another of their events.
     */
    public function loyal(User $user, ?Event $except = null): bool
    {
        if (! config('journey.loyalty.enabled', true) || (int) config('journey.loyalty.percent', 0) <= 0) {
            return false;
        }

        return Order::query()->where('user_id', $user->getKey())->fulfilled()
            ->when($except, fn ($q) => $q->where(fn ($q) => $q->whereNull('event_id')->orWhere('event_id', '!=', $except->getKey())))
            ->whereRaw('items @> ?::jsonb', [json_encode([['type' => 'plan']])])
            ->exists();
    }

    /**
     * The automatic reduction this host gets on this event's plan, or null.
     *
     * @return array{type: string, percent: int}|null
     */
    public function reduction(User $user, Event $event): ?array
    {
        $candidates = [];
        if ($this->loyal($user, $event)) {
            $candidates[] = ['type' => 'loyalty', 'percent' => (int) config('journey.loyalty.percent')];
        }
        if ($this->referrals->welcomeEligible($user) && (int) config('journey.referrals.welcome_percent', 0) > 0) {
            $candidates[] = ['type' => 'referral_welcome', 'percent' => (int) config('journey.referrals.welcome_percent')];
        }

        return collect($candidates)->sortByDesc('percent')->first();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array{bundle_credit?: ?string, use_credit?: bool}  $extras
     * @return array{0: list<array<string, mixed>>, 1: ?DiscountCode, 2: int}
     */
    public function apply(User $user, Event $event, array $lines, string $currency, ?DiscountCode $code, int $discount, array $extras): array
    {
        $planIndex = collect($lines)->search(fn (array $l) => $l['type'] === 'plan');
        $plan = $planIndex === false ? null : $lines[$planIndex];

        if (filled($extras['bundle_credit'] ?? null)) {
            if (! $plan) {
                throw ValidationException::withMessages(['bundle_credit' => trans('journey.bundles.errors.no_plan')]);
            }
            $credit = $this->bundles->usable($user, (string) $extras['bundle_credit'], (string) $plan['id']);
            $lines[] = $this->line('bundle_credit', $credit->getKey(), trans('journey.bundles.credit_line', ['plan' => trans("app.wizard.tiers.{$credit->plan_id}", [], 'en')], 'en'), (int) $plan['amount']);
            $code = null;
            $discount = 0;
        } elseif ($plan && ($reduction = $this->reduction($user, $event))) {
            $amount = intdiv((int) $plan['amount'] * $reduction['percent'], 100);
            if ($amount > $discount) {
                $lines[] = $this->line($reduction['type'], $reduction['type'], static::reductionLabel($reduction['type'], $reduction['percent'], 'en'), $amount) + ['percent' => $reduction['percent']];
                $code = null;
                $discount = 0;
            }
        }

        if ($extras['use_credit'] ?? false) {
            $net = array_sum(array_column($lines, 'amount')) - $discount;
            $spend = min($this->credits->available($user, $currency), max(0, $net));
            if ($spend > 0) {
                $lines[] = $this->line('referral_credit', 'credit', trans('journey.referrals.credit_line', [], 'en'), $spend);
            }
        }

        return [$lines, $code, $discount];
    }

    /**
     * What the checkout panel shows (CheckoutService::options()['journey']).
     *
     * @return array<string, mixed>
     */
    public function options(Event $event, ?User $user): array
    {
        if (! $user) {
            return ['reduction' => null, 'bundle_credits' => [], 'credit' => (object) []];
        }
        $locale = app()->getLocale();

        return [
            'reduction' => ($r = $this->reduction($user, $event)) ? $r + ['label' => static::reductionLabel($r['type'], $r['percent'], $locale)] : null,
            'bundle_credits' => $this->bundles->available($user)
                ->map(fn (BundleCredit $c) => $this->bundles->present($c, $locale))
                ->values()->all(),
            'credit' => (object) array_filter($this->credits->availableAll($user)),
        ];
    }

    /** "خصم العميل الدائم ١٠٪" / "Returning customer 10% off". */
    public static function reductionLabel(string $type, int $percent, string $locale): string
    {
        return trans("journey.reductions.{$type}", ['percent' => Digits::localize($percent, $locale)], $locale);
    }

    private function line(string $type, string $id, string $name, int $amount): array
    {
        return ['type' => $type, 'id' => $id, 'name' => $name, 'quantity' => 1, 'unit_amount' => -$amount, 'amount' => -$amount];
    }
}
