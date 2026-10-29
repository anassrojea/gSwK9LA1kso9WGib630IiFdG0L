<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Background music uploaded in the wizard (config media.music) is stored as
 * a media row of kind "audio", next to photos and videos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Pg::active()) {
            DB::statement('alter table media drop constraint if exists media_kind_check');
        }
        Pg::checkIn('media', 'kind', ['photo', 'video', 'audio']);
    }

    public function down(): void
    {
        if (Pg::active()) {
            DB::statement("delete from media where kind = 'audio'");
            DB::statement('alter table media drop constraint if exists media_kind_check');
        }
        Pg::checkIn('media', 'kind', ['photo', 'video']);
    }
};
