<?php

namespace App\Http\Controllers\Invite;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Keepsakes\ThankYouPageController;
use App\Models\Event;
use App\Models\Household;
use App\Models\Invitation;
use App\Services\Entitlements;
use App\Services\InvitationConfig\InvitationConfig;
use App\Services\InvitationConfig\MediaUrl;
use App\Services\InvitationConfig\ThemeStyles;
use App\Services\Invite\GuestDayState;
use App\Services\Keepsakes\ThankYouCards;
use App\Services\OgImage\OgImage;
use App\Services\OpenTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class InvitationController extends Controller
{
    use ResolvesHousehold;

    /**
     * GET /i/{token}: the invitation, rendered on the server from its config.
     * Per-guest parts (name, seats, RSVP state) come from /state, so this HTML
     * holds nothing personal and can be cached at the edge.
     */
    public function show(Request $request, string $token, OpenTracker $opens, Entitlements $entitlements, OgImage $og): Response
    {
        $household = $this->findHousehold($token);
        if (! $household) {
            return app(OpenLinkController::class)->show($request, $token, $opens, $entitlements, $og);
        }

        [$household, $invitation, $config] = $this->resolve($token, $household);

        $opens->record($household, $request->userAgent());

        $event = $household->event;

        // after the event the same link shows the thank-you card, when the host made one (phase 8D)
        if ($card = app(ThankYouCards::class)->replacesInvite($event)) {
            return ThankYouPageController::render($event, $card, $token, $entitlements);
        }

        return response()
            ->view('invite.show', static::viewData($event, $invitation, $config, $entitlements, [
                'state' => route('invite.state', $token, false),
                'rsvp' => route('invite.rsvp', $token, false),
                'rose' => route('invite.rose', $invitation->slug, false),
            ], ogImage: $og->publicUrl($token, $og->hash($invitation, $config, $household))))
            ->header('Cache-Control', 'public, max-age=0, s-maxage=300');
    }

    /**
     * Data for the invite.show view, shared with the wizard preview
     * (App\Http\Controllers\Wizard\PreviewController).
     *
     * @param  array{state: ?string, rsvp: ?string, rose: ?string}  $urls
     * @param  ?string  $ogImage  absolute URL of the link preview image (none in the wizard preview)
     * @param  bool  $openLink  the RSVP form asks for the guest's name (one link for everyone)
     */
    public static function viewData(Event $event, Invitation $invitation, array $config, Entitlements $entitlements, array $urls, ?string $ogImage = null, bool $openLink = false): array
    {
        return [
            'ogImage' => $ogImage,
            'openLink' => $openLink,
            'config' => $config,
            'sections' => InvitationConfig::visibleSections($config),
            'event' => $event,
            'invitation' => $invitation,
            'themeVars' => ThemeStyles::cssVariables($config['theme']),
            'fontsUrl' => ThemeStyles::fontsUrl($config['theme']),
            'showBadge' => ! $entitlements->hasFeature($event, Entitlements::NO_BADGE),
            'mediaUrl' => new MediaUrl($event),
            'boot' => [
                'locale' => $config['locale'],
                'opening' => ['id' => $config['opening']['id'], 'options' => (object) ($config['opening']['options'] ?? [])],
                'effects' => (object) ($config['effects'] ?? []),
                'urls' => $urls,
            ],
        ];
    }

    /**
     * GET /i/{token}/state: household name, seats, RSVP state (JSON, never cached).
     * The page calls it with ?open=1 once per load to record the open.
     */
    public function state(Request $request, string $token, OpenTracker $opens, Entitlements $entitlements): JsonResponse
    {
        $household = $this->findHousehold($token);
        if (! $household) {
            return app(OpenLinkController::class)->state($request, $token, $opens, $entitlements);
        }

        [$household, $invitation, $config] = $this->resolve($token, $household);

        if ($request->boolean('open')) {
            $opens->record($household, $request->userAgent());
        }

        return response()->json(static::statePayload($household, $invitation, $config, $entitlements));
    }

    public static function statePayload(Household $household, Invitation $invitation, array $config, Entitlements $entitlements): array
    {
        $event = $household->event;
        $rsvp = $household->rsvp;
        $rsvpSection = InvitationConfig::section($config, 'rsvp');

        // "You are at table 7" is part of the seating feature (plan section 5)
        $table = $rsvp?->attending && $entitlements->hasFeature($event, Entitlements::SEATING)
            ? $household->seatAssignments()->value('table_id')
            : null;

        return [
            'household' => [
                'name' => $household->display_name,
                'seats_allowed' => $household->seats_allowed,
            ],
            'rsvp' => $rsvp ? [
                'attending' => $rsvp->attending,
                'people_count' => $rsvp->people_count,
                'kids_count' => $rsvp->kids_count,
                'message' => $rsvp->message,
                'meal' => $rsvp->extras['meal'] ?? null,
                'allergies' => $rsvp->extras['allergies'] ?? null,
                'responded_at' => $rsvp->responded_at?->toIso8601String(),
            ] : null,
            'rsvp_open' => RsvpController::isOpen($rsvpSection),
            'table' => $table,
            'roses' => InvitationConfig::section($config, 'send_rose') ? (int) ($event->rose?->count ?? 0) : null,
            // event-day parts: table map, door QR, the "today" panel (phase 7A)
            ...GuestDayState::payload($household, $invitation, $config, $entitlements),
        ];
    }
}
