import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, CalendarCheck, Gift, Sparkles, Users } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

import { Section } from '@/components/account/Section';
import { ReferralLink } from '@/components/journey/ReferralLink';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Toaster } from '@/components/ui/sonner';
import { Switch } from '@/components/ui/switch';
import { TierBadge } from '@/components/wizard/TierBadge';
import AppLayout from '@/layouts/AppLayout';
import { useT } from '@/lib/i18n';
import { chargeCurrency, formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';
import type { BundleView, JourneyPageProps } from '@/types/journey';

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div className="bg-muted/50 grid gap-0.5 rounded-lg p-3 text-center">
            <span className="text-2xl font-bold tabular-nums">{value}</span>
            <span className="text-muted-foreground text-xs">{label}</span>
        </div>
    );
}

function BundleCard({ bundle, currency, gateway, busy, onBuy }: { bundle: BundleView; currency: string; gateway: string | null; busy: boolean; onBuy: () => void }) {
    const { t, n, locale } = useT();
    const pricing = bundle.prices[currency];
    const money = (minor: number) => formatMoney(minor, currency, locale);
    const saving = pricing?.price != null && pricing.separately != null ? pricing.separately - pricing.price : null;

    return (
        <li className="bg-card grid content-between gap-4 rounded-xl border p-4" data-bundle={bundle.id}>
            <div className="grid gap-2">
                <h3 className="flex items-center gap-2 text-base font-bold">
                    <Gift className="text-primary size-5 shrink-0" /> {bundle.name}
                </h3>
                {bundle.description && <p className="text-muted-foreground text-sm leading-relaxed">{bundle.description}</p>}
                <ul className="flex flex-wrap gap-1.5">
                    {bundle.credits.map((c) => (
                        <li key={c.plan} className="flex items-center gap-1.5 text-sm">
                            <span className="font-semibold tabular-nums">{n(c.count)}×</span>
                            <TierBadge tier={c.plan} />
                        </li>
                    ))}
                </ul>
                <p className="text-muted-foreground flex items-center gap-1.5 text-xs">
                    <CalendarCheck className="size-3.5" /> {bundle.validity_days === 730 ? t('journey.bundles.validity_years') : t('journey.bundles.validity', { days: bundle.validity_days })}
                </p>
            </div>
            <div className="grid gap-2">
                {pricing?.price != null ? (
                    <div className="flex flex-wrap items-baseline gap-x-2">
                        <span className="text-primary text-2xl font-bold" data-bundle-price>
                            {money(pricing.price)}
                        </span>
                        {pricing.separately != null && <span className="text-muted-foreground text-sm line-through">{money(pricing.separately)}</span>}
                        {saving !== null && saving > 0 && <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800">{t('journey.bundles.save', { amount: money(saving) })}</span>}
                    </div>
                ) : (
                    <span className="text-muted-foreground text-sm">{t('checkout.unavailable', { currency })}</span>
                )}
                <Button className="h-11" disabled={busy || !gateway || pricing?.price == null} onClick={onBuy} data-bundle-buy>
                    {busy ? t('journey.bundles.buying') : t('journey.bundles.buy')}
                </Button>
            </div>
        </li>
    );
}

/**
 * GET /account/journey (phase 8D): invite friends (link, stats, credit), the
 * family journey bundles and the host's bundle credits.
 */
export default function JourneyShow({ referral, loyalty_percent, bundles, credits, checkout, urls }: JourneyPageProps) {
    const { t, n, locale, sep } = useT();
    const { flash } = usePage<SharedProps>().props;
    const [currency, setCurrency] = useState(checkout.currency);
    const [gateway, setGateway] = useState<string | null>(checkout.gateways[0]?.id ?? null);
    const [busy, setBusy] = useState<string | null>(null);
    const [footer, setFooter] = useState(referral.footer);
    const date = (iso: string) => new Intl.DateTimeFormat(locale === 'ar' ? 'ar-u-nu-arab' : 'en-GB', { dateStyle: 'medium' }).format(new Date(iso));
    const credit = Object.entries(referral.credit).filter(([, v]) => v > 0);
    const method = checkout.gateways.find((g) => g.id === gateway);
    const charged = method ? chargeCurrency(method.currencies, currency, checkout.fallback_currency) : null;

    useEffect(() => {
        if (flash?.warning) toast.error(flash.warning);
        if (flash?.status) toast.success(flash.status);
    }, [flash?.warning, flash?.status]);

    const toggleFooter = (enabled: boolean) => {
        setFooter(enabled);
        router.put(urls.footer, { enabled }, { preserveScroll: true, preserveState: true });
    };

    const buy = (bundle: BundleView) => {
        if (!gateway) return;
        setBusy(bundle.id);
        router.post(`/bundles/${bundle.id}/checkout`, { currency, gateway }, { onFinish: () => setBusy(null) });
    };

    return (
        <AppLayout>
            <Head title={t('journey.title')} />
            <Toaster />
            <div className="mx-auto grid max-w-3xl gap-5">
                <Link href={urls.billing} className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1 text-sm">
                    <ArrowLeft className="size-4 rtl:rotate-180" /> {t('account.tabs.billing')}
                </Link>
                <div className="grid gap-1">
                    <h1 className="text-2xl font-bold">{t('journey.title')}</h1>
                    <p className="text-muted-foreground">{t('journey.subtitle')}</p>
                </div>

                {referral.enabled && (
                    <Section
                        name="referral"
                        title={t('journey.referrals.title')}
                        description={t('journey.referrals.text', {
                            amount: formatMoney(referral.reward[currency] ?? referral.reward.USD ?? 0, referral.reward[currency] ? currency : 'USD', locale),
                            percent: referral.welcome_percent,
                        })}
                    >
                        <ReferralLink link={referral.link} />
                        <div className="grid grid-cols-3 gap-2">
                            <Stat label={t('journey.referrals.stats.signups')} value={n(referral.stats.signups)} />
                            <Stat label={t('journey.referrals.stats.rewarded')} value={n(referral.stats.rewarded)} />
                            <Stat label={t('journey.referrals.stats.pending')} value={n(referral.stats.pending)} />
                        </div>
                        <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3" data-referral-credit>
                            <span className="flex items-center gap-2 text-sm font-semibold">
                                <Users className="text-primary size-4" /> {t('journey.referrals.credit')}
                            </span>
                            <span className="font-bold">
                                {credit.length === 0 ? <span className="text-muted-foreground font-normal">{t('journey.referrals.no_credit')}</span> : credit.map(([c, v]) => formatMoney(v, c, locale)).join(sep)}
                            </span>
                        </div>
                        <label className="flex items-start justify-between gap-4" htmlFor="referral-footer">
                            <span className="grid gap-0.5">
                                <span className="text-sm font-semibold">{t('journey.referrals.footer')}</span>
                                <span className="text-muted-foreground text-xs">{t('journey.referrals.footer_hint')}</span>
                            </span>
                            <Switch id="referral-footer" checked={footer} onCheckedChange={toggleFooter} data-referral-footer />
                        </label>
                        <p className="text-muted-foreground text-xs">{t('journey.referrals.cap', { cap: referral.cap })}</p>
                    </Section>
                )}

                <Section name="bundles" title={t('journey.bundles.title')} description={t('journey.bundles.subtitle')}>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="bundle-currency">{t('checkout.currency')}</Label>
                            <NativeSelect id="bundle-currency" value={currency} onChange={(e) => setCurrency(e.target.value)}>
                                {checkout.currencies.map((c) => (
                                    <option key={c.code} value={c.code}>
                                        {t(`checkout.currencies.${c.code}`)}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="bundle-gateway">{t('checkout.method')}</Label>
                            <NativeSelect id="bundle-gateway" value={gateway ?? ''} onChange={(e) => setGateway(e.target.value)}>
                                {checkout.gateways.map((g) => (
                                    <option key={g.id} value={g.id}>
                                        {t(`checkout.methods.${g.id}.name`)}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                    </div>
                    {checkout.gateways.length === 0 && <p className="text-muted-foreground text-sm">{t('checkout.no_methods')}</p>}
                    {charged && charged !== currency && <p className="text-muted-foreground text-xs">{t('checkout.total_charged', { currency: t(`checkout.currencies.${charged}`) })}</p>}
                    <ul className="grid gap-3 sm:grid-cols-2">
                        {bundles.map((b) => (
                            <BundleCard key={b.id} bundle={b} currency={charged ?? currency} gateway={gateway} busy={busy === b.id} onBuy={() => buy(b)} />
                        ))}
                    </ul>
                    {loyalty_percent && (
                        <p className="flex items-center gap-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
                            <Sparkles className="size-4 shrink-0" /> {t('journey.reductions.loyalty', { percent: n(loyalty_percent) })}
                            {sep}
                            {t('journey.reductions.note')}
                        </p>
                    )}
                </Section>

                <Section name="credits" title={t('journey.bundles.yours')}>
                    {credits.length === 0 ? (
                        <p className="text-muted-foreground text-sm">{t('journey.bundles.none')}</p>
                    ) : (
                        <ul className="divide-y">
                            {credits.map((c) => (
                                <li key={c.id} className={cn('flex flex-wrap items-center justify-between gap-2 py-3', !c.available && 'opacity-70')} data-credit={c.id}>
                                    <span className="flex items-center gap-2">
                                        <TierBadge tier={c.plan} />
                                        {c.bundle && <span className="text-muted-foreground text-xs">{c.bundle}</span>}
                                    </span>
                                    <span className="text-muted-foreground text-xs">
                                        {c.used_at ? t('journey.bundles.used', { event: c.used_event ?? '' }) : c.available ? t('journey.bundles.expires', { date: date(c.expires_at) }) : t('journey.bundles.expired')}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>
            </div>
        </AppLayout>
    );
}
