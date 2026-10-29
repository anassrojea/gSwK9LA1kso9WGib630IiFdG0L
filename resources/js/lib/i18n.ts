import { usePage } from '@inertiajs/react';

import type { SharedProps } from '@/types';

/**
 * Minimal translator over lang/{locale}/app.php (shared by HandleInertiaRequests).
 * Swap for react-i18next when the wizard needs plurals and namespaces.
 */
export function useT() {
    const { translations, locale, dir } = usePage<SharedProps>().props;

    const t = (key: string, replace: Record<string, string | number> = {}): string => {
        const value = key.split('.').reduce<unknown>((node, part) => (node as Record<string, unknown> | undefined)?.[part], translations);
        let text = typeof value === 'string' ? value : key;
        for (const [name, v] of Object.entries(replace)) {
            text = text.replace(`:${name}`, String(v));
        }
        return text;
    };

    return { t, locale, dir };
}
