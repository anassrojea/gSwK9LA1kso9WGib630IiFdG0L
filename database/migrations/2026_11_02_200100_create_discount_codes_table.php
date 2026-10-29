<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Discount codes (plan section 4.3) and what an order keeps of the code it
 * used: the code, its text and the discount in minor units of the order's
 * currency (total_cents is already net of it). Rules: App\Services\Payments\Discounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // stored upper case; unique ignoring case (index below)
            $table->string('code', 40)->unique();
            $table->string('description')->nullable();
            // exactly one of: a percentage, or fixed amounts per currency {"SAR": 2000, "USD": 500} in minor units
            $table->smallInteger('percent')->nullable();
            $table->jsonb('amounts')->nullable();
            $table->boolean('applies_to_plans')->default(true);
            $table->boolean('applies_to_addons')->default(true);
            // null: every plan / add-on
            $table->jsonb('plan_ids')->nullable();
            $table->jsonb('addon_ids')->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            // counted on paid / approved orders only
            $table->integer('max_uses')->nullable();
            $table->integer('max_uses_per_user')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        if (Pg::active()) {
            DB::statement('create unique index discount_codes_code_ci_unique on discount_codes (lower(code))');
        }
        Pg::check('discount_codes', 'discount_codes_kind_check', '(percent is null) <> (amounts is null)');
        Pg::check('discount_codes', 'discount_codes_percent_check', 'percent is null or (percent between 1 and 100)');
        Pg::check('discount_codes', 'discount_codes_targets_check', 'applies_to_plans or applies_to_addons');
        Pg::check('discount_codes', 'discount_codes_uses_check', '(max_uses is null or max_uses >= 1) and (max_uses_per_user is null or max_uses_per_user >= 1)');

        Schema::table('orders', function (Blueprint $table) {
            // a code that was used can't be deleted, only switched off
            $table->foreignUuid('discount_code_id')->nullable()->after('total_cents')->constrained('discount_codes')->restrictOnDelete();
            $table->string('discount_code', 40)->nullable()->after('discount_code_id');
            $table->bigInteger('discount_cents')->default(0)->after('discount_code');
            $table->index(['discount_code_id', 'status']);
        });
        Pg::check('orders', 'orders_discount_cents_check', 'discount_cents >= 0');
    }

    public function down(): void
    {
        if (Pg::active()) {
            DB::statement('alter table orders drop constraint if exists orders_discount_cents_check');
        }
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['discount_code_id', 'status']);
            $table->dropConstrainedForeignId('discount_code_id');
            $table->dropColumn(['discount_code', 'discount_cents']);
        });
        Schema::dropIfExists('discount_codes');
    }
};
