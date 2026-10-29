<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The host's own WhatsApp wording for invitations and reminders (dashboard
 * row actions): {"invite": "...", "reminder": "..."}. Null keys fall back to
 * the defaults in lang/{ar,en}/app.php (dashboard.messages).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->jsonb('messages')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('messages');
        });
    }
};
