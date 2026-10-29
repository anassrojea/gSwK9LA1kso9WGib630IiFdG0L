<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Household;
use App\Models\SeatAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeatAssignment>
 */
class SeatAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'household_id' => fn (array $a) => Household::factory()->state(['event_id' => $a['event_id']]),
            'table_id' => 'T'.fake()->numberBetween(1, 20),
            'seat_index' => fake()->unique()->numberBetween(0, 11),
        ];
    }
}
