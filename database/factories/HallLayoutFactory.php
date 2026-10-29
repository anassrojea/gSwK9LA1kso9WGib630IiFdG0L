<?php

namespace Database\Factories;

use App\Models\HallLayout;
use App\Models\User;
use App\Services\HallGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HallLayout>
 */
class HallLayoutFactory extends Factory
{
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'name' => 'قاعة '.fake()->word(),
            'layout' => app(HallGenerator::class)->generate(20, 8),
        ];
    }
}
