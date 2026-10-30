<?php

use App\Models\Entitlement;
use App\Models\MarketplaceSignatureUse;
use App\Models\Order;
use App\Models\User;
use App\Services\Marketplace\MarketplaceCheckout;
use App\Services\Marketplace\TemplateAccess;
use App\Services\Payments\OrderFulfillment;
use App\Services\PublishGate;
use App\Services\Wizard\PlanAdvisor;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeStripe;
use Tests\Support\Marketplace;
use Tests\Support\Payments;

beforeEach(function () {
    Payments::setUp();
    Notification::fake();
});

afterEach(fn () => FakeStripe::uninstall());

it('needs the template itself, not a plan, to publish a paid marketplace design', function () {
    $template = Marketplace::published();
    $event = Marketplace::eventUsing($template);
    $gate = app(PublishGate::class);

    $advice = app(PlanAdvisor::class)->advise($event, $event->invitation->config);
    expect($advice['reasons'])->toContain('template');

    // the rose opening is Premium, but the template's own opening comes with the template: no plan is advised
    expect($advice['tier'])->toBe('free')->and($advice['reasons'])->not->toContain('opening');
    $check = $gate->check($event);
    expect($check['allowed'])->toBeFalse()->and($check['missing'])->toBe(['template']);

    // a Premium plan doesn't cover the marketplace design
    $event->update(['plan_id' => 'premium']);
    expect($gate->check($event->fresh())['missing'])->toBe(['template']);

    // switching to another Premium opening needs the plan again
    $config = $event->invitation->config;
    $config['opening']['id'] = 'ringbox';
    $event->update(['plan_id' => null]);
    expect($gate->check($event->fresh(), $config)['missing'])->toBe(['opening', 'template']);
});

it('sells a template through the transfer flow and lets PublishGate accept it whatever its tier', function () {
    $template = Marketplace::published();
    $host = User::factory()->create(['email_verified_at' => now()]);
    $event = Marketplace::eventUsing($template, $host);

    $this->actingAs($host)->get("/buy/template/{$template->id}?event={$event->id}&from=wizard")
        ->assertInertia(fn (Assert $page) => $page->component('marketplace/Buy')->where('item.kind', 'template')->where('item.prices.SAR', 11500)->where('item.owned', false));

    $this->actingAs($host)->post("/buy/template/{$template->id}", ['event_id' => $event->id, 'currency' => 'SAR', 'gateway' => 'transfer', 'country' => 'SA', 'from' => 'wizard'])
        ->assertRedirect("/create/{$event->id}/review");

    $order = Order::query()->sole();
    expect($order->status)->toBe('awaiting_payment')
        ->and($order->event_id)->toBe($event->id)
        ->and($order->total_cents)->toBe(11500)
        ->and($order->items[0])->toMatchArray([
            'type' => 'template_purchase', 'id' => $template->id, 'designer_id' => $template->designer_id, 'unit_amount' => 11500, 'amount' => 11500,
            'prices' => ['SAR' => 11500, 'SYP' => 150000, 'USD' => 1200],
        ]);

    $this->actingAs($host)->post("/orders/{$order->code}/receipt")->assertRedirect();
    app(OrderFulfillment::class)->approveTransfer($order->fresh(), Marketplace::admin(), 'BANK-123');

    $entitlement = Entitlement::query()->where('feature', TemplateAccess::feature($template))->sole();
    expect($entitlement->event_id)->toBe($event->id)->and($entitlement->source_order_id)->toBe($order->id);

    $check = app(PublishGate::class)->check($event->fresh());
    expect($check['allowed'])->toBeTrue()->and($check['current'])->toBe('free');

    // the order and invoice name the design
    expect($order->fresh()->invoices()->first()->lines[0]['name_en'])->toBe('Design: Damascus Jasmine');

    // buying it twice is refused
    expect(fn () => app(MarketplaceCheckout::class)->buyTemplate($host, $event->fresh(), $template, ['currency' => 'SAR', 'gateway' => 'transfer']))->toThrow(ValidationException::class);

    // published through the wizard
    $event->openLink()->create(['token' => 'OpenTplBuy000001', 'enabled' => true, 'cap' => 50]);
    $this->actingAs($host)->post("/create/{$event->id}/publish")->assertSessionHasNoErrors();
    expect($event->fresh()->invitation->published_at)->not->toBeNull();
});

it('sells a template by card through Stripe (faked) and unlocks it on the webhook', function () {
    $stripe = FakeStripe::install();
    $template = Marketplace::published();
    $host = User::factory()->create(['email_verified_at' => now()]);
    $event = Marketplace::eventUsing($template, $host);

    $this->actingAs($host)->post("/buy/template/{$template->id}", ['event_id' => $event->id, 'currency' => 'USD', 'gateway' => 'stripe'], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location');

    $order = Order::query()->sole();
    expect($order->gateway)->toBe('stripe')->and($order->total_cents)->toBe(1200);
    expect($stripe->last('/v1/checkout/sessions')['params']['line_items'][0]['price_data']['unit_amount'])->toBe(1200);

    $payload = json_encode(Payments::stripeEvent($order));
    $this->call('POST', '/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => Payments::stripeSignature($payload)], $payload)->assertOk();

    expect($order->fresh()->status)->toBe('paid')
        ->and(app(TemplateAccess::class)->owned($event, $template))->toBeTrue()
        ->and(app(PublishGate::class)->check($event->fresh())['allowed'])->toBeTrue();
});

it('does not give paid templates to Signature unless the designer included them', function () {
    $excluded = Marketplace::published();
    $event = Marketplace::eventUsing($excluded, attributes: ['plan_id' => 'signature']);
    expect(app(PublishGate::class)->check($event)['missing'])->toBe(['template']);

    $included = Marketplace::published(overrides: ['included_in_signature' => true]);
    $signature = Marketplace::eventUsing($included, attributes: ['plan_id' => 'signature']);
    expect(app(PublishGate::class)->check($signature)['allowed'])->toBeTrue();

    // a Premium event still buys it
    $premium = Marketplace::eventUsing($included, attributes: ['plan_id' => 'premium']);
    expect(app(PublishGate::class)->check($premium)['missing'])->toBe(['template']);

    // publishing records the use for the designers' monthly pool (once)
    $signature->invitation->update(['published_at' => now()]);
    $signature->invitation->update(['published_at' => now()->addMinute()]);
    $use = MarketplaceSignatureUse::query()->sole();
    expect($use->template_id)->toBe($included->id)
        ->and($use->designer_id)->toBe($included->designer_id)
        ->and($use->period)->toBe(now()->format('Y-m'));
});

it('shows marketplace designs in the wizard with the designer and a price, and hides designer themes', function () {
    $template = Marketplace::published();
    $host = User::factory()->create();
    $event = Payments::event($host);

    $this->actingAs($host)->get("/create/{$event->id}/design")->assertInertia(fn (Assert $page) => $page
        ->component('create/Design')
        ->where('marketplace.0.id', $template->id)
        ->where('marketplace.0.designer', 'استوديو '.$template->designer->name)
        ->where('marketplace.0.owned', false)
        ->where('marketplace.0.buy_url', "/buy/template/{$template->id}?event={$event->id}&from=wizard")
        ->where('themes', fn ($themes) => collect($themes)->pluck('id')->doesntContain($template->theme_id))
        ->where('templates', fn ($templates) => collect($templates)->pluck('id')->doesntContain($template->id)));

    // the review step offers to buy it once the host picked it
    $event->invitation->update(['template_id' => $template->id, 'config' => $template->default_config]);
    $this->actingAs($host)->get("/create/{$event->id}/review")->assertInertia(fn (Assert $page) => $page
        ->where('marketplaceTemplate.id', $template->id)
        ->where('gate.missing', fn ($missing) => collect($missing)->contains('template')));
});

it('refuses to sell what is not on sale, or for an event the buyer cannot edit', function () {
    $template = Marketplace::published();
    $host = User::factory()->create(['email_verified_at' => now()]);
    $event = Marketplace::eventUsing($template, $host);
    $stranger = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($stranger)->post("/buy/template/{$template->id}", ['event_id' => $event->id, 'currency' => 'SAR', 'gateway' => 'transfer'])->assertForbidden();
    $this->actingAs($stranger)->get("/buy/template/{$template->id}?event={$event->id}")->assertForbidden();

    $template->update(['active' => false]);
    $this->actingAs($host)->post("/buy/template/{$template->id}", ['event_id' => $event->id, 'currency' => 'SAR', 'gateway' => 'transfer'])->assertSessionHasErrors('template');
    $this->actingAs($host)->post('/buy/template/warda-rose', ['event_id' => $event->id, 'currency' => 'SAR', 'gateway' => 'transfer'])->assertSessionHasErrors('template');
    expect(Order::query()->count())->toBe(0);
});
