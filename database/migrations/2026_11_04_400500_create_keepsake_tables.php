<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * After the event (phase 8D, plan sections 5, 13.2 and 14.5):
 *
 *   thank_you_cards   one per event: a config of schema v2 with kind "thank_you", shown at /t/{token}
 *                     (and on /i/{token} after the event when show_on_invite)
 *   music_tracks      royalty-free tracks the admin uploads for highlight videos (none ship with the app)
 *   highlight_videos  each build of the slideshow; files on the media disk under events/{id}/highlight/
 *   photo_books       a printed book: photos, layout, the print-ready PDF (events/{id}/photobook/),
 *                     shipping address and the print status the admin moves along
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thank_you_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->unique()->constrained('events')->cascadeOnDelete();
            $table->jsonb('config');
            $table->integer('config_version')->default(2);
            $table->boolean('show_on_invite')->default(true);
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('music_tracks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('artist')->nullable();
            // where the licence comes from (e.g. "Pixabay Content License"), for the admin's records
            $table->string('license')->nullable();
            $table->string('storage_key');
            $table->integer('duration')->nullable();
            $table->boolean('active')->default(true);
            $table->integer('sort')->default(0);
            $table->timestampsTz();
        });

        Schema::create('highlight_videos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('music_track_id')->nullable()->constrained('music_tracks')->nullOnDelete();
            $table->string('status', 16)->default('queued');
            // {vertical: {key, poster, width, height, duration, bytes}, landscape: {...}}
            $table->jsonb('outputs')->nullable();
            $table->jsonb('formats');
            $table->integer('photo_count')->default(0);
            $table->integer('clip_count')->default(0);
            $table->text('error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['event_id', 'created_at']);
        });
        Pg::checkIn('highlight_videos', 'status', ['queued', 'rendering', 'ready', 'failed']);

        Schema::create('photo_books', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('title', 80);
            $table->string('subtitle', 120)->nullable();
            $table->string('size', 24)->default('a4_landscape');
            $table->integer('pages')->default(20);
            $table->boolean('auto_pick')->default(true);
            $table->jsonb('photo_ids');
            $table->integer('copies')->default(1);
            $table->string('ship_name')->nullable();
            $table->char('ship_country', 2)->nullable();
            $table->string('ship_city', 80)->nullable();
            $table->string('ship_address')->nullable();
            $table->string('ship_phone', 32)->nullable();
            $table->string('status', 16)->default('draft');
            $table->text('tracking_notes')->nullable();
            $table->string('pdf_key')->nullable();
            $table->integer('pdf_pages')->nullable();
            $table->timestampTz('pdf_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('printing_at')->nullable();
            $table->timestampTz('shipped_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'paid_at']);
        });
        Pg::checkIn('photo_books', 'status', ['draft', 'pending', 'printing', 'shipped', 'delivered', 'cancelled']);
        Pg::checkIn('photo_books', 'size', ['a4_landscape', 'square_21']);
        Pg::checkIn('photo_books', 'pages', ['20', '40']);
    }

    public function down(): void
    {
        foreach (['photo_books', 'highlight_videos', 'music_tracks', 'thank_you_cards'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
