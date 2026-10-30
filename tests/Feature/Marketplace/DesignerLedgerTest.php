<?php

use App\Filament\Resources\Marketplace\Pages\ListDesignerPayouts;
use App\Models\AuditLog;
use App\Models\DesignerLedgerEntry;
use App\Models\DesignerPayout;
use App\Models\DiscountCode;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Template;
use App\Models\User;
use App\Services\Marketplace\DesignerLedger;
use App\Services\Marketplace\Designers;
use App\Services\Marketplace\MarketplaceCheckout;
use App\Services\Marketplace\PayoutStatements;
use App\Services\Marketplace\RevenueShare;
use App\Services\Marketplace\TemplateAccess;
use App\Services\Payments\OrderFulfillment;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\Support\Marketplace;
use Tests\Support\Payments;

beforeEach(function () {
    Payments::setUp();
    Notification::fake();
    // VAT-registered, prices include 15 % VAT in SAR (docs/INVOICES.md)
    config([
        'invoicing.seller.vat_number' => '310122393500003',
        'invoicing.vat.rate' => 15,
        'invoicing.vat.prices_include_vat' => true,
        'invoicing.vat.currencies' => ['SAR'],
        'invoicing.vat.countries' => [],
    ]);
});

/**
 * A paid (approved transfer) order for one marketplace template.
 */
function marketplaceSale(Template $template, string $currency = 'SAR', ?string $code = null, ?User $host = null): Order
{
    $host ??= User::factory()->create(['email_verified_at' => now(), 'country' => 'SA']);
    $event = Marketplace::eventUsing($template, $host);
    $order = app(MarketplaceCheckout::class)->buyTemplate($host, $event, $template, ['currency' => $currency, 'gateway' => 'transfer', 'country' => 'SA', 'discount_code' => $code]);
    app(OrderFulfillment::class)->approveTransfer($order, Marketplace::admin(), 'REF-'.$order->code);

    return $order->fresh();
}

function payable(string $designerId, string $currency): int
{
    return app(DesignerLedger::class)->balances($designerId)[$currency] ?? 0;
}

it('credits the designer 70 % of the net amount: after the discount, without VAT', function () {
    $template = Marketplace::published(); // SAR 115.00 VAT included
    DiscountCode::factory()->create(['code' => 'EID20', 'percent' => 20, 'applies_to_plans' => false, 'applies_to_addons' => true]);

    $order = marketplaceSale($template, 'SAR', 'EID20');

    // 115.00 − 20 % = 92.00 paid; VAT inside 92.00 at 15 % = 12.00; net 80.00; 70 % = 56.00
    expect($order->total_cents)->toBe(9200)->and($order->discount_cents)->toBe(2300);
    $invoice = Invoice::query()->where('order_id', $order->id)->sole();
    expect($invoice->vat_cents)->toBe(1200)->and($invoice->subtotal_cents)->toBe(8000);

    $rows = DesignerLedgerEntry::query()->where('order_id', $order->id)->get()->keyBy('account');
    expect($rows['sales']->debit_cents)->toBe(8000)
        ->and($rows['designer_payable']->credit_cents)->toBe(5600)
        ->and($rows['platform_share']->credit_cents)->toBe(2400)
        ->and($rows['sales']->memo)->toMatchArray(['net' => 8000, 'share' => 5600, 'platform' => 2400, 'rate_bp' => 7000, 'line_net' => 10000, 'discount_net' => -2000])
        ->and($rows->pluck('currency')->unique()->all())->toBe(['SAR'])
        ->and($rows->pluck('txn_id')->unique())->toHaveCount(1);

    // balanced
    expect($rows->sum('debit_cents'))->toBe($rows->sum('credit_cents'));
    expect(payable($template->designer_id, 'SAR'))->toBe(5600);
    expect($template->fresh()->sales_count)->toBe(1);
});

it('uses the designer’s own commission and integer rounding, in the order currency', function () {
    $profile = Marketplace::designer();
    app(Designers::class)->setCommission($profile, 6550, Marketplace::admin());
    $template = Marketplace::published($profile->fresh(), ['prices' => ['USD' => 999, 'SYP' => 150001]]);

    // SYP: no VAT, no subunit. 150001 × 65.5 % = 98250.655 → 98251
    $order = marketplaceSale($template, 'SYP');
    expect($order->currency)->toBe('SYP');
    expect(payable($template->designer_id, 'SYP'))->toBe(98251)
        ->and(DesignerLedgerEntry::query()->where('account', 'platform_share')->value('credit_cents'))->toBe(51750);

    // USD stays separate: never converted or added to SYP
    marketplaceSale($template, 'USD');
    expect(app(DesignerLedger::class)->balances($template->designer_id))->toBe(['SYP' => 98251, 'USD' => 654]);
});

it('shares a discount only among the lines the code covered, to the last minor unit', function () {
    expect(RevenueShare::allocate(-1000, [0 => 3333, 2 => 6667]))->toBe([0 => -333, 2 => -667])
        ->and(RevenueShare::allocate(-100, [0 => 1, 1 => 1, 2 => 1]))->toBe([0 => -34, 1 => -33, 2 => -33])
        ->and(array_sum(RevenueShare::allocate(-12345, [0 => 7, 1 => 11, 2 => 13])))->toBe(-12345)
        ->and(RevenueShare::allocate(500, [0 => 0]))->toBe([]);

    // a code limited to plans takes nothing off the design
    $template = Marketplace::published();
    DiscountCode::factory()->create(['code' => 'PLANS', 'percent' => 50, 'applies_to_plans' => true, 'applies_to_addons' => false]);
    $host = User::factory()->create(['email_verified_at' => now()]);
    $event = Marketplace::eventUsing($template, $host);
    expect(fn () => app(MarketplaceCheckout::class)->buyTemplate($host, $event, $template, ['currency' => 'SAR', 'gateway' => 'transfer', 'discount_code' => 'PLANS']))
        ->toThrow(ValidationException::class);
});

it('reverses the credit when the order is refunded (with the credit note)', function () {
    $template = Marketplace::published();
    $order = marketplaceSale($template);
    expect(payable($template->designer_id, 'SAR'))->toBe(7000); // 115.00 incl. VAT → 100.00 net → 70.00

    app(OrderFulfillment::class)->refund($order, Marketplace::admin(), 'Wrong design');

    expect(Invoice::query()->where('order_id', $order->id)->where('kind', 'credit_note')->exists())->toBeTrue();
    $refund = DesignerLedgerEntry::query()->where('order_id', $order->id)->where('kind', 'refund')->get()->keyBy('account');
    expect($refund['designer_payable']->debit_cents)->toBe(7000)
        ->and($refund['sales']->credit_cents)->toBe(10000)
        ->and($refund['platform_share']->debit_cents)->toBe(3000);
    expect(payable($template->designer_id, 'SAR'))->toBe(0)
        ->and($template->fresh()->sales_count)->toBe(0);

    // the template's entitlement ended with the order
    expect(app(TemplateAccess::class)->owned($order->event, $template))->toBeFalse();

    // a second refund changes nothing
    app(DesignerLedger::class)->recordRefund($order, $template);
    expect(DesignerLedgerEntry::query()->where('kind', 'refund')->count())->toBe(3);
});

it('never updates or deletes ledger rows', function () {
    $template = Marketplace::published();
    marketplaceSale($template);
    $row = DesignerLedgerEntry::query()->first();

    expect(fn () => $row->update(['credit_cents' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $row->delete())->toThrow(LogicException::class);

    // and the database refuses it too
    expect(fn () => DB::table('designer_ledger_entries')->where('id', $row->id)->update(['credit_cents' => 1]))->toThrow(QueryException::class);
});

it('issues monthly statements per currency from the minimum, carrying smaller amounts over', function () {
    config(['marketplace.payout_minimum' => ['SAR' => 10000, 'SYP' => 100000, 'USD' => 5000]]);
    $template = Marketplace::published();
    $designer = $template->designer_id;
    $statements = app(PayoutStatements::class);

    // October: one SAR sale (70.00, under the SAR 100.00 minimum) and one SYP sale (105,000 ≥ 100,000)
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00'));
    marketplaceSale($template, 'SAR');
    marketplaceSale($template, 'SYP');

    $this->travelTo(CarbonImmutable::parse('2026-11-01 06:00'));
    $this->artisan('marketplace:payout-statements')->assertSuccessful();
    $october = DesignerPayout::query()->where('period', '2026-10')->get()->keyBy('currency');
    expect($october->keys()->all())->toBe(['SYP'])
        ->and($october['SYP']->amount_cents)->toBe(105000)
        ->and($october['SYP']->sales_count)->toBe(1)
        ->and($october['SYP']->status)->toBe('pending');

    // running it again changes nothing
    $this->artisan('marketplace:payout-statements', ['--month' => '2026-10'])->assertSuccessful();
    expect(DesignerPayout::query()->count())->toBe(1);

    // November: another SAR sale: 70 carried + 70 = 140.00 ≥ 100.00; SYP: nothing new
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00'));
    marketplaceSale($template, 'SAR');
    $this->travelTo(CarbonImmutable::parse('2026-12-01 06:00'));
    $statements->run(CarbonImmutable::parse('2026-11-01'));

    $november = DesignerPayout::query()->where('period', '2026-11')->get()->keyBy('currency');
    expect($november->keys()->all())->toBe(['SAR'])
        ->and($november['SAR']->amount_cents)->toBe(14000)
        ->and($november['SAR']->sales_count)->toBe(1);
    expect(app(DesignerLedger::class)->balances($designer))->toBe(['SAR' => 14000, 'SYP' => 105000]);
});

it('marks a statement paid with the bank reference, in the ledger and the audit log', function () {
    config(['marketplace.payout_minimum.SAR' => 1000]);
    $template = Marketplace::published();
    marketplaceSale($template);
    $payout = app(PayoutStatements::class)->run(CarbonImmutable::now()->startOfMonth())[0];
    $admin = Marketplace::admin();
    $ledger = app(DesignerLedger::class);

    expect(fn () => $ledger->markPaid($payout, $template->designer, 'X'))->toThrow(AuthorizationException::class)
        ->and(fn () => $ledger->markPaid($payout, $admin, '  '))->toThrow(ValidationException::class);

    $ledger->markPaid($payout, $admin, 'RJHI-998877', 'October payout');
    $payout->refresh();
    expect($payout->status)->toBe('paid')
        ->and($payout->reference)->toBe('RJHI-998877')
        ->and($payout->paid_by)->toBe($admin->id);
    expect(payable($template->designer_id, 'SAR'))->toBe(0);

    $log = AuditLog::query()->where('action', 'marketplace.payout_paid')->sole();
    expect($log->actor_id)->toBe($admin->id)
        ->and($log->data)->toMatchArray(['amount' => 7000, 'currency' => 'SAR', 'reference' => 'RJHI-998877']);

    expect(fn () => $ledger->markPaid($payout, $admin, 'AGAIN'))->toThrow(ValidationException::class);

    // the designer sees the statement (and only their own)
    $this->actingAs($template->designer)->get('/designer')->assertInertia(fn (Assert $page) => $page
        ->where('payouts.0.status', 'paid')
        ->where('payouts.0.reference', 'RJHI-998877')
        ->has('payouts', 1));
    $this->actingAs(Marketplace::designer()->user)->get('/designer')->assertInertia(fn (Assert $page) => $page->has('payouts', 0)->has('balances', 0));
});

it('marks payouts paid from the Filament payouts page', function () {
    config(['marketplace.payout_minimum.SAR' => 1000]);
    $template = Marketplace::published();
    marketplaceSale($template);
    $payout = app(PayoutStatements::class)->run(CarbonImmutable::now()->startOfMonth())[0];
    $admin = Marketplace::admin();

    $this->actingAs($admin);
    Livewire::test(ListDesignerPayouts::class)
        ->assertCanSeeTableRecords([$payout])
        ->callTableAction('mark_paid', $payout, ['reference' => 'BANK-42', 'note' => null])
        ->assertHasNoTableActionErrors();

    expect($payout->fresh()->reference)->toBe('BANK-42')
        ->and(AuditLog::query()->where('action', 'marketplace.payout_paid')->where('actor_id', $admin->id)->exists())->toBeTrue();
});
