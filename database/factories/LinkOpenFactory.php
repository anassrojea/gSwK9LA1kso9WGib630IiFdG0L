<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\LinkOpen;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LinkOpen>
 */
class LinkOpenFactory extends Factory
{
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'opened_at' => now(),
            'ua_hash' => hash('sha256', fake()->userAgent()),
        ];
    }
}
