<?php

namespace App\Services\Org;

use App\Models\Organization;
use App\Models\Plan;
use App\Services\Payments\Currencies;
use App\Services\Payments\PriceList;

/**
 * The Venue & Planner Pro tiers (plan section 13.1): which plans rows are
 * organization plans, their tier and interval, and the limits an
 * organization has now (its plan_id, kept in sync with its live subscription
 * by OrgSubscriptions). Rows: venue_starter, venue_pro, venue_business
 * (monthly) and the same with config('org.yearly_suffix') (yearly).
 */
class OrgPlans
{
    public const INTERVALS = ['month', 'year'];

    public function __construct(private PriceList $prices) {}

    /**
     * @return list<string>
     */
    public static function tiers(): array
    {
        return array_values(config('org.tiers', []));
    }

    /**
     * Every plans id that is an organization plan (monthly and yearly rows).
     *
     * @return list<string>
     */
    public static function planIds(): array
    {
        return collect(static::tiers())->flatMap(fn (string $tier) => [$tier, static::planId($tier, 'year')])->values()->all();
    }

    public static function isOrgPlan(?string $planId): bool
    {
        return $planId !== null && in_array($planId, static::planIds(), true);
    }

    public static function planId(string $tier, string $interval): string
    {
        return $interval === 'year' ? $tier.config('org.yearly_suffix', '_yearly') : $tier;
    }

    public static function tierOf(string $planId): string
    {
        $suffix = (string) config('org.yearly_suffix', '_yearly');

        return str_ends_with($planId, $suffix) ? substr($planId, 0, -strlen($suffix)) : $planId;
    }

    public static function intervalOf(string $planId): string
    {
        return static::tierOf($planId) === $planId ? 'month' : 'year';
    }

    /**
     * "Venue Pro · monthly": a subscription line on orders and invoices.
     */
    public static function lineName(string $planId, string $locale): string
    {
        // a middle dot reads like a zero (٠) in Arabic: a comma there
        return trans('org.plans.'.static::tierOf($planId).'.name', [], $locale)
            .(str_starts_with($locale, 'ar') ? '، ' : ' · ')
            .trans('org.billing.intervals.'.static::intervalOf($planId), [], $locale);
    }

    /** Rank of a tier: 0 for the cheapest. */
    public static function rank(string $planId): int
    {
        $index = array_search(static::tierOf($planId), static::tiers(), true);

        return $index === false ? -1 : (int) $index;
    }

    /**
     * The organization's limits now: its plan's, or the free defaults without one.
     *
     * @return array{signature_events_per_month: int, team_seats: int, white_label: bool, directory_listing: bool, hall_layouts: ?int}
     */
    public function limits(Organization $org): array
    {
        $plan = $org->plan_id ? ($org->relationLoaded('plan') && $org->plan?->id === $org->plan_id ? $org->plan : Plan::query()->find($org->plan_id)) : null;
        $limits = $plan?->limits ?? [];

        return [
            'signature_events_per_month' => (int) ($limits['signature_events_per_month'] ?? 0),
            'team_seats' => (int) ($limits['team_seats'] ?? $limits['team_members'] ?? config('org.free_seats', 2)),
            'white_label' => (bool) ($limits['white_label'] ?? in_array('white_label', $limits['features'] ?? [], true)),
            'directory_listing' => (bool) ($limits['directory_listing'] ?? false),
            'hall_layouts' => array_key_exists('hall_layouts', $limits) ? ($limits['hall_layouts'] === null ? null : (int) $limits['hall_layouts']) : ($plan ? null : 1),
        ];
    }

    /**
     * A yes/no feature of the organization's plan: white_label, directory_listing.
     */
    public function allows(Organization $org, string $feature): bool
    {
        return (bool) ($this->limits($org)[$feature] ?? false);
    }

    /**
     * The tiers for the billing page: limits and prices per interval in a currency.
     *
     * @return list<array<string, mixed>>
     */
    public function catalog(string $currency, string $locale): array
    {
        $plans = Plan::query()->with('prices')->whereIn('id', static::planIds())->where('active', true)->get()->keyBy('id');

        return collect(static::tiers())
            ->filter(fn (string $tier) => $plans->has($tier))
            ->map(function (string $tier) use ($plans, $currency, $locale) {
                $monthly = $plans[$tier];
                $yearly = $plans[static::planId($tier, 'year')] ?? null;
                $price = function (?Plan $plan) use ($currency, $locale) {
                    $amount = $plan ? $this->prices->amount($plan, $currency) : null;

                    return $plan && $amount !== null ? [
                        'plan_id' => $plan->id,
                        'amount' => $amount,
                        'formatted' => Currencies::format($amount, $currency, $locale),
                        'placeholder' => (bool) $plan->prices->firstWhere('currency', $currency)?->placeholder,
                    ] : null;
                };
                $limits = $monthly->limits ?? [];

                return [
                    'tier' => $tier,
                    'month' => $price($monthly),
                    'year' => $price($yearly),
                    'limits' => [
                        'signature_events_per_month' => (int) ($limits['signature_events_per_month'] ?? 0),
                        'team_seats' => (int) ($limits['team_seats'] ?? 0),
                        'white_label' => (bool) ($limits['white_label'] ?? false),
                        'directory_listing' => (bool) ($limits['directory_listing'] ?? false),
                        'hall_layouts' => $limits['hall_layouts'] ?? null,
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * A plan row's price in a currency (minor units), or null when it has none.
     */
    public function price(string $planId, string $currency): ?int
    {
        $plan = Plan::query()->find($planId);

        return $plan ? $this->prices->amount($plan, $currency) : null;
    }
}
