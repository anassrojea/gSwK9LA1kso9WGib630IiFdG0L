/**
 * Money in the checkout. Twin of App\Services\Payments\Currencies and
 * PaymentGateways::chargeCurrency: amounts are integers in minor units of
 * each currency's exponent (SYP 0: whole pounds; SAR and USD 2).
 */
export const EXPONENTS: Record<string, number> = { SYP: 0, SAR: 2, USD: 2 };

/** "١٠٩ ر.س.‏" in Arabic (Arabic-Indic digits), "SAR 109" / "$29.50" in English. Whole amounts drop decimals. */
export function formatMoney(minor: number, currency: string, locale: string, exponent: number = EXPONENTS[currency] ?? 2): string {
    const unit = 10 ** exponent;
    const digits = exponent > 0 && minor % unit !== 0 ? exponent : 0;

    return new Intl.NumberFormat(locale.startsWith('ar') ? 'ar-u-nu-arab' : 'en', {
        style: 'currency',
        currency,
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    }).format(minor / unit);
}

/**
 * The currency a payment method charges when the host chose `requested`:
 * the same one when the method takes it, else the fallback (USD) when it
 * takes that, else null (the method is not offered).
 */
export function chargeCurrency(methodCurrencies: string[], requested: string, fallback = 'USD'): string | null {
    if (methodCurrencies.includes(requested)) return requested;
    return methodCurrencies.includes(fallback) ? fallback : null;
}

export interface PricedChoice {
    prices: Record<string, number | null>;
    quantity?: number;
}

/** Sum of the chosen items in one currency, or null when one of them has no price in it. */
export function total(choices: PricedChoice[], currency: string): number | null {
    let sum = 0;
    for (const choice of choices) {
        const price = choice.prices[currency];
        if (price === null || price === undefined) return null;
        sum += price * (choice.quantity ?? 1);
    }
    return sum;
}
