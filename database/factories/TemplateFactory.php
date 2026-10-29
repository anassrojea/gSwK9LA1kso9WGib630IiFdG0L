<?php

namespace Database\Factories;

use App\Models\Opening;
use App\Models\Template;
use App\Models\Theme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Template>
 */
class TemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => fake()->unique()->slug(2),
            'occasion' => 'wedding',
            'name_ar' => 'قالب',
            'name_en' => 'Template',
            'tier' => 'free',
            'opening_id' => Opening::factory(),
            'theme_id' => Theme::factory(),
            'default_config' => InvitationFactory::sampleConfig(),
            'sort' => 0,
            'active' => true,
        ];
    }
}
