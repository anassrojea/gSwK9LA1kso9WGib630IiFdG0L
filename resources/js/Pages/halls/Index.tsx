import { Head, Link, router } from '@inertiajs/react';
import { Building2, Pencil, Plus, Trash2 } from 'lucide-react';
import { useEffect } from 'react';
import { toast } from 'sonner';

import { HallSvg } from '@/components/seating/HallSvg';
import { Button } from '@/components/ui/button';
import { Toaster } from '@/components/ui/sonner';
import AppLayout from '@/layouts/AppLayout';
import { toastFlash } from '@/lib/dashboard/flash';
import type { HallLayout } from '@/lib/hall-generator';
import { useT } from '@/lib/i18n';
import type { HallSummary } from '@/types/seating';

interface Props {
    halls: (HallSummary & { events: number; can_edit: boolean; layout: HallLayout; updated_at: string | null })[];
    organizations: { id: string; name: string }[];
    errors?: { hall?: string };
}

/**
 * GET /dashboard/halls: the venue's hall layouts (plan section 4.2), reused by every event in the hall.
 */
export default function HallsIndex({ halls, errors }: Props) {
    const { t, tc, sep } = useT();

    useEffect(() => {
        if (errors?.hall) toast.error(errors.hall);
    }, [errors?.hall]);

    return (
        <AppLayout>
            <Head title={t('seating.halls.title')} />
            <Toaster />
            <div className="grid gap-5" data-halls>
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="grid gap-1">
                        <h1 className="flex items-center gap-2 text-2xl font-bold">
                            <Building2 className="text-primary size-6" /> {t('seating.halls.title')}
                        </h1>
                        <p className="text-muted-foreground text-sm">{t('seating.halls.subtitle')}</p>
                    </div>
                    <Button asChild className="h-11">
                        <Link href="/dashboard/halls/create">
                            <Plus /> {t('seating.halls.new')}
                        </Link>
                    </Button>
                </div>

                {halls.length === 0 ? (
                    <p className="rounded-xl border border-dashed bg-white p-6 text-center text-sm">{t('seating.halls.empty')}</p>
                ) : (
                    <ul className="grid gap-4 sm:grid-cols-2">
                        {halls.map((h) => (
                            <li key={h.id} className="grid gap-3 rounded-xl border bg-white p-4" data-hall={h.id}>
                                <div className="bg-muted/30 rounded-lg border p-1">
                                    <HallSvg layout={h.layout} mode="view" />
                                </div>
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div className="grid min-w-0 gap-0.5">
                                        <h2 className="truncate font-bold">
                                            <bdi>{h.name}</bdi>
                                        </h2>
                                        <span className="text-muted-foreground text-xs">
                                            {h.org ? <bdi>{h.org}</bdi> : t('seating.halls.personal')}
                                            {sep}
                                            {tc('seating.hall.tables', h.tables)}
                                            {sep}
                                            {t('seating.hall.seats', { count: h.seats })}
                                        </span>
                                        <span className="text-muted-foreground text-xs">{tc('seating.halls.events', h.events)}</span>
                                    </div>
                                    {h.can_edit && (
                                        <div className="flex gap-1">
                                            <Button asChild size="sm" variant="outline">
                                                <Link href={`/dashboard/halls/${h.id}`}>
                                                    <Pencil /> {t('seating.halls.edit')}
                                                </Link>
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                className="text-destructive"
                                                disabled={h.events > 0}
                                                title={h.events > 0 ? t('seating.halls.in_use') : undefined}
                                                onClick={() => window.confirm(t('seating.halls.delete_confirm')) && router.delete(`/dashboard/halls/${h.id}`, { onSuccess: toastFlash })}
                                            >
                                                <Trash2 /> {t('seating.halls.delete')}
                                            </Button>
                                        </div>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
