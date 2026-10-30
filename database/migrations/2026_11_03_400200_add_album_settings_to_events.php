<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Album and photo wall settings per event (App\Services\Album\Album): PIN
 * hash, "upload before the event", "approve before the wall", the wall's
 * secret link and the last ZIP export. album_token is the event's own upload
 * link (/i/{album_token}/album), shown as a QR on the wall when the event has
 * no open link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->jsonb('album')->nullable();
            $table->string('album_token', 32)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['album', 'album_token']);
        });
    }
};
