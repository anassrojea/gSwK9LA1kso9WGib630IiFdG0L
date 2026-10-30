<?php

namespace App\Http\Controllers\Org;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Organization;
use App\Models\OrgInvitation;
use App\Models\OrgMember;
use App\Models\Template;
use App\Services\Dashboard\GuestBoard;
use App\Services\Door\DoorDesk;
use App\Services\Entitlements;
use App\Services\Org\OrgPresenter;
use App\Services\Org\OrgQuota;
use App\Services\Org\OrgTeam;
use App\Services\Seating\MealCounts;
use App\Services\Wizard\DraftCreator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Events an organization makes for its clients (phase 8A):
 *
 *   POST /org/{org}/events                    a draft owned by the org (events.org_id), opened in the
 *                                             wizard; refused once the month's signature events are used
 *   GET  /org/{org}/events/{event}            the venue's per-event view (plan section 4.2): guests,
 *                                             RSVPs, meal counts per section, live arrivals, the seating PDF
 *   POST /org/{org}/events/{event}/client     invite the couple to edit their invitation: on accepting they
 *                                             become the event's owner_id; org_id stays (OrgTeam::accept)
 */
class OrgEventController extends Controller
{
    public function __construct(private OrgPresenter $presenter) {}

    public function store(Request $request, Organization $organization, DraftCreator $drafts, OrgQuota $quota): RedirectResponse
    {
        Gate::authorize('createEvents', $organization);
        $data = $request->validate([
            'occasion' => ['required', Rule::in(Event::OCCASIONS)],
            'template_id' => ['nullable', 'string', Rule::exists('templates', 'id')->where('active', true)],
        ]);

        $quota->ensureCanCreate($organization);

        $event = $drafts->create($data['occasion'], $request->user(), isset($data['template_id']) ? Template::query()->find($data['template_id']) : null);
        $event->forceFill(['org_id' => $organization->getKey()])->save();
        AuditLog::record('org.event_created', $event, ['org_id' => $organization->getKey()], $request->user());

        return redirect()->route('create.step', [$event, 'design']);
    }

    public function show(Request $request, Organization $organization, Event $event, GuestBoard $board, MealCounts $meals, DoorDesk $door, Entitlements $entitlements): Response
    {
        abort_unless($event->org_id === $organization->getKey(), 404);
        Gate::authorize('view', $organization);
        Gate::authorize('view', $event);

        $user = $request->user();
        $client = $event->owner_id && $event->owner && ! OrgMember::query()->where('org_id', $organization->getKey())->where('user_id', $event->owner_id)->exists()
            ? $event->owner
            : null;
        $pending = OrgInvitation::query()->where('event_id', $event->getKey())->where('role', OrgInvitation::ROLE_CLIENT)->pending()->latest()->first();
        $features = $entitlements->features($event);
        $published = (bool) $event->invitation?->published_at;

        return Inertia::render('org/Event', [
            'org' => $this->presenter->present($organization, $user),
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'occasion' => $event->occasion,
                'status' => $event->status,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'timezone' => $event->timezone,
                'venue_name' => $event->venue_name,
                'hall' => $event->hallLayout?->name,
                'published' => $published,
                'plan' => $event->plan_id ?? $organization->plan_id ?? 'free',
                'org_quota_at' => $event->org_quota_at,
            ],
            'stats' => $board->stats($event),
            'meals' => $meals->for($event),
            'arrivals' => $door->stats($event),
            'client' => $client ? ['name' => $client->name, 'email' => $client->email] : null,
            'client_invitation' => $pending ? ['email' => $pending->email, 'expires_at' => $pending->expires_at->toIso8601String()] : null,
            'can' => [
                'update' => $user->can('update', $event),
                'invite_client' => $user->can('createEvents', $organization),
            ],
            'features' => [
                'seating' => in_array(Entitlements::SEATING, $features, true),
                'qr_checkin' => in_array(Entitlements::QR_CHECKIN, $features, true),
            ],
            'urls' => [
                'dashboard' => $event->status === 'draft' ? null : route('dashboard.events.show', $event, false),
                'edit' => route('create.step', [$event, $event->status === 'draft' ? 'details' : 'review'], false),
                'seating' => route('dashboard.events.seating', $event, false),
                'seating_pdf' => route('events.seating.pdf', $event, false),
                'door' => route('door.show', $event, false),
                'client' => route('org.events.client', [$organization, $event], false),
            ],
            'seating_translations' => ['seating' => trans('seating'), 'door' => trans('door')],
        ]);
    }

    public function inviteClient(Request $request, Organization $organization, Event $event, OrgTeam $team): RedirectResponse
    {
        abort_unless($event->org_id === $organization->getKey(), 404);
        Gate::authorize('createEvents', $organization);
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);

        $team->invite($organization, $request->user(), $data['email'], OrgInvitation::ROLE_CLIENT, $event);

        return back()->with('status', trans('org.clients.invited', ['email' => $data['email']]));
    }
}
