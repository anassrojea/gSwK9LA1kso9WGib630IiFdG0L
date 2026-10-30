<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hall designer and venue directory (phase 8B):
 *  - template_key: a starter hall (no owner, no organization) that venues
 *    copy and change ("ballroom", "banquet", "garden"); see HallTemplateSeeder
 *  - public: shown on the venue's directory page, and offered to hosts who
 *    start an invitation from that page
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hall_layouts', function (Blueprint $table) {
            $table->string('template_key', 40)->nullable()->unique();
            $table->boolean('public')->default(false);
            $table->index('org_id');
        });
    }

    public function down(): void
    {
        Schema::table('hall_layouts', function (Blueprint $table) {
            $table->dropIndex(['org_id']);
            $table->dropUnique(['template_key']);
            $table->dropColumn(['template_key', 'public']);
        });
    }
};
