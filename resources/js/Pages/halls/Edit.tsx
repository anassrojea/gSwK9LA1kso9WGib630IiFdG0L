import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Save, Sparkles } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { HallDesigner, type DesignerState } from '@/components/halls/Designer';
import { HallGenerator } from '@/components/seating/HallTools';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Toaster } from '@/components/ui/sonner';
import AppLayout from '@/layouts/AppLayout';
import { toastFlash } from '@/lib/dashboard/flash';
import type { HallLayout } from '@/lib/hall-layout';
import { useT } from '@/lib/i18n';
import { errorMessage, sendJson } from '@/lib/seating/api';
import type { HallSummary } from '@/types/seating';

interface Props {
    hall: (HallSummary & { layout: HallLayout; events: number; org_id: string | null }) | null;
    urls: { impact: string } | null;
    organizations: { id: string; name: string }[];
    limits: { tables: number; chairs: number };
    errors?: Record<string, string>;
}

/**
 * GET /dashboard/halls/create and /dashboard/halls/{hall}: the hall designer
 * (phase 8B). A new hall starts from the generator; a hall that events use
 * says how many seats a change would unassign before it is saved.
 */
export default function HallEdit({ hall, urls, organizations, limits, errors = {} }: Props) {
    const { t, tc, dir } = useT();
    const [initial, setInitial] = useState<HallLayout | null>(hall?.layout ?? null);
    const [version, setVersion] = useState(0);
    const [name, setName] = useState(hall?.name ?? '');
    const [orgId, setOrgId] = useState<string>(organizations[0]?.id ?? '');
    const [busy, setBusy] = useState(false);
    const [dirty, setDirty] = useState(false);
    const [generatorOpen, setGeneratorOpen] = useState(false);
    const [impact, setImpact] = useState<{ events: number; seats: number } | null>(null);
    const current = useRef<{ state: DesignerState; renames: Record<string, string> } | null>(null);
    const firstError = Object.values(errors)[0];

    useEffect(() => {
        if (firstError) toast.error(firstError);
    }, [firstError]);

    useEffect(() => {
        if (!dirty) return;
        const warn = (e: BeforeUnloadEvent) => e.preventDefault();
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty]);

    const onChange = useCallback(
        (state: DesignerState, renames: Record<string, string>) => {
            const changed = current.current !== null && current.current.state.layout !== state.layout;
            current.current = { state, renames };
            if (changed) setDirty(true);
        },
        [],
    );

    const put = (confirmed: boolean) => {
        if (!hall || !current.current) return;
        setBusy(true);
        router.put(
            `/dashboard/halls/${hall.id}`,
            { name: name.trim(), layout: current.current.state.layout as never, renames: current.current.renames, confirmed },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    toastFlash(page);
                    setDirty(false);
                    setImpact(null);
                    // the saved hall is the new starting point (and table renames are done)
                    const saved = (page.props as unknown as Props).hall?.layout;
                    if (saved) {
                        setInitial(saved);
                        setVersion((v) => v + 1);
                    }
                },
                onFinish: () => setBusy(false),
            },
        );
    };

    const save = async () => {
        const state = current.current?.state;
        if (!state || !name.trim()) return;
        if (!hall) {
            setBusy(true);
            router.post('/dashboard/halls', { name: name.trim(), layout: state.layout as never, org_id: orgId || null }, { onFinish: () => setBusy(false), onSuccess: toastFlash });
            return;
        }
        if (hall.events > 0 && urls) {
            setBusy(true);
            try {
                const result = await sendJson<{ events: number; seats: number }>('post', urls.impact, { layout: state.layout, renames: current.current?.renames ?? {} });
                setBusy(false);
                if (result.seats > 0) {
                    setImpact(result);
                    return;
                }
            } catch (e) {
                setBusy(false);
                toast.error(errorMessage(e, t('seating.errors.save')));
                return;
            }
        }
        put(false);
    };

    const useGenerated = (layout: HallLayout, generatedName: string) => {
        if (initial && !window.confirm(t('halls.designer.generate_replace'))) return;
        if (!name.trim()) setName(generatedName);
        setInitial(layout);
        setVersion((v) => v + 1);
        setDirty(true);
        setGeneratorOpen(false);
    };

    const title = hall ? t('seating.halls.edit_title') : t('seating.halls.create_title');

    return (
        <AppLayout wide>
            <Head title={title} />
            <Toaster />
            <div className="grid gap-4" data-hall-edit>
                <Link href="/dashboard/halls" className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1 text-sm">
                    <ArrowLeft className="size-4 rtl:rotate-180" /> {t('seating.halls.back')}
                </Link>
                <div className="flex flex-wrap items-end gap-3">
                    <h1 className="me-auto text-2xl font-bold">{title}</h1>
                    {initial && (
                        <>
                            <div className="grid min-w-56 gap-1">
                                <Label htmlFor="hall-title" className="text-xs">
                                    {t('seating.hall.name')}
                                </Label>
                                <Input
                                    id="hall-title"
                                    value={name}
                                    maxLength={120}
                                    onChange={(e) => {
                                        setName(e.target.value);
                                        setDirty(true);
                                    }}
                                    className="h-9"
                                />
                            </div>
                            {!hall && organizations.length > 0 && (
                                <div className="grid gap-1">
                                    <Label htmlFor="hall-owner" className="text-xs">
                                        {t('seating.halls.owner')}
                                    </Label>
                                    <NativeSelect id="hall-owner" dir={dir} value={orgId} onChange={(e) => setOrgId(e.target.value)} className="h-9">
                                        {organizations.map((o) => (
                                            <option key={o.id} value={o.id}>
                                                {o.name}
                                            </option>
                                        ))}
                                        <option value="">{t('seating.halls.personal')}</option>
                                    </NativeSelect>
                                </div>
                            )}
                            <Button variant="outline" className="h-9" onClick={() => setGeneratorOpen(true)} data-open-generator>
                                <Sparkles /> {t('halls.designer.generate')}
                            </Button>
                            <Button className="h-9" disabled={busy || !name.trim()} onClick={save} data-save-hall>
                                <Save /> {busy ? t('halls.save.saving') : t('halls.save.button')}
                            </Button>
                        </>
                    )}
                </div>
                {hall && hall.events > 0 && <p className="rounded-lg bg-amber-50 p-2 text-xs text-amber-900">{t('seating.halls.used_note')}</p>}
                {dirty && (
                    <p className="sr-only" role="status">
                        {t('halls.designer.unsaved')}
                    </p>
                )}

                {initial ? (
                    <HallDesigner key={version} initial={initial} onChange={onChange} />
                ) : (
                    <section className="grid max-w-xl gap-3 rounded-xl border bg-white p-4">
                        <h2 className="font-bold">{t('seating.hall.generate')}</h2>
                        <HallGenerator initialName={t('seating.hall.title')} limits={limits} busy={busy} submitLabel={t('seating.hall.use_generated')} onUse={useGenerated} />
                    </section>
                )}
            </div>

            <Dialog open={generatorOpen} onOpenChange={setGeneratorOpen}>
                <DialogContent closeLabel={t('seating.hall.cancel')} className="max-h-[90svh] overflow-y-auto sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>{t('halls.designer.generate')}</DialogTitle>
                        <DialogDescription>{t('halls.designer.generate_replace')}</DialogDescription>
                    </DialogHeader>
                    <HallGenerator initialName={name || t('seating.hall.title')} limits={limits} busy={busy} submitLabel={t('seating.hall.use_generated')} onUse={useGenerated} />
                </DialogContent>
            </Dialog>

            <Dialog open={impact !== null} onOpenChange={(open) => !open && setImpact(null)}>
                <DialogContent closeLabel={t('halls.save.cancel')} data-impact-dialog>
                    <DialogHeader>
                        <DialogTitle>{t('halls.save.impact_title')}</DialogTitle>
                        <DialogDescription>{impact && tc('halls.save.impact', impact.events, { seats: impact.seats })}</DialogDescription>
                    </DialogHeader>
                    <p className="text-muted-foreground text-sm">{t('halls.save.impact_hint')}</p>
                    <div className="flex flex-wrap justify-end gap-2">
                        <Button variant="outline" onClick={() => setImpact(null)}>
                            {t('halls.save.cancel')}
                        </Button>
                        <Button variant="destructive" disabled={busy} onClick={() => put(true)} data-confirm-impact>
                            {t('halls.save.confirm')}
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
