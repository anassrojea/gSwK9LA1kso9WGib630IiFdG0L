<?php

use App\Models\Entitlement;
use App\Models\Event;
use App\Models\Invitation;
use App\Models\OpenLink;
use App\Models\Plan;
use App\Models\User;
use App\Services\Entitlements;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    $this->event = Event::factory()->create(['plan_id' => 'free']);
    Invitation::factory()->for($this->event)->create();
    $this->url = "/events/{$this->event->id}/open-link";
});

it('is only for the event owner', function () {
    $this->getJson($this->url)->assertUnauthorized();
    $this->putJson($this->url, ['enabled' => true, 'cap' => 30])->assertUnauthorized();

    $this->actingAs(User::factory()->create());
    $this->getJson($this->url)->assertForbidden();
    $this->putJson($this->url, ['enabled' => true, 'cap' => 30])->assertForbidden();

    expect(OpenLink::query()->count())->toBe(0);
});

it('shows the link as off before it is used', function () {
    $this->actingAs($this->event->owner)->getJson($this->url)
        ->assertOk()
        ->assertJsonPath('available', true)
        ->assertJsonPath('enabled', false)
        ->assertJsonPath('url', null)
        ->assertJsonPath('max_cap', 50)
        ->assertJsonPath('max_per_reply', OpenLink::MAX_PER_REPLY)
        ->assertJsonPath('endpoint', $this->url);
});

it('turns the open link on with a cap, and off again keeping the same link', function () {
    $this->actingAs($this->event->owner);

    $on = $this->putJson($this->url, ['enabled' => true, 'cap' => 40])
        ->assertOk()
        ->assertJsonPath('enabled', true)
        ->assertJsonPath('cap', 40)
        ->assertJsonPath('seats_left', 40)
        ->assertJsonPath('people', 0)
        ->assertJsonPath('published', true);

    $link = OpenLink::query()->sole();
    expect($on->json('url'))->toBe(rtrim(config('app.invite_url'), '/')."/i/{$link->token}");
    $this->get("/i/{$link->token}")->assertOk();

    $this->putJson($this->url, ['enabled' => false])->assertOk()->assertJsonPath('enabled', false);
    $this->get("/i/{$link->token}")->assertNotFound();

    $this->putJson($this->url, ['enabled' => true])->assertOk()->assertJsonPath('cap', 40);
    expect(OpenLink::query()->sole()->token)->toBe($link->token);

    $this->putJson($this->url, ['cap' => 25])->assertOk()->assertJsonPath('cap', 25)->assertJsonPath('enabled', true);
});

it('needs a cap within the plan guest limit', function () {
    $this->actingAs($this->event->owner);

    $this->putJson($this->url, ['enabled' => true])->assertUnprocessable()->assertJsonValidationErrors('cap');
    $this->putJson($this->url, ['enabled' => true, 'cap' => 0])->assertUnprocessable()->assertJsonValidationErrors('cap');
    $this->putJson($this->url, ['enabled' => true, 'cap' => 51])->assertUnprocessable()->assertJsonValidationErrors('cap');

    $this->event->update(['plan_id' => 'premium']);
    $this->putJson($this->url, ['enabled' => true, 'cap' => 300])->assertOk();
});

it('is gated by the open_link entitlement, which every plan includes', function () {
    expect(Plan::query()->get()->every(fn (Plan $p) => in_array(Entitlements::OPEN_LINK, $p->limits['features'], true)))->toBeTrue();

    // a plan without it (e.g. a custom one) cannot switch it on, unless granted
    Plan::query()->create(['id' => 'bare', 'name' => 'Bare', 'price_cents' => 0, 'currency' => 'USD', 'limits' => ['guests' => 20, 'features' => [Entitlements::RSVP]], 'active' => false]);
    $this->event->update(['plan_id' => 'bare']);
    $this->actingAs($this->event->owner);

    $this->getJson($this->url)->assertJsonPath('available', false);
    $this->putJson($this->url, ['enabled' => true, 'cap' => 10])->assertForbidden();

    Entitlement::factory()->create(['event_id' => $this->event->id, 'feature' => Entitlements::OPEN_LINK]);
    $this->putJson($this->url, ['enabled' => true, 'cap' => 10])->assertOk();
});

it('reports replies, people and opens for the dashboard', function () {
    $this->actingAs($this->event->owner)->putJson($this->url, ['enabled' => true, 'cap' => 10]);
    $link = OpenLink::query()->sole();

    $this->get("/i/{$link->token}");
    $this->postJson("/i/{$link->token}/rsvp", ['name' => 'Ali', 'attending' => true, 'people_count' => 3]);
    $this->postJson("/i/{$link->token}/rsvp", ['name' => 'Mona', 'attending' => false]);

    $this->getJson($this->url)
        ->assertJsonPath('people', 3)
        ->assertJsonPath('seats_left', 7)
        ->assertJsonPath('replies', 2)
        ->assertJsonPath('opens', 1);
});

it('shows the panel on the review step and lets the host publish with only the open link', function () {
    $this->actingAs($this->event->owner);
    $this->event->invitation->update(['published_at' => null]);
    $this->event->update(['status' => 'draft']);

    $this->get("/create/{$this->event->id}/review")
        ->assertInertia(fn ($page) => $page->where('openLink.enabled', false)->where('openLink.endpoint', $this->url));

    $this->post("/create/{$this->event->id}/publish")->assertSessionHasErrors('publish');

    $this->putJson($this->url, ['enabled' => true, 'cap' => 20])->assertOk();
    $this->post("/create/{$this->event->id}/publish")->assertSessionHasNoErrors();

    expect($this->event->invitation->fresh()->published_at)->not->toBeNull();
});

it('lists only personal links on the review step, not open-link replies', function () {
    $this->actingAs($this->event->owner);
    $this->event->households()->create(['display_name' => 'Invited family', 'seats_allowed' => 2]);
    $this->putJson($this->url, ['enabled' => true, 'cap' => 20]);
    $this->postJson('/i/'.OpenLink::query()->sole()->token.'/rsvp', ['name' => 'Walk-in', 'attending' => true, 'people_count' => 4]);

    $this->get("/create/{$this->event->id}/review")
        ->assertInertia(fn ($page) => $page->has('households', 1)->where('households.0.name', 'Invited family')->where('seats', 2));
});
