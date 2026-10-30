<?php

namespace Database\Seeders;

use App\Models\Addon;
use App\Models\Plan;
use App\Models\Price;
use App\Services\Entitlements as E;
use App\Services\Payments\PriceList;
use Illuminate\Database\Seeder;

/**
 * Plans and add-ons from plan section 13, with a price list per currency.
 *
 * USD prices are the plan's (price_cents). SAR and SYP prices are
 * PLACEHOLDERS (prices.placeholder = true, flagged in Filament) until the
 * owner sets real ones in Admin → Prices; re-seeding never overwrites a price
 * an admin saved. SYP amounts are whole pounds at roughly 12,000 SYP per USD
 * (2026, old pounds): check them against the redenominated pound before launch.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $premium = [E::RSVP, E::OPEN_LINK, E::PREMIUM_TEMPLATES, E::PERSONAL_LINKS, E::OPEN_TRACKING, E::REMINDERS, E::COVER_VIDEO, E::NO_BADGE, E::CUSTOM_SLUG];
        $signature = [...$premium, E::SEATING, E::QR_CHECKIN, E::WALLET, E::LIVE_DAY, E::PHOTO_WALL, E::OPENINGS_3D];

        $plans = [
            ['id' => 'free', 'name' => 'Free', 'price_cents' => 0, 'interval' => null,
                'limits' => ['guests' => 50, 'storage_days' => 0, 'features' => [E::RSVP, E::OPEN_LINK]]],
            ['id' => 'premium', 'name' => 'Premium', 'price_cents' => 2900, 'interval' => null,
                'limits' => ['guests' => 300, 'storage_days' => 30, 'features' => $premium]],
            ['id' => 'signature', 'name' => 'Signature', 'price_cents' => 7900, 'interval' => null,
                'limits' => ['guests' => 1000, 'storage_days' => 90, 'features' => $signature]],
            ['id' => 'venue_pro', 'name' => 'Venue & Planner Pro', 'price_cents' => 9900, 'interval' => 'month',
                'limits' => ['guests' => 1000, 'storage_days' => 90, 'signature_events_per_month' => 15, 'team_members' => 10,
                    'features' => [...$signature, 'white_label', 'hall_layouts']]],
        ];

        foreach ($plans as $plan) {
            Plan::query()->updateOrCreate(['id' => $plan['id']], $plan + ['currency' => 'USD', 'active' => true]);
        }

        $addons = [
            'template_unlock' => ['Single template unlock', 900],
            'seating' => ['Seating planner', 1500],
            'photo_cloud_90d' => ['Photo cloud · 90 days', 900],
            'photo_cloud_1y' => ['Photo cloud · 1 year', 2500],
            'photo_cloud_forever' => ['Photo cloud · forever', 4900],
            'guests_200' => ['Extra 200 guests', 1000],
            'qr_checkin' => ['QR check-in', 1000],
            'wallet' => ['Wallet pass for every guest', 500],
            'video_guestbook' => ['Video guestbook', 1200],
            'thank_you' => ['Thank-you card + highlight gallery', 700],
            'ai_poem' => ['AI poem', 500],
            // phase 8D (placeholder prices): save the date, highlight video, printed photo book (per copy)
            'save_the_date' => ['Save the date', 500],
            'highlight_video' => ['Highlight video', 1500],
            'photo_book_20' => ['Printed photo book · 20 pages', 4900],
            'photo_book_40' => ['Printed photo book · 40 pages', 7900],
        ];

        foreach ($addons as $id => [$name, $price]) {
            Addon::query()->updateOrCreate(['id' => $id], ['name' => $name, 'price_cents' => $price, 'currency' => 'USD', 'active' => true]);
        }

        $this->seedPrices();
    }

    /**
     * id => [SAR (halalas), SYP (whole pounds)]: placeholders, see the class comment.
     */
    public const PLACEHOLDER_PRICES = [
        'plan' => [
            'premium' => [10900, 350000],
            'signature' => [29900, 950000],
            'venue_pro' => [36900, 1200000],
        ],
        'addon' => [
            'template_unlock' => [3500, 110000],
            'seating' => [5500, 180000],
            'photo_cloud_90d' => [3500, 110000],
            'photo_cloud_1y' => [9500, 300000],
            'photo_cloud_forever' => [18500, 590000],
            'guests_200' => [3900, 120000],
            'qr_checkin' => [3900, 120000],
            'wallet' => [1900, 60000],
            'video_guestbook' => [4500, 145000],
            'thank_you' => [2500, 85000],
            'ai_poem' => [1900, 60000],
            'save_the_date' => [1900, 60000],
            'highlight_video' => [5500, 180000],
            'photo_book_20' => [18500, 590000],
            'photo_book_40' => [29900, 950000],
        ],
    ];

    private function seedPrices(): void
    {
        $prices = app(PriceList::class);

        foreach (['plan' => Plan::class, 'addon' => Addon::class] as $type => $model) {
            foreach ($model::query()->get() as $item) {
                if ($item->price_cents > 0) {
                    $this->seedPrice($prices, $item, 'USD', $item->price_cents, false);
                }
                if ($placeholder = self::PLACEHOLDER_PRICES[$type][$item->id] ?? null) {
                    $this->seedPrice($prices, $item, 'SAR', $placeholder[0], true);
                    $this->seedPrice($prices, $item, 'SYP', $placeholder[1], true);
                }
            }
        }
    }

    private function seedPrice(PriceList $prices, Plan|Addon $item, string $currency, int $amount, bool $placeholder): void
    {
        $existing = Price::query()->where(['priceable_type' => $item->getMorphClass(), 'priceable_id' => $item->id, 'currency' => $currency])->first();

        // an admin's price wins over the seed
        if ($existing && ! $existing->placeholder) {
            return;
        }

        $prices->set($item, $currency, $amount, $placeholder);
    }
}
