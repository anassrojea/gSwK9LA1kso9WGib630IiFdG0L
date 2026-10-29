<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Rsvp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rsvp>
 */
class RsvpFactory extends Factory
{
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'attending' => true,
            'people_count' => 1,
            'kids_count' => 0,
            'message' => 'ألف مبروك',
            'responded_at' => now(),
        ];
    }
}
