<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([PlanSeeder::class, OrgPlanSeeder::class, CatalogSeeder::class, HallTemplateSeeder::class, JourneySeeder::class]);

        // Demo accounts have a known password: keep them out of production unless asked for.
        if (! app()->isProduction() || env('SEED_DEMO', false)) {
            $this->call([DemoSeeder::class, EventDayDemoSeeder::class, VenueProDemoSeeder::class, MarketplaceDemoSeeder::class, VenueDirectoryDemoSeeder::class, JourneyDemoSeeder::class]);
        }
    }
}
