<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('household_id')->nullable()->constrained('households')->nullOnDelete();
            $table->string('kind', 8);
            $table->string('storage_key');
            $table->string('thumb_key')->nullable();
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->bigInteger('bytes')->default(0);
            $table->boolean('hidden')->default(false);
            $table->timestampsTz();

            $table->index('event_id');
        });
        Pg::checkIn('media', 'kind', ['photo', 'video']);

        Schema::create('guestbook', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('household_id')->nullable()->constrained('households')->nullOnDelete();
            $table->string('kind', 8);
            $table->text('text')->nullable();
            $table->foreignUuid('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->timestampsTz();

            $table->index('event_id');
        });
        Pg::checkIn('guestbook', 'kind', ['text', 'video', 'audio']);

        Schema::create('roses', function (Blueprint $table) {
            $table->foreignUuid('event_id')->primary()->constrained('events')->cascadeOnDelete();
            $table->integer('count')->default(0);
            $table->timestampsTz();
        });

        Schema::create('wallet_passes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('household_id')->constrained('households')->cascadeOnDelete();
            $table->string('platform', 8);
            $table->string('serial', 64)->unique();
            $table->string('pass_url')->nullable();
            $table->timestampsTz();

            $table->unique(['household_id', 'platform']);
        });
        Pg::checkIn('wallet_passes', 'platform', ['apple', 'google']);
    }

    public function down(): void
    {
        foreach (['wallet_passes', 'roses', 'guestbook', 'media'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
