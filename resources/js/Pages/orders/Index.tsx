import { Head, Link } from '@inertiajs/react';
import { ChevronLeft, Receipt } from 'lucide-react';

import { OrderStatusBadge } from '@/components/checkout/OrderPanel';
import AppLayout from '@/layouts/AppLayout';
import { useT } from '@/lib/i18n';
import type { OrderSummary } from '@/types/payments';

/**
 * GET /dashboard/orders: the host's orders, newest first.
 */
export default function OrdersIndex({ orders }: { orders: OrderSummary[] }) {
    const { t, locale, sep } = useT();
    const date = (iso: string | null) => (iso ? new Intl.DateTimeFormat(locale === 'ar' ? 'ar-u-nu-arab' : 'en-GB', { dateStyle: 'medium' }).format(new Date(iso)) : '');

    return (
        <AppLayout>
            <Head title={t('orders.title')} />
            <h1 className="mb-6 text-2xl font-bold">{t('orders.title')}</h1>

            {orders.length === 0 && <p className="text-muted-foreground">{t('orders.empty')}</p>}

            <ul className="grid gap-3" data-orders>
                {orders.map((order) => (
                    <li key={order.code}>
                        <Link href={order.url} className="bg-card hover:border-foreground/30 flex items-center gap-3 rounded-xl border p-4 transition-colors" data-order-row={order.code}>
                            <Receipt className="text-muted-foreground size-5 shrink-0" />
                            <div className="grid min-w-0 flex-1 gap-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <bdi className="font-mono font-semibold" dir="ltr">
                                        {order.code}
                                    </bdi>
                                    <OrderStatusBadge status={order.status} />
                                </div>
                                <span className="text-muted-foreground truncate text-sm">
                                    {order.event?.title ? (
                                        <>
                                            <bdi>{order.event.title}</bdi>
                                            {sep}
                                        </>
                                    ) : null}
                                    {order.summary}
                                    {sep}
                                    {date(order.created_at)}
                                </span>
                            </div>
                            <span className="shrink-0 font-semibold">{order.total_formatted}</span>
                            <ChevronLeft className="text-muted-foreground size-4 shrink-0 ltr:rotate-180" />
                        </Link>
                    </li>
                ))}
            </ul>
        </AppLayout>
    );
}
