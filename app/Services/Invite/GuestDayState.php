<?php

namespace App\Services\Invite;

use App\Models\Household;
use App\Models\Invitation;
use App\Services\Entitlements;
use App\Services\EventDay;
use App\Services\InvitationConfig\InvitationConfig;
use App\Services\Seating\HallLayoutData;
use App\Services\Seating\SeatingPlan;
use Illuminate\Support\Facades\Route;

/**
 * The per-guest event-day parts of GET /i/{token}/state (plan sections 9 and
 * 14.1). The invitation HTML is cached at the edge, so these never go into
 * it: invite.js fills the RSVP ticket and the "today" panel from this JSON.
 *
 *  seat  the guest's table and a compact hall map to highlight it (seating)
 *  qr    their door code, /i/{token}/qr.svg (QR check-in, attending only)
 *  live  the "today" panel: directions, live stream, photo wall (live day,
 *        only inside EventDay::window)
 */
class GuestDayState
{
    /**
     * @return array{seat: ?array, qr: ?string, live: ?array}
     */
    public static function payload(Household $household, Invitation $invitation, array $config, Entitlements $entitlements): array
    {
        $event = $household->event;
        $attending = (bool) $household->rsvp?->attending;
        $features = $entitlements->features($event);

        $seat = null;
        if ($attending && in_array(Entitlements::SEATING, $features, true) && ($tableId = SeatingPlan::tableOf($household))) {
            $layout = $event->hallLayout?->layout;
            $seat = [
                'table' => $tableId,
                'number' => HallLayoutData::tableNumber($tableId),
                'map' => $layout ? static::map($layout, $tableId) : null,
            ];
        }

        $qr = $attending && in_array(Entitlements::QR_CHECKIN, $features, true)
            ? route('invite.qr', $household->token, false)
            : null;

        $live = null;
        if (in_array(Entitlements::LIVE_DAY, $features, true) && EventDay::isLive($event)) {
            $place = InvitationConfig::section($config, 'place');
            $live = [
                'venue' => $place['name'] ?? $event->venue_name,
                'directions' => static::directions($place, $event->venue_name, $event->address, $event->lat, $event->lng),
                'stream' => $event->live_stream_url,
                // the live photo wall (phase 7B) plugs in here once its route exists
                'wall' => Route::has('wall.show') ? rescue(fn () => route('wall.show', $invitation->slug, false), null, false) : null,
                'until' => EventDay::window($event)[1]->toIso8601String(),
            ];
        }

        return ['seat' => $seat, 'qr' => $qr, 'live' => $live];
    }

    /**
     * A small hall map for the guest page: whole numbers only, one array per
     * table [number, x, y, r]; the guest's table is "mine".
     *
     * @return array{w: int, h: int, stage: ?array, tables: list<array{0: string, 1: int, 2: int, 3: int}>, mine: string}
     */
    public static function map(array $layout, string $tableId): array
    {
        $stage = $layout['stage'] ?? null;

        return [
            'w' => (int) round($layout['size']['w'] ?? 800),
            'h' => (int) round($layout['size']['h'] ?? 580),
            'stage' => $stage ? [(int) round($stage['x']), (int) round($stage['y']), (int) round($stage['w']), (int) round($stage['h'])] : null,
            'tables' => array_map(fn (array $t) => [
                HallLayoutData::tableNumber($t['id']),
                (int) round($t['x']),
                (int) round($t['y']),
                (int) round(max($t['r'] ?? 10, ($t['ring'] ?? 0) + ($t['chairR'] ?? 0) * 0.6)),
            ], $layout['tables'] ?? []),
            'mine' => HallLayoutData::tableNumber($tableId),
        ];
    }

    public static function directions(?array $place, ?string $venue, ?string $address, ?float $lat, ?float $lng): ?string
    {
        if (! empty($place['mapUrl']) && str_starts_with((string) $place['mapUrl'], 'https://')) {
            return $place['mapUrl'];
        }

        $lat = $place['lat'] ?? $lat;
        $lng = $place['lng'] ?? $lng;
        $query = $lat !== null && $lng !== null
            ? $lat.','.$lng
            : trim(($place['name'] ?? $venue ?? '').' '.($place['address'] ?? $address ?? ''));

        return $query === '' ? null : 'https://www.google.com/maps/search/?api=1&query='.urlencode($query);
    }
}
