<?php

namespace Database\Factories;

use App\Models\Entitlement;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Entitlement>
 */
class EntitlementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'feature' => 'seating',
            'until' => null,
        ];
    }
}
