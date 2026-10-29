<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 16)->unique(); // e.g. DW-4821; unique index doubles as orders(code)
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->foreignUuid('org_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->jsonb('items');
            $table->integer('total_cents');
            $table->char('currency', 3)->default('USD');
            $table->string('method', 16);
            $table->string('status', 24)->default('awaiting_payment');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'expires_at']);
            $table->index('user_id');
        });
        Pg::checkIn('orders', 'method', ['card', 'transfer', 'wallet_local']);
        Pg::checkIn('orders', 'status', ['awaiting_payment', 'receipt_received', 'paid', 'approved', 'rejected', 'expired', 'refunded']);
        Pg::check('orders', 'orders_total_cents_check', 'total_cents >= 0');

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_ref')->nullable();
            $table->integer('amount_cents');
            $table->string('status', 24);
            $table->jsonb('raw')->nullable();
            $table->timestampsTz();

            $table->index('order_id');
            $table->unique(['provider', 'provider_ref']);
        });

        Schema::create('transfer_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('received_via', 16)->default('whatsapp');
            $table->timestampTz('received_at');
            $table->text('note')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision', 16)->nullable();
            $table->timestampsTz();

            $table->index('order_id');
        });
        Pg::checkIn('transfer_receipts', 'decision', ['approved', 'rejected'], nullable: true);

        Schema::create('entitlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->nullable()->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('org_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->string('feature', 40);
            $table->timestampTz('until')->nullable();
            $table->foreignUuid('source_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['event_id', 'feature']);
            $table->index(['org_id', 'feature']);
        });
        Pg::check('entitlements', 'entitlements_owner_check', 'event_id is not null or org_id is not null');
    }

    public function down(): void
    {
        foreach (['entitlements', 'transfer_receipts', 'payments', 'orders'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
