<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * events.source_event_id: the event this one grew from (a save-the-date
 * upgraded to its full invitation, phase 8D). anniversary_reminders: one row
 * per event and year sent, so the yearly email goes out once
 * (journey:anniversaries).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->foreignUuid('source_event_id')->nullable()->constrained('events')->nullOnDelete();
        });

        Schema::create('anniversary_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->integer('year');
            $table->timestampTz('sent_at');

            $table->unique(['event_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anniversary_reminders');
        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_event_id');
        });
    }
};
