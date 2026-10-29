<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => fake()->unique()->slug(2),
            'name' => fake()->word(),
            'price_cents' => 2900,
            'currency' => 'USD',
            'interval' => null,
            'limits' => ['guests' => 300, 'features' => ['rsvp']],
            'active' => true,
        ];
    }

    /**
     * @param  list<string>  $features
     */
    public function withFeatures(array $features, ?int $guests = 300): static
    {
        return $this->state(['limits' => ['guests' => $guests, 'features' => $features]]);
    }
}
