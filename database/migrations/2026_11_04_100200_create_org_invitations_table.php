<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team invitations (phase 8A): an email, a role and a signed link that
 * expires after 7 days (App\Services\Org\OrgTeam). role "client" invites a
 * couple to edit one event (event_id) the organization made for them; the
 * other roles join the team. Only a hash of the token is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('event_id')->nullable()->constrained('events')->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 16);
            $table->string('token_hash', 64)->unique();
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignUuid('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index(['org_id', 'accepted_at', 'revoked_at']);
            $table->index('email');
        });
        Pg::checkIn('org_invitations', 'role', ['staff', 'door', 'client']);
    }

    public function down(): void
    {
        Schema::dropIfExists('org_invitations');
    }
};
