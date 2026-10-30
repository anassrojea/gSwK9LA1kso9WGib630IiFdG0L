<?php

use App\Jobs\PurgeEdgeCache;
use App\Models\Event;
use App\Models\Household;
use App\Models\OrgInvitation;
use App\Models\User;
use App\Notifications\OrgInvited;
use App\Services\Org\OrgSubscriptions;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\OrgFixtures;

beforeEach(function () {
    OrgFixtures::setUp();
    Notification::fake();
});

function publishedOrgEvent($org, $owner): array
{
    $event = OrgFixtures::event($org, $owner, ['status' => 'active', 'plan_id' => 'signature']);
    $event->invitation->update(['published_at' => now()]);
    $household = Household::factory()->for($event)->create();

    return [$event->fresh(), $household];
}

it('invites the couple to edit their event: they own it, the org keeps it, and they see only theirs', function () {
    [$org, $owner] = OrgFixtures::org(['white_label' => ['sender_name' => 'قاعة النور للحفلات']]);
    $staff = OrgFixtures::member($org);
    OrgFixtures::subscribe($org);
    $event = OrgFixtures::event($org, $staff);
    $other = OrgFixtures::event($org, $staff);
    $client = User::factory()->create(['email' => 'couple@example.com']);

    $this->actingAs($staff)->post("/org/{$org->id}/events/{$event->id}/client", ['email' => 'couple@example.com'])->assertSessionHasNoErrors();
    $invitation = OrgInvitation::query()->sole();
    expect($invitation->only('role', 'event_id'))->toBe(['role' => 'client', 'event_id' => $event->id]);

    $url = null;
    Notification::assertSentTo(new AnonymousNotifiable, OrgInvited::class, function (OrgInvited $n) use (&$url) {
        $url = $n->url;
        // white label: emails to clients go out under the organization's sender name
        $mail = $n->toMail(new AnonymousNotifiable);

        return ($mail->from[1] ?? null) === 'قاعة النور للحفلات';
    });

    $this->actingAs($client)->post($url)->assertRedirect("/create/{$event->id}/details");
    $event->refresh();
    expect($event->owner_id)->toBe($client->id)
        ->and($event->org_id)->toBe($org->id)
        // client invitations take no team seat and give no org role
        ->and($client->roleIn($org))->toBeNull();

    expect($client->can('update', $event))->toBeTrue()
        ->and($client->can('view', $other))->toBeFalse()
        ->and($staff->can('update', $event))->toBeTrue();

    $this->actingAs($client)->get('/dashboard')->assertInertia(fn ($page) => $page->has('events', 1)->where('events.0.id', $event->id));
    $this->actingAs($client)->get("/org/{$org->id}")->assertForbidden();
    $this->actingAs($staff)->get("/org/{$org->id}/events/{$event->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('org/Event')->where('client.email', 'couple@example.com'));
});

it('replaces the Daawa badge with the org logo and name when the plan has white label', function () {
    [$org, $owner] = OrgFixtures::org(['name' => 'قاعة الياسمين', 'logo_url' => 'https://daawa.app/storage/orgs/x/logo.png']);
    [, $household] = publishedOrgEvent($org, $owner);

    // no plan: the Daawa footer
    $this->get("/i/{$household->token}")->assertOk()->assertDontSee('data-org-brand', false);

    // Venue Starter has no white label
    $subscription = OrgFixtures::subscribe($org, 'venue_starter');
    $this->get("/i/{$household->token}")->assertDontSee('data-org-brand', false);

    $subscription->update(['plan_id' => 'venue_pro']);
    app(OrgSubscriptions::class)->applyOrgPlan($org);
    $this->get("/i/{$household->token}")->assertOk()
        ->assertSee('data-org-brand', false)
        ->assertSee('قاعة الياسمين')
        ->assertSee('https://daawa.app/storage/orgs/x/logo.png', false)
        ->assertDontSee(__('invite.footer'))
        ->assertDontSee(__('invite.make_yours'));
});

it('purges the org\'s cached invitations when its branding or plan changes', function () {
    [$org, $owner] = OrgFixtures::org();
    [$event] = publishedOrgEvent($org, $owner);
    OrgFixtures::event($org, $owner); // a draft: never cached
    Queue::fake();

    $org->update(['name' => 'New name']);
    Queue::assertPushed(PurgeEdgeCache::class, fn (PurgeEdgeCache $job) => $job->eventId === $event->id && $job->tokens === null);
    Queue::assertPushed(PurgeEdgeCache::class, 1);

    Queue::fake();
    $org->update(['city' => 'Damascus']);
    Queue::assertNothingPushed();

    OrgFixtures::subscribe($org);
    Queue::assertPushed(PurgeEdgeCache::class, fn (PurgeEdgeCache $job) => $job->eventId === $event->id);
});

it('saves white label settings and the logo, owner only', function () {
    Storage::fake('public');
    [$org, $owner] = OrgFixtures::org();
    $staff = OrgFixtures::member($org);
    OrgFixtures::subscribe($org);

    $this->actingAs($staff)->post("/org/{$org->id}/settings", ['name' => 'X'])->assertForbidden();

    $this->actingAs($owner)->post("/org/{$org->id}/settings", [
        'name' => 'قاعة النور',
        'brand_color' => '#7a3e2b',
        'sender_name' => 'Al Noor Events',
        'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
    ])->assertSessionHasNoErrors();

    $org->refresh();
    expect($org->white_label)->toBe(['brand_color' => '#7a3e2b', 'sender_name' => 'Al Noor Events'])
        ->and($org->logo_path)->toStartWith("orgs/{$org->id}/")
        ->and($org->logo_url)->toContain($org->logo_path);
    Storage::disk('public')->assertExists($org->logo_path);

    $this->actingAs($owner)->post("/org/{$org->id}/settings", ['brand_color' => 'red'])->assertSessionHasErrors('brand_color');
    $this->actingAs($owner)->get("/org/{$org->id}")->assertInertia(fn ($page) => $page->where('org.white_label.brand_color', '#7a3e2b'));
});

it('keeps an org event visible to its members and nobody else', function () {
    [$org, $owner] = OrgFixtures::org();
    $door = OrgFixtures::member($org, 'door');
    $event = OrgFixtures::event($org, $owner);
    $stranger = User::factory()->create();

    $this->actingAs($door)->get("/org/{$org->id}/events/{$event->id}")->assertOk()->assertInertia(fn ($page) => $page->where('can.update', false));
    $this->actingAs($stranger)->get("/org/{$org->id}/events/{$event->id}")->assertForbidden();
    // another org's event under this org's URL
    [$otherOrg, $otherOwner] = OrgFixtures::org();
    $foreign = OrgFixtures::event($otherOrg, $otherOwner);
    $this->actingAs($owner)->get("/org/{$org->id}/events/{$foreign->id}")->assertNotFound();
    expect(Event::query()->count())->toBe(2);
});
