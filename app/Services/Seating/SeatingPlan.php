<?php

namespace App\Services\Seating;

use App\Models\Event;
use App\Models\Household;
use App\Models\SeatAssignment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The seating planner's data (plan section 7.3): confirmed families with the
 * people they bring, the event's hall and who sits where. Saving replaces the
 * event's seat_assignments in one transaction after checking every seat.
 */
class SeatingPlan
{
    public const SECTIONS = ['men', 'women'];

    public function layout(Event $event): ?array
    {
        $event->loadMissing('hallLayout');

        return $event->hallLayout?->layout;
    }

    /**
     * Families who said yes, in the order they were added.
     *
     * @return list<array{id: string, name: string, section: ?string, people: int, kids: int, seats: int, meal: ?string, tags: list<string>}>
     */
    public function families(Event $event): array
    {
        return Household::query()
            ->where('event_id', $event->getKey())
            ->whereHas('rsvp', fn ($q) => $q->where('attending', true)->where('people_count', '>', 0))
            ->with('rsvp')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Household $h) => [
                'id' => $h->id,
                'name' => $h->display_name,
                'section' => in_array($h->section, self::SECTIONS, true) ? $h->section : null,
                'people' => (int) $h->rsvp->people_count,
                'kids' => (int) $h->rsvp->kids_count,
                'seats' => (int) $h->seats_allowed,
                'meal' => $h->rsvp->extras['meal'] ?? null,
                'tags' => array_values($h->tags ?? []),
            ])
            ->all();
    }

    /**
     * @return list<array{household_id: string, table_id: string, seat_index: int, person_label: ?string}>
     */
    public function assignments(Event $event): array
    {
        return SeatAssignment::query()
            ->where('event_id', $event->getKey())
            ->orderBy('table_id')
            ->orderBy('seat_index')
            ->get(['household_id', 'table_id', 'seat_index', 'person_label'])
            ->map(fn (SeatAssignment $a) => [
                'household_id' => $a->household_id,
                'table_id' => $a->table_id,
                'seat_index' => (int) $a->seat_index,
                'person_label' => $a->person_label,
            ])
            ->all();
    }

    /**
     * Replace every seat of the event. Each row: {household_id, table_id, seat_index}.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{household_id: string, table_id: string, seat_index: int, person_label: ?string}>
     *
     * @throws ValidationException
     */
    public function save(Event $event, array $rows): array
    {
        $layout = $this->layout($event) ?? throw ValidationException::withMessages(['assignments' => trans('seating.errors.no_hall')]);
        $tables = HallLayoutData::tablesById($layout);
        $families = collect($this->families($event))->keyBy('id');

        $taken = [];
        $perFamily = [];
        $errors = [];
        foreach (array_values($rows) as $i => $row) {
            $table = $tables[$row['table_id'] ?? ''] ?? null;
            $seat = $row['seat_index'] ?? null;
            $family = $families[$row['household_id'] ?? ''] ?? null;

            if ($family === null) {
                $errors["assignments.{$i}.household_id"] = trans('seating.errors.household');
            } elseif ($table === null) {
                $errors["assignments.{$i}.table_id"] = trans('seating.errors.table');
            } elseif (! is_int($seat) || $seat < 0 || $seat >= $table['seats']) {
                $errors["assignments.{$i}.seat_index"] = trans('seating.errors.seat', ['table' => HallLayoutData::tableNumber($table['id'])]);
            } elseif (isset($taken[$table['id']][$seat])) {
                $errors["assignments.{$i}.seat_index"] = trans('seating.errors.seat_taken', ['table' => HallLayoutData::tableNumber($table['id'])]);
            } else {
                $taken[$table['id']][$seat] = true;
                $perFamily[$family['id']] = ($perFamily[$family['id']] ?? 0) + 1;
                if ($perFamily[$family['id']] > $family['people']) {
                    $errors["assignments.{$i}.household_id"] = trans('seating.errors.too_many', ['name' => $family['name'], 'count' => $family['people']]);
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(array_slice($errors, 0, 20, true));
        }

        $labelled = static::label(array_values($rows), $families->all(), array_keys($tables));

        DB::transaction(function () use ($event, $labelled) {
            SeatAssignment::query()->where('event_id', $event->getKey())->delete();

            $now = now();
            foreach (array_chunk($labelled, 500) as $chunk) {
                SeatAssignment::query()->insert(array_map(fn (array $a) => $a + [
                    'id' => (string) Str::uuid(),
                    'event_id' => $event->getKey(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }
        });

        return $this->assignments($event);
    }

    /**
     * "Family name 1/3": people numbered in hall order (table order, then seat).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, array{name: string, people: int}>  $families
     * @param  list<string>  $tableOrder
     * @return list<array{household_id: string, table_id: string, seat_index: int, person_label: string}>
     */
    public static function label(array $rows, array $families, array $tableOrder): array
    {
        $position = array_flip($tableOrder);
        usort($rows, fn ($a, $b) => [$position[$a['table_id']] ?? PHP_INT_MAX, $a['seat_index']] <=> [$position[$b['table_id']] ?? PHP_INT_MAX, $b['seat_index']]);

        $counter = [];
        $out = [];
        foreach ($rows as $row) {
            $id = $row['household_id'];
            $counter[$id] = ($counter[$id] ?? 0) + 1;
            $family = $families[$id];
            $out[] = [
                'household_id' => $id,
                'table_id' => $row['table_id'],
                'seat_index' => (int) $row['seat_index'],
                'person_label' => mb_substr($family['name'], 0, 200).' '.$counter[$id].'/'.$family['people'],
            ];
        }

        return $out;
    }

    /**
     * Drop seats that no longer fit the hall (a table removed or given fewer
     * chairs). Returns how many people lost their seat.
     */
    public function pruneToLayout(Event $event, array $layout): int
    {
        $tables = HallLayoutData::tablesById($layout);
        $stale = SeatAssignment::query()
            ->where('event_id', $event->getKey())
            ->get(['id', 'table_id', 'seat_index'])
            ->filter(fn (SeatAssignment $a) => ! isset($tables[$a->table_id]) || $a->seat_index >= $tables[$a->table_id]['seats'])
            ->pluck('id');

        if ($stale->isNotEmpty()) {
            SeatAssignment::query()->whereIn('id', $stale)->delete();
        }

        return $stale->count();
    }

    /**
     * The table a household sits at (its first seat in hall order).
     */
    public static function tableOf(Household $household): ?string
    {
        return $household->seatAssignments()->orderBy('table_id')->orderBy('seat_index')->value('table_id');
    }

    /**
     * @return Collection<string, list<array{name: string, count: int}>> families (and how many of them) per table id
     */
    public function namesByTable(Event $event): Collection
    {
        return SeatAssignment::query()
            ->where('seat_assignments.event_id', $event->getKey())
            ->join('households', 'households.id', '=', 'seat_assignments.household_id')
            ->orderBy('seat_assignments.seat_index')
            ->get(['seat_assignments.table_id', 'seat_assignments.household_id', 'households.display_name'])
            ->groupBy('table_id')
            ->map(fn (Collection $rows) => $rows->groupBy('household_id')->map(fn ($r) => [
                'name' => $r->first()->display_name,
                'count' => $r->count(),
            ])->values()->all());
    }
}
