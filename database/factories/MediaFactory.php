<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'kind' => 'photo',
            'storage_key' => 'events/'.fake()->uuid().'.webp',
            'width' => 2560,
            'height' => 1706,
            'bytes' => fake()->numberBetween(200_000, 900_000),
            'hidden' => false,
        ];
    }
}
