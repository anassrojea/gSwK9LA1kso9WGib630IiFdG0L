<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account credit (phase 8D, plan section 14.7): an append-only ledger per user
 * and currency. A referral reward adds, paying with credit at checkout
 * subtracts (when the order is paid), a refund gives it back. The balance is
 * the sum (App\Services\Journey\Credits). Amounts in minor units of `currency`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_credits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('currency', 3);
            $table->integer('amount');
            $table->string('kind', 24);
            $table->foreignUuid('referral_id')->nullable()->constrained('referrals')->nullOnDelete();
            $table->foreignUuid('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'currency']);
        });
        Pg::checkIn('account_credits', 'kind', ['referral_reward', 'spend', 'restore', 'admin']);
        Pg::check('account_credits', 'account_credits_amount_check', 'amount <> 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('account_credits');
    }
};
