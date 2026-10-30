<?php

namespace Tests\Support;

use App\Models\Event;
use App\Models\HallLayout;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\Plan;
use App\Models\Rsvp;
use App\Services\HallGenerator;
use Database\Seeders\PlanSeeder;

/**
 * A Signature event (seating, QR check-in, live day) with a hall and
 * confirmed families, for the event-day tests (phase 7A).
 */
class SeatingFixtures
{
    /**
     * @param  list<array{0: string, 1: int, 2?: int, 3?: ?string}>  $families  [name, people, kids, section]
     * @return array{0: Event, 1: array<string, Household>}
     */
    public static function event(array $families = [], int $tables = 4, int $chairs = 4, string $layout = 'split', string $plan = 'signature', array $attributes = []): array
    {
        if (! Plan::query()->exists()) {
            app(PlanSeeder::class)->run();
        }

        $event = Event::factory()->create($attributes + ['plan_id' => $plan, 'guest_limit' => 500]);
        Invitation::factory()->for($event)->create();

        $hall = HallLayout::query()->create([
            'owner_id' => $event->owner_id,
            'name' => 'Test hall',
            'layout' => app(HallGenerator::class)->generate($tables, $chairs, $layout, name: 'Test hall'),
        ]);
        $event->update(['hall_layout_id' => $hall->id]);

        $out = [];
        foreach ($families as $i => $f) {
            [$name, $people] = $f;
            $household = Household::factory()->for($event)->create([
                'display_name' => $name,
                'seats_allowed' => max($people, 1) + 1,
                'section' => $f[3] ?? null,
                'created_at' => now()->subMinutes(100 - $i),
            ]);
            if ($people > 0) {
                Rsvp::factory()->create(['household_id' => $household->id, 'attending' => true, 'people_count' => $people, 'kids_count' => $f[2] ?? 0]);
            }
            $out[$name] = $household;
        }

        return [$event->fresh(), $out];
    }
}
