import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Building2, CreditCard, ShieldCheck } from 'lucide-react';
import { useState, type FormEvent } from 'react';

import { useNeedsVerification, VerifyEmailNotice } from '@/components/account/VerifyEmailNotice';
import { FieldError } from '@/components/FieldError';
import { DesignPoster, fontsHref } from '@/components/marketplace/common';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout';
import type { InvitationConfig } from '@/lib/config-schema';
import { useT } from '@/lib/i18n';
import { chargeCurrency, formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';

interface Item {
    kind: 'template' | 'feature' | 'calligraphy';
    title: string;
    subtitle: string | null;
    image: string | null;
    theme: InvitationConfig['theme'] | null;
    prices: Record<string, number>;
    formatted: Record<string, string>;
    owned: boolean;
    discounts: boolean;
    event: { id: string; title: string } | null;
    events?: { id: string; title: string }[];
    cities?: { id: string; name: string }[];
    days?: number;
    endpoint: string;
    back: string;
    from: 'wizard' | 'dashboard';
}

interface Props {
    item: Item;
    currencies: { code: string; exponent: number }[];
    currency: string;
    country: string | null;
    countries: string[];
    fallback_currency: string;
    gateways: { id: string; method: string; currencies: string[] }[];
    errors?: Record<string, string>;
}

/**
 * One marketplace item through the usual checkout (gateways, bank transfer approval,
 * discount codes, invoices): a template for an event, a vendor's featured spot, or a
 * calligraphy name logo with its brief.
 */
export default function Buy({ item, currencies, currency: initialCurrency, country: initialCountry, countries, fallback_currency, gateways, errors = {} }: Props) {
    const { t, locale } = useT();
    const needsVerification = useNeedsVerification();
    const [currency, setCurrency] = useState(item.prices[initialCurrency] !== undefined ? initialCurrency : (Object.keys(item.prices)[0] ?? initialCurrency));
    const [country, setCountry] = useState(initialCountry ?? '');
    const [gateway, setGateway] = useState(gateways[0]?.id ?? '');
    const [code, setCode] = useState('');
    const [city, setCity] = useState(item.cities?.[0]?.id ?? '');
    const [names, setNames] = useState('');
    const [brief, setBrief] = useState('');
    const [eventId, setEventId] = useState(item.event?.id ?? '');
    const [busy, setBusy] = useState(false);

    const exponent = (c: string) => currencies.find((x) => x.code === c)?.exponent ?? 2;
    const method = gateways.find((g) => g.id === gateway) ?? null;
    const charged = method ? chargeCurrency(method.currencies, currency, fallback_currency) : null;
    const amount = charged ? item.prices[charged] : undefined;
    const href = fontsHref([item.theme]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!gateway || needsVerification) return;
        setBusy(true);
        router.post(
            item.endpoint,
            {
                currency,
                gateway,
                country: country || null,
                discount_code: item.discounts && code.trim() ? code.trim() : null,
                ...(item.kind === 'template' ? { event_id: item.event?.id, from: item.from } : {}),
                ...(item.kind === 'feature' ? { city } : {}),
                ...(item.kind === 'calligraphy' ? { names, brief, event_id: eventId || null } : {}),
            },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    const firstError = errors.template ?? errors.listing ?? errors.checkout ?? errors.currency ?? errors.gateway ?? errors.discount_code ?? errors.city;

    return (
        <AppLayout>
            <Head title={item.title}>{href && <link rel="stylesheet" href={href} />}</Head>
            <div className="mx-auto grid max-w-3xl gap-5" data-buy={item.kind}>
                <Link href={item.back} className="text-muted-foreground inline-flex items-center gap-1 text-sm">
                    <ArrowLeft className="size-4 rtl:rotate-180" /> {t('marketplace.buy.back')}
                </Link>

                <div className="grid gap-5 sm:grid-cols-[14rem_1fr]">
                    <div className="mx-auto grid w-40 content-start gap-2 sm:w-full">
                        {item.image || item.theme ? (
                            <DesignPoster theme={item.theme} title={item.title} cover={item.image} />
                        ) : (
                            <div className="bg-primary/10 text-primary grid aspect-[4/5] place-items-center rounded-xl text-4xl font-bold">{item.subtitle?.[0] ?? '★'}</div>
                        )}
                    </div>

                    <form onSubmit={submit} className="grid content-start gap-5 rounded-xl border bg-white p-4 sm:p-5">
                        <header className="grid gap-1">
                            <h1 className="text-xl font-bold">
                                <bdi>{item.title}</bdi>
                            </h1>
                            {item.subtitle && (
                                <p className="text-muted-foreground text-sm">
                                    <bdi>{item.subtitle}</bdi>
                                </p>
                            )}
                            {item.event && (
                                <p className="text-sm">
                                    {t('marketplace.buy.for_event')} <bdi className="font-semibold">{item.event.title}</bdi>
                                </p>
                            )}
                            {item.kind === 'feature' && <p className="text-muted-foreground text-sm">{t('vendors.feature.text', { days: item.days ?? 30 })}</p>}
                            <p className="text-muted-foreground flex flex-wrap gap-x-3 text-xs">
                                {Object.entries(item.formatted).map(([c, f]) => (
                                    <bdi key={c}>{f}</bdi>
                                ))}
                            </p>
                        </header>

                        {item.owned ? (
                            <p className="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-900" data-owned>
                                {t('marketplace.buy.owned')}
                            </p>
                        ) : (
                            <>
                                {item.kind === 'feature' && (
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="buy-city">{t('vendors.public.city')}</Label>
                                        <NativeSelect id="buy-city" value={city} onChange={(e) => setCity(e.target.value)}>
                                            {item.cities?.map((c) => (
                                                <option key={c.id} value={c.id}>
                                                    {c.name}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                        <FieldError message={errors.city} />
                                    </div>
                                )}

                                {item.kind === 'calligraphy' && (
                                    <div className="grid gap-3">
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="buy-names">{t('vendors.calligraphy.names')}</Label>
                                            <Input id="buy-names" value={names} onChange={(e) => setNames(e.target.value)} maxLength={120} required placeholder={t('vendors.calligraphy.names_placeholder')} />
                                            <FieldError message={errors.names} />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="buy-brief">{t('vendors.calligraphy.brief')}</Label>
                                            <Textarea id="buy-brief" rows={4} value={brief} onChange={(e) => setBrief(e.target.value)} maxLength={2000} required placeholder={t('vendors.calligraphy.brief_placeholder')} />
                                            <FieldError message={errors.brief} />
                                        </div>
                                        {(item.events?.length ?? 0) > 0 && (
                                            <div className="grid gap-1.5">
                                                <Label htmlFor="buy-event">{t('vendors.calligraphy.event')}</Label>
                                                <NativeSelect id="buy-event" value={eventId} onChange={(e) => setEventId(e.target.value)}>
                                                    <option value="">—</option>
                                                    {item.events?.map((ev) => (
                                                        <option key={ev.id} value={ev.id}>
                                                            {ev.title}
                                                        </option>
                                                    ))}
                                                </NativeSelect>
                                            </div>
                                        )}
                                    </div>
                                )}

                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="buy-country">{t('checkout.country')}</Label>
                                        <NativeSelect id="buy-country" value={country} onChange={(e) => setCountry(e.target.value)}>
                                            <option value="">{t('checkout.countries.other')}</option>
                                            {countries.map((c) => (
                                                <option key={c} value={c}>
                                                    {t(`checkout.countries.${c}`)}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="buy-currency">{t('checkout.currency')}</Label>
                                        <NativeSelect id="buy-currency" value={currency} onChange={(e) => setCurrency(e.target.value)}>
                                            {currencies
                                                .filter((c) => item.prices[c.code] !== undefined)
                                                .map((c) => (
                                                    <option key={c.code} value={c.code}>
                                                        {t(`checkout.currencies.${c.code}`)}
                                                    </option>
                                                ))}
                                        </NativeSelect>
                                    </div>
                                </div>

                                <fieldset className="grid gap-2" role="radiogroup">
                                    <legend className="mb-1 text-sm font-semibold">{t('checkout.method')}</legend>
                                    {gateways.length === 0 && <p className="text-muted-foreground text-sm">{t('marketplace.buy.no_gateway')}</p>}
                                    {gateways.map((g) => {
                                        const charges = chargeCurrency(g.currencies, currency, fallback_currency);
                                        return (
                                            <button
                                                key={g.id}
                                                type="button"
                                                role="radio"
                                                aria-checked={gateway === g.id}
                                                disabled={!charges || item.prices[charges] === undefined}
                                                onClick={() => setGateway(g.id)}
                                                className={cn('flex min-h-11 items-center gap-2 rounded-xl border p-3 text-start text-sm disabled:opacity-50', gateway === g.id ? 'border-primary bg-primary/5 ring-primary/30 ring-2' : 'hover:border-foreground/30')}
                                                data-choice={`gateway-${g.id}`}
                                            >
                                                {g.method === 'transfer' ? <Building2 className="size-4" /> : <CreditCard className="size-4" />}
                                                <span className="font-medium">{t(`checkout.methods.${g.id}.name`)}</span>
                                                {charges && charges !== currency && <span className="text-muted-foreground ms-auto text-xs">{t('checkout.total_charged', { currency: t(`checkout.currencies.${charges}`) })}</span>}
                                            </button>
                                        );
                                    })}
                                </fieldset>

                                {item.discounts && (
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="buy-code">{t('checkout.discount.label')}</Label>
                                        <Input id="buy-code" dir="ltr" value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} maxLength={40} placeholder={t('checkout.discount.placeholder')} />
                                        <FieldError message={errors.discount_code} />
                                    </div>
                                )}

                                <div className="flex items-baseline justify-between gap-2 border-t pt-3">
                                    <span className="font-semibold">{t('checkout.total')}</span>
                                    <span className="text-xl font-bold" data-buy-total>
                                        {amount !== undefined && charged ? formatMoney(amount, charged, locale, exponent(charged)) : '—'}
                                    </span>
                                </div>
                                {item.discounts && <p className="text-muted-foreground -mt-3 text-xs">{t('marketplace.buy.discount_note')}</p>}

                                {firstError && <FieldError message={firstError} />}
                                {needsVerification ? (
                                    <VerifyEmailNotice action="checkout" />
                                ) : (
                                    <Button type="submit" className="h-12 text-base" disabled={busy || !gateway || amount === undefined} data-buy-submit>
                                        {busy ? t('checkout.paying') : method?.method === 'transfer' ? t('checkout.pay_transfer') : t('checkout.pay_card', { amount: amount !== undefined && charged ? formatMoney(amount, charged, locale, exponent(charged)) : '' })}
                                    </Button>
                                )}
                                <p className="text-muted-foreground flex items-start gap-1.5 text-xs">
                                    <ShieldCheck className="mt-0.5 size-4 shrink-0" /> {t('checkout.terms')}
                                </p>
                            </>
                        )}
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}
