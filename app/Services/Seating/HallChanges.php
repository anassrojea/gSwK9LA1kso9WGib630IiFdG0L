<?php

namespace App\Services\Seating;

use App\Jobs\UpdateWalletPasses;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\HallLayout;
use App\Models\SeatAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changing a hall that events already use (phase 8B). A venue's hall is
 * shared by every event in it, so removing a table or taking chairs away
 * frees the seats of guests sitting there. The designer first asks how many
 * (impact), says "N events use this hall; M seats will be unassigned", and
 * the save then:
 *
 *  1. follows renamed tables (renumbering T7 → T3 keeps its guests),
 *  2. unassigns the seats the new hall no longer has, in every event,
 *  3. records it in audit_log, for the hall and for each event that lost seats;
 *     the event's seating planner shows the latest such entry (see notice())
 *     until the host saves the seats again.
 */
class HallChanges
{
    public const ACTION_HALL = 'hall.updated';

    public const ACTION_EVENT = 'hall.seats_unassigned';

    /**
     * Only renames of tables the old hall has, to tables the new one has, each target once.
     *
     * @param  array<mixed>  $renames  old id => new id
     * @return array<string, string>
     */
    public static function cleanRenames(array $old, array $new, array $renames): array
    {
        $before = HallLayoutData::tablesById($old);
        $after = HallLayoutData::tablesById($new);
        $out = [];
        $targets = [];
        foreach ($renames as $from => $to) {
            $from = (string) $from;
            if (! is_string($to) || $from === $to || ! isset($before[$from], $after[$to]) || isset($targets[$to])) {
                continue;
            }
            $targets[$to] = true;
            $out[$from] = $to;
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $renames
     * @return array{events: int, seats: int, by_event: array<string, int>}
     */
    public function impact(HallLayout $hall, array $layout, array $renames = []): array
    {
        $tables = HallLayoutData::tablesById($layout);
        $eventIds = Event::query()->where('hall_layout_id', $hall->getKey())->pluck('id');

        $byEvent = [];
        SeatAssignment::query()
            ->whereIn('event_id', $eventIds)
            ->get(['event_id', 'table_id', 'seat_index'])
            ->each(function (SeatAssignment $a) use ($tables, $renames, &$byEvent) {
                $table = $tables[$renames[$a->table_id] ?? $a->table_id] ?? null;
                if ($table === null || $a->seat_index >= (int) $table['seats']) {
                    $byEvent[$a->event_id] = ($byEvent[$a->event_id] ?? 0) + 1;
                }
            });

        return ['events' => $eventIds->count(), 'seats' => array_sum($byEvent), 'by_event' => $byEvent];
    }

    /**
     * Save the new layout of a hall and fix every event that uses it.
     *
     * @param  array<string, string>  $renames  old id => new id (already cleaned)
     * @return array{events: int, seats: int, by_event: array<string, int>}
     *
     * @throws ValidationException when seats would be unassigned and the change was not confirmed
     */
    public function save(HallLayout $hall, string $name, array $layout, array $renames, bool $confirmed, ?User $actor): array
    {
        return DB::transaction(function () use ($hall, $name, $layout, $renames, $confirmed, $actor) {
            $hall = HallLayout::query()->lockForUpdate()->findOrFail($hall->getKey());
            $impact = $this->impact($hall, $layout, $renames);

            if ($impact['seats'] > 0 && ! $confirmed) {
                throw ValidationException::withMessages([
                    'hall' => trans('halls.save.confirm_needed', ['count' => $impact['events'], 'seats' => $impact['seats']]),
                ]);
            }

            $eventIds = Event::query()->where('hall_layout_id', $hall->getKey())->pluck('id')->all();
            if ($eventIds !== []) {
                $this->unassign($eventIds, $layout, $renames);
                $this->rename($eventIds, $renames);
            }

            $old = $hall->layout;
            $hall->update(['name' => $name, 'layout' => $layout]);

            if ($eventIds !== [] && ($impact['seats'] > 0 || $renames !== [] || HallLayoutData::lostSeats($old, $layout) !== [])) {
                AuditLog::record(self::ACTION_HALL, $hall, [
                    'events' => $impact['events'],
                    'seats_unassigned' => $impact['seats'],
                    'renamed' => count($renames),
                ], $actor);
            }
            foreach ($impact['by_event'] as $eventId => $count) {
                AuditLog::record(self::ACTION_EVENT, Event::query()->findOrFail($eventId), [
                    'hall_id' => $hall->getKey(),
                    'hall' => $name,
                    'count' => $count,
                ], $actor);
            }
            // wallet passes show the table: refresh those of every event whose seats moved
            foreach ($renames !== [] ? $eventIds : array_keys($impact['by_event']) as $eventId) {
                UpdateWalletPasses::dispatch((string) $eventId, null, 'table');
            }

            return $impact;
        });
    }

    /**
     * The planner's note when the venue changed the hall after the host last
     * saved the seats: {at, count}, else null.
     *
     * @return array{at: string, count: int}|null
     */
    public static function notice(Event $event): ?array
    {
        $entry = AuditLog::query()
            ->where('action', self::ACTION_EVENT)
            ->where('entity', 'Event')
            ->where('entity_id', (string) $event->getKey())
            ->where('at', '>', now()->subDays(60))
            ->latest('at')
            ->first();
        if (! $entry) {
            return null;
        }

        $savedAt = SeatAssignment::query()->where('event_id', $event->getKey())->max('updated_at');
        if ($savedAt !== null && CarbonImmutable::parse($savedAt)->greaterThan($entry->at)) {
            return null;
        }

        return ['at' => $entry->at->toIso8601String(), 'count' => (int) ($entry->data['count'] ?? 0)];
    }

    /**
     * @param  list<string>  $eventIds
     * @param  array<string, string>  $renames
     */
    private function unassign(array $eventIds, array $layout, array $renames): void
    {
        $tables = HallLayoutData::tablesById($layout);
        $stale = SeatAssignment::query()
            ->whereIn('event_id', $eventIds)
            ->get(['id', 'table_id', 'seat_index'])
            ->filter(function (SeatAssignment $a) use ($tables, $renames) {
                $table = $tables[$renames[$a->table_id] ?? $a->table_id] ?? null;

                return $table === null || $a->seat_index >= (int) $table['seats'];
            })
            ->pluck('id');

        foreach ($stale->chunk(500) as $chunk) {
            SeatAssignment::query()->whereIn('id', $chunk)->delete();
        }
    }

    /**
     * Move seats to their tables' new ids in two steps (through a placeholder
     * id), so swapping two numbers never trips the (event, table, seat) unique key.
     *
     * @param  list<string>  $eventIds
     * @param  array<string, string>  $renames
     */
    private function rename(array $eventIds, array $renames): void
    {
        $i = 0;
        $placeholders = [];
        foreach ($renames as $from => $to) {
            $tmp = '~'.(++$i);
            $placeholders[$tmp] = $to;
            // toBase(): no updated_at, so the planner still sees the change as newer than the host's last save
            SeatAssignment::query()->whereIn('event_id', $eventIds)->where('table_id', $from)->toBase()->update(['table_id' => $tmp]);
        }
        foreach ($placeholders as $tmp => $to) {
            SeatAssignment::query()->whereIn('event_id', $eventIds)->where('table_id', $tmp)->toBase()->update(['table_id' => $to]);
        }
    }
}
