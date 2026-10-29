<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'occasion' => 'wedding',
            'title' => 'زفاف '.fake()->firstName().' و'.fake()->firstName(),
            'starts_at' => now()->addMonths(2)->setTime(20, 0),
            'timezone' => 'Asia/Riyadh',
            'venue_name' => 'قاعة '.fake()->word(),
            'address' => fake()->address(),
            'status' => 'active',
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => 'draft']);
    }

    public function forOrganization(?Organization $organization = null): static
    {
        return $this->state(['org_id' => $organization ?? Organization::factory()]);
    }
}
