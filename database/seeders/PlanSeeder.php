<?php

namespace Database\Seeders;

use App\Models\Addon;
use App\Models\Plan;
use App\Services\Entitlements as E;
use Illuminate\Database\Seeder;

/**
 * Plans and add-ons from plan section 13 (USD; localize before launch).
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $premium = [E::RSVP, E::PREMIUM_TEMPLATES, E::PERSONAL_LINKS, E::OPEN_TRACKING, E::REMINDERS, E::COVER_VIDEO, E::NO_BADGE, E::CUSTOM_SLUG];
        $signature = [...$premium, E::SEATING, E::QR_CHECKIN, E::WALLET, E::LIVE_DAY, E::PHOTO_WALL, E::OPENINGS_3D];

        $plans = [
            ['id' => 'free', 'name' => 'Free', 'price_cents' => 0, 'interval' => null,
                'limits' => ['guests' => 50, 'storage_days' => 0, 'features' => [E::RSVP]]],
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
        ];

        foreach ($addons as $id => [$name, $price]) {
            Addon::query()->updateOrCreate(['id' => $id], ['name' => $name, 'price_cents' => $price, 'currency' => 'USD', 'active' => true]);
        }
    }
}
