<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Household;
use App\Models\User;
use App\Services\Account\UpgradeHints;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The host's events list: their own events and their organizations' events
     * (the same rule as EventPolicy::view), with the top-line numbers. Each
     * event links to its dashboard (EventDashboardController), drafts to the wizard.
     */
    public function __invoke(Request $request, UpgradeHints $hints): Response
    {
        $user = $request->user();
        $orgIds = $user->organizations()->pluck('organizations.id');

        $events = Event::query()
            ->where(fn (Builder $q) => $q->where('owner_id', $user->id)->orWhereIn('org_id', $orgIds))
            ->withCount([
                'households as invited',
                'households as opened' => fn (Builder $q) => $q->whereNotNull('first_opened_at'),
                'households as replied' => fn (Builder $q) => $q->whereHas('rsvp'),
            ])
            ->with(['households' => fn ($q) => $q->select('id', 'event_id', 'token')->oldest()->limit(1)])
            ->orderBy('starts_at')
            ->get();

        $attending = Household::query()
            ->join('rsvps', 'rsvps.household_id', '=', 'households.id')
            ->whereIn('households.event_id', $events->modelKeys())
            ->where('rsvps.attending', true)
            ->groupBy('households.event_id')
            ->selectRaw('households.event_id, sum(rsvps.people_count) as people')
            ->pluck('people', 'event_id');

        return Inertia::render('Dashboard', [
            'events' => $events->map(fn (Event $event) => [
                'id' => $event->id,
                'title' => $event->title,
                'occasion' => $event->occasion,
                'status' => $event->status,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'stats' => [
                    'invited' => $event->invited,
                    'opened' => $event->opened,
                    'replied' => $event->replied,
                    'attending' => (int) ($attending[$event->id] ?? 0),
                ],
                // published events open their dashboard; drafts go back to the wizard
                'dashboard_url' => $event->status === 'draft' ? null : route('dashboard.events.show', $event, false),
                'edit_url' => route('create.step', [$event, $event->status === 'draft' ? 'details' : 'review'], false),
                'preview_url' => ($token = $event->households->first()?->token) ? route('invite.show', $token) : null,
                // plan badge and "Upgrade" (phase 5A): upgrading is the owner's / org editor's call
                ...$this->plan($event, $user, $hints),
            ]),
        ]);
    }

    /**
     * @return array{plan: string, upgrade_url: ?string}
     */
    private function plan(Event $event, User $user, UpgradeHints $hints): array
    {
        $hint = $hints->for($event);

        return [
            'plan' => $hint['plan'],
            'upgrade_url' => $hint['can_upgrade'] && ! $event->isArchived() && $user->can('update', $event)
                ? AccountController::upgradeUrl($event)
                : null,
        ];
    }
}
