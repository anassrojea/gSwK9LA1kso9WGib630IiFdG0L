<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Household;
use App\Models\Reminder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reminder>
 */
class ReminderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'household_id' => fn (array $a) => Household::factory()->state(['event_id' => $a['event_id']]),
            'channel' => 'whatsapp',
            'status' => 'queued',
        ];
    }
}
