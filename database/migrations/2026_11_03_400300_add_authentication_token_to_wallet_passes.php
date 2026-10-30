<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Apple Wallet's web service (pass updates, a later phase) authenticates
 * devices with the pass's authenticationToken; it is generated with the pass
 * and kept next to the serial number. content_hash records what the issued
 * pass showed (time, hall, table, seats), so UpdateWalletPasses can tell which
 * passes are out of date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_passes', function (Blueprint $table) {
            $table->string('authentication_token', 64)->nullable();
            $table->string('content_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wallet_passes', function (Blueprint $table) {
            $table->dropColumn(['authentication_token', 'content_hash']);
        });
    }
};
