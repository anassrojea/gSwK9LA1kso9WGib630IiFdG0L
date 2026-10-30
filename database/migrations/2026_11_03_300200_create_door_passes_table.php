<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Door PIN links (plan sections 9 and 14.8): door staff without an account
 * open a signed link and type a 6-digit PIN. Only a hash of the PIN is kept;
 * the link stops working at expires_at, on revocation, or after too many
 * wrong PINs (App\Services\Door\DoorPasses).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('door_passes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('label', 60);
            $table->string('pin_hash');
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoked_reason', 32)->nullable();
            $table->integer('failed_attempts')->default(0);
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();

            $table->index('event_id');
        });

        Schema::table('checkins', function (Blueprint $table) {
            // who scanned, when it was a PIN link rather than an account
            $table->foreignUuid('door_pass_id')->nullable()->constrained('door_passes')->nullOnDelete();
            // one id per scan on the door device: a retried or re-synced scan is recorded once
            $table->uuid('scan_id')->nullable();
            $table->unique(['event_id', 'scan_id']);
        });
    }

    public function down(): void
    {
        Schema::table('checkins', function (Blueprint $table) {
            $table->dropUnique(['event_id', 'scan_id']);
            $table->dropConstrainedForeignId('door_pass_id');
            $table->dropColumn('scan_id');
        });
        Schema::dropIfExists('door_passes');
    }
};
