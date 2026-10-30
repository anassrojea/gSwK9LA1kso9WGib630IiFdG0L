<?php

namespace Tests\Support;

use App\Models\Event;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\OrgMember;
use App\Models\OrgSubscription;
use App\Models\User;
use App\Services\Org\OrgSubscriptions;
use Carbon\CarbonImmutable;
use Database\Factories\InvitationFactory;
use Database\Seeders\OrgPlanSeeder;

/**
 * Venue & Planner Pro test helpers (phase 8A). Payments::setUp() first (plans,
 * prices, gateways), then the org tiers from OrgPlanSeeder.
 */
class OrgFixtures
{
    public static function setUp(): void
    {
        Payments::setUp();
        test()->seed(OrgPlanSeeder::class);
        config([
            'org.lemon_squeezy.product_id' => '900',
            'org.lemon_squeezy.variants.venue_pro' => '901',
            'org.lemon_squeezy.variants.venue_pro_yearly' => '902',
            'org.lemon_squeezy.variants.venue_business' => '903',
            'org.stripe.prices.venue_pro.USD' => 'price_venue_pro_usd',
            'org.stripe.prices.venue_business.USD' => 'price_venue_business_usd',
        ]);
    }

    /**
     * An organization with its owner (and optional staff / door members).
     *
     * @return array{0: Organization, 1: User}
     */
    public static function org(array $attributes = [], ?User $owner = null): array
    {
        $owner ??= User::factory()->create();
        $org = Organization::factory()->venue()->create($attributes);
        OrgMember::query()->create(['org_id' => $org->id, 'user_id' => $owner->id, 'role' => OrgMember::ROLE_OWNER]);

        return [$org, $owner];
    }

    public static function member(Organization $org, string $role = OrgMember::ROLE_STAFF, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        OrgMember::query()->create(['org_id' => $org->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    /**
     * A live subscription, and the org's plan set from it.
     */
    public static function subscribe(Organization $org, string $plan = 'venue_pro', string $provider = 'transfer', array $attributes = []): OrgSubscription
    {
        $start = CarbonImmutable::now()->subDays(3);
        $subscription = OrgSubscription::query()->forceCreate($attributes + [
            'org_id' => $org->id,
            'user_id' => $org->owner()?->id,
            'plan_id' => $plan,
            'interval' => str_ends_with($plan, '_yearly') ? 'year' : 'month',
            'provider' => $provider,
            'provider_id' => $provider === 'transfer' ? null : ($provider === 'stripe' ? 'sub_test_'.substr(md5($org->id), 0, 10) : '7'.random_int(1000, 9999)),
            'currency' => 'SAR',
            'amount_cents' => 36900,
            'status' => 'active',
            'current_period_start' => $start,
            'current_period_end' => $start->addMonthNoOverflow(),
        ]);
        app(OrgSubscriptions::class)->applyOrgPlan($org);

        return $subscription;
    }

    /**
     * A draft org event with an unpublished invitation in a Signature design.
     */
    public static function event(Organization $org, User $owner, array $attributes = []): Event
    {
        $event = Event::factory()->draft()->create($attributes + ['owner_id' => $owner->id, 'org_id' => $org->id, 'plan_id' => null]);
        Invitation::factory()->for($event)->create(['config' => InvitationFactory::sampleConfig('layali-envelope'), 'published_at' => null]);

        return $event->fresh();
    }
}
