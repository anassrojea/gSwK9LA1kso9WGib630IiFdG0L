import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Armchair, BellRing, CalendarDays, Download, Gem, Hourglass, Images, MapPin, MessageSquareText, Pencil, PartyPopper, QrCode, RefreshCw, Search, UserPlus, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import { toast } from 'sonner';

import { AddGuestsDialog } from '@/components/dashboard/AddGuestsDialog';
import { DeleteGuestDialog, EditGuestDialog } from '@/components/dashboard/GuestDialogs';
import { GuestTable, type RowHandlers } from '@/components/dashboard/GuestTable';
import { MessagesDialog } from '@/components/dashboard/MessagesDialog';
import { Funnel, StatCards } from '@/components/dashboard/Overview';
import { ReminderDialog } from '@/components/dashboard/ReminderDialog';
import { UpgradeBanner } from '@/components/dashboard/UpgradeBanner';
import { OpenLinkPanel } from '@/components/wizard/OpenLinkPanel';
import { TierBadge } from '@/components/wizard/TierBadge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Toaster } from '@/components/ui/sonner';
import { useEventChannel, type BroadcastName, type GuestBroadcast, type LiveState } from '@/hooks/useEventChannel';
import AppLayout from '@/layouts/AppLayout';
import { toastFlash } from '@/lib/dashboard/flash';
import { countByStatus, filterGuests, STATUSES, upsertGuest, type GuestFilters, type GuestRow, type Stats } from '@/lib/dashboard/guests';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { DashboardProps } from '@/types/dashboard';

const RELOAD = ['guests', 'stats', 'tags'];

async function copyText(text: string): Promise<boolean> {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch {
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        const ok = document.execCommand('copy');
        area.remove();
        return ok;
    }
}

function LiveBadge({ state, onRefresh }: { state: LiveState; onRefresh: () => void }) {
    const { t } = useT();
    return (
        <div className="flex items-center gap-2">
            <span
                className={cn(
                    'inline-flex h-8 items-center gap-1.5 rounded-full px-3 text-xs font-medium',
                    state === 'live' ? 'bg-emerald-100 text-emerald-900' : 'bg-muted text-muted-foreground',
                )}
                data-live={state}
                aria-live="polite"
            >
                <span className={cn('size-2 rounded-full', state === 'live' ? 'animate-pulse bg-emerald-500' : 'bg-zinc-400')} />
                {state === 'live' ? t('dashboard.realtime.live') : t('dashboard.realtime.offline')}
            </span>
            <Button variant="outline" size="sm" className="h-8" onClick={onRefresh} data-refresh>
                <RefreshCw /> {t('dashboard.realtime.refresh')}
            </Button>
        </div>
    );
}

function BigButton({ icon, children, onClick, href, disabled, note, primary, name }: { icon: ReactNode; children: ReactNode; onClick?: () => void; href?: string; disabled?: boolean; note?: string; primary?: boolean; name: string }) {
    const className = cn(
        'h-auto min-h-14 w-full justify-start gap-2 px-3 py-2.5 text-start text-sm whitespace-normal sm:gap-3 sm:px-4 sm:py-3 sm:text-[0.95rem] [&_svg]:size-5',
        primary && 'col-span-2 shadow-sm lg:col-span-1',
    );
    const body = (
        <>
            {icon}
            <span className="grid gap-0.5">
                <span className="font-semibold">{children}</span>
                {note && <span className="text-xs font-normal opacity-80">{note}</span>}
            </span>
        </>
    );

    if (href && !disabled) {
        return (
            <Button asChild variant={primary ? 'default' : 'outline'} className={className}>
                {href.endsWith('.csv') ? (
                    <a href={href} data-big={name}>
                        {body}
                    </a>
                ) : (
                    <Link href={href} data-big={name}>
                        {body}
                    </Link>
                )}
            </Button>
        );
    }

    return (
        <Button variant={primary ? 'default' : 'outline'} className={className} onClick={onClick} disabled={disabled} aria-disabled={disabled} data-big={name}>
            {body}
        </Button>
    );
}

function LiveDay({ stats }: { stats: Stats }) {
    const { t, n, sep } = useT();
    const expectedPeople = Math.max(stats.attending, stats.arrived_people);
    const expectedFamilies = stats.attending_families || stats.invited;
    const share = expectedPeople > 0 ? Math.min(100, (stats.arrived_people / expectedPeople) * 100) : 0;

    return (
        <section className="from-primary/10 to-primary/0 border-primary/30 grid gap-4 rounded-2xl border bg-gradient-to-b p-4 sm:p-5" data-live-day aria-labelledby="live-day-title">
            <div className="flex items-center gap-2">
                <PartyPopper className="text-primary size-5" />
                <h2 id="live-day-title" className="text-lg font-bold">
                    {t('dashboard.live.title')}
                </h2>
            </div>
            <div className="grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end">
                <div className="grid gap-2">
                    <span className="text-muted-foreground text-sm">{t('dashboard.live.arrivals')}</span>
                    <span className="text-5xl leading-none font-bold tabular-nums" data-arrivals>
                        {n(stats.arrived_people)}
                    </span>
                    <span className="text-muted-foreground text-sm">
                        {t('dashboard.live.people', { count: stats.arrived_people, expected: expectedPeople })}
                        {sep}
                        {t('dashboard.live.families', { count: stats.arrived_families, total: expectedFamilies })}
                    </span>
                    <div className="bg-muted h-3 overflow-hidden rounded-full">
                        <div className="bg-primary h-full rounded-full transition-[width] duration-500" style={{ width: `${share}%` }} />
                    </div>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button variant="outline" disabled className="h-11" title={t('dashboard.big.soon')}>
                        <QrCode /> {t('dashboard.live.door_app')}
                    </Button>
                    <Button variant="outline" disabled className="h-11" title={t('dashboard.big.soon')}>
                        <Images /> {t('dashboard.live.photo_wall')}
                    </Button>
                    <p className="text-muted-foreground w-full text-xs">{t('dashboard.big.soon')}</p>
                </div>
            </div>
        </section>
    );
}

/**
 * Host dashboard for one event (plan section 4.1): numbers on top, one list
 * below, actions on each row. Live over Reverb when it runs; the Refresh
 * button (and coming back to the tab) covers the times it does not.
 */
export default function EventDashboard(props: DashboardProps) {
    const { t, tc, n, locale, dir } = useT();
    const { event, can, urls } = props;

    const [guests, setGuests] = useState<GuestRow[]>(props.guests);
    const [stats, setStats] = useState<Stats>(props.stats);
    useEffect(() => setGuests(props.guests), [props.guests]);
    useEffect(() => setStats(props.stats), [props.stats]);

    const [filters, setFilters] = useState<GuestFilters>({ status: 'all', tag: 'all', query: '' });
    const [editing, setEditing] = useState<GuestRow | null>(null);
    const [deleting, setDeleting] = useState<GuestRow | null>(null);
    const [reminders, setReminders] = useState<{ open: boolean; audience: 'never' | 'opened' | 'both'; key: number }>({ open: false, audience: 'both', key: 0 });
    const [adding, setAdding] = useState(false);
    const [editingMessages, setEditingMessages] = useState(false);

    const refresh = useCallback(() => router.reload({ only: RELOAD }), []);

    const onMessage = useCallback(
        (name: BroadcastName, payload: GuestBroadcast) => {
            if (payload.guest) setGuests((list) => upsertGuest(list, payload.guest));
            if (payload.stats) setStats(payload.stats);
            if (name === '.rsvp.submitted' && payload.guest) {
                toast(`${payload.guest.name}: ${t(`dashboard.status.${payload.guest.status}`)}`);
            }
        },
        [t],
    );
    const liveState = useEventChannel(event.id, onMessage, refresh);

    // without a socket, coming back to the tab is a good moment to catch up
    useEffect(() => {
        if (liveState !== 'offline') return;
        const onVisible = () => document.visibilityState === 'visible' && refresh();
        document.addEventListener('visibilitychange', onVisible);
        return () => document.removeEventListener('visibilitychange', onVisible);
    }, [liveState, refresh]);

    const handlers: RowHandlers = useMemo(
        () => ({
            onEdit: setEditing,
            onDelete: setDeleting,
            onCheckIn: (guest) => router.post(`/dashboard/households/${guest.id}/checkin`, {}, { preserveScroll: true, onSuccess: toastFlash }),
            onCopy: async (guest) => {
                if (await copyText(guest.link)) toast.success(t('dashboard.actions.copied'));
                else toast.error(t('dashboard.actions.copy_failed'));
            },
        }),
        [t],
    );

    const visible = useMemo(() => filterGuests(guests, filters), [guests, filters]);
    const counts = useMemo(() => countByStatus(guests), [guests]);
    const filtered = filters.status !== 'all' || filters.tag !== 'all' || filters.query.trim() !== '';

    const openReminders = (audience: 'never' | 'opened' | 'both') => setReminders((r) => ({ open: true, audience, key: r.key + 1 }));

    const date = event.starts_at
        ? new Intl.DateTimeFormat(locale === 'ar' ? 'ar-u-nu-arab' : 'en-GB', { dateStyle: 'full', timeStyle: 'short', timeZone: event.timezone }).format(new Date(event.starts_at))
        : null;

    const exportUrl = (() => {
        const params = new URLSearchParams();
        if (filters.status !== 'all') params.set('status', filters.status);
        if (filters.tag !== 'all') params.set('tag', filters.tag);
        const query = params.toString();
        return query ? `${urls.export}?${query}` : urls.export;
    })();

    return (
        <AppLayout>
            <Head title={event.title} />
            <Toaster />
            <div className="grid gap-5" data-dashboard>
                <header className="grid gap-3">
                    <Link href="/dashboard" className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1 text-sm">
                        <ArrowLeft className="size-4 rtl:rotate-180" /> {t('dashboard.all_events')}
                    </Link>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="grid min-w-0 gap-1.5">
                            <h1 className="text-2xl font-bold sm:text-3xl">
                                <bdi>{event.title}</bdi>
                            </h1>
                            <p className="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                                {date && (
                                    <span className="inline-flex items-center gap-1.5">
                                        <CalendarDays className="size-4" /> {date}
                                    </span>
                                )}
                                {event.venue_name && (
                                    <span className="inline-flex items-center gap-1.5">
                                        <MapPin className="size-4" /> <bdi>{event.venue_name}</bdi>
                                    </span>
                                )}
                                {stats.is_event_day && <span className="bg-primary text-primary-foreground rounded-full px-2 py-0.5 text-xs font-semibold">{t('dashboard.event_day')}</span>}
                                <TierBadge tier={props.plan} />
                            </p>
                        </div>
                        <LiveBadge state={liveState} onRefresh={refresh} />
                    </div>
                </header>

                {stats.is_event_day && <LiveDay stats={stats} />}

                {props.pendingOrder && (
                    <Link href={`${urls.orders}/${props.pendingOrder.code}`} className="flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950" data-pending-order>
                        <Hourglass className="size-4 shrink-0" />
                        <span>
                            {t(`payments.status.${props.pendingOrder.status}`)} · <bdi dir="ltr">{props.pendingOrder.code}</bdi>
                        </span>
                    </Link>
                )}

                {can.update && !can.send && guests.some((g) => g.link) && (
                    <section className="grid gap-2 rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-950 sm:grid-cols-[1fr_auto] sm:items-center" data-send-locked>
                        <div className="grid gap-1">
                            <h2 className="font-bold">{t('checkout.send_locked_title')}</h2>
                            <p className="text-sm">{t('checkout.send_locked')}</p>
                        </div>
                        <Button asChild className="h-11">
                            <Link href={urls.upgrade}>
                                <Gem /> {t('checkout.upgrade')}
                            </Link>
                        </Button>
                    </section>
                )}

                {props.upgradeHint && !(can.update && !can.send && guests.some((g) => g.link)) && (
                    <UpgradeBanner eventId={event.id} hint={props.upgradeHint} upgradeUrl={urls.upgrade} />
                )}

                <StatCards stats={stats} />

                <div className="grid grid-cols-2 gap-2 lg:grid-cols-4" data-big-buttons>
                    {can.update && can.remind && (
                        <BigButton name="remind" primary icon={<BellRing />} onClick={() => openReminders('both')} note={tc('wizard.guests.families', stats.opened_not_replied + stats.never_opened)}>
                            {t('dashboard.big.remind')}
                        </BigButton>
                    )}
                    {can.update && (
                        <BigButton name="add" icon={<UserPlus />} onClick={() => setAdding(true)}>
                            {t('dashboard.big.add_guests')}
                        </BigButton>
                    )}
                    {can.update && (
                        <BigButton name="edit" icon={<Pencil />} href={urls.edit}>
                            {t('dashboard.big.edit_invitation')}
                        </BigButton>
                    )}
                    {can.update && (
                        <BigButton name="messages" icon={<MessageSquareText />} onClick={() => setEditingMessages(true)}>
                            {t('dashboard.big.messages')}
                        </BigButton>
                    )}
                    {can.update && (
                        <BigButton name="upgrade" primary={!can.send} icon={<Gem />} href={urls.upgrade}>
                            {t('checkout.upgrade')}
                        </BigButton>
                    )}
                    <BigButton name="seat" icon={<Armchair />} disabled note={t('dashboard.big.soon')}>
                        {t('dashboard.big.seat')}
                    </BigButton>
                    <BigButton name="album" icon={<Images />} disabled note={t('dashboard.big.soon')}>
                        {t('dashboard.big.album')}
                    </BigButton>
                    <BigButton name="export" icon={<Download />} href={exportUrl} note={filtered ? t('dashboard.table.showing', { shown: visible.length, total: guests.length }) : undefined}>
                        {t('dashboard.big.export')}
                    </BigButton>
                </div>

                {props.openLink && <OpenLinkPanel initial={props.openLink} title={event.title} />}

                <Funnel stats={stats} onRemind={can.update && can.remind ? () => openReminders(stats.opened_not_replied > 0 ? 'opened' : 'both') : undefined} />

                <section className="grid gap-3" aria-labelledby="guests-title">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 id="guests-title" className="text-lg font-semibold">
                            {t('dashboard.table.title')}
                        </h2>
                        <span className="text-muted-foreground text-sm" data-showing>
                            {t('dashboard.table.showing', { shown: visible.length, total: guests.length })}
                        </span>
                    </div>

                    <div className="grid gap-2 sm:grid-cols-[1fr_12rem_12rem]" data-filters>
                        <div className="relative">
                            <Search className="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2" />
                            <Input
                                type="search"
                                className="h-11 ps-9"
                                dir="auto"
                                placeholder={t('dashboard.table.search')}
                                aria-label={t('dashboard.table.search')}
                                value={filters.query}
                                onChange={(e) => setFilters((f) => ({ ...f, query: e.target.value }))}
                                data-search
                            />
                        </div>
                        <NativeSelect
                            dir={dir}
                            aria-label={t('dashboard.table.filter_status')}
                            value={filters.status}
                            onChange={(e) => setFilters((f) => ({ ...f, status: e.target.value as GuestFilters['status'] }))}
                            data-filter-status
                        >
                            <option value="all">
                                {t('dashboard.table.all_statuses')} ({n(guests.length)})
                            </option>
                            {STATUSES.map((status) => (
                                <option key={status} value={status}>
                                    {t(`dashboard.status.${status}`)} ({n(counts[status])})
                                </option>
                            ))}
                        </NativeSelect>
                        <NativeSelect
                            dir={dir}
                            aria-label={t('dashboard.table.filter_tag')}
                            value={filters.tag}
                            onChange={(e) => setFilters((f) => ({ ...f, tag: e.target.value }))}
                            disabled={props.tags.length === 0}
                            data-filter-tag
                        >
                            <option value="all">{t('dashboard.table.all_tags')}</option>
                            {props.tags.map((tag) => (
                                <option key={tag} value={tag}>
                                    {tag}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>

                    {filtered && (
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            <Button variant="ghost" size="sm" onClick={() => setFilters({ status: 'all', tag: 'all', query: '' })}>
                                <X /> {t('dashboard.table.clear')}
                            </Button>
                        </div>
                    )}

                    {guests.length === 0 ? (
                        <div className="bg-card grid justify-items-center gap-3 rounded-xl border border-dashed p-8 text-center">
                            <p className="text-muted-foreground">{t('dashboard.table.empty')}</p>
                            {can.update && (
                                <Button onClick={() => setAdding(true)}>
                                    <UserPlus /> {t('dashboard.big.add_guests')}
                                </Button>
                            )}
                        </div>
                    ) : visible.length === 0 ? (
                        <p className="text-muted-foreground bg-card rounded-xl border border-dashed p-6 text-center text-sm">{t('dashboard.table.no_match')}</p>
                    ) : (
                        <GuestTable guests={visible} can={can} timezone={event.timezone} handlers={handlers} />
                    )}
                </section>
            </div>

            {can.update && (
                <>
                    <EditGuestDialog guest={editing} onClose={() => setEditing(null)} limits={props.limits} />
                    <DeleteGuestDialog guest={deleting} onClose={() => setDeleting(null)} />
                    <ReminderDialog
                        key={reminders.key}
                        open={reminders.open}
                        onOpenChange={(open) => {
                            setReminders((r) => ({ ...r, open }));
                            if (!open) refresh();
                        }}
                        stats={stats}
                        url={urls.reminders}
                        initialAudience={reminders.audience}
                    />
                    <AddGuestsDialog open={adding} onOpenChange={setAdding} urls={urls} />
                    <MessagesDialog
                        key={`${props.messages.invite}|${props.messages.reminder}`}
                        open={editingMessages}
                        onOpenChange={setEditingMessages}
                        messages={props.messages}
                        url={urls.messages}
                        max={props.limits.message}
                        dir={event.invite_locale === 'ar' ? 'rtl' : 'ltr'}
                        sample={{ name: guests[0]?.name ?? t('wizard.preview.guest_name'), title: event.title, link: guests[0]?.link ?? 'https://daawa.link/…' }}
                    />
                </>
            )}
        </AppLayout>
    );
}
