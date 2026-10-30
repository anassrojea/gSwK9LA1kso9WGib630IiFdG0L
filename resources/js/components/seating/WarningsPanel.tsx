import { CircleCheck, TriangleAlert, Wrench } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { iso, tableNumber, type Warning } from '@/lib/seating/plan';
import { cn } from '@/lib/utils';

/**
 * Over capacity, split families, section mix-ups and people without a seat
 * (plan section 7.3). Each one points at its table or family.
 */
export function WarningsPanel({
    warnings,
    unseated,
    invalid,
    onFix,
    onTable,
    onFamily,
}: {
    warnings: Warning[];
    unseated: number;
    invalid: number;
    onFix: () => void;
    onTable: (table: string) => void;
    onFamily: (household: string) => void;
}) {
    const { t, tc, n } = useT();
    const [open, setOpen] = useState(false);
    // "unseated" is summed up in one line; the others are listed
    const listed = warnings.filter((w) => w.type !== 'unseated');
    const shown = open ? listed : listed.slice(0, 4);

    const text = (w: Warning) => {
        switch (w.type) {
            case 'over_capacity':
                return t('seating.warnings.over_capacity', { table: n(tableNumber(w.table)), count: w.count, seats: w.seats });
            case 'split':
                return t('seating.warnings.split', { name: iso(w.name), tables: w.tables.map((x) => n(tableNumber(x))).join('، ') });
            case 'section_mismatch':
                return t('seating.warnings.section_mismatch', { name: iso(w.name), table: n(tableNumber(w.table)), section: t(`seating.section.${w.section}`) });
            case 'stale':
                return t('seating.warnings.stale', { name: iso(w.name ?? '…') });
            case 'unseated':
                return t('seating.warnings.unseated', { name: iso(w.name), count: w.count });
        }
    };

    if (listed.length === 0 && unseated === 0) {
        return (
            <p className="flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900" data-warnings="none">
                <CircleCheck className="size-4 shrink-0" /> {t('seating.warnings.none')}
            </p>
        );
    }

    return (
        <section className="grid gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-amber-950" aria-labelledby="warnings-title" data-warnings={listed.length + (unseated > 0 ? 1 : 0)}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 id="warnings-title" className="flex items-center gap-2 text-sm font-bold">
                    <TriangleAlert className="size-4" /> {t('seating.warnings.title')}
                </h2>
                {invalid > 0 && (
                    <Button size="sm" variant="outline" className="h-8 border-amber-300 bg-white" onClick={onFix} data-fix>
                        <Wrench /> {t('seating.warnings.fix')}
                    </Button>
                )}
            </div>
            <ul className="grid gap-1 text-sm">
                {unseated > 0 && <li className="font-medium">{tc('seating.warnings.unseated_total', unseated)}</li>}
                {shown.map((w, i) => (
                    <li key={i}>
                        <button
                            type="button"
                            className={cn('text-start underline-offset-2 hover:underline', (w.type === 'over_capacity' || w.type === 'stale') && 'text-red-800')}
                            onClick={() => ('table' in w ? onTable(w.table) : onFamily(w.household))}
                            data-warning={w.type}
                        >
                            {text(w)}
                        </button>
                    </li>
                ))}
            </ul>
            {listed.length > 4 && (
                <button type="button" className="w-fit text-xs font-semibold underline" onClick={() => setOpen((o) => !o)}>
                    {open ? '−' : t('seating.warnings.more', { count: listed.length - 4 })}
                </button>
            )}
        </section>
    );
}
