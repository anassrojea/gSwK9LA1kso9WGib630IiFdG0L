<?php

namespace Database\Factories;

use App\Models\Opening;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Opening>
 */
class OpeningFactory extends Factory
{
    public function definition(): array
    {
        $id = fake()->unique()->slug(2);

        return [
            'id' => $id,
            'name_ar' => 'افتتاحية '.$id,
            'name_en' => ucfirst($id),
            'tier' => 'premium',
            'module_path' => "resources/js/openings/{$id}.js",
            'active' => true,
        ];
    }
}
