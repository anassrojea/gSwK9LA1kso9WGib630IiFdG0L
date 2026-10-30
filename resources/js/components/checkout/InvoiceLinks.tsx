import { Download, FileText } from 'lucide-react';

import { useT } from '@/lib/i18n';
import type { InvoiceLink } from '@/types/payments';

/**
 * The order's invoice or receipt (and credit note after a refund) as PDF
 * downloads (GET /invoices/{number}.pdf). Plain links: the browser downloads.
 */
export function InvoiceLinks({ invoices }: { invoices: InvoiceLink[] }) {
    const { t, locale } = useT();
    if (invoices.length === 0) return null;
    const date = (iso: string) => new Intl.DateTimeFormat(locale === 'ar' ? 'ar-u-nu-arab' : 'en-GB', { dateStyle: 'medium' }).format(new Date(iso));

    return (
        <section className="bg-card grid gap-3 rounded-xl border p-4" data-invoices>
            <h2 className="text-sm font-semibold">{t('invoices.download_title')}</h2>
            <ul className="grid gap-2">
                {invoices.map((invoice) => (
                    <li key={invoice.number}>
                        <a
                            href={invoice.url}
                            className="hover:bg-muted/60 flex min-h-11 items-center gap-3 rounded-lg border p-3 transition-colors"
                            data-invoice={invoice.number}
                        >
                            <FileText className="text-muted-foreground size-5 shrink-0" />
                            <span className="grid min-w-0 flex-1 gap-0.5">
                                <span className="text-sm font-medium">{invoice.title}</span>
                                <span className="text-muted-foreground text-xs">
                                    <bdi dir="ltr" className="font-mono">
                                        {invoice.number}
                                    </bdi>{' '}
                                    · {date(invoice.issued_at)}
                                </span>
                            </span>
                            <span className="shrink-0 text-sm font-semibold">{invoice.total_formatted}</span>
                            <Download className="text-primary size-4 shrink-0" aria-label={t('invoices.download')} />
                        </a>
                    </li>
                ))}
            </ul>
        </section>
    );
}
