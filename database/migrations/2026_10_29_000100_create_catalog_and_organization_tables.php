<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TIERS = ['free', 'premium', 'signature'];

    public function up(): void
    {
        // money catalog first: organizations and events reference plans
        Schema::create('plans', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('name');
            $table->integer('price_cents')->default(0);
            $table->char('currency', 3)->default('USD');
            $table->string('interval', 16)->nullable(); // null = per event, 'month' = subscription
            $table->jsonb('limits')->default('{}');
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });
        Pg::checkIn('plans', 'interval', ['month', 'year'], nullable: true);
        Pg::check('plans', 'plans_price_cents_check', 'price_cents >= 0');

        Schema::create('addons', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('name');
            $table->integer('price_cents')->default(0);
            $table->char('currency', 3)->default('USD');
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('type', 16);
            $table->string('logo_url')->nullable();
            $table->string('plan_id', 40)->nullable();
            $table->foreign('plan_id')->references('id')->on('plans')->nullOnDelete();
            $table->jsonb('white_label')->nullable();
            $table->timestampsTz();
        });
        Pg::checkIn('organizations', 'type', ['venue', 'planner', 'company']);

        Schema::create('org_members', function (Blueprint $table) {
            $table->foreignUuid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 16)->default('staff');
            $table->timestampsTz();
            $table->primary(['org_id', 'user_id']);
            $table->index('user_id');
        });
        Pg::checkIn('org_members', 'role', ['owner', 'staff', 'door']);

        // design catalog
        Schema::create('openings', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('tier', 16)->default('premium');
            $table->string('module_path');
            $table->string('preview_url')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });
        Pg::checkIn('openings', 'tier', self::TIERS);

        Schema::create('themes', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('tier', 16)->default('free');
            $table->jsonb('tokens');
            $table->jsonb('fonts');
            $table->string('preview_url')->nullable();
            $table->foreignUuid('designer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });
        Pg::checkIn('themes', 'tier', self::TIERS);

        Schema::create('templates', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('occasion', 32)->index();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('tier', 16)->default('free');
            $table->string('opening_id', 40);
            $table->foreign('opening_id')->references('id')->on('openings');
            $table->string('theme_id', 64);
            $table->foreign('theme_id')->references('id')->on('themes');
            $table->jsonb('default_config');
            $table->integer('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });
        Pg::checkIn('templates', 'tier', self::TIERS);
    }

    public function down(): void
    {
        foreach (['templates', 'themes', 'openings', 'org_members', 'organizations', 'addons', 'plans'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
