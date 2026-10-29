<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Household>
 */
class HouseholdFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'display_name' => 'عائلة '.fake()->name(),
            'phone' => fake()->e164PhoneNumber(),
            'seats_allowed' => fake()->numberBetween(1, 5),
            'tags' => [],
        ];
    }

    public function seats(int $seats): static
    {
        return $this->state(['seats_allowed' => $seats]);
    }
}
