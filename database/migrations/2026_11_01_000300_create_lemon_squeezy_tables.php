<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * lemonsqueezy/laravel's tables with UUID billables. The package's own
 * migrations use integer billable ids while users have UUID keys, so they are
 * ignored (AppServiceProvider) and replaced by these. A database that already
 * ran the package's versions gets them recreated (nothing was ever written to them).
 * Orders are paid through our own orders/payments tables; these exist so the
 * package's models keep working (subscriptions come with Venue Pro).
 */
return new class extends Migration
{
    private const TABLES = ['lemon_squeezy_license_key_instances', 'lemon_squeezy_license_keys', 'lemon_squeezy_orders', 'lemon_squeezy_subscriptions', 'lemon_squeezy_customers'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('lemon_squeezy_customers', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('billable');
            $table->string('lemon_squeezy_id')->nullable()->unique();
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampsTz();

            $table->unique(['billable_id', 'billable_type']);
        });

        Schema::create('lemon_squeezy_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('billable');
            $table->string('type');
            $table->string('lemon_squeezy_id')->unique();
            $table->string('status');
            $table->string('product_id');
            $table->string('variant_id');
            $table->string('card_brand')->nullable();
            $table->string('card_last_four')->nullable();
            $table->string('pause_mode')->nullable();
            $table->timestampTz('pause_resumes_at')->nullable();
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('renews_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('lemon_squeezy_orders', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('billable');
            $table->string('lemon_squeezy_id')->unique();
            $table->string('customer_id');
            $table->uuid('identifier')->unique();
            $table->string('product_id')->index();
            $table->string('variant_id')->index();
            $table->integer('order_number')->unique();
            $table->string('currency');
            $table->integer('subtotal');
            $table->integer('discount_total');
            $table->integer('tax');
            $table->integer('total');
            $table->string('tax_name')->nullable();
            $table->string('status');
            $table->string('receipt_url')->nullable();
            $table->boolean('refunded');
            $table->timestampTz('refunded_at')->nullable();
            $table->timestampTz('ordered_at');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
};
