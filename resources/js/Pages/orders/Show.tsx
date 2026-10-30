import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, ListOrdered } from 'lucide-react';
import { useEffect } from 'react';
import { toast } from 'sonner';

import { InvoiceLinks } from '@/components/checkout/InvoiceLinks';
import { OrderPanel } from '@/components/checkout/OrderPanel';
import { Button } from '@/components/ui/button';
import { Toaster } from '@/components/ui/sonner';
import AppLayout from '@/layouts/AppLayout';
import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';
import type { OrderView } from '@/types/payments';

/**
 * GET /dashboard/orders/{code}: transfer instructions, the waiting state,
 * a card retry, or the outcome. After a card checkout (?checkout=success) it
 * polls the order until the webhook has marked it paid.
 */
export default function OrderShow({ order, checkout }: { order: OrderView; checkout: string | null }) {
    const { t } = useT();
    const { flash } = usePage<SharedProps>().props;

    useEffect(() => {
        if (flash?.status) toast.success(flash.status);
        if (flash?.warning) toast.error(flash.warning);
    }, [flash?.status, flash?.warning]);

    return (
        <AppLayout>
            <Head title={t('payments.order', { code: order.code })} />
            <Toaster />
            <div className="mx-auto grid max-w-2xl gap-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <Link href={order.urls.back} className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm">
                        <ArrowLeft className="size-4 rtl:rotate-180" /> {t('payments.back')}
                    </Link>
                    <Button asChild variant="ghost" size="sm">
                        <Link href="/dashboard/orders">
                            <ListOrdered /> {t('payments.all_orders')}
                        </Link>
                    </Button>
                </div>
                {order.event && (
                    <p className="text-muted-foreground text-sm">
                        <bdi>{order.event.title}</bdi>
                    </p>
                )}
                <OrderPanel order={order} outcome={checkout} />
                <InvoiceLinks invoices={order.invoices ?? []} />
            </div>
        </AppLayout>
    );
}
