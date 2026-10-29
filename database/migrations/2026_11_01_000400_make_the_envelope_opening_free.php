<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Free plan includes free designs (plan section 13.1), but every opening
 * was Premium, so no invitation could ever be free. The classic envelope
 * becomes the free opening (CatalogSeeder says the same for new installs).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('openings')->where('id', 'envelope')->update(['tier' => 'free']);
    }

    public function down(): void
    {
        DB::table('openings')->where('id', 'envelope')->update(['tier' => 'premium']);
    }
};
