<?php

namespace App\Services;

use App\Models\Event;
use App\Models\OpenLink;

/**
 * Host side of the open link (plan section 8): switch it on or off, set the
 * total people cap, and the numbers the wizard and the dashboard show.
 * Guest side: App\Http\Controllers\Invite\OpenLinkController.
 */
class OpenLinks
{
    public function __construct(private Entitlements $entitlements) {}

    public function available(Event $event): bool
    {
        return $this->entitlements->hasFeature($event, Entitlements::OPEN_LINK);
    }

    /**
     * The highest cap the host may set: the plan's guest limit, if any.
     */
    public function maxCap(Event $event): int
    {
        return min(OpenLink::MAX_CAP, $this->entitlements->limit($event, 'guests') ?? OpenLink::MAX_CAP);
    }

    /**
     * Turn the link on or off and/or change its cap. The token is created on
     * first use and kept when the link is switched off, so a link already
     * shared works again when it is switched back on.
     */
    public function configure(Event $event, ?bool $enabled, ?int $cap): OpenLink
    {
        $link = $event->openLink()->firstOrNew();
        $link->event()->associate($event);

        if ($cap !== null) {
            $link->cap = $cap;
        }
        $link->cap ??= min(50, $this->maxCap($event));

        if ($enabled !== null) {
            $link->enabled = $enabled;
        }

        $link->save();
        $event->setRelation('openLink', $link);

        return $link;
    }

    /**
     * JSON for the host: GET/PUT /events/{event}/open-link and the wizard's review step.
     *
     * @return array{available: bool, enabled: bool, cap: ?int, max_cap: int, max_per_reply: int, url: ?string,
     *     people: int, seats_left: ?int, replies: int, opens: int, first_opened_at: ?string, last_opened_at: ?string,
     *     published: bool, endpoint: string}
     */
    public function payload(Event $event): array
    {
        $link = $event->openLink()->first();

        return [
            'available' => $this->available($event),
            'enabled' => (bool) $link?->enabled,
            'cap' => $link?->cap,
            'max_cap' => $this->maxCap($event),
            'max_per_reply' => OpenLink::MAX_PER_REPLY,
            'url' => $link ? rtrim((string) config('app.invite_url'), '/').route('invite.show', $link->token, false) : null,
            'people' => $link ? $link->peopleCount() : 0,
            'seats_left' => $link?->seatsLeft(),
            'replies' => $link ? $link->households()->count() : 0,
            'opens' => (int) $link?->open_count,
            'first_opened_at' => $link?->first_opened_at?->toIso8601String(),
            'last_opened_at' => $link?->last_opened_at?->toIso8601String(),
            'published' => $event->invitation?->published_at !== null,
            'endpoint' => route('events.open-link.show', $event, false),
        ];
    }
}
