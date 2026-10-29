<?php

use App\Events\RsvpSubmitted;
use App\Models\Event;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\OpenLink;
use App\Models\Rsvp;
use Database\Factories\InvitationFactory;
use Illuminate\Support\Facades\Event as Events;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

/**
 * A published invitation with its open link switched on.
 */
function openLinkFor(int $cap = 20, ?array $config = null, bool $enabled = true): OpenLink
{
    $event = Event::factory()->create();
    Invitation::factory()->for($event)->create(['config' => $config ?? InvitationFactory::sampleConfig()]);

    return OpenLink::query()->create(['event_id' => $event->id, 'cap' => $cap, 'enabled' => $enabled]);
}

function openReply(OpenLink $link, array $data): TestResponse
{
    return test()->postJson("/i/{$link->token}/rsvp", $data + ['attending' => true]);
}

it('renders the same invitation for the open link, with a name field', function () {
    $link = openLinkFor();

    expect($link->token)->toHaveLength(OpenLink::TOKEN_LENGTH)->toMatch('/^[A-Za-z0-9]+$/');

    $this->get("/i/{$link->token}")
        ->assertOk()
        ->assertSee('sec-rsvp', false)
        ->assertSee('name="name"', false)
        ->assertSee('data-full', false)
        ->assertSee('"state":"\/i\/'.$link->token.'\/state"', false)
        ->assertHeader('Cache-Control', 'max-age=0, public, s-maxage=300');
});

it('does not show the name field on personal links', function () {
    [, $household] = invitedHousehold();

    $this->get("/i/{$household->token}")->assertOk()->assertDontSee('name="name"', false);
});

it('is a 404 when the open link is off, the invitation unpublished or the event archived', function () {
    $off = openLinkFor(enabled: false);
    $this->get("/i/{$off->token}")->assertNotFound();
    $this->getJson("/i/{$off->token}/state")->assertNotFound();
    $this->postJson("/i/{$off->token}/rsvp", ['name' => 'Ali', 'attending' => true, 'people_count' => 1])->assertNotFound();

    $draft = openLinkFor();
    $draft->event->invitation->update(['published_at' => null]);
    $this->get("/i/{$draft->token}")->assertNotFound();

    $archived = openLinkFor();
    $archived->event->update(['status' => 'archived']);
    $this->get("/i/{$archived->token}")->assertNotFound();
});

it('reports the seats left in /state', function () {
    $link = openLinkFor(cap: 12);

    $this->getJson("/i/{$link->token}/state")
        ->assertOk()
        ->assertJsonPath('household.name', null)
        ->assertJsonPath('household.seats_allowed', OpenLink::MAX_PER_REPLY)
        ->assertJsonPath('rsvp', null)
        ->assertJsonPath('rsvp_open', true)
        ->assertJsonPath('open_link.seats_left', 12)
        ->assertJsonPath('open_link.max_per_reply', OpenLink::MAX_PER_REPLY)
        ->assertJsonPath('open_link.full', false)
        ->assertJsonPath('reply_token', null)
        ->assertHeader('Cache-Control', 'no-store, private');

    openReply($link, ['name' => 'خالد العلي', 'people_count' => 9])->assertOk();

    $this->getJson("/i/{$link->token}/state")
        ->assertJsonPath('open_link.seats_left', 3)
        ->assertJsonPath('open_link.max_per_reply', 3)
        ->assertJsonPath('household.seats_allowed', 3);
});

it('creates a household tagged open-link with its reply', function () {
    Events::fake([RsvpSubmitted::class]);
    $link = openLinkFor();

    $response = openReply($link, ['name' => '  خالد   العلي ', 'people_count' => 4])
        ->assertOk()
        ->assertJsonPath('household.name', 'خالد العلي')
        ->assertJsonPath('rsvp.attending', true)
        ->assertJsonPath('rsvp.people_count', 4)
        ->assertJsonPath('open_link.seats_left', 16);

    $household = Household::query()->where('event_id', $link->event_id)->sole();
    expect($household)
        ->display_name->toBe('خالد العلي')
        ->seats_allowed->toBe(4)
        ->tags->toBe([OpenLink::TAG])
        ->token->toBe($response->json('reply_token'))
        ->and($household->rsvp->people_count)->toBe(4);

    Events::assertDispatched(RsvpSubmitted::class);
});

it('requires a name and keeps one reply within the per-reply maximum', function () {
    $link = openLinkFor(cap: 100);

    openReply($link, ['people_count' => 2])->assertUnprocessable()->assertJsonValidationErrors('name');
    openReply($link, ['name' => 'Ali', 'people_count' => OpenLink::MAX_PER_REPLY + 1])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('people_count');

    expect(Household::query()->count())->toBe(0);
});

it('stores declines without using places', function () {
    $link = openLinkFor(cap: 2);

    openReply($link, ['name' => 'Sara', 'attending' => false])
        ->assertOk()
        ->assertJsonPath('rsvp.attending', false)
        ->assertJsonPath('open_link.seats_left', 2);

    expect(Household::query()->sole()->seats_allowed)->toBe(1);
});

it('never confirms more people than the cap', function () {
    $link = openLinkFor(cap: 5);

    openReply($link, ['name' => 'A', 'people_count' => 3])->assertOk();
    // the boundary: 3 + 3 > 5
    openReply($link, ['name' => 'B', 'people_count' => 3])
        ->assertUnprocessable()
        ->assertJsonPath('errors.people_count.0', 'بقي مكانان فقط.');
    openReply($link, ['name' => 'B', 'people_count' => 2])->assertOk()->assertJsonPath('open_link.seats_left', 0);
    openReply($link, ['name' => 'C', 'people_count' => 1])
        ->assertUnprocessable()
        ->assertJsonPath('errors.people_count.0', __('invite.open_link.full'));

    $this->getJson("/i/{$link->token}/state")
        ->assertJsonPath('open_link.seats_left', 0)
        ->assertJsonPath('open_link.full', true);

    expect(Household::query()->count())->toBe(2)
        ->and((int) Rsvp::query()->sum('people_count'))->toBe(5);
});

it('holds the cap when replies race for the last places', function () {
    $link = openLinkFor(cap: 10);
    openReply($link, ['name' => 'First', 'people_count' => 8])->assertOk();

    // back-to-back requests at the boundary: each one sums under the link's row lock
    $statuses = collect(['X', 'Y', 'Z'])->map(fn ($name) => openReply($link, ['name' => $name, 'people_count' => 2])->status());

    expect($statuses->all())->toBe([200, 422, 422])
        ->and($link->peopleCount())->toBe(10)
        ->and($link->households()->count())->toBe(2);
});

it('lets the guest change their own reply with the reply token instead of adding a household', function () {
    $link = openLinkFor(cap: 6);
    $token = openReply($link, ['name' => 'Khalid', 'people_count' => 4])->json('reply_token');
    openReply($link, ['name' => 'Huda', 'people_count' => 2])->assertOk();

    $this->getJson("/i/{$link->token}/state?reply={$token}")
        ->assertJsonPath('household.name', 'Khalid')
        ->assertJsonPath('rsvp.people_count', 4)
        ->assertJsonPath('reply_token', $token)
        // the guest's own 4 people count as available to them
        ->assertJsonPath('household.seats_allowed', 4);

    openReply($link, ['name' => 'Khalid Ali', 'people_count' => 3, 'reply_token' => $token])
        ->assertOk()
        ->assertJsonPath('reply_token', $token)
        ->assertJsonPath('rsvp.people_count', 3)
        ->assertJsonPath('open_link.seats_left', 1);

    // still bound by the cap: 2 others + 5 > 6
    openReply($link, ['name' => 'Khalid Ali', 'people_count' => 5, 'reply_token' => $token])->assertUnprocessable();

    openReply($link, ['name' => 'Khalid Ali', 'attending' => false, 'reply_token' => $token])->assertOk();

    expect($link->households()->count())->toBe(2)
        ->and(Household::query()->where('token', $token)->sole())
        ->display_name->toBe('Khalid Ali')
        ->rsvp->attending->toBeFalse();
});

it('ignores reply tokens that belong to other households', function () {
    $link = openLinkFor();
    $personal = Household::factory()->for($link->event)->create(['display_name' => 'Invited family']);
    $elsewhere = openLinkFor();
    $foreign = openReply($elsewhere, ['name' => 'Other event', 'people_count' => 1])->json('reply_token');

    $this->getJson("/i/{$link->token}/state?reply={$personal->token}")->assertJsonPath('household.name', null);

    openReply($link, ['name' => 'New guest', 'people_count' => 1, 'reply_token' => $personal->token])->assertOk();
    openReply($link, ['name' => 'Another', 'people_count' => 1, 'reply_token' => $foreign])->assertOk();

    expect($personal->fresh()->display_name)->toBe('Invited family')
        ->and($personal->rsvp()->exists())->toBeFalse()
        ->and($link->households()->count())->toBe(2);
});

it('rate limits open-link replies per device like personal links', function () {
    $link = openLinkFor(cap: 1000);
    RateLimiter::clear('invite-rsvp');

    foreach (range(1, 10) as $i) {
        openReply($link, ['name' => "Guest {$i}", 'people_count' => 1])->assertOk();
    }

    openReply($link, ['name' => 'Guest 11', 'people_count' => 1])->assertTooManyRequests();

    // another device on the same link still gets through
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->postJson("/i/{$link->token}/rsvp", ['name' => 'Elsewhere', 'attending' => true, 'people_count' => 1])
        ->assertOk();
});

it('counts opens on the link, not on a household, once per device', function () {
    $link = openLinkFor();

    $this->get("/i/{$link->token}")->assertOk();
    $this->getJson("/i/{$link->token}/state?open=1")->assertOk();

    expect($link->fresh())
        ->open_count->toBe(1)
        ->first_opened_at->not->toBeNull();

    $this->withHeader('User-Agent', 'Another phone')->getJson("/i/{$link->token}/state?open=1");

    expect($link->fresh()->open_count)->toBe(2)
        ->and(Household::query()->count())->toBe(0);
});

it('keeps the cutoff of the RSVP section', function () {
    $link = openLinkFor(config: withRsvpSection(['cutoff' => now()->subDay()->toIso8601String()]));

    $this->getJson("/i/{$link->token}/state")->assertJsonPath('rsvp_open', false);
    openReply($link, ['name' => 'Late', 'people_count' => 1])->assertUnprocessable()->assertJsonValidationErrors('attending');
});
