<?php

use App\Models\Plan;
use App\Services\Entitlements;
use Illuminate\Database\Migrations\Migration;

/**
 * The open link is included in every plan, free too (plan section 5). Plans
 * already in the database get the feature here; PlanSeeder lists it for new ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Plan::query()->each(function (Plan $plan) {
            $limits = $plan->limits ?? [];
            $features = $limits['features'] ?? [];
            if (! in_array(Entitlements::OPEN_LINK, $features, true)) {
                $limits['features'] = [...$features, Entitlements::OPEN_LINK];
                $plan->limits = $limits;
                $plan->save();
            }
        });
    }

    public function down(): void
    {
        Plan::query()->each(function (Plan $plan) {
            $limits = $plan->limits ?? [];
            $limits['features'] = array_values(array_diff($limits['features'] ?? [], [Entitlements::OPEN_LINK]));
            $plan->limits = $limits;
            $plan->save();
        });
    }
};
