<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Price;
use App\Services\Entitlements as E;
use App\Services\Payments\PriceList;
use Illuminate\Database\Seeder;

/**
 * Venue & Planner Pro tiers (plan section 13.1, phase 8A): $49 to $149 a
 * month, and yearly at ten months' price (two months free). One plans row
 * per tier and interval: venue_pro (month) and venue_pro_yearly (year).
 *
 * limits:
 *   signature_events_per_month  events the org can publish as Signature each month (App\Services\Org\OrgQuota)
 *   team_seats                  members including the owner, pending invitations count (App\Services\Org\OrgTeam)
 *   white_label                 org logo and name instead of the Daawa badge, brand color, sender name
 *   directory_listing           listed in the venue directory (phase 8B reads it)
 *   hall_layouts                saved hall layouts, null = unlimited
 *   features                    what the org's events get while the subscription is active (App\Services\Entitlements)
 *
 * USD prices are the plans' own; SAR and SYP rows are PLACEHOLDERS (prices.placeholder)
 * like PlanSeeder's, and re-seeding never overwrites a price an admin saved.
 * Runs after PlanSeeder (which created venue_pro first) and updates it.
 */
class OrgPlanSeeder extends Seeder
{
    /** tier => [USD cents a month, SAR halalas a month, SYP a month, limits] */
    public const TIERS = [
        'venue_starter' => [4900, 18900, 600000, ['signature_events_per_month' => 15, 'team_seats' => 3, 'white_label' => false, 'directory_listing' => true, 'hall_layouts' => 2]],
        'venue_pro' => [9900, 36900, 1200000, ['signature_events_per_month' => 30, 'team_seats' => 10, 'white_label' => true, 'directory_listing' => true, 'hall_layouts' => 10]],
        'venue_business' => [14900, 55900, 1800000, ['signature_events_per_month' => 75, 'team_seats' => 25, 'white_label' => true, 'directory_listing' => true, 'hall_layouts' => null]],
    ];

    public const NAMES = [
        'venue_starter' => 'Venue Starter',
        'venue_pro' => 'Venue & Planner Pro',
        'venue_business' => 'Venue Business',
    ];

    /** A year costs this many months. */
    public const YEAR_MONTHS = 10;

    public function run(): void
    {
        $signature = [E::RSVP, E::OPEN_LINK, E::PREMIUM_TEMPLATES, E::PERSONAL_LINKS, E::OPEN_TRACKING, E::REMINDERS, E::COVER_VIDEO,
            E::NO_BADGE, E::CUSTOM_SLUG, E::SEATING, E::QR_CHECKIN, E::WALLET, E::LIVE_DAY, E::PHOTO_WALL, E::OPENINGS_3D];
        $prices = app(PriceList::class);

        foreach (self::TIERS as $tier => [$usd, $sar, $syp, $limits]) {
            $orgFeatures = array_keys(array_filter([
                'white_label' => $limits['white_label'],
                'directory_listing' => $limits['directory_listing'],
                'hall_layouts' => true,
            ]));
            $allLimits = ['guests' => 1000, 'storage_days' => 90, ...$limits, 'features' => [...$signature, ...$orgFeatures]];

            foreach (['month' => 1, 'year' => self::YEAR_MONTHS] as $interval => $months) {
                $id = $interval === 'month' ? $tier : $tier.config('org.yearly_suffix', '_yearly');
                $plan = Plan::query()->updateOrCreate(['id' => $id], [
                    'name' => self::NAMES[$tier].($interval === 'year' ? ' (yearly)' : ''),
                    'price_cents' => $usd * $months,
                    'currency' => 'USD',
                    'interval' => $interval,
                    'limits' => $allLimits,
                    'active' => true,
                ]);

                $this->price($prices, $plan, 'USD', $usd * $months, false);
                $this->price($prices, $plan, 'SAR', $sar * $months, true);
                $this->price($prices, $plan, 'SYP', $syp * $months, true);
            }
        }
    }

    private function price(PriceList $prices, Plan $plan, string $currency, int $amount, bool $placeholder): void
    {
        $existing = Price::query()->where(['priceable_type' => $plan->getMorphClass(), 'priceable_id' => $plan->id, 'currency' => $currency])->first();

        // an admin's price wins over the seed
        if ($existing && ! $existing->placeholder) {
            return;
        }

        $prices->set($plan, $currency, $amount, $placeholder);
    }
}
