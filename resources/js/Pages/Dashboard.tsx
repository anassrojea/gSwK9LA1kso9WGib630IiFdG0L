import { Head } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import { CalendarDays, ExternalLink, LayoutDashboard, Pencil, Plus } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout';
import { useT } from '@/lib/i18n';
import { toLocalDigits } from '@/lib/digits';

interface EventSummary {
    id: string;
    title: string;
    occasion: string;
    status: 'draft' | 'active' | 'ended' | 'archived';
    starts_at: string | null;
    stats: { invited: number; opened: number; replied: number; attending: number };
    preview_url: string | null;
    edit_url: string;
    dashboard_url: string | null;
}

/**
 * The host's events (plan section 4.1): numbers per event. Published events
 * open their own dashboard (Pages/dashboard/Event); drafts go back to the wizard.
 */
export default function Dashboard({ events }: { events: EventSummary[] }) {
    const { t, locale } = useT();
    const n = (value: number) => toLocalDigits(value, locale);
    const date = (iso: string | null) =>
        iso ? new Intl.DateTimeFormat(locale === 'ar' ? 'ar-u-nu-arab' : 'en-GB', { dateStyle: 'full', timeStyle: 'short' }).format(new Date(iso)) : '';

    return (
        <AppLayout>
            <Head title={t('dashboard.title')} />
            <div className="mb-6 flex items-end justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-bold">{t('my_events')}</h1>
                </div>
                <Button asChild size="lg" className="h-11">
                    <Link href="/create">
                        <Plus /> {t('create_invitation')}
                    </Link>
                </Button>
            </div>

            {events.length === 0 && <p className="text-muted-foreground">{t('no_events')}</p>}

            <div className="grid gap-4">
                {events.map((event) => (
                    <Card key={event.id}>
                        <CardHeader className="flex flex-col items-start justify-between gap-4 sm:flex-row">
                            <div className="grid gap-1.5">
                                <CardTitle className="text-lg">
                                    <Link href={event.dashboard_url ?? event.edit_url} className="hover:underline" data-event-link>
                                        {event.title}
                                    </Link>
                                </CardTitle>
                                <CardDescription className="flex items-center gap-1.5">
                                    <CalendarDays className="size-4" /> {date(event.starts_at)}
                                    <span className="ms-2 rounded-full bg-secondary px-2 py-0.5 text-xs text-secondary-foreground">{t(`status.${event.status}`)}</span>
                                </CardDescription>
                            </div>
                            <div className="flex flex-wrap gap-2">
                            {event.dashboard_url && (
                                <Button size="sm" asChild>
                                    <Link href={event.dashboard_url}>
                                        <LayoutDashboard /> {t('dashboard.open_dashboard')}
                                    </Link>
                                </Button>
                            )}
                            <Button variant={event.status === 'draft' ? 'default' : 'outline'} size="sm" asChild>
                                <Link href={event.edit_url}>
                                    <Pencil /> {t('continue_editing')}
                                </Link>
                            </Button>
                            {event.preview_url && (
                                <Button variant="outline" size="sm" asChild>
                                    <a href={event.preview_url} target="_blank" rel="noopener">
                                        <ExternalLink /> {t('open_invite')}
                                    </a>
                                </Button>
                            )}
                            </div>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                {(['invited', 'opened', 'replied', 'attending'] as const).map((key) => (
                                    <div key={key} className="rounded-lg bg-muted p-3">
                                        <dt className="text-xs text-muted-foreground">{t(`dashboard.cards.${key}`)}</dt>
                                        <dd className="text-2xl font-semibold tabular-nums">{n(event.stats[key])}</dd>
                                    </div>
                                ))}
                            </dl>
                        </CardContent>
                    </Card>
                ))}
            </div>
        </AppLayout>
    );
}
