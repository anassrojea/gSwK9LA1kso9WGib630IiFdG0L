import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useEffect } from 'react';
import { toast } from 'sonner';

import { CheckoutPanel } from '@/components/checkout/CheckoutPanel';
import { OrderPanel } from '@/components/checkout/OrderPanel';
import { Toaster } from '@/components/ui/sonner';
import { TierBadge } from '@/components/wizard/TierBadge';
import AppLayout from '@/layouts/AppLayout';
import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';
import type { CheckoutOptions, OrderView } from '@/types/payments';

interface Props {
    event: { id: string; title: string; status: string };
    plan: string;
    checkout: CheckoutOptions;
    order: OrderView | null;
    urls: { dashboard: string; orders: string };
    errors?: Record<string, string>;
}

/**
 * GET /dashboard/events/{event}/upgrade: a bigger plan or add-ons for a live event.
 */
export default function Upgrade({ event, plan, checkout, order, urls, errors }: Props) {
    const { t } = useT();
    const { flash } = usePage<SharedProps>().props;

    useEffect(() => {
        if (flash?.warning) toast.error(flash.warning);
    }, [flash?.warning]);

    return (
        <AppLayout>
            <Head title={t('checkout.upgrade_title')} />
            <Toaster />
            <div className="mx-auto grid max-w-2xl gap-5">
                <Link href={urls.dashboard} className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1 text-sm">
                    <ArrowLeft className="size-4 rtl:rotate-180" /> <bdi>{event.title}</bdi>
                </Link>
                <div className="grid gap-2">
                    <h1 className="text-2xl font-bold">{t('checkout.upgrade_title')}</h1>
                    <p className="text-muted-foreground flex flex-wrap items-center gap-2 text-sm">
                        {t('checkout.upgrade_subtitle')} <TierBadge tier={plan} />
                    </p>
                </div>
                {order && order.status === 'receipt_received' ? <OrderPanel order={order} /> : <CheckoutPanel options={checkout} from="dashboard" errors={errors} />}
                {order && order.status === 'awaiting_payment' && <OrderPanel order={order} compact />}
            </div>
        </AppLayout>
    );
}
