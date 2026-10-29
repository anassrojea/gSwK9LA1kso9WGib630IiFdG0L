<?php

use App\Filament\Resources\DiscountCodes\Pages\ManageDiscountCodes;
use App\Filament\Widgets\OrdersChart;
use App\Filament\Widgets\PaymentsOverview;
use App\Models\AuditLog;
use App\Models\DiscountCode;
use App\Models\Event;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\User;
use App\Services\Digits;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Support\Payments;

beforeEach(function () {
    Payments::setUp();
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

function revenueOrder(string $currency, int $total, string $paidAt, string $status = 'approved'): Order
{
    return Order::factory()->create([
        'currency' => $currency,
        'total_cents' => $total,
        'status' => $status,
        'paid_at' => $paidAt,
        'created_at' => $paidAt,
    ]);
}

it('counts today\'s and this month\'s revenue per currency, never adding currencies together', function () {
    revenueOrder('SAR', 10900, '2026-11-15 09:00');
    revenueOrder('SAR', 3500, '2026-11-15 10:00', 'paid');
    revenueOrder('SYP', 350000, '2026-11-15 11:00');
    revenueOrder('SAR', 2000, '2026-11-10 10:00');
    revenueOrder('USD', 2900, '2026-11-02 10:00');
    revenueOrder('SAR', 9900, '2026-10-31 23:00'); // last month
    revenueOrder('SAR', 29900, '2026-11-15 08:00', 'refunded'); // money given back
    Order::factory()->create(['currency' => 'SAR', 'total_cents' => 10900, 'status' => 'receipt_received']); // not paid yet

    expect(PaymentsOverview::revenue(CarbonImmutable::parse('2026-11-15')))->toBe([
        'SYP' => ['total' => 350000, 'orders' => 1],
        'SAR' => ['total' => 14400, 'orders' => 2],
        'USD' => ['total' => 0, 'orders' => 0],
    ])->and(PaymentsOverview::revenue(CarbonImmutable::parse('2026-11-01')))->toBe([
        'SYP' => ['total' => 350000, 'orders' => 1],
        'SAR' => ['total' => 16400, 'orders' => 3],
        'USD' => ['total' => 2900, 'orders' => 1],
    ]);

    Livewire::test(PaymentsOverview::class)
        ->assertSee(trans('app.admin.widgets.revenue', ['currency' => 'SAR']))
        ->assertSee('١٤٤')        // SAR today
        ->assertSee('١٦٤')        // SAR this month
        ->assertSee('٣٥٠٬٠٠٠')    // SYP, on its own card
        ->assertSee(trans('app.admin.orders.waiting'));
});

it('shows new events, published events, waiting transfers and new customers today', function () {
    User::factory()->count(2)->create();
    User::factory()->create(['created_at' => '2026-11-03 10:00']);
    $published = Payments::event();
    $published->invitation->update(['published_at' => now()]);
    $old = Event::factory()->create(['created_at' => '2026-11-01 10:00']);
    Invitation::factory()->for($old)->create(['published_at' => '2026-11-02 10:00']);
    Order::factory()->create(['status' => 'receipt_received']);

    $stats = collect((fn () => $this->getStats())->call(new PaymentsOverview))
        ->mapWithKeys(fn ($stat) => [(string) $stat->getLabel() => (string) $stat->getValue()]);

    expect($stats[trans('app.admin.orders.waiting')])->toBe('١')
        ->and($stats[trans('app.admin.widgets.new_events')])->toBe('١') // the published one; the other was made on the 1st
        ->and($stats[trans('admin.dashboard.published')])->toBe('٢')
        ->and($stats[trans('admin.dashboard.new_users')])->toBe(Digits::localize(User::query()->where('created_at', '>=', today())->count(), 'ar'));
});

it('charts orders per day over 30 days', function () {
    revenueOrder('SAR', 10900, '2026-11-15 09:00');
    revenueOrder('SYP', 350000, '2026-11-15 10:00');
    Order::factory()->create(['created_at' => '2026-11-14 10:00']);
    Order::factory()->create(['created_at' => '2026-10-01 10:00']); // older than 30 days

    $series = OrdersChart::series();

    expect($series['labels'])->toHaveCount(30)
        ->and(array_sum($series['placed']))->toBe(3)
        ->and(array_sum($series['paid']))->toBe(2)
        ->and($series['paid'][29])->toBe(2)
        ->and($series['placed'][28])->toBe(1);

    Livewire::test(OrdersChart::class)->assertSuccessful();
});

it('manages discount codes: one kind at a time, unique ignoring case, logged, kept once used', function () {
    Livewire::test(ManageDiscountCodes::class)
        ->callAction('create', data: [
            'code' => 'eid-sar',
            'kind' => 'fixed',
            'amounts' => ['SAR' => '20', 'SYP' => '', 'USD' => '5.5'],
            'applies_to_plans' => true,
            'applies_to_addons' => false,
            'max_uses' => 100,
        ])
        ->assertHasNoActionErrors();

    $code = DiscountCode::query()->sole();
    expect($code->only('code', 'percent', 'applies_to_addons', 'max_uses'))->toBe(['code' => 'EID-SAR', 'percent' => null, 'applies_to_addons' => false, 'max_uses' => 100])
        ->and($code->amounts)->toEqual(['SAR' => 2000, 'USD' => 550])
        ->and($code->created_by)->toBe($this->admin->id)
        ->and(AuditLog::query()->where('action', 'discount.created')->exists())->toBeTrue();

    Livewire::test(ManageDiscountCodes::class)
        ->callAction('create', data: ['code' => 'EID-sar', 'kind' => 'percent', 'percent' => 10, 'applies_to_plans' => true, 'applies_to_addons' => true])
        ->assertHasActionErrors(['code']);
    Livewire::test(ManageDiscountCodes::class)
        ->callAction('create', data: ['code' => 'FREE', 'kind' => 'percent', 'percent' => 120, 'applies_to_plans' => true, 'applies_to_addons' => true])
        ->assertHasActionErrors(['percent']);
    Livewire::test(ManageDiscountCodes::class)
        ->callAction('create', data: ['code' => 'NONE', 'kind' => 'fixed', 'amounts' => ['SAR' => '', 'SYP' => '', 'USD' => ''], 'applies_to_plans' => true, 'applies_to_addons' => true])
        ->assertHasActionErrors();

    // switching to a percentage drops the amounts
    Livewire::test(ManageDiscountCodes::class)
        ->callAction(TestAction::make('edit')->table($code), data: ['kind' => 'percent', 'percent' => 15])
        ->assertHasNoActionErrors();
    expect($code->fresh()->only('percent', 'amounts'))->toBe(['percent' => 15, 'amounts' => null]);

    // a used code can't be deleted, only switched off
    Order::factory()->create(['discount_code_id' => $code->id, 'discount_code' => $code->code]);
    Livewire::test(ManageDiscountCodes::class)
        ->assertActionHidden(TestAction::make('delete')->table($code))
        ->assertSee('١٥٪');

    $unused = DiscountCode::factory()->create(['code' => 'SPARE']);
    Livewire::test(ManageDiscountCodes::class)
        ->callAction(TestAction::make('delete')->table($unused));
    expect(DiscountCode::query()->find($unused->id))->toBeNull()
        ->and(AuditLog::query()->where('action', 'discount.deleted')->exists())->toBeTrue();
});
