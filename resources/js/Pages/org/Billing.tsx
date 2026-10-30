import { Head, Link, router } from '@inertiajs/react';
import { Check, CreditCard, ExternalLink, Landmark, RotateCcw, X } from 'lucide-react';
import { useState, type ReactNode } from 'react';

import { InvoiceLinks } from '@/components/checkout/InvoiceLinks';
import { OrgLayout } from '@/components/org/OrgLayout';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { useT } from '@/lib/i18n';
import { chargeCurrency } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { Interval, Org, Tier } from '@/types/org';
import type { OrderSummary } from '@/types/payments';

interface Props {
    org: Org;
    currency: string;
    currencies: string[];
    tiers: Tier[];
    methods: Record<string, string[]>;
    renewal: { code: string; status: string; url: string } | null;
    pending: { code: string } | null;
    orders: OrderSummary[];
    checkout: string | null;
}

/**
 * GET /org/{org}/billing (phase 8A): Venue & Planner Pro tiers, monthly or
 * yearly, by card or bank transfer; the live subscription with change,
 * cancel / resume and the provider's portal; the org's orders and invoices.
 */
export default function OrgBilling({ org, currency, currencies, tiers, methods, renewal, pending, orders, checkout }: Props) {
    const { t, n, locale, dir, sep } = useT();
    const sub = org.subscription;
    const [interval, setPeriod] = useState<Interval>(sub?.interval ?? 'month');
    const methodIds = Object.keys(methods);
    const [method, setMethod] = useState(methodIds[0] ?? '');
    const [busy, setBusy] = useState(false);
    const intl = locale === 'ar' ? 'ar-u-nu-arab' : 'en-GB';
    const date = (iso: string | null) => (iso ? new Intl.DateTimeFormat(intl, { dateStyle: 'long' }).format(new Date(iso)) : '');
    const base = `/org/${org.id}/billing`;
    const charge = method ? chargeCurrency(methods[method] ?? [], currency) : null;
    const anyPlaceholder = tiers.some((tier) => tier[interval]?.placeholder);

    const post = (url: string, data: Record<string, string> = {}) => {
        setBusy(true);
        router.post(url, data, { preserveScroll: true, onFinish: () => setBusy(false) });
    };
    const subscribe = (planId: string) => post(`${base}/subscribe`, { plan: planId, gateway: method, currency });

    return (
        <OrgLayout org={org} active="billing">
            <Head title={`${org.name} · ${t('org.billing.title')}`} />
            <div className="grid gap-5">
                {checkout === 'success' && <p className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{t('org.billing.checkout_success')}</p>}
                {checkout === 'cancelled' && <p className="bg-muted rounded-lg border px-4 py-3 text-sm">{t('org.billing.checkout_cancelled')}</p>}

                {sub && (
                    <section className="grid gap-3 rounded-xl border bg-white p-4 sm:p-5" data-subscription={sub.status}>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-lg font-bold">{t('org.billing.status_title')}</h2>
                            <span
                                className={cn(
                                    'rounded-full px-2.5 py-0.5 text-xs font-medium',
                                    sub.status === 'past_due' ? 'bg-amber-100 text-amber-900' : 'bg-emerald-100 text-emerald-900',
                                )}
                            >
                                {t(`org.billing.statuses.${sub.status}`)}
                            </span>
                        </div>
                        <p className="text-xl font-semibold">
                            {t(`org.plans.${sub.tier}.name`)}
                            {sep}
                            {t(`org.billing.intervals.${sub.interval}`)}
                        </p>
                        <p className="text-muted-foreground text-sm">
                            {t(`org.billing.providers.${sub.provider}`)}
                            {sep}
                            {sub.status === 'past_due'
                                ? t('org.billing.past_due', { date: date(sub.grace_until) })
                                : sub.cancel_at_period_end
                                  ? t('org.billing.ends', { date: date(sub.current_period_end) })
                                  : t('org.billing.renews', { date: date(sub.current_period_end) })}
                        </p>
                        {sub.next_plan_id && (
                            <p className="text-sm">{t('org.billing.next_plan', { plan: t(`org.plans.${sub.next_plan_id.replace(/_yearly$/, '')}.name`) })}</p>
                        )}
                        <div className="flex flex-wrap gap-2">
                            {sub.provider === 'transfer' && renewal && (
                                <Button asChild size="sm" variant="outline" className="h-10">
                                    <Link href={renewal.url} data-renewal-order>
                                        <Landmark /> {t('org.billing.renewal_order', { code: renewal.code })}
                                    </Link>
                                </Button>
                            )}
                            {sub.provider === 'transfer' && !sub.cancel_at_period_end && (!renewal || renewal.status === 'expired') && (
                                <Button size="sm" variant="outline" className="h-10" disabled={busy} onClick={() => post(`${base}/renew`)} data-renew-now>
                                    <Landmark /> {t('org.billing.renew_now')}
                                </Button>
                            )}
                            {sub.provider !== 'transfer' && (
                                <Button asChild size="sm" variant="outline" className="h-10">
                                    <a href={`${base}/portal`} data-portal>
                                        <CreditCard /> {t('org.billing.portal')} <ExternalLink className="size-3" />
                                    </a>
                                </Button>
                            )}
                            {sub.cancel_at_period_end ? (
                                <Button size="sm" className="h-10" disabled={busy} onClick={() => post(`${base}/resume`)} data-resume>
                                    <RotateCcw /> {t('org.billing.resume')}
                                </Button>
                            ) : (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="text-destructive h-10"
                                    disabled={busy}
                                    onClick={() => window.confirm(t('org.billing.cancel_confirm')) && post(`${base}/cancel`)}
                                    data-cancel
                                >
                                    <X /> {t('org.billing.cancel')}
                                </Button>
                            )}
                        </div>
                    </section>
                )}

                <section className="grid gap-4" data-tiers>
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div className="grid gap-1">
                            <h2 className="text-lg font-bold">{t('org.billing.title')}</h2>
                            <p className="text-muted-foreground max-w-2xl text-sm">{t('org.billing.subtitle')}</p>
                        </div>
                        <div className="bg-muted flex rounded-lg p-1" role="group">
                            {(['month', 'year'] as const).map((i) => (
                                <Button key={i} size="sm" variant={interval === i ? 'default' : 'ghost'} className="h-9" onClick={() => setPeriod(i)} aria-pressed={interval === i} data-interval={i}>
                                    {t(i === 'month' ? 'org.billing.monthly' : 'org.billing.yearly')}
                                    {i === 'year' && <span className="rounded bg-emerald-100 px-1 text-[10px] text-emerald-900">{t('org.billing.yearly_save')}</span>}
                                </Button>
                            ))}
                        </div>
                    </div>

                    {!sub && (
                        <div className="grid gap-3 rounded-xl border bg-white p-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="billing-currency">{t('org.billing.currency')}</Label>
                                <NativeSelect
                                    id="billing-currency"
                                    dir={dir}
                                    value={currency}
                                    onChange={(e) => router.get(base, { currency: e.target.value }, { preserveScroll: true, preserveState: true })}
                                >
                                    {currencies.map((c) => (
                                        <option key={c} value={c}>
                                            {c}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </div>
                            <fieldset className="grid gap-2">
                                <legend className="mb-2 text-sm font-medium">{t('org.billing.method')}</legend>
                                {methodIds.length === 0 && <p className="text-muted-foreground text-sm">{t('org.billing.no_methods')}</p>}
                                <div className="flex flex-wrap gap-2">
                                    {methodIds.map((id) => (
                                        <label
                                            key={id}
                                            className={cn('flex h-11 cursor-pointer items-center gap-2 rounded-lg border px-3 text-sm', method === id && 'border-primary bg-primary/5')}
                                            data-method={id}
                                        >
                                            <input type="radio" name="method" value={id} checked={method === id} onChange={() => setMethod(id)} className="accent-primary" />
                                            {id === 'transfer' ? <Landmark className="size-4" /> : <CreditCard className="size-4" />}
                                            {t(`org.billing.methods.${id}`)}
                                        </label>
                                    ))}
                                </div>
                                {charge && charge !== currency && <p className="text-muted-foreground text-xs">{t('org.billing.charged_in', { currency: charge })}</p>}
                            </fieldset>
                        </div>
                    )}

                    <ul className="grid gap-4 md:grid-cols-3">
                        {tiers.map((tier) => {
                            const price = tier[interval];
                            const current = sub?.plan_id === price?.plan_id;
                            const featured = tier.tier === 'venue_pro';
                            return (
                                <li
                                    key={tier.tier}
                                    className={cn('grid content-start gap-3 rounded-xl border bg-white p-5', featured && 'border-violet-300 ring-2 ring-violet-200', current && 'border-emerald-400')}
                                    data-tier={tier.tier}
                                >
                                    <div className="grid gap-1">
                                        <h3 className="text-lg font-bold">{t(`org.plans.${tier.tier}.name`)}</h3>
                                        <p className="text-muted-foreground text-sm">{t(`org.plans.${tier.tier}.text`)}</p>
                                    </div>
                                    <p className="text-3xl font-bold" data-price={price?.amount ?? ''}>
                                        {price?.formatted ?? '—'} <span className="text-muted-foreground text-sm font-normal">{t(`org.billing.per.${interval}`)}</span>
                                    </p>
                                    <ul className="grid gap-1.5 text-sm">
                                        <Feature on>{t('org.billing.limits.signature_events_per_month', { count: n(tier.limits.signature_events_per_month) })}</Feature>
                                        <Feature on>{t('org.billing.limits.team_seats', { count: n(tier.limits.team_seats) })}</Feature>
                                        <Feature on>
                                            {tier.limits.hall_layouts === null
                                                ? t('org.billing.limits.hall_layouts_unlimited')
                                                : t('org.billing.limits.hall_layouts', { count: n(tier.limits.hall_layouts) })}
                                        </Feature>
                                        <Feature on={tier.limits.white_label}>{t('org.billing.limits.white_label')}</Feature>
                                        <Feature on={tier.limits.directory_listing}>{t('org.billing.limits.directory_listing')}</Feature>
                                    </ul>
                                    {current ? (
                                        <span className="inline-flex h-11 items-center justify-center rounded-md bg-emerald-50 text-sm font-medium text-emerald-900">
                                            <Check className="me-1 size-4" /> {t('org.billing.current')}
                                        </span>
                                    ) : sub ? (
                                        <Button variant="outline" className="h-11" disabled={busy || !price} onClick={() => price && post(`${base}/change`, { plan: price.plan_id })} data-switch={price?.plan_id}>
                                            {t('org.billing.switch')}
                                        </Button>
                                    ) : (
                                        <Button
                                            className="h-11"
                                            variant={featured ? 'default' : 'outline'}
                                            disabled={busy || !price || !method || !!pending}
                                            onClick={() => price && subscribe(price.plan_id)}
                                            data-subscribe={price?.plan_id}
                                        >
                                            {t('org.billing.choose', { plan: t(`org.plans.${tier.tier}.name`) })}
                                        </Button>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                    {pending && !sub && (
                        <p className="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-950">
                            <Link href={`/dashboard/orders/${pending.code}`} className="font-medium underline">
                                {t('org.billing.pending_order', { code: pending.code })}
                            </Link>
                        </p>
                    )}
                    {anyPlaceholder && <p className="text-muted-foreground text-xs">{t('org.billing.placeholder')}</p>}
                </section>

                <section className="grid gap-3 rounded-xl border bg-white p-4 sm:p-5" data-org-orders>
                    <h2 className="text-lg font-bold">{t('org.billing.orders')}</h2>
                    {orders.length === 0 ? (
                        <p className="text-muted-foreground text-sm">{t('org.billing.no_orders')}</p>
                    ) : (
                        <ul className="divide-y">
                            {orders.map((o) => (
                                <li key={o.code} className="grid gap-2 py-3">
                                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                        <Link href={o.url} className="font-medium hover:underline">
                                            <bdi dir="ltr" className="font-mono">
                                                {o.code}
                                            </bdi>{' '}
                                            · {o.summary}
                                        </Link>
                                        <span className="flex items-center gap-2">
                                            <span className="font-semibold">{o.total_formatted}</span>
                                            <span className="bg-muted rounded-full px-2 py-0.5 text-xs">{t(`payments.status.${o.status}`)}</span>
                                        </span>
                                    </div>
                                    {o.invoices && o.invoices.length > 0 && <InvoiceLinks invoices={o.invoices} />}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </OrgLayout>
    );
}

function Feature({ on, children }: { on: boolean; children: ReactNode }) {
    return (
        <li className={cn('flex items-start gap-2', !on && 'text-muted-foreground line-through decoration-1')}>
            {on ? <Check className="mt-0.5 size-4 shrink-0 text-emerald-600" /> : <X className="mt-0.5 size-4 shrink-0" />}
            <span>{children}</span>
        </li>
    );
}
