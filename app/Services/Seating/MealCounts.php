<?php

namespace App\Services\Seating;

use App\Models\Event;
use Illuminate\Support\Facades\DB;

/**
 * Meal counts for the kitchen (plan sections 4.2 and 14.8): adults and kids
 * per section (men / women / not set) from confirmed RSVPs, plus the meal
 * choices guests picked (rsvps.extras.meal), counted in people.
 */
class MealCounts
{
    /**
     * @return array{sections: array<string, array{families: int, people: int, adults: int, kids: int}>, total: array{families: int, people: int, adults: int, kids: int}, meals: list<array{meal: string, people: int}>}
     */
    public function for(Event $event): array
    {
        $rows = DB::table('rsvps')
            ->join('households', 'households.id', '=', 'rsvps.household_id')
            ->where('households.event_id', $event->getKey())
            ->where('rsvps.attending', true)
            ->orderBy('households.created_at')
            ->orderBy('households.id')
            ->get(['households.section', 'rsvps.people_count', 'rsvps.kids_count', 'rsvps.extras']);

        $empty = ['families' => 0, 'people' => 0, 'adults' => 0, 'kids' => 0];
        $sections = ['men' => $empty, 'women' => $empty, 'none' => $empty];
        $total = $empty;
        $meals = [];

        foreach ($rows as $row) {
            $key = in_array($row->section, SeatingPlan::SECTIONS, true) ? $row->section : 'none';
            $people = (int) $row->people_count;
            $kids = min($people, (int) $row->kids_count);
            foreach ([&$sections[$key], &$total] as &$bucket) {
                $bucket['families']++;
                $bucket['people'] += $people;
                $bucket['kids'] += $kids;
                $bucket['adults'] += $people - $kids;
            }
            unset($bucket);

            $meal = json_decode((string) $row->extras, true)['meal'] ?? null;
            if (is_string($meal) && trim($meal) !== '') {
                $meals[trim($meal)] = ($meals[trim($meal)] ?? 0) + $people;
            }
        }

        arsort($meals);

        return [
            'sections' => $sections,
            'total' => $total,
            'meals' => array_map(fn ($meal, $people) => ['meal' => (string) $meal, 'people' => $people], array_keys($meals), $meals),
        ];
    }
}
