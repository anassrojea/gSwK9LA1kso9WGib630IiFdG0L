<?php

use App\Models\Addon;
use App\Models\Plan;
use App\Models\Price;
use App\Services\Payments\Currencies;
use App\Services\Payments\Gateways\LemonSqueezyGateway;
use App\Services\Payments\Gateways\StripeGateway;
use App\Services\Payments\Gateways\TransferGateway;
use App\Services\Payments\PaymentGateways;
use App\Services\Payments\PriceList;
use Database\Seeders\PlanSeeder;
use Tests\Support\Payments;

beforeEach(fn () => Payments::setUp());

// ICU wraps the symbol in direction marks and no-break spaces
function plainMoney(string $text): string
{
    return trim(preg_replace('/\s/u', ' ', str_replace(["\u{200E}", "\u{200F}", "\u{061C}"], '', $text)));
}

it('looks prices up per currency, with clearly marked placeholders', function () {
    $prices = app(PriceList::class);
    $premium = Plan::query()->find('premium');

    expect($prices->amount($premium, 'USD'))->toBe(2900)
        ->and($prices->amount($premium, 'SAR'))->toBe(10900)
        ->and($prices->amount($premium, 'SYP'))->toBe(350000)
        ->and($prices->amount(Plan::query()->find('signature'), 'SAR'))->toBe(29900)
        ->and($prices->all($premium))->toBe(['SYP' => 350000, 'SAR' => 10900, 'USD' => 2900]);

    expect(Price::query()->where('currency', 'USD')->where('placeholder', true)->exists())->toBeFalse()
        ->and(Price::query()->whereIn('currency', ['SAR', 'SYP'])->where('placeholder', false)->exists())->toBeFalse();
});

it('falls back to price_cents for USD only, and keeps admin prices when re-seeding', function () {
    $prices = app(PriceList::class);
    $addon = Addon::query()->create(['id' => 'mystery', 'name' => 'Mystery', 'price_cents' => 1234, 'currency' => 'USD', 'active' => true]);

    expect($prices->amount($addon, 'USD'))->toBe(1234)
        ->and($prices->amount($addon, 'SAR'))->toBeNull()
        ->and($prices->amount($addon, 'SYP'))->toBeNull();

    $prices->set(Plan::query()->find('premium'), 'SAR', 9900);
    $this->seed(PlanSeeder::class);

    expect($prices->amount(Plan::query()->find('premium'), 'SAR'))->toBe(9900);
});

it('handles each currency\'s exponent: SYP in whole pounds, SAR and USD in cents', function () {
    expect(Currencies::exponent('SYP'))->toBe(0)
        ->and(Currencies::exponent('SAR'))->toBe(2)
        ->and(Currencies::toMinor('109', 'SAR'))->toBe(10900)
        ->and(Currencies::toMinor(109.5, 'SAR'))->toBe(10950)
        ->and(Currencies::toMinor('350000', 'SYP'))->toBe(350000)
        ->and(Currencies::toMajor(10950, 'SAR'))->toBe(109.5)
        ->and(Currencies::toMajor(350000, 'SYP'))->toBe(350000);
});

it('formats money with Arabic-Indic digits in Arabic', function () {
    expect(plainMoney(Currencies::format(10900, 'SAR', 'ar')))->toBe('١٠٩ ر.س.')
        ->and(plainMoney(Currencies::format(350000, 'SYP', 'ar')))->toBe('٣٥٠٬٠٠٠ ل.س.')
        ->and(plainMoney(Currencies::format(2900, 'USD', 'en')))->toBe('$29')
        ->and(plainMoney(Currencies::format(2950, 'USD', 'en')))->toBe('$29.50')
        ->and(plainMoney(Currencies::format(350000, 'SYP', 'en')))->toBe('SYP 350,000');
});

it('picks the default currency from the country', function () {
    expect(Currencies::forCountry('SY'))->toBe('SYP')
        ->and(Currencies::forCountry('sa'))->toBe('SAR')
        ->and(Currencies::forCountry('JO'))->toBe('USD')
        ->and(Currencies::forCountry(null))->toBe('USD')
        ->and(Currencies::countryForTimezone('Asia/Damascus'))->toBe('SY')
        ->and(Currencies::countryForTimezone('Europe/Berlin'))->toBeNull();
});

it('declares the currencies each method supports', function () {
    $gateways = app(PaymentGateways::class);

    expect($gateways->get('transfer')->currencies())->toBe(['SYP', 'SAR', 'USD'])
        ->and($gateways->get('stripe')->currencies())->toBe(['SAR', 'USD'])
        ->and($gateways->get('stripe')->supportsCurrency('SYP'))->toBeFalse()
        ->and($gateways->get('lemon_squeezy')->currencies())->toBe(['USD']);
});

it('charges the USD price when a card method can\'t take the host\'s currency', function (string $gateway, string $requested, ?string $charged) {
    expect(PaymentGateways::chargeCurrency(app(PaymentGateways::class)->get($gateway), $requested))->toBe($charged);
})->with([
    'transfer in SYP' => ['transfer', 'SYP', 'SYP'],
    'transfer in SAR' => ['transfer', 'SAR', 'SAR'],
    'transfer in USD' => ['transfer', 'USD', 'USD'],
    'Stripe has no SYP' => ['stripe', 'SYP', 'USD'],
    'Stripe in SAR' => ['stripe', 'SAR', 'SAR'],
    'Stripe in USD' => ['stripe', 'USD', 'USD'],
    'Lemon Squeezy in SYP' => ['lemon_squeezy', 'SYP', 'USD'],
    'Lemon Squeezy in SAR' => ['lemon_squeezy', 'SAR', 'USD'],
    'Lemon Squeezy in USD' => ['lemon_squeezy', 'USD', 'USD'],
]);

it('offers a transfer currency only when its bank account is configured', function () {
    config(['payments.transfer.accounts.SYP' => ['account_name' => 'Daawa', 'iban' => null, 'account_number' => null]]);

    expect(app(TransferGateway::class)->currencies())->toBe(['SAR', 'USD'])
        ->and(PaymentGateways::chargeCurrency(app(TransferGateway::class), 'SYP'))->toBe('USD');
});

it('switches each card gateway off when its keys are missing', function () {
    $gateways = app(PaymentGateways::class);
    expect(array_keys($gateways->enabled()))->toBe(['transfer', 'stripe', 'lemon_squeezy']);

    config(['cashier.secret' => null]);
    expect(app(StripeGateway::class)->enabled())->toBeFalse();

    config(['payments.lemon_squeezy.variant_id' => null]);
    expect(app(LemonSqueezyGateway::class)->enabled())->toBeFalse()
        ->and(array_keys($gateways->enabled()))->toBe(['transfer']);

    config(['lemon-squeezy.signing_secret' => null, 'payments.lemon_squeezy.variant_id' => '1']);
    expect(app(LemonSqueezyGateway::class)->enabled())->toBeFalse();

    config(['payments.transfer.enabled' => false]);
    expect($gateways->enabled())->toBe([]);
});
