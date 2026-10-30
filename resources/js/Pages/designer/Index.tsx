import { Head, Link } from '@inertiajs/react';
import { Brush, ExternalLink, Palette, Plus, Wallet } from 'lucide-react';

import { DesignPoster, fontsHref, StatusBadge, useFlashToasts } from '@/components/marketplace/common';
import { Button } from '@/components/ui/button';
import { Toaster } from '@/components/ui/sonner';
import AppLayout from '@/layouts/AppLayout';
import type { InvitationConfig } from '@/lib/config-schema';
import { useT } from '@/lib/i18n';

interface TemplateRow {
    id: string;
    name: string;
    status: string;
    revision: { number: number; status: string; rejection_reason: string | null } | null;
    live_revision: number | null;
    cover: string | null;
    prices: Record<string, string>;
    included_in_signature: boolean;
    sales: number;
    earned: Record<string, string>;
    edit_url: string;
    theme: InvitationConfig['theme'] | null;
}

interface Props {
    profile: { name: string; slug: string; share_bp: number; public_url: string };
    templates: TemplateRow[];
    themes: { id: string; name: string; config: InvitationConfig['theme']; edit_url: string; templates: number }[];
    earnings: { currency: string; amount: number; formatted: string }[];
    balances: { currency: string; amount: number; formatted: string; minimum: string }[];
    payouts: { id: string; period: string; currency: string; amount: string; sales_count: number; status: string; reference: string | null; paid_at: string | null }[];
    signature_uses: number;
}

/**
 * GET /designer: an approved designer's templates and themes, sales per template and
 * currency, what Daawa owes them now and their monthly payout statements.
 */
export default function DesignerIndex({ profile, templates, themes, earnings, balances, payouts, signature_uses }: Props) {
    const { t, n, locale, sep } = useT();
    useFlashToasts();
    const percent = (bp: number) => (locale === 'ar' ? n(String(bp / 100)) + '٪' : `${bp / 100}%`);
    const href = fontsHref([...templates.map((tpl) => tpl.theme), ...themes.map((th) => th.config)]);
    const month = (period: string) => new Intl.DateTimeFormat(locale === 'ar' ? 'ar-u-nu-arab' : 'en-GB', { month: 'long', year: 'numeric' }).format(new Date(period + '-01T12:00:00'));

    return (
        <AppLayout>
            <Head title={t('marketplace.portal.title')}>{href && <link rel="stylesheet" href={href} />}</Head>
            <Toaster />
            <div className="grid gap-6" data-designer-portal>
                <header className="flex flex-wrap items-end justify-between gap-3">
                    <div className="grid gap-1">
                        <h1 className="flex items-center gap-2 text-2xl font-bold">
                            <Brush className="text-primary size-6" /> {t('marketplace.portal.title')}
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            <bdi>{profile.name}</bdi>{sep}{t('marketplace.portal.your_share', { share: percent(profile.share_bp) })}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline" className="h-11">
                            <a href={profile.public_url} target="_blank" rel="noreferrer">
                                <ExternalLink /> {t('marketplace.portal.public_page')}
                            </a>
                        </Button>
                        <Button asChild variant="outline" className="h-11">
                            <Link href="/designer/themes/create">
                                <Palette /> {t('marketplace.portal.new_theme')}
                            </Link>
                        </Button>
                        <Button asChild className="h-11">
                            <Link href="/designer/templates/create">
                                <Plus /> {t('marketplace.portal.new_template')}
                            </Link>
                        </Button>
                    </div>
                </header>

                <section className="grid gap-3 sm:grid-cols-2" aria-label={t('marketplace.portal.money')}>
                    <div className="grid gap-2 rounded-xl border bg-white p-4" data-earnings>
                        <h2 className="text-muted-foreground text-sm font-semibold">{t('marketplace.portal.earned')}</h2>
                        {earnings.length === 0 ? (
                            <p className="text-sm">{t('marketplace.portal.no_sales')}</p>
                        ) : (
                            <ul className="flex flex-wrap gap-x-6 gap-y-1">
                                {earnings.map((e) => (
                                    <li key={e.currency} className="text-xl font-bold" data-currency={e.currency}>
                                        {e.formatted}
                                    </li>
                                ))}
                            </ul>
                        )}
                        {signature_uses > 0 && <p className="text-muted-foreground text-xs">{t('marketplace.portal.signature_uses', { count: signature_uses })}</p>}
                    </div>
                    <div className="grid gap-2 rounded-xl border bg-white p-4" data-balance>
                        <h2 className="text-muted-foreground flex items-center gap-1.5 text-sm font-semibold">
                            <Wallet className="size-4" /> {t('marketplace.portal.balance')}
                        </h2>
                        {balances.length === 0 ? (
                            <p className="text-sm">—</p>
                        ) : (
                            <ul className="grid gap-1">
                                {balances.map((b) => (
                                    <li key={b.currency} className="flex flex-wrap items-baseline gap-2">
                                        <span className="text-xl font-bold">{b.formatted}</span>
                                        <span className="text-muted-foreground text-xs">{t('marketplace.portal.minimum', { amount: b.minimum })}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <p className="text-muted-foreground text-xs">{t('marketplace.portal.payout_rule')}</p>
                    </div>
                </section>

                <section className="grid gap-3" aria-labelledby="designer-templates">
                    <h2 id="designer-templates" className="text-lg font-bold">
                        {t('marketplace.portal.templates')}
                    </h2>
                    {templates.length === 0 ? (
                        <p className="rounded-xl border border-dashed bg-white p-6 text-center text-sm">{t('marketplace.portal.no_templates')}</p>
                    ) : (
                        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {templates.map((tpl) => (
                                <li key={tpl.id} className="grid gap-3 rounded-xl border bg-white p-3" data-template-row={tpl.id}>
                                    <DesignPoster theme={tpl.theme} title={tpl.name} cover={tpl.cover} />
                                    <div className="grid gap-1.5">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <h3 className="font-bold">
                                                <bdi>{tpl.name}</bdi>
                                            </h3>
                                            <StatusBadge status={tpl.status} group="template" />
                                        </div>
                                        {tpl.live_revision !== null && tpl.revision && tpl.revision.status !== 'approved' && tpl.revision.status !== 'superseded' && (
                                            <p className="text-muted-foreground flex flex-wrap items-center gap-1.5 text-xs">
                                                {t('marketplace.portal.revision', { number: tpl.revision.number })} <StatusBadge status={tpl.revision.status} group="revision" />
                                            </p>
                                        )}
                                        {tpl.revision?.status === 'rejected' && tpl.revision.rejection_reason && (
                                            <p className="rounded-md bg-red-50 p-2 text-xs text-red-900" data-rejection>
                                                {t('marketplace.portal.rejected_because')} <bdi>{tpl.revision.rejection_reason}</bdi>
                                            </p>
                                        )}
                                        <p className="text-muted-foreground text-xs">{Object.values(tpl.prices).join(sep) || '—'}</p>
                                        <p className="text-sm">
                                            {t('marketplace.portal.sales', { count: tpl.sales })}
                                            {Object.values(tpl.earned).length > 0 && <>{sep}{Object.values(tpl.earned).join(sep)}</>}
                                        </p>
                                        {tpl.included_in_signature && <p className="text-xs text-emerald-700">{t('marketplace.portal.in_signature')}</p>}
                                    </div>
                                    <Button asChild variant="outline" size="sm" className="h-10">
                                        <Link href={tpl.edit_url}>{t('marketplace.portal.edit')}</Link>
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="grid gap-3" aria-labelledby="designer-themes">
                    <h2 id="designer-themes" className="text-lg font-bold">
                        {t('marketplace.portal.themes')}
                    </h2>
                    {themes.length === 0 ? (
                        <p className="rounded-xl border border-dashed bg-white p-6 text-center text-sm">{t('marketplace.portal.no_themes')}</p>
                    ) : (
                        <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            {themes.map((th) => (
                                <li key={th.id} className="flex items-center justify-between gap-3 rounded-xl border bg-white p-3">
                                    <div className="flex min-w-0 items-center gap-3">
                                        <span className="flex -space-x-1.5 rtl:space-x-reverse" aria-hidden>
                                            {['bg', 'accent', 'gold', 'ink'].map((k) => (
                                                <span key={k} className="size-6 rounded-full border-2 border-white shadow-sm" style={{ background: (th.config.colors as Record<string, string>)[k] }} />
                                            ))}
                                        </span>
                                        <span className="grid min-w-0">
                                            <bdi className="truncate font-semibold">{th.name}</bdi>
                                            <span className="text-muted-foreground text-xs">{t('marketplace.portal.theme_templates', { count: th.templates })}</span>
                                        </span>
                                    </div>
                                    <Button asChild variant="ghost" size="sm">
                                        <Link href={th.edit_url}>{t('marketplace.portal.edit')}</Link>
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="grid gap-3" aria-labelledby="designer-payouts">
                    <h2 id="designer-payouts" className="text-lg font-bold">
                        {t('marketplace.portal.statements')}
                    </h2>
                    {payouts.length === 0 ? (
                        <p className="rounded-xl border border-dashed bg-white p-6 text-center text-sm">{t('marketplace.portal.no_statements')}</p>
                    ) : (
                        <div className="overflow-x-auto rounded-xl border bg-white">
                            <table className="w-full min-w-[32rem] text-sm" data-statements>
                                <thead className="bg-muted/50 text-muted-foreground text-start">
                                    <tr>
                                        <th className="p-3 text-start font-medium">{t('marketplace.portal.period')}</th>
                                        <th className="p-3 text-start font-medium">{t('marketplace.portal.amount')}</th>
                                        <th className="p-3 text-start font-medium">{t('marketplace.portal.sales_col')}</th>
                                        <th className="p-3 text-start font-medium">{t('marketplace.portal.status')}</th>
                                        <th className="p-3 text-start font-medium">{t('marketplace.portal.reference')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {payouts.map((p) => (
                                        <tr key={p.id} className="border-t">
                                            <td className="p-3">{month(p.period)}</td>
                                            <td className="p-3 font-semibold">{p.amount}</td>
                                            <td className="p-3">{n(p.sales_count)}</td>
                                            <td className="p-3">
                                                <StatusBadge status={p.status} group="payout" />
                                            </td>
                                            <td className="p-3">
                                                <bdi dir="ltr">{p.reference ?? '—'}</bdi>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
