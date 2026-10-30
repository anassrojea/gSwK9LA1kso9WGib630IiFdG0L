<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The event album (plan section 11): guests' photos and videos live in the
 * media table next to the host's wizard uploads, told apart by "source".
 * thumb_key is the 400 px WebP thumbnail, preview_key the 1200 px one
 * (App\Jobs\MakeThumbnails). approved_at is set when the host lets a photo
 * onto the live wall while "approve first" is on. Video guestbook entries
 * are source "guestbook": they never show in the album or on the wall.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->string('source', 12)->default('host');
            $table->string('mime', 64)->nullable();
            $table->string('preview_key')->nullable();
            $table->integer('duration')->nullable();
            $table->string('uploader_name', 120)->nullable();
            $table->timestampTz('approved_at')->nullable();

            $table->index(['event_id', 'source', 'created_at']);
        });
        Pg::checkIn('media', 'source', ['host', 'guest', 'guestbook']);
    }

    public function down(): void
    {
        if (Pg::active()) {
            DB::statement('alter table media drop constraint if exists media_source_check');
        }
        Schema::table('media', function (Blueprint $table) {
            $table->dropIndex(['event_id', 'source', 'created_at']);
            $table->dropColumn(['source', 'mime', 'preview_key', 'duration', 'uploader_name', 'approved_at']);
        });
    }
};
