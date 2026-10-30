<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Family journey bundles (phase 8D, plan section 14.2): "engagement + henna +
 * wedding" bought upfront at a discount. A bundle grants event-plan credits
 * (one row each in bundle_credits) that pay an event's plan at checkout within
 * validity_days. Definitions are managed in Filament.
 *
 * bundles.credits:  [{plan: "premium", count: 2}, {plan: "signature", count: 1}]
 * bundles.prices:   {"USD": 10900, "SAR": 40900, "SYP": 1300000}  (minor units, like the prices table)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bundles', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('name_ar');
            $table->string('name_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->jsonb('credits');
            $table->jsonb('prices');
            // seeded prices are placeholders until an admin saves the bundle
            $table->boolean('placeholder_prices')->default(true);
            $table->integer('validity_days')->default(730);
            $table->boolean('active')->default(true);
            $table->integer('sort')->default(0);
            $table->timestampsTz();
        });

        Schema::create('bundle_credits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('bundle_id', 64)->nullable();
            $table->foreign('bundle_id')->references('id')->on('bundles')->nullOnDelete();
            $table->string('plan_id', 40);
            $table->foreign('plan_id')->references('id')->on('plans')->restrictOnDelete();
            $table->foreignUuid('source_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->foreignUuid('used_event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->foreignUuid('used_order_id')->nullable()->constrained('orders')->nullOnDelete();
            // a refunded bundle voids its unused credits
            $table->timestampTz('voided_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bundle_credits');
        Schema::dropIfExists('bundles');
    }
};
