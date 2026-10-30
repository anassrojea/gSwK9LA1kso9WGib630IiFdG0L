<?php

namespace App\Services\Wizard;

use App\Models\Event;
use App\Models\Opening;
use App\Models\OpenLink;
use App\Models\Plan;
use App\Models\Theme;
use App\Services\Marketplace\TemplateAccess;

/**
 * The plan an invitation needs, shown before the last step so the price is
 * never a surprise (plan section 3), and checked again when publishing
 * (App\Services\PublishGate). Free (plan section 13.1): free openings and
 * themes, up to 50 guests, the open link only; personal links, a cover video
 * or Premium designs need Premium.
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

        // a paid marketplace template (phase 8C) is bought on its own, not through a plan tier,
        // and that purchase covers its own opening and theme
        $marketplace = app(TemplateAccess::class)->inUse($event, $config);
        $bought = $marketplace?->isPaidMarketplace() ? $marketplace : null;

        if (! $bought || ($config['opening']['id'] ?? null) !== $bought->opening_id) {
            $raise(Opening::query()->whereKey($config['opening']['id'])->value('tier') ?? 'free', 'opening');
        }
        if (! $bought || ($config['theme']['id'] ?? null) !== $bought->theme_id) {
            $raise(Theme::query()->whereKey($config['theme']['id'])->value('tier') ?? 'free', 'theme');
        }
        if ($bought) {
            $reasons[] = 'template';
        }

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
