<?php

namespace App\Services;

use App\Models\Entitlement;
use App\Models\Event;
use App\Models\Plan;
use Illuminate\Support\Collection;

/**
 * The one place that decides what is unlocked for an event (plan section 16):
 * features included in the event's plan (or its organization's plan), plus
 * add-ons and grants recorded in the entitlements table.
 */
class Entitlements
{
    public const RSVP = 'rsvp';

    public const PREMIUM_TEMPLATES = 'premium_templates';

    public const PERSONAL_LINKS = 'personal_links';

    public const OPEN_TRACKING = 'open_tracking';

    public const REMINDERS = 'reminders';

    public const COVER_VIDEO = 'cover_video';

    public const NO_BADGE = 'no_badge';

    public const CUSTOM_SLUG = 'custom_slug';

    public const SEATING = 'seating';

    public const QR_CHECKIN = 'qr_checkin';

    public const WALLET = 'wallet';

    public const PHOTO_WALL = 'photo_wall';

    public const LIVE_DAY = 'live_day';

    public const OPENINGS_3D = 'openings_3d';

    public const FEATURES = [
        self::RSVP, self::PREMIUM_TEMPLATES, self::PERSONAL_LINKS, self::OPEN_TRACKING, self::REMINDERS,
        self::COVER_VIDEO, self::NO_BADGE, self::CUSTOM_SLUG, self::SEATING, self::QR_CHECKIN, self::WALLET,
        self::PHOTO_WALL, self::LIVE_DAY, self::OPENINGS_3D,
    ];

    /** Applies when an event has no plan at all. */
    public const FALLBACK_PLAN = 'free';

    /** @var array<string, Collection<int, string>> per-request cache keyed by event id */
    private array $granted = [];

    public function hasFeature(Event $event, string $feature): bool
    {
        if (in_array($feature, $this->planFeatures($event), true)) {
            return true;
        }

        return $this->grantedFeatures($event)->contains($feature);
    }

    /**
     * @return list<string>
     */
    public function features(Event $event): array
    {
        return collect($this->planFeatures($event))
            ->merge($this->grantedFeatures($event))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * A numeric plan limit (e.g. "guests"), or null when unlimited / unknown.
     */
    public function limit(Event $event, string $key): ?int
    {
        if ($key === 'guests' && $event->guest_limit !== null) {
            return $event->guest_limit;
        }

        $value = $this->plan($event)?->limits[$key] ?? null;

        return $value === null ? null : (int) $value;
    }

    /**
     * Soft limit check on write: can $adding more guests (people) be invited?
     */
    public function canAddGuests(Event $event, int $adding = 1): bool
    {
        $limit = $this->limit($event, 'guests');

        if ($limit === null) {
            return true;
        }

        return $event->households()->sum('seats_allowed') + $adding <= $limit;
    }

    public function forget(?Event $event = null): void
    {
        if ($event) {
            unset($this->granted[$event->getKey()]);
        } else {
            $this->granted = [];
        }
    }

    private function plan(Event $event): ?Plan
    {
        $planId = $event->plan_id ?? $event->organization?->plan_id ?? self::FALLBACK_PLAN;

        return $event->relationLoaded('plan') && $event->plan?->id === $planId
            ? $event->plan
            : Plan::query()->find($planId);
    }

    /**
     * @return list<string>
     */
    private function planFeatures(Event $event): array
    {
        $plan = $this->plan($event);

        // An inactive plan is no longer sold, but events bought on it keep its features.
        return array_values($plan?->limits['features'] ?? []);
    }

    /**
     * @return Collection<int, string>
     */
    private function grantedFeatures(Event $event): Collection
    {
        return $this->granted[$event->getKey()] ??= Entitlement::query()
            ->where(function ($query) use ($event) {
                $query->where('event_id', $event->getKey());
                if ($event->org_id) {
                    $query->orWhere(fn ($q) => $q->whereNull('event_id')->where('org_id', $event->org_id));
                }
            })
            ->where(fn ($q) => $q->whereNull('until')->orWhere('until', '>', now()))
            ->pluck('feature')
            ->unique()
            ->values();
    }
}
