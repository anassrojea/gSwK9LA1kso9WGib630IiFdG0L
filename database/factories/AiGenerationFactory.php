<?php

namespace Database\Factories;

use App\Models\AiGeneration;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiGeneration>
 */
class AiGenerationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'kind' => 'story',
            'input' => ['answers' => ['how did you meet?' => 'في المكتبة']],
            'output' => 'كانت البداية في المكتبة…',
            'model' => 'claude-sonnet-5-5',
            'cost_cents' => 2,
        ];
    }
}
