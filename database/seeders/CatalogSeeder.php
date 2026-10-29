<?php

namespace Database\Seeders;

use App\Services\Catalog\CatalogSync;
use Illuminate\Database\Seeder;

/**
 * Openings, themes and templates. Themes and templates are data, not code:
 * they live as JSON in templates/ and are synced into the database here.
 * Rows an admin saved in Filament are left alone (see CatalogSync).
 */
class CatalogSeeder extends Seeder
{
    public function run(CatalogSync $sync): void
    {
        $report = $sync->sync();

        if ($report['skipped'] !== [] && $this->command) {
            $this->command->warn('Kept admin edits (not overwritten): '.implode(', ', $report['skipped']));
        }
    }
}
