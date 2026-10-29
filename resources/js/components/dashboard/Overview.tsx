import { BellRing, CalendarClock, Eye, MailCheck, Armchair, UserCheck, Users } from 'lucide-react';
import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { percent, type Stats } from '@/lib/dashboard/guests';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

function StatCard({ icon, label, value, hint, extra, tone, name }: { icon: ReactNode; label: string; value: string; hint?: string; extra?: string; tone?: string; name: string }) {
    return (
        <div className={cn('bg-card flex min-w-0 flex-col gap-1 rounded-xl border p-3 shadow-xs sm:p-4', tone)} data-stat={name}>
            <dt className="text-muted-foreground flex items-center gap-1.5 text-xs font-medium sm:text-sm [&_svg]:size-4 [&_svg]:shrink-0">
                {icon}
                <span className="leading-snug">{label}</span>
            </dt>
            <dd className="text-2xl leading-tight font-bold tabular-nums sm:text-3xl" data-value>
                {value}
            </dd>
            {hint && <dd className="text-muted-foreground text-xs leading-snug">{hint}</dd>}
            {extra && <dd className="text-muted-foreground text-xs leading-snug">{extra}</dd>}
        </div>
    );
}

/**
 * Top cards (plan section 4.1): invited families, opened, replied,
 * attending people, seats left, days left.
 */
export function StatCards({ stats }: { stats: Stats }) {
    const { t, tc, n } = useT();

    const days =
        stats.days_left === null
            ? t('dashboard.cards.no_date')
            : stats.days_left < 0
              ? t('dashboard.cards.passed')
              : stats.days_left === 0
                ? t('dashboard.cards.today')
                : n(stats.days_left);

    return (
        <dl className="grid grid-cols-2 gap-2 sm:grid-cols-3 sm:gap-3 xl:grid-cols-6">
            <StatCard name="invited" icon={<Users />} label={t('dashboard.cards.invited')} value={n(stats.invited)} hint={tc('wizard.guests.seat_count', stats.seats_allowed)} />
            <StatCard
                name="opened"
                icon={<Eye />}
                label={t('dashboard.cards.opened')}
                value={n(stats.opened)}
                hint={t('dashboard.cards.of_invited', { percent: percent(stats.opened, stats.invited) })}
            />
            <StatCard
                name="replied"
                icon={<MailCheck />}
                label={t('dashboard.cards.replied')}
                value={n(stats.replied)}
                hint={t('dashboard.cards.of_invited', { percent: percent(stats.replied, stats.invited) })}
            />
            <StatCard
                name="attending"
                icon={<UserCheck />}
                label={t('dashboard.cards.attending')}
                value={n(stats.attending)}
                hint={tc('wizard.guests.families', stats.attending_families)}
                tone="border-emerald-200 bg-emerald-50/60 dark:bg-emerald-950/20"
            />
            <StatCard
                name="seats_left"
                icon={<Armchair />}
                label={t('dashboard.cards.seats_left')}
                value={n(stats.seats_left)}
                hint={t('dashboard.cards.seats_hint', { seats: stats.seats_allowed })}
                extra={
                    stats.guest_limit === null
                        ? t('dashboard.cards.plan_unlimited')
                        : t('dashboard.cards.plan_left', { left: stats.limit_left ?? 0, limit: stats.guest_limit })
                }
            />
            <StatCard
                name="days_left"
                icon={<CalendarClock />}
                label={t('dashboard.cards.days_left')}
                value={days}
                tone={stats.is_event_day ? 'border-primary/40 bg-primary/5' : undefined}
            />
        </dl>
    );
}

/**
 * Funnel bar: opened → replied → attending, with "opened but didn't reply"
 * highlighted, because those are the families worth a reminder.
 */
export function Funnel({ stats, onRemind }: { stats: Stats; onRemind?: () => void }) {
    const { t, n, locale } = useT();
    const total = Math.max(stats.invited, 1);
    const pct = (value: number) => (locale === 'ar' ? `${n(value)}٪` : `${value}%`);
    const steps = [
        { key: 'opened', value: stats.opened, className: 'bg-sky-500' },
        { key: 'replied', value: stats.replied, className: 'bg-violet-500' },
        { key: 'attending', value: stats.attending_families, className: 'bg-emerald-500' },
    ] as const;

    return (
        <section className="bg-card grid gap-3 rounded-xl border p-4 shadow-xs" aria-labelledby="funnel-title">
            <h2 id="funnel-title" className="font-semibold">
                {t('dashboard.funnel.title')}
            </h2>
            <div className="grid gap-2">
                {steps.map((step) => (
                    <div key={step.key} className="grid grid-cols-[6.5rem_1fr_auto] items-center gap-2 text-sm sm:grid-cols-[8rem_1fr_auto]" data-funnel={step.key}>
                        <span className="text-muted-foreground truncate">{t(`dashboard.funnel.${step.key}`)}</span>
                        <div className="bg-muted h-3 overflow-hidden rounded-full" role="presentation">
                            <div className={cn('h-full rounded-full transition-[width] duration-500', step.className)} style={{ width: `${percent(step.value, total)}%` }} />
                        </div>
                        <span className="min-w-[4.5rem] text-end font-semibold tabular-nums">
                            {n(step.value)} <span className="text-muted-foreground text-xs font-normal">({pct(percent(step.value, stats.invited))})</span>
                        </span>
                    </div>
                ))}
            </div>
            <div className="flex flex-wrap items-center gap-2">
                <div
                    className={cn(
                        'flex items-center gap-2 rounded-lg px-3 py-2 text-sm',
                        stats.opened_not_replied > 0 ? 'bg-amber-100 text-amber-950 dark:bg-amber-900/40 dark:text-amber-100' : 'bg-muted text-muted-foreground',
                    )}
                    data-opened-not-replied
                >
                    <b className="text-lg tabular-nums">{n(stats.opened_not_replied)}</b>
                    <span>{t('dashboard.funnel.opened_not_replied')}</span>
                </div>
                <div className="bg-muted text-muted-foreground flex items-center gap-2 rounded-lg px-3 py-2 text-sm">
                    <b className="text-foreground text-lg tabular-nums">{n(stats.never_opened)}</b>
                    <span>{t('dashboard.funnel.never_opened')}</span>
                </div>
                {onRemind && stats.opened_not_replied + stats.never_opened > 0 && (
                    <Button variant="outline" size="sm" className="ms-auto h-9" onClick={onRemind}>
                        <BellRing /> {t('dashboard.funnel.chase')}
                    </Button>
                )}
            </div>
        </section>
    );
}
