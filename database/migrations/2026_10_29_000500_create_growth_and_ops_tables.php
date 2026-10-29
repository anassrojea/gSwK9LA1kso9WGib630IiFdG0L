<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only logs: bigserial ids and their own timestamp column, no created_at/updated_at.
        Schema::create('link_opens', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('household_id')->constrained('households')->cascadeOnDelete();
            $table->timestampTz('opened_at');
            $table->string('ua_hash', 64)->nullable();

            $table->index(['household_id', 'opened_at']);
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('referrer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('referred_user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('reward_status', 16)->default('pending');
            $table->timestampsTz();
        });

        Schema::create('ai_generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->string('kind', 32);
            $table->jsonb('input');
            $table->text('output')->nullable();
            $table->string('model', 64);
            $table->integer('cost_cents')->default(0);
            $table->timestampsTz();

            $table->index('event_id');
        });

        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('entity', 64);
            $table->string('entity_id', 64)->nullable();
            $table->jsonb('data')->nullable();
            $table->timestampTz('at')->useCurrent();

            $table->index(['entity', 'entity_id']);
            $table->index('at');
        });
    }

    public function down(): void
    {
        foreach (['audit_log', 'ai_generations', 'referrals', 'link_opens'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
