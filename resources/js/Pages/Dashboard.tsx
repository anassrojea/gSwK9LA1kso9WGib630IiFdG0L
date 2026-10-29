import { Head } from '@inertiajs/react';
import { CalendarDays, ExternalLink } from 'lucide-react';

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
}

/**
 * Placeholder host dashboard (plan section 4.1): numbers per event. The
 * guest table, filters and actions arrive with the "Guests" milestone.
 */
export default function Dashboard({ events }: { events: EventSummary[] }) {
    const { t, locale } = useT();
    const n = (value: number) => toLocalDigits(value, locale);
    const date = (iso: string | null) =>
        iso ? new Intl.DateTimeFormat(locale === 'ar' ? 'ar-u-nu-arab' : 'en-GB', { dateStyle: 'full', timeStyle: 'short' }).format(new Date(iso)) : '';

    return (
        <AppLayout>
            <Head title={t('dashboard')} />
            <div className="mb-6 flex items-end justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-bold">{t('my_events')}</h1>
                    <p className="text-sm text-muted-foreground">{t('create_soon')}</p>
                </div>
            </div>

            {events.length === 0 && <p className="text-muted-foreground">{t('no_events')}</p>}

            <div className="grid gap-4">
                {events.map((event) => (
                    <Card key={event.id}>
                        <CardHeader className="flex flex-row items-start justify-between gap-4">
                            <div className="grid gap-1.5">
                                <CardTitle>{event.title}</CardTitle>
                                <CardDescription className="flex items-center gap-1.5">
                                    <CalendarDays className="size-4" /> {date(event.starts_at)}
                                    <span className="ms-2 rounded-full bg-secondary px-2 py-0.5 text-xs text-secondary-foreground">{t(`status.${event.status}`)}</span>
                                </CardDescription>
                            </div>
                            {event.preview_url && (
                                <Button variant="outline" size="sm" asChild>
                                    <a href={event.preview_url} target="_blank" rel="noopener">
                                        <ExternalLink /> {t('open_invite')}
                                    </a>
                                </Button>
                            )}
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                {(['invited', 'opened', 'replied', 'attending'] as const).map((key) => (
                                    <div key={key} className="rounded-lg bg-muted p-3">
                                        <dt className="text-xs text-muted-foreground">{t(key)}</dt>
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
