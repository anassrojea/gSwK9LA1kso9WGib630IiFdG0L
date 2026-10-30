import { router } from '@inertiajs/react';
import axios from 'axios';
import { Building2, Check, CreditCard, Info, Minus, Plus, ShieldCheck, Tag, X } from 'lucide-react';
import { useMemo, useState, type FormEvent, type ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { TierBadge } from '@/components/wizard/TierBadge';
import { discountFor, type DiscountLine, type DiscountRules } from '@/lib/discount';
import { useT } from '@/lib/i18n';
import { chargeCurrency, formatMoney, total } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { CheckoutAddon, CheckoutGateway, CheckoutOptions } from '@/types/payments';

interface Props {
    options: CheckoutOptions;
    from: 'wizard' | 'dashboard';
    errors?: Record<string, string>;
}

function Choice({ selected, disabled, onSelect, children, className, name }: { selected: boolean; disabled?: boolean; onSelect: () => void; children: ReactNode; className?: string; name: string }) {
    return (
        <button
            type="button"
            role="radio"
            aria-checked={selected}
            disabled={disabled}
            onClick={onSelect}
            data-choice={name}
            className={cn(
                'bg-card relative grid min-h-11 gap-1 rounded-xl border p-3 text-start transition-colors disabled:cursor-not-allowed disabled:opacity-50',
                selected ? 'border-primary ring-primary/30 bg-primary/5 ring-2' : 'hover:border-foreground/30',
                className,
            )}
        >
            {children}
        </button>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <fieldset className="grid gap-2">
            <legend className="mb-2 text-sm font-semibold">{title}</legend>
            {children}
        </fieldset>
    );
}

/**
 * Plan, add-ons, currency and payment method, with the price always in view
 * (plan section 3). The server (CheckoutService) re-checks everything and
 * prices the order itself; this only mirrors its rules for the display.
 */
export function CheckoutPanel({ options, from, errors = {} }: Props) {
    const { t, n, locale } = useT();
    const exponent = (code: string) => options.currencies.find((c) => c.code === code)?.exponent ?? 2;
    const money = (minor: number, currency: string) => formatMoney(minor, currency, locale, exponent(currency));

    const [country, setCountry] = useState(options.country);
    const [currency, setCurrency] = useState(options.currency);
    const initialPlan = options.plans.find((p) => p.id === options.required_plan)?.id ?? (options.current_plan === 'free' ? (options.plans[0]?.id ?? null) : null);
    const [plan, setPlan] = useState<string | null>(initialPlan);
    // ?addon=seating from an upsell (seating planner, door app) picks that add-on
    const [addons, setAddons] = useState<Record<string, number>>(() => {
        const wanted = typeof window === 'undefined' ? null : new URLSearchParams(window.location.search).get('addon');
        return wanted && options.addons.some((a) => a.id === wanted && !a.owned) ? { [wanted]: 1 } : {};
    });
    const [gateway, setGateway] = useState<string | null>(options.gateways[0]?.id ?? null);
    const [busy, setBusy] = useState(false);
    const [codeOpen, setCodeOpen] = useState(false);
    const [codeText, setCodeText] = useState('');
    const [codeBusy, setCodeBusy] = useState(false);
    const [codeError, setCodeError] = useState<string | null>(null);
    const [rules, setRules] = useState<DiscountRules | null>(null);

    const selectedPlan = options.plans.find((p) => p.id === plan) ?? null;
    const features = selectedPlan ? selectedPlan.features : options.current_features;
    const visibleAddons = options.addons.filter((a) => !features.includes(a.feature) && !(a.covered_by && features.includes(a.covered_by)));
    const chosenAddons = visibleAddons.filter((a) => addons[a.id]);

    const method: CheckoutGateway | null = options.gateways.find((g) => g.id === gateway) ?? null;
    const charged = method ? chargeCurrency(method.currencies, currency, options.fallback_currency) : null;

    const choices = useMemo(
        () => [...(selectedPlan ? [{ prices: selectedPlan.prices }] : []), ...chosenAddons.map((a) => ({ prices: a.prices, quantity: addons[a.id] }))],
        [selectedPlan, chosenAddons, addons],
    );
    const subtotal = charged ? total(choices, charged) : null;
    const nothing = choices.length === 0;

    // the code is priced in the currency actually charged, like Discounts::amount on the server
    const lines: DiscountLine[] = charged
        ? [
              ...(selectedPlan ? [{ type: 'plan' as const, id: selectedPlan.id, amount: selectedPlan.prices[charged] ?? 0 }] : []),
              ...chosenAddons.map((a) => ({ type: 'addon' as const, id: a.id, amount: (a.prices[charged] ?? 0) * addons[a.id] })),
          ]
        : [];
    const discount = rules && charged && subtotal !== null && !nothing ? discountFor(rules, lines, charged) : null;
    const discountAmount = discount && discount.error === null ? discount.amount : 0;
    const sum = subtotal === null ? null : Math.max(0, subtotal - discountAmount);
    const free = sum === 0 && discountAmount > 0;

    const applyCode = async (e: FormEvent) => {
        e.preventDefault();
        const code = codeText.trim();
        if (!code) return;
        setCodeBusy(true);
        setCodeError(null);
        try {
            const { data } = await axios.post<{ discount: DiscountRules }>(options.discount_endpoint, { event_id: options.event_id, code }, { headers: { Accept: 'application/json' } });
            setRules(data.discount);
            setCodeText(data.discount.code);
        } catch (err) {
            const body = axios.isAxiosError(err) ? (err.response?.data as { errors?: Record<string, string[]>; message?: string } | undefined) : undefined;
            setRules(null);
            setCodeError(body?.errors ? (Object.values(body.errors)[0]?.[0] ?? null) : (body?.message ?? t('checkout.errors.discount_unknown')));
        } finally {
            setCodeBusy(false);
        }
    };

    const removeCode = () => {
        setRules(null);
        setCodeText('');
        setCodeError(null);
    };

    const pickCountry = (code: string) => {
        setCountry(code);
        const match = options.countries.find((c) => c.code === code);
        if (match) setCurrency(match.currency);
    };

    const toggleAddon = (addon: CheckoutAddon) => setAddons((current) => ({ ...current, [addon.id]: current[addon.id] ? 0 : 1 }));
    const setQuantity = (addon: CheckoutAddon, quantity: number) => setAddons((current) => ({ ...current, [addon.id]: Math.max(1, Math.min(addon.max_quantity, quantity)) }));

    const submit = () => {
        if (!gateway || nothing) return;
        setBusy(true);
        router.post(
            options.endpoint,
            {
                event_id: options.event_id,
                plan,
                addons: chosenAddons.map((a) => ({ id: a.id, quantity: addons[a.id] })),
                currency,
                gateway,
                country: country === 'other' ? null : country,
                discount_code: rules && discount?.error === null ? rules.code : null,
                from,
            },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    const firstError = errors.checkout ?? errors.plan ?? errors.addons ?? errors.currency ?? errors.gateway ?? errors.discount_code;

    return (
        <section className="bg-card grid gap-6 rounded-xl border p-4 sm:p-5" data-checkout aria-labelledby="checkout-title">
            <div className="grid gap-1">
                <h2 id="checkout-title" className="text-lg font-bold">
                    {t('checkout.title')}
                </h2>
                <p className="text-muted-foreground text-sm">{t('checkout.subtitle')}</p>
            </div>

            <Section title={t('checkout.country')}>
                <div role="radiogroup" className="grid grid-cols-3 gap-2">
                    {options.countries.map((c) => (
                        <Choice key={c.code} name={`country-${c.code}`} selected={country === c.code} onSelect={() => pickCountry(c.code)} className="place-items-center p-2 text-center text-sm font-medium">
                            {t(`checkout.countries.${c.code}`)}
                        </Choice>
                    ))}
                </div>
            </Section>

            <Section title={t('checkout.currency')}>
                <div role="radiogroup" className="grid grid-cols-3 gap-2">
                    {options.currencies.map((c) => (
                        <Choice key={c.code} name={`currency-${c.code}`} selected={currency === c.code} onSelect={() => setCurrency(c.code)} className="place-items-center p-2 text-center">
                            <span className="text-sm font-bold" dir="ltr">
                                {c.code}
                            </span>
                            <span className="text-muted-foreground text-xs">{t(`checkout.currencies.${c.code}`)}</span>
                        </Choice>
                    ))}
                </div>
            </Section>

            {options.plans.length > 0 && (
                <Section title={t('checkout.plan')}>
                    <div role="radiogroup" className="grid gap-2 sm:grid-cols-2">
                        {options.plans.map((p) => {
                            const price = p.prices[currency];
                            return (
                                <Choice key={p.id} name={`plan-${p.id}`} selected={plan === p.id} onSelect={() => setPlan(p.id)}>
                                    <span className="flex items-center justify-between gap-2">
                                        <TierBadge tier={p.id} className="px-2.5 py-1 text-sm" />
                                        {plan === p.id && <Check className="text-primary size-4" />}
                                    </span>
                                    <span className="text-xl font-bold" data-price>
                                        {price === null || price === undefined ? t('checkout.unavailable', { currency }) : money(price, currency)}
                                    </span>
                                    {p.guests && <span className="text-muted-foreground text-xs">{t('checkout.up_to', { guests: n(p.guests) })}</span>}
                                    {p.id === options.required_plan && <span className="text-primary text-xs font-semibold">{t('checkout.needed', { plan: t(`wizard.tiers.${p.id}`) })}</span>}
                                </Choice>
                            );
                        })}
                        {options.current_plan !== 'free' && (
                            <Choice name="plan-none" selected={plan === null} onSelect={() => setPlan(null)}>
                                <span className="font-semibold">{t('checkout.no_plan')}</span>
                                <span className="text-muted-foreground text-xs">{t('checkout.current_plan', { plan: t(`wizard.tiers.${options.current_plan}`) })}</span>
                            </Choice>
                        )}
                    </div>
                </Section>
            )}

            {visibleAddons.length > 0 && (
                <details className="group grid gap-2" open={from === 'dashboard'}>
                    <summary className="flex min-h-11 cursor-pointer items-center justify-between text-sm font-semibold">
                        {t('checkout.addons_title')}
                        <Plus className="size-4 transition-transform group-open:rotate-45" />
                    </summary>
                    <ul className="grid gap-2 pt-2">
                        {visibleAddons.map((addon) => {
                            const price = addon.prices[currency];
                            const on = !!addons[addon.id];
                            return (
                                <li key={addon.id} className={cn('flex flex-wrap items-center gap-3 rounded-xl border p-3', on && 'border-primary bg-primary/5')} data-addon={addon.id}>
                                    <label className="flex min-w-0 flex-1 cursor-pointer items-start gap-3">
                                        <input
                                            type="checkbox"
                                            className="accent-primary mt-1 size-5 shrink-0"
                                            checked={on}
                                            disabled={addon.owned || price === null || price === undefined}
                                            onChange={() => toggleAddon(addon)}
                                        />
                                        <span className="grid min-w-0 gap-0.5">
                                            <span className="text-sm font-semibold">{addon.name}</span>
                                            {addon.description && <span className="text-muted-foreground text-xs">{addon.description}</span>}
                                            {addon.owned && <span className="text-xs text-emerald-700">{t('checkout.owned')}</span>}
                                        </span>
                                    </label>
                                    {addon.stackable && on && (
                                        <span className="flex items-center gap-1" aria-label={t('checkout.quantity')}>
                                            <Button type="button" variant="outline" size="icon" className="size-9" onClick={() => setQuantity(addon, addons[addon.id] - 1)} aria-label="-">
                                                <Minus />
                                            </Button>
                                            <span className="w-6 text-center text-sm font-semibold tabular-nums">{n(addons[addon.id])}</span>
                                            <Button type="button" variant="outline" size="icon" className="size-9" onClick={() => setQuantity(addon, addons[addon.id] + 1)} aria-label="+">
                                                <Plus />
                                            </Button>
                                        </span>
                                    )}
                                    <span className="ms-auto text-sm font-semibold whitespace-nowrap">
                                        {price === null || price === undefined ? t('checkout.unavailable', { currency }) : money(price * (addons[addon.id] || 1), currency)}
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                </details>
            )}

            <Section title={t('checkout.method')}>
                {options.gateways.length === 0 && <p className="text-muted-foreground text-sm">{t('checkout.no_methods')}</p>}
                <div role="radiogroup" className="grid gap-2">
                    {options.gateways.map((g) => {
                        const charges = chargeCurrency(g.currencies, currency, options.fallback_currency);
                        const fallbackTotal = charges && charges !== currency ? total(choices, charges) : null;
                        return (
                            <Choice key={g.id} name={`gateway-${g.id}`} selected={gateway === g.id} disabled={!charges} onSelect={() => setGateway(g.id)}>
                                <span className="flex items-center gap-2 font-semibold">
                                    {g.method === 'transfer' ? <Building2 className="size-4" /> : <CreditCard className="size-4" />}
                                    {t(`checkout.methods.${g.id}.name`)}
                                    {g.id === 'transfer' && <span className="ms-auto rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">{t('checkout.recommended')}</span>}
                                </span>
                                <span className="text-muted-foreground text-xs leading-relaxed">{t(`checkout.methods.${g.id}.text`)}</span>
                                {!charges && <span className="text-xs font-medium">{t('checkout.method_unavailable', { currency })}</span>}
                                {charges && charges !== currency && (
                                    <span className="flex items-start gap-1.5 rounded-lg bg-amber-50 p-2 text-xs text-amber-900" data-fallback>
                                        <Info className="mt-0.5 size-3.5 shrink-0" />
                                        {t('checkout.fallback', { currency: t(`checkout.currencies.${currency}`), amount: fallbackTotal ? money(fallbackTotal, charges) : charges })}
                                    </span>
                                )}
                            </Choice>
                        );
                    })}
                </div>
            </Section>

            <div className="grid gap-2" data-discount>
                {rules ? (
                    <div className="flex flex-wrap items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">
                        <Tag className="size-4 shrink-0" />
                        <span className="font-semibold" dir="ltr">
                            {rules.code}
                        </span>
                        <span className="min-w-0 flex-1">
                            {discount?.error === 'currency'
                                ? t('checkout.errors.discount_currency', { currency: t(`checkout.currencies.${charged}`) })
                                : discount?.error === 'items'
                                  ? t('checkout.discount.not_here', { code: rules.code })
                                  : t('checkout.discount.applied', { code: rules.code })}
                        </span>
                        <Button type="button" variant="ghost" size="sm" className="h-9" onClick={removeCode} data-discount-remove>
                            <X className="size-4" /> {t('checkout.discount.remove')}
                        </Button>
                    </div>
                ) : codeOpen ? (
                    <form className="grid gap-1.5" onSubmit={applyCode}>
                        <label htmlFor="discount-code" className="text-sm font-semibold">
                            {t('checkout.discount.label')}
                        </label>
                        <div className="flex gap-2">
                            <Input
                                id="discount-code"
                                value={codeText}
                                onChange={(e) => setCodeText(e.target.value)}
                                placeholder={t('checkout.discount.placeholder')}
                                autoComplete="off"
                                autoCapitalize="characters"
                                dir="ltr"
                                className="h-11 uppercase"
                                maxLength={40}
                                aria-invalid={!!codeError}
                                data-discount-input
                            />
                            <Button type="submit" variant="outline" className="h-11 shrink-0" disabled={codeBusy || !codeText.trim()} data-discount-apply>
                                {codeBusy ? t('checkout.discount.applying') : t('checkout.discount.apply')}
                            </Button>
                        </div>
                        {codeError && (
                            <p className="text-destructive text-sm" role="alert" data-discount-error>
                                {codeError}
                            </p>
                        )}
                    </form>
                ) : (
                    <button type="button" className="text-primary flex min-h-11 w-fit items-center gap-1.5 text-sm font-semibold" onClick={() => setCodeOpen(true)} data-discount-toggle>
                        <Tag className="size-4" /> {t('checkout.discount.toggle')}
                    </button>
                )}
            </div>

            <div className="bg-muted/60 grid gap-1 rounded-xl p-4" data-total>
                {discountAmount > 0 && subtotal !== null && charged && (
                    <>
                        <div className="text-muted-foreground flex items-baseline justify-between gap-3 text-sm">
                            <span>{t('checkout.discount.subtotal')}</span>
                            <span>{money(subtotal, charged)}</span>
                        </div>
                        <div className="flex items-baseline justify-between gap-3 text-sm font-medium text-emerald-700" data-discount-line>
                            <span>{t('checkout.discount.line', { code: rules?.code ?? '' })}</span>
                            <span>−{money(discountAmount, charged)}</span>
                        </div>
                    </>
                )}
                <div className="flex items-baseline justify-between gap-3">
                    <span className="font-semibold">{t('checkout.total')}</span>
                    <span className="text-primary text-2xl font-bold" data-total-amount>
                        {free ? t('checkout.discount.free') : sum !== null && charged ? money(sum, charged) : '—'}
                    </span>
                </div>
                {charged && charged !== currency && <span className="text-muted-foreground text-xs">{t('checkout.total_charged', { currency: t(`checkout.currencies.${charged}`) })}</span>}
            </div>

            <div className="text-muted-foreground grid gap-1.5 text-xs leading-relaxed" data-terms>
                <p className="text-foreground flex items-center gap-1.5 font-semibold">
                    <ShieldCheck className="size-4" /> {t('checkout.terms_title')}
                </p>
                <p>{t('checkout.terms')}</p>
                <p>{t('checkout.refund')}</p>
                <p className="italic">{t('checkout.terms_note')}</p>
            </div>

            {firstError && (
                <p className="text-destructive text-sm" role="alert">
                    {firstError}
                </p>
            )}

            <Button className="h-14 text-lg" disabled={busy || nothing || !method || !charged || sum === null} onClick={submit} data-pay>
                {busy
                    ? t('checkout.paying')
                    : nothing
                      ? t('checkout.pick_something')
                      : free
                        ? t('checkout.discount.pay_free')
                        : method?.method === 'transfer'
                        ? t('checkout.pay_transfer')
                        : t('checkout.pay_card', { amount: sum !== null && charged ? money(sum, charged) : '' })}
            </Button>
        </section>
    );
}
