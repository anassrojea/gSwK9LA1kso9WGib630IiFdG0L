<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Invitation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'config' => static::sampleConfig(),
            'config_version' => 1,
            'published_at' => now(),
        ];
    }

    public function opening(string $template): static
    {
        return $this->state(['config' => static::sampleConfig($template)]);
    }

    /**
     * A valid config taken from a template in templates/invitations/.
     */
    public static function sampleConfig(string $template = 'warda-rose'): array
    {
        $json = json_decode(file_get_contents(base_path("templates/invitations/{$template}.json")), true);

        return $json['default_config'];
    }
}
