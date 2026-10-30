<?php

use App\Models\Event;
use App\Models\HallLayout;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\OrgMember;
use App\Models\User;
use App\Services\HallGenerator;
use Database\Seeders\PlanSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{0: Organization, 1: User, 2: User, 3: User}
 */
function p7aVenue(): array
{
    $org = Organization::factory()->venue()->create();
    $members = [];
    foreach ([OrgMember::ROLE_OWNER, OrgMember::ROLE_STAFF, OrgMember::ROLE_DOOR] as $role) {
        $user = User::factory()->create();
        OrgMember::factory()->create(['org_id' => $org->id, 'user_id' => $user->id, 'role' => $role]);
        $members[] = $user;
    }

    return [$org, ...$members];
}

function p7aLayout(int $tables = 6, int $chairs = 8): array
{
    return app(HallGenerator::class)->generate($tables, $chairs, 'split', name: 'Main');
}

it('lists the venue\'s halls to its owners and staff', function () {
    [$org, $owner, $staff, $door] = p7aVenue();
    HallLayout::query()->create(['org_id' => $org->id, 'name' => 'Ballroom', 'layout' => p7aLayout()]);
    HallLayout::query()->create(['owner_id' => User::factory()->create()->id, 'name' => 'Someone else', 'layout' => p7aLayout()]);

    foreach ([$owner, $staff] as $user) {
        $this->actingAs($user)->get('/dashboard/halls')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('halls/Index')->has('halls', 1)->where('halls.0.name', 'Ballroom')->where('halls.0.can_edit', true)->where('halls.0.seats', 48));
    }

    // door staff only scan: no halls to manage
    $this->actingAs($door)->get('/dashboard/halls')->assertOk()->assertInertia(fn (Assert $page) => $page->has('halls', 0));
});

it('lets owners and staff create, edit and delete their venue\'s halls', function () {
    [$org, $owner, $staff] = p7aVenue();

    $this->actingAs($staff)->post('/dashboard/halls', ['org_id' => $org->id, 'name' => 'Garden', 'layout' => p7aLayout()])->assertRedirect();
    $hall = HallLayout::query()->sole();
    expect($hall->org_id)->toBe($org->id)->and($hall->owner_id)->toBeNull()->and($hall->layout['name'])->toBe('Garden');

    $layout = $hall->layout;
    $layout['tables'][0]['x'] = 99;
    $this->actingAs($owner)->put("/dashboard/halls/{$hall->id}", ['name' => 'Garden hall', 'layout' => $layout])->assertRedirect();
    expect($hall->fresh()->layout['tables'][0]['x'])->toEqual(99)->and($hall->fresh()->name)->toBe('Garden hall');

    $this->actingAs($owner)->get("/dashboard/halls/{$hall->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('halls/Edit')->where('hall.name', 'Garden hall'));

    $this->actingAs($staff)->delete("/dashboard/halls/{$hall->id}")->assertRedirect('/dashboard/halls');
    expect(HallLayout::query()->count())->toBe(0);
});

it('keeps door staff and outsiders away from a venue\'s halls', function () {
    [$org, , , $door] = p7aVenue();
    $hall = HallLayout::query()->create(['org_id' => $org->id, 'name' => 'Ballroom', 'layout' => p7aLayout()]);
    $outsider = User::factory()->create();

    foreach ([$door, $outsider] as $user) {
        $this->actingAs($user)->post('/dashboard/halls', ['org_id' => $org->id, 'name' => 'X', 'layout' => p7aLayout()])->assertForbidden();
        $this->actingAs($user)->get("/dashboard/halls/{$hall->id}")->assertForbidden();
        $this->actingAs($user)->put("/dashboard/halls/{$hall->id}", ['name' => 'X', 'layout' => p7aLayout()])->assertForbidden();
        $this->actingAs($user)->delete("/dashboard/halls/{$hall->id}")->assertForbidden();
    }
    expect($hall->fresh()->name)->toBe('Ballroom');
});

it('does not delete a hall an event uses', function () {
    [$org, $owner] = p7aVenue();
    $hall = HallLayout::query()->create(['org_id' => $org->id, 'name' => 'Ballroom', 'layout' => p7aLayout()]);
    Event::factory()->create(['hall_layout_id' => $hall->id, 'org_id' => $org->id]);

    $this->actingAs($owner)->delete("/dashboard/halls/{$hall->id}")->assertSessionHasErrors('hall');
    expect($hall->fresh())->not->toBeNull();
});

it('keeps a personal hall to its owner', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/dashboard/halls', ['name' => 'Home', 'layout' => p7aLayout(2, 4)])->assertRedirect();
    $hall = HallLayout::query()->sole();
    expect($hall->owner_id)->toBe($user->id);

    $this->actingAs(User::factory()->create())->get("/dashboard/halls/{$hall->id}")->assertForbidden();
});

it('offers the venue\'s halls in the planner of the venue\'s events', function () {
    app(PlanSeeder::class)->run();
    [$org, $owner] = p7aVenue();
    HallLayout::query()->create(['org_id' => $org->id, 'name' => 'Ballroom', 'layout' => p7aLayout()]);
    $event = Event::factory()->create(['org_id' => $org->id, 'plan_id' => null]);
    $org->update(['plan_id' => 'venue_pro']);
    Invitation::factory()->for($event)->create();

    $this->actingAs($owner)->get("/dashboard/events/{$event->id}/seating")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('locked', false)->where('hall', null)->has('halls', 1)->where('halls.0.name', 'Ballroom'));
});
