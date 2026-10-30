<?php

use App\Models\AuditLog;
use App\Models\OrgInvitation;
use App\Models\OrgMember;
use App\Models\User;
use App\Notifications\OrgInvited;
use App\Services\Org\OrgTeam;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\Support\OrgFixtures;

beforeEach(function () {
    OrgFixtures::setUp();
    Notification::fake();
});

/**
 * Invite through the team page and return the link from the email.
 */
function inviteLink($owner, $org, string $email, string $role = 'staff'): string
{
    test()->actingAs($owner)->post("/org/{$org->id}/invitations", ['email' => $email, 'role' => $role])->assertSessionHasNoErrors();
    $url = null;
    Notification::assertSentTo(new AnonymousNotifiable, OrgInvited::class, function (OrgInvited $n, $channels, $notifiable) use ($email, &$url) {
        if (($notifiable->routes['mail'] ?? null) === strtolower($email)) {
            $url = $n->url;

            return true;
        }

        return false;
    });

    return $url;
}

it('invites by email with a signed link that an existing user accepts', function () {
    [$org, $owner] = OrgFixtures::org();
    $sara = User::factory()->create(['email' => 'sara@example.com']);

    $url = inviteLink($owner, $org, 'Sara@Example.com', 'door');
    $invitation = OrgInvitation::query()->sole();
    expect($invitation->only('email', 'role'))->toBe(['email' => 'sara@example.com', 'role' => 'door'])
        ->and($invitation->token_hash)->toHaveLength(64)
        ->and($invitation->expires_at->diffInDays(now(), true))->toBeGreaterThan(6.9);

    $this->actingAs($sara)->get($url)->assertOk()->assertInertia(fn ($page) => $page->component('org/Invitation')->where('email_matches', true)->where('invitation.state', 'pending'));
    $this->actingAs($sara)->post($url)->assertRedirect("/org/{$org->id}");

    expect($sara->roleIn($org))->toBe('door')
        ->and($invitation->fresh()->accepted_by)->toBe($sara->id)
        ->and(AuditLog::query()->where('action', 'org.member_joined')->exists())->toBeTrue();

    // a tampered link is refused
    $this->actingAs($sara)->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();
});

it('accepts the invitation right after a new user registers', function () {
    [$org, $owner] = OrgFixtures::org();
    $url = inviteLink($owner, $org, 'new@example.com');
    auth()->logout();

    $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->where('signed_in_as', null));
    $this->post('/register', ['name' => 'New Person', 'email' => 'new@example.com', 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1'])
        ->assertRedirect();

    $user = User::query()->where('email', 'new@example.com')->sole();
    expect($user->roleIn($org))->toBe('staff');
});

it('accepts on login too, and refuses another email address', function () {
    [$org, $owner] = OrgFixtures::org();
    $right = User::factory()->create(['email' => 'right@example.com', 'password' => 'password']);
    $wrong = User::factory()->create(['email' => 'wrong@example.com']);
    $url = inviteLink($owner, $org, 'right@example.com');

    $this->actingAs($wrong)->get($url)->assertInertia(fn ($page) => $page->where('email_matches', false));
    $this->actingAs($wrong)->post($url)->assertSessionHasErrors('invitation');
    expect($wrong->roleIn($org))->toBeNull();

    auth()->logout();
    $this->get($url);
    $this->post('/login', ['email' => 'right@example.com', 'password' => 'password'])->assertRedirect();
    expect($right->roleIn($org))->toBe('staff');
});

it('expires invitations after 7 days', function () {
    [$org, $owner] = OrgFixtures::org();
    $sara = User::factory()->create(['email' => 'sara@example.com']);
    $url = inviteLink($owner, $org, 'sara@example.com');

    $this->travel(8)->days();
    $this->actingAs($sara)->get($url)->assertOk()->assertInertia(fn ($page) => $page->where('invitation.state', 'expired'));
    $this->actingAs($sara)->post($url)->assertSessionHasErrors('invitation');
    expect($sara->roleIn($org))->toBeNull();
});

it('enforces the plan\'s seats, counting pending invitations', function () {
    [$org, $owner] = OrgFixtures::org();
    // no plan: the owner and one more
    config(['org.free_seats' => 2]);

    inviteLink($owner, $org, 'one@example.com');
    $this->actingAs($owner)->post("/org/{$org->id}/invitations", ['email' => 'two@example.com', 'role' => 'staff'])->assertSessionHasErrors('email');

    // Venue Starter: 3 seats
    OrgFixtures::subscribe($org, 'venue_starter');
    inviteLink($owner, $org, 'two@example.com');
    $this->actingAs($owner)->post("/org/{$org->id}/invitations", ['email' => 'three@example.com', 'role' => 'staff'])->assertSessionHasErrors('email');
    expect(app(OrgTeam::class)->seatsUsed($org))->toBe(3);

    // revoking frees the seat
    $this->actingAs($owner)->delete("/org/{$org->id}/invitations/".OrgInvitation::query()->where('email', 'two@example.com')->value('id'))->assertRedirect();
    inviteLink($owner, $org, 'three@example.com');
});

it('keeps exactly one owner through ownership transfers', function () {
    [$org, $owner] = OrgFixtures::org();
    $staff = OrgFixtures::member($org);
    $owners = fn () => OrgMember::query()->where('org_id', $org->id)->where('role', 'owner')->pluck('user_id')->all();

    $this->actingAs($owner)->post("/org/{$org->id}/transfer", ['user_id' => $staff->id])->assertSessionHasNoErrors();
    expect($owners())->toBe([$staff->id])->and($owner->roleIn($org))->toBe('staff');

    // the old owner is staff now: no more team management
    $this->actingAs($owner)->post("/org/{$org->id}/transfer", ['user_id' => $owner->id])->assertForbidden();
    expect($owners())->toBe([$staff->id]);

    // the owner can't leave, be removed or lose the role; others can leave
    $this->actingAs($staff)->post("/org/{$org->id}/leave")->assertForbidden();
    $this->actingAs($staff)->delete("/org/{$org->id}/members/{$staff->id}")->assertSessionHasErrors('member');
    $this->actingAs($staff)->patch("/org/{$org->id}/members/{$staff->id}", ['role' => 'door'])->assertSessionHasErrors('role');
    $outsider = User::factory()->create();
    $this->actingAs($staff)->post("/org/{$org->id}/transfer", ['user_id' => $outsider->id])->assertSessionHasErrors('user_id');
    expect($owners())->toBe([$staff->id]);

    $this->actingAs($owner)->post("/org/{$org->id}/leave")->assertRedirect('/org');
    expect($owner->roleIn($org))->toBeNull()->and($owners())->toBe([$staff->id]);
});

it('lets the owner change roles and remove members; staff can\'t', function () {
    [$org, $owner] = OrgFixtures::org();
    $staff = OrgFixtures::member($org);
    $door = OrgFixtures::member($org, 'door');

    $this->actingAs($staff)->patch("/org/{$org->id}/members/{$door->id}", ['role' => 'staff'])->assertForbidden();
    $this->actingAs($staff)->post("/org/{$org->id}/invitations", ['email' => 'x@example.com', 'role' => 'staff'])->assertForbidden();

    $this->actingAs($owner)->patch("/org/{$org->id}/members/{$door->id}", ['role' => 'staff'])->assertSessionHasNoErrors();
    expect($door->roleIn($org))->toBe('staff');
    $this->actingAs($owner)->patch("/org/{$org->id}/members/{$door->id}", ['role' => 'owner'])->assertSessionHasErrors('role');

    $this->actingAs($owner)->delete("/org/{$org->id}/members/{$door->id}")->assertSessionHasNoErrors();
    expect($door->roleIn($org))->toBeNull();

    $this->actingAs($staff)->get("/org/{$org->id}/team")->assertOk()->assertInertia(fn ($page) => $page->where('can_manage', false)->has('members', 2)->has('invitations', 0));
});
