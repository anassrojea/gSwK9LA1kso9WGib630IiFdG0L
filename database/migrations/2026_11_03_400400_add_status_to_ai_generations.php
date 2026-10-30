<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI writing runs in queued jobs (AiWriteStory, AiWritePoem, AiCleanGuestList):
 * the row is created "queued", the page polls it, and the validated result
 * waits in "result" until the host accepts it. Nothing here is ever applied to
 * an invitation or a guest list without the host's confirmation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_generations', function (Blueprint $table) {
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 12)->default('done');
            $table->string('error', 32)->nullable();
            $table->jsonb('result')->nullable();

            $table->index(['event_id', 'kind', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
        Pg::checkIn('ai_generations', 'status', ['queued', 'running', 'done', 'failed']);
    }

    public function down(): void
    {
        if (Pg::active()) {
            DB::statement('alter table ai_generations drop constraint if exists ai_generations_status_check');
        }
        Schema::table('ai_generations', function (Blueprint $table) {
            $table->dropIndex(['event_id', 'kind', 'created_at']);
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['status', 'error', 'result']);
        });
    }
};
