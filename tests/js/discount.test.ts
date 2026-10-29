/**
 * Discount codes in the checkout (resources/js/lib/discount.ts), twin of
 * App\Services\Payments\Discounts::amount (tests/Feature/Payments/DiscountCodeTest.php).
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { discountFor, type DiscountLine, type DiscountRules } from '../../resources/js/lib/discount.ts';

const rules = (over: Partial<DiscountRules>): DiscountRules => ({ code: 'EID', percent: null, amounts: null, plans: true, addons: true, ...over });
const lines: DiscountLine[] = [
    { type: 'plan', id: 'premium', amount: 10900 },
    { type: 'addon', id: 'seating', amount: 5500 },
];

test('takes a percentage of the lines the code covers, rounded down', () => {
    assert.deepEqual(discountFor(rules({ percent: 20 }), lines, 'SAR'), { amount: 3280, error: null });
    assert.deepEqual(discountFor(rules({ percent: 15, addons: false }), lines, 'SAR'), { amount: 1635, error: null });
    assert.deepEqual(discountFor(rules({ percent: 50, plans: ['signature'] }), lines, 'SAR'), { amount: 2750, error: null });
});

test('a fixed code needs an amount in the charged currency (the USD fallback included)', () => {
    const fixed = rules({ amounts: { SAR: 2000 } });
    assert.deepEqual(discountFor(fixed, lines, 'SAR'), { amount: 2000, error: null });
    assert.deepEqual(discountFor(fixed, lines, 'USD'), { amount: 0, error: 'currency' });
    assert.deepEqual(discountFor(rules({ amounts: { SAR: 2000, USD: 500 } }), lines, 'USD'), { amount: 500, error: null });
});

test('never takes off more than the covered lines, and says when it covers none', () => {
    assert.deepEqual(discountFor(rules({ amounts: { SAR: 999999 } }), lines, 'SAR'), { amount: 16400, error: null });
    assert.deepEqual(discountFor(rules({ percent: 100 }), lines, 'SAR'), { amount: 16400, error: null });
    assert.deepEqual(discountFor(rules({ percent: 10, plans: false }), [lines[0]], 'SAR'), { amount: 0, error: 'items' });
});
