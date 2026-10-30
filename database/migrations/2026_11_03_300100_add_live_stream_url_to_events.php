<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The live day page (plan sections 4.1 and 14.1): an optional live stream
 * link the host sets on the dashboard, shown to guests on the event day.
 * Always https (validated in App\Http\Controllers\Dashboard\EventDayController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('live_stream_url', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('live_stream_url');
        });
    }
};
