import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, Baby, Camera, CameraOff, CheckCircle2, CloudOff, Gem, Minus, Plus, QrCode, RefreshCw, Search, Users, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

import { LocaleSwitch } from '@/components/LocaleSwitch';
import { Scanner } from '@/components/door/Scanner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Toaster } from '@/components/ui/sonner';
import { useEventChannel } from '@/hooks/useEventChannel';
import {
    addLocalCheckin,
    applySync,
    parseToken,
    replaceGuest,
    searchGuests,
    store,
    suggestedPeople,
    uuid,
    warnings as computeWarnings,
    type DoorGuest,
    type DoorStats,
    type DoorWarning,
    type QueuedScan,
    type SyncResult,
} from '@/lib/door/desk';
import { useT } from '@/lib/i18n';
import { isOffline, sendJson, statusOf } from '@/lib/seating/api';
import { cn } from '@/lib/utils';

interface Props {
    event: { id: string; title: string; venue_name: string | null; timezone: string };
    locked: boolean;
    actor: { name: string; pass: boolean; live: boolean };
    guests: DoorGuest[];
    stats: DoorStats | null;
    poll_seconds: number;
    urls: { guests: string; stats: string; checkin: string; sync: string; dashboard: string | null; upgrade: string | null };
}

type Live = 'live' | 'polling' | 'offline';
type CheckinResponse = { status: 'checked_in' | 'needs_confirmation' | 'already_recorded'; guest: DoorGuest; warnings: DoorWarning[]; stats: DoorStats };

/** Subscribes to private-event.{id} (signed-in staff only; PIN links poll instead). */
function LiveChannel({ eventId, onChange, onState }: { eventId: string; onChange: () => void; onState: (s: 'connecting' | 'live' | 'offline') => void }) {
    const state = useEventChannel(eventId, (name) => name === '.guest.checked_in' && onChange(), onChange);
    useEffect(() => onState(state), [state, onState]);
    return null;
}

/**
 * GET /door/{event}: the door app (plan sections 9 and 14.8). Scan the guest's
 * QR code (or search by name), see their seats, table and section, confirm
 * odd entries, and keep counting when the network drops.
 */
export default function Door(props: Props) {
    const { t } = useT();
    if (props.locked) {
        return (
            <Shell title={props.event.title}>
                <section className="grid gap-4 rounded-2xl border bg-white p-6 text-center" data-door-locked>
                    <QrCode className="text-primary mx-auto size-10" />
                    <h1 className="text-lg font-bold">{t('door.locked.title')}</h1>
                    <p className="text-muted-foreground text-sm">{t('door.locked.text')}</p>
                    {props.urls.upgrade ? (
                        <Button asChild className="mx-auto h-11">
                            <Link href={props.urls.upgrade}>
                                <Gem /> {t('door.locked.cta')}
                            </Link>
                        </Button>
                    ) : (
                        <p className="text-sm font-medium">{t('door.locked.ask')}</p>
                    )}
                </section>
            </Shell>
        );
    }
    return <DoorDesk {...props} />;
}

function Shell({ title, children, right }: { title: string; children: React.ReactNode; right?: React.ReactNode }) {
    const { t } = useT();
    return (
        <div className="bg-muted/40 min-h-svh">
            <Head title={`${t('door.title')} · ${title}`} />
            <Toaster />
            <header className="bg-background sticky top-0 z-20 border-b">
                <div className="mx-auto flex h-14 max-w-lg items-center justify-between gap-2 px-4">
                    <div className="grid min-w-0">
                        <span className="text-primary text-xs font-bold">{t('door.title')}</span>
                        <span className="truncate text-sm font-semibold">
                            <bdi>{title}</bdi>
                        </span>
                    </div>
                    <div className="flex items-center gap-1">
                        {right}
                        <LocaleSwitch />
                    </div>
                </div>
            </header>
            <main className="mx-auto grid max-w-lg gap-4 px-4 py-4">{children}</main>
        </div>
    );
}

function DoorDesk(props: Props) {
    const { t, tc, n, sep } = useT();
    const { event, urls } = props;
    const queueKey = `daawa-door:${event.id}:queue`;
    const guestsKey = `daawa-door:${event.id}:guests`;

    const [guests, setGuests] = useState<DoorGuest[]>(props.guests);
    const [stats, setStats] = useState<DoorStats | null>(props.stats);
    const [queue, setQueue] = useState<QueuedScan[]>(() => store.get<QueuedScan[]>(queueKey, []));
    const [conflicts, setConflicts] = useState<SyncResult[]>([]);
    const [live, setLive] = useState<Live>('polling');
    const onLiveState = useCallback((s: 'connecting' | 'live' | 'offline') => setLive(s === 'live' ? 'live' : 'polling'), []);
    const [online, setOnline] = useState(true);
    const [scanning, setScanning] = useState(false);
    const [scanError, setScanError] = useState<string | null>(null);
    const [query, setQuery] = useState('');
    const [current, setCurrent] = useState<{ token: string; people: number; serverWarnings?: DoorWarning[] } | null>(null);
    const [result, setResult] = useState<{ guest: DoorGuest; people: number; offline?: boolean; already?: boolean } | null>(null);
    const [busy, setBusy] = useState(false);
    const lastCode = useRef<{ text: string; at: number }>({ text: '', at: 0 });

    const byToken = useMemo(() => new Map(guests.map((g) => [g.token, g])), [guests]);
    const guest = current ? byToken.get(current.token) : undefined;
    const localWarnings = useMemo(() => (guest && current ? computeWarnings(guest, current.people) : []), [guest, current]);
    const shownWarnings = current?.serverWarnings ?? localWarnings;
    const results = useMemo(() => searchGuests(guests, query), [guests, query]);

    useEffect(() => store.set(queueKey, queue), [queue, queueKey]);
    useEffect(() => store.set(guestsKey, guests), [guests, guestsKey]);

    const refresh = useCallback(async () => {
        try {
            const data = await sendJson<{ guests: DoorGuest[]; stats: DoorStats }>('get', urls.guests);
            setGuests(data.guests);
            setStats(data.stats);
            setOnline(true);
        } catch (e) {
            if (isOffline(e)) setOnline(false);
        }
    }, [urls.guests]);

    const sync = useCallback(async () => {
        const pending = store.get<QueuedScan[]>(queueKey, []);
        if (pending.length === 0) return;
        try {
            const data = await sendJson<{ results: SyncResult[]; stats: DoorStats }>('post', urls.sync, { scans: pending.slice(0, 200) });
            const out = applySync(pending, data.results);
            setQueue(out.queue);
            if (out.conflicts.length) setConflicts((c) => [...c, ...out.conflicts]);
            if (out.synced) toast.success(tc('door.queue.synced', out.synced));
            setStats(data.stats);
            setOnline(true);
            refresh();
        } catch (e) {
            if (isOffline(e)) setOnline(false);
        }
    }, [queueKey, urls.sync, tc, refresh]);

    // Reverb when it runs; otherwise (and for PIN links) poll every poll_seconds
    useEffect(() => {
        const every = live === 'live' ? 60 : props.poll_seconds;
        const id = window.setInterval(() => {
            refresh();
            sync();
        }, every * 1000);
        return () => window.clearInterval(id);
    }, [live, props.poll_seconds, refresh, sync]);

    useEffect(() => {
        const up = () => {
            setOnline(true);
            sync();
        };
        const down = () => setOnline(false);
        window.addEventListener('online', up);
        window.addEventListener('offline', down);
        if (store.get<QueuedScan[]>(queueKey, []).length) sync();
        return () => {
            window.removeEventListener('online', up);
            window.removeEventListener('offline', down);
        };
    }, [sync, queueKey]);

    const open = (token: string) => {
        const g = byToken.get(token);
        if (!g) return false;
        setResult(null);
        setCurrent({ token, people: suggestedPeople(g) });
        return true;
    };

    const onCode = (text: string) => {
        const now = Date.now();
        if (text === lastCode.current.text && now - lastCode.current.at < 3000) return;
        lastCode.current = { text, at: now };
        if (current) return;
        const token = parseToken(text);
        if (token && open(token)) {
            navigator.vibrate?.(60);
            return;
        }
        // a family added after this page loaded: ask the server once
        if (token) {
            refresh().then(() => setTimeout(() => open(token) || toast.error(t('door.scan.unknown')), 0));
            return;
        }
        toast.error(t('door.scan.unknown'));
    };

    const checkIn = async (confirm: boolean) => {
        if (!current || !guest) return;
        const scan: QueuedScan = { scan_id: uuid(), token: guest.token, people_in: current.people, scanned_at: new Date().toISOString(), confirm, known_in: guest.in };
        setBusy(true);
        try {
            const data = await sendJson<CheckinResponse>('post', urls.checkin, { token: scan.token, people_in: scan.people_in, scan_id: scan.scan_id, confirm });
            setGuests((list) => replaceGuest(list, data.guest));
            setStats(data.stats);
            setOnline(true);
            setCurrent(null);
            setResult({ guest: data.guest, people: scan.people_in, already: data.status === 'already_recorded' });
        } catch (e) {
            if (statusOf(e) === 409) {
                const data = (e as { response: { data: CheckinResponse } }).response.data;
                setGuests((list) => replaceGuest(list, data.guest));
                setCurrent({ token: guest.token, people: current.people, serverWarnings: data.warnings });
            } else if (isOffline(e)) {
                // keep counting without the network: queue it, sync later
                setOnline(false);
                setQueue((q) => [...q, { ...scan, confirm: true }]);
                setGuests((list) => addLocalCheckin(list, guest.token, scan.people_in, new Date(), props.actor.name));
                setCurrent(null);
                setResult({ guest, people: scan.people_in, offline: true });
            } else {
                toast.error(t('door.not_found'));
            }
        } finally {
            setBusy(false);
        }
    };

    // the welcome card closes by itself so the next guest can be scanned
    useEffect(() => {
        if (!result) return;
        const id = window.setTimeout(() => setResult(null), 3500);
        return () => window.clearTimeout(id);
    }, [result]);

    const letInAnyway = async (c: SyncResult) => {
        setConflicts((list) => list.filter((x) => x.scan_id !== c.scan_id));
        if (c.guest) {
            setGuests((list) => replaceGuest(list, c.guest!));
            setCurrent({ token: c.guest.token, people: Math.max(1, suggestedPeople(c.guest)) });
        }
    };

    const liveBadge = (
        <span
            className={cn('inline-flex h-7 items-center gap-1.5 rounded-full px-2.5 text-xs font-medium', !online ? 'bg-red-50 text-red-800' : live === 'live' ? 'bg-emerald-50 text-emerald-800' : 'bg-muted text-muted-foreground')}
            data-live={online ? live : 'offline'}
            role="status"
        >
            <span className={cn('size-2 rounded-full', !online ? 'bg-red-500' : live === 'live' ? 'animate-pulse bg-emerald-500' : 'bg-zinc-400')} />
            {!online ? t('door.live.offline') : live === 'live' ? t('door.live.live') : t('door.live.polling', { seconds: props.poll_seconds })}
        </span>
    );

    return (
        <Shell title={event.title} right={liveBadge}>
            {props.actor.live && <LiveChannel eventId={event.id} onChange={refresh} onState={onLiveState} />}

            {urls.dashboard && (
                <Link href={urls.dashboard} className="text-muted-foreground inline-flex w-fit items-center gap-1 text-sm">
                    <ArrowLeft className="size-4 rtl:rotate-180" /> {props.actor.name}
                </Link>
            )}

            {stats && <Counters stats={stats} />}

            {queue.length > 0 && (
                <div className="flex items-center justify-between gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950" data-queue={queue.length}>
                    <span className="inline-flex items-center gap-2">
                        <CloudOff className="size-4" /> {tc('door.queue.waiting', queue.length)}
                    </span>
                    <Button size="sm" variant="outline" className="h-8 bg-white" onClick={sync}>
                        <RefreshCw /> {t('door.queue.sync')}
                    </Button>
                </div>
            )}

            {conflicts.length > 0 && (
                <section className="grid gap-2 rounded-xl border border-red-200 bg-red-50 p-3 text-sm" data-conflicts={conflicts.length}>
                    <h2 className="font-bold text-red-900">{t('door.queue.conflicts')}</h2>
                    {conflicts.map((c) => (
                        <div key={c.scan_id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-white p-2">
                            <span className="grid">
                                <b>
                                    <bdi>{c.guest?.name ?? c.token}</bdi>
                                </b>
                                <span className="text-xs text-red-800">{t(`door.queue.${c.reason ?? 'invalid'}`)}</span>
                            </span>
                            <span className="flex gap-1">
                                {c.guest && (
                                    <Button size="sm" className="h-8" onClick={() => letInAnyway(c)}>
                                        {t('door.queue.let_in')}
                                    </Button>
                                )}
                                <Button size="sm" variant="ghost" className="h-8" onClick={() => setConflicts((list) => list.filter((x) => x.scan_id !== c.scan_id))}>
                                    {t('door.queue.dismiss')}
                                </Button>
                            </span>
                        </div>
                    ))}
                </section>
            )}

            {result ? (
                <ResultCard result={result} onNext={() => setResult(null)} />
            ) : current && guest ? (
                <GuestCard
                    guest={guest}
                    people={current.people}
                    warnings={shownWarnings}
                    busy={busy}
                    onPeople={(people) => setCurrent({ token: guest.token, people: Math.max(1, Math.min(100, people)) })}
                    onCheckIn={() => checkIn(shownWarnings.length > 0)}
                    onCancel={() => setCurrent(null)}
                />
            ) : (
                <>
                    {scanning ? (
                        <div className="grid gap-2">
                            <Scanner
                                onCode={onCode}
                                onError={(e) => {
                                    setScanError(t(`door.scan.${e}`));
                                    setScanning(false);
                                }}
                            />
                            <p className="text-muted-foreground text-center text-sm">{t('door.scan.hint')}</p>
                            <Button variant="outline" className="h-11" onClick={() => setScanning(false)}>
                                <CameraOff /> {t('door.scan.stop')}
                            </Button>
                        </div>
                    ) : (
                        <Button
                            className="h-16 text-lg"
                            onClick={() => {
                                setScanError(null);
                                setScanning(true);
                            }}
                            data-scan
                        >
                            <Camera className="size-6" /> {t('door.scan.start')}
                        </Button>
                    )}
                    {scanError && <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">{scanError}</p>}

                    <section className="grid gap-2" aria-labelledby="door-search">
                        <label id="door-search" htmlFor="door-search-input" className="text-sm font-semibold">
                            {t('door.search.label')}
                        </label>
                        <div className="relative">
                            <Search className="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2" />
                            <Input id="door-search-input" type="search" className="h-12 bg-white ps-9 text-base" value={query} onChange={(e) => setQuery(e.target.value)} placeholder={t('door.search.placeholder')} autoComplete="off" />
                        </div>
                        {query.trim() !== '' && (
                            <ul className="grid gap-1.5" data-results={results.length}>
                                {results.length === 0 && <li className="text-muted-foreground p-2 text-sm">{t('door.search.none')}</li>}
                                {results.map((g) => (
                                    <li key={g.token}>
                                        <button
                                            type="button"
                                            className="flex w-full items-center justify-between gap-2 rounded-xl border bg-white p-3 text-start"
                                            onClick={() => {
                                                open(g.token);
                                                setQuery('');
                                            }}
                                        >
                                            <span className="grid min-w-0">
                                                <span className="truncate font-semibold">
                                                    <bdi>{g.name}</bdi>
                                                </span>
                                                <span className="text-muted-foreground text-xs">
                                                    {t(`door.guest.status.${g.status}`)}
                                                    {g.table && `${sep}${t('door.result.table', { n: n(g.table) })}`}
                                                </span>
                                            </span>
                                            {g.in > 0 && (
                                                <span className="shrink-0 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-800">
                                                    {t('door.search.in')} {n(g.in)}
                                                </span>
                                            )}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </>
            )}
        </Shell>
    );
}

function Counters({ stats }: { stats: DoorStats }) {
    const { t, n } = useT();
    const share = stats.total.expected > 0 ? Math.min(100, (stats.total.arrived / stats.total.expected) * 100) : 0;
    const sections = (['men', 'women', 'none'] as const).filter((k) => stats.sections[k].expected > 0 || stats.sections[k].arrived > 0 || (k !== 'none' && stats.sections[k].capacity > 0));

    return (
        <section className="grid gap-3 rounded-2xl border bg-white p-4" aria-label={t('door.counters.arrived')} data-counters>
            <div className="flex items-end justify-between gap-3">
                <div className="grid">
                    <span className="text-muted-foreground text-xs">{t('door.counters.arrived')}</span>
                    <span className="text-4xl leading-none font-bold tabular-nums" data-arrived>
                        {n(stats.total.arrived)}
                    </span>
                    <span className="text-muted-foreground text-sm">{t('door.counters.of_expected', { count: stats.total.expected })}</span>
                </div>
                {stats.total.capacity > 0 && <span className="text-muted-foreground text-xs">{t('door.counters.capacity', { count: stats.total.capacity })}</span>}
            </div>
            <div className="bg-muted h-2.5 overflow-hidden rounded-full">
                <div className="bg-primary h-full rounded-full transition-[width] duration-500" style={{ width: `${share}%` }} />
            </div>
            {sections.length > 1 && (
                <div className="grid grid-cols-3 gap-2">
                    {sections.map((k) => (
                        <div key={k} className={cn('grid rounded-lg p-2 text-center', k === 'men' ? 'bg-sky-50' : k === 'women' ? 'bg-rose-50' : 'bg-zinc-50')} data-section={k}>
                            <span className="text-muted-foreground text-xs">{t(`door.counters.sections.${k}`)}</span>
                            <span className="text-lg font-bold tabular-nums">
                                {n(stats.sections[k].arrived)}
                                <span className="text-muted-foreground text-xs font-normal">/{n(stats.sections[k].expected)}</span>
                            </span>
                            {stats.sections[k].capacity > 0 && <span className="text-muted-foreground text-[11px]">{t('door.counters.capacity', { count: stats.sections[k].capacity })}</span>}
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}

function warningText(w: DoorWarning, t: ReturnType<typeof useT>['t'], n: (v: number | string) => string): string {
    switch (w.type) {
        case 'already_in':
            return w.by ? t('door.warnings.already_in', { count: w.people_in, time: n(w.time ?? ''), by: `\u2068${w.by}\u2069` }) : t('door.warnings.already_in_short', { count: w.people_in, time: n(w.time ?? '') });
        case 'over_limit':
            return t('door.warnings.over_limit', { total: w.total, allowed: w.allowed });
        case 'not_attending':
            return t(`door.warnings.not_attending_${w.status}`);
        case 'partial':
            return t('door.warnings.partial', { total: w.total, expected: w.expected });
        case 'extra':
            return t('door.warnings.extra', { total: w.total, expected: w.expected });
    }
}

function GuestCard({
    guest,
    people,
    warnings,
    busy,
    onPeople,
    onCheckIn,
    onCancel,
}: {
    guest: DoorGuest;
    people: number;
    warnings: DoorWarning[];
    busy: boolean;
    onPeople: (n: number) => void;
    onCheckIn: () => void;
    onCancel: () => void;
}) {
    const { t, tc, n } = useT();
    const serious = warnings.some((w) => w.type === 'already_in' || w.type === 'over_limit' || w.type === 'not_attending');
    const fact = (label: string, value: React.ReactNode, key: string) => (
        <div className="grid rounded-lg bg-zinc-50 p-2 text-center" data-fact={key}>
            <span className="text-muted-foreground text-xs">{label}</span>
            <span className="text-lg font-bold">{value}</span>
        </div>
    );

    return (
        <section className="grid gap-4 rounded-2xl border-2 bg-white p-4 shadow-sm" aria-labelledby="guest-name" data-guest-card={guest.token}>
            <div className="flex items-start justify-between gap-2">
                <div className="grid gap-1">
                    <h2 id="guest-name" className="text-xl font-bold">
                        <bdi>{guest.name}</bdi>
                    </h2>
                    <span
                        className={cn(
                            'w-fit rounded-full px-2 py-0.5 text-xs font-semibold',
                            guest.status === 'attending' ? 'bg-emerald-50 text-emerald-800' : guest.status === 'declined' ? 'bg-red-50 text-red-800' : 'bg-zinc-100 text-zinc-700',
                        )}
                    >
                        {t(`door.guest.status.${guest.status}`)}
                    </span>
                </div>
                <Button size="icon" variant="ghost" onClick={onCancel} aria-label={t('door.guest.cancel')}>
                    <X />
                </Button>
            </div>

            <div className="grid grid-cols-3 gap-2">
                {fact(t('door.guest.seats'), n(guest.seats), 'seats')}
                {fact(
                    t('door.guest.attending'),
                    <>
                        {guest.people !== null ? n(guest.people) : '—'}
                        {!!guest.kids && (
                            <span className="text-muted-foreground ms-1 inline-flex items-center text-xs font-normal">
                                <Baby className="size-3" />
                                {n(guest.kids)}
                            </span>
                        )}
                    </>,
                    'people',
                )}
                {fact(t('door.guest.table'), guest.table ? n(guest.table) : '—', 'table')}
                {fact(t('door.guest.section'), guest.section ? t(`door.counters.sections.${guest.section}`) : '—', 'section')}
                {fact(t('door.guest.inside'), n(guest.in), 'inside')}
            </div>

            {warnings.length > 0 && (
                <ul className="grid gap-1.5" data-warnings={warnings.map((w) => w.type).join(' ')}>
                    {warnings.map((w, i) => (
                        <li key={i} className={cn('flex items-start gap-2 rounded-lg p-2.5 text-sm font-medium', w.type === 'partial' || w.type === 'extra' ? 'bg-amber-50 text-amber-950' : 'bg-red-50 text-red-900')}>
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                            <bdi>{warningText(w, t, n)}</bdi>
                        </li>
                    ))}
                </ul>
            )}

            <div className="grid gap-2">
                <span className="text-sm font-semibold">{t('door.guest.people_in')}</span>
                <div className="flex items-center justify-center gap-4">
                    <Button size="icon" variant="outline" className="size-12 rounded-full" onClick={() => onPeople(people - 1)} disabled={people <= 1} aria-label={t('door.guest.less')}>
                        <Minus />
                    </Button>
                    <span className="w-16 text-center text-4xl font-bold tabular-nums" aria-live="polite" data-people-in>
                        {n(people)}
                    </span>
                    <Button size="icon" variant="outline" className="size-12 rounded-full" onClick={() => onPeople(people + 1)} aria-label={t('door.guest.more')}>
                        <Plus />
                    </Button>
                </div>
            </div>

            <Button className={cn('h-14 text-base', serious && 'bg-red-700 hover:bg-red-800')} disabled={busy} onClick={onCheckIn} data-check-in={warnings.length ? 'confirm' : 'ok'}>
                <Users /> {warnings.length ? tc('door.guest.confirm', people) : tc('door.guest.check_in', people)}
            </Button>
            <Button variant="ghost" onClick={onCancel}>
                {t('door.guest.cancel')}
            </Button>
        </section>
    );
}

function ResultCard({ result, onNext }: { result: { guest: DoorGuest; people: number; offline?: boolean; already?: boolean }; onNext: () => void }) {
    const { t, n, sep } = useT();
    const { guest } = result;
    return (
        <section className={cn('grid gap-3 rounded-2xl p-6 text-center text-white shadow-lg', result.offline ? 'bg-amber-600' : 'bg-emerald-600')} role="status" aria-live="assertive" data-result={result.offline ? 'offline' : 'ok'}>
            <CheckCircle2 className="mx-auto size-14" />
            <h2 className="text-2xl font-bold">{t('door.result.welcome')}</h2>
            <p className="text-lg">
                <bdi>{t('door.result.checked_in', { name: `\u2068${guest.name}\u2069`, count: result.people })}</bdi>
            </p>
            {(guest.table || guest.section) && (
                <p className="text-3xl font-bold">
                    {guest.table && t('door.result.table', { n: n(guest.table) })}
                    {guest.table && guest.section && sep}
                    {guest.section && <span className="text-lg font-semibold">{t('door.result.section', { section: t(`door.counters.sections.${guest.section}`) })}</span>}
                </p>
            )}
            {result.offline && <p className="text-sm">{t('door.result.offline')}</p>}
            {result.already && <p className="text-sm">{t('door.result.already_recorded')}</p>}
            <Button variant="secondary" className="mx-auto h-11" onClick={onNext}>
                {t('door.result.next')}
            </Button>
        </section>
    );
}
