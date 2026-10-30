<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The venue directory (phase 8B, plan sections 13.1 and 14.8): one public
 * profile per organization, shown at /venues/{slug} once the venue opts in
 * (listed), an admin approves it (status) and the organization's plan
 * includes directory_listing. org_media holds the venue's own photos and
 * logo on the media disk under orgs/{org_id}/.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->unique()->constrained('organizations')->cascadeOnDelete();
            $table->string('slug', 80)->unique();
            $table->string('name_en', 120)->nullable();
            $table->string('venue_type', 16)->default('hall');
            $table->string('city', 40);
            $table->string('area', 80)->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->integer('capacity')->nullable();
            $table->jsonb('amenities')->default('[]');
            $table->string('whatsapp', 32)->nullable();
            $table->string('map_url', 500)->nullable();
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();
            $table->foreignUuid('logo_id')->nullable();
            // the venue opts in; an admin approves (or hides); featured venues come first
            $table->boolean('listed')->default(false);
            $table->string('status', 16)->default('draft');
            $table->boolean('featured')->default(false);
            $table->timestampTz('approved_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            // "Book your invitation with us" poster: /v/{qr_code}
            $table->string('qr_code', 12)->unique();
            $table->timestampsTz();

            $table->index(['status', 'listed', 'city']);
        });
        Pg::checkIn('venue_profiles', 'status', ['draft', 'pending', 'approved', 'hidden']);
        Pg::checkIn('venue_profiles', 'venue_type', ['hall', 'hotel', 'garden', 'resort', 'restaurant', 'other']);
        Pg::check('venue_profiles', 'venue_profiles_capacity_check', 'capacity is null or capacity between 1 and 100000');

        Schema::create('org_media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('kind', 8);
            $table->string('storage_key');
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->bigInteger('bytes')->default(0);
            $table->integer('sort')->default(0);
            $table->string('caption', 120)->nullable();
            $table->timestampsTz();

            $table->index(['org_id', 'kind', 'sort']);
        });
        Pg::checkIn('org_media', 'kind', ['logo', 'photo']);

        Schema::table('venue_profiles', function (Blueprint $table) {
            $table->foreign('logo_id')->references('id')->on('org_media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('venue_profiles', function (Blueprint $table) {
            $table->dropForeign(['logo_id']);
        });
        Schema::dropIfExists('org_media');
        Schema::dropIfExists('venue_profiles');
    }
};
