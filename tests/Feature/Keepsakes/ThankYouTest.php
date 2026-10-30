<?php

use App\Models\OpenLink;
use App\Models\ThankYouCard;
use App\Services\InvitationConfig\InvitationConfig;
use App\Services\Keepsakes\ThankYouCards;
use Illuminate\Validation\ValidationException;
use Tests\Support\GuestExtras;
use Tests\Support\Journey;

/*
| The thank-you card and highlight gallery (plan section 13.2): made from the
| event after it, gated by the thank_you add-on, sent through the same links.
*/

beforeEach(function () {
    [$this->event, $this->household] = GuestExtras::event('premium', ['starts_at' => now()->subDay()]);
    $this->photos = Journey::photos($this->event, 12);
});

it('drafts the card from the invitation with photos spread over the evening', function () {
    $card = app(ThankYouCards::class)->forEvent($this->event);
    $gallery = InvitationConfig::section($card->config, 'thank_you_gallery');

    expect($card->config)->version->toBe(2)->kind->toBe('thank_you')->occasion->toBe('thank_you')
        ->and($card->config['theme'])->toBe($this->event->invitation->config['theme'])
        ->and($card->config['opening']['id'])->toBe($this->event->invitation->config['opening']['id'])
        ->and($gallery['photos'])->toHaveCount(9)
        ->and($gallery['photos'][0]['src'])->toBe('media:'.$this->photos->first()->id)
        ->and($gallery['photos'][8]['src'])->toBe('media:'.$this->photos->last()->id)
        ->and($card->config_version)->toBe(2);
});

it('leaves hidden photos out and refuses another event\'s', function () {
    $this->photos[0]->update(['hidden' => true]);
    [$other] = GuestExtras::event();
    $foreign = Journey::photos($other, 1)->first();
    $card = app(ThankYouCards::class)->forEvent($this->event);

    app(ThankYouCards::class)->update($card, ['photos' => [$this->photos[0]->id, $this->photos[1]->id, $foreign->id]]);

    expect(InvitationConfig::section($card->fresh()->config, 'thank_you_gallery')['photos'])->toBe([['src' => 'media:'.$this->photos[1]->id]]);
});

it('only publishes with the thank_you add-on, and shows guests nothing before', function () {
    $owner = $this->event->owner;
    $this->actingAs($owner)->put("/events/{$this->event->id}/thank-you", [
        'message' => 'شكراً من القلب لكل من شاركنا', 'signature' => 'ريم وعمر', 'photos' => [$this->photos[2]->id], 'show_on_invite' => true,
    ])->assertSessionHasNoErrors();

    $this->actingAs($owner)->post("/events/{$this->event->id}/thank-you/publish")->assertSessionHasErrors('thank_you');
    $this->get("/t/{$this->household->token}")->assertNotFound();

    GuestExtras::grant($this->event, 'thank_you');
    $this->actingAs($owner)->post("/events/{$this->event->id}/thank-you/publish")->assertSessionHasNoErrors();

    $this->get("/t/{$this->household->token}")
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, public, s-maxage=300')
        ->assertSee('شكراً من القلب لكل من شاركنا')
        ->assertSee('ريم وعمر')
        ->assertSee('sec-thanks', false)
        // personal parts come from /state: the HTML names nobody
        ->assertDontSee($this->household->display_name)
        ->assertSee('/m/'.$this->photos[2]->id.'/preview', false)
        ->assertDontSee('sec-rsvp', false);
});

it('turns /i/{token} into the card after the event when asked, and not before', function () {
    GuestExtras::grant($this->event, 'thank_you');
    $cards = app(ThankYouCards::class);
    $card = $cards->publish($cards->forEvent($this->event));

    $this->get("/i/{$this->household->token}")->assertOk()->assertSee('sec-thanks', false);

    $card->update(['show_on_invite' => false]);
    $this->get("/i/{$this->household->token}")->assertOk()->assertDontSee('sec-thanks', false)->assertSee('sec-names', false);

    $card->update(['show_on_invite' => true]);
    $this->event->update(['starts_at' => now()->subHours(2)]);
    $this->get("/i/{$this->household->token}")->assertOk()->assertDontSee('sec-thanks', false);
    $this->get("/t/{$this->household->token}")->assertOk()->assertSee('sec-thanks', false);
});

it('serves the card at the open link too, without the family state', function () {
    GuestExtras::grant($this->event, 'thank_you');
    $cards = app(ThankYouCards::class);
    $cards->publish($cards->forEvent($this->event));
    $link = OpenLink::query()->create(['event_id' => $this->event->id, 'token' => 'OpenLinkToken123', 'enabled' => true, 'cap' => 100]);

    $html = $this->get("/t/{$link->token}")->assertOk()->getContent();

    expect($html)->toContain('sec-thanks')->toContain('"state":null');
});

it('asks /state without counting an open', function () {
    GuestExtras::grant($this->event, 'thank_you');
    $cards = app(ThankYouCards::class);
    $cards->publish($cards->forEvent($this->event));

    $html = $this->get("/t/{$this->household->token}")->getContent();

    expect($html)->toContain('"countOpen":false')->toContain('\/i\/'.$this->household->token.'\/state');
});

it('validates the card as a v2 thank-you config', function () {
    $card = app(ThankYouCards::class)->forEvent($this->event);
    $config = $card->config;
    $config['kind'] = 'invitation';

    expect(fn () => $card->update(['config' => $config]))->toThrow(ValidationException::class)
        ->and(ThankYouCard::query()->find($card->id)->config['kind'])->toBe('thank_you');
});

it('shows the keepsakes page with the card, the video and the book', function () {
    $this->actingAs($this->event->owner)->get("/dashboard/events/{$this->event->id}/keepsakes")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('dashboard/Keepsakes')
            ->where('thankYou.unlocked', false)
            ->where('thankYou.checkout.addons.0.id', 'thank_you')
            ->where('video.checkout.addons.0.id', 'highlight_video')
            ->has('photos', 12)
            ->where('book.pages', 20));
});
