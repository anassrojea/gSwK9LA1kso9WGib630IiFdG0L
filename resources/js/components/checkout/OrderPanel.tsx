import { router } from '@inertiajs/react';
import { CheckCircle2, Clock, Copy, CreditCard, Hourglass, Loader2, MessageCircle, Undo2, XCircle } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { copyText } from '@/lib/clipboard';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { OrderStatus, OrderView } from '@/types/payments';

const STATUS_STYLE: Record<OrderStatus, string> = {
    awaiting_payment: 'bg-zinc-100 text-zinc-800',
    receipt_received: 'bg-amber-100 text-amber-900',
    paid: 'bg-emerald-100 text-emerald-900',
    approved: 'bg-emerald-100 text-emerald-900',
    rejected: 'bg-red-100 text-red-900',
    expired: 'bg-zinc-100 text-zinc-600',
    refunded: 'bg-sky-100 text-sky-900',
};

export function OrderStatusBadge({ status }: { status: OrderStatus }) {
    const { t } = useT();
    return (
        <Badge variant="outline" className={cn('h-6 border-transparent px-2.5', STATUS_STYLE[status])} data-order-status={status}>
            {t(`payments.status.${status}`)}
        </Badge>
    );
}

function CopyValue({ value, label, big }: { value: string; label: string; big?: boolean }) {
    const { t } = useT();
    const [copied, setCopied] = useState(false);
    // account numbers read left to right in a monospace font; names keep their own script
    const latin = !/[\u0600-\u06FF]/.test(value);
    return (
        <div className="flex items-center justify-between gap-2 rounded-lg border p-2.5 ps-3">
            <div className="grid min-w-0 gap-0.5">
                <span className="text-muted-foreground text-xs">{label}</span>
                <bdi className={cn('truncate font-semibold', latin && 'font-mono', big ? 'text-xl' : 'text-sm')} dir={latin ? 'ltr' : 'auto'}>
                    {value}
                </bdi>
            </div>
            <Button
                variant="ghost"
                size="sm"
                className="h-9 shrink-0"
                onClick={async () => {
                    if (await copyText(value)) {
                        setCopied(true);
                        setTimeout(() => setCopied(false), 1500);
                    }
                }}
            >
                <Copy /> {copied ? t('payments.transfer.copied') : t('payments.transfer.copy')}
            </Button>
        </div>
    );
}

/** After a card checkout, ask the server until the webhook has marked the order paid. */
function usePaidPolling(order: OrderView, active: boolean) {
    useEffect(() => {
        if (!active) return;
        let tries = 0;
        const timer = window.setInterval(async () => {
            tries += 1;
            try {
                const response = await fetch(order.urls.status, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                const data = (await response.json()) as { status: OrderStatus };
                if (data.status !== order.status) {
                    window.clearInterval(timer);
                    router.reload();
                }
            } catch {
                // keep trying
            }
            if (tries >= 40) window.clearInterval(timer);
        }, 3000);
        return () => window.clearInterval(timer);
    }, [active, order.status, order.urls.status]);
}

/**
 * One order as the customer sees it: transfer instructions and the WhatsApp
 * receipt button, "waiting for approval", card retry, or the outcome.
 */
export function OrderPanel({ order, outcome, from = 'dashboard', compact }: { order: OrderView; outcome?: string | null; from?: 'wizard' | 'dashboard'; compact?: boolean }) {
    const { t, locale } = useT();
    const [busy, setBusy] = useState(false);
    const transfer = order.transfer;
    const checking = order.method === 'card' && order.status === 'awaiting_payment' && outcome === 'success';
    usePaidPolling(order, checking);

    const date = (iso: string | null) =>
        iso ? new Intl.DateTimeFormat(locale === 'ar' ? 'ar-u-nu-arab' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso)) : '';

    const sentReceipt = () => {
        setBusy(true);
        router.post(order.urls.receipt, {}, { preserveScroll: true, onFinish: () => setBusy(false), onSuccess: () => toast.success(t('payments.transfer.receipt_marked')) });
    };
    const retry = () => {
        setBusy(true);
        router.post(order.urls.retry, { from }, { onFinish: () => setBusy(false) });
    };

    return (
        <section className="bg-card grid gap-4 rounded-xl border p-4 sm:p-5" data-order={order.code} aria-labelledby={`order-${order.code}`}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 id={`order-${order.code}`} className="text-lg font-bold">
                    {t('payments.order', { code: '' })}
                    <bdi dir="ltr">{order.code}</bdi>
                </h2>
                <OrderStatusBadge status={order.status} />
            </div>

            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <span className="text-muted-foreground text-sm">{order.summary}</span>
                <span className="text-primary text-2xl font-bold" data-order-total>
                    {order.total_formatted}
                </span>
            </div>
            {order.requested_currency && <p className="text-muted-foreground text-xs">{t('payments.charged_in_usd', { currency: t(`checkout.currencies.${order.requested_currency}`) })}</p>}

            {/* bank transfer, before the receipt */}
            {transfer && order.status === 'awaiting_payment' && (
                <div className="grid gap-3" data-transfer>
                    <h3 className="font-semibold">{t('payments.transfer.title')}</h3>
                    <ol className={cn('text-muted-foreground grid list-inside gap-1 text-sm', locale === 'ar' ? 'list-[arabic-indic]' : 'list-decimal')}>
                        <li>{t('payments.transfer.steps.amount', { amount: transfer.amount })}</li>
                        <li>{t('payments.transfer.steps.code', { code: transfer.code })}</li>
                        <li>{t('payments.transfer.steps.receipt')}</li>
                    </ol>
                    <CopyValue value={transfer.code} label={t('payments.transfer.write_code')} big />
                    {transfer.account.length === 0 && <p className="text-muted-foreground text-sm">{t('payments.transfer.no_details')}</p>}
                    <div className="grid gap-2 sm:grid-cols-2">
                        {transfer.account.map((row) => (
                            <CopyValue key={row.field} value={row.value} label={row.label} />
                        ))}
                    </div>
                    {transfer.note && <p className="bg-muted rounded-lg p-3 text-sm">{transfer.note}</p>}
                    {transfer.local_note && <p className="bg-muted rounded-lg p-3 text-sm">{transfer.local_note}</p>}
                    {transfer.expires_at && (
                        <p className="text-muted-foreground flex items-center gap-1.5 text-xs">
                            <Clock className="size-3.5" /> {t('payments.transfer.expires', { date: date(transfer.expires_at) })}
                        </p>
                    )}
                    <div className="grid gap-2 sm:grid-cols-2">
                        <Button asChild className="h-12 bg-[#1f9d55] text-base text-white hover:bg-[#1a8a4a]">
                            <a href={transfer.whatsapp_url} target="_blank" rel="noopener" data-whatsapp-receipt>
                                <MessageCircle /> {t('payments.transfer.whatsapp')}
                            </a>
                        </Button>
                        <Button variant="outline" className="h-12 text-base" onClick={sentReceipt} disabled={busy} data-receipt-sent>
                            <CheckCircle2 /> {t('payments.transfer.sent')}
                        </Button>
                    </div>
                </div>
            )}

            {order.status === 'receipt_received' && (
                <div className="grid gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-950" data-waiting>
                    <p className="flex items-center gap-2 font-semibold">
                        <Hourglass className="size-4" /> {t('payments.transfer.waiting_title')}
                    </p>
                    <p className="text-sm leading-relaxed">{t('payments.transfer.waiting', { code: order.code })}</p>
                    {transfer && (
                        <Button asChild variant="outline" className="h-11 w-full bg-white sm:w-fit">
                            <a href={transfer.whatsapp_url} target="_blank" rel="noopener">
                                <MessageCircle /> {t('payments.transfer.whatsapp')}
                            </a>
                        </Button>
                    )}
                </div>
            )}

            {checking && (
                <div className="flex items-start gap-3 rounded-xl border p-4" data-checking>
                    <Loader2 className="text-primary mt-0.5 size-5 shrink-0 animate-spin" />
                    <div className="grid gap-1">
                        <p className="font-semibold">{t('payments.card.checking')}</p>
                        <p className="text-muted-foreground text-sm">{t('payments.card.checking_text')}</p>
                    </div>
                </div>
            )}

            {order.can_retry && !checking && (
                <div className="grid gap-2">
                    {outcome === 'cancelled' && <p className="text-muted-foreground text-sm">{t('payments.card.cancelled')}</p>}
                    {outcome === 'failed' && <p className="text-destructive text-sm">{t('payments.card.failed')}</p>}
                    <Button className="h-12 text-base" onClick={retry} disabled={busy} data-retry>
                        <CreditCard /> {t('payments.card.retry')}
                    </Button>
                </div>
            )}

            {(order.status === 'paid' || order.status === 'approved') && (
                <p className="flex items-center gap-2 rounded-xl bg-emerald-50 p-4 text-sm font-medium text-emerald-900" data-unlocked>
                    <CheckCircle2 className="size-5 shrink-0" /> {order.status === 'paid' ? t('payments.card.paid') : t('payments.approved_text')}
                </p>
            )}
            {order.status === 'rejected' && (
                <div className="grid gap-1 rounded-xl bg-red-50 p-4 text-sm text-red-900">
                    <p className="flex items-center gap-2 font-semibold">
                        <XCircle className="size-4" /> {t('payments.rejected_text')}
                    </p>
                    {order.rejection_reason && <p>{t('payments.rejected_reason', { reason: order.rejection_reason })}</p>}
                </div>
            )}
            {order.status === 'expired' && <p className="text-muted-foreground rounded-xl bg-zinc-50 p-4 text-sm">{t('payments.expired_text')}</p>}
            {order.status === 'refunded' && (
                <p className="flex items-center gap-2 rounded-xl bg-sky-50 p-4 text-sm text-sky-900">
                    <Undo2 className="size-4" /> {t('payments.refunded_text')}
                </p>
            )}

            {!compact && (
                <dl className="grid gap-2 border-t pt-3 text-sm">
                    {order.items.map((item) => (
                        <div key={`${item.type}-${item.id}`} className="flex justify-between gap-3">
                            <dt>
                                {item.name}
                                {item.quantity > 1 && <span className="text-muted-foreground"> × {item.quantity.toLocaleString(locale === 'ar' ? 'ar-u-nu-arab' : 'en')}</span>}
                            </dt>
                            <dd className="font-medium">{item.amount_formatted}</dd>
                        </div>
                    ))}
                    {order.discount && (
                        <div className="flex justify-between gap-3 text-emerald-700" data-order-discount>
                            <dt>{t('checkout.discount.line', { code: order.discount.code })}</dt>
                            <dd className="font-medium">−{order.discount.amount_formatted}</dd>
                        </div>
                    )}
                    <div className="text-muted-foreground flex justify-between gap-3 text-xs">
                        <dt>{t('payments.created')}</dt>
                        <dd>{date(order.created_at)}</dd>
                    </div>
                </dl>
            )}
        </section>
    );
}
