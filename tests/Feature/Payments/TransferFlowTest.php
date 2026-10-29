<?php

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Order;
use App\Models\TransferReceipt;
use App\Models\User;
use App\Notifications\OrderApproved;
use App\Notifications\OrderExpired;
use App\Notifications\OrderRejected;
use App\Services\Payments\OrderFulfillment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\Payments;

beforeEach(function () {
    Payments::setUp();
    Notification::fake();
});

function transferOrder(?Event $event = null, array $data = []): Order
{
    $event ??= Payments::event();
    test()->actingAs($event->owner)->post('/checkout', $data + ['event_id' => $event->id, 'plan' => 'premium', 'currency' => 'SAR', 'gateway' => 'transfer', 'from' => 'wizard']);

    return Order::query()->where('event_id', $event->id)->latest()->firstOrFail();
}

it('runs the transfer flow end to end: receipt, approval, entitlements, publish', function () {
    $event = Payments::event();
    $event->households()->create(['display_name' => 'أم فيصل', 'seats_allowed' => 3]);
    $owner = $event->owner;

    // the review step shows the checkout instead of Publish
    $this->actingAs($owner)->get("/create/{$event->id}/review")
        ->assertInertia(fn ($page) => $page->where('gate.allowed', false)->where('gate.missing', ['theme', 'personal_links'])->where('order', null)->where('canSendPersonal', false));
    $this->post("/create/{$event->id}/publish")->assertSessionHasErrors(['publish' => trans('app.checkout.payment_needed')]);

    $order = transferOrder($event);

    // 1. bank details for the order's currency, the amount and the code
    $this->get("/create/{$event->id}/review")
        ->assertInertia(fn ($page) => $page
            ->where('order.code', $order->code)
            ->where('order.status', 'awaiting_payment')
            ->where('order.transfer.code', $order->code)
            ->where('order.transfer.account.0', ['field' => 'account_name', 'label' => 'اسم الحساب', 'value' => 'Daawa LLC'])
            ->where('order.transfer.account.2.value', 'SA0380000000608010167519')
            ->where('order.transfer.amount', fn ($amount) => str_contains($amount, '١٠٩'))
            // 2. the WhatsApp button: payments number, code, amount and plan
            ->where('order.transfer.whatsapp_url', fn ($url) => str_starts_with($url, 'https://wa.me/963912345678?text=')
                && str_contains(rawurldecode($url), $order->code)
                && str_contains(rawurldecode($url), 'مميّز')));

    // 4. editing goes on while waiting; publishing does not
    $this->post("/create/{$event->id}/publish")->assertSessionHasErrors(['publish' => trans('app.checkout.waiting_publish', ['code' => $order->code])]);

    // 3. "I sent the receipt"
    $this->post("/orders/{$order->code}/receipt", ['note' => 'Al Rajhi app'])->assertRedirect()->assertSessionHasNoErrors();
    $order->refresh();
    $receipt = TransferReceipt::query()->sole();
    expect($order->status)->toBe('receipt_received')
        ->and($receipt->only('received_via', 'note', 'decision'))->toBe(['received_via' => 'whatsapp', 'note' => 'Al Rajhi app', 'decision' => null]);

    // pressing it twice changes nothing
    $this->post("/orders/{$order->code}/receipt")->assertSessionHasNoErrors();
    expect(TransferReceipt::query()->count())->toBe(1);

    $this->get("/create/{$event->id}/review")->assertInertia(fn ($page) => $page->where('order.status', 'receipt_received'));

    // 5. an admin approves after seeing the money
    $admin = User::factory()->admin()->create();
    app(OrderFulfillment::class)->approveTransfer($order, $admin, 'RJ-2026-0001');

    $order->refresh();
    $receipt->refresh();
    expect($order->status)->toBe('approved')
        ->and($order->paid_at)->not->toBeNull()
        ->and($receipt->approved_by)->toBe($admin->id)
        ->and($receipt->decision)->toBe('approved')
        ->and($receipt->bank_reference)->toBe('RJ-2026-0001')
        ->and($receipt->decided_at)->not->toBeNull()
        ->and($order->payments()->sole()->only('provider', 'provider_ref', 'amount_cents', 'currency', 'status'))
        ->toBe(['provider' => 'transfer', 'provider_ref' => 'RJ-2026-0001', 'amount_cents' => 10900, 'currency' => 'SAR', 'status' => 'succeeded']);

    // 6. entitlements and the customer's email
    expect($event->fresh()->plan_id)->toBe('premium');
    Notification::assertSentTo($owner, OrderApproved::class);
    $audit = AuditLog::query()->where('action', 'order.approved')->sole();
    expect($audit->actor_id)->toBe($admin->id)->and($audit->entity_id)->toBe($order->id)->and($audit->at)->not->toBeNull();

    // publishing unlocks, and personal links are handed out
    $this->post("/create/{$event->id}/publish")->assertSessionHasNoErrors();
    expect($event->invitation->fresh()->published_at)->not->toBeNull();
    $this->get("/create/{$event->id}/review")->assertInertia(fn ($page) => $page->where('canSendPersonal', true)->has('households.0.link'));
});

it('rejects with a reason, tells the customer and logs who did it', function () {
    $order = transferOrder();
    $this->post("/orders/{$order->code}/receipt");
    $admin = User::factory()->admin()->create();

    app(OrderFulfillment::class)->rejectTransfer($order, $admin, 'No transfer reached the account');

    $order->refresh();
    expect($order->status)->toBe('rejected')
        ->and($order->rejection_reason)->toBe('No transfer reached the account')
        ->and($order->latestReceipt->only('decision', 'reason', 'approved_by'))->toBe(['decision' => 'rejected', 'reason' => 'No transfer reached the account', 'approved_by' => $admin->id])
        ->and($order->event->plan_id)->toBeNull();

    $audit = AuditLog::query()->where('action', 'order.rejected')->sole();
    expect($audit->actor_id)->toBe($admin->id)->and($audit->data)->toBe(['reason' => 'No transfer reached the account']);
    Notification::assertSentTo($order->user, OrderRejected::class);

    // the customer sees the reason
    $this->actingAs($order->user)->get("/dashboard/orders/{$order->code}")
        ->assertInertia(fn ($page) => $page->component('orders/Show')->where('order.status', 'rejected')->where('order.rejection_reason', 'No transfer reached the account'));

    expect(fn () => app(OrderFulfillment::class)->rejectTransfer($order->fresh(), $admin, ''))->toThrow(ValidationException::class);
});

it('refuses a bank reference that already approved another order', function () {
    $admin = User::factory()->admin()->create();
    $first = transferOrder();
    $second = transferOrder();

    app(OrderFulfillment::class)->approveTransfer($first, $admin, 'REF-777');

    expect(fn () => app(OrderFulfillment::class)->approveTransfer($second, $admin, ' REF-777 '))
        ->toThrow(ValidationException::class, trans('app.payments.errors.duplicate_reference'));
    expect($second->fresh()->status)->toBe('awaiting_payment')
        ->and($second->event->fresh()->plan_id)->toBeNull();

    // and an order can't be approved twice
    expect(fn () => app(OrderFulfillment::class)->approveTransfer($first->fresh(), $admin, 'REF-778'))->toThrow(ValidationException::class);
});

it('lets only admins approve or reject', function () {
    $order = transferOrder();

    expect(fn () => app(OrderFulfillment::class)->approveTransfer($order, $order->user, 'REF-1'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(OrderFulfillment::class)->rejectTransfer($order, User::factory()->create(), 'nope'))->toThrow(AuthorizationException::class);
    expect($order->fresh()->status)->toBe('awaiting_payment');

    // the admin panel is closed to hosts
    $this->actingAs($order->user)->get('/admin/orders')->assertForbidden();
});

it('expires transfer orders after 48 hours unless the receipt was sent', function () {
    $waiting = transferOrder();
    $sent = transferOrder();
    $this->post("/orders/{$sent->code}/receipt");

    $this->travel(47)->hours();
    $this->artisan('orders:expire')->assertSuccessful();
    expect($waiting->fresh()->status)->toBe('awaiting_payment');

    $this->travel(2)->hours();
    $this->artisan('orders:expire')->expectsOutput('Expired 1 order(s).')->assertSuccessful();

    expect($waiting->fresh()->status)->toBe('expired')
        ->and($sent->fresh()->status)->toBe('receipt_received');
    Notification::assertSentTo($waiting->user, OrderExpired::class);
    Notification::assertNotSentTo($sent->user, OrderExpired::class);
    expect(AuditLog::query()->where('action', 'order.expired')->where('entity_id', $waiting->id)->exists())->toBeTrue();

    // money that arrives late can still be approved
    app(OrderFulfillment::class)->approveTransfer($waiting->fresh(), User::factory()->admin()->create(), 'LATE-1');
    expect($waiting->fresh()->status)->toBe('approved');

    // and an expired order takes no receipt
    $old = transferOrder();
    $old->update(['status' => 'expired']);
    $this->actingAs($old->user)->post("/orders/{$old->code}/receipt")->assertSessionHasErrors('receipt');
});

it('is scheduled', function () {
    $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command);

    expect($events->contains(fn ($command) => str_contains((string) $command, 'orders:expire')))->toBeTrue();
});

it('hides one customer\'s orders from another', function () {
    $order = transferOrder();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get("/dashboard/orders/{$order->code}")->assertNotFound();
    $this->actingAs($stranger)->getJson("/orders/{$order->code}/status")->assertNotFound();
    $this->actingAs($stranger)->post("/orders/{$order->code}/receipt")->assertNotFound();
    $this->actingAs($stranger)->post("/orders/{$order->code}/retry")->assertNotFound();
    $this->actingAs($stranger)->get('/dashboard/orders')->assertInertia(fn ($page) => $page->component('orders/Index')->has('orders', 0));

    expect($order->fresh()->status)->toBe('awaiting_payment');

    $this->actingAs($order->user)->get('/dashboard/orders')->assertInertia(fn ($page) => $page->has('orders', 1)->where('orders.0.code', $order->code));
    $this->actingAs($order->user)->getJson("/orders/{$order->code}/status")->assertOk()->assertJson(['status' => 'awaiting_payment']);
    $this->get('/dashboard/orders/DW-1')->assertNotFound();
});

it('emails in the customer\'s language', function () {
    $order = transferOrder();
    $order->user->update(['locale' => 'en']);
    app(OrderFulfillment::class)->approveTransfer($order, User::factory()->admin()->create(), 'EN-1');

    Notification::assertSentTo($order->user, OrderApproved::class, function (OrderApproved $notification, array $channels, $notifiable) use ($order) {
        $mail = $notification->locale('en')->toMail($notifiable);

        return $channels === ['mail']
            && str_contains($mail->subject, $order->code)
            && str_contains(implode(' ', $mail->introLines), 'SAR') && str_contains(implode(' ', $mail->introLines), '109 for Premium')
            && str_contains($mail->actionUrl, "/dashboard/orders/{$order->code}");
    });
});
