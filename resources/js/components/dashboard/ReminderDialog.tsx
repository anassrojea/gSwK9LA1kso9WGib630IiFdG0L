import axios from 'axios';
import { ArrowRight, BellRing, Check, Loader2, MessageCircle } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { relativeTime, type Stats } from '@/lib/dashboard/guests';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { ReminderGuest } from '@/types/dashboard';

type Audience = 'never' | 'opened' | 'both';

/**
 * "Remind those who didn't reply". Sending stays manual (no WhatsApp API
 * yet): pick a group, then tap each family to open WhatsApp with their own
 * reminder. Each tap marks that family's reminder as sent on the server.
 */
export function ReminderDialog({ open, onOpenChange, stats, url, initialAudience = 'both' }: { open: boolean; onOpenChange: (open: boolean) => void; stats: Stats; url: string; initialAudience?: Audience }) {
    const { t, n } = useT();
    const [audience, setAudience] = useState<Audience>(initialAudience);
    const [guests, setGuests] = useState<ReminderGuest[] | null>(null);
    const [sent, setSent] = useState<Record<string, boolean>>({});
    const [loading, setLoading] = useState(false);

    const counts: Record<Audience, number> = { never: stats.never_opened, opened: stats.opened_not_replied, both: stats.never_opened + stats.opened_not_replied };

    const reset = () => {
        setGuests(null);
        setSent({});
    };

    const load = async () => {
        setLoading(true);
        try {
            const { data } = await axios.post<{ guests: ReminderGuest[] }>(url, { audience }, { headers: { Accept: 'application/json' } });
            setGuests(data.guests);
            setSent({});
        } catch {
            toast.error(t('wizard.save.error'));
        } finally {
            setLoading(false);
        }
    };

    const markSent = (guest: ReminderGuest) => {
        setSent((s) => ({ ...s, [guest.reminder_id]: true }));
        axios.post(`/dashboard/reminders/${guest.reminder_id}/sent`, {}, { headers: { Accept: 'application/json' } }).catch(() => {
            setSent((s) => ({ ...s, [guest.reminder_id]: false }));
        });
    };

    const sentCount = guests ? guests.filter((g) => sent[g.reminder_id]).length : 0;

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                onOpenChange(next);
                if (!next) reset();
            }}
        >
            <DialogContent closeLabel={t('wizard.common.close')} className="max-h-[90svh] grid-rows-[auto_1fr_auto] overflow-hidden" data-reminders>
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <BellRing className="size-5" /> {t('dashboard.remind.title')}
                    </DialogTitle>
                    <DialogDescription>{guests ? t('dashboard.remind.tap_hint') : t('dashboard.remind.subtitle')}</DialogDescription>
                </DialogHeader>

                {guests === null ? (
                    <div className="grid gap-2" role="radiogroup" aria-label={t('dashboard.remind.subtitle')}>
                        {(['opened', 'never', 'both'] as const).map((key) => (
                            <button
                                key={key}
                                type="button"
                                role="radio"
                                aria-checked={audience === key}
                                onClick={() => setAudience(key)}
                                className={cn(
                                    'flex min-h-12 items-center justify-between gap-3 rounded-lg border px-4 py-3 text-start transition-colors',
                                    audience === key ? 'border-primary bg-primary/5 ring-primary/30 ring-2' : 'hover:bg-muted/60',
                                )}
                                data-audience={key}
                            >
                                <span className="font-medium">{t(`dashboard.remind.${key}`)}</span>
                                <span className={cn('rounded-full px-2.5 py-0.5 text-sm font-semibold tabular-nums', key === 'opened' ? 'bg-amber-100 text-amber-950' : 'bg-muted')}>{n(counts[key])}</span>
                            </button>
                        ))}
                    </div>
                ) : guests.length === 0 ? (
                    <p className="text-muted-foreground rounded-lg border border-dashed p-6 text-center text-sm">{t('dashboard.remind.empty')}</p>
                ) : (
                    <div className="grid min-h-0 gap-2">
                        <div className="flex items-center justify-between gap-2 text-sm">
                            <span className="font-semibold" aria-live="polite" data-progress>
                                {t('dashboard.remind.progress', { sent: sentCount, total: guests.length })}
                            </span>
                        </div>
                        <div className="bg-muted h-2 overflow-hidden rounded-full">
                            <div className="h-full rounded-full bg-emerald-500 transition-[width]" style={{ width: `${(sentCount / guests.length) * 100}%` }} />
                        </div>
                        <ul className="-mx-1 grid min-h-0 gap-1.5 overflow-y-auto px-1 py-1">
                            {guests.map((guest) => (
                                <ReminderItem key={guest.reminder_id} guest={guest} sent={!!sent[guest.reminder_id]} onSent={() => markSent(guest)} />
                            ))}
                        </ul>
                    </div>
                )}

                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    {guests === null ? (
                        <Button className="h-11" onClick={load} disabled={loading || counts[audience] === 0} data-start>
                            {loading ? <Loader2 className="animate-spin" /> : <ArrowRight className="rtl:rotate-180" />}
                            {t('dashboard.remind.start')}
                        </Button>
                    ) : (
                        <>
                            <Button variant="outline" className="h-11" onClick={reset}>
                                {t('dashboard.remind.back')}
                            </Button>
                            <Button className="h-11" onClick={() => onOpenChange(false)}>
                                {t('dashboard.remind.done')}
                            </Button>
                        </>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}

function ReminderItem({ guest, sent, onSent }: { guest: ReminderGuest; sent: boolean; onSent: () => void }) {
    const { t, locale, sep } = useT();

    return (
        <li>
            <a
                href={guest.whatsapp_url}
                target="_blank"
                rel="noopener"
                onClick={onSent}
                className={cn(
                    'flex min-h-14 items-center gap-3 rounded-lg border px-3 py-2 transition-colors',
                    sent ? 'border-emerald-200 bg-emerald-50 dark:bg-emerald-950/30' : 'hover:bg-muted/60',
                )}
                data-reminder={guest.household_id}
                data-sent={sent || undefined}
            >
                <span
                    className={cn('flex size-9 shrink-0 items-center justify-center rounded-full', sent ? 'bg-emerald-600 text-white' : 'bg-[#1f8f4e]/10 text-[#1f8f4e]')}
                    aria-hidden
                >
                    {sent ? <Check className="size-5" /> : <MessageCircle className="size-5" />}
                </span>
                <span className="grid min-w-0 flex-1 gap-0.5">
                    <span className="truncate font-medium">
                        <bdi>{guest.name}</bdi>
                    </span>
                    <span className="text-muted-foreground truncate text-xs">
                        {guest.phone ? <bdi dir="ltr">{guest.phone}</bdi> : t('dashboard.remind.no_phone')}
                        {guest.last_reminded_at && <>{sep}{t('dashboard.remind.reminded', { time: relativeTime(guest.last_reminded_at, locale) })}</>}
                    </span>
                </span>
                <span className={cn('shrink-0 text-sm font-semibold', sent ? 'text-emerald-700' : 'text-[#1f8f4e]')}>{sent ? t('dashboard.remind.sent') : t('dashboard.remind.send')}</span>
            </a>
        </li>
    );
}
