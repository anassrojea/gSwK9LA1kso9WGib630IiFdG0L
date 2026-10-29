<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 payments: the host's country and currency, Cashier's customer
 * columns on the (UUID) users table, and what orders and receipts need for
 * the gateways, the admin decisions and refunds. Cashier's own migrations are
 * never loaded (AppServiceProvider): there are no subscriptions yet, so no
 * subscriptions tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('country', 2)->nullable()->after('locale');
            $table->char('currency', 3)->nullable()->after('country');
            // Laravel Cashier (Stripe customer); card data never touches our database
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestampTz('trial_ends_at')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            // transfer | stripe | lemon_squeezy (method stays card | transfer | wallet_local)
            $table->string('gateway', 32)->default('transfer')->after('method');
            // the gateway's checkout session / checkout id
            $table->string('gateway_ref')->nullable()->after('gateway');
            // the host's chosen currency when the gateway charges another one (card methods: USD)
            $table->char('requested_currency', 3)->nullable()->after('currency');
            $table->text('rejection_reason')->nullable();
            $table->timestampTz('refunded_at')->nullable();

            $table->index(['event_id', 'status']);
        });
        if (Pg::active()) {
            DB::statement('alter table orders alter column total_cents type bigint');
            DB::statement('alter table payments alter column amount_cents type bigint');
        }

        Schema::table('transfer_receipts', function (Blueprint $table) {
            // the bank's own reference, typed by the admin on approval: one receipt can't approve two orders
            $table->string('bank_reference', 64)->nullable()->unique();
            $table->text('reason')->nullable();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->char('currency', 3)->nullable()->after('amount_cents');
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('currency'));
        Schema::table('transfer_receipts', fn (Blueprint $table) => $table->dropColumn(['bank_reference', 'reason']));
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['event_id', 'status']);
            $table->dropColumn(['gateway', 'requested_currency', 'gateway_ref', 'rejection_reason', 'refunded_at']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['stripe_id']);
            $table->dropColumn(['country', 'currency', 'stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at']);
        });
    }
};
