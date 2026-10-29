<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Rose;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rose>
 */
class RoseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'count' => fake()->numberBetween(0, 200),
        ];
    }
}
