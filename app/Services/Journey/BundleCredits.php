<?php

namespace App\Services\Journey;

use App\Models\AuditLog;
use App\Models\Bundle;
use App\Models\BundleCredit;
use App\Models\Event;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\Payments\Currencies;
use App\Services\Payments\PriceList;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Family journey bundles (phase 8D, plan section 14.2). Buying a bundle (order
 * line "bundle", BundleItem) issues one credit per event plan it holds, usable
 * for validity_days. At an event's checkout a credit pays that event's plan
 * line in full (line "bundle_credit", BundleCreditItem): with nothing else to
 * pay the order is a zero total, paid at once through OrderFulfillment::fulfillFree
 * like a 100% discount code. A credit pays a plan of its own tier only.
 */
class BundleCredits
{
    public function __construct(private PriceList $prices) {}

    /**
     * @return Collection<int, Bundle>
     */
    public function forSale(): Collection
    {
        return Bundle::query()->where('active', true)->orderBy('sort')->orderBy('id')->get();
    }

    /**
     * The credits a paid bundle order grants (idempotent per order).
     *
     * @param  array<string, mixed>  $line
     * @return list<BundleCredit>
     */
    public function issue(Order $order, array $line, CarbonImmutable $from): array
    {
        $bundle = Bundle::query()->find($line['id'] ?? null);
        if (! $bundle || ! $order->user_id || BundleCredit::query()->where('source_order_id', $order->getKey())->exists()) {
            return [];
        }

        $days = (int) ($bundle->validity_days ?: config('journey.bundles.validity_days', 730));
        $credits = [];
        for ($copy = 0; $copy < max(1, (int) ($line['quantity'] ?? 1)); $copy++) {
            foreach ($bundle->creditCounts() as $plan => $count) {
                for ($i = 0; $i < $count; $i++) {
                    $credits[] = BundleCredit::query()->create([
                        'user_id' => $order->user_id,
                        'bundle_id' => $bundle->id,
                        'plan_id' => $plan,
                        'source_order_id' => $order->getKey(),
                        'expires_at' => $from->addDays($days),
                    ]);
                }
            }
        }

        AuditLog::record('bundle.credits_issued', $order, ['bundle' => $bundle->id, 'credits' => count($credits)]);

        return $credits;
    }

    /**
     * A refunded bundle: its credits not used yet are voided (used ones stay used).
     */
    public function void(Order $order): int
    {
        return BundleCredit::query()->where('source_order_id', $order->getKey())->whereNull('used_at')->whereNull('voided_at')
            ->update(['voided_at' => now()]);
    }

    /**
     * @return Collection<int, BundleCredit>
     */
    public function available(User $user): Collection
    {
        return BundleCredit::query()->with('bundle')->where('user_id', $user->getKey())->available()
            ->orderBy('expires_at')->get()
            // a credit already on an unpaid order waits for that order
            ->reject(fn (BundleCredit $c) => $this->reserved($c));
    }

    /**
     * The credit a host chose at checkout, if they may use it for this plan now.
     *
     * @throws ValidationException
     */
    public function usable(User $user, string $creditId, string $planId): BundleCredit
    {
        $credit = BundleCredit::query()->where('user_id', $user->getKey())->whereKey($creditId)->first();

        if (! $credit || $credit->voided_at || $credit->used_at) {
            throw ValidationException::withMessages(['bundle_credit' => trans('journey.bundles.errors.unknown')]);
        }
        if ($credit->expires_at->isPast()) {
            throw ValidationException::withMessages(['bundle_credit' => trans('journey.bundles.errors.expired')]);
        }
        if ($credit->plan_id !== $planId) {
            throw ValidationException::withMessages(['bundle_credit' => trans('journey.bundles.errors.plan', ['plan' => trans("app.wizard.tiers.{$credit->plan_id}")])]);
        }
        if ($this->reserved($credit)) {
            throw ValidationException::withMessages(['bundle_credit' => trans('journey.bundles.errors.reserved')]);
        }

        return $credit;
    }

    /**
     * The order paid: the credit is spent on its event.
     */
    public function markUsed(string $creditId, Order $order): void
    {
        BundleCredit::query()->whereKey($creditId)->whereNull('used_at')->update([
            'used_at' => now(),
            'used_event_id' => $order->event_id,
            'used_order_id' => $order->getKey(),
        ]);
    }

    /**
     * The order that used it was refunded: the credit can be used again (until it expires).
     */
    public function release(string $creditId, Order $order): void
    {
        BundleCredit::query()->whereKey($creditId)->where('used_order_id', $order->getKey())->update([
            'used_at' => null,
            'used_event_id' => null,
            'used_order_id' => null,
        ]);
    }

    /**
     * What one bundle costs in a currency, against the plans bought one by one.
     *
     * @return array{price: ?int, separately: ?int}
     */
    public function pricing(Bundle $bundle, string $currency): array
    {
        $separately = 0;
        foreach ($bundle->creditCounts() as $plan => $count) {
            $model = Plan::query()->find($plan);
            $price = $model ? $this->prices->amount($model, $currency) : null;
            if ($price === null) {
                $separately = null;
                break;
            }
            $separately += $price * $count;
        }

        return ['price' => $bundle->price($currency), 'separately' => $separately];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(BundleCredit $credit, string $locale): array
    {
        return [
            'id' => $credit->id,
            'plan' => $credit->plan_id,
            'plan_name' => trans("app.wizard.tiers.{$credit->plan_id}", [], $locale),
            'bundle' => $credit->bundle?->name($locale),
            'expires_at' => $credit->expires_at->toIso8601String(),
            'used_at' => $credit->used_at?->toIso8601String(),
            'used_event' => $credit->used_event_id ? Event::query()->whereKey($credit->used_event_id)->value('title') : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentBundle(Bundle $bundle, string $locale): array
    {
        return [
            'id' => $bundle->id,
            'name' => $bundle->name($locale),
            'description' => $bundle->description($locale),
            'credits' => collect($bundle->creditCounts())->map(fn (int $count, string $plan) => [
                'plan' => $plan,
                'count' => $count,
                'name' => trans("app.wizard.tiers.{$plan}", [], $locale),
            ])->values()->all(),
            'validity_days' => $bundle->validity_days,
            'prices' => collect(Currencies::all())->mapWithKeys(fn (string $c) => [$c => $this->pricing($bundle, $c)])->all(),
        ];
    }

    private function reserved(BundleCredit $credit): bool
    {
        return Order::query()->where('user_id', $credit->user_id)->pending()
            ->whereRaw('items @> ?::jsonb', [json_encode([['type' => 'bundle_credit', 'id' => $credit->id]])])
            ->exists();
    }
}
