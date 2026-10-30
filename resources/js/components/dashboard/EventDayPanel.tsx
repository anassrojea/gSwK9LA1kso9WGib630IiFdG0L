import { Link } from '@inertiajs/react';
import { Armchair, CalendarClock, ChevronDown, Copy, DoorOpen, FileDown, KeyRound, Link2, MessageCircle, Printer, QrCode, Radio, Save, Trash2 } from 'lucide-react';
import { lazy, Suspense, useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';

import { MealCounts } from '@/components/seating/MealCounts';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ExtraTranslations, useT } from '@/lib/i18n';
import type { Translations } from '@/types';
import { errorMessage, sendJson } from '@/lib/seating/api';
import { cn } from '@/lib/utils';
import type { Meals } from '@/types/seating';

const DesignedQr = lazy(() => import('@/components/dashboard/DesignedQr'));

interface DoorPassRow {
    id: string;
    label: string;
    active: boolean;
    expires_at: string;
    revoked_at: string | null;
    revoked_reason: string | null;
    last_used_at: string | null;
    checkins: number;
    url: string | null;
}

interface DayData {
    translations: Translations;
    meals: Meals;
    live_stream_url: string | null;
    features: { seating: boolean; qr_checkin: boolean; live_day: boolean; personal_links: boolean };
    live: { now: boolean; from: string | null; until: string | null };
    can_update: boolean;
    door_passes: DoorPassRow[];
    urls: { door: string; seating: string; chart_pdf: string; cards_pdf: string; qr_pdf: string; live_stream: string; door_passes: string; upgrade: string };
}

/**
 * The dashboard's event-day tools (phase 7A): door app and door PIN links,
 * seating and printables, meal counts per section, and the live stream link
 * guests see on the live day page. Loads its own data (GET /dashboard/events/{event}/day).
 */
export function EventDayPanel(props: { eventId: string; title: string; timezone: string; guests: { name: string; link: string | null }[] }) {
    const [data, setData] = useState<DayData | null>(null);

    const load = useCallback(() => {
        sendJson<DayData>('get', `/dashboard/events/${props.eventId}/day`)
            .then(setData)
            .catch(() => {});
    }, [props.eventId]);
    useEffect(load, [load]);

    if (!data) return null;
    return (
        <ExtraTranslations.Provider value={data.translations}>
            <Panel {...props} data={data} setData={setData} />
        </ExtraTranslations.Provider>
    );
}

function Panel({ title, timezone, guests, data, setData }: { title: string; timezone: string; guests: { name: string; link: string | null }[]; data: DayData; setData: (d: DayData) => void }) {
    const { t } = useT();
    const [open, setOpen] = useState(false);
    const f = data.features;

    return (
        <section className="grid gap-4 rounded-2xl border bg-white p-4 sm:p-5" aria-labelledby="event-day-title" data-event-day>
            <button type="button" className="flex items-center justify-between gap-2 text-start" onClick={() => setOpen((o) => !o)} aria-expanded={open}>
                <span className="grid gap-0.5">
                    <h2 id="event-day-title" className="flex items-center gap-2 text-lg font-bold">
                        <CalendarClock className="text-primary size-5" /> {t('seating.day.title')}
                    </h2>
                    <span className="text-muted-foreground text-sm">{t('seating.day.subtitle')}</span>
                </span>
                <ChevronDown className={cn('size-5 transition-transform', open && 'rotate-180')} />
            </button>

            <div className="flex flex-wrap gap-2">
                {f.qr_checkin ? (
                    <Button asChild variant="outline" className="h-10">
                        <a href={data.urls.door} data-day-link="door">
                            <DoorOpen /> {t('seating.day.door')}
                        </a>
                    </Button>
                ) : null}
                {data.can_update && (
                    <Button asChild variant="outline" className="h-10">
                        <Link href={data.urls.seating} data-day-link="seating">
                            <Armchair /> {t('seating.day.seating')}
                        </Link>
                    </Button>
                )}
                {f.seating && (
                    <>
                        <Button asChild variant="outline" className="h-10">
                            <a href={data.urls.chart_pdf} target="_blank" rel="noopener" data-day-link="chart">
                                <Printer /> {t('seating.pdf.chart')}
                            </a>
                        </Button>
                        <Button asChild variant="outline" className="h-10">
                            <a href={data.urls.cards_pdf} target="_blank" rel="noopener">
                                <FileDown /> {t('seating.pdf.cards')}
                            </a>
                        </Button>
                    </>
                )}
                {data.can_update && (f.personal_links || f.qr_checkin) && (
                    <Button asChild variant="outline" className="h-10">
                        <a href={data.urls.qr_pdf} target="_blank" rel="noopener" data-day-link="qr">
                            <QrCode /> {t('seating.pdf.qr')}
                        </a>
                    </Button>
                )}
            </div>

            {open && (
                <div className="grid gap-4 lg:grid-cols-2">
                    <MealCounts meals={data.meals} />
                    <div className="grid content-start gap-4">
                        {data.can_update && <LiveStream data={data} timezone={timezone} onSaved={(url) => setData({ ...data, live_stream_url: url })} />}
                        {!f.qr_checkin && <p className="text-muted-foreground rounded-lg bg-zinc-50 p-3 text-sm">{t('seating.day.qr_locked')}</p>}
                    </div>
                    {data.can_update && f.qr_checkin && <DoorPasses data={data} title={title} timezone={timezone} onChange={(rows) => setData({ ...data, door_passes: rows })} />}
                    {data.can_update && (f.personal_links || f.qr_checkin) && guests.some((g) => g.link) && (
                        <Suspense fallback={null}>
                            <DesignedQr guests={guests.filter((g): g is { name: string; link: string } => !!g.link)} title={title} />
                        </Suspense>
                    )}
                </div>
            )}
        </section>
    );
}

function formatTime(iso: string | null, timezone: string, locale: string, withDate = false) {
    if (!iso) return '';
    return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-u-nu-arab' : 'en-GB', { timeZone: timezone, hour: '2-digit', minute: '2-digit', ...(withDate ? { weekday: 'short', day: 'numeric', month: 'short' } : {}) }).format(new Date(iso));
}

function LiveStream({ data, timezone, onSaved }: { data: DayData; timezone: string; onSaved: (url: string | null) => void }) {
    const { t, locale } = useT();
    const [url, setUrl] = useState(data.live_stream_url ?? '');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    if (!data.features.live_day) {
        return (
            <p className="text-muted-foreground flex flex-wrap items-center justify-between gap-2 rounded-lg bg-zinc-50 p-3 text-sm">
                {t('seating.day.live_locked')}
                <Link href={data.urls.upgrade} className="text-primary font-semibold">
                    {t('seating.day.upgrade')}
                </Link>
            </p>
        );
    }

    return (
        <form
            className="grid gap-2 rounded-xl border p-3"
            onSubmit={async (e) => {
                e.preventDefault();
                setBusy(true);
                setError(null);
                try {
                    const res = await sendJson<{ live_stream_url: string | null }>('put', data.urls.live_stream, { url: url.trim() || null });
                    onSaved(res.live_stream_url);
                    toast.success(t('seating.day.stream_saved'));
                } catch (err) {
                    setError(errorMessage(err, t('seating.day.stream_invalid')));
                } finally {
                    setBusy(false);
                }
            }}
            data-live-stream
        >
            <Label htmlFor="live-stream" className="flex items-center gap-2">
                <Radio className="text-primary size-4" /> {t('seating.day.stream')}
            </Label>
            <div className="flex gap-2">
                <Input id="live-stream" type="url" dir="ltr" inputMode="url" placeholder="https://" value={url} onChange={(e) => setUrl(e.target.value)} aria-invalid={!!error} className="h-10" />
                <Button type="submit" className="h-10" disabled={busy}>
                    <Save /> {t('seating.day.save')}
                </Button>
            </div>
            {error && (
                <p className="text-destructive text-sm" role="alert">
                    {error}
                </p>
            )}
            <p className="text-muted-foreground text-xs">{t('seating.day.stream_hint')}</p>
            {data.live.from && (
                <p className="text-muted-foreground text-xs">
                    {data.live.now ? t('seating.day.live_now') : t('seating.day.live_window', { from: formatTime(data.live.from, timezone, locale, true), until: formatTime(data.live.until, timezone, locale, true) })}
                </p>
            )}
        </form>
    );
}

function DoorPasses({ data, title, timezone, onChange }: { data: DayData; title: string; timezone: string; onChange: (rows: DoorPassRow[]) => void }) {
    const { t, tc, locale, sep } = useT();
    const [label, setLabel] = useState('');
    const [busy, setBusy] = useState(false);
    const [created, setCreated] = useState<{ pin: string; url: string; label: string } | null>(null);

    const copy = async (text: string) => {
        try {
            await navigator.clipboard.writeText(text);
            toast.success(t('door.pass.copied'));
        } catch {
            toast.error(text);
        }
    };

    return (
        <section className="grid content-start gap-3 rounded-xl border p-3 lg:col-span-2" aria-labelledby="door-passes-title" data-door-passes>
            <h3 id="door-passes-title" className="flex items-center gap-2 font-bold">
                <KeyRound className="text-primary size-4" /> {t('door.pass.title')}
            </h3>
            <p className="text-muted-foreground text-sm">{t('door.pass.hint')}</p>
            <form
                className="flex flex-wrap gap-2"
                onSubmit={async (e) => {
                    e.preventDefault();
                    if (!label.trim()) return;
                    setBusy(true);
                    try {
                        const res = await sendJson<{ pin: string; url: string; pass: { label: string }; door_passes: DoorPassRow[] }>('post', data.urls.door_passes, { label: label.trim() });
                        onChange(res.door_passes);
                        setCreated({ pin: res.pin, url: res.url, label: res.pass.label });
                        setLabel('');
                    } catch (err) {
                        toast.error(errorMessage(err, t('seating.errors.save')));
                    } finally {
                        setBusy(false);
                    }
                }}
            >
                <Label htmlFor="pass-label" className="sr-only">
                    {t('door.pass.label')}
                </Label>
                <Input id="pass-label" className="h-10 min-w-0 flex-1" maxLength={60} placeholder={t('door.pass.label_placeholder')} value={label} onChange={(e) => setLabel(e.target.value)} />
                <Button type="submit" className="h-10" disabled={busy || !label.trim()} data-create-pass>
                    <KeyRound /> {t('door.pass.create')}
                </Button>
            </form>
            {data.door_passes.length === 0 ? (
                <p className="text-muted-foreground text-sm">{t('door.pass.none')}</p>
            ) : (
                <ul className="grid gap-1.5">
                    {data.door_passes.map((p) => (
                        <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border px-3 py-2 text-sm" data-pass={p.active ? 'active' : 'inactive'}>
                            <span className="grid">
                                <b>
                                    <bdi>{p.label}</bdi>
                                </b>
                                <span className={cn('text-xs', p.active ? 'text-emerald-700' : 'text-muted-foreground')}>
                                    {p.active
                                        ? t('door.pass.active', { time: formatTime(p.expires_at, timezone, locale, true) })
                                        : p.revoked_reason === 'too_many_attempts'
                                          ? t('door.pass.locked_out')
                                          : p.revoked_at
                                            ? t('door.pass.revoked')
                                            : t('door.pass.expired')}
                                    {sep}
                                    {tc('door.pass.uses', p.checkins)}
                                </span>
                            </span>
                            {p.active && (
                                <span className="flex gap-1">
                                    {p.url && (
                                        <Button size="sm" variant="ghost" className="h-8" onClick={() => copy(p.url!)}>
                                            <Link2 /> {t('door.pass.copy_link')}
                                        </Button>
                                    )}
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        className="text-destructive h-8"
                                        onClick={async () => {
                                            try {
                                                const res = await sendJson<{ door_passes: DoorPassRow[] }>('delete', `${data.urls.door_passes}/${p.id}`);
                                                onChange(res.door_passes);
                                            } catch (err) {
                                                toast.error(errorMessage(err, t('seating.errors.save')));
                                            }
                                        }}
                                    >
                                        <Trash2 /> {t('door.pass.revoke')}
                                    </Button>
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <Dialog open={!!created} onOpenChange={(o) => !o && setCreated(null)}>
                <DialogContent closeLabel={t('door.pass.done')}>
                    <DialogHeader>
                        <DialogTitle>{t('door.pass.created')}</DialogTitle>
                        <DialogDescription>{t('door.pass.pin_once')}</DialogDescription>
                    </DialogHeader>
                    {created && (
                        <div className="grid gap-3" data-pass-created>
                            <p className="text-center text-sm">
                                <bdi>{created.label}</bdi>
                            </p>
                            <p className="bg-muted rounded-xl py-3 text-center font-mono text-4xl font-bold tracking-[0.3em]" dir="ltr" data-pin>
                                {created.pin}
                            </p>
                            <div className="grid gap-2 sm:grid-cols-2">
                                <Button variant="outline" onClick={() => copy(created.url)}>
                                    <Copy /> {t('door.pass.copy_link')}
                                </Button>
                                <Button asChild variant="outline">
                                    <a href={`https://wa.me/?text=${encodeURIComponent(t('door.pass.share_text', { event: title, url: created.url }))}`} target="_blank" rel="noopener">
                                        <MessageCircle /> {t('door.pass.share')}
                                    </a>
                                </Button>
                            </div>
                            <Button onClick={() => setCreated(null)}>{t('door.pass.done')}</Button>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </section>
    );
}
