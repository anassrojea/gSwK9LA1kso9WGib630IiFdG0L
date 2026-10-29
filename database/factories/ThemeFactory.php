<?php

namespace Database\Factories;

use App\Models\Theme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Theme>
 */
class ThemeFactory extends Factory
{
    public function definition(): array
    {
        $id = fake()->unique()->slug(2);

        return [
            'id' => $id,
            'name_ar' => 'ثيم '.$id,
            'name_en' => ucfirst($id),
            'tier' => 'free',
            'tokens' => ['colors' => [
                'bg' => fake()->hexColor(), 'ink' => fake()->hexColor(), 'accent' => fake()->hexColor(),
                'soft' => fake()->hexColor(), 'gold' => fake()->hexColor(),
            ], 'radius' => 8],
            'fonts' => ['display' => 'Amiri', 'body' => 'Amiri', 'label' => 'Almarai'],
            'active' => true,
        ];
    }
}
