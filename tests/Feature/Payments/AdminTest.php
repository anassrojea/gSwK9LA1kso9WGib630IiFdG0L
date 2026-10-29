<?php

use App\Filament\Resources\Addons\Pages\ManageAddons;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\Orders\OrderActions;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Orders\RelationManagers\TransferReceiptsRelationManager;
use App\Filament\Resources\Prices\Pages\ManagePrices;
use App\Filament\Widgets\PaymentsOverview;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Price;
use App\Models\User;
use App\Services\Payments\OrderFulfillment;
use App\Services\Payments\PriceList;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\Payments;

beforeEach(function () {
    Payments::setUp();
    Notification::fake();
    $this->admin = User::factory()->admin()->create(['name' => 'Admin']);
    $this->actingAs($this->admin);
});

function waitingOrder(array $attributes = []): Order
{
    $event = Payments::event();
    $order = Order::factory()->create($attributes + ['user_id' => $event->owner_id, 'event_id' => $event->id, 'currency' => 'SAR', 'total_cents' => 10900,
        'items' => [['type' => 'plan', 'id' => 'premium', 'name' => 'Premium', 'quantity' => 1, 'unit_amount' => 10900, 'amount' => 10900]]]);
    app(OrderFulfillment::class)->receiptSent($order, $order->user);

    return $order->fresh();
}

it('lists transfers waiting first, with a count badge in the navigation', function () {
    $waiting = waitingOrder();
    $paid = Order::factory()->card()->create(['status' => 'paid', 'paid_at' => now()]);

    expect(OrderResource::getNavigationBadge())->toBe('1');

    Livewire::test(ListOrders::class)
        ->assertSet('activeTab', 'waiting')
        ->assertCanSeeTableRecords([$waiting])
        ->assertCanNotSeeTableRecords([$paid])
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$waiting, $paid])
        ->searchTable($waiting->code)
        ->assertCanSeeTableRecords([$waiting])
        ->assertCanNotSeeTableRecords([$paid]);

    Livewire::test(ListOrders::class)
        ->set('activeTab', 'all')
        ->filterTable('status', ['paid'])
        ->assertCanSeeTableRecords([$paid])
        ->assertCanNotSeeTableRecords([$waiting])
        ->resetTableFilters()
        ->filterTable('gateway', 'transfer')
        ->assertCanSeeTableRecords([$waiting])
        ->assertCanNotSeeTableRecords([$paid])
        ->resetTableFilters()
        ->filterTable('currency', 'SAR')
        ->assertCanSeeTableRecords([$waiting])
        ->assertCanNotSeeTableRecords([$paid]);
});

it('approves from the table with the bank-account warning and a bank reference', function () {
    $order = waitingOrder();

    $warning = OrderActions::approve()->record($order)->getModalDescription();
    expect($warning)->toContain('وافق فقط بعد رؤية المبلغ في الحساب البنكي')->toContain('١٠٩');

    Livewire::test(ListOrders::class)
        ->assertActionVisible(TestAction::make('approve')->table($order))
        ->callAction(TestAction::make('approve')->table($order), data: ['bank_reference' => ''])
        ->assertHasActionErrors(['bank_reference' => 'required']);
    expect($order->fresh()->status)->toBe('receipt_received');

    Livewire::test(ListOrders::class)
        ->callAction(TestAction::make('approve')->table($order), data: ['bank_reference' => 'RJ-555', 'note' => 'Seen in Al Rajhi'])
        ->assertHasNoActionErrors()
        ->assertNotified(trans('app.admin.actions.approved', ['code' => $order->code]));

    $order->refresh();
    expect($order->status)->toBe('approved')
        ->and($order->event->plan_id)->toBe('premium')
        ->and($order->latestReceipt->only('approved_by', 'decision', 'bank_reference', 'note'))
        ->toBe(['approved_by' => $this->admin->id, 'decision' => 'approved', 'bank_reference' => 'RJ-555', 'note' => 'Seen in Al Rajhi'])
        ->and(AuditLog::query()->where('action', 'order.approved')->value('actor_id'))->toBe($this->admin->id);

    Livewire::test(ListOrders::class)->set('activeTab', 'all')->assertActionHidden(TestAction::make('approve')->table($order));
});

it('refuses a bank reference used before', function () {
    $first = waitingOrder();
    $second = waitingOrder();
    app(OrderFulfillment::class)->approveTransfer($first, $this->admin, 'DUP-1');

    Livewire::test(ListOrders::class)
        ->callAction(TestAction::make('approve')->table($second), data: ['bank_reference' => 'DUP-1'])
        ->assertHasActionErrors(['bank_reference' => 'unique']);

    expect($second->fresh()->status)->toBe('receipt_received');
});

it('rejects with a required reason', function () {
    $order = waitingOrder();

    Livewire::test(ViewOrder::class, ['record' => $order->code])
        ->callAction('reject', data: ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);
    expect($order->fresh()->status)->toBe('receipt_received');

    Livewire::test(ViewOrder::class, ['record' => $order->code])
        ->callAction('reject', data: ['reason' => 'Amount does not match'])
        ->assertHasNoActionErrors();

    expect($order->fresh()->only('status', 'rejection_reason'))->toBe(['status' => 'rejected', 'rejection_reason' => 'Amount does not match'])
        ->and(AuditLog::query()->where('action', 'order.rejected')->value('actor_id'))->toBe($this->admin->id);
});

it('marks a paid order refunded and offers the WhatsApp confirmation', function () {
    $order = waitingOrder();
    app(OrderFulfillment::class)->approveTransfer($order, $this->admin, 'R-1');
    $order->refresh();

    expect(OrderActions::whatsappConfirmationUrl($order))->toStartWith('https://wa.me/')
        ->and(rawurldecode(OrderActions::whatsappConfirmationUrl($order)))->toContain($order->code);

    // the order page lists its receipts and payments
    Livewire::test(TransferReceiptsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class])
        ->assertSee('R-1')
        ->assertSee('Admin');
    Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class])
        ->assertCanSeeTableRecords($order->payments);

    Livewire::test(ViewOrder::class, ['record' => $order->code])
        ->assertSee($order->code)
        ->assertActionVisible('whatsapp')
        ->assertActionHidden('approve')
        ->callAction('refund', data: ['reason' => 'Event cancelled'])
        ->assertHasNoActionErrors();

    expect($order->fresh()->status)->toBe('refunded')
        ->and($order->event->fresh()->plan_id)->toBeNull();
});

it('keeps hosts out of the admin and its actions', function () {
    $host = User::factory()->create();
    $order = waitingOrder();

    $this->actingAs($host)->get('/admin')->assertForbidden();
    $this->actingAs($host)->get("/admin/orders/{$order->code}")->assertForbidden();
    $this->actingAs($host)->get('/admin/prices')->assertForbidden();
});

it('edits prices in normal units and clears the placeholder flag', function () {
    $price = Price::query()->where(['priceable_type' => 'plan', 'priceable_id' => 'premium', 'currency' => 'SAR'])->sole();
    expect($price->placeholder)->toBeTrue();

    Livewire::test(ManagePrices::class)
        ->searchTable('premium')
        ->assertCanSeeTableRecords([$price])
        ->callAction(TestAction::make('edit')->table($price), data: ['amount' => '119.5'])
        ->assertHasNoActionErrors();

    expect($price->fresh()->only('amount', 'placeholder'))->toBe(['amount' => 11950, 'placeholder' => false])
        ->and(app(PriceList::class)->amount(Plan::query()->find('premium'), 'SAR'))->toBe(11950);

    $syp = Price::query()->where(['priceable_type' => 'plan', 'priceable_id' => 'premium', 'currency' => 'SYP'])->sole();
    Livewire::test(ManagePrices::class)
        ->searchTable('premium')
        ->callAction(TestAction::make('edit')->table($syp), data: ['amount' => '400000'])
        ->assertHasNoActionErrors();
    expect($syp->fresh()->amount)->toBe(400000);

    // one price per item and currency
    Livewire::test(ManagePrices::class)
        ->callAction('create', data: ['priceable_type' => 'plan', 'priceable_id' => 'premium', 'currency' => 'SAR', 'amount' => '1'])
        ->assertHasActionErrors(['priceable_id' => 'unique']);
});

it('shows add-ons, the read-only audit log and today\'s numbers', function () {
    $order = waitingOrder();
    app(OrderFulfillment::class)->approveTransfer($order, $this->admin, 'W-1');

    Livewire::test(ManageAddons::class)->assertSuccessful()->assertSee('guests_200');
    Livewire::test(ListAuditLogs::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords(AuditLog::query()->get())
        ->assertActionDoesNotExist(TestAction::make('edit')->table(AuditLog::query()->first()))
        ->assertActionExists(TestAction::make('view')->table(AuditLog::query()->first()));

    Livewire::test(PaymentsOverview::class)
        ->assertSee(trans('app.admin.widgets.revenue', ['currency' => 'SAR']))
        ->assertSee('١٠٩')
        ->assertSee(trans('app.admin.widgets.new_events'))
        ->assertSee(trans('app.admin.orders.waiting'));

    $this->get('/admin')->assertOk();
    $this->get('/admin/orders')->assertOk();
    $this->get("/admin/orders/{$order->code}")->assertOk();
    $this->get('/admin/audit-logs')->assertOk();
});
