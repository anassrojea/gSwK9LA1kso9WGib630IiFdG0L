<?php

use App\Models\Event;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\LinkOpen;
use App\Models\Media;
use Database\Factories\InvitationFactory;

it('returns 404 for an unknown token', function () {
    invitedHousehold();

    $this->get('/i/AbCdEfGhIj12')->assertNotFound();
    $this->getJson('/i/AbCdEfGhIj12/state')->assertNotFound();
});

it('returns 404 for a malformed token', function (string $token) {
    $this->get('/i/'.$token)->assertNotFound();
})->with(['short' => 'abc', 'symbols' => 'abc$def%ghi!', 'too long' => str_repeat('a', 40)]);

it('returns 404 while the invitation is unpublished', function () {
    [$event, $household] = invitedHousehold();
    $event->invitation->update(['published_at' => null]);

    $this->get("/i/{$household->token}")->assertNotFound();
});

it('returns 404 once the event is archived', function () {
    [$event, $household] = invitedHousehold();
    $event->update(['status' => 'archived']);

    $this->get("/i/{$household->token}")->assertNotFound();
});

it('renders the invitation from its config', function (string $template, string $opening) {
    $event = Event::factory()->create();
    Invitation::factory()->for($event)->opening($template)->create();
    $household = Household::factory()->for($event)->create();

    $response = $this->get("/i/{$household->token}");

    $response->assertOk()
        ->assertSee('dir="rtl"', false)
        ->assertSee("op-{$opening}", false)
        ->assertSee('data-countdown', false)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeaderMissing('Set-Cookie');

    expect($response->headers->get('Cache-Control'))->toContain('public');
})->with([
    'envelope' => ['layali-envelope', 'envelope'],
    'rose' => ['warda-rose', 'rose'],
]);

it('renders every visible section and skips hidden ones', function () {
    $config = InvitationFactory::sampleConfig();
    foreach ($config['sections'] as $i => $section) {
        if ($section['type'] === 'program') {
            $config['sections'][$i]['hidden'] = true;
        }
    }
    [, $household] = invitedHousehold(config: $config);

    $this->get("/i/{$household->token}")
        ->assertOk()
        ->assertSee('sec-names', false)
        ->assertSee('sec-story', false)
        ->assertSee('sec-rsvp', false)
        ->assertSee('٨ رجب ١٤٤٨ هـ')
        ->assertDontSee('sec-program', false);
});

it('serves the per-guest state as private JSON', function () {
    [, $household] = invitedHousehold(seats: 3);

    $response = $this->getJson("/i/{$household->token}/state")
        ->assertOk()
        ->assertJsonPath('household.name', $household->display_name)
        ->assertJsonPath('household.seats_allowed', 3)
        ->assertJsonPath('rsvp', null)
        ->assertJsonPath('rsvp_open', true);

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('logs opens and updates the household counters', function () {
    [, $household] = invitedHousehold();

    $this->getJson("/i/{$household->token}/state?open=1", ['User-Agent' => 'Phone A'])->assertOk();

    $household->refresh();
    expect($household->open_count)->toBe(1)
        ->and($household->first_opened_at)->not->toBeNull()
        ->and($household->last_opened_at)->not->toBeNull()
        ->and(LinkOpen::query()->where('household_id', $household->id)->count())->toBe(1)
        ->and(LinkOpen::query()->value('ua_hash'))->toBe(hash('sha256', 'Phone A'));

    // the page view and its /state call from the same device count once
    $this->get("/i/{$household->token}", ['User-Agent' => 'Phone A'])->assertOk();
    expect($household->refresh()->open_count)->toBe(1);

    // another device is a new open; first_opened_at stays
    $first = $household->first_opened_at;
    $this->travel(5)->minutes();
    $this->get("/i/{$household->token}", ['User-Agent' => 'Phone B'])->assertOk();

    $household->refresh();
    expect($household->open_count)->toBe(2)
        ->and($household->first_opened_at->equalTo($first))->toBeTrue()
        ->and($household->last_opened_at->greaterThan($first))->toBeTrue()
        ->and(LinkOpen::query()->count())->toBe(2);
});

it('does not log an open for a plain state refresh', function () {
    [, $household] = invitedHousehold();

    $this->getJson("/i/{$household->token}/state")->assertOk();

    expect($household->refresh()->open_count)->toBe(0)
        ->and(LinkOpen::query()->count())->toBe(0);
});

it('counts roses sent to the couple', function () {
    [$event] = invitedHousehold();
    $slug = $event->invitation->slug;

    $this->postJson("/e/{$slug}/rose")->assertOk()->assertJson(['count' => 1]);
    $this->postJson("/e/{$slug}/rose")->assertOk()->assertJson(['count' => 2]);
    $this->postJson('/e/unknown-slug/rose')->assertNotFound();
});

it('renders the gallery, favorites, wishes and closing sections and resolves uploads', function () {
    $event = Event::factory()->create();
    $own = Media::factory()->for($event)->create(['storage_key' => 'events/own.webp']);
    $foreign = Media::factory()->create(['storage_key' => 'events/foreign.webp']);

    $config = InvitationFactory::sampleConfig();
    $config['media'] = ['cover' => ['type' => 'image', 'src' => "media:{$own->id}"]];
    array_splice($config['sections'], 2, 0, [
        ['type' => 'gallery', 'photos' => [['src' => "media:{$own->id}", 'caption' => 'الخطوبة'], ['src' => "media:{$foreign->id}"]]],
        ['type' => 'favorites', 'items' => [['icon' => '🚀', 'text' => 'الفضاء']]],
        ['type' => 'wishes', 'items' => [['name' => 'خالته', 'text' => 'مبروك يا بطل']]],
        ['type' => 'closing', 'text' => 'بحضوركم يكتمل الفرح'],
    ]);
    Invitation::factory()->for($event)->create(['config' => $config]);
    $household = Household::factory()->for($event)->create();

    $this->get("/i/{$household->token}")
        ->assertOk()
        ->assertSee('/storage/events/own.webp', false)
        ->assertDontSee('foreign.webp', false) // another event's upload never resolves
        ->assertSee('الخطوبة')
        ->assertSee('الفضاء')
        ->assertSee('مبروك يا بطل')
        ->assertSee('بحضوركم يكتمل الفرح');
});
