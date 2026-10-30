<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\HallLayout;
use App\Models\Household;
use App\Services\Entitlements;
use App\Services\HallGenerator;
use App\Services\Seating\AutoSeater;
use App\Services\Seating\HallChanges;
use App\Services\Seating\HallLayoutData;
use App\Services\Seating\HallLayouts;
use App\Services\Seating\MealCounts;
use App\Services\Seating\SeatingPlan;
use App\Services\Seating\SeatingWarnings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The seating planner (plan section 7.3): pick or generate the hall, drag
 * families onto tables, auto-seat, and save. Part of the seating add-on;
 * without it the page offers the add-on instead.
 */
class SeatingController extends Controller
{
    public function __construct(
        private SeatingPlan $plan,
        private HallLayouts $halls,
        private Entitlements $entitlements,
    ) {}

    /**
     * GET /dashboard/events/{event}/seating
     */
    public function show(Request $request, Event $event, MealCounts $meals): Response
    {
        Gate::authorize('update', $event);

        $base = [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'timezone' => $event->timezone ?: config('app.timezone'),
                'has_org' => $event->org_id !== null,
            ],
            'translations' => static::translations(),
            'urls' => [
                'dashboard' => route('dashboard.events.show', $event, false),
                'upgrade' => route('dashboard.events.upgrade', $event, false).'?addon=seating',
            ],
        ];

        if (! $this->entitlements->hasFeature($event, Entitlements::SEATING)) {
            return Inertia::render('seating/Planner', $base + ['locked' => true]);
        }

        $layout = $this->plan->layout($event);
        $families = $this->plan->families($event);
        $assignments = $this->plan->assignments($event);

        return Inertia::render('seating/Planner', array_merge_recursive($base, [
            'locked' => false,
            'hall_change' => HallChanges::notice($event),
            'hall' => $layout ? [
                'id' => $event->hall_layout_id,
                'name' => $event->hallLayout->name,
                'layout' => $layout,
                'shared' => $this->halls->inUse($event->hallLayout) > 1,
            ] : null,
            'halls' => $this->halls->available($request->user(), $event),
            'families' => $families,
            'assignments' => $assignments,
            'warnings' => SeatingWarnings::compute($layout, $families, $assignments),
            'meals' => $meals->for($event),
            'limits' => ['tables' => HallGenerator::MAX_TABLES, 'chairs' => HallGenerator::MAX_CHAIRS],
            'urls' => [
                'hall' => route('events.hall.update', $event, false),
                'seats' => route('events.seats.update', $event, false),
                'auto' => route('events.seating.auto', $event, false),
                'section' => route('events.seating.section', $event, false),
                'chart_pdf' => route('events.seating.pdf', $event, false),
                'cards_pdf' => route('events.table-cards.pdf', $event, false),
                'find_pdf' => route('events.find-table.pdf', $event, false),
                'halls' => route('dashboard.halls.index', absolute: false),
            ],
        ]));
    }

    /**
     * PUT /events/{event}/hall {hall_id} | {layout, name}: link a saved hall,
     * or save the generated / nudged one. Seats that no longer exist are freed.
     */
    public function hall(Request $request, Event $event): JsonResponse
    {
        Gate::authorize('update', $event);
        $this->ensureSeating($event);

        $data = $request->validate([
            'hall_id' => ['nullable', 'uuid'],
            'layout' => ['required_without:hall_id', 'array'],
            'name' => ['nullable', 'string', 'max:'.HallLayoutData::MAX_NAME],
        ]);

        if (! empty($data['hall_id'])) {
            $hall = HallLayout::query()->find($data['hall_id']);
            abort_unless($hall && $this->halls->canUse($request->user(), $hall, $event), 403);
            $dropped = $this->halls->link($event, $hall);
        } else {
            ['hall' => $hall, 'dropped' => $dropped] = $this->halls->saveForEvent($request->user(), $event, $data['layout'], $data['name'] ?? null);
        }

        $event->setRelation('hallLayout', $hall);
        $families = $this->plan->families($event);
        $assignments = $this->plan->assignments($event);

        return response()->json([
            'hall' => ['id' => $hall->id, 'name' => $hall->name, 'layout' => $hall->layout, 'shared' => $this->halls->inUse($hall) > 1],
            'halls' => $this->halls->available($request->user(), $event),
            'assignments' => $assignments,
            'warnings' => SeatingWarnings::compute($hall->layout, $families, $assignments),
            'dropped' => $dropped,
        ]);
    }

    /**
     * PUT /events/{event}/seats {assignments: [{household_id, table_id, seat_index}]}:
     * the whole seating plan, replaced at once.
     */
    public function seats(Request $request, Event $event): JsonResponse
    {
        Gate::authorize('update', $event);
        $this->ensureSeating($event);

        $layout = $this->plan->layout($event);
        $max = $layout ? HallLayoutData::totalSeats($layout) : 0;

        $data = $request->validate([
            'assignments' => ['present', 'array', 'max:'.max(1, $max)],
            'assignments.*.household_id' => ['required', 'uuid'],
            'assignments.*.table_id' => ['required', 'string', 'max:16'],
            'assignments.*.seat_index' => ['required', 'integer', 'min:0', 'max:'.HallLayoutData::MAX_SEATS],
        ]);

        $rows = array_map(fn (array $a) => [
            'household_id' => $a['household_id'],
            'table_id' => $a['table_id'],
            'seat_index' => (int) $a['seat_index'],
        ], $data['assignments']);

        $assignments = $this->plan->save($event, $rows);

        return response()->json([
            'assignments' => $assignments,
            'warnings' => SeatingWarnings::compute($layout, $this->plan->families($event), $assignments),
        ]);
    }

    /**
     * POST /events/{event}/seating/auto {mode: all|fill}: a proposal only; the
     * planner shows it and saves it through PUT /seats when the host applies it.
     */
    public function auto(Request $request, Event $event, AutoSeater $seater): JsonResponse
    {
        Gate::authorize('update', $event);
        $this->ensureSeating($event);

        $data = $request->validate(['mode' => ['nullable', Rule::in([AutoSeater::MODE_ALL, AutoSeater::MODE_FILL])]]);

        $layout = $this->plan->layout($event);
        abort_if($layout === null, 422, trans('seating.errors.no_hall'));

        $families = $this->plan->families($event);
        $result = $seater->plan($layout, $families, $this->plan->assignments($event), $data['mode'] ?? AutoSeater::MODE_ALL);
        $labelled = SeatingPlan::label($result['assignments'], collect($families)->keyBy('id')->all(), array_column($layout['tables'], 'id'));

        return response()->json([
            'assignments' => $labelled,
            'placed' => $result['placed'],
            'unplaced' => $result['unplaced'],
            'warnings' => SeatingWarnings::compute($layout, $families, $result['assignments']),
        ]);
    }

    /**
     * PATCH /events/{event}/seating/section {household_id, section: men|women|null}
     */
    public function section(Request $request, Event $event): JsonResponse
    {
        Gate::authorize('update', $event);

        $data = $request->validate([
            'household_id' => ['required', 'uuid'],
            'section' => ['nullable', Rule::in(SeatingPlan::SECTIONS)],
        ]);

        $household = Household::query()->where('event_id', $event->getKey())->findOrFail($data['household_id']);
        $household->update(['section' => $data['section'] ?? null]);

        return response()->json(['household_id' => $household->id, 'section' => $household->section]);
    }

    /**
     * lang/{locale}/app.php plus the planner's and the door app's own strings.
     */
    public static function translations(): array
    {
        return array_merge((array) trans('app'), ['seating' => trans('seating'), 'door' => trans('door'), 'halls' => trans('halls'), 'venues' => trans('venues')]);
    }

    private function ensureSeating(Event $event): void
    {
        abort_unless($this->entitlements->hasFeature($event, Entitlements::SEATING), 403, trans('seating.locked.title'));
    }
}
