/**
 * The checkout's money helpers (resources/js/lib/money.ts), twins of
 * App\Services\Payments\Currencies and PaymentGateways::chargeCurrency.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { chargeCurrency, formatMoney, total } from '../../resources/js/lib/money.ts';

// ICU puts direction marks and no-break spaces around the symbol
const plain = (text: string) => text.replace(/[\u200e\u200f\u061c]/g, '').replace(/\s/g, ' ').trim();

test('formats with each currency exponent, Arabic-Indic digits in Arabic', () => {
    assert.equal(plain(formatMoney(10900, 'SAR', 'ar')), '١٠٩ ر.س.');
    assert.equal(plain(formatMoney(350000, 'SYP', 'ar')), '٣٥٠٬٠٠٠ ل.س.');
    assert.equal(plain(formatMoney(2900, 'USD', 'en')), '$29');
    assert.equal(plain(formatMoney(2950, 'USD', 'en')), '$29.50');
    // SYP has no subunit: 350000 is 350,000 pounds, not 3,500.00
    assert.equal(plain(formatMoney(350000, 'SYP', 'en')), 'SYP 350,000');
});

test('charges the chosen currency when the method takes it, else USD, else nothing', () => {
    const transfer = ['SYP', 'SAR', 'USD'];
    const stripe = ['SAR', 'USD'];
    const lemonSqueezy = ['USD'];

    assert.equal(chargeCurrency(transfer, 'SYP'), 'SYP');
    assert.equal(chargeCurrency(stripe, 'SAR'), 'SAR');
    assert.equal(chargeCurrency(stripe, 'SYP'), 'USD');
    assert.equal(chargeCurrency(lemonSqueezy, 'SAR'), 'USD');
    assert.equal(chargeCurrency(lemonSqueezy, 'SYP'), 'USD');
    assert.equal(chargeCurrency(['EUR'], 'SYP'), null);
});

test('totals the choices in one currency, or null when one has no price there', () => {
    const premium = { prices: { SAR: 10900, USD: 2900, SYP: 350000 } };
    const packs = { prices: { SAR: 3900, USD: 1000, SYP: null }, quantity: 2 };

    assert.equal(total([premium, packs], 'SAR'), 10900 + 7800);
    assert.equal(total([premium, packs], 'USD'), 4900);
    assert.equal(total([premium, packs], 'SYP'), null);
    assert.equal(total([], 'USD'), 0);
});
