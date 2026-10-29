<?php

namespace App\Services\Wizard;

use App\Models\Event;
use App\Models\Opening;
use App\Models\Plan;
use App\Models\Theme;

/**
 * The plan an invitation needs, shown before the last step so the price is
 * never a surprise (plan section 3). Checkout itself comes with payments.
 */
class PlanAdvisor
{
    public const TIERS = ['free' => 0, 'premium' => 1, 'signature' => 2];

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

        $raise(Opening::query()->whereKey($config['opening']['id'])->value('tier') ?? 'free', 'opening');
        $raise(Theme::query()->whereKey($config['theme']['id'])->value('tier') ?? 'free', 'theme');

        if (($config['media']['cover']['type'] ?? null) === 'video') {
            $raise('premium', 'cover_video');
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
