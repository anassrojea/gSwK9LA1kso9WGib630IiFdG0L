/**
 * The checkout display of bundle credits, the loyalty / referral reduction and
 * account credit (resources/js/lib/journey.ts) follows the same rules as
 * App\Services\Journey\CheckoutExtras (tests/Feature/Journey/BundlesTest.php).
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { applyJourney } from '../../resources/js/lib/journey.ts';

const base = { planAmount: 29900, subtotal: 29900, codeDiscount: 0, reductionPercent: null, useBundleCredit: false, credit: 0 };

test('a bundle credit pays the plan and drops the code', () => {
    assert.deepEqual(applyJourney({ ...base, subtotal: 29900 + 5500, codeDiscount: 1000, useBundleCredit: true }), {
        codeApplies: false,
        bundleCredit: 29900,
        reduction: 0,
        credit: 0,
        net: 5500,
    });
});

test('the loyalty discount wins over a smaller code', () => {
    const r = applyJourney({ ...base, codeDiscount: 1495, reductionPercent: 10 });
    assert.equal(r.codeApplies, false);
    assert.equal(r.reduction, 2990);
    assert.equal(r.net, 29900 - 2990);
});

test('a larger code wins over the loyalty discount', () => {
    const r = applyJourney({ ...base, codeDiscount: 7475, reductionPercent: 10 });
    assert.equal(r.codeApplies, true);
    assert.equal(r.reduction, 0);
    assert.equal(r.net, 29900 - 7475);
});

test('account credit takes what is left, never more', () => {
    assert.equal(applyJourney({ ...base, credit: 1900 }).net, 28000);
    const all = applyJourney({ ...base, credit: 99999 });
    assert.equal(all.credit, 29900);
    assert.equal(all.net, 0);
});

test('no plan: nothing for the credit or the reduction to apply to', () => {
    const r = applyJourney({ ...base, planAmount: null, subtotal: 5500, reductionPercent: 10, useBundleCredit: true });
    assert.equal(r.bundleCredit, 0);
    assert.equal(r.reduction, 0);
    assert.equal(r.net, 5500);
});
