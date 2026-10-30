/*
 * Masks email addresses and phone numbers before an error report leaves the
 * browser (twin of App\Support\SentryScrubber). Pure functions: tests/js/scrub.test.ts.
 */

export const MASK = '[Filtered]';

const EMAIL = /[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/gu;
// 9–15 digits (Western, Arabic-Indic or Persian), optional +/00, single spaces or dashes between digits
const PHONE = /(?<![\w.:/-])(?:\+|00)?[0-9٠-٩۰-۹](?:[\s-]?[0-9٠-٩۰-۹]){8,14}(?![\w.:/-])/gu;
const SENSITIVE_KEY = /phone|mobile|whatsapp|e-?mail|password|passwd|secret|token|authorization|cookie|iban|account_number|display_name/i;

export function scrubText(text: string): string {
    return text.replace(EMAIL, MASK).replace(PHONE, MASK);
}

export function scrubValue<T>(value: T, key = '', depth = 0): T {
    if (depth > 8) return value;
    if (key && SENSITIVE_KEY.test(key) && value !== null && value !== undefined && value !== '' && typeof value !== 'boolean') {
        return MASK as T;
    }
    if (typeof value === 'string') return scrubText(value) as T;
    if (Array.isArray(value)) return value.map((item) => scrubValue(item, '', depth + 1)) as T;
    if (value && typeof value === 'object') {
        return Object.fromEntries(Object.entries(value).map(([k, v]) => [k, scrubValue(v, k, depth + 1)])) as T;
    }
    return value;
}
