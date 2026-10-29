<?php

namespace App\Filament\Resources\Templates\Pages;

use App\Filament\Resources\Catalog\CatalogActions;
use App\Filament\Resources\Templates\TemplateResource;
use App\Models\Template;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTemplate extends CreateRecord
{
    protected static string $resource = TemplateResource::class;

    /**
     * A new template starts from the first one's config, so the JSON is never empty.
     */
    protected function afterFill(): void
    {
        $sample = Template::query()->orderBy('sort')->first();
        if ($sample) {
            $this->form->fill([
                'default_config' => json_encode($sample->default_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'occasion' => $sample->occasion,
                'opening_id' => $sample->opening_id,
                'theme_id' => $sample->theme_id,
                'tier' => $sample->tier,
                'sort' => 100,
                'active' => false,
            ]);
        }
    }

    protected function handleRecordCreation(array $data): Model
    {
        return CatalogActions::createEdited(Template::class, $data, 'catalog.template_created');
    }
}
