/**
 * The family journey at checkout (phase 8D), for the display only: twin of
 * App\Services\Journey\CheckoutExtras, which prices the order on the server.
 *
 *   1. a bundle credit pays the plan line in full (no code, no reduction with it);
 *   2. otherwise the automatic reduction (loyalty / referral welcome, a percent of
 *      the plan) and the discount code compete: the larger applies, never both;
 *   3. account credit then takes up to its balance off what is left.
 */
export interface JourneyCheckout {
    reduction: { type: 'loyalty' | 'referral_welcome'; percent: number; label: string } | null;
    bundle_credits: { id: string; plan: string; plan_name: string; bundle: string | null; expires_at: string }[];
    /** available account credit per currency (minor units); missing = none */
    credit: Record<string, number>;
}

export interface JourneyInput {
    /** the plan line's amount in the charged currency, or null without a plan */
    planAmount: number | null;
    /** the sum of every line (plan and add-ons) */
    subtotal: number;
    /** what the discount code takes off (0 without one) */
    codeDiscount: number;
    reductionPercent: number | null;
    useBundleCredit: boolean;
    /** the available credit in the charged currency, when the host ticked "use my credit" */
    credit: number;
}

export interface JourneyResult {
    /** the discount code applies (false when the reduction or a bundle credit won) */
    codeApplies: boolean;
    bundleCredit: number;
    reduction: number;
    credit: number;
    /** what is left to pay, before VAT */
    net: number;
}

export function applyJourney(input: JourneyInput): JourneyResult {
    let codeApplies = input.codeDiscount > 0;
    let bundleCredit = 0;
    let reduction = 0;

    if (input.useBundleCredit && input.planAmount !== null) {
        bundleCredit = input.planAmount;
        codeApplies = false;
    } else if (input.planAmount !== null && input.reductionPercent) {
        const amount = Math.floor((input.planAmount * input.reductionPercent) / 100);
        if (amount > input.codeDiscount) {
            reduction = amount;
            codeApplies = false;
        }
    }

    const beforeCredit = Math.max(0, input.subtotal - bundleCredit - reduction - (codeApplies ? input.codeDiscount : 0));
    const credit = Math.min(Math.max(0, input.credit), beforeCredit);

    return { codeApplies, bundleCredit, reduction, credit, net: beforeCredit - credit };
}
