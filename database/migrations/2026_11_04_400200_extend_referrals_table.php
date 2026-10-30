<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The referrals table from phase 1, with what phase 8D needs (plan section 14.7):
 * the code used, the sign-up IP (abuse check), why a referral earns nothing
 * (reason) and the order that earned the reward.
 *
 * reward_status: pending (no paid order yet) → rewarded | capped (monthly cap)
 *                blocked (self-referral) | void
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->string('code', 16)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('reason', 32)->nullable();
            $table->timestampTz('rewarded_at')->nullable();
            $table->foreignUuid('reward_order_id')->nullable()->constrained('orders')->nullOnDelete();

            $table->index(['referrer_user_id', 'reward_status']);
        });
        Pg::checkIn('referrals', 'reward_status', ['pending', 'rewarded', 'capped', 'blocked', 'void']);
    }

    public function down(): void
    {
        if (Pg::active()) {
            DB::statement('alter table referrals drop constraint if exists referrals_reward_status_check');
        }
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropIndex(['referrer_user_id', 'reward_status']);
            $table->dropConstrainedForeignId('reward_order_id');
            $table->dropColumn(['code', 'ip', 'reason', 'rewarded_at']);
        });
    }
};
