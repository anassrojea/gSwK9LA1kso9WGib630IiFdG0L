import { Head, Link, router, useForm } from '@inertiajs/react';
import { CalendarDays, Crown, DoorOpen, ExternalLink, FileDown, LayoutDashboard, Lock, Pencil, Radio, Send, UserRound, Users } from 'lucide-react';
import { useCallback, useRef, type FormEvent, type ReactNode } from 'react';

import { FieldError } from '@/components/FieldError';
import { OrgLayout } from '@/components/org/OrgLayout';
import { MealCounts } from '@/components/seating/MealCounts';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useEventChannel } from '@/hooks/useEventChannel';
import { ExtraTranslations, useT } from '@/lib/i18n';
import type { Translations } from '@/types';
import type { Org } from '@/types/org';
import type { Meals } from '@/types/seating';

type Bucket = { expected: number; arrived: number; families: number; capacity: number };

interface Props {
    org: Org;
    event: {
        id: string;
        title: string;
        occasion: string;
        status: string;
        starts_at: string | null;
        timezone: string | null;
        venue_name: string | null;
        hall: string | null;
        published: boolean;
        plan: string;
        org_quota_at: string | null;
    };
    stats: Record<string, number | boolean | null>;
    meals: Meals;
    arrivals: { sections: Record<'men' | 'women' | 'none', Bucket>; total: Bucket };
    client: { name: string; email: string | null } | null;
    client_invitation: { email: string; expires_at: string } | null;
    can: { update: boolean; invite_client: boolean };
    features: { seating: boolean; qr_checkin: boolean };
    urls: { dashboard: string | null; edit: string; seating: string; seating_pdf: string; door: string; client: string };
    seating_translations: Translations;
}

/**
 * GET /org/{org}/events/{event}: the venue's view of one event (plan section 4.2):
 * guests and RSVPs, meal counts per section, arrivals live over Reverb, the
 * seating chart PDF, and the client who edits the invitation.
 */
export default function OrgEvent({ org, event, stats, meals, arrivals, client, client_invitation, can, features, urls, seating_translations }: Props) {
    const { t, n, locale, sep } = useT();
    const intl = locale === 'ar' ? 'ar-u-nu-arab' : 'en-GB';
    const form = useForm({ email: '' });
    const timer = useRef<number | null>(null);

    // any RSVP, open or check-in: refresh the numbers (debounced, partial reload)
    const refresh = useCallback(() => {
        if (timer.current) window.clearTimeout(timer.current);
        timer.current = window.setTimeout(() => router.reload({ only: ['stats', 'meals', 'arrivals'] }), 800);
    }, []);
    const live = useEventChannel(event.id, refresh, refresh);

    const when = event.starts_at
        ? new Intl.DateTimeFormat(intl, { dateStyle: 'full', timeStyle: 'short', timeZone: event.timezone || undefined }).format(new Date(event.starts_at))
        : t('org.calendar.no_date');

    const invite = (e: FormEvent) => {
        e.preventDefault();
        form.post(urls.client, { preserveScroll: true, onSuccess: () => form.reset() });
    };

    const card = (key: string, label: string, value: number, accent = false) => (
        <div key={key} className={accent ? 'col-span-2 rounded-lg bg-emerald-50 p-3 sm:col-span-1' : 'bg-muted rounded-lg p-3'} data-stat={key}>
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="text-2xl font-semibold tabular-nums">{n(value)}</dd>
        </div>
    );

    return (
        <OrgLayout org={org} active={null}>
            <Head title={event.title} />
            <div className="grid gap-5">
                <section className="grid gap-3 rounded-xl border bg-white p-4 sm:p-5">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="grid gap-1">
                            <Link href={org.urls.dashboard} className="text-muted-foreground text-xs hover:underline">
                                {t('org.nav.dashboard')}
                            </Link>
                            <h2 className="text-2xl font-bold" data-event-title>
                                {event.title}
                            </h2>
                            <p className="text-muted-foreground flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                                <CalendarDays className="size-4" /> {[when, event.hall].filter(Boolean).join(sep)}
                            </p>
                            <p className="flex flex-wrap gap-2 text-xs">
                                {!event.published && <span className="bg-muted rounded-full px-2 py-0.5">{t('org.event.not_published')}</span>}
                                {event.org_quota_at && (
                                    <span className="inline-flex items-center gap-1 rounded-full bg-violet-100 px-2 py-0.5 text-violet-900">
                                        <Crown className="size-3" /> {t('org.event.signature_slot')}
                                    </span>
                                )}
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {urls.dashboard && (
                                <Button asChild size="sm">
                                    <Link href={urls.dashboard}>
                                        <LayoutDashboard /> {t('org.event.open_dashboard')}
                                    </Link>
                                </Button>
                            )}
                            {can.update && (
                                <Button asChild size="sm" variant="outline">
                                    <Link href={urls.edit}>
                                        <Pencil /> {t('org.event.edit')}
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </div>
                    <dl className="grid grid-cols-2 gap-3 sm:grid-cols-5" data-event-stats>
                        {card('invited', t('org.event.guests'), Number(stats.invited ?? 0))}
                        {card('replied', t('org.event.replied'), Number(stats.replied ?? 0))}
                        {card('attending', t('org.event.attending'), Number(stats.attending ?? 0))}
                        {card('declined', t('org.event.declined'), Number(stats.declined ?? 0))}
                        {card('arrived', t('org.event.arrived'), Number(stats.arrived_people ?? 0), true)}
                    </dl>
                </section>

                <div className="grid gap-5 lg:grid-cols-2">
                    <ExtraTranslations.Provider value={seating_translations}>
                        <MealCounts meals={meals} />
                    </ExtraTranslations.Provider>

                    <section className="grid content-start gap-3 rounded-xl border bg-white p-4" data-arrivals>
                        <div className="flex items-center justify-between gap-2">
                            <h2 className="flex items-center gap-2 font-bold">
                                <DoorOpen className="text-primary size-4" /> {t('org.event.arrivals_by_section')}
                            </h2>
                            <span className={live === 'live' ? 'inline-flex items-center gap-1 text-xs text-emerald-700' : 'text-muted-foreground text-xs'} data-live={live}>
                                <Radio className="size-3" /> {live === 'live' ? t('org.event.live') : t('org.event.offline')}
                            </span>
                        </div>
                        <ul className="grid gap-3">
                            {(['men', 'women', 'none'] as const)
                                .filter((k) => arrivals.sections[k].expected > 0 || arrivals.sections[k].arrived > 0 || arrivals.sections[k].capacity > 0)
                                .map((k) => {
                                    const s = arrivals.sections[k];
                                    const pct = s.expected > 0 ? Math.min(100, Math.round((s.arrived / s.expected) * 100)) : 0;
                                    return (
                                        <li key={k} className="grid gap-1" data-arrival-section={k}>
                                            <div className="flex items-baseline justify-between gap-2 text-sm">
                                                <span className="font-medium">{t(`org.event.sections.${k}`)}</span>
                                                <span className="tabular-nums">
                                                    {t('org.event.arrived_of', { arrived: s.arrived, expected: s.expected })}
                                                    {s.capacity > 0 && (
                                                        <span className="text-muted-foreground text-xs">
                                                            {sep}
                                                            {t('org.event.capacity_of', { count: s.capacity })}
                                                        </span>
                                                    )}
                                                </span>
                                            </div>
                                            <div className="bg-muted h-2 overflow-hidden rounded-full">
                                                <div className="h-full bg-emerald-500" style={{ width: `${pct}%` }} />
                                            </div>
                                        </li>
                                    );
                                })}
                        </ul>
                        <div className="flex flex-wrap gap-2 border-t pt-3">
                            <Linked show={features.qr_checkin} href={urls.door} icon={<DoorOpen />} label={t('org.event.door')} />
                            <Linked show={features.seating} href={urls.seating} icon={<Users />} label={t('org.event.seating')} />
                            <Linked show={features.seating} href={urls.seating_pdf} icon={<FileDown />} label={t('org.event.seating_pdf')} external />
                        </div>
                    </section>
                </div>

                <section className="grid gap-3 rounded-xl border bg-white p-4" data-client>
                    <h2 className="flex items-center gap-2 font-bold">
                        <UserRound className="text-primary size-4" /> {t('org.event.client')}
                    </h2>
                    {client ? (
                        <p className="text-sm" data-client-joined>
                            {t('org.event.client_joined', { name: client.name })}{' '}
                            {client.email && (
                                <bdi dir="ltr" className="text-muted-foreground">
                                    {client.email}
                                </bdi>
                            )}
                        </p>
                    ) : (
                        <>
                            <p className="text-muted-foreground text-sm">{t('org.event.client_text')}</p>
                            {client_invitation && (
                                <p className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-950" data-client-pending>
                                    {t('org.event.client_pending', {
                                        email: client_invitation.email,
                                        date: new Intl.DateTimeFormat(intl, { day: 'numeric', month: 'long' }).format(new Date(client_invitation.expires_at)),
                                    })}
                                </p>
                            )}
                            {can.invite_client && (
                                <form onSubmit={invite} className="flex flex-col gap-2 sm:flex-row sm:items-end">
                                    <div className="grid flex-1 gap-2">
                                        <Label htmlFor="client-email">{t('org.event.client_email')}</Label>
                                        <Input
                                            id="client-email"
                                            type="email"
                                            dir="ltr"
                                            className="h-11 text-start"
                                            value={form.data.email}
                                            onChange={(e) => form.setData('email', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <Button type="submit" className="h-11" disabled={form.processing}>
                                        <Send className="rtl:-scale-x-100" /> {t('org.event.client_invite')}
                                    </Button>
                                </form>
                            )}
                            <FieldError message={form.errors.email} />
                        </>
                    )}
                </section>
            </div>
        </OrgLayout>
    );
}

function Linked({ show, href, icon, label, external = false }: { show: boolean; href: string; icon: ReactNode; label: string; external?: boolean }) {
    const { t } = useT();

    if (!show) {
        return (
            <span className="text-muted-foreground inline-flex h-9 items-center gap-1.5 rounded-md border border-dashed px-3 text-sm" title={t('org.event.locked')}>
                <Lock className="size-4" /> {label}
            </span>
        );
    }

    return (
        <Button asChild variant="outline" size="sm" className="h-9">
            {external ? (
                <a href={href} target="_blank" rel="noopener" data-seating-pdf>
                    {icon} {label} <ExternalLink className="size-3" />
                </a>
            ) : (
                <Link href={href}>
                    {icon} {label}
                </Link>
            )}
        </Button>
    );
}
