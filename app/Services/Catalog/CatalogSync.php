<?php

namespace App\Services\Catalog;

use App\Models\Opening;
use App\Models\Template;
use App\Models\Theme;
use App\Services\InvitationConfig\InvitationConfigValidator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;

/**
 * Syncs the design catalog from its source files into the database (plan
 * section 6: themes and templates are data). Used by CatalogSeeder and by the
 * admin's "Import from templates/" action.
 *
 * The rule between the files and the admin:
 *   - a row missing from the database is inserted;
 *   - a row nobody edited in the admin is updated from its file;
 *   - a row saved in the admin (admin_edited_at set) is skipped, so re-seeding
 *     on deploy never overwrites admin edits. "Reset to templates/" on that row
 *     (force) takes the file's version again and clears the flag.
 */
class CatalogSync
{
    /** id => [name_ar, name_en, tier, active]; modules in resources/js/openings/{id}.js */
    public const OPENINGS = [
        'envelope' => ['الظرف', 'Envelope', 'free', true], // the Free plan's opening (plan section 13.1)
        'rose' => ['الوردة', 'Rose', 'premium', true],
        'ringbox' => ['علبة الخاتم', 'Ring box', 'premium', true],
        'doors' => ['الأبواب المقوّسة', 'Arched doors', 'premium', true],
        'gift' => ['صندوق الهدية', 'Gift box', 'premium', true],
        'diploma' => ['الشهادة', 'Diploma', 'premium', true],
    ];

    public function __construct(private InvitationConfigValidator $validator) {}

    /**
     * @param  list<string>|null  $only  ids to force back to their source (null: everything, respecting admin edits)
     * @return array{inserted: list<string>, updated: list<string>, skipped: list<string>}
     */
    public function sync(bool $force = false, ?array $only = null): array
    {
        $report = ['inserted' => [], 'updated' => [], 'skipped' => []];

        foreach ($this->openings() as $id => $attributes) {
            $this->write(Opening::class, $id, $attributes, $force, $only, $report);
        }
        foreach ($this->themes() as $id => $attributes) {
            $this->write(Theme::class, $id, $attributes, $force, $only, $report);
        }
        foreach ($this->templates() as $id => $attributes) {
            $this->write(Template::class, $id, $attributes, $force, $only, $report);
        }

        return $report;
    }

    /**
     * Put one row back to its source file, dropping the admin's edits.
     */
    public function reset(Opening|Theme|Template $record): bool
    {
        $sources = match (true) {
            $record instanceof Opening => $this->openings(),
            $record instanceof Theme => $this->themes(),
            default => $this->templates(),
        };
        $source = $sources[$record->getKey()] ?? null;

        if ($source === null) {
            return false;
        }

        $record->forceFill($source + ['admin_edited_at' => null])->save();

        return true;
    }

    public function hasSource(Opening|Theme|Template $record): bool
    {
        return match (true) {
            $record instanceof Opening => isset(self::OPENINGS[$record->getKey()]),
            $record instanceof Theme => File::exists($this->themePath($record->getKey())),
            default => File::exists($this->templatePath($record->getKey())),
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function openings(): array
    {
        return collect(self::OPENINGS)->map(fn (array $row, string $id) => [
            'name_ar' => $row[0],
            'name_en' => $row[1],
            'tier' => $row[2],
            'module_path' => "resources/js/openings/{$id}.js",
            'active' => $row[3],
        ])->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function themes(): array
    {
        $themes = [];
        foreach (File::glob(base_path('templates/themes/*.json')) as $file) {
            $theme = json_decode(File::get($file), true, flags: JSON_THROW_ON_ERROR);
            $themes[$theme['id']] = array_diff_key($theme, ['id' => true]) + ['active' => true];
        }

        return $themes;
    }

    /**
     * Templates from templates/invitations/*.json; each default_config is checked against the schema.
     *
     * @return array<string, array<string, mixed>>
     */
    public function templates(): array
    {
        $templates = [];
        foreach (File::glob(base_path('templates/invitations/*.json')) as $file) {
            $template = json_decode(File::get($file), true, flags: JSON_THROW_ON_ERROR);
            $this->validator->assertValid($template['default_config'], basename($file));
            $templates[$template['id']] = array_diff_key($template, ['id' => true]) + ['active' => true];
        }

        return $templates;
    }

    public function themePath(string $id): string
    {
        return base_path("templates/themes/{$id}.json");
    }

    public function templatePath(string $id): string
    {
        return base_path("templates/invitations/{$id}.json");
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function write(string $model, string $id, array $attributes, bool $force, ?array $only, array &$report): void
    {
        $label = class_basename($model).':'.$id;
        $row = $model::query()->find($id);

        if (! $row) {
            (new $model)->forceFill(['id' => $id] + $attributes)->save();
            $report['inserted'][] = $label;

            return;
        }

        $forced = $force && ($only === null || in_array($id, $only, true));
        if ($row->admin_edited_at !== null && ! $forced) {
            $report['skipped'][] = $label;

            return;
        }

        $row->forceFill($attributes + ($forced ? ['admin_edited_at' => null] : []))->save();
        if ($row->wasChanged()) {
            $report['updated'][] = $label;
        }
    }
}
