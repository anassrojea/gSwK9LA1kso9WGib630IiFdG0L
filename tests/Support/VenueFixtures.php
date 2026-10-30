<?php

namespace Tests\Support;

use App\Models\Plan;
use Database\Seeders\PlanSeeder;

/**
 * Venue directory tests (phase 8B): the venue plans list the directory the
 * way phase 8A's org plans do (limits.directory_listing = true).
 */
class VenueFixtures
{
    public static function plans(): void
    {
        app(PlanSeeder::class)->run();
        foreach (['venue_starter', 'venue_pro', 'venue_business'] as $id) {
            $plan = Plan::query()->find($id);
            if ($plan) {
                $plan->update(['limits' => ['directory_listing' => true] + ($plan->limits ?? [])]);
            }
        }
    }
}
