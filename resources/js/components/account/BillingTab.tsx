import { Link } from '@inertiajs/react';
import { Check, ChevronLeft, Gem, Plus, Receipt } from 'lucide-react';

import { Section } from '@/components/account/Section';
import { OrderPanel, OrderStatusBadge } from '@/components/checkout/OrderPanel';
import { Button } from '@/components/ui/button';
import { TierBadge } from '@/components/wizard/TierBadge';
import { useT } from '@/lib/i18n';
import type { BillingData, BillingEvent } from '@/types/account';

function EventPlan({ event }: { event: BillingEvent }) {
    const { t, n, sep } = useT();

    return (
        <li className="grid gap-3 py-4 first:pt-0 last:pb-0" data-billing-event={event.id}>
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="grid min-w-0 gap-1">
                    <Link href={event.url} className="truncate font-semibold hover:underline">
                        <bdi>{event.title}</bdi>
                    </Link>
                    <span className="text-muted-foreground flex flex-wrap items-center gap-2 text-xs">
                        <TierBadge tier={event.plan} />
                        {t(`status.${event.status}`)}
                        {event.guests !== null && (
                            <>
                                {sep}
                                {t('account.billing.guests', { count: event.guests })}
                            </>
                        )}
                    </span>
                </div>
                {event.upgrade_url && (
                    <Button asChild size="sm" className="h-9">
                        <Link href={event.upgrade_url} data-billing-upgrade>
                            <Gem /> {t('account.billing.upgrade')}
                        </Link>
                    </Button>
                )}
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="grid gap-1.5">
                    <span className="text-muted-foreground text-xs font-medium">{t('account.billing.includes')}</span>
                    <ul className="flex flex-wrap gap-1.5 text-xs">
                        {event.features.slice(0, 4).map((f) => (
                            <li key={f} className="bg-muted inline-flex items-center gap-1 rounded-full px-2 py-1">
                                <Check className="size-3.5 shrink-0 text-emerald-600" /> {t(`account.features.${f}`)}
                            </li>
                        ))}
                        {event.features.length > 4 && <li className="text-muted-foreground px-1 py-1">+{n(event.features.length - 4)}</li>}
                    </ul>
                </div>
                {event.next && event.unlocks.length > 0 ? (
                    <div className="grid content-start gap-1.5 rounded-lg bg-amber-50/70 p-3">
                        <span className="text-xs font-medium text-amber-900">{t('account.billing.adds', { plan: t(`wizard.tiers.${event.next}`) })}</span>
                        <ul className="grid gap-1 text-sm text-amber-950">
                            {event.unlocks.slice(0, 4).map((f) => (
                                <li key={f} className="flex items-center gap-1.5">
                                    <Plus className="size-4 shrink-0" /> {t(`account.features.${f}`)}
                                </li>
                            ))}
                            {event.next_guests !== null && (
                                <li className="flex items-center gap-1.5">
                                    <Plus className="size-4 shrink-0" /> {t('account.billing.guests', { count: event.next_guests })}
                                </li>
                            )}
                        </ul>
                    </div>
                ) : (
                    !event.next && <p className="text-muted-foreground self-end text-xs">{t('account.billing.top_plan')}</p>
                )}
            </div>
        </li>
    );
}

export function BillingTab({ billing }: { billing: BillingData }) {
    const { t, locale, sep } = useT();
    const date = (iso: string | null) => (iso ? new Intl.DateTimeFormat(locale === 'ar' ? 'ar-u-nu-arab' : 'en-GB', { dateStyle: 'medium' }).format(new Date(iso)) : '');

    return (
        <div className="grid gap-5">
            {billing.pending.length > 0 && (
                <Section title={t('account.billing.pending_title')} description={t('account.billing.pending_text')} name="pending">
                    <div className="grid gap-4">
                        {billing.pending.map((order) => (
                            <OrderPanel key={order.code} order={order} compact />
                        ))}
                    </div>
                </Section>
            )}

            <Section title={t('account.billing.events_title')} name="events">
                {billing.events.length === 0 ? (
                    <p className="text-muted-foreground text-sm">{t('account.billing.events_empty')}</p>
                ) : (
                    <ul className="divide-y">
                        {billing.events.map((e) => (
                            <EventPlan key={e.id} event={e} />
                        ))}
                    </ul>
                )}
            </Section>

            <Section title={t('account.billing.orders_title')} name="orders">
                {billing.orders.length === 0 ? (
                    <p className="text-muted-foreground text-sm">{t('account.billing.orders_empty')}</p>
                ) : (
                    <ul className="grid gap-2">
                        {billing.orders.map((order) => (
                            <li key={order.code}>
                                <Link href={order.url} className="hover:bg-muted/60 flex items-center gap-3 rounded-lg border p-3 transition-colors" data-billing-order={order.code}>
                                    <Receipt className="text-muted-foreground size-5 shrink-0" />
                                    <div className="grid min-w-0 flex-1 gap-0.5">
                                        <span className="flex flex-wrap items-center gap-2">
                                            <bdi className="font-mono text-sm font-semibold" dir="ltr">
                                                {order.code}
                                            </bdi>
                                            <OrderStatusBadge status={order.status} />
                                        </span>
                                        <span className="text-muted-foreground truncate text-xs">
                                            {order.event?.title ? (
                                                <>
                                                    <bdi>{order.event.title}</bdi>
                                                    {sep}
                                                </>
                                            ) : null}
                                            {date(order.created_at)}
                                        </span>
                                    </div>
                                    <span className="shrink-0 text-sm font-semibold">{order.total_formatted}</span>
                                    <ChevronLeft className="text-muted-foreground size-4 shrink-0 ltr:rotate-180" />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
                <Button asChild variant="outline" className="h-10 w-full sm:w-fit">
                    <Link href={billing.orders_url}>{t('account.billing.orders_all')}</Link>
                </Button>
            </Section>
        </div>
    );
}
