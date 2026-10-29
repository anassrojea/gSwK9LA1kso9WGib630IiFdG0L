<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time email codes (App\Services\Account\LoginCodes): passwordless sign-in,
 * confirming an account deletion and confirming a new email address. Only an
 * HMAC of the code is stored; a code lives 10 minutes and takes 5 tries.
 * Keyed by email, not user: a code can create the account it signs in to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email');
            $table->string('purpose', 16);
            $table->string('code_hash', 64);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestampsTz();

            $table->index(['email', 'purpose']);
            $table->index('expires_at');
        });
        Pg::checkIn('login_codes', 'purpose', ['login', 'delete', 'email']);
    }

    public function down(): void
    {
        Schema::dropIfExists('login_codes');
    }
};
