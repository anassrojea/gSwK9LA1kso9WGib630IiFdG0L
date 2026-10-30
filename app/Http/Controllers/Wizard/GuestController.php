<?php

namespace App\Http\Controllers\Wizard;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Household;
use App\Services\Dashboard\PhoneNumber;
use App\Services\Digits;
use App\Services\Entitlements;
use App\Services\Wizard\GuestListParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Wizard step 4: households from a pasted list, plus add / edit / delete.
 * Every write checks the plan's soft guest limit (plan section 16).
 */
class GuestController extends Controller
{
    public const MAX_LINES = 1000;

    public function __construct(private Entitlements $entitlements) {}

    /**
     * POST /events/{event}/guests/import {text} — or {guests: [{name, seats, phone?}]},
     * the rows the host reviewed after "Clean with AI" (AiController::clean).
     */
    public function import(Request $request, Event $event): RedirectResponse
    {
        if ($request->has('guests')) {
            $guests = $this->reviewedRows($request);
        } else {
            $request->validate(['text' => ['required', 'string', 'max:50000']]);
            $guests = GuestListParser::parse($request->string('text')->toString());
        }

        if ($guests === []) {
            throw ValidationException::withMessages(['text' => trans('app.wizard.guests.nothing_found')]);
        }
        if (count($guests) > self::MAX_LINES) {
            throw ValidationException::withMessages(['text' => trans('app.wizard.guests.too_many_lines', ['max' => Digits::localize(self::MAX_LINES)])]);
        }

        $this->ensureRoom($event, array_sum(array_column($guests, 'seats')), 'text');

        DB::transaction(function () use ($event, $guests) {
            foreach ($guests as $guest) {
                $event->households()->create(['display_name' => $guest['name'], 'seats_allowed' => $guest['seats'], 'phone' => $guest['phone'] ?? null]);
            }
        });

        return back()->with('status', trans_choice('app.wizard.guests.imported', count($guests), ['count' => Digits::localize(count($guests))]));
    }

    /**
     * Reviewed rows are validated like any input: never trusted because a model wrote them.
     *
     * @return list<array{name: string, seats: int, phone: ?string}>
     */
    private function reviewedRows(Request $request): array
    {
        $request->validate([
            'guests' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'guests.*.name' => ['required', 'string', 'max:'.GuestListParser::MAX_NAME],
            'guests.*.seats' => ['required', 'integer', 'min:1', 'max:'.GuestListParser::MAX_SEATS],
            'guests.*.phone' => ['nullable', 'string', 'max:32', function ($attribute, $value, $fail) {
                if (! PhoneNumber::valid($value)) {
                    $fail(trans('app.dashboard.csv.errors.phone'));
                }
            }],
        ]);

        return collect($request->input('guests'))
            ->map(fn (array $g) => [
                'name' => trim(preg_replace('/\s+/u', ' ', (string) $g['name'])),
                'seats' => (int) $g['seats'],
                'phone' => PhoneNumber::normalize($g['phone'] ?? null),
            ])
            ->filter(fn (array $g) => $g['name'] !== '')
            ->values()
            ->all();
    }

    /**
     * POST /events/{event}/guests {name, seats}
     */
    public function store(Request $request, Event $event): RedirectResponse
    {
        $data = $this->validated($request);
        $this->ensureRoom($event, $data['seats'], 'name');

        $event->households()->create(['display_name' => $data['name'], 'seats_allowed' => $data['seats']]);

        return back();
    }

    /**
     * PATCH /households/{household} {name, seats}
     */
    public function update(Request $request, Household $household): RedirectResponse
    {
        $data = $this->validated($request);
        $adding = $data['seats'] - $household->seats_allowed;

        if ($adding > 0) {
            $this->ensureRoom($household->event, $adding, 'seats');
        }

        $household->update(['display_name' => $data['name'], 'seats_allowed' => $data['seats']]);

        return back();
    }

    /**
     * DELETE /households/{household}
     */
    public function destroy(Household $household): RedirectResponse
    {
        $household->delete();

        return back();
    }

    /**
     * @return array{name: string, seats: int}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:'.GuestListParser::MAX_NAME],
            'seats' => ['required', 'integer', 'min:1', 'max:'.GuestListParser::MAX_SEATS],
        ]);

        return ['name' => trim($data['name']), 'seats' => (int) $data['seats']];
    }

    private function ensureRoom(Event $event, int $adding, string $field): void
    {
        if ($this->entitlements->canAddGuests($event, $adding)) {
            return;
        }

        $limit = (int) $this->entitlements->limit($event, 'guests');
        $left = max(0, $limit - (int) $event->households()->sum('seats_allowed'));

        throw ValidationException::withMessages([
            $field => trans('app.wizard.guests.limit', ['limit' => Digits::localize($limit), 'left' => Digits::localize($left)]),
        ]);
    }
}
