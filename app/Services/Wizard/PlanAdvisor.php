<?php

namespace App\Services\Wizard;

use App\Models\Event;
use App\Models\Opening;
use App\Models\OpenLink;
use App\Models\Plan;
use App\Models\Theme;
use App\Services\Journey\SaveTheDate;

/**
 * The plan an invitation needs, shown before the last step so the price is
 * never a surprise (plan section 3), and checked again when publishing
 * (App\Services\PublishGate). Free (plan section 13.1): free openings and
 * themes, up to 50 guests, the open link only; personal links, a cover video
 * or Premium designs need Premium. A save-the-date (config kind
 * "save_the_date", phase 8D) is its own product: it needs the save_the_date
 * add-on and no plan, and may not carry an RSVP.
 */
class PlanAdvisor
{
    public const TIERS = ['free' => 0, 'premium' => 1, 'signature' => 2];

    /** Plans outside the per-event ladder, ranked by what they include. */
    public const PLAN_TIERS = ['venue_pro' => 2];

    /**
     * Rank of a plan or design tier (no plan: free).
     */
    public static function tier(?string $id): int
    {
        return self::TIERS[$id ?? 'free'] ?? self::PLAN_TIERS[$id] ?? 0;
    }

    /**
     * @return array{plan: ?Plan, tier: string, reasons: list<string>}
     */
    public function advise(Event $event, array $config): array
    {
        if (SaveTheDate::is($config)) {
            return ['plan' => null, 'tier' => 'free', 'reasons' => ['save_the_date', ...SaveTheDate::problems($config)]];
        }

        $reasons = [];
        $tier = 'free';
        $raise = function (string $to, string $reason) use (&$tier, &$reasons) {
            if ((self::TIERS[$to] ?? 0) > self::TIERS[$tier]) {
                $tier = $to;
            }
            if ((self::TIERS[$to] ?? 0) > 0) {
                $reasons[] = $reason;
            }
        };

        $raise(Opening::query()->whereKey($config['opening']['id'])->value('tier') ?? 'free', 'opening');
        $raise(Theme::query()->whereKey($config['theme']['id'])->value('tier') ?? 'free', 'theme');

        if (($config['media']['cover']['type'] ?? null) === 'video') {
            $raise('premium', 'cover_video');
        }

        // families invited by name get personal links (Premium); the open link is on every plan
        if ($event->households()->whereRaw('not (? = any(tags))', [OpenLink::TAG])->exists()) {
            $raise('premium', 'personal_links');
        }

        $seats = (int) $event->households()->sum('seats_allowed');
        foreach (['free', 'premium', 'signature'] as $candidate) {
            $limit = Plan::query()->find($candidate)?->limits['guests'] ?? null;
            if ($limit === null || $seats <= $limit) {
                $raise($candidate, 'guests');
                break;
            }
        }

        return ['plan' => Plan::query()->find($tier), 'tier' => $tier, 'reasons' => array_values(array_unique($reasons))];
    }
}
