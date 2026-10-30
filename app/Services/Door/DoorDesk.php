<?php

namespace App\Services\Door;

use App\Events\GuestCheckedIn;
use App\Models\Checkin;
use App\Models\Event;
use App\Models\Household;
use App\Services\Seating\HallLayoutData;
use App\Services\Seating\SeatingPlan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The door app (plan sections 9 and 14.8): look a family up by the token in
 * their QR code (or by name), check them in, and count arrivals per section.
 *
 * A check-in that needs a second look (already inside, more people than
 * seats, fewer or more than they said, no RSVP) is not recorded until the
 * staff confirm it. Each scan carries an id made on the device, so a retried
 * request or a re-synced offline scan is recorded once.
 */
class DoorDesk
{
    public const STATUS_CHECKED_IN = 'checked_in';

    public const STATUS_NEEDS_CONFIRMATION = 'needs_confirmation';

    public const STATUS_ALREADY_RECORDED = 'already_recorded';

    /** Warnings that need the staff to confirm before the entry is recorded. */
    public const WARNINGS = ['already_in', 'over_limit', 'not_attending', 'partial', 'extra'];

    /**
     * Every family of the event in door format, for name search and offline lookups.
     *
     * @return list<array<string, mixed>>
     */
    public function guests(Event $event): array
    {
        return $this->households($event)->map(fn (Household $h) => $this->present($event, $h))->values()->all();
    }

    public function find(Event $event, string $token): ?Household
    {
        return $this->households($event, $token)->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Event $event, Household $h): array
    {
        $rsvp = $h->rsvp;
        $status = $rsvp === null ? 'no_reply' : ($rsvp->attending ? 'attending' : 'declined');
        $table = $h->seatAssignments->first()?->table_id;
        $first = $h->checkins->first();
        $timezone = $event->timezone ?: config('app.timezone');

        return [
            'id' => $h->id,
            'token' => $h->token,
            'name' => $h->display_name,
            'seats' => $h->seats_allowed,
            'status' => $status,
            'people' => $status === 'attending' ? $rsvp->people_count : null,
            'kids' => $status === 'attending' ? $rsvp->kids_count : null,
            'section' => in_array($h->section, SeatingPlan::SECTIONS, true) ? $h->section : null,
            'table' => $table ? HallLayoutData::tableNumber($table) : null,
            'in' => (int) $h->checkins->sum('people_in'),
            'checkins' => $h->checkins->map(fn (Checkin $c) => [
                'at' => $c->scanned_at->toIso8601String(),
                'time' => $c->scanned_at->setTimezone($timezone)->format('H:i'),
                'people_in' => $c->people_in,
                'by' => $c->scanner?->name ?? ($c->doorPass ? trans('door.by_pass', ['label' => $c->doorPass->label]) : null),
            ])->all(),
            'first_in_at' => $first?->scanned_at->toIso8601String(),
        ];
    }

    /**
     * What the door should know before letting $peopleIn people of this family in.
     *
     * @param  array<string, mixed>  $guest  present() output
     * @return list<array<string, mixed>>
     */
    public static function warnings(array $guest, int $peopleIn): array
    {
        $warnings = [];
        $total = $guest['in'] + $peopleIn;

        if ($guest['in'] > 0) {
            $first = $guest['checkins'][0] ?? null;
            $warnings[] = ['type' => 'already_in', 'people_in' => $guest['in'], 'at' => $first['at'] ?? null, 'time' => $first['time'] ?? null, 'by' => $first['by'] ?? null];
        }
        if ($total > $guest['seats']) {
            $warnings[] = ['type' => 'over_limit', 'allowed' => $guest['seats'], 'total' => $total];
        }
        if ($guest['status'] !== 'attending') {
            $warnings[] = ['type' => 'not_attending', 'status' => $guest['status']];
        } elseif ($total < $guest['people']) {
            $warnings[] = ['type' => 'partial', 'expected' => $guest['people'], 'total' => $total];
        } elseif ($total > $guest['people'] && $total <= $guest['seats']) {
            $warnings[] = ['type' => 'extra', 'expected' => $guest['people'], 'total' => $total];
        }

        return $warnings;
    }

    /** How many people a scan lets in unless the staff change it. */
    public static function suggestedPeople(array $guest): int
    {
        $expected = $guest['status'] === 'attending' ? $guest['people'] : $guest['seats'];

        return max(1, $expected - $guest['in']);
    }

    /**
     * @return array{status: string, guest: array<string, mixed>, warnings: list<array<string, mixed>>, checkin?: array<string, mixed>}
     */
    public function checkIn(Event $event, DoorActor $actor, Household $household, int $peopleIn, string $scanId, bool $confirm, ?CarbonImmutable $scannedAt = null): array
    {
        $existing = $this->byScanId($event, $scanId);
        if ($existing) {
            return $this->result(self::STATUS_ALREADY_RECORDED, $event, $household, [], $existing);
        }

        try {
            [$status, $warnings, $checkin] = DB::transaction(function () use ($event, $actor, $household, $peopleIn, $scanId, $confirm, $scannedAt) {
                // serialize two door phones scanning the same family at the same moment
                Household::query()->whereKey($household->getKey())->lockForUpdate()->first();

                $guest = $this->present($event, $this->find($event, $household->token));
                $warnings = static::warnings($guest, $peopleIn);
                if ($warnings !== [] && ! $confirm) {
                    return [self::STATUS_NEEDS_CONFIRMATION, $warnings, null];
                }

                $checkin = Checkin::query()->create([
                    'event_id' => $event->getKey(),
                    'household_id' => $household->getKey(),
                    'scanned_by' => $actor->user?->getKey(),
                    'door_pass_id' => $actor->pass?->getKey(),
                    'scan_id' => $scanId,
                    'people_in' => $peopleIn,
                    'scanned_at' => $scannedAt ?? now(),
                ]);

                return [self::STATUS_CHECKED_IN, $warnings, $checkin];
            });
        } catch (QueryException $e) {
            // the same scan id raced in from another request: it is recorded, report it
            $existing = $this->byScanId($event, $scanId) ?? throw $e;

            return $this->result(self::STATUS_ALREADY_RECORDED, $event, $household, [], $existing);
        }

        if ($checkin) {
            $actor->pass?->forceFill(['last_used_at' => now()])->save();
            rescue(fn () => GuestCheckedIn::dispatch($checkin));
        }

        return $this->result($status, $event, $household, $warnings, $checkin);
    }

    /**
     * Offline scans sent once the network is back. A scan is recorded unless
     * the family was let in meanwhile on another device (more people inside
     * than the scanning device knew of) or it now breaks a rule that needs a
     * confirmation the device never gave; those come back as conflicts for
     * the staff to look at, nothing is recorded for them.
     *
     * @param  list<array{scan_id: string, token: string, people_in: int, scanned_at?: ?string, confirm?: bool, known_in?: int}>  $scans
     * @return list<array<string, mixed>>
     */
    public function sync(Event $event, DoorActor $actor, array $scans): array
    {
        $results = [];
        foreach ($scans as $scan) {
            $row = ['scan_id' => $scan['scan_id'], 'token' => $scan['token']];

            if ($existing = $this->byScanId($event, $scan['scan_id'])) {
                $results[] = $row + ['status' => 'synced', 'checkin_id' => $existing->id];

                continue;
            }

            $household = $this->find($event, $scan['token']);
            if (! $household) {
                $results[] = $row + ['status' => 'invalid'];

                continue;
            }

            $guest = $this->present($event, $household);
            $knownIn = (int) ($scan['known_in'] ?? 0);
            if ($guest['in'] > $knownIn) {
                $results[] = $row + ['status' => 'conflict', 'reason' => 'checked_in_elsewhere', 'guest' => $guest, 'warnings' => static::warnings($guest, (int) $scan['people_in'])];

                continue;
            }

            $at = isset($scan['scanned_at']) ? rescue(fn () => CarbonImmutable::parse($scan['scanned_at']), null, false) : null;
            $at = ($at && $at->isPast() && $at->greaterThan(now()->subDay()) ? $at : now()->toImmutable())->setTimezone(config('app.timezone'));

            $outcome = $this->checkIn($event, $actor, $household, (int) $scan['people_in'], $scan['scan_id'], (bool) ($scan['confirm'] ?? false), $at);
            $results[] = $row + match ($outcome['status']) {
                self::STATUS_NEEDS_CONFIRMATION => ['status' => 'conflict', 'reason' => 'needs_confirmation', 'guest' => $outcome['guest'], 'warnings' => $outcome['warnings']],
                default => ['status' => 'synced', 'checkin_id' => $outcome['checkin']['id'] ?? null],
            };
        }

        return $results;
    }

    /**
     * Arrivals against what was expected and what the hall holds, per section.
     *
     * @return array{sections: array<string, array{expected: int, arrived: int, families: int, capacity: int}>, total: array{expected: int, arrived: int, families: int, capacity: int}}
     */
    public function stats(Event $event): array
    {
        $rows = DB::table('households')
            ->leftJoin('rsvps', 'rsvps.household_id', '=', 'households.id')
            ->where('households.event_id', $event->getKey())
            ->groupBy('households.section')
            ->selectRaw('households.section, sum(case when rsvps.attending then rsvps.people_count else 0 end) as expected, sum(households.seats_allowed) as seats')
            ->get();

        $arrivals = DB::table('checkins')
            ->join('households', 'households.id', '=', 'checkins.household_id')
            ->where('checkins.event_id', $event->getKey())
            ->groupBy('households.section')
            ->selectRaw('households.section, coalesce(sum(checkins.people_in), 0) as arrived, count(distinct checkins.household_id) as families')
            ->get();

        $key = fn (?string $section) => in_array($section, SeatingPlan::SECTIONS, true) ? $section : 'none';
        $empty = ['expected' => 0, 'arrived' => 0, 'families' => 0, 'capacity' => 0];
        $sections = ['men' => $empty, 'women' => $empty, 'none' => $empty];

        $layout = $event->hallLayout?->layout;
        $hallSeats = [];
        foreach ($layout['tables'] ?? [] as $table) {
            $s = $key($table['section'] ?? null);
            $hallSeats[$s] = ($hallSeats[$s] ?? 0) + (int) $table['seats'];
        }

        foreach ($rows as $row) {
            $s = $key($row->section);
            $sections[$s]['expected'] += (int) $row->expected;
            if (! $layout) {
                $sections[$s]['capacity'] += (int) $row->seats;
            }
        }
        foreach ($hallSeats as $s => $seats) {
            $sections[$s]['capacity'] += $seats;
        }
        foreach ($arrivals as $row) {
            $s = $key($row->section);
            $sections[$s]['arrived'] += (int) $row->arrived;
            $sections[$s]['families'] += (int) $row->families;
        }

        $total = $empty;
        foreach ($sections as $section) {
            foreach ($total as $k => $v) {
                $total[$k] = $v + $section[$k];
            }
        }

        return ['sections' => $sections, 'total' => $total];
    }

    private function byScanId(Event $event, string $scanId): ?Checkin
    {
        return Checkin::query()->where('event_id', $event->getKey())->where('scan_id', $scanId)->first();
    }

    /**
     * @return array{status: string, guest: array<string, mixed>, warnings: list<array<string, mixed>>, checkin?: array<string, mixed>}
     */
    private function result(string $status, Event $event, Household $household, array $warnings, ?Checkin $checkin): array
    {
        $out = [
            'status' => $status,
            'guest' => $this->present($event, $this->find($event, $household->token)),
            'warnings' => $warnings,
        ];
        if ($checkin) {
            $out['checkin'] = ['id' => $checkin->id, 'people_in' => $checkin->people_in, 'at' => $checkin->scanned_at->toIso8601String()];
        }

        return $out;
    }

    /**
     * @return Collection<int, Household>
     */
    private function households(Event $event, ?string $token = null): Collection
    {
        return Household::query()
            ->where('event_id', $event->getKey())
            ->when($token !== null, fn ($q) => $q->where('token', $token))
            ->with([
                'rsvp',
                'seatAssignments' => fn ($q) => $q->select('id', 'household_id', 'table_id')->orderBy('seat_index'),
                'checkins' => fn ($q) => $q->with(['scanner:id,name', 'doorPass:id,label'])->orderBy('scanned_at'),
            ])
            ->orderBy('display_name')
            ->orderBy('id')
            ->get();
    }
}
