<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8A, Venue & Planner Pro (plan section 13.1): the organization's own
 * subscription (org_subscriptions, synced from Stripe / Lemon Squeezy
 * webhooks or extended by approved transfer renewals), the profile fields of
 * an organization, and the signature-event quota stamp on events.
 *
 * Cashier's subscriptions tables are created here with a UUID user_id
 * (Cashier's own migrations are ignored, AppServiceProvider): the Stripe
 * subscription lives on the owner user with type "org:{org_id}" and is
 * mirrored into org_subscriptions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('city', 80)->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('contact_email')->nullable();
            // the logo file on the public disk (logo_url is its public URL)
            $table->string('logo_path')->nullable();
        });

        Schema::create('org_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->constrained('organizations')->cascadeOnDelete();
            // the payer: the org owner when it started (User stays the only Cashier billable)
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            // plans row: venue_starter | venue_pro | venue_business (+ _yearly)
            $table->string('plan_id', 40);
            $table->foreign('plan_id')->references('id')->on('plans');
            $table->string('interval', 8);
            $table->string('provider', 16);
            // Stripe sub_… / Lemon Squeezy subscription id; null for transfer
            $table->string('provider_id')->nullable();
            $table->string('provider_customer_id')->nullable();
            // Stripe price / Lemon Squeezy variant currently billed
            $table->string('provider_price')->nullable();
            $table->char('currency', 3);
            $table->bigInteger('amount_cents')->default(0);
            $table->string('status', 16);
            $table->timestampTz('current_period_start')->nullable();
            $table->timestampTz('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            // transfer: a tier change waiting for the next period
            $table->string('next_plan_id', 40)->nullable();
            $table->timestampTz('past_due_since')->nullable();
            $table->timestampTz('canceled_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            // reminder emails already sent: {"<period end>:7": iso, "<period end>:1": iso, "past_due:<period end>": iso}
            $table->jsonb('reminders')->nullable();
            $table->timestampsTz();

            $table->index(['org_id', 'status']);
            $table->index(['provider', 'status', 'current_period_end']);
            $table->unique(['provider', 'provider_id']);
        });
        Pg::checkIn('org_subscriptions', 'interval', ['month', 'year']);
        Pg::checkIn('org_subscriptions', 'provider', ['stripe', 'lemon_squeezy', 'transfer']);
        Pg::checkIn('org_subscriptions', 'status', ['trialing', 'active', 'past_due', 'canceled', 'expired']);
        if (Pg::active()) {
            // one live subscription per organization
            DB::statement("create unique index org_subscriptions_one_live on org_subscriptions (org_id) where status in ('trialing', 'active', 'past_due')");
        }

        Schema::table('events', function (Blueprint $table) {
            // when the event used one of its organization's monthly signature events (App\Services\Org\OrgQuota)
            $table->timestampTz('org_quota_at')->nullable();
            $table->index(['org_id', 'org_quota_at']);
        });

        // Laravel Cashier's tables, with the UUID users key
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id');
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'stripe_status']);
        });

        Schema::create('subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id');
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->string('meter_id')->nullable();
            $table->integer('quantity')->nullable();
            $table->string('meter_event_name')->nullable();
            $table->timestampsTz();

            $table->index(['subscription_id', 'stripe_price']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('subscriptions');
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['org_id', 'org_quota_at']);
            $table->dropColumn('org_quota_at');
        });
        Schema::dropIfExists('org_subscriptions');
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn(['city', 'contact_phone', 'contact_email', 'logo_path']));
    }
};
