import { usePage } from '@inertiajs/react';

import { toLocalDigits } from '@/lib/digits';
import type { SharedProps } from '@/types';

/**
 * Minimal translator over lang/{locale}/app.php (shared by HandleInertiaRequests).
 * Numbers passed as replacements are written with the locale's digits.
 */
export function useT() {
    const { translations, locale, dir } = usePage<SharedProps>().props;

    const lookup = (key: string): unknown =>
        key.split('.').reduce<unknown>((node, part) => (node as Record<string, unknown> | undefined)?.[part], translations);

    const fill = (text: string, replace: Record<string, string | number>) => {
        for (const [name, v] of Object.entries(replace)) {
            text = text.replaceAll(`:${name}`, typeof v === 'number' ? toLocalDigits(v, locale) : String(v));
        }
        return text;
    };

    const t = (key: string, replace: Record<string, string | number> = {}): string => {
        const value = lookup(key);
        return fill(typeof value === 'string' ? value : key, replace);
    };

    /**
     * Plurals: "key" holds {zero, one, two, few, many, other} (CLDR categories, Arabic has all six).
     */
    const tc = (key: string, count: number, replace: Record<string, string | number> = {}): string => {
        const forms = lookup(key);
        if (typeof forms === 'string') return fill(forms, { count, ...replace });
        const map = (forms ?? {}) as Record<string, string>;
        const category = count === 0 && map.zero ? 'zero' : new Intl.PluralRules(locale).select(count);
        return fill(map[category] ?? map.other ?? key, { count, ...replace });
    };

    /** Digits in the UI's script (Arabic-Indic in Arabic). */
    const n = (value: string | number) => toLocalDigits(value, locale);

    return { t, tc, n, locale, dir };
}
