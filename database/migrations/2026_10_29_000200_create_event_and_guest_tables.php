<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hall_layouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->jsonb('layout'); // schema in plan section 7.1
            $table->timestampsTz();
        });
        Pg::gin('hall_layouts', 'layout');

        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('org_id')->nullable()->constrained('organizations')->nullOnDelete();
            // drafts created before sign-up belong to an anonymous session until "Pay & send"
            $table->string('anon_session_id', 64)->nullable()->index();
            $table->string('occasion', 32);
            $table->string('title');
            $table->timestampTz('starts_at')->nullable();
            $table->string('timezone', 64)->default('Asia/Riyadh');
            $table->string('venue_name')->nullable();
            $table->string('address')->nullable();
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();
            $table->foreignUuid('hall_layout_id')->nullable()->constrained('hall_layouts')->nullOnDelete();
            $table->string('status', 16)->default('draft');
            $table->string('plan_id', 40)->nullable();
            $table->foreign('plan_id')->references('id')->on('plans')->nullOnDelete();
            $table->integer('guest_limit')->nullable();
            $table->date('storage_until')->nullable();
            $table->timestampsTz();

            $table->index('owner_id');
            $table->index('org_id');
        });
        Pg::checkIn('events', 'status', ['draft', 'active', 'ended', 'archived']);

        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->unique()->constrained('events')->cascadeOnDelete();
            $table->string('template_id', 64)->nullable();
            $table->foreign('template_id')->references('id')->on('templates')->nullOnDelete();
            $table->jsonb('config');
            $table->integer('config_version')->default(1);
            $table->timestampTz('published_at')->nullable();
            $table->string('slug', 80)->unique();
            $table->timestampsTz();
        });
        Pg::gin('invitations', 'config');

        Schema::create('households', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('display_name');
            $table->string('phone', 32)->nullable();
            $table->integer('seats_allowed')->default(1);
            $table->string('section', 32)->nullable();
            $table->string('token', 32)->unique(); // unique index doubles as the households(token) lookup index
            $table->timestampTz('first_opened_at')->nullable();
            $table->timestampTz('last_opened_at')->nullable();
            $table->integer('open_count')->default(0);
            $table->timestampsTz();

            $table->index('event_id');
        });
        Pg::textArray('households', 'tags');
        Pg::check('households', 'households_seats_allowed_check', 'seats_allowed between 1 and 100');

        Schema::create('rsvps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('household_id')->unique()->constrained('households')->cascadeOnDelete();
            $table->boolean('attending');
            $table->integer('people_count')->default(0);
            $table->integer('kids_count')->default(0);
            $table->text('message')->nullable();
            $table->jsonb('extras')->nullable();
            $table->timestampTz('responded_at');
            $table->timestampsTz();
        });
        Pg::check('rsvps', 'rsvps_counts_check', 'people_count >= 0 and kids_count >= 0 and kids_count <= people_count');

        Schema::create('reminders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('household_id')->constrained('households')->cascadeOnDelete();
            $table->string('channel', 16);
            $table->timestampTz('sent_at')->nullable();
            $table->string('status', 16)->default('queued');
            $table->timestampsTz();

            $table->index(['event_id', 'household_id']);
        });
        Pg::checkIn('reminders', 'channel', ['whatsapp', 'email', 'sms']);
        Pg::checkIn('reminders', 'status', ['queued', 'sent', 'delivered', 'failed']);

        Schema::create('seat_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('household_id')->constrained('households')->cascadeOnDelete();
            $table->string('table_id', 16);
            $table->integer('seat_index');
            $table->string('person_label')->nullable();
            $table->timestampsTz();

            $table->unique(['event_id', 'table_id', 'seat_index']);
            $table->index('household_id');
        });

        Schema::create('checkins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('household_id')->constrained('households')->cascadeOnDelete();
            $table->foreignUuid('scanned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('people_in')->default(1);
            $table->timestampTz('scanned_at');
            $table->timestampsTz();

            $table->index(['event_id', 'household_id']);
        });
    }

    public function down(): void
    {
        foreach (['checkins', 'seat_assignments', 'reminders', 'rsvps', 'households', 'invitations', 'events', 'hall_layouts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
