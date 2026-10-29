<?php

namespace App\Http\Controllers\Wizard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Invite\InvitationController;
use App\Http\Middleware\SetLocale;
use App\Models\Event;
use App\Services\Entitlements;
use App\Services\InvitationConfig\InvitationConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The live preview in the wizard: the real guest view (invite.show) for a
 * draft, with a placeholder guest. Nothing is logged as an open, the page is
 * never cached or indexed, and the RSVP form answers "this is a preview".
 */
class PreviewController extends Controller
{
    public const LINK_TTL_HOURS = 12;

    /**
     * GET /create/{event}/preview (session: owner or the draft's browser).
     */
    public function show(Event $event, Entitlements $entitlements): Response
    {
        return static::render($event, $entitlements);
    }

    /**
     * The preview page, shared with the admin's read-only "open as customer"
     * (App\Http\Controllers\Admin\PreviewController). Nothing here writes.
     */
    public static function render(Event $event, Entitlements $entitlements, ?string $ribbon = null): Response
    {
        $invitation = $event->invitation;
        abort_unless($invitation, 404);

        $config = $invitation->renderConfig();
        SetLocale::apply($config['locale']);

        // invite.js calls these without cookies (and appends ?open=1), so they carry a signature in the path
        $expires = now()->addHours(self::LINK_TTL_HOURS)->getTimestamp();
        $signed = fn (string $name) => route($name, [
            'event' => $event->getKey(),
            'expires' => $expires,
            'signature' => static::signature($event, $expires),
        ], false);

        $html = view('invite.show', InvitationController::viewData($event, $invitation, $config, $entitlements, [
            'state' => $signed('create.preview.state'),
            'rsvp' => $signed('create.preview.rsvp'),
            'rose' => null,
        ]))->render();

        return static::privateResponse(static::withRibbon($html, $ribbon ?? trans('app.wizard.preview.ribbon', [], $config['locale'])));
    }

    /**
     * Preview HTML: never cached, indexed or leaking the URL as a referrer.
     */
    public static function privateResponse(string $html): Response
    {
        return response($html)
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * GET /p/{event}/{expires}/{signature}/state: a placeholder guest, never a real household.
     */
    public function state(Event $event, int $expires, string $signature): JsonResponse
    {
        static::verify($event, $expires, $signature);
        $config = $event->invitation?->renderConfig();
        abort_unless($config, 404);

        return response()->json([
            'household' => [
                'name' => trans('app.wizard.preview.guest_name', [], $config['locale']),
                'seats_allowed' => 2,
            ],
            'rsvp' => null,
            'rsvp_open' => true,
            'table' => null,
            'roses' => InvitationConfig::section($config, 'send_rose') ? 0 : null,
            'preview' => true,
        ]);
    }

    /**
     * POST /p/{event}/{expires}/{signature}/rsvp: replies are switched off in the preview.
     */
    public function rsvp(Event $event, int $expires, string $signature): JsonResponse
    {
        static::verify($event, $expires, $signature);
        $locale = $event->invitation?->config['locale'] ?? 'ar';
        $message = trans('app.wizard.preview.rsvp_disabled', [], $locale);

        return response()->json(['message' => $message, 'errors' => ['preview' => [$message]]], 422);
    }

    public static function signature(Event $event, int $expires): string
    {
        return hash_hmac('sha256', 'preview|'.$event->getKey().'|'.$expires, (string) config('app.key'));
    }

    private static function verify(Event $event, int $expires, string $signature): void
    {
        abort_if($expires < now()->getTimestamp() || ! hash_equals(static::signature($event, $expires), $signature), 403);
    }

    /**
     * A small "Preview" ribbon, added around the shared view rather than inside it.
     */
    public static function withRibbon(string $html, string $label): string
    {
        $ribbon = '<div data-preview-ribbon style="position:fixed;inset-block-start:10px;inset-inline-start:10px;z-index:2147483647;'
            .'pointer-events:none;padding:4px 10px;border-radius:999px;background:rgba(20,10,15,.72);color:#fff;'
            .'font:600 12px/1.4 system-ui,sans-serif;letter-spacing:.02em">'.e($label).'</div>';

        $at = strripos($html, '</body>');

        return $at === false ? $html.$ribbon : substr_replace($html, $ribbon, $at, 0);
    }
}
