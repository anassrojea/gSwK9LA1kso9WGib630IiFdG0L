<?php

namespace Database\Factories;

use App\Models\Checkin;
use App\Models\Event;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Checkin>
 */
class CheckinFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'household_id' => fn (array $a) => Household::factory()->state(['event_id' => $a['event_id']]),
            'people_in' => 1,
            'scanned_at' => now(),
        ];
    }
}
