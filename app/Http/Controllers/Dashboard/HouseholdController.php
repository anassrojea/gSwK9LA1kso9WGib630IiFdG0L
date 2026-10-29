<?php

namespace App\Http\Controllers\Dashboard;

use App\Events\GuestCheckedIn;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksGuestLimit;
use App\Models\Checkin;
use App\Models\Household;
use App\Services\Dashboard\GuestCsv;
use App\Services\Dashboard\PhoneNumber;
use App\Services\Digits;
use App\Services\Entitlements;
use App\Services\Wizard\GuestListParser;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Row actions of the host dashboard: edit, delete, mark arrived.
 */
class HouseholdController extends Controller
{
    use ChecksGuestLimit;

    public function __construct(private Entitlements $entitlements) {}

    /**
     * PATCH /dashboard/households/{household} {name, seats, phone, tags}
     */
    public function update(Request $request, Household $household): RedirectResponse
    {
        Gate::authorize('update', $household);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:'.GuestListParser::MAX_NAME],
            'seats' => ['required', 'integer', 'min:1', 'max:'.GuestListParser::MAX_SEATS],
            'phone' => ['nullable', 'string', 'max:40', function (string $attribute, mixed $value, \Closure $fail) {
                if (! PhoneNumber::valid(is_string($value) ? $value : null)) {
                    $fail(trans('app.dashboard.edit.phone_invalid'));
                }
            }],
            'tags' => ['nullable', 'array', 'max:'.GuestCsv::MAX_TAGS],
            'tags.*' => ['string', 'max:'.GuestCsv::MAX_TAG],
        ]);

        $seats = (int) $data['seats'];
        $rsvp = $household->rsvp;

        // the guest already said how many are coming: fewer seats than that would break their reply
        if ($rsvp?->attending && $seats < $rsvp->people_count) {
            throw ValidationException::withMessages([
                'seats' => trans('app.dashboard.edit.seats_below_reply', ['count' => Digits::localize($rsvp->people_count)]),
            ]);
        }

        $this->ensureRoom($this->entitlements, $household->event, $seats - $household->seats_allowed, 'seats');

        $household->update([
            'display_name' => trim(preg_replace('/\s+/u', ' ', $data['name']) ?? $data['name']),
            'seats_allowed' => $seats,
            'phone' => PhoneNumber::normalize($data['phone'] ?? null),
            'tags' => GuestCsv::tags($data['tags'] ?? []),
        ]);

        return back()->with('status', trans('app.dashboard.edit.saved'));
    }

    /**
     * DELETE /dashboard/households/{household}
     */
    public function destroy(Household $household): RedirectResponse
    {
        Gate::authorize('delete', $household);

        $name = $household->display_name;
        $household->delete();

        return back()->with('status', trans('app.dashboard.delete.done', ['name' => $name]));
    }

    /**
     * POST /dashboard/households/{household}/checkin {people?}: mark a family
     * as arrived. Doing it twice keeps the first arrival and warns instead.
     */
    public function checkIn(Request $request, Household $household): RedirectResponse
    {
        $event = $household->event;
        Gate::authorize('checkIn', $event);

        $data = $request->validate([
            'people' => ['nullable', 'integer', 'min:1', 'max:'.max($household->seats_allowed, 1)],
        ]);

        [$checkin, $created] = DB::transaction(function () use ($household, $data, $request) {
            // serialize two phones marking the same family at the same moment
            Household::query()->whereKey($household->getKey())->lockForUpdate()->first();

            $existing = Checkin::query()->where('household_id', $household->getKey())->orderBy('scanned_at')->first();
            if ($existing) {
                return [$existing, false];
            }

            $rsvp = $household->rsvp;
            $people = $data['people'] ?? ($rsvp?->attending ? $rsvp->people_count : $household->seats_allowed);

            return [Checkin::query()->create([
                'event_id' => $household->event_id,
                'household_id' => $household->getKey(),
                'scanned_by' => $request->user()->getKey(),
                'people_in' => max(1, (int) $people),
                'scanned_at' => now(),
            ]), true];
        });

        if (! $created) {
            $at = CarbonImmutable::parse($checkin->scanned_at)->setTimezone($event->timezone ?: config('app.timezone'))->format('H:i');

            return back()->with('warning', trans('app.dashboard.checkin.duplicate', [
                'name' => $household->display_name,
                'time' => Digits::time($at),
            ]));
        }

        rescue(fn () => GuestCheckedIn::dispatch($checkin));

        return back()->with('status', trans('app.dashboard.checkin.done', [
            'name' => $household->display_name,
            'count' => Digits::localize($checkin->people_in),
        ]));
    }
}
