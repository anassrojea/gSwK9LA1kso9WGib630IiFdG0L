<?php

namespace App\Services\Account;

use App\Models\Event;
use App\Models\Plan;
use App\Services\Entitlements;
use App\Services\PublishGate;
use App\Services\Wizard\PlanAdvisor;

/**
 * What an event's plan includes and what upgrading would add, for the events
 * list, the event dashboard banner and Account → Billing. Read from the plans
 * table (limits.features) so it follows what the admin sells.
 */
class UpgradeHints
{
    /** the features worth naming to a host, in the order they are named */
    public const HIGHLIGHTS = [
        Entitlements::PERSONAL_LINKS, Entitlements::REMINDERS, Entitlements::OPEN_TRACKING, Entitlements::COVER_VIDEO,
        Entitlements::PREMIUM_TEMPLATES, Entitlements::NO_BADGE, Entitlements::CUSTOM_SLUG, Entitlements::SEATING,
        Entitlements::QR_CHECKIN, Entitlements::WALLET, Entitlements::LIVE_DAY, Entitlements::PHOTO_WALL, Entitlements::OPENINGS_3D,
        Entitlements::OPEN_LINK, Entitlements::RSVP,
    ];

    private const LADDER = ['free', 'premium', 'signature'];

    /** @var array<string, ?Plan> */
    private array $plans = [];

    public function __construct(private PublishGate $gate, private Entitlements $entitlements) {}

    /**
     * @return array{plan: string, can_upgrade: bool, next: ?string, unlocks: list<string>, missing: list<string>, guests: ?int, next_guests: ?int, features: list<string>}
     */
    public function for(Event $event, bool $withMissing = false): array
    {
        $plan = $this->gate->currentPlan($event);
        $next = $this->nextPlan($plan);
        $owned = $this->entitlements->features($event);

        $missing = [];
        if ($withMissing && $event->status !== 'draft') {
            $missing = $this->gate->check($event)['missing'];
        }

        return [
            'plan' => $plan,
            'can_upgrade' => $next !== null || $missing !== [],
            'next' => $next,
            'unlocks' => $next ? $this->ordered(array_diff($this->planFeatures($next), $owned)) : [],
            'missing' => $missing,
            'guests' => $this->entitlements->limit($event, 'guests'),
            'next_guests' => $next ? $this->guestLimit($next) : null,
            'features' => $this->ordered($owned),
        ];
    }

    public function nextPlan(string $plan): ?string
    {
        $index = array_search($plan, self::LADDER, true);
        if ($index === false) {
            // venue_pro and other custom plans sit at or above Signature
            return PlanAdvisor::tier($plan) < PlanAdvisor::tier('signature') ? 'signature' : null;
        }

        return self::LADDER[$index + 1] ?? null;
    }

    /**
     * @return list<string>
     */
    private function planFeatures(string $planId): array
    {
        return array_values($this->plan($planId)?->limits['features'] ?? []);
    }

    private function guestLimit(string $planId): ?int
    {
        $limit = $this->plan($planId)?->limits['guests'] ?? null;

        return $limit === null ? null : (int) $limit;
    }

    private function plan(string $id): ?Plan
    {
        return $this->plans[$id] ??= Plan::query()->find($id);
    }

    /**
     * @param  array<string>  $features
     * @return list<string>
     */
    private function ordered(array $features): array
    {
        return array_values(array_intersect(self::HIGHLIGHTS, $features));
    }
}
