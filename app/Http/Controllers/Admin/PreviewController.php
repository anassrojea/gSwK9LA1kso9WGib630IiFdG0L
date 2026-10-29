<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Invite\InvitationController;
use App\Http\Controllers\Wizard\PreviewController as WizardPreview;
use App\Http\Middleware\SetLocale;
use App\Models\Event;
use App\Models\Invitation;
use App\Models\Opening;
use App\Models\Template;
use App\Models\Theme;
use App\Services\Entitlements;
use App\Services\InvitationConfig\InvitationConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

/**
 * The admin's previews, rendered with the real guest view (invite.show) and
 * the wizard preview's code. Signed, short-lived URLs (made by the Filament
 * actions) and admins only; nothing is saved, logged as an open or cached.
 *
 *   GET /admin-preview/events/{event}           a customer's invitation, as the host's preview shows it
 *   GET /admin-preview/templates/{template}     a template's default_config, optionally with ?theme= / ?opening=
 */
class PreviewController extends Controller
{
    public const TTL_MINUTES = 30;

    public static function eventUrl(Event $event): string
    {
        return URL::temporarySignedRoute('admin.preview.event', now()->addMinutes(self::TTL_MINUTES), ['event' => $event->getKey()]);
    }

    /**
     * @param  array{theme?: string, opening?: string}  $with
     */
    public static function templateUrl(Template $template, array $with = []): string
    {
        return URL::temporarySignedRoute('admin.preview.template', now()->addMinutes(self::TTL_MINUTES), ['template' => $template->getKey()] + array_filter($with));
    }

    public function event(Request $request, Event $event, Entitlements $entitlements): Response
    {
        $this->authorizeAdmin($request);

        return WizardPreview::render($event, $entitlements, trans('admin.preview.ribbon_customer', [], $event->invitation?->config['locale'] ?? 'ar'));
    }

    public function template(Request $request, Template $template, Entitlements $entitlements): Response
    {
        $this->authorizeAdmin($request);

        $config = InvitationConfig::upgrade($template->default_config ?? []);
        if ($theme = $request->query('theme') ? Theme::query()->find($request->query('theme')) : null) {
            $config['theme'] = $theme->toConfig();
        }
        if ($opening = $request->query('opening') ? Opening::query()->find($request->query('opening')) : null) {
            $config['opening'] = ['id' => $opening->getKey()] + array_intersect_key($config['opening'] ?? [], ['options' => true]);
        }
        SetLocale::apply($config['locale'] ?? 'ar');

        // never saved: an event and invitation that exist only for this render
        $event = new Event([
            'occasion' => $config['occasion'] ?? $template->occasion,
            'title' => $template->name_en,
            'status' => 'draft',
            'plan_id' => in_array($template->tier, ['premium', 'signature'], true) ? $template->tier : null,
        ]);
        $invitation = new Invitation(['template_id' => $template->getKey(), 'config' => $config]);
        $invitation->setRelation('event', $event);
        $event->setRelation('invitation', $invitation);
        $event->setRelation('rose', null);

        $html = view('invite.show', InvitationController::viewData($event, $invitation, $config, $entitlements, [
            'state' => null,
            'rsvp' => null,
            'rose' => null,
        ]))->render();

        return WizardPreview::privateResponse(WizardPreview::withRibbon($html, trans('admin.preview.ribbon_template', [], $config['locale'] ?? 'ar')));
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->isAdmin(), 403);
    }
}
