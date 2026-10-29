/**
 * Discount codes in the checkout. Twin of App\Services\Payments\Discounts::amount:
 * the server prices the order itself; this only shows the same numbers first.
 */

/** POST /checkout/discount → Discounts::present */
export interface DiscountRules {
    code: string;
    /** 1–100, or null for a fixed amount */
    percent: number | null;
    /** fixed amounts per currency, in minor units; null for a percentage */
    amounts: Record<string, number> | null;
    /** false: never; true: every plan; a list: only those ids */
    plans: boolean | string[];
    addons: boolean | string[];
}

export interface DiscountLine {
    type: 'plan' | 'addon';
    id: string;
    /** the line total in minor units of the charged currency */
    amount: number;
}

export type DiscountResult = { amount: number; error: null } | { amount: 0; error: 'items' | 'currency' };

export function covers(rules: DiscountRules, type: 'plan' | 'addon', id: string): boolean {
    const target = type === 'plan' ? rules.plans : rules.addons;
    return Array.isArray(target) ? target.length === 0 || target.includes(id) : target;
}

/**
 * The discount on the chosen lines in `currency` (the one actually charged, after
 * the USD fallback). Never more than the lines it applies to, so a total never
 * goes below zero.
 */
export function discountFor(rules: DiscountRules, lines: DiscountLine[], currency: string): DiscountResult {
    const eligible = lines.filter((line) => covers(rules, line.type, line.id)).reduce((sum, line) => sum + line.amount, 0);
    if (eligible <= 0) return { amount: 0, error: 'items' };

    let discount: number;
    if (rules.percent !== null) {
        discount = Math.floor((eligible * rules.percent) / 100);
    } else {
        const fixed = rules.amounts?.[currency];
        if (fixed === undefined || fixed === null) return { amount: 0, error: 'currency' };
        discount = fixed;
    }

    return { amount: Math.max(0, Math.min(discount, eligible)), error: null };
}
