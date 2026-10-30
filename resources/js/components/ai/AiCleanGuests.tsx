import { router } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, Loader2, Sparkles, Trash2, UserPlus } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useAiGeneration } from '@/hooks/useAiGeneration';
import { importRows, type Family } from '@/lib/ai';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * "Clean with AI" for a pasted guest list (phase 7B), in the wizard's guests
 * step and the dashboard's "Add guests": the families come back for review in
 * an editable table, then go through the usual import endpoint (which checks
 * the guest limit and every row again). Nothing is added before "Add these families".
 */
export function AiCleanGuests({ text, cleanUrl, importUrl, signedIn, onDone }: { text: string; cleanUrl: string; importUrl: string; signedIn: boolean; onDone?: () => void }) {
    const { t, tc, n, sep } = useT();
    const ai = useAiGeneration<{ families: Family[] }>();
    const [rows, setRows] = useState<Family[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    const clean = async () => {
        setRows(null);
        setError(null);
        await ai.start(cleanUrl, { text });
    };
    const families = rows ?? ai.result?.families ?? null;
    const update = (i: number, change: Partial<Family>) => setRows((current) => (current ?? families ?? []).map((row, j) => (j === i ? { ...row, ...change } : row)));
    const remove = (i: number) => setRows((current) => (current ?? families ?? []).filter((_, j) => j !== i));

    const save = () => {
        if (!families) return;
        setSaving(true);
        router.post(importUrl, { guests: importRows(families) }, {
            preserveScroll: true,
            onSuccess: () => {
                setRows(null);
                ai.reset();
                onDone?.();
            },
            onError: (errors) => setError(Object.values(errors)[0] ?? t('wizard.save.error')),
            onFinish: () => setSaving(false),
        });
    };

    if (!families) {
        return (
            <div className="grid gap-2 rounded-lg border border-dashed p-3" data-ai-clean>
                <p className="text-muted-foreground text-xs">{signedIn ? t('ai.clean.hint') : t('ai.sign_in')}</p>
                {ai.error && (
                    <p className="text-destructive text-sm" role="alert">
                        {ai.error.message}
                    </p>
                )}
                <Button type="button" variant="secondary" className="h-11 w-fit" onClick={clean} disabled={!signedIn || ai.busy || !text.trim()} data-ai-clean-button>
                    {ai.busy ? <Loader2 className="animate-spin" /> : <Sparkles />}
                    {ai.busy ? t('ai.working') : t('ai.clean.button')}
                </Button>
            </div>
        );
    }

    const seats = families.reduce((sum, f) => sum + f.seats, 0);

    return (
        <div className="grid gap-3" data-ai-clean-preview>
            <div className="grid gap-1">
                <h3 className="flex items-center gap-2 font-semibold">
                    <Sparkles className="text-primary size-4" /> {t('ai.clean.review')}
                </h3>
                <p className="text-sm font-medium text-emerald-700">
                    {tc('ai.clean.found', families.length)}
                    {sep}
                    {tc('wizard.guests.seat_count', seats)}
                </p>
                <p className="text-muted-foreground text-xs">{t('ai.review_note')}</p>
            </div>
            {/* inside the paste form: Enter must not submit it */}
            <div className="max-h-80 overflow-auto rounded-lg border" onKeyDown={(e) => e.key === 'Enter' && e.preventDefault()}>
                <table className="w-full text-sm">
                    <thead className="bg-muted/60 text-muted-foreground sticky top-0 z-10">
                        <tr>
                            <th className="px-2 py-1.5 text-start font-medium">{t('dashboard.csv.columns.name')}</th>
                            <th className="w-20 px-2 py-1.5 text-start font-medium">{t('dashboard.csv.columns.seats')}</th>
                            <th className="px-2 py-1.5 text-start font-medium">{t('dashboard.csv.columns.phone')}</th>
                            <th className="w-10" />
                        </tr>
                    </thead>
                    <tbody>
                        {families.map((family, i) => (
                            <tr key={i} className={cn('border-t align-top', family.warnings.length > 0 && 'bg-amber-50')} data-ai-row>
                                <td className="p-1.5">
                                    <Input className="h-9" dir="auto" value={family.name} maxLength={120} onChange={(e) => update(i, { name: e.target.value })} aria-label={t('dashboard.csv.columns.name')} />
                                    {family.notes && <span className="text-muted-foreground mt-0.5 block text-xs" dir="auto">{family.notes}</span>}
                                    {family.warnings.includes('phone') && (
                                        <span className="mt-0.5 flex items-center gap-1 text-xs text-amber-700">
                                            <AlertTriangle className="size-3 shrink-0" /> {t('ai.clean.phone_dropped')}
                                        </span>
                                    )}
                                </td>
                                <td className="p-1.5">
                                    <Input className="h-9" type="number" inputMode="numeric" dir="ltr" min={1} max={100} value={family.seats} onChange={(e) => update(i, { seats: Math.max(1, Math.min(100, Number(e.target.value) || 1)) })} aria-label={t('dashboard.csv.columns.seats')} />
                                </td>
                                <td className="p-1.5">
                                    <Input className="h-9" dir="ltr" inputMode="tel" value={family.phone ?? ''} maxLength={32} onChange={(e) => update(i, { phone: e.target.value || null })} aria-label={t('dashboard.csv.columns.phone')} />
                                </td>
                                <td className="p-1.5">
                                    <Button type="button" variant="ghost" size="icon" className="text-destructive size-9" onClick={() => remove(i)} aria-label={t('ai.clean.remove')}>
                                        <Trash2 className="size-4" />
                                    </Button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {error && (
                <p className="text-destructive text-sm" role="alert">
                    {error}
                </p>
            )}
            <div className="grid gap-2 sm:grid-cols-[1fr_auto]">
                <Button type="button" className="h-12 text-base" onClick={save} disabled={saving || families.length === 0} data-ai-import>
                    {saving ? <Loader2 className="animate-spin" /> : <UserPlus />} {t('ai.clean.import')} ({n(families.length)})
                </Button>
                <Button type="button" variant="ghost" className="h-12" onClick={() => { setRows(null); ai.reset(); }}>
                    <ArrowLeft className="rtl:rotate-180" /> {t('ai.clean.back')}
                </Button>
            </div>
        </div>
    );
}
