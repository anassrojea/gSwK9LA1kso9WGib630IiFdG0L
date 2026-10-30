<?php

namespace App\Http\Controllers\Door;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\SeatingController;
use App\Models\Event;
use App\Models\Household;
use App\Services\Door\DoorActor;
use App\Services\Door\DoorDesk;
use App\Services\Door\DoorPasses;
use App\Services\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The door app (plan sections 9 and 14.8): a mobile web page, no install.
 * Open to users allowed by EventPolicy::checkIn (owner, organization owner,
 * staff and door) and to door PIN links. Part of the QR check-in add-on;
 * manual check-in from the dashboard stays available to every plan.
 */
class DoorController extends Controller
{
    public function __construct(
        private DoorDesk $desk,
        private DoorPasses $passes,
        private Entitlements $entitlements,
    ) {}

    /**
     * GET /door/{event}
     */
    public function show(Request $request, Event $event): Response|RedirectResponse
    {
        $actor = $this->actor($request, $event, redirect: true);
        if ($actor instanceof RedirectResponse) {
            return $actor;
        }

        $locked = ! $this->entitlements->hasFeature($event, Entitlements::QR_CHECKIN);
        $canUpdate = (bool) $actor->user?->can('update', $event);

        return Inertia::render('door/Door', [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'venue_name' => $event->venue_name,
                'timezone' => $event->timezone ?: config('app.timezone'),
            ],
            'locked' => $locked,
            'actor' => ['name' => $actor->label(), 'pass' => $actor->pass !== null, 'live' => $actor->canSubscribe()],
            'guests' => $locked ? [] : $this->desk->guests($event),
            'stats' => $locked ? null : $this->desk->stats($event),
            'poll_seconds' => (int) config('event_day.door.poll_seconds'),
            'translations' => SeatingController::translations(),
            'urls' => [
                'guests' => route('door.guests', $event, false),
                'stats' => route('door.stats', $event, false),
                'checkin' => route('door.checkin', $event, false),
                'sync' => route('door.sync', $event, false),
                'dashboard' => $actor->user ? route('dashboard.events.show', $event, false) : null,
                'upgrade' => $canUpdate ? route('dashboard.events.upgrade', $event, false).'?addon=qr_checkin' : null,
            ],
        ]);
    }

    /**
     * GET /door/{event}/guests: every family in door format (name search, offline lookups).
     */
    public function guests(Request $request, Event $event): JsonResponse
    {
        $this->ready($request, $event);

        return response()->json(['guests' => $this->desk->guests($event), 'stats' => $this->desk->stats($event)]);
    }

    /**
     * GET /door/{event}/stats: arrivals per section (polled when Reverb is unavailable).
     */
    public function stats(Request $request, Event $event): JsonResponse
    {
        $this->ready($request, $event);

        return response()->json(['stats' => $this->desk->stats($event)]);
    }

    /**
     * POST /door/{event}/checkin {token, people_in, scan_id, confirm}
     */
    public function checkin(Request $request, Event $event): JsonResponse
    {
        $actor = $this->ready($request, $event);

        $data = $request->validate([
            'token' => ['required', 'string', 'max:300'],
            'people_in' => ['nullable', 'integer', 'min:1', 'max:100'],
            'scan_id' => ['required', 'uuid'],
            'confirm' => ['boolean'],
        ]);

        $household = $this->household($event, $data['token']);
        $guest = $this->desk->present($event, $household);
        $people = (int) ($data['people_in'] ?? DoorDesk::suggestedPeople($guest));

        $result = $this->desk->checkIn($event, $actor, $household, $people, $data['scan_id'], (bool) ($data['confirm'] ?? false));

        return response()->json($result + ['stats' => $this->desk->stats($event)], $result['status'] === DoorDesk::STATUS_NEEDS_CONFIRMATION ? 409 : 200);
    }

    /**
     * POST /door/{event}/sync {scans: [...]}: scans queued on the device while offline.
     */
    public function sync(Request $request, Event $event): JsonResponse
    {
        $actor = $this->ready($request, $event);

        $data = $request->validate([
            'scans' => ['required', 'array', 'max:200'],
            'scans.*.scan_id' => ['required', 'uuid', 'distinct'],
            'scans.*.token' => ['required', 'string', 'max:300'],
            'scans.*.people_in' => ['required', 'integer', 'min:1', 'max:100'],
            'scans.*.scanned_at' => ['nullable', 'date'],
            'scans.*.confirm' => ['boolean'],
            'scans.*.known_in' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        $scans = array_map(fn (array $s) => ['token' => static::token($s['token'])] + $s, $data['scans']);

        return response()->json([
            'results' => $this->desk->sync($event, $actor, $scans),
            'stats' => $this->desk->stats($event),
        ]);
    }

    /**
     * The household token in a scanned code: a personal link …/i/{token} or the bare token.
     */
    public static function token(string $scanned): string
    {
        $scanned = trim($scanned);

        return preg_match('#/i/([A-Za-z0-9]{10,32})(?:[/?\#]|$)#', $scanned, $m) ? $m[1] : $scanned;
    }

    private function household(Event $event, string $scanned): Household
    {
        $token = static::token($scanned);
        $household = preg_match('/^[A-Za-z0-9]{10,32}$/', $token) ? $this->desk->find($event, $token) : null;

        abort_if($household === null, 404, trans('door.not_found'));

        return $household;
    }

    /**
     * A door actor for the JSON endpoints, with the QR check-in feature.
     */
    private function ready(Request $request, Event $event): DoorActor
    {
        $actor = $this->actor($request, $event);
        abort_unless($this->entitlements->hasFeature($event, Entitlements::QR_CHECKIN), 403, trans('door.locked.title'));

        return $actor;
    }

    private function actor(Request $request, Event $event, bool $redirect = false): DoorActor|RedirectResponse
    {
        $user = $request->user();
        if ($user && $user->can('checkIn', $event)) {
            return new DoorActor(user: $user);
        }

        if ($pass = $this->passes->fromSession($request, $event)) {
            return new DoorActor(pass: $pass);
        }

        if ($redirect && ! $user) {
            return redirect()->guest(route('login'));
        }

        abort($user ? 403 : 401);
    }
}
