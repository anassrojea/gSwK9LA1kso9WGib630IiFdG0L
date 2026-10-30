import { Head, Link, useForm } from '@inertiajs/react';
import { Building2, Plus, Users } from 'lucide-react';
import type { FormEvent } from 'react';

import { FieldError } from '@/components/FieldError';
import { OrgLayout } from '@/components/org/OrgLayout';
import { QuotaCard } from '@/components/org/QuotaCard';
import { VenueCalendar } from '@/components/org/VenueCalendar';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { useT } from '@/lib/i18n';
import type { CalendarEvent, HallSummary, Member, Org } from '@/types/org';

interface Props {
    org: Org;
    month: string;
    events: CalendarEvent[];
    team: Member[];
    halls: HallSummary[];
    occasions: string[];
}

/**
 * GET /org/{org}: the venue dashboard (plan section 4.2): the month's events,
 * this month's signature quota, a new client event, the team and the halls.
 */
export default function OrgShow({ org, month, events, team, halls, occasions }: Props) {
    const { t, n, dir, sep } = useT();
    const form = useForm({ occasion: 'wedding' });

    const create = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/org/${org.id}/events`);
    };

    return (
        <OrgLayout org={org} active="dashboard">
            <Head title={`${org.name} · ${t('org.dashboard.title')}`} />
            <div className="grid gap-5 lg:grid-cols-[1fr_320px]">
                <div className="grid min-w-0 content-start gap-5">
                    <VenueCalendar events={events} month={month} baseUrl={org.urls.dashboard} />
                </div>

                <aside className="grid content-start gap-4">
                    <QuotaCard quota={org.quota} />

                    {org.can.create_events && (
                        <form onSubmit={create} className="grid gap-3 rounded-xl border bg-white p-4" data-new-event>
                            <h2 className="text-sm font-semibold">{t('org.dashboard.new_event')}</h2>
                            <div className="grid gap-2">
                                <Label htmlFor="new-occasion">{t('org.dashboard.occasion')}</Label>
                                <NativeSelect id="new-occasion" dir={dir} value={form.data.occasion} onChange={(e) => form.setData('occasion', e.target.value)}>
                                    {occasions.map((o) => (
                                        <option key={o} value={o}>
                                            {t(`wizard.occasions.${o}`)}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </div>
                            <Button type="submit" className="h-11" disabled={form.processing}>
                                <Plus /> {t('org.dashboard.create')}
                            </Button>
                            <FieldError message={form.errors.occasion} />
                        </form>
                    )}

                    <section className="grid gap-3 rounded-xl border bg-white p-4" data-team-list>
                        <div className="flex items-center justify-between gap-2">
                            <h2 className="flex items-center gap-2 text-sm font-semibold">
                                <Users className="size-4" /> {t('org.dashboard.team')}
                            </h2>
                            <Link href={org.urls.team} className="text-primary text-xs font-medium hover:underline">
                                {t('org.dashboard.manage_team')}
                            </Link>
                        </div>
                        <ul className="grid gap-2">
                            {team.map((m) => (
                                <li key={m.user_id} className="flex items-center justify-between gap-2 text-sm">
                                    <span className="min-w-0 truncate">
                                        <bdi>{m.name}</bdi>
                                    </span>
                                    <span className="bg-muted shrink-0 rounded-full px-2 py-0.5 text-xs">{t(`org.roles.${m.role}`)}</span>
                                </li>
                            ))}
                        </ul>
                    </section>

                    <section className="grid gap-3 rounded-xl border bg-white p-4" data-hall-list>
                        <div className="flex items-center justify-between gap-2">
                            <h2 className="flex items-center gap-2 text-sm font-semibold">
                                <Building2 className="size-4" /> {t('org.dashboard.halls')}
                            </h2>
                            <Link href={org.urls.halls} className="text-primary text-xs font-medium hover:underline">
                                {t('org.dashboard.all_halls')}
                            </Link>
                        </div>
                        {halls.length === 0 ? (
                            <p className="text-muted-foreground text-sm">{t('org.dashboard.no_halls')}</p>
                        ) : (
                            <ul className="grid gap-2">
                                {halls.map((h) => (
                                    <li key={h.id}>
                                        <Link href={h.url} className="hover:bg-muted/50 grid gap-0.5 rounded-lg border p-2 text-sm">
                                            <span className="font-medium">{h.name}</span>
                                            <span className="text-muted-foreground text-xs">
                                                {[
                                                    t('org.dashboard.tables', { count: n(h.tables ?? 0) }),
                                                    t('org.dashboard.seats', { count: n(h.seats ?? 0) }),
                                                    t('org.dashboard.used_by', { count: n(h.events) }),
                                                ].join(sep)}
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {org.can.create_events && (
                            <Button asChild variant="outline" size="sm" className="h-9">
                                <Link href="/dashboard/halls/create">
                                    <Plus /> {t('org.dashboard.new_hall')}
                                </Link>
                            </Button>
                        )}
                    </section>
                </aside>
            </div>
        </OrgLayout>
    );
}
