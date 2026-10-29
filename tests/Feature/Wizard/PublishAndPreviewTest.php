<?php

use App\Http\Controllers\Wizard\PreviewController;
use App\Models\Event;
use App\Models\LinkOpen;
use App\Models\Order;
use App\Models\User;
use App\Services\Payments\OrderFulfillment;
use Database\Factories\InvitationFactory;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlanSeeder;

beforeEach(fn () => $this->seed([PlanSeeder::class, CatalogSeeder::class]));

it('needs an account to publish', function () {
    $event = wizardDraft();
    $this->post("/events/{$event->id}/guests", ['name' => 'Umm Faisal', 'seats' => 3]);

    $this->post("/create/{$event->id}/publish")->assertRedirect('/login');

    expect($event->invitation->fresh()->published_at)->toBeNull()
        ->and($event->fresh()->status)->toBe('draft');
});

it('publishes once the guest has signed up and paid, then lists every personal link', function () {
    $event = wizardDraft();
    $this->post("/events/{$event->id}/guests/import", ['text' => "أم فيصل - 3\nKhaled Ali, 2"]);

    $this->get("/create/{$event->id}/review")
        ->assertInertia(fn ($page) => $page->component('create/Review')->where('plan.id', 'premium')->missing('households.0.link'));

    $this->post('/register', ['name' => 'Lina', 'email' => 'lina@example.test', 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1']);

    // personal links and a Premium design: publishing waits for the payment (phase 4)
    $this->post("/create/{$event->id}/publish")->assertSessionHasErrors('publish');
    expect($event->invitation->fresh()->published_at)->toBeNull();

    $order = Order::factory()->create(['user_id' => $event->fresh()->owner_id, 'event_id' => $event->id]);
    app(OrderFulfillment::class)->approveTransfer($order, User::factory()->admin()->create(), 'BANK-REF-1');

    $this->post("/create/{$event->id}/publish")->assertRedirect("/create/{$event->id}/review");

    $event->refresh();
    expect($event->status)->toBe('active')
        ->and($event->invitation->published_at)->not->toBeNull();

    $token = $event->households()->where('display_name', 'أم فيصل')->value('token');
    $this->get("/create/{$event->id}/review")
        ->assertInertia(fn ($page) => $page
            ->where('event.published', true)
            ->has('households', 2)
            ->where('households.0.link', url("/i/{$token}"))
            ->where('households.0.message', fn ($message) => str_contains($message, url("/i/{$token}")) && str_contains($message, 'أم فيصل')));

    // the guest page is live
    $this->get("/i/{$token}")->assertOk();
});

it('refuses to publish without guests', function () {
    $this->actingAs(User::factory()->create());
    $event = wizardDraft();

    $this->post("/create/{$event->id}/publish")->assertSessionHasErrors('publish');
    expect($event->invitation->fresh()->published_at)->toBeNull();
});

it('does not let another user publish', function () {
    $event = Event::factory()->draft()->create();
    $event->invitation()->create(['config' => InvitationFactory::sampleConfig()]);

    $this->actingAs(User::factory()->create())->post("/create/{$event->id}/publish")->assertForbidden();
});

it('renders the preview of a draft without logging opens', function () {
    $event = wizardDraft();
    $this->post("/events/{$event->id}/guests", ['name' => 'Umm Faisal', 'seats' => 3]);

    $response = $this->get("/create/{$event->id}/preview")
        ->assertOk()
        ->assertSee('data-preview-ribbon', false)
        ->assertSee('sec-rsvp', false)
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(LinkOpen::query()->count())->toBe(0)
        ->and($event->households()->first()->open_count)->toBe(0);

    // the per-guest calls are signed in the path, since invite.js sends no cookies
    preg_match('#"state":"([^"]+)"#', $response->getContent(), $m);
    $state = stripslashes($m[1]);
    $this->getJson($state.'?open=1')
        ->assertOk()
        ->assertJsonPath('household.name', 'ضيفنا الكريم')
        ->assertJsonPath('rsvp_open', true);
    expect(LinkOpen::query()->count())->toBe(0);

    $this->postJson(str_replace('/state', '/rsvp', $state), ['attending' => true])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'هذه معاينة: الرد على الدعوة متوقف.');
});

it('refuses preview calls with a bad or expired signature', function () {
    $event = wizardDraft();
    $expired = now()->subMinute()->getTimestamp();

    $this->getJson("/p/{$event->id}/{$expired}/".PreviewController::signature($event, $expired).'/state')->assertForbidden();
    $future = now()->addHour()->getTimestamp();
    $this->getJson("/p/{$event->id}/{$future}/".str_repeat('0', 64).'/state')->assertForbidden();
});

it('keeps the preview private to the draft\'s browser', function () {
    $event = wizardDraft();
    $this->flushSession();

    $this->get("/create/{$event->id}/preview")->assertRedirect('/login');
});
