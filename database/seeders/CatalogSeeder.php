<?php

namespace Database\Seeders;

use App\Models\Opening;
use App\Models\Template;
use App\Models\Theme;
use App\Services\InvitationConfig\InvitationConfigValidator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Openings, themes and templates. Themes and templates are data, not code:
 * they live as JSON in templates/ and are synced into the database here.
 */
class CatalogSeeder extends Seeder
{
    public function run(InvitationConfigValidator $validator): void
    {
        $openings = [
            // id => [name_ar, name_en, tier, ported to resources/js/openings/]
            'envelope' => ['الظرف', 'Envelope', 'free', true], // the Free plan's opening (plan section 13.1)
            'rose' => ['الوردة', 'Rose', 'premium', true],
            'ringbox' => ['علبة الخاتم', 'Ring box', 'premium', true],
            'doors' => ['الأبواب المقوّسة', 'Arched doors', 'premium', true],
            'gift' => ['صندوق الهدية', 'Gift box', 'premium', true],
            'diploma' => ['الشهادة', 'Diploma', 'premium', true],
        ];

        foreach ($openings as $id => [$ar, $en, $tier, $active]) {
            Opening::query()->updateOrCreate(['id' => $id], [
                'name_ar' => $ar,
                'name_en' => $en,
                'tier' => $tier,
                'module_path' => "resources/js/openings/{$id}.js",
                'active' => $active,
            ]);
        }

        foreach (File::glob(base_path('templates/themes/*.json')) as $file) {
            $theme = json_decode(File::get($file), true, flags: JSON_THROW_ON_ERROR);
            Theme::query()->updateOrCreate(['id' => $theme['id']], $theme + ['active' => true]);
        }

        foreach (File::glob(base_path('templates/invitations/*.json')) as $file) {
            $template = json_decode(File::get($file), true, flags: JSON_THROW_ON_ERROR);
            $validator->assertValid($template['default_config'], basename($file));
            Template::query()->updateOrCreate(['id' => $template['id']], $template + ['active' => true]);
        }
    }
}
