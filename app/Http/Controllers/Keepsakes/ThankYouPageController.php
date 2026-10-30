<?php

namespace App\Http\Controllers\Keepsakes;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Invite\InvitationController;
use App\Http\Middleware\SetLocale;
use App\Models\Event;
use App\Models\Household;
use App\Models\OpenLink;
use App\Models\ThankYouCard;
use App\Services\Entitlements;
use App\Services\Keepsakes\AlbumMediaUrl;
use App\Services\Keepsakes\ThankYouCards;
use Illuminate\Http\Response;

/**
 * GET /t/{token}: the event's thank-you card (phase 8D) at each household's
 * personal link and at the open link. Rendered with the invitation's own view
 * from the card's config; like /i/{token}, the HTML holds nothing personal
 * (the family's name comes from /i/{token}/state) and is cached at the edge.
 * Opening it is not counted as an invitation open.
 */
class ThankYouPageController extends Controller
{
    public function show(string $token, ThankYouCards $cards, Entitlements $entitlements): Response
    {
        $household = Household::query()->with('event.invitation')->where('token', $token)->first();
        $link = $household ? null : OpenLink::query()->with('event.invitation')->where('token', $token)->where('enabled', true)->first();
        $event = $household?->event ?? $link?->event;

        $card = $event ? $cards->visible($event) : null;
        abort_if(! $event || ! $card || ! $event->invitation?->published_at, 404);

        return static::render($event, $card, $household ? $token : null, $entitlements);
    }

    /**
     * The card's page; also what /i/{token} serves after the event (ThankYouCards::replacesInvite).
     *
     * @param  ?string  $stateToken  a personal link: the page asks /state for the family's name
     */
    public static function render(Event $event, ThankYouCard $card, ?string $stateToken, Entitlements $entitlements): Response
    {
        $config = $card->renderConfig();
        SetLocale::apply($config['locale']);

        $data = InvitationController::viewData($event, $event->invitation, $config, $entitlements, [
            'state' => $stateToken ? route('invite.state', $stateToken, false) : null,
            'rsvp' => null,
            'rose' => null,
        ]);
        $data['mediaUrl'] = new AlbumMediaUrl($event);
        // the thank-you card is not an invitation open (invite.js asks /state without ?open=1)
        $data['boot']['countOpen'] = false;

        return response()
            ->view('invite.show', $data)
            ->header('Cache-Control', 'public, max-age=0, s-maxage=300');
    }
}
