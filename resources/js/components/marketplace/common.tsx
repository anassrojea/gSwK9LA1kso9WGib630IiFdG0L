import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/badge';
import type { InvitationConfig } from '@/lib/config-schema';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

/** Flash messages of a full page visit (redirect after a form) as toasts. */
export function useFlashToasts() {
    const { flash } = usePage<SharedProps>().props;
    useEffect(() => {
        if (flash?.warning) toast.warning(flash.warning);
        else if (flash?.status) toast.success(flash.status);
    }, [flash?.status, flash?.warning]);
}

const tones: Record<string, string> = {
    draft: 'bg-slate-100 text-slate-700',
    pending: 'bg-amber-100 text-amber-900',
    in_review: 'bg-amber-100 text-amber-900',
    new: 'bg-amber-100 text-amber-900',
    in_progress: 'bg-sky-100 text-sky-900',
    published: 'bg-emerald-100 text-emerald-800',
    approved: 'bg-emerald-100 text-emerald-800',
    delivered: 'bg-emerald-100 text-emerald-800',
    paid: 'bg-emerald-100 text-emerald-800',
    rejected: 'bg-red-100 text-red-800',
    suspended: 'bg-red-100 text-red-800',
    hidden: 'bg-red-100 text-red-800',
    cancelled: 'bg-slate-100 text-slate-600',
    unpublished: 'bg-slate-100 text-slate-700',
    superseded: 'bg-slate-100 text-slate-600',
};

/** A status chip; `group` picks the label set in lang/{locale}/marketplace.php (statuses.*). */
export function StatusBadge({ status, group, className }: { status: string | null; group: 'template' | 'revision' | 'designer' | 'payout' | 'vendor' | 'calligraphy'; className?: string }) {
    const { t } = useT();
    if (!status) return null;
    const label = group === 'vendor' ? t(`vendors.statuses.${status}`) : group === 'calligraphy' ? t(`vendors.calligraphy.statuses.${status}`) : t(`marketplace.statuses.${group}.${status}`);

    return (
        <Badge variant="outline" className={cn('border-transparent', tones[status] ?? 'bg-slate-100 text-slate-700', className)} data-status={status}>
            {label}
        </Badge>
    );
}

/** A small poster of a design in its own colours and fonts (as in the wizard's gallery). */
export function DesignPoster({ theme, title, subtitle, cover, className }: { theme: InvitationConfig['theme'] | null | undefined; title: string; subtitle?: string | null; cover?: string | null; className?: string }) {
    const c = (theme?.colors ?? {}) as Record<string, string>;
    if (cover) {
        return <img src={cover} alt="" className={cn('aspect-[4/5] w-full rounded-xl object-cover', className)} loading="lazy" />;
    }
    return (
        <div
            className={cn('relative grid aspect-[4/5] w-full place-items-center overflow-hidden rounded-xl', className)}
            style={{ background: `radial-gradient(circle at 50% 35%, ${c.surface ?? c.bg ?? '#fff'}, ${c.backdrop ?? c.accent ?? '#5a1a2c'})` }}
            aria-hidden
        >
            <div className="mx-4 grid gap-1 rounded-lg px-4 py-6 text-center shadow-sm" style={{ background: c.bg, color: c.ink, borderRadius: theme?.radius ?? 12 }}>
                <span className="text-xl leading-tight break-words" style={{ fontFamily: `'${theme?.fonts?.display ?? 'Amiri'}', serif`, color: c.accent }}>
                    {title}
                </span>
                {subtitle && (
                    <span className="text-[0.7rem]" style={{ fontFamily: `'${theme?.fonts?.label ?? 'Almarai'}', sans-serif`, color: c.soft }}>
                        {subtitle}
                    </span>
                )}
                <span className="mx-auto mt-1 h-px w-10" style={{ background: c.gold }} />
            </div>
        </div>
    );
}

/** One Google Fonts request for the display and label fonts of the designs on a page. */
export function fontsHref(themes: (InvitationConfig['theme'] | null | undefined)[]): string | null {
    const families = new Set<string>();
    themes.forEach((theme) => theme?.fonts && [theme.fonts.display, theme.fonts.label].forEach((f) => f && families.add(f)));
    if (families.size === 0) return null;
    return `https://fonts.googleapis.com/css2?${[...families].map((f) => 'family=' + f.replace(/ /g, '+')).join('&')}&display=swap`;
}

/** Minor units from what a person types in major units ("12.5" SAR → 1250), or null. */
export function toMinor(major: string, exponent: number): number | null {
    const clean = major.replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d))).replace(',', '.').trim();
    if (clean === '') return null;
    const value = Number(clean);
    if (!Number.isFinite(value) || value < 0) return null;
    return Math.round(value * 10 ** exponent);
}

export function toMajor(minor: number | null | undefined, exponent: number): string {
    if (minor === null || minor === undefined) return '';
    return String(minor / 10 ** exponent);
}
