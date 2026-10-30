<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Anonymous daily counters (plan section 22), e.g. "صمّم دعوتك" clicks per
     * invitation. No visitor data: a name, what it counts (an event id or ''), a day, a count.
     */
    public function up(): void
    {
        Schema::create('site_counters', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40);
            $table->string('subject', 64)->default('');
            $table->date('day');
            $table->unsignedInteger('count')->default(0);

            $table->unique(['name', 'day', 'subject']);
            $table->index(['name', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_counters');
    }
};
