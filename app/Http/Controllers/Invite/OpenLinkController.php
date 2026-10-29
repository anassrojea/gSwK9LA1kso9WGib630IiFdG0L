<?php

namespace App\Http\Controllers\Invite;

use App\Events\RsvpSubmitted;
use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\OpenLink;
use App\Services\Digits;
use App\Services\Entitlements;
use App\Services\InvitationConfig\InvitationConfig;
use App\Services\OgImage\OgImage;
use App\Services\OpenTracker;
use App\Services\Wizard\GuestListParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The open link (plan section 8): one /i/{token} for everyone. The page is the
 * same invitation; the RSVP form also asks for the guest's name, and each
 * reply becomes a household tagged "open-link" with its Rsvp, so the host's
 * dashboard counts it like any other family. The total of attending people
 * never exceeds the link's cap.
 *
 * A reply answers with the new household's token (reply_token). The page keeps
 * it in localStorage and sends it back to change the same reply instead of
 * creating another household. The guest routes reach this controller when a
 * token is not a household's (InvitationController, RsvpController).
 */
class OpenLinkController extends Controller
{
    /**
     * GET /i/{token} for an open link.
     */
    public function show(Request $request, string $token, OpenTracker $opens, Entitlements $entitlements, OgImage $og): Response
    {
        [$link, $invitation, $config] = $this->resolve($token);

        $opens->recordOpenLink($link, $request->userAgent());

        return response()
            ->view('invite.show', InvitationController::viewData($link->event, $invitation, $config, $entitlements, [
                'state' => route('invite.state', $token, false),
                'rsvp' => route('invite.rsvp', $token, false),
                'rose' => route('invite.rose', $invitation->slug, false),
            ], ogImage: $og->publicUrl($token, $og->hash($invitation, $config)), openLink: true))
            ->header('Cache-Control', 'public, max-age=0, s-maxage=300');
    }

    /**
     * GET /i/{token}/state[?reply={reply_token}]: seats left on the link and,
     * with a reply token, that guest's own reply.
     */
    public function state(Request $request, string $token, OpenTracker $opens, Entitlements $entitlements): JsonResponse
    {
        [$link, $invitation, $config] = $this->resolve($token);

        if ($request->boolean('open')) {
            $opens->recordOpenLink($link, $request->userAgent());
        }

        $reply = $this->findReply($link, $request->query('reply'));

        return response()->json(static::statePayload($link, $reply, $invitation, $config, $entitlements));
    }

    /**
     * POST /i/{token}/rsvp {name, attending, people_count, …, reply_token?}
     */
    public function rsvp(Request $request, string $token, Entitlements $entitlements): JsonResponse
    {
        [$link, $invitation, $config] = $this->resolve($token);

        $section = RsvpController::openSection($config);
        $locale = $config['locale'];

        $data = RsvpController::validateReply($request, $section, OpenLink::MAX_PER_REPLY,
            trans_choice('invite.open_link.too_many', OpenLink::MAX_PER_REPLY, ['count' => Digits::localize(OpenLink::MAX_PER_REPLY, $locale)]),
            [
                'name' => ['required', 'string', 'max:'.GuestListParser::MAX_NAME],
                'reply_token' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{10,32}$/'],
            ]);

        $name = trim(preg_replace('/\s+/u', ' ', $data['name']));
        $attending = (bool) $data['attending'];
        $people = $attending ? (int) $data['people_count'] : 0;

        [$household, $rsvp] = DB::transaction(function () use ($link, $data, $name, $attending, $people, $locale) {
            // one lock per link: concurrent replies are summed one after another, so the cap holds
            $locked = OpenLink::query()->whereKey($link->getKey())->lockForUpdate()->first();
            abort_if(! $locked?->enabled, 404);

            $household = $this->findReply($locked, $data['reply_token'] ?? null);

            $left = $locked->seatsLeft($household?->getKey());
            if ($attending && $people > $left) {
                throw ValidationException::withMessages(['people_count' => $left === 0
                    ? __('invite.open_link.full')
                    : trans_choice('invite.open_link.only_left', $left, ['count' => Digits::localize($left, $locale)]),
                ]);
            }

            $seats = max(1, $people);
            if ($household) {
                $household->update(['display_name' => $name, 'seats_allowed' => $seats]);
            } else {
                $household = $locked->event->households()->create([
                    'display_name' => $name,
                    'seats_allowed' => $seats,
                    'tags' => [OpenLink::TAG],
                ]);
            }

            return [$household, RsvpController::saveReply($household, $data)];
        });

        $household->setRelation('rsvp', $rsvp);
        $household->setRelation('event', $link->event);

        RsvpSubmitted::dispatch($rsvp);

        return response()->json(static::statePayload($link, $household, $invitation, $config, $entitlements));
    }

    /**
     * The per-guest JSON of /state for the open link. "household" describes the
     * guest's own reply (name null before they reply); seats_allowed is how many
     * people this reply may bring right now.
     */
    public static function statePayload(OpenLink $link, ?Household $reply, Invitation $invitation, array $config, Entitlements $entitlements): array
    {
        $left = $link->seatsLeft();
        $own = $reply?->rsvp?->attending ? $reply->rsvp->people_count : 0;
        $maxPeople = min(OpenLink::MAX_PER_REPLY, $left + $own);

        $payload = $reply
            ? InvitationController::statePayload($reply, $invitation, $config, $entitlements)
            : [
                'household' => ['name' => null],
                'rsvp' => null,
                'rsvp_open' => RsvpController::isOpen(InvitationConfig::section($config, 'rsvp')),
                'table' => null,
                'roses' => InvitationConfig::section($config, 'send_rose') ? (int) ($link->event->rose?->count ?? 0) : null,
            ];

        $payload['household']['seats_allowed'] = max(1, $maxPeople);
        $payload['open_link'] = [
            'seats_left' => $left,
            'max_per_reply' => $maxPeople,
            'full' => $maxPeople < 1,
        ];
        $payload['reply_token'] = $reply?->token;

        return $payload;
    }

    /**
     * An enabled open link on a live event with a published invitation; anything else is a 404.
     *
     * @return array{0: OpenLink, 1: Invitation, 2: array}
     */
    private function resolve(string $token): array
    {
        $link = OpenLink::query()->with('event.invitation')->where('token', $token)->where('enabled', true)->first();
        $invitation = $link?->event?->invitation;

        abort_if(! $link || ! $invitation?->published_at || $link->event->isArchived(), 404);

        $config = $invitation->renderConfig();
        SetLocale::apply($config['locale']);

        return [$link, $invitation, $config];
    }

    /**
     * The household a reply token points to, only if it was created through this link.
     */
    private function findReply(OpenLink $link, mixed $replyToken): ?Household
    {
        if (! is_string($replyToken) || ! preg_match('/^[A-Za-z0-9]{10,32}$/', $replyToken)) {
            return null;
        }

        return Household::query()
            ->with('rsvp')
            ->where('event_id', $link->event_id)
            ->where('token', $replyToken)
            ->whereRaw('? = any(tags)', [OpenLink::TAG])
            ->first();
    }
}
