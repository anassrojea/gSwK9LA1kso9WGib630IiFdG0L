<?php

namespace App\Services\Venues;

use App\Models\Entitlement;
use App\Models\Organization;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Builder;

/**
 * Organization-level features for venues (phase 8B). The directory listing
 * follows the org plans of phase 8A: organizations.plan_id holds the plan
 * while the subscription is trialing, active or past_due (null otherwise),
 * and venue_starter / venue_pro / venue_business set
 * limits.directory_listing = true. An org-level entitlement
 * (entitlements.org_id, no event_id, feature directory_listing, not expired)
 * grants it too, e.g. for a launch partner. Without a plan the listing is
 * simply not public; the profile stays editable.
 *
 * limits.features containing "directory_listing" is also accepted, for plans
 * written before 8A.
 */
class VenueFeatures
{
    public const DIRECTORY_LISTING = 'directory_listing';

    public function has(Organization $org, string $feature): bool
    {
        if ($org->plan_id && static::planHas(Plan::query()->find($org->plan_id), $feature)) {
            return true;
        }

        return Entitlement::query()
            ->where('org_id', $org->getKey())
            ->whereNull('event_id')
            ->where('feature', $feature)
            ->where(fn ($q) => $q->whereNull('until')->orWhere('until', '>', now()))
            ->exists();
    }

    public static function planHas(?Plan $plan, string $feature): bool
    {
        if (! $plan) {
            return false;
        }
        $limits = $plan->limits ?? [];

        return ($limits[$feature] ?? false) === true || in_array($feature, $limits['features'] ?? [], true);
    }

    /**
     * Organizations that have the feature, for the public listing query.
     *
     * @return Builder<Organization>
     */
    public function organizationsWith(string $feature): Builder
    {
        $plans = Plan::query()->get()->filter(fn (Plan $p) => static::planHas($p, $feature))->modelKeys();

        return Organization::query()->where(function ($q) use ($feature, $plans) {
            $q->whereIn('plan_id', $plans)
                ->orWhereHas('entitlements', fn ($e) => $e->whereNull('event_id')->where('feature', $feature)
                    ->where(fn ($u) => $u->whereNull('until')->orWhere('until', '>', now())));
        });
    }
}
