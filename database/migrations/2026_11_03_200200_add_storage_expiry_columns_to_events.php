<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storage period expiry (plan section 11, phase 6B, events:expire-storage).
 *
 * storage_warnings: which warning emails went out for which storage date,
 *   {"until": "2026-12-01", "sent": {"14": "2026-11-17T06:00:00+00:00", "3": "…"}}.
 *   Extending storage changes events.storage_until, so the next period warns again.
 * media_purged_at: when the event's media files and rows were deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->jsonb('storage_warnings')->nullable()->after('storage_until');
            $table->timestampTz('media_purged_at')->nullable()->after('storage_warnings');
            $table->index(['storage_until', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['storage_until', 'status']);
            $table->dropColumn(['storage_warnings', 'media_purged_at']);
        });
    }
};
