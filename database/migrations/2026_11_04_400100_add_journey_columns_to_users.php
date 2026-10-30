<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8D, referrals and the anniversary email (plan sections 14.5, 14.7):
 *   referral_code      the user's /r/{code}, made on first use (App\Services\Journey\Referrals)
 *   referral_footer    the host's invitations credit them on "صمّم دعوتك" (opt-in)
 *   marketing_opt_out  no anniversary or other marketing email (signed unsubscribe link)
 *   signup_ip          kept for the self-referral check only
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 16)->nullable()->unique();
            $table->boolean('referral_footer')->default(false);
            $table->boolean('marketing_opt_out')->default(false);
            $table->string('signup_ip', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['referral_code']);
            $table->dropColumn(['referral_code', 'referral_footer', 'marketing_opt_out', 'signup_ip']);
        });
    }
};
