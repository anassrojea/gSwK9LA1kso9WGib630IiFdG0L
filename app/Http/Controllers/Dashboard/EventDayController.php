<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\DoorPass;
use App\Models\Event;
use App\Services\Door\DoorPasses;
use App\Services\Entitlements;
use App\Services\EventDay;
use App\Services\Seating\MealCounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The host dashboard's event-day tools (plan sections 4.1 and 14.8), loaded
 * by its "Event day" panel: meal counts per section, the live stream link
 * for the guests' live day page, door PIN links, and where the door app,
 * seating chart and QR sheet live.
 */
class EventDayController extends Controller
{
    public function __construct(private Entitlements $entitlements, private DoorPasses $passes) {}

    /**
     * GET /dashboard/events/{event}/day
     */
    public function show(Request $request, Event $event, MealCounts $meals): JsonResponse
    {
        Gate::authorize('view', $event);

        $canUpdate = $request->user()->can('update', $event);
        $features = $this->entitlements->features($event);
        $has = fn (string $f) => in_array($f, $features, true);
        $window = EventDay::window($event);

        return response()->json([
            // the dashboard page only carries lang/{locale}/app.php; the panel brings its own strings
            'translations' => ['seating' => trans('seating'), 'door' => trans('door')],
            'meals' => $meals->for($event),
            'live_stream_url' => $event->live_stream_url,
            'features' => [
                'seating' => $has(Entitlements::SEATING),
                'qr_checkin' => $has(Entitlements::QR_CHECKIN),
                'live_day' => $has(Entitlements::LIVE_DAY),
                'personal_links' => $has(Entitlements::PERSONAL_LINKS),
            ],
            'live' => [
                'now' => EventDay::isLive($event),
                'from' => $window ? $window[0]->toIso8601String() : null,
                'until' => $window ? $window[1]->toIso8601String() : null,
            ],
            'can_update' => $canUpdate,
            'door_passes' => $canUpdate && $has(Entitlements::QR_CHECKIN) ? $this->passes->list($event) : [],
            'urls' => [
                'door' => route('door.show', $event, false),
                'seating' => route('dashboard.events.seating', $event, false),
                'chart_pdf' => route('events.seating.pdf', $event, false),
                'cards_pdf' => route('events.table-cards.pdf', $event, false),
                'qr_pdf' => route('events.qr-codes.pdf', $event, false),
                'live_stream' => route('dashboard.events.live-stream', $event, false),
                'door_passes' => route('dashboard.events.door-passes.store', $event, false),
                'upgrade' => route('dashboard.events.upgrade', $event, false),
            ],
        ]);
    }

    /**
     * PUT /dashboard/events/{event}/live-stream {url}: https only, empty clears it.
     */
    public function liveStream(Request $request, Event $event): JsonResponse
    {
        Gate::authorize('update', $event);

        $data = $request->validate([
            'url' => ['nullable', 'string', 'max:500', 'url:https', function (string $attribute, mixed $value, \Closure $fail) {
                $parts = is_string($value) ? parse_url($value) : false;
                if (! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
                    $fail(trans('seating.day.stream_invalid'));
                }
            }],
        ], ['url.url' => trans('seating.day.stream_invalid')]);

        $event->update(['live_stream_url' => ($data['url'] ?? null) ?: null]);

        return response()->json(['live_stream_url' => $event->live_stream_url]);
    }

    /**
     * POST /dashboard/events/{event}/door-passes {label}: a door PIN link. The PIN is in this response only.
     */
    public function storePass(Request $request, Event $event): JsonResponse
    {
        Gate::authorize('update', $event);
        abort_unless($this->entitlements->hasFeature($event, Entitlements::QR_CHECKIN), 403, trans('door.locked.title'));

        $data = $request->validate(['label' => ['required', 'string', 'max:60']]);
        ['pass' => $pass, 'pin' => $pin, 'url' => $url] = $this->passes->create($event, $request->user(), trim($data['label']));

        return response()->json([
            'pass' => ['id' => $pass->id, 'label' => $pass->label, 'expires_at' => $pass->expires_at->toIso8601String()],
            'pin' => $pin,
            'url' => $url,
            'door_passes' => $this->passes->list($event),
        ], 201);
    }

    /**
     * DELETE /dashboard/events/{event}/door-passes/{pass}: revoke now; open door sessions end on their next request.
     */
    public function revokePass(Request $request, Event $event, DoorPass $pass): JsonResponse
    {
        Gate::authorize('update', $event);
        abort_unless($pass->event_id === $event->getKey(), 404);

        $this->passes->revoke($pass, $request->user());

        return response()->json(['door_passes' => $this->passes->list($event)]);
    }
}
