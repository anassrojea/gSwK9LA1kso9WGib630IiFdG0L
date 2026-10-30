<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8C, vendor listings (plan sections 5, 13.2, 14.6; docs/MARKETPLACE.md).
 *
 * vendor_listings         a photographer, kosha designer, band… (one per user), approved in Filament
 * vendor_features         "featured in {city}/{category}" until ends_at (bought as vendor_feature, or granted by an admin)
 * vendor_contact_clicks   anonymous daily counters of WhatsApp / Instagram taps (no IP, no user)
 * calligraphy_requests    a host's custom name logo from a calligrapher (order item calligraphy_request)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_listings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('slug', 80)->unique();
            $table->string('name_ar', 120);
            $table->string('name_en', 120);
            $table->string('category', 24);
            // city keys from config('marketplace.cities'); occasions from Event::OCCASIONS
            $table->jsonb('cities');
            $table->jsonb('occasions')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            // media disk paths under vendors/{listing_id}/
            $table->jsonb('photos')->nullable();
            $table->bigInteger('price_min')->nullable();
            $table->bigInteger('price_max')->nullable();
            $table->char('price_currency', 3)->nullable();
            // international number without "+", e.g. 963912345678
            $table->string('whatsapp', 20)->nullable();
            $table->string('instagram', 40)->nullable();
            // calligraphers only: the name logo's price per currency (minor units)
            $table->jsonb('calligraphy_prices')->nullable();
            $table->string('status', 16)->default('pending');
            $table->text('status_reason')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'category']);
        });
        Pg::checkIn('vendor_listings', 'status', ['pending', 'approved', 'rejected', 'hidden']);
        Pg::gin('vendor_listings', 'cities');
        Pg::check('vendor_listings', 'vendor_listings_price_check', 'price_min is null or price_max is null or price_min <= price_max');

        Schema::create('vendor_features', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('listing_id')->constrained('vendor_listings')->cascadeOnDelete();
            $table->string('city', 32);
            $table->string('category', 24);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            // set by vendors:end-features (or a refund): no longer shown first
            $table->timestampTz('ended_at')->nullable();
            $table->foreignUuid('source_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignUuid('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['city', 'category', 'ends_at']);
            $table->index(['listing_id', 'ends_at']);
        });

        Schema::create('vendor_contact_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('listing_id')->constrained('vendor_listings')->cascadeOnDelete();
            $table->string('channel', 16);
            $table->date('day');
            $table->integer('count')->default(0);

            $table->unique(['listing_id', 'channel', 'day']);
        });
        Pg::checkIn('vendor_contact_clicks', 'channel', ['whatsapp', 'instagram']);

        Schema::create('calligraphy_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('listing_id')->constrained('vendor_listings')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->foreignUuid('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('names', 120);
            $table->text('brief');
            // awaiting_payment → new (paid) → in_progress → delivered; cancelled (refund / not paid)
            $table->string('status', 16)->default('awaiting_payment');
            $table->text('vendor_note')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();

            $table->index(['listing_id', 'status']);
        });
        Pg::checkIn('calligraphy_requests', 'status', ['awaiting_payment', 'new', 'in_progress', 'delivered', 'cancelled']);
    }

    public function down(): void
    {
        foreach (['calligraphy_requests', 'vendor_contact_clicks', 'vendor_features', 'vendor_listings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
