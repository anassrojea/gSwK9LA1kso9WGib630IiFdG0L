<?php

namespace App\Services\Payments;

use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\User;
use App\Services\Digits;
use App\Services\Marketplace\MarketplaceOrders;
use App\Services\Payments\Items\OrderItemHandlers;
use Illuminate\Validation\ValidationException;

/**
 * Discount codes at checkout (plan section 4.3). Twin of resources/js/lib/discount.ts,
 * which only mirrors these rules for the display:
 *
 *   - a code is found ignoring case; it must be active and inside starts_at/ends_at;
 *   - uses are the code's orders that are paid or approved (OrderFulfillment
 *     sets those statuses), so an order that expires, is rejected or refunded
 *     doesn't use it up; max_uses counts every customer, max_uses_per_user one;
 *   - it applies to the order's plan line and/or add-on lines (optionally only
 *     some plans / add-ons): a percent of those lines, or a fixed amount in the
 *     currency the order is charged in (after the USD fallback) — a fixed code
 *     without an amount in that currency doesn't apply;
 *   - the discount never exceeds the lines it applies to, so a total is never
 *     negative; a 100% code makes a zero total, fulfilled without a gateway.
 */
class Discounts
{
    public function find(?string $code): ?DiscountCode
    {
        $code = DiscountCode::normalize($code);

        return $code === '' ? null : DiscountCode::query()->whereRaw('lower(code) = lower(?)', [$code])->first();
    }

    /**
     * The code a customer typed, if they may use it now.
     *
     * @throws ValidationException
     */
    public function usable(?string $typed, User $user, ?Order $except = null): DiscountCode
    {
        $code = $this->find($typed);

        if (! $code) {
            throw $this->error('discount_unknown');
        }
        if (! $code->active || ($code->ends_at && $code->ends_at->isPast())) {
            throw $this->error('discount_expired');
        }
        if ($code->starts_at && $code->starts_at->isFuture()) {
            throw $this->error('discount_not_started');
        }
        if ($code->max_uses !== null && $this->uses($code, except: $except) >= $code->max_uses) {
            throw $this->error('discount_used_up');
        }
        if ($code->max_uses_per_user !== null && $this->uses($code, $user, $except) >= $code->max_uses_per_user) {
            throw $this->error('discount_used_by_you');
        }

        return $code;
    }

    /**
     * Paid or approved orders carrying the code (of one customer).
     */
    public function uses(DiscountCode $code, ?User $user = null, ?Order $except = null): int
    {
        return Order::query()
            ->where('discount_code_id', $code->getKey())
            ->fulfilled()
            ->when($user, fn ($q) => $q->where('user_id', $user->getKey()))
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->count();
    }

    /**
     * The discount on priced lines (CheckoutService::lines), in minor units of $currency.
     *
     * @param  list<array{type: string, id: string, amount: int}>  $lines
     *
     * @throws ValidationException when the code covers none of the lines, or has no amount in $currency
     */
    public function amount(DiscountCode $code, array $lines, string $currency): int
    {
        $eligible = array_sum(array_map(
            fn (array $line) => (int) $line['amount'],
            array_filter($lines, fn (array $line) => $this->covers($code, $line['type'], $line['id'])),
        ));

        if ($eligible <= 0) {
            throw $this->error('discount_items');
        }

        if ($code->isPercent()) {
            $discount = intdiv($eligible * $code->percent, 100);
        } else {
            $fixed = $code->amounts[$currency] ?? null;
            if ($fixed === null) {
                throw $this->error('discount_currency', ['currency' => trans("app.checkout.currencies.{$currency}")]);
            }
            $discount = (int) $fixed;
        }

        return max(0, min($discount, $eligible));
    }

    public function covers(DiscountCode $code, string $type, string $id): bool
    {
        return match ($type) {
            'plan' => $code->applies_to_plans && ($code->plan_ids === null || $code->plan_ids === [] || in_array($id, $code->plan_ids, true)),
            'addon' => $code->applies_to_addons && ($code->addon_ids === null || $code->addon_ids === [] || in_array($id, $code->addon_ids, true)),
            // phase 8C marketplace items decide for themselves (template purchases: codes for every add-on);
            // phase 8D products sold like add-ons (thank_you, highlight_video, …) are covered as add-ons.
            // Each answers false for types it doesn't own.
            default => app(MarketplaceOrders::class)->discountable($code, $type, $id)
                || (app(OrderItemHandlers::class)->discountable($type) && $this->covers($code, 'addon', $id)),
        };
    }

    /**
     * What the checkout UI needs to show the discount (POST /checkout/discount).
     *
     * @return array<string, mixed>
     */
    public function present(DiscountCode $code): array
    {
        return [
            'code' => $code->code,
            'percent' => $code->percent,
            'amounts' => $code->isPercent() ? null : (object) array_map('intval', $code->amounts ?? []),
            // false: never; true: every one; a list: only those ids
            'plans' => $code->applies_to_plans ? ($code->plan_ids ?: true) : false,
            'addons' => $code->applies_to_addons ? ($code->addon_ids ?: true) : false,
        ];
    }

    /**
     * "20%" or "SAR 20 · USD 5" for the admin.
     */
    public static function label(DiscountCode $code, string $locale): string
    {
        if ($code->isPercent()) {
            return $locale === 'ar' ? Digits::localize($code->percent, 'ar').'٪' : $code->percent.'%';
        }

        return collect($code->amounts ?? [])
            ->map(fn ($amount, $currency) => Currencies::format((int) $amount, $currency, $locale))
            ->implode(' · ');
    }

    private function error(string $key, array $replace = []): ValidationException
    {
        return ValidationException::withMessages(['discount_code' => trans("app.checkout.errors.{$key}", $replace)]);
    }
}
