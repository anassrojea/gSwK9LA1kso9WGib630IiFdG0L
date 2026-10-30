import { Link } from '@inertiajs/react';
import { Building2, Check, LayoutGrid, Move, Save, X } from 'lucide-react';
import { useState } from 'react';

import { HallEditor, HallGenerator } from '@/components/seating/HallTools';
import { Button } from '@/components/ui/button';
import type { HallLayout } from '@/lib/hall-generator';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { EventHall, HallSummary } from '@/types/seating';

/**
 * The planner's "Hall" tab: the event's hall, a saved one (the venue's), or a
 * generated one; tables can be moved by hand and saved.
 */
export function HallSetup({
    hall,
    halls,
    busy,
    limits,
    hallsUrl,
    hasSeats,
    onUseSaved,
    onSaveLayout,
}: {
    hall: EventHall | null;
    halls: HallSummary[];
    busy: boolean;
    limits?: { tables: number; chairs: number };
    hallsUrl?: string;
    hasSeats: boolean;
    onUseSaved: (id: string) => void;
    onSaveLayout: (layout: HallLayout, name: string) => Promise<boolean>;
}) {
    const { t, tc, sep } = useT();
    const [draft, setDraft] = useState<HallLayout | null>(null);

    const confirmSwitch = () => !hasSeats || window.confirm(t('seating.hall.replace_confirm'));

    return (
        <div className="grid gap-5 lg:grid-cols-[1fr_22rem]" data-hall-setup>
            <div className="grid content-start gap-4">
                {hall ? (
                    <section className="grid gap-3 rounded-xl border bg-white p-4" aria-labelledby="current-hall">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div className="grid gap-0.5">
                                <span className="text-muted-foreground text-xs">{t('seating.hall.current')}</span>
                                <h2 id="current-hall" className="text-lg font-bold">
                                    <bdi>{hall.name}</bdi>
                                </h2>
                                <span className="text-muted-foreground text-sm">
                                    {tc('seating.hall.tables', hall.layout.tables.length)}
                                    {sep}
                                    {t('seating.hall.seats', { count: hall.layout.tables.reduce((s, x) => s + x.seats, 0) })}
                                </span>
                            </div>
                            {draft ? (
                                <div className="flex gap-2">
                                    <Button variant="outline" onClick={() => setDraft(null)} disabled={busy}>
                                        <X /> {t('seating.hall.cancel')}
                                    </Button>
                                    <Button
                                        onClick={async () => {
                                            if (await onSaveLayout(draft, hall.name)) setDraft(null);
                                        }}
                                        disabled={busy}
                                        data-save-hall
                                    >
                                        <Save /> {t('seating.hall.save')}
                                    </Button>
                                </div>
                            ) : (
                                <Button variant="outline" onClick={() => setDraft(hall.layout)} data-edit-hall>
                                    <Move /> {t('seating.hall.edit')}
                                </Button>
                            )}
                        </div>
                        {hall.shared && <p className="rounded-lg bg-amber-50 p-2 text-xs text-amber-900">{t('seating.hall.shared')}</p>}
                        {draft && <HallEditor layout={draft} onChange={setDraft} />}
                    </section>
                ) : (
                    <p className="rounded-xl border border-dashed bg-white p-4 text-sm">{t('seating.hall.none')}</p>
                )}

                <section className="grid gap-3 rounded-xl border bg-white p-4" aria-labelledby="saved-halls">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 id="saved-halls" className="flex items-center gap-2 font-bold">
                            <Building2 className="size-4" /> {t('seating.hall.saved_halls')}
                        </h2>
                        {hallsUrl && (
                            <Link href={hallsUrl} className="text-primary text-sm underline-offset-2 hover:underline">
                                {t('seating.hall.manage')}
                            </Link>
                        )}
                    </div>
                    {halls.length === 0 ? (
                        <p className="text-muted-foreground text-sm">{t('seating.hall.no_saved')}</p>
                    ) : (
                        <ul className="grid gap-2 sm:grid-cols-2">
                            {halls.map((h) => {
                                const current = hall?.id === h.id;
                                return (
                                    <li key={h.id} className={cn('flex items-center justify-between gap-2 rounded-lg border p-3', current && 'border-primary bg-primary/5')} data-saved-hall={h.id}>
                                        <span className="grid min-w-0 gap-0.5">
                                            <span className="truncate font-semibold">
                                                <bdi>{h.name}</bdi>
                                            </span>
                                            <span className="text-muted-foreground text-xs">
                                                {h.org && (
                                                    <>
                                                        <bdi>{h.org}</bdi>
                                                        {sep}
                                                    </>
                                                )}
                                                {tc('seating.hall.tables', h.tables)}
                                                {sep}
                                                {t('seating.hall.seats', { count: h.seats })}
                                                {h.split && `${sep}${t('seating.hall.split')}`}
                                            </span>
                                        </span>
                                        {current ? (
                                            <span className="text-primary inline-flex shrink-0 items-center gap-1 text-xs font-semibold">
                                                <Check className="size-4" /> {t('seating.hall.in_use')}
                                            </span>
                                        ) : (
                                            <Button size="sm" variant="outline" disabled={busy} onClick={() => confirmSwitch() && onUseSaved(h.id)}>
                                                {t('seating.hall.use')}
                                            </Button>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </section>
            </div>

            <section className="grid content-start gap-3 rounded-xl border bg-white p-4" aria-labelledby="generate-hall">
                <h2 id="generate-hall" className="flex items-center gap-2 font-bold">
                    <LayoutGrid className="size-4" /> {t('seating.hall.generate')}
                </h2>
                <HallGenerator initialName={hall?.name ?? t('seating.hall.title')} limits={limits} busy={busy} onUse={(layout, name) => confirmSwitch() && onSaveLayout(layout, name)} />
            </section>
        </div>
    );
}
