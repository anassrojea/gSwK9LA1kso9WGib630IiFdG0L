<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\GuestbookEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuestbookEntry>
 */
class GuestbookEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'kind' => 'text',
            'text' => 'ألف مبروك وبالرفاه والبنين',
        ];
    }
}
