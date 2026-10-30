<?php

namespace Database\Seeders;

use App\Services\Seating\HallTemplates;
use Illuminate\Database\Seeder;

/**
 * Starter halls every venue can copy (phase 8B). Safe to run again: it
 * refreshes the three templates by their template_key.
 */
class HallTemplateSeeder extends Seeder
{
    public function run(HallTemplates $templates): void
    {
        $templates->seed();
    }
}
