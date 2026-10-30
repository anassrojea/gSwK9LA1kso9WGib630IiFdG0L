<?php

namespace App\Services\Org;

use App\Models\Event;
use App\Models\Organization;

/**
 * White label (plan sections 13.1 and 14.8) for organizations whose plan
 * has it: the org's logo and name in its events' invitation footer instead
 * of the Daawa badge, a brand color for its dashboard header, and its own
 * sender name on emails to its clients.
 *
 * Read at render time from the event's organization, so guest pages stay
 * edge-cacheable: changing the org's name, logo, colors or plan purges its
 * events' pages (App\Observers\EdgeCachePurger). A custom subdomain is not
 * part of this phase (docs/VENUE_PRO.md).
 */
class WhiteLabel
{
    public const COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    public function __construct(private OrgPlans $plans) {}

    public function enabled(?Organization $org): bool
    {
        return $org !== null && $this->plans->allows($org, 'white_label');
    }

    /**
     * The invitation footer branding, or null when the event is not white-labelled.
     *
     * @return array{name: string, logo_url: ?string}|null
     */
    public function forEvent(Event $event): ?array
    {
        $org = $event->org_id ? $event->organization : null;

        if (! $this->enabled($org)) {
            return null;
        }

        return ['name' => $org->name, 'logo_url' => $org->logo_url];
    }

    public function brandColor(?Organization $org): ?string
    {
        $color = $org?->white_label['brand_color'] ?? null;

        return $this->enabled($org) && is_string($color) && preg_match(self::COLOR_PATTERN, $color) ? $color : null;
    }

    /**
     * "From" name on emails the organization sends its clients; null: the app's own.
     */
    public function senderName(?Organization $org): ?string
    {
        if (! $this->enabled($org)) {
            return null;
        }

        $name = trim((string) ($org->white_label['sender_name'] ?? ''));

        return $name !== '' ? $name : $org->name;
    }
}
