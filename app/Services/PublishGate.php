<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Opening;
use App\Models\Order;
use App\Models\Theme;
use App\Services\Wizard\PlanAdvisor;

/**
 * Can this invitation be published and sent? Yes when the event's plan (and
 * add-ons) cover everything PlanAdvisor says the invitation uses:
 *
 *   opening / theme   their tier is within the plan (or a template_unlock add-on for a Premium design)
 *   cover_video       Entitlements::COVER_VIDEO
 *   personal_links    Entitlements::PERSONAL_LINKS (households invited by name)
 *   guests            the seats fit the event's guest limit (plan + extra guest packs)
 *
 * A Free invitation (free design, open link only, up to 50 guests) passes
 * without paying. Sending personal links, their WhatsApp buttons and
 * reminders after publishing are checked with canSendPersonalLinks / canRemind.
 */
class PublishGate
{
    public const TEMPLATE_UNLOCK = 'template_unlock';

    public function __construct(private PlanAdvisor $advisor, private Entitlements $entitlements) {}

    /**
     * @return array{allowed: bool, required: string, current: string, reasons: list<string>, missing: list<string>, pending_order: ?string}
     */
    public function check(Event $event, ?array $config = null): array
    {
        $config ??= $event->invitation?->config ?? [];
        $advice = $this->advisor->advise($event, $config);
        $current = $this->currentPlan($event);

        $missing = array_values(array_filter(
            $advice['reasons'],
            fn (string $reason) => ! $this->covers($event, $reason, $config, $current),
        ));

        return [
            'allowed' => $missing === [],
            'required' => $advice['tier'],
            'current' => $current,
            'reasons' => $advice['reasons'],
            'missing' => $missing,
            'pending_order' => Order::query()->where('event_id', $event->getKey())->pending()->latest()->value('code'),
        ];
    }

    public function allows(Event $event): bool
    {
        return $this->check($event)['allowed'];
    }

    public function canSendPersonalLinks(Event $event): bool
    {
        return $this->entitlements->hasFeature($event, Entitlements::PERSONAL_LINKS);
    }

    /**
     * The personal WhatsApp preview image ("دعوة خاصة لعائلة …") is Premium (plan section 5),
     * like personal links: Free events share the generic card (App\Services\OgImage\OgImage).
     */
    public function canShowPersonalPreview(Event $event): bool
    {
        return $this->entitlements->hasFeature($event, Entitlements::PERSONAL_LINKS);
    }

    public function canRemind(Event $event): bool
    {
        return $this->entitlements->hasFeature($event, Entitlements::REMINDERS);
    }

    /**
     * The plan the event has now: its own, its organization's, else free.
     */
    public function currentPlan(Event $event): string
    {
        return $event->plan_id ?? $event->organization?->plan_id ?? Entitlements::FALLBACK_PLAN;
    }

    private function covers(Event $event, string $reason, array $config, string $plan): bool
    {
        return match ($reason) {
            'opening' => $this->designCovered($event, Opening::query()->whereKey($config['opening']['id'] ?? null)->value('tier') ?? 'free', $plan),
            'theme' => $this->designCovered($event, Theme::query()->whereKey($config['theme']['id'] ?? null)->value('tier') ?? 'free', $plan),
            'cover_video' => $this->entitlements->hasFeature($event, Entitlements::COVER_VIDEO),
            'personal_links' => $this->entitlements->hasFeature($event, Entitlements::PERSONAL_LINKS),
            'guests' => ($limit = $this->entitlements->limit($event, 'guests')) === null
                || (int) $event->households()->sum('seats_allowed') <= $limit,
            default => false,
        };
    }

    private function designCovered(Event $event, string $tier, string $plan): bool
    {
        if (PlanAdvisor::tier($tier) <= PlanAdvisor::tier($plan)) {
            return true;
        }

        // "Single template unlock" (plan section 13.2): a Premium design on a Free event
        return $tier === 'premium' && $this->entitlements->hasFeature($event, self::TEMPLATE_UNLOCK);
    }
}
