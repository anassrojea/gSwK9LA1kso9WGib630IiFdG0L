<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Invite\InvitationController;
use App\Http\Controllers\Wizard\PreviewController as WizardPreview;
use App\Http\Middleware\SetLocale;
use App\Models\Event;
use App\Models\Invitation;
use App\Models\TemplateRevision;
use App\Services\Digits;
use App\Services\Entitlements;
use App\Services\InvitationConfig\InvitationConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /designer/revisions/{revision}/preview: a template revision rendered with the real
 * guest view (invite.show), as the wizard and the admin previews do. Drafts and revisions
 * in review are private: only their designer and admins see them. Nothing is saved,
 * cached, indexed or counted as an open.
 */
class RevisionPreviewController extends Controller
{
    public function __invoke(Request $request, TemplateRevision $revision, Entitlements $entitlements): Response
    {
        $revision->loadMissing('template');
        $user = $request->user();
        abort_unless($user && ($user->isAdmin() || ($revision->template?->designer_id !== null && $revision->template->designer_id === $user->getKey())), 403);

        $config = InvitationConfig::upgrade($revision->default_config ?? []);
        SetLocale::apply($config['locale'] ?? 'ar');

        // an event and invitation that exist only for this render; Signature shows every section
        $event = new Event([
            'occasion' => $config['occasion'] ?? $revision->occasion,
            'title' => $revision->name_en,
            'status' => 'draft',
            'plan_id' => 'signature',
        ]);
        $invitation = new Invitation(['template_id' => $revision->template_id, 'config' => $config]);
        $invitation->setRelation('event', $event);
        $event->setRelation('invitation', $invitation);
        $event->setRelation('rose', null);

        $html = view('invite.show', InvitationController::viewData($event, $invitation, $config, $entitlements, [
            'state' => null,
            'rsvp' => null,
            'rose' => null,
        ]))->render();

        $ribbon = trans('marketplace.preview.ribbon', ['number' => Digits::localize($revision->number, $config['locale'] ?? 'ar'), 'status' => trans("marketplace.statuses.revision.{$revision->status}", [], $config['locale'] ?? 'ar')], $config['locale'] ?? 'ar');

        return WizardPreview::privateResponse(WizardPreview::withRibbon($html, $ribbon));
    }
}
