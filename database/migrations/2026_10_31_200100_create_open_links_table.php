<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The open link (plan section 8): one link for everyone, for casual events,
 * with a cap on the total number of people. Each reply becomes a household
 * tagged "open-link"; opens are counted here, on the link, not on a household.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('open_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->unique()->constrained('events')->cascadeOnDelete();
            // same alphabet as household tokens, longer, unique across both tables (checked on generation)
            $table->string('token', 32)->unique();
            $table->boolean('enabled')->default(false);
            $table->integer('cap');
            $table->integer('open_count')->default(0);
            $table->timestampTz('first_opened_at')->nullable();
            $table->timestampTz('last_opened_at')->nullable();
            $table->timestampsTz();
        });
        Pg::check('open_links', 'open_links_cap_check', 'cap between 1 and 10000');
    }

    public function down(): void
    {
        Schema::dropIfExists('open_links');
    }
};
