<?php

use App\Models\Entitlement;
use App\Models\Event;
use App\Models\Household;
use App\Models\User;
use App\Services\InvitationConfig\InvitationConfig;
use App\Services\Journey\SaveTheDate;
use App\Services\PublishGate;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;
use Tests\Support\Payments;

/*
| Save the date (plan section 14.5): a cheap card months before the
| invitation, its own product, no RSVP; later upgraded to the full
| invitation with the names, date, place and families carried over.
*/

beforeEach(function () {
    Payments::setUp();
    Notification::fake();
    $this->owner = User::factory()->create(['email_verified_at' => now()]);
});

function saveTheDateDraft(User $owner): Event
{
    test()->actingAs($owner)->post('/create', ['occasion' => 'save_the_date'])->assertRedirect();

    return Event::query()->where('owner_id', $owner->id)->latest()->firstOrFail();
}

it('starts a short save-the-date card without an RSVP', function () {
    $event = saveTheDateDraft($this->owner);
    $config = $event->invitation->config;

    expect($config)->version->toBe(2)->kind->toBe('save_the_date')
        ->and(array_column($config['sections'], 'type'))->toBe(['intro_line', 'names', 'date', 'place', 'closing'])
        ->and(InvitationConfig::section($config, 'date')['showHijri'])->toBeTrue();
});

it('needs the save_the_date product, not a plan, to publish with personal links', function () {
    $event = saveTheDateDraft($this->owner);
    Household::factory()->for($event)->count(2)->create();
    $gate = app(PublishGate::class);

    expect($gate->check($event->fresh()))->required->toBe('free')->missing->toBe(['save_the_date']);
    $this->actingAs($this->owner)->post("/create/{$event->id}/publish")->assertSessionHasErrors('publish');

    // the review step only sells the save-the-date product
    $this->actingAs($this->owner)->get("/create/{$event->id}/review")->assertInertia(fn ($page) => $page
        ->where('saveTheDate', true)
        ->where('checkout.plans', [])
        ->where('checkout.addons.0.id', 'save_the_date'));

    $order = Journey::buy($event, ['addons' => [['id' => 'save_the_date']]]);
    expect($order->items[0]['type'])->toBe('save_the_date');

    $event->refresh();
    expect($gate->check($event)['allowed'])->toBeTrue()
        ->and($gate->canSendPersonalLinks($event))->toBeTrue()
        ->and($event->plan_id)->toBeNull();

    $this->actingAs($this->owner)->post("/create/{$event->id}/publish")->assertSessionHasNoErrors();
    $household = $event->households()->first();
    $this->get("/i/{$household->token}")->assertOk()->assertSee('احفظوا الموعد')->assertDontSee('sec-rsvp', false);
});

it('refuses to publish a save-the-date that carries an RSVP', function () {
    $event = saveTheDateDraft($this->owner);
    Entitlement::query()->create(['event_id' => $event->id, 'feature' => 'save_the_date']);
    $config = $event->invitation->config;
    $config['sections'][] = ['type' => 'rsvp'];
    $event->invitation->update(['config' => $config]);

    expect(app(PublishGate::class)->check($event->fresh())['missing'])->toBe(['save_the_date_rsvp']);
});

it('upgrades to the full invitation carrying names, date, place and families', function () {
    $event = saveTheDateDraft($this->owner);
    $config = $event->invitation->config;
    foreach ($config['sections'] as $i => $s) {
        $config['sections'][$i] = match ($s['type']) {
            'names' => ['type' => 'names', 'first' => 'هند', 'second' => 'سامي'],
            'date' => ['type' => 'date', 'start' => '2027-06-10T20:00:00+03:00', 'showHijri' => true, 'countdown' => true],
            'place' => ['type' => 'place', 'name' => 'جدة'],
            default => $s,
        };
    }
    $event->invitation->update(['config' => $config]);
    Household::factory()->for($event)->create(['display_name' => 'عائلة النجار', 'seats_allowed' => 4, 'phone' => '+966500000001']);
    Household::factory()->for($event)->create(['display_name' => 'عائلة الحسن', 'seats_allowed' => 2]);

    $this->actingAs($this->owner)->post("/events/{$event->id}/save-the-date/upgrade", ['occasion' => 'wedding'])->assertRedirect();

    $full = Event::query()->where('source_event_id', $event->id)->firstOrFail();
    $fullConfig = $full->invitation->config;
    expect($full)->occasion->toBe('wedding')->status->toBe('draft')->owner_id->toBe($this->owner->id)
        ->and($full->title)->toBe('زفاف هند وسامي')
        ->and($full->starts_at->toIso8601String())->toBe('2027-06-10T17:00:00+00:00')
        ->and(InvitationConfig::kind($fullConfig))->toBe('invitation')
        ->and(InvitationConfig::section($fullConfig, 'names'))->toMatchArray(['first' => 'هند', 'second' => 'سامي'])
        ->and(InvitationConfig::section($fullConfig, 'place')['address'])->toBe('جدة')
        ->and(InvitationConfig::section($fullConfig, 'rsvp'))->not->toBeNull()
        ->and($full->households()->orderBy('display_name')->pluck('display_name')->all())->toBe(['عائلة الحسن', 'عائلة النجار'])
        ->and($full->households()->where('display_name', 'عائلة النجار')->first())->seats_allowed->toBe(4)->phone->toBe('+966500000001')
        // new personal links: the save-the-date's keep showing the card
        ->and($full->households()->pluck('token')->intersect($event->households()->pluck('token')))->toBeEmpty();

    // a second click opens the same invitation
    app(SaveTheDate::class)->upgrade($event, $this->owner, 'wedding');
    expect(Event::query()->where('source_event_id', $event->id)->count())->toBe(1);

    $event->update(['status' => 'active']);
    $this->actingAs($this->owner)->get("/dashboard/events/{$event->id}")->assertInertia(fn ($page) => $page
        ->where('saveTheDate.invitation_url', "/create/{$full->id}/design"));
});

it('only upgrades a save-the-date, for its owner', function () {
    $event = Payments::event($this->owner);
    $this->actingAs($this->owner)->post("/events/{$event->id}/save-the-date/upgrade", ['occasion' => 'wedding'])->assertSessionHasErrors('event');

    $std = saveTheDateDraft($this->owner);
    $this->actingAs(User::factory()->create())->post("/events/{$std->id}/save-the-date/upgrade", ['occasion' => 'wedding'])->assertForbidden();
});
