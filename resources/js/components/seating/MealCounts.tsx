import { UtensilsCrossed } from 'lucide-react';

import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Meals } from '@/types/seating';

/**
 * Meal count for the kitchen (plan section 14.8): adults and kids per section
 * from confirmed replies, plus the meal choices guests picked.
 */
export function MealCounts({ meals, className }: { meals: Meals; className?: string }) {
    const { t, n } = useT();
    const rows = (['men', 'women', 'none'] as const).filter((k) => meals.sections[k].people > 0);

    return (
        <section className={cn('grid gap-3 rounded-xl border bg-white p-4', className)} aria-labelledby="meals-title" data-meals>
            <div className="flex items-center gap-2">
                <UtensilsCrossed className="text-primary size-4" />
                <h2 id="meals-title" className="font-bold">
                    {t('seating.meals.title')}
                </h2>
                <span className="text-muted-foreground text-xs">{t('seating.meals.subtitle')}</span>
            </div>
            {meals.total.people === 0 ? (
                <p className="text-muted-foreground text-sm">{t('seating.meals.none')}</p>
            ) : (
                <>
                    <table className="w-full text-sm tabular-nums">
                        <thead className="text-muted-foreground text-xs">
                            <tr>
                                <th className="py-1 text-start font-medium">{t('seating.section.label')}</th>
                                <th className="py-1 text-center font-medium">{t('seating.meals.adults')}</th>
                                <th className="py-1 text-center font-medium">{t('seating.meals.kids')}</th>
                                <th className="py-1 text-end font-medium">{t('seating.meals.people')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((k) => (
                                <tr key={k} className="border-t" data-meal-section={k}>
                                    <td className="py-1.5">{t(`seating.section.${k}`)}</td>
                                    <td className="py-1.5 text-center">{n(meals.sections[k].adults)}</td>
                                    <td className="py-1.5 text-center">{n(meals.sections[k].kids)}</td>
                                    <td className="py-1.5 text-end font-semibold">{n(meals.sections[k].people)}</td>
                                </tr>
                            ))}
                            <tr className="border-t-2 font-bold">
                                <td className="py-1.5">{t('seating.meals.total')}</td>
                                <td className="py-1.5 text-center">{n(meals.total.adults)}</td>
                                <td className="py-1.5 text-center">{n(meals.total.kids)}</td>
                                <td className="py-1.5 text-end">{n(meals.total.people)}</td>
                            </tr>
                        </tbody>
                    </table>
                    {meals.meals.length > 0 && (
                        <div className="grid gap-1.5">
                            <h3 className="text-muted-foreground text-xs font-semibold">{t('seating.meals.choices')}</h3>
                            <ul className="flex flex-wrap gap-1.5">
                                {meals.meals.map((m) => (
                                    <li key={m.meal} className="bg-muted rounded-full px-2.5 py-1 text-xs">
                                        <bdi>{m.meal}</bdi>: <span className="font-semibold">{n(m.people)}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </>
            )}
        </section>
    );
}
