<?php

namespace Database\Factories;

use App\Models\DiscountCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiscountCode>
 */
class DiscountCodeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'CODE'.fake()->unique()->numberBetween(100, 99999),
            'percent' => 20,
            'amounts' => null,
            'applies_to_plans' => true,
            'applies_to_addons' => true,
            'active' => true,
        ];
    }

    /**
     * @param  array<string, int>  $amounts  minor units per currency
     */
    public function fixed(array $amounts): static
    {
        return $this->state(['percent' => null, 'amounts' => $amounts]);
    }
}
