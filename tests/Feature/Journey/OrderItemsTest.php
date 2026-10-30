<?php

use App\Models\DiscountCode;
use App\Models\Entitlement;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PhotoBook;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\Payments\CheckoutService;
use App\Services\Payments\Gateways\TransferGateway;
use App\Services\Payments\Items\OrderItemHandler;
use App\Services\Payments\Items\OrderItemHandlers;
use App\Services\Payments\OrderFulfillment;
use App\Services\Payments\OrderPresenter;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;
use Tests\Support\Payments;

/*
| Phase 8D order line types, each fulfilled by a handler registered with
| OrderItemHandlers (no change to how plans and add-ons work), and invoiced.
*/

beforeEach(function () {
    Payments::setUp();
    Notification::fake();
    $this->admin = User::factory()->admin()->create();
});

it('registers a handler for every new line type', function () {
    $handlers = app(OrderItemHandlers::class);

    foreach (['save_the_date', 'thank_you', 'highlight_video', 'photo_book', 'bundle', 'bundle_credit', 'referral_credit', 'loyalty', 'referral_welcome'] as $type) {
        expect($handlers->for($type))->toBeInstanceOf(OrderItemHandler::class, $type);
    }
    expect($handlers->for('plan'))->toBeNull()->and($handlers->for('addon'))->toBeNull();
});

it('fulfils the product lines with their feature and invoices them by name', function (string $addon, string $type, string $feature) {
    $event = Payments::event();

    $order = Journey::buy($event, ['addons' => [['id' => $addon]]]);

    expect($order->status)->toBe('approved')
        ->and($order->items[0]['type'])->toBe($type)
        ->and(Entitlement::query()->where('event_id', $event->id)->where('feature', $feature)->where('source_order_id', $order->id)->exists())->toBeTrue();

    $invoice = Invoice::query()->where('order_id', $order->id)->firstOrFail();
    expect($invoice->lines[0]['type'])->toBe($type)
        ->and($invoice->lines[0]['name_ar'])->toBe(trans("app.checkout.addons.{$addon}.name", [], 'ar'))
        ->and($invoice->lines[0]['name_en'])->toBe(trans("app.checkout.addons.{$addon}.name", [], 'en'))
        ->and($invoice->total_cents)->toBe($order->total_cents);

    // a refund ends the feature like any add-on
    app(OrderFulfillment::class)->refund($order, $this->admin);
    expect(app(Entitlements::class)->hasFeature($event->fresh(), $feature))->toBeFalse();
})->with([
    'save the date' => ['save_the_date', 'save_the_date', 'save_the_date'],
    'thank-you card' => ['thank_you', 'thank_you', 'thank_you'],
    'highlight video' => ['highlight_video', 'highlight_video', 'highlight_video'],
]);

it('names the new lines on the order page and in the transfer note', function () {
    $event = Payments::event();
    $this->actingAs($event->owner)->post('/checkout', ['event_id' => $event->id, 'addons' => [['id' => 'highlight_video']], 'currency' => 'SAR', 'gateway' => 'transfer']);
    $order = Order::query()->latest()->first();

    expect(app(OrderPresenter::class)->present($order, 'ar')['items'][0]['name'])->toBe('فيديو اللحظات')
        ->and(TransferGateway::itemsLabel($order, 'en'))->toBe('Highlight video');
});

it('leaves the products sold on their own page out of the general checkout', function () {
    $event = Payments::event();
    $options = app(CheckoutService::class)->options($event, $event->owner);
    $ids = collect($options['addons'])->pluck('id');

    expect($ids)->toContain('highlight_video')->toContain('thank_you')
        ->not->toContain('save_the_date')->not->toContain('photo_book_20');

    $only = app(CheckoutService::class)->options($event, $event->owner, ['photo_book_40']);
    expect(collect($only['addons'])->pluck('id')->all())->toBe(['photo_book_40'])
        ->and($only['plans'])->toBe([])
        ->and($only['preselect_addon'])->toBe('photo_book_40');
});

it('lets discount codes that cover add-ons cover the products', function () {
    DiscountCode::factory()->create(['code' => 'VIDEO50', 'percent' => 50]);
    $event = Payments::event();

    $order = Journey::buy($event, ['addons' => [['id' => 'highlight_video']], 'discount_code' => 'VIDEO50']);

    expect($order->discount_cents)->toBe(2750)->and($order->total_cents)->toBe(2750);
});

it('moves a paid photo book to pending, with its copies, and cancels it on refund', function () {
    $event = Payments::event();
    $book = PhotoBook::query()->create(['event_id' => $event->id, 'user_id' => $event->owner_id, 'title' => 'Book', 'pages' => 40, 'photo_ids' => []]);
    $this->actingAs($event->owner)->post('/checkout', ['event_id' => $event->id, 'addons' => [['id' => 'photo_book_40', 'quantity' => 2]], 'currency' => 'SAR', 'gateway' => 'transfer']);
    $order = Order::query()->latest()->first();
    $book->update(['order_id' => $order->id]);

    expect($order->items[0])->toMatchArray(['type' => 'photo_book', 'quantity' => 2, 'amount' => 2 * 29900]);

    Journey::pay($order);
    expect($book->fresh())->status->toBe('pending')->copies->toBe(2)->paid_at->not->toBeNull()
        ->and(Invoice::query()->where('order_id', $order->id)->first()->lines[0]['quantity'])->toBe(2);

    app(OrderFulfillment::class)->refund($order->fresh(), $this->admin);
    expect($book->fresh()->status)->toBe('cancelled');
});

it('ignores a line type nobody registered', function () {
    $event = Payments::event();
    $order = Order::query()->create([
        'user_id' => $event->owner_id, 'event_id' => $event->id, 'currency' => 'SAR', 'method' => 'transfer', 'status' => 'awaiting_payment',
        'items' => [['type' => 'mystery', 'id' => 'x', 'name' => 'Mystery', 'quantity' => 1, 'unit_amount' => 1000, 'amount' => 1000]],
        'total_cents' => 1000,
    ]);

    Journey::pay($order);

    expect($order->fresh()->status)->toBe('approved')
        ->and(Invoice::query()->where('order_id', $order->id)->first()->lines[0]['name_en'])->toBe('Mystery');
});
