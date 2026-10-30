<?php

use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\InvoicesRelationManager;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\Invoicing\InvoiceCsv;
use App\Services\Invoicing\InvoiceNumbers;
use App\Services\Invoicing\InvoicePdf;
use App\Services\Invoicing\Invoicer;
use App\Services\Invoicing\Vat;
use App\Services\Invoicing\ZatcaQr;
use App\Services\OgImage\Chromium;
use App\Services\Payments\CheckoutService;
use App\Services\Payments\OrderFulfillment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\FakePdfChromium;
use Tests\Support\Payments;

/*
| Phase 6B: invoices, receipts and credit notes (docs/INVOICES.md).
*/

beforeEach(function () {
    Payments::setUp();
    Notification::fake();
    Storage::fake(config('filesystems.media_disk'));
    config([
        'invoicing.seller.vat_number' => null,
        'invoicing.seller.name_ar' => 'مؤسسة دعوة',
        'invoicing.seller.name_en' => 'Daawa Est.',
        'invoicing.vat.rate' => 15,
        'invoicing.vat.prices_include_vat' => true,
        'invoicing.vat.currencies' => ['SAR'],
        'invoicing.vat.countries' => [],
    ]);
    $this->admin = User::factory()->admin()->create();
});

function invoiceVatRegistered(): void
{
    config(['invoicing.seller.vat_number' => '310122393500003']);
}

/**
 * An approved transfer order (Premium 109 SAR by default) for a host in Saudi Arabia.
 */
function invoicedOrder(array $attributes = [], ?User $owner = null): Order
{
    $owner ??= User::factory()->create(['country' => 'SA', 'name' => 'ليان الحسن']);
    $event = Payments::event($owner);
    $order = Order::factory()->create($attributes + [
        'user_id' => $owner->id,
        'event_id' => $event->id,
        'currency' => 'SAR',
        'total_cents' => 10900,
        'items' => [['type' => 'plan', 'id' => 'premium', 'name' => 'Premium', 'quantity' => 1, 'unit_amount' => 10900, 'amount' => 10900]],
    ]);
    app(OrderFulfillment::class)->approveTransfer($order, test()->admin, 'BANK-'.$order->code);

    return $order->fresh();
}

// ---- VAT math -------------------------------------------------------------

it('extracts VAT from inclusive prices with half-away-from-zero rounding', function (int $gross, int $vat) {
    expect(Vat::inGross($gross, 1500))->toBe($vat)
        ->and(Vat::inGross(-$gross, 1500))->toBe(-$vat);
})->with([
    '109.00' => [10900, 1422],   // 1421.739…
    '115.00' => [11500, 1500],   // exact
    '0.23' => [23, 3],           // 3.0
    '0.01' => [1, 0],            // 0.13
    '0.08' => [8, 1],            // 1.043…
    'half' => [2300 + 115, 315], // 2415 × 15/115 = 315.0
    '164.70' => [16470, 2148],   // 2148.26…
]);

it('adds VAT on net prices and rounds halves away from zero', function () {
    expect(Vat::onNet(10000, 1500))->toBe(1500)
        ->and(Vat::onNet(10, 1500))->toBe(2)      // 1.5 → 2
        ->and(Vat::onNet(-10, 1500))->toBe(-2)
        ->and(Vat::onNet(3, 1500))->toBe(0)       // 0.45
        ->and(Vat::divideRounded(5, 2))->toBe(3)
        ->and(Vat::divideRounded(-5, 2))->toBe(-3);
});

it('allocates the document VAT over the lines so they add up exactly', function () {
    $amounts = [10900, 7400, -1830];
    $vat = Vat::allocate($amounts, Vat::inGross(array_sum($amounts), 1500), 1500, true);

    expect(array_sum($vat))->toBe(2148)
        ->and($vat)->toBe([1422, 965, -239]);

    // rounding drift goes on the largest line
    $lines = [1, 1, 1];
    $vat = Vat::allocate($lines, 1, 1500, true);
    expect(array_sum($vat))->toBe(1)->and($vat)->toBe([1, 0, 0]);
});

it('decides where VAT applies from the configuration', function () {
    expect(Vat::applies('SAR', 'SA'))->toBeFalse(); // not registered

    invoiceVatRegistered();
    expect(Vat::applies('SAR', 'SA'))->toBeTrue()
        ->and(Vat::applies('SAR', null))->toBeTrue()
        ->and(Vat::applies('USD', 'SA'))->toBeFalse()
        ->and(Vat::applies('SYP', 'SY'))->toBeFalse();

    config(['invoicing.vat.currencies' => ['SAR', 'USD'], 'invoicing.vat.countries' => ['SA']]);
    expect(Vat::applies('USD', 'SA'))->toBeTrue()
        ->and(Vat::applies('USD', 'US'))->toBeFalse();
});

// ---- ZATCA QR ---------------------------------------------------------------

it('encodes the ZATCA phase-1 QR as TLV base64 (published vector)', function () {
    $payload = ZatcaQr::payload('Bobs Records', '310122393500003', CarbonImmutable::parse('2022-04-25T15:30:00Z'), '1000.00', '150.00');

    expect($payload)->toBe('AQxCb2JzIFJlY29yZHMCDzMxMDEyMjM5MzUwMDAwMwMUMjAyMi0wNC0yNVQxNTozMDowMFoEBzEwMDAuMDAFBjE1MC4wMA==')
        ->and(ZatcaQr::decode($payload))->toBe([1 => 'Bobs Records', 2 => '310122393500003', 3 => '2022-04-25T15:30:00Z', 4 => '1000.00', 5 => '150.00']);
});

it('counts Arabic seller names in UTF-8 bytes', function () {
    $tlv = ZatcaQr::tlv([1 => 'دعوة']);

    expect(ord($tlv[0]))->toBe(1)
        ->and(ord($tlv[1]))->toBe(8)
        ->and(substr($tlv, 2))->toBe('دعوة')
        ->and(ZatcaQr::amount(10900, 'SAR'))->toBe('109.00')
        ->and(ZatcaQr::amount(5, 'SAR'))->toBe('0.05')
        ->and(ZatcaQr::amount(1150000, 'SYP'))->toBe('1150000');
});

// ---- issuing ----------------------------------------------------------------

it('issues a plain receipt with no VAT while the seller is not VAT-registered', function () {
    $order = invoicedOrder();
    $invoice = $order->invoices()->sole();

    expect($invoice)
        ->kind->toBe('invoice')
        ->type->toBe('receipt')
        ->number->toBe('INV-'.now('Asia/Riyadh')->year.'-000001')
        ->vat_cents->toBe(0)
        ->vat_rate_bp->toBe(0)
        ->subtotal_cents->toBe(10900)
        ->total_cents->toBe(10900)
        ->qr->toBeNull()
        ->user_id->toBe($order->user_id)
        ->and($invoice->customer)->toBe(['name' => 'ليان الحسن', 'email' => $order->user->email, 'country' => 'SA'])
        ->and($invoice->seller['name_ar'])->toBe('مؤسسة دعوة')
        ->and($invoice->seller['vat_number'])->toBeNull()
        ->and($invoice->lines[0])->toMatchArray(['type' => 'plan', 'id' => 'premium', 'name_en' => 'Premium', 'amount' => 10900, 'net' => 10900, 'vat' => 0]);

    expect(AuditLog::query()->where('action', 'invoice.issued')->where('entity_id', $invoice->id)->exists())->toBeTrue();
});

it('issues a simplified tax invoice with VAT extracted and the ZATCA QR for SAR orders', function () {
    invoiceVatRegistered();
    $order = invoicedOrder([
        'items' => [
            ['type' => 'plan', 'id' => 'premium', 'name' => 'Premium', 'quantity' => 1, 'unit_amount' => 10900, 'amount' => 10900],
            ['type' => 'addon', 'id' => 'guests_200', 'name' => 'Extra 200 guests', 'quantity' => 2, 'unit_amount' => 3700, 'amount' => 7400],
        ],
        'discount_code' => 'EID10',
        'discount_cents' => 1830,
        'total_cents' => 16470,
    ]);
    $invoice = $order->invoices()->sole();

    expect($invoice)
        ->type->toBe('simplified_tax_invoice')
        ->vat_rate_bp->toBe(1500)
        ->gross_cents->toBe(18300)
        ->discount_cents->toBe(1830)
        ->vat_cents->toBe(2148)
        ->subtotal_cents->toBe(14322)
        ->total_cents->toBe(16470)
        ->and(array_column($invoice->lines, 'type'))->toBe(['plan', 'addon', 'discount'])
        ->and(array_sum(array_column($invoice->lines, 'vat')))->toBe(2148)
        ->and(array_sum(array_column($invoice->lines, 'net')))->toBe(14322)
        ->and($invoice->lines[2])->toMatchArray(['amount' => -1830, 'name_en' => 'Discount (EID10)', 'name_ar' => 'خصم (EID10)']);

    $qr = ZatcaQr::decode($invoice->qr);
    expect($qr[1])->toBe('مؤسسة دعوة')
        ->and($qr[2])->toBe('310122393500003')
        ->and($qr[3])->toBe($invoice->issued_at->utc()->format('Y-m-d\TH:i:s\Z'))
        ->and($qr[4])->toBe('164.70')
        ->and($qr[5])->toBe('21.48');
});

it('gives SYP and USD orders a receipt without VAT unless configured', function () {
    invoiceVatRegistered();
    $syp = invoicedOrder(['currency' => 'SYP', 'total_cents' => 950000, 'items' => [['type' => 'plan', 'id' => 'premium', 'name' => 'Premium', 'quantity' => 1, 'unit_amount' => 950000, 'amount' => 950000]]]);
    $usd = invoicedOrder(['currency' => 'USD', 'total_cents' => 2900]);

    expect($syp->invoices()->sole()->only(['type', 'vat_cents']))->toBe(['type' => 'receipt', 'vat_cents' => 0])
        ->and($usd->invoices()->sole()->only(['type', 'vat_cents']))->toBe(['type' => 'receipt', 'vat_cents' => 0]);

    config(['invoicing.vat.currencies' => ['SAR', 'USD']]);
    $taxedUsd = invoicedOrder(['currency' => 'USD', 'total_cents' => 2900]);
    expect($taxedUsd->invoices()->sole()->only(['type', 'vat_cents']))->toBe(['type' => 'simplified_tax_invoice', 'vat_cents' => 378]);
});

it('adds VAT on top at checkout when prices are VAT-exclusive, and invoices it as charged', function () {
    invoiceVatRegistered();
    config(['invoicing.vat.prices_include_vat' => false]);
    $event = Payments::event(User::factory()->create(['country' => 'SA']));

    $this->actingAs($event->owner)
        ->post('/checkout', ['event_id' => $event->id, 'plan' => 'premium', 'currency' => 'SAR', 'gateway' => 'transfer', 'country' => 'SA'])
        ->assertSessionHasNoErrors();

    $order = Order::query()->sole();
    expect($order->vat_cents)->toBe(1635)          // 10900 × 15 %
        ->and($order->total_cents)->toBe(12535)
        ->and($order->subtotal())->toBe(10900);

    app(OrderFulfillment::class)->approveTransfer($order, $this->admin, 'BANK-EXCL');
    $invoice = $order->invoices()->sole();

    expect($invoice->only(['type', 'prices_include_vat', 'subtotal_cents', 'vat_cents', 'total_cents']))
        ->toBe(['type' => 'simplified_tax_invoice', 'prices_include_vat' => false, 'subtotal_cents' => 10900, 'vat_cents' => 1635, 'total_cents' => 12535])
        ->and($invoice->lines[0])->toMatchArray(['amount' => 10900, 'net' => 10900, 'vat' => 1635]);

    // the checkout panel adds the same VAT line
    expect(app(CheckoutService::class)->options($event, $event->owner)['vat'])->toMatchArray(['rate_bp' => 1500, 'currencies' => ['SAR']]);
    config(['invoicing.vat.prices_include_vat' => true]);
    expect(app(CheckoutService::class)->options($event, $event->owner)['vat'])->toBeNull();
});

it('issues one invoice per order, for card payments and free orders too', function () {
    $event = Payments::event();
    $card = Order::factory()->card()->create(['user_id' => $event->owner_id, 'event_id' => $event->id, 'total_cents' => 2900, 'currency' => 'USD']);
    $fulfillment = app(OrderFulfillment::class);

    $fulfillment->markPaid($card, 'stripe', 'cs_1', 2900, 'USD');
    $fulfillment->markPaid($card, 'stripe', 'cs_1', 2900, 'USD'); // retried webhook
    expect($card->invoices()->count())->toBe(1);

    $free = Order::factory()->create(['user_id' => $event->owner_id, 'event_id' => $event->id, 'total_cents' => 0, 'discount_cents' => 2900, 'discount_code' => 'GIFT']);
    $fulfillment->fulfillFree($free);
    expect($free->invoices()->sole()->total_cents)->toBe(0);

    expect(app(Invoicer::class)->issue($card->fresh())->getKey())->toBe($card->invoices()->sole()->getKey());
});

it('numbers documents sequentially without gaps, per series and year', function () {
    $orders = collect(range(1, 5))->map(fn () => invoicedOrder());
    $year = now('Asia/Riyadh')->year;

    expect(Invoice::query()->orderBy('sequence')->pluck('number')->all())->toBe(
        collect(range(1, 5))->map(fn ($n) => sprintf('INV-%d-%06d', $year, $n))->all()
    );

    // a payment that fails after taking a number gives it back
    try {
        DB::transaction(function () use ($year) {
            app(InvoiceNumbers::class)->next('INV', $year);
            throw new RuntimeException('gateway hiccup');
        });
    } catch (RuntimeException) {
    }
    expect(invoicedOrder()->invoices()->sole()->number)->toBe(sprintf('INV-%d-%06d', $year, 6));

    // credit notes have their own series
    app(OrderFulfillment::class)->refund($orders[0], $this->admin, 'cancelled');
    expect($orders[0]->invoices()->where('kind', 'credit_note')->sole()->number)->toBe(sprintf('CN-%d-%06d', $year, 1));

    // a new year starts a new series
    $this->travelTo(CarbonImmutable::create($year + 1, 1, 2, 12));
    expect(invoicedOrder()->invoices()->sole()->number)->toBe(sprintf('INV-%d-%06d', $year + 1, 1));
});

it('stays gap-free when several orders are paid one after another in a burst', function () {
    $orders = collect(range(1, 12))->map(function () {
        $event = Payments::event();

        return Order::factory()->create(['user_id' => $event->owner_id, 'event_id' => $event->id, 'currency' => 'SAR', 'total_cents' => 10900]);
    });

    // interleave approvals with failing transactions that each take a number
    foreach ($orders as $i => $order) {
        if ($i % 3 === 0) {
            rescue(fn () => DB::transaction(function () {
                app(InvoiceNumbers::class)->next('INV', now('Asia/Riyadh')->year);
                throw new RuntimeException('rolled back');
            }), report: false);
        }
        app(OrderFulfillment::class)->approveTransfer($order, $this->admin, 'BANK-'.$i);
    }

    expect(Invoice::query()->orderBy('sequence')->pluck('sequence')->all())->toBe(range(1, 12))
        ->and(DB::table('invoice_counters')->where('series', 'INV-'.now('Asia/Riyadh')->year)->value('last_number'))->toBe(12);
});

it('issues a credit note referencing the invoice when an order is refunded', function () {
    invoiceVatRegistered();
    $order = invoicedOrder();
    $invoice = $order->invoices()->sole();

    app(OrderFulfillment::class)->refund($order, $this->admin, 'Event cancelled');
    app(OrderFulfillment::class)->refund($order->fresh(), $this->admin, 'again'); // already refunded: nothing more

    $note = Invoice::query()->where('kind', 'credit_note')->sole();
    expect($note)
        ->credit_for_id->toBe($invoice->id)
        ->order_id->toBe($order->id)
        ->type->toBe('simplified_tax_invoice')
        ->total_cents->toBe(-10900)
        ->vat_cents->toBe(-1422)
        ->subtotal_cents->toBe(-9478)
        ->and($note->lines[0])->toMatchArray(['amount' => -10900, 'vat' => -1422, 'net' => -9478])
        ->and(ZatcaQr::decode($note->qr)[4])->toBe('109.00')
        ->and($note->titleKey())->toBe('simplified_tax_credit_note')
        ->and($invoice->fresh()->creditNote->id)->toBe($note->id);

    expect(AuditLog::query()->where('action', 'invoice.credit_note_issued')->exists())->toBeTrue();
});

it('skips the credit note for an order paid before invoices existed', function () {
    $order = invoicedOrder();
    $order->invoices()->delete();

    app(OrderFulfillment::class)->refund($order, $this->admin);

    expect(Invoice::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'invoice.credit_note_skipped')->exists())->toBeTrue();
});

// ---- PDF and access ---------------------------------------------------------

it('renders the PDF on first download, caches it, and only for its customer or an admin', function () {
    $chromium = FakePdfChromium::install();
    invoiceVatRegistered();
    $order = invoicedOrder();
    $invoice = $order->invoices()->sole();
    $url = "/invoices/{$invoice->number}.pdf";

    $this->get($url)->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->get($url)->assertNotFound();

    $response = $this->actingAs($order->user)->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain($invoice->number.'.pdf')
        ->and($response->streamedContent())->toStartWith('%PDF-');

    $this->actingAs($this->admin)->get($url)->assertOk();

    expect($chromium->renders)->toBe(1)
        ->and($invoice->fresh()->pdf_path)->toStartWith("invoices/{$invoice->id}/")
        ->and($chromium->html[0])->toContain('فاتورة ضريبية مبسطة')
        ->and($chromium->html[0])->toContain('Simplified Tax Invoice')
        ->and($chromium->html[0])->toContain('310122393500003')
        ->and($chromium->html[0])->toContain('data:image/svg+xml;base64,');
    Storage::disk(config('filesystems.media_disk'))->assertExists($invoice->fresh()->pdf_path);
});

it('goes back with a message when the PDF cannot be rendered', function () {
    app()->instance(Chromium::class, new class extends Chromium
    {
        public function pdf(string $html, int $timeout): string
        {
            throw new RuntimeException('no chromium');
        }
    });
    $order = invoicedOrder();
    $invoice = $order->invoices()->sole();

    $this->actingAs($order->user)->from('/dashboard/orders')->get("/invoices/{$invoice->number}.pdf")
        ->assertRedirect('/dashboard/orders')
        ->assertSessionHas('warning', trans('invoices.not_ready', [], 'ar'));
});

it('prints a real A4 PDF with headless Chromium', function () {
    if (! app(Chromium::class)->available()) {
        $this->markTestSkipped('Chromium not installed (set OG_CHROME_PATH).');
    }
    config(['og.chrome_no_sandbox' => true]);
    invoiceVatRegistered();
    $invoice = invoicedOrder()->invoices()->sole();

    $path = app(InvoicePdf::class)->ensure($invoice);
    $pdf = Storage::disk(config('filesystems.media_disk'))->get($path);

    expect($pdf)->toStartWith('%PDF-')
        ->and(strlen($pdf))->toBeGreaterThan(5000)
        ->and(preg_match('#/MediaBox\s*\[\s*0 0 59[45]\.?\d* 84[12]\.?\d*\s*\]#', $pdf))->toBe(1); // A4 in points
});

it('lists the invoices on the order page and the billing tab', function () {
    $order = invoicedOrder();
    $invoice = $order->invoices()->sole();
    $url = "/invoices/{$invoice->number}.pdf";

    $this->actingAs($order->user)->get("/dashboard/orders/{$order->code}")
        ->assertInertia(fn ($page) => $page
            ->where('order.invoices.0.number', $invoice->number)
            ->where('order.invoices.0.url', $url)
            ->where('order.invoices.0.title', 'إيصال'));

    $this->get('/account/billing')
        ->assertInertia(fn ($page) => $page->where('billing.orders.0.invoices.0.url', $url));
});

// ---- admin --------------------------------------------------------------------

it('lists invoices in Filament with type and date filters, and on the order page', function () {
    invoiceVatRegistered();
    $tax = invoicedOrder()->invoices()->sole();
    config(['invoicing.seller.vat_number' => null]);
    $this->travel(3)->days();
    $receipt = invoicedOrder()->invoices()->sole();

    $this->actingAs($this->admin);
    Livewire::test(ListInvoices::class)
        ->assertCanSeeTableRecords([$tax, $receipt])
        ->filterTable('type', 'simplified_tax_invoice')
        ->assertCanSeeTableRecords([$tax])
        ->assertCanNotSeeTableRecords([$receipt]);

    Livewire::test(ListInvoices::class)
        ->filterTable('issued', ['from' => now('Asia/Riyadh')->toDateString(), 'until' => null])
        ->assertCanSeeTableRecords([$receipt])
        ->assertCanNotSeeTableRecords([$tax]);

    Livewire::test(InvoicesRelationManager::class, ['ownerRecord' => $tax->order, 'pageClass' => ViewOrder::class])
        ->assertCanSeeTableRecords([$tax]);

    $this->get('/admin/invoices')->assertOk();
});

it('exports the filtered invoices as CSV for the accountant', function () {
    invoiceVatRegistered();
    $order = invoicedOrder([], User::factory()->create(['name' => '=HYPERLINK("x")', 'country' => 'SA']));
    app(OrderFulfillment::class)->refund($order, $this->admin);

    $out = fopen('php://memory', 'w+');
    app(InvoiceCsv::class)->write($out, Invoice::query());
    rewind($out);
    $csv = stream_get_contents($out);
    $rows = array_map(fn ($line) => str_getcsv($line, escape: ''), array_filter(explode("\n", substr($csv, 3))));

    expect(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF")
        ->and($rows[0])->toBe(InvoiceCsv::COLUMNS)
        ->and($rows)->toHaveCount(3)
        ->and($rows[1][1])->toBe('invoice')
        ->and($rows[1][6])->toBe("'=HYPERLINK(\"x\")")
        ->and(array_slice($rows[1], 9))->toBe(['SAR', '109.00', '0.00', '94.78', '15', '14.22', '109.00'])
        ->and($rows[2][1])->toBe('credit_note')
        ->and($rows[2][5])->toBe($rows[1][0])
        ->and($rows[2][15])->toBe('-109.00');

    $this->actingAs($this->admin);
    Livewire::test(ListInvoices::class)
        ->callAction('export')
        ->assertFileDownloaded('invoices-'.now()->format('Y-m-d').'.csv');
    expect(AuditLog::query()->where('action', 'invoice.exported')->exists())->toBeTrue();
});
