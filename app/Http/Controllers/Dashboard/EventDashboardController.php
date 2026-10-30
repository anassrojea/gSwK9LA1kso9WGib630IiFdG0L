<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Order;
use App\Services\Account\UpgradeHints;
use App\Services\Dashboard\GuestBoard;
use App\Services\Dashboard\GuestCsv;
use App\Services\Dashboard\WhatsApp;
use App\Services\Journey\SaveTheDate;
use App\Services\Marketplace\VendorCards;
use App\Services\OpenLinks;
use App\Services\PublishGate;
use App\Services\Wizard\GuestListParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The host dashboard for one event (plan section 4.1): numbers on top, one
 * list below, actions on each row. Org members see it per EventPolicy; door
 * staff (view + check-in only) get the same screen without the edit actions.
 */
class EventDashboardController extends Controller
{
    public function __construct(private GuestBoard $board, private PublishGate $gate) {}

    /**
     * GET /dashboard/events/{event}
     */
    public function show(Request $request, Event $event): Response|RedirectResponse
    {
        Gate::authorize('view', $event);

        $user = $request->user();
        $canUpdate = $user->can('update', $event);

        // a draft is still being written: its place is the wizard
        if ($event->status === 'draft' && $canUpdate) {
            return redirect()->route('create.step', [$event, 'details']);
        }

        $event->loadMissing('invitation');
        $guests = $this->board->rows($event);
        $locale = WhatsApp::locale($event);
        $templates = WhatsApp::templates($event);

        return Inertia::render('dashboard/Event', [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'occasion' => $event->occasion,
                'status' => $event->status,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'timezone' => $event->timezone ?: config('app.timezone'),
                'venue_name' => $event->venue_name,
                'invite_locale' => $locale,
            ],
            'stats' => $this->board->stats($event),
            'guests' => $guests,
            'tags' => collect($guests)->pluck('tags')->flatten()->unique()->sort()->values(),
            'messages' => [
                'invite' => $templates['invite'],
                'reminder' => $templates['reminder'],
                'defaults' => [
                    'invite' => WhatsApp::defaultTemplate('invite', $locale),
                    'reminder' => WhatsApp::defaultTemplate('reminder', $locale),
                ],
            ],
            'can' => [
                'update' => $canUpdate,
                'check_in' => $user->can('checkIn', $event),
                // personal links, their WhatsApp buttons and reminders are Premium (PublishGate)
                'send' => $this->gate->canSendPersonalLinks($event),
                'remind' => $this->gate->canRemind($event),
            ],
            'plan' => $this->gate->currentPlan($event),
            // the "what upgrading unlocks" banner: Free events, or published ones the plan no longer covers
            'upgradeHint' => $canUpdate ? $this->upgradeHint($event) : null,
            // phase 8D: a save-the-date can grow into the full invitation; after the event, the keepsakes page
            'saveTheDate' => $canUpdate && SaveTheDate::isEvent($event) ? [
                'upgrade_url' => route('events.save-the-date.upgrade', $event, false),
                'occasions' => array_values(array_diff(Event::WIZARD_OCCASIONS, ['save_the_date'])),
                'invitation_url' => ($next = Event::query()->where('source_event_id', $event->getKey())->value('id')) ? route('create.step', [$next, 'design'], false) : null,
            ] : null,
            'keepsakesUrl' => route('dashboard.events.keepsakes', $event, false),
            'pendingOrder' => $canUpdate ? Order::query()->where('event_id', $event->getKey())->pending()->latest()->first(['code', 'status'])?->only('code', 'status') : null,
            'urls' => [
                'edit' => route('create.step', [$event, 'details'], false),
                'export' => route('dashboard.events.guests.export', $event, false),
                'paste' => route('events.guests.import', $event, false),
                'csv_preview' => route('dashboard.events.guests.csv.preview', $event, false),
                'csv_import' => route('dashboard.events.guests.csv.import', $event, false),
                'reminders' => route('dashboard.events.reminders.store', $event, false),
                'messages' => route('dashboard.events.messages', $event, false),
                'upgrade' => route('dashboard.events.upgrade', $event, false),
                'orders' => route('dashboard.orders.index', absolute: false),
            ],
            // the open link panel (phase 3B) talks to GET/PUT /events/{event}/open-link itself
            'openLink' => $canUpdate ? app(OpenLinks::class)->payload($event) : null,
            // "Vendors near your venue" (phase 8C): by the event's city and occasion, contact through WhatsApp
            'vendors' => $canUpdate ? app(VendorCards::class)->forEvent($event) : null,
            'limits' => [
                'seats' => GuestListParser::MAX_SEATS,
                'name' => GuestListParser::MAX_NAME,
                'tags' => GuestCsv::MAX_TAGS,
                'message' => WhatsApp::MAX_TEMPLATE,
            ],
        ]);
    }

    /**
     * @return array{plan: string, next: ?string, unlocks: list<string>, missing: list<string>, next_guests: ?int}|null
     */
    private function upgradeHint(Event $event): ?array
    {
        if ($event->isArchived()) {
            return null;
        }

        $hint = app(UpgradeHints::class)->for($event, withMissing: true);
        if ($hint['plan'] !== 'free' && $hint['missing'] === []) {
            return null;
        }

        return [
            'plan' => $hint['plan'],
            'next' => $hint['next'],
            'unlocks' => array_slice($hint['unlocks'], 0, 4),
            'missing' => $hint['missing'],
            'next_guests' => $hint['next_guests'],
        ];
    }

    /**
     * PATCH /dashboard/events/{event}/messages {invite, reminder}: the host's
     * own WhatsApp wording. Empty or unchanged text goes back to the default.
     */
    public function messages(Request $request, Event $event): RedirectResponse
    {
        Gate::authorize('update', $event);

        $data = $request->validate([
            'invite' => ['nullable', 'string', 'max:'.WhatsApp::MAX_TEMPLATE],
            'reminder' => ['nullable', 'string', 'max:'.WhatsApp::MAX_TEMPLATE],
        ]);

        $event->loadMissing('invitation');
        $locale = WhatsApp::locale($event);
        $messages = [];
        foreach (WhatsApp::KINDS as $kind) {
            $text = trim((string) ($data[$kind] ?? ''));
            if ($text !== '' && $text !== WhatsApp::defaultTemplate($kind, $locale)) {
                $messages[$kind] = $text;
            }
        }

        $event->update(['messages' => $messages ?: null]);

        return back()->with('status', trans('app.dashboard.messages.saved'));
    }

    /**
     * GET /dashboard/events/{event}/guests.csv[?status=&tag=]: the guest list
     * with replies, UTF-8 with a BOM so Excel shows Arabic.
     */
    public function export(Request $request, Event $event): StreamedResponse
    {
        Gate::authorize('view', $event);

        $request->validate([
            'status' => ['nullable', 'in:'.implode(',', GuestBoard::STATUSES)],
            'tag' => ['nullable', 'string', 'max:'.GuestCsv::MAX_TAG],
        ]);

        $rows = collect($this->board->rows($event))
            ->when($request->filled('status'), fn ($rows) => $rows->where('status', $request->string('status')->toString()))
            ->when($request->filled('tag'), fn ($rows) => $rows->filter(fn ($row) => in_array($request->string('tag')->toString(), $row['tags'], true)))
            ->values();

        $timezone = $event->timezone ?: config('app.timezone');
        $locale = app()->getLocale();

        return response()->streamDownload(function () use ($rows, $timezone, $locale) {
            $out = fopen('php://output', 'w');
            GuestCsv::export($out, $rows, $timezone, $locale);
            fclose($out);
        }, GuestCsv::filename($event), [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
