<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Household;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Placeholder host dashboard: the user's own events and their organizations'
     * events (the same rule as EventPolicy::view), with the top-line numbers.
     */
    public function __invoke(Request $request): Response
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
                'preview_url' => ($token = $event->households->first()?->token) ? route('invite.show', $token) : null,
            ]),
        ]);
    }
}
