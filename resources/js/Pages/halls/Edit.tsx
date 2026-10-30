import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Save } from 'lucide-react';
import { useState } from 'react';

import { HallEditor, HallGenerator } from '@/components/seating/HallTools';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Toaster } from '@/components/ui/sonner';
import AppLayout from '@/layouts/AppLayout';
import { toastFlash } from '@/lib/dashboard/flash';
import type { HallLayout } from '@/lib/hall-generator';
import { useT } from '@/lib/i18n';
import type { HallSummary } from '@/types/seating';

interface Props {
    hall: (HallSummary & { layout: HallLayout; events: number; org_id: string | null }) | null;
    organizations: { id: string; name: string }[];
    limits: { tables: number; chairs: number };
    errors?: Record<string, string>;
}

/**
 * GET /dashboard/halls/create and /dashboard/halls/{hall}: generate a hall,
 * then move its tables to where they really stand.
 */
export default function HallEdit({ hall, organizations, limits, errors = {} }: Props) {
    const { t, dir } = useT();
    const [layout, setLayout] = useState<HallLayout | null>(hall?.layout ?? null);
    const [name, setName] = useState(hall?.name ?? '');
    const [orgId, setOrgId] = useState<string>(organizations[0]?.id ?? '');
    const [busy, setBusy] = useState(false);
    const firstError = Object.values(errors)[0];

    const save = (l: HallLayout, n: string) => {
        setBusy(true);
        const done = { onFinish: () => setBusy(false), onSuccess: toastFlash, preserveScroll: true };
        if (hall) router.put(`/dashboard/halls/${hall.id}`, { name: n, layout: l as never }, done);
        else router.post('/dashboard/halls', { name: n, layout: l as never, org_id: orgId || null }, done);
    };

    return (
        <AppLayout wide>
            <Head title={hall ? t('seating.halls.edit_title') : t('seating.halls.create_title')} />
            <Toaster />
            <div className="grid gap-5" data-hall-edit>
                <Link href="/dashboard/halls" className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1 text-sm">
                    <ArrowLeft className="size-4 rtl:rotate-180" /> {t('seating.halls.back')}
                </Link>
                <h1 className="text-2xl font-bold">{hall ? t('seating.halls.edit_title') : t('seating.halls.create_title')}</h1>
                {firstError && <p className="text-destructive rounded-lg bg-red-50 p-3 text-sm">{firstError}</p>}

                <div className="grid items-start gap-5 lg:grid-cols-[1fr_22rem]">
                    <section className="grid gap-3 rounded-xl border bg-white p-4">
                        {layout ? (
                            <>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="hall-title">{t('seating.hall.name')}</Label>
                                    <Input id="hall-title" value={name} maxLength={120} onChange={(e) => setName(e.target.value)} />
                                </div>
                                {hall && hall.events > 0 && <p className="rounded-lg bg-amber-50 p-2 text-xs text-amber-900">{t('seating.halls.used_note')}</p>}
                                <HallEditor layout={layout} onChange={setLayout} />
                                <Button className="h-11" disabled={busy || !name.trim()} onClick={() => save(layout, name.trim())} data-save-hall>
                                    <Save /> {t('seating.hall.save')}
                                </Button>
                            </>
                        ) : (
                            <p className="text-muted-foreground text-sm">{t('seating.halls.empty')}</p>
                        )}
                    </section>

                    <section className="grid gap-3 rounded-xl border bg-white p-4">
                        <h2 className="font-bold">{t('seating.hall.generate')}</h2>
                        {!hall && organizations.length > 0 && (
                            <div className="grid gap-1.5">
                                <Label htmlFor="hall-owner">{t('seating.halls.owner')}</Label>
                                <NativeSelect id="hall-owner" dir={dir} value={orgId} onChange={(e) => setOrgId(e.target.value)}>
                                    {organizations.map((o) => (
                                        <option key={o.id} value={o.id}>
                                            {o.name}
                                        </option>
                                    ))}
                                    <option value="">{t('seating.halls.personal')}</option>
                                </NativeSelect>
                            </div>
                        )}
                        <HallGenerator
                            initialName={hall?.name ?? t('seating.hall.title')}
                            limits={limits}
                            busy={busy}
                            submitLabel={hall ? t('seating.hall.use_generated') : t('seating.hall.save')}
                            onUse={(l, n) => {
                                setName(n);
                                if (hall) setLayout(l);
                                else save(l, n);
                            }}
                        />
                    </section>
                </div>
            </div>
        </AppLayout>
    );
}
