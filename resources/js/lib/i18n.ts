import { usePage } from '@inertiajs/react';
import { createContext, useContext } from 'react';

import { toLocalDigits } from '@/lib/digits';
import type { SharedProps, Translations } from '@/types';

/**
 * Extra dictionaries for a part of a page whose strings are not in the shared
 * lang/{locale}/app.php (e.g. the dashboard's event-day panel loads seating.* and door.*).
 */
export const ExtraTranslations = createContext<Translations | null>(null);

/**
 * Minimal translator over lang/{locale}/app.php (shared by HandleInertiaRequests).
 * Numbers passed as replacements are written with the locale's digits.
 */
export function useT() {
    const { translations, locale, dir } = usePage<SharedProps>().props;
    const extra = useContext(ExtraTranslations);

    const find = (dict: unknown, key: string): unknown => key.split('.').reduce<unknown>((node, part) => (node as Record<string, unknown> | undefined)?.[part], dict);
    const lookup = (key: string): unknown => find(translations, key) ?? (extra ? find(extra, key) : undefined);

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

    /** Separator between short facts. In Arabic a middle dot reads like the digit zero (٠), so use a comma. */
    const sep = locale === 'ar' ? '، ' : ' · ';

    return { t, tc, n, sep, locale, dir };
}
