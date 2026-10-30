<?php

use App\Models\Event;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\OrgMember;
use App\Models\User;
use Database\Factories\InvitationFactory;

function patchConfig(Event $event, ?array $config = null)
{
    return test()->patchJson("/events/{$event->id}/config", ['config' => $config ?? InvitationFactory::sampleConfig('layali-envelope')]);
}

it('blocks access to another user\'s event', function () {
    $event = Event::factory()->create();
    Invitation::factory()->for($event)->create();
    $stranger = User::factory()->create();

    $this->actingAs($stranger);
    patchConfig($event)->assertForbidden();

    expect($stranger->can('view', $event))->toBeFalse()
        ->and($stranger->can('update', $event))->toBeFalse()
        ->and($stranger->can('delete', $event))->toBeFalse()
        ->and($event->invitation->fresh()->config['opening']['id'])->toBe('rose');
});

it('lets the owner update their event', function () {
    $event = Event::factory()->create();
    Invitation::factory()->for($event)->create();

    $this->actingAs($event->owner);
    patchConfig($event)->assertOk()->assertJson(['config_version' => 2]);

    expect($event->invitation->fresh()->config['opening']['id'])->toBe('envelope');
});

it('returns 404 for an event id that is not a UUID', function () {
    $this->actingAs(User::factory()->create())
        ->patchJson('/events/not-a-uuid/config', ['config' => []])
        ->assertNotFound();
});

it('requires login to update an event', function () {
    $event = Event::factory()->create();

    patchConfig($event)->assertUnauthorized();
});

it('gives organization members access according to their role', function (string $role, bool $view, bool $update, bool $delete) {
    $org = Organization::factory()->venue()->create();
    $event = Event::factory()->forOrganization($org)->create();
    $member = User::factory()->create();
    OrgMember::factory()->create(['org_id' => $org->id, 'user_id' => $member->id, 'role' => $role]);

    expect($member->can('view', $event))->toBe($view)
        ->and($member->can('update', $event))->toBe($update)
        ->and($member->can('delete', $event))->toBe($delete)
        ->and($member->can('checkIn', $event))->toBe($view);
})->with([
    'owner' => ['owner', true, true, true],
    'staff' => ['staff', true, true, false],
    'door' => ['door', true, false, false],
]);

it('does not give members of another organization access', function () {
    $event = Event::factory()->forOrganization()->create();
    $otherOrgOwner = User::factory()->create();
    OrgMember::factory()->create(['user_id' => $otherOrgOwner->id, 'role' => 'owner']);

    expect($otherOrgOwner->can('view', $event))->toBeFalse();
});

it('lets admins do anything', function () {
    $event = Event::factory()->forOrganization()->create();
    $admin = User::factory()->admin()->create();

    expect($admin->can('update', $event))->toBeTrue()
        ->and($admin->can('delete', $event->organization))->toBeTrue();
});

it('applies the event rules to its households', function () {
    $household = Household::factory()->create();
    $owner = $household->event->owner;
    $stranger = User::factory()->create();

    expect($owner->can('view', $household))->toBeTrue()
        ->and($owner->can('update', $household))->toBeTrue()
        ->and($owner->can('create', [Household::class, $household->event]))->toBeTrue()
        ->and($stranger->can('view', $household))->toBeFalse()
        ->and($stranger->can('update', $household))->toBeFalse()
        ->and($stranger->can('create', [Household::class, $household->event]))->toBeFalse();
});

it('lets only organization owners manage the organization', function () {
    $org = Organization::factory()->create();
    [$owner, $staff, $door, $outsider] = User::factory()->count(4)->create();
    OrgMember::factory()->create(['org_id' => $org->id, 'user_id' => $owner->id, 'role' => 'owner']);
    OrgMember::factory()->create(['org_id' => $org->id, 'user_id' => $staff->id, 'role' => 'staff']);
    OrgMember::factory()->create(['org_id' => $org->id, 'user_id' => $door->id, 'role' => 'door']);

    expect($owner->can('manageMembers', $org))->toBeTrue()
        ->and($staff->can('manageMembers', $org))->toBeFalse()
        ->and($staff->can('createEvents', $org))->toBeTrue()
        ->and($door->can('createEvents', $org))->toBeFalse()
        ->and($door->can('view', $org))->toBeTrue()
        ->and($outsider->can('view', $org))->toBeFalse();
});

it('keeps non-admins out of the Filament panel', function () {
    $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get('/admin')->assertOk();
});

it('only lists the user\'s own and organization events on the dashboard', function () {
    $user = User::factory()->create();
    $mine = Event::factory()->for($user, 'owner')->create(['title' => 'Mine']);
    $org = Organization::factory()->create();
    OrgMember::factory()->create(['org_id' => $org->id, 'user_id' => $user->id, 'role' => 'staff']);
    Event::factory()->forOrganization($org)->create(['title' => 'Org event']);
    Event::factory()->create(['title' => 'Someone else']);

    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->has('events', 2)
            ->where('events.0.id', fn ($id) => in_array($id, [$mine->id, Event::query()->where('title', 'Org event')->value('id')], true)));
});
