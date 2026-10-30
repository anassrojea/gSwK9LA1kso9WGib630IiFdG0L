<?php

use App\Models\Event;
use App\Models\HallLayout;
use App\Models\Organization;
use App\Models\OrgMedia;
use App\Models\OrgMember;
use App\Models\User;
use App\Models\VenueProfile;
use App\Services\HallGenerator;
use App\Services\InvitationConfig\InvitationConfig;
use App\Services\InvitationConfig\InvitationConfigValidator;
use Database\Seeders\CatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\VenueFixtures;

/*
| The venue's own profile at /dashboard/venue (phase 8B) and "Create your
| invitation at this venue" (POST /create + hall_layout_id).
*/

beforeEach(fn () => VenueFixtures::plans());

/**
 * @return array{0: Organization, 1: User, 2: User, 3: User}
 */
function p8bVenueTeam(?string $plan = 'venue_pro'): array
{
    $org = Organization::factory()->venue()->create(['name' => 'Al Noor Hall', 'plan_id' => $plan]);
    $members = [];
    foreach ([OrgMember::ROLE_OWNER, OrgMember::ROLE_STAFF, OrgMember::ROLE_DOOR] as $role) {
        $user = User::factory()->create();
        OrgMember::factory()->create(['org_id' => $org->id, 'user_id' => $user->id, 'role' => $role]);
        $members[] = $user;
    }

    return [$org, ...$members];
}

function p8bProfileData(Organization $org, array $overrides = []): array
{
    return $overrides + [
        'org_id' => $org->id,
        'slug' => 'al-noor-hall',
        'name_en' => 'Al Noor Hall',
        'venue_type' => 'hall',
        'city' => 'riyadh',
        'area' => 'الملقا',
        'description_ar' => "قاعة أنيقة.\n\nمواقف واسعة.",
        'description_en' => 'An elegant hall.',
        'capacity' => 350,
        'amenities' => ['parking', 'prayer_room'],
        'whatsapp' => '+966 50 000 0001',
        'map_url' => 'https://maps.app.goo.gl/xyz',
        'listed' => true,
        'public_halls' => [],
    ];
}

it('shows owners and staff their venue profile form, and nobody else', function () {
    [$org, $owner, $staff, $door] = p8bVenueTeam();

    foreach ([$owner, $staff] as $user) {
        $this->actingAs($user)->get('/dashboard/venue')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('venue/Profile')
                ->where('org.id', $org->id)->where('feature', true)->where('profile.exists', false)->where('profile.slug', 'al-noor-hall')
                ->has('options.cities')->has('translations.venues.dashboard'));
    }

    $this->actingAs($door)->get('/dashboard/venue')->assertOk()->assertInertia(fn (Assert $page) => $page->where('org', null));
    $this->actingAs($door)->put('/dashboard/venue', p8bProfileData($org))->assertForbidden();
    $this->actingAs(User::factory()->create())->put('/dashboard/venue', p8bProfileData($org))->assertForbidden();
});

it('saves the profile, asks for review when listed and picks the public halls', function () {
    [$org, $owner] = p8bVenueTeam();
    $hall = HallLayout::query()->create(['org_id' => $org->id, 'name' => 'Ballroom', 'layout' => app(HallGenerator::class)->generate(4, 8)]);
    $other = HallLayout::query()->create(['owner_id' => $owner->id, 'name' => 'Not the venue\'s', 'layout' => app(HallGenerator::class)->generate(2, 8)]);

    $this->actingAs($owner)->put('/dashboard/venue', p8bProfileData($org, ['public_halls' => [$hall->id, $other->id], 'amenities' => ['parking', 'parking']]))
        ->assertRedirect("/dashboard/venue?org={$org->id}")
        ->assertSessionHasNoErrors();

    $profile = VenueProfile::query()->sole();
    expect($profile->status)->toBe(VenueProfile::STATUS_PENDING)
        ->and($profile->listed)->toBeTrue()
        ->and($profile->whatsapp)->toBe('+966500000001')
        ->and($profile->amenities)->toBe(['parking'])
        ->and($profile->qr_code)->toHaveLength(8)
        ->and($hall->fresh()->public)->toBeTrue()
        ->and($other->fresh()->public)->toBeFalse();

    // not public until an admin approves
    $this->get('/venues/al-noor-hall')->assertNotFound();

    // opting out while pending goes back to draft
    $this->actingAs($owner)->put('/dashboard/venue', p8bProfileData($org, ['listed' => false]))->assertSessionHasNoErrors();
    expect($profile->fresh()->status)->toBe(VenueProfile::STATUS_DRAFT);
});

it('keeps the listing to organizations whose plan includes it, and degrades without a plan', function () {
    [$org, $owner] = p8bVenueTeam(plan: null);

    $this->actingAs($owner)->get('/dashboard/venue')->assertOk()->assertInertia(fn (Assert $page) => $page->where('feature', false));

    $this->actingAs($owner)->put('/dashboard/venue', p8bProfileData($org))->assertSessionHasErrors('listed');
    expect(VenueProfile::query()->count())->toBe(0);

    // the profile itself can still be prepared
    $this->actingAs($owner)->put('/dashboard/venue', p8bProfileData($org, ['listed' => false]))->assertSessionHasNoErrors();
    expect(VenueProfile::query()->sole()->status)->toBe(VenueProfile::STATUS_DRAFT);
});

it('validates the page address and the links', function () {
    [$org, $owner] = p8bVenueTeam();
    VenueProfile::factory()->create(['slug' => 'taken-hall']);

    $this->actingAs($owner)->put('/dashboard/venue', p8bProfileData($org, ['slug' => 'taken-hall']))->assertSessionHasErrors('slug');
    $this->actingAs($owner)->put('/dashboard/venue', p8bProfileData($org, ['slug' => 'Not OK!']))->assertSessionHasErrors('slug');
    $this->actingAs($owner)->put('/dashboard/venue', p8bProfileData($org, ['map_url' => 'javascript:alert(1)']))->assertSessionHasErrors('map_url');
    $this->actingAs($owner)->put('/dashboard/venue', p8bProfileData($org, ['amenities' => ['helipad']]))->assertSessionHasErrors('amenities.0');
});

it('uploads photos and one logo under the organization, and removes them', function () {
    Storage::fake('public');
    config(['filesystems.media_disk' => 'public']);
    [$org, $owner, $staff] = p8bVenueTeam();
    $this->actingAs($owner)->put('/dashboard/venue', p8bProfileData($org))->assertSessionHasNoErrors();

    $this->actingAs($staff)->post('/dashboard/venue/media', ['org_id' => $org->id, 'kind' => 'photo', 'file' => UploadedFile::fake()->image('hall.jpg', 1200, 800)])->assertRedirect();
    $this->actingAs($staff)->post('/dashboard/venue/media', ['org_id' => $org->id, 'kind' => 'logo', 'file' => UploadedFile::fake()->image('logo.png', 200, 200)])->assertRedirect();
    $this->actingAs($staff)->post('/dashboard/venue/media', ['org_id' => $org->id, 'kind' => 'logo', 'file' => UploadedFile::fake()->image('logo2.png', 200, 200)])->assertRedirect();

    $photo = OrgMedia::query()->where('kind', 'photo')->sole();
    $logo = OrgMedia::query()->where('kind', 'logo')->sole();
    expect($photo->storage_key)->toStartWith("orgs/{$org->id}/")->and($photo->width)->toBe(1200)
        ->and(VenueProfile::query()->sole()->logo_id)->toBe($logo->id);
    Storage::disk('public')->assertExists($photo->storage_key);

    $this->actingAs($staff)->post('/dashboard/venue/media', ['org_id' => $org->id, 'kind' => 'photo', 'file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
        ->assertSessionHasErrors('file');
    $this->actingAs(User::factory()->create())->post('/dashboard/venue/media', ['org_id' => $org->id, 'kind' => 'photo', 'file' => UploadedFile::fake()->image('x.jpg')])->assertForbidden();
    $this->actingAs(User::factory()->create())->delete("/dashboard/venue/media/{$photo->id}")->assertForbidden();

    $this->actingAs($owner)->delete("/dashboard/venue/media/{$photo->id}")->assertRedirect();
    expect(OrgMedia::query()->where('kind', 'photo')->count())->toBe(0);
    Storage::disk('public')->assertMissing($photo->storage_key);
});

it('starts a draft in the venue\'s hall from its directory page', function () {
    $this->seed(CatalogSeeder::class);
    [$org] = p8bVenueTeam();
    $profile = VenueProfile::factory()->approved()->create(['org_id' => $org->id, 'slug' => 'al-noor-hall', 'area' => 'الملقا', 'lat' => 24.8, 'lng' => 46.6]);
    $hall = HallLayout::query()->create(['org_id' => $org->id, 'name' => 'Ballroom', 'public' => true, 'layout' => app(HallGenerator::class)->generate(4, 8)]);
    $private = HallLayout::query()->create(['org_id' => $org->id, 'name' => 'Office', 'public' => false, 'layout' => app(HallGenerator::class)->generate(1, 8)]);

    $this->post('/create', ['occasion' => 'wedding', 'hall_layout_id' => $hall->id])->assertRedirect();
    $event = Event::query()->latest()->first();
    $place = InvitationConfig::section($event->invitation->config, 'place');

    expect($event->hall_layout_id)->toBe($hall->id)
        ->and($event->venue_name)->toBe('Al Noor Hall')
        ->and($place['name'])->toBe('Al Noor Hall')
        ->and($place['address'])->toBe('الملقا، الرياض')
        ->and($place['lat'])->toEqual(24.8);
    // the config stays valid
    expect(app(InvitationConfigValidator::class)->validate($event->invitation->config))->toBe([]);

    // only public halls of listed venues
    $this->post('/create', ['occasion' => 'wedding', 'hall_layout_id' => $private->id])->assertSessionHasErrors('hall_layout_id');
    $profile->update(['status' => VenueProfile::STATUS_HIDDEN]);
    $this->post('/create', ['occasion' => 'wedding', 'hall_layout_id' => $hall->id])->assertSessionHasErrors('hall_layout_id');
    expect(Event::query()->count())->toBe(1);
});
