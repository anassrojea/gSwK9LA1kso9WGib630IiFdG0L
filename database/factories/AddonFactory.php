<?php

namespace Database\Factories;

use App\Models\Addon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Addon>
 */
class AddonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => fake()->unique()->slug(2),
            'name' => fake()->word(),
            'price_cents' => 1000,
            'currency' => 'USD',
            'active' => true,
        ];
    }
}
