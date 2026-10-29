<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Set when an admin saves an opening, theme or template in Filament: from then
 * on CatalogSeeder (App\Services\Catalog\CatalogSync) leaves the row alone,
 * so re-seeding on deploy never overwrites admin edits. "Reset to templates/"
 * in the admin clears it.
 */
return new class extends Migration
{
    private const TABLES = ['openings', 'themes', 'templates'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->timestampTz('admin_edited_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('admin_edited_at'));
        }
    }
};
