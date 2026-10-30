import { Link, router } from '@inertiajs/react';
import { CalendarDays, ChevronLeft, ChevronRight, List, UserRound } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { useMediaQuery } from '@/hooks/useMediaQuery';
import { useT } from '@/lib/i18n';
import { byDay, inMonth, localDay, monthGrid, shiftMonth } from '@/lib/org/calendar';
import { cn } from '@/lib/utils';
import type { CalendarEvent } from '@/types/org';

/**
 * The venue's calendar (plan section 4.2): a month grid on wide screens, a
 * day-by-day list on phones (and on request). Events sit on their own local
 * date; ?month=YYYY-MM moves between months.
 */
export function VenueCalendar({ events, month, baseUrl }: { events: CalendarEvent[]; month: string; baseUrl: string }) {
    const { t, n, locale, dir } = useT();
    const wide = useMediaQuery('(min-width: 768px)');
    const [view, setView] = useState<'month' | 'list' | null>(null);
    const mode = view ?? (wide ? 'month' : 'list');
    const intl = locale === 'ar' ? 'ar-u-nu-arab' : 'en-GB';
    // the Arabic week starts on Saturday, the English one on Sunday
    const weekStart = locale === 'ar' ? 6 : 0;
    const grid = monthGrid(month, weekStart);
    const days = byDay(events);
    const today = localDay({ starts_at: new Date().toISOString(), timezone: Intl.DateTimeFormat().resolvedOptions().timeZone });
    const Prev = dir === 'rtl' ? ChevronRight : ChevronLeft;
    const Next = dir === 'rtl' ? ChevronLeft : ChevronRight;

    const title = new Intl.DateTimeFormat(intl, { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${month}-01T00:00:00Z`));
    const weekday = (day: string, style: 'short' | 'long') => new Intl.DateTimeFormat(intl, { weekday: style, timeZone: 'UTC' }).format(new Date(`${day}T00:00:00Z`));
    const dayLabel = (day: string) => new Intl.DateTimeFormat(intl, { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }).format(new Date(`${day}T00:00:00Z`));
    const time = (e: CalendarEvent) =>
        e.starts_at ? new Intl.DateTimeFormat(intl, { hour: 'numeric', minute: '2-digit', timeZone: e.timezone || undefined }).format(new Date(e.starts_at)) : '';
    const go = (to: string) => router.get(baseUrl, { month: to }, { preserveScroll: true, preserveState: true, only: ['events', 'month'] });

    const monthDays = [...days.entries()].filter(([day]) => day !== '' && inMonth(day, month));
    const undated = days.get('') ?? [];

    return (
        <section className="grid gap-3 rounded-xl border bg-white p-3 sm:p-4" data-calendar={month} data-calendar-view={mode}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-1">
                    <Button variant="ghost" size="icon" className="size-10" onClick={() => go(shiftMonth(month, -1))} aria-label={t('org.calendar.previous')} data-calendar-prev>
                        <Prev />
                    </Button>
                    <h2 className="min-w-36 text-center text-lg font-bold" data-calendar-title>
                        {title}
                    </h2>
                    <Button variant="ghost" size="icon" className="size-10" onClick={() => go(shiftMonth(month, 1))} aria-label={t('org.calendar.next')} data-calendar-next>
                        <Next />
                    </Button>
                </div>
                <div className="bg-muted flex rounded-lg p-1" role="group">
                    <Button size="sm" variant={mode === 'month' ? 'default' : 'ghost'} className="h-8" onClick={() => setView('month')} aria-pressed={mode === 'month'}>
                        <CalendarDays /> {t('org.calendar.month')}
                    </Button>
                    <Button size="sm" variant={mode === 'list' ? 'default' : 'ghost'} className="h-8" onClick={() => setView('list')} aria-pressed={mode === 'list'}>
                        <List /> {t('org.calendar.list')}
                    </Button>
                </div>
            </div>

            {mode === 'month' ? (
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[640px] table-fixed border-collapse text-sm" data-calendar-grid>
                        <thead>
                            <tr>
                                {grid[0].map((day) => (
                                    <th key={day} className="text-muted-foreground pb-2 text-xs font-medium">
                                        {weekday(day, 'short')}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {grid.map((week) => (
                                <tr key={week[0]}>
                                    {week.map((day) => {
                                        const list = days.get(day) ?? [];
                                        return (
                                            <td
                                                key={day}
                                                className={cn('h-24 overflow-hidden border p-1 align-top', !inMonth(day, month) && 'bg-muted/40 text-muted-foreground', day === today && 'bg-amber-50')}
                                                data-day={day}
                                            >
                                                <div className="mb-1 text-xs font-semibold tabular-nums">{n(Number(day.slice(8)))}</div>
                                                <ul className="grid min-w-0 gap-1">
                                                    {list.slice(0, 3).map((e) => (
                                                        <li key={e.id}>
                                                            <Link
                                                                href={e.url}
                                                                className={cn(
                                                                    'block max-w-full truncate rounded px-1.5 py-0.5 text-xs',
                                                                    e.published ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground',
                                                                )}
                                                                title={e.title}
                                                                data-calendar-event={e.id}
                                                            >
                                                                <span className="tabular-nums">{time(e)}</span> {e.title}
                                                            </Link>
                                                        </li>
                                                    ))}
                                                    {list.length > 3 && <li className="text-muted-foreground px-1 text-xs">{t('org.calendar.more', { count: list.length - 3 })}</li>}
                                                </ul>
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <ol className="grid gap-4" data-calendar-list>
                    {monthDays.length === 0 && <li className="text-muted-foreground py-6 text-center text-sm">{t('org.dashboard.no_events')}</li>}
                    {monthDays.map(([day, list]) => (
                        <li key={day} className="grid gap-2" data-day={day}>
                            <h3 className={cn('text-sm font-semibold', day === today && 'text-amber-700')}>{dayLabel(day)}</h3>
                            <ul className="grid gap-2">
                                {list.map((e) => (
                                    <li key={e.id}>
                                        <EventRow event={e} time={time(e)} />
                                    </li>
                                ))}
                            </ul>
                        </li>
                    ))}
                </ol>
            )}

            {undated.length > 0 && (
                <div className="grid gap-2 border-t pt-3">
                    <h3 className="text-muted-foreground text-sm font-semibold">{t('org.calendar.no_date')}</h3>
                    {undated.map((e) => (
                        <EventRow key={e.id} event={e} time="" />
                    ))}
                </div>
            )}
        </section>
    );
}

function EventRow({ event, time }: { event: CalendarEvent; time: string }) {
    const { t, n } = useT();

    return (
        <Link href={event.url} className="hover:bg-muted/50 flex items-center gap-3 rounded-lg border p-3" data-calendar-event={event.id}>
            <span className="text-primary w-16 shrink-0 text-sm font-bold tabular-nums">{time}</span>
            <span className="grid min-w-0 flex-1 gap-0.5">
                <span className="truncate font-medium">{event.title}</span>
                <span className="text-muted-foreground flex flex-wrap items-center gap-x-2 text-xs">
                    {!event.published && <span className="bg-muted rounded px-1.5">{t('org.calendar.draft')}</span>}
                    {event.hall && <span>{event.hall}</span>}
                    <span>{t('org.calendar.guests', { attending: n(event.stats.attending), invited: n(event.stats.invited) })}</span>
                    {event.client && (
                        <span className="inline-flex items-center gap-1">
                            <UserRound className="size-3" /> {t('org.calendar.client', { name: event.client })}
                        </span>
                    )}
                </span>
            </span>
        </Link>
    );
}
