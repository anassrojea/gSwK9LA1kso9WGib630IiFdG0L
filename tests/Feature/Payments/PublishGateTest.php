<?php

use App\Models\AuditLog;
use App\Models\Entitlement;
use App\Models\Event;
use App\Models\OpenLink;
use App\Models\Order;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\OpenLinks;
use App\Services\Payments\OrderFulfillment;
use App\Services\PublishGate;
use Illuminate\Support\Facades\Notification;
use Tests\Support\DashboardFixtures;
use Tests\Support\Payments;

beforeEach(function () {
    Payments::setUp();
    Notification::fake();
});

function freeEvent(): Event
{
    $event = Payments::event();
    $event->invitation->update(['config' => Payments::freeConfig()]);

    return $event->fresh();
}

function paidOrder(Event $event, array $items, string $status = 'approved'): Order
{
    $order = Order::factory()->create([
        'user_id' => $event->owner_id,
        'event_id' => $event->id,
        'items' => $items,
        'total_cents' => array_sum(array_column($items, 'amount')),
    ]);
    app(OrderFulfillment::class)->approveTransfer($order, User::factory()->admin()->create(), 'REF-'.$order->code);

    return $order->fresh();
}

function addonLine(string $id, int $quantity = 1): array
{
    return ['type' => 'addon', 'id' => $id, 'name' => $id, 'quantity' => $quantity, 'unit_amount' => 1000, 'amount' => 1000 * $quantity];
}

it('publishes a Free invitation without paying: free design, open link, up to 50 guests', function () {
    $event = freeEvent();
    app(OpenLinks::class)->configure($event, true, 50);

    expect(app(PublishGate::class)->check($event))->toMatchArray(['allowed' => true, 'required' => 'free', 'current' => 'free', 'missing' => []]);

    $this->actingAs($event->owner)->get("/create/{$event->id}/review")
        ->assertInertia(fn ($page) => $page->where('gate.allowed', true)->where('checkout', null)->where('plan.id', 'free'));

    $this->post("/create/{$event->id}/publish")->assertSessionHasNoErrors();
    expect($event->invitation->fresh()->published_at)->not->toBeNull()
        ->and(Order::query()->count())->toBe(0);

    // open-link replies are households too, and never need Premium
    $event->households()->create(['display_name' => 'ضيف', 'seats_allowed' => 2, 'tags' => [OpenLink::TAG]]);
    expect(app(PublishGate::class)->check($event)['allowed'])->toBeTrue();

    // the Free badge stays on the guest page
    expect(app(Entitlements::class)->hasFeature($event, Entitlements::NO_BADGE))->toBeFalse();
});

it('needs a plan for a Premium design', function () {
    $event = freeEvent();
    $config = $event->invitation->config;
    $config['theme']['id'] = 'wine-gold';
    $event->invitation->update(['config' => $config]);
    app(OpenLinks::class)->configure($event, true, 50);

    expect(app(PublishGate::class)->check($event->fresh()))->toMatchArray(['allowed' => false, 'required' => 'premium', 'missing' => ['theme']]);

    // a single template unlock covers a Premium design on Free
    paidOrder($event, [addonLine('template_unlock')]);
    expect(app(PublishGate::class)->check($event->fresh())['allowed'])->toBeTrue()
        ->and($event->fresh()->plan_id)->toBeNull();
});

it('keeps personal links Premium: listed on Free, but not published or sent', function () {
    $event = freeEvent();
    $this->actingAs($event->owner);

    // a draft guest list is fine on Free
    $this->post("/events/{$event->id}/guests", ['name' => 'أم فيصل', 'seats' => 3])->assertSessionHasNoErrors();

    $check = app(PublishGate::class)->check($event);
    expect($check)->toMatchArray(['allowed' => false, 'required' => 'premium', 'missing' => ['personal_links']]);

    $this->post("/create/{$event->id}/publish")->assertSessionHasErrors('publish');
    expect($event->invitation->fresh()->published_at)->toBeNull();

    // the open link stays available on Free
    $this->putJson("/events/{$event->id}/open-link", ['enabled' => true, 'cap' => 40])->assertOk()->assertJson(['available' => true, 'enabled' => true]);

    // with Premium the same invitation publishes and hands out each family's link
    paidOrder($event, [['type' => 'plan', 'id' => 'premium', 'name' => 'Premium', 'quantity' => 1, 'unit_amount' => 2900, 'amount' => 2900]]);
    $this->post("/create/{$event->id}/publish")->assertSessionHasNoErrors();
    $this->get("/create/{$event->id}/review")->assertInertia(fn ($page) => $page->where('canSendPersonal', true)->has('households.0.link'));
});

it('hides personal links and blocks reminders on a free event that is already live', function () {
    [$event, $h] = DashboardFixtures::event(['plan_id' => 'free']);
    $event->invitation->update(['published_at' => now()]);

    $this->actingAs($event->owner)->get("/dashboard/events/{$event->id}")
        ->assertInertia(fn ($page) => $page->where('can.send', false)->where('can.remind', false)->where('plan', 'free')->where('urls.upgrade', "/dashboard/events/{$event->id}/upgrade"));

    $this->postJson("/dashboard/events/{$event->id}/reminders", ['audience' => 'both'])
        ->assertForbidden()
        ->assertJson(['message' => trans('app.checkout.reminders_premium')]);

    $this->get("/create/{$event->id}/review")->assertInertia(fn ($page) => $page->where('canSendPersonal', false)->missing('households.0.link'));

    $event->update(['plan_id' => 'premium']);
    $this->postJson("/dashboard/events/{$event->id}/reminders", ['audience' => 'both'])->assertOk();
});

it('grants add-ons with an end date and extra guest packs', function () {
    $event = freeEvent();
    $order = paidOrder($event, [
        ['type' => 'plan', 'id' => 'premium', 'name' => 'Premium', 'quantity' => 1, 'unit_amount' => 2900, 'amount' => 2900],
        addonLine('photo_cloud_90d'),
        addonLine('guests_200', 2),
        addonLine('seating'),
    ]);
    $event->refresh();

    expect($event->plan_id)->toBe('premium')
        ->and($event->guest_limit)->toBe(300 + 400)
        ->and(app(Entitlements::class)->limit($event, 'guests'))->toBe(700)
        ->and(app(Entitlements::class)->hasFeature($event, Entitlements::SEATING))->toBeTrue();

    $cloud = Entitlement::query()->where('feature', 'photo_cloud')->sole();
    expect($cloud->source_order_id)->toBe($order->id)
        ->and((int) round($cloud->until->diffInDays($order->paid_at, true)))->toBe(90)
        ->and(Entitlement::query()->where('feature', 'guests_200')->count())->toBe(2)
        ->and(Entitlement::query()->where('feature', 'seating')->value('until'))->toBeNull();

    // time-limited add-ons end on their own
    $this->travel(91)->days();
    expect(app(Entitlements::class)->hasFeature($event, 'photo_cloud'))->toBeFalse()
        ->and(app(Entitlements::class)->hasFeature($event, Entitlements::SEATING))->toBeTrue();
});

it('ends the add-ons and returns the event to its earlier plan on refund', function () {
    $event = freeEvent();
    paidOrder($event, [['type' => 'plan', 'id' => 'premium', 'name' => 'Premium', 'quantity' => 1, 'unit_amount' => 2900, 'amount' => 2900]]);
    $upgrade = paidOrder($event, [['type' => 'plan', 'id' => 'signature', 'name' => 'Signature', 'quantity' => 1, 'unit_amount' => 7900, 'amount' => 7900], addonLine('guests_200')]);

    expect($event->fresh()->plan_id)->toBe('signature')->and($event->fresh()->guest_limit)->toBe(1200);

    $admin = User::factory()->admin()->create();
    expect(app(OrderFulfillment::class)->refund($upgrade, $admin, 'Customer asked'))->toBeTrue();

    $event->refresh();
    expect($upgrade->fresh()->status)->toBe('refunded')
        ->and($upgrade->fresh()->refunded_at)->not->toBeNull()
        ->and($event->plan_id)->toBe('premium')
        ->and($event->guest_limit)->toBeNull()
        ->and(app(Entitlements::class)->hasFeature($event, 'guests_200'))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'order.refunded')->value('actor_id'))->toBe($admin->id);

    // refunding twice does nothing
    expect(app(OrderFulfillment::class)->refund($upgrade->fresh(), $admin))->toBeFalse();
});

it('never lowers a plan when a smaller order is approved later', function () {
    $event = freeEvent();
    $event->update(['plan_id' => 'signature']);

    paidOrder($event, [addonLine('wallet')]);
    expect($event->fresh()->plan_id)->toBe('signature');
});
