<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8C, template marketplace (plan sections 2, 5, 6, 13.2; docs/MARKETPLACE.md).
 *
 * designer_profiles   a user's application to sell templates, approved in Filament
 * templates           marketplace columns: the designer, the review state, prices per currency;
 *                     the live columns (opening, theme, default_config) are what hosts get
 * template_revisions  every version a designer submits; approving one copies it onto the template,
 *                     so editing a published template never changes it until the new revision is approved
 * marketplace_signature_uses  a Signature event publishing a template "included in Signature" (paid from a monthly pool later)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('designer_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('slug', 64)->unique();
            $table->string('display_name_ar', 80);
            $table->string('display_name_en', 80);
            $table->text('bio_ar')->nullable();
            $table->text('bio_en')->nullable();
            $table->jsonb('portfolio_links')->nullable();
            // encrypted (bank, IBAN, holder): the model casts it, nothing in plain text
            $table->text('payout_details')->nullable();
            $table->char('country', 2)->nullable();
            $table->string('status', 16)->default('pending');
            // basis points of the net line amount; null: config('marketplace.designer_share_bp')
            $table->integer('commission_bp')->nullable();
            $table->timestampTz('terms_accepted_at')->nullable();
            $table->string('terms_version', 32)->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('status_reason')->nullable();
            $table->timestampsTz();

            $table->index('status');
        });
        Pg::checkIn('designer_profiles', 'status', ['pending', 'approved', 'suspended']);
        Pg::check('designer_profiles', 'designer_profiles_commission_check', 'commission_bp is null or (commission_bp between 0 and 10000)');

        Schema::table('templates', function (Blueprint $table) {
            $table->foreignUuid('designer_id')->nullable()->constrained('users')->nullOnDelete();
            // null for the house catalog; draft | in_review | published | rejected | unpublished
            $table->string('marketplace_status', 16)->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            // media disk path under designers/{user_id}/
            $table->string('cover_path')->nullable();
            // {"USD": 900, "SAR": 3400, "SYP": 120000} in minor units; null: the template is not sold
            $table->jsonb('prices')->nullable();
            $table->boolean('included_in_signature')->default(false);
            $table->timestampTz('published_at')->nullable();
            $table->uuid('live_revision_id')->nullable();
            $table->integer('sales_count')->default(0);

            $table->index(['designer_id', 'marketplace_status']);
        });
        Pg::checkIn('templates', 'marketplace_status', ['draft', 'in_review', 'published', 'rejected', 'unpublished'], nullable: true);

        Schema::create('template_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('template_id', 64);
            $table->foreign('template_id')->references('id')->on('templates')->cascadeOnDelete();
            $table->integer('number');
            $table->string('name_ar');
            $table->string('name_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('occasion', 32);
            $table->string('opening_id', 40);
            $table->string('theme_id', 64);
            $table->jsonb('default_config');
            $table->jsonb('prices')->nullable();
            $table->boolean('included_in_signature')->default(false);
            // draft | in_review | approved | rejected | superseded
            $table->string('status', 16)->default('draft');
            $table->text('rejection_reason')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['template_id', 'number']);
            $table->index('status');
        });
        Pg::checkIn('template_revisions', 'status', ['draft', 'in_review', 'approved', 'rejected', 'superseded']);
        Schema::table('templates', function (Blueprint $table) {
            $table->foreign('live_revision_id')->references('id')->on('template_revisions')->nullOnDelete();
        });

        // "template:{id}" entitlements (one marketplace template for one event) outgrow 40 characters
        if (Pg::active()) {
            DB::statement('alter table entitlements alter column feature type varchar(100)');
        }

        Schema::create('marketplace_signature_uses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('template_id', 64);
            $table->foreign('template_id')->references('id')->on('templates')->cascadeOnDelete();
            $table->foreignUuid('designer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('plan_id', 40)->nullable();
            // YYYY-MM: the month whose pool pays it
            $table->char('period', 7);
            $table->timestampsTz();

            $table->unique(['template_id', 'event_id']);
            $table->index(['designer_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_signature_uses');
        Schema::table('templates', fn (Blueprint $table) => $table->dropForeign(['live_revision_id']));
        Schema::dropIfExists('template_revisions');
        Schema::table('templates', function (Blueprint $table) {
            $table->dropForeign(['designer_id']);
            $table->dropIndex(['designer_id', 'marketplace_status']);
            $table->dropColumn(['designer_id', 'marketplace_status', 'description_ar', 'description_en', 'cover_path', 'prices', 'included_in_signature', 'published_at', 'live_revision_id', 'sales_count']);
        });
        Schema::dropIfExists('designer_profiles');
    }
};
