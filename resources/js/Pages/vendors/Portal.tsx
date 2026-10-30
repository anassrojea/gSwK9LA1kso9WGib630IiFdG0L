import { Head, Link, router, useForm } from '@inertiajs/react';
import { ExternalLink, ImagePlus, MessageCircle, Star, Store, Trash2 } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';

import { FieldError } from '@/components/FieldError';
import { StatusBadge, toMajor, toMinor, useFlashToasts } from '@/components/marketplace/common';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Toaster } from '@/components/ui/sonner';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

interface Listing {
    slug: string;
    status: string;
    status_reason: string | null;
    name_ar: string;
    name_en: string;
    category: string;
    cities: string[];
    occasions: string[];
    description_ar: string;
    description_en: string;
    price_min: number | null;
    price_max: number | null;
    price_currency: string | null;
    whatsapp: string;
    instagram: string;
    calligraphy_prices: Record<string, number>;
    photos: string[];
    public_url: string | null;
    clicks: Record<string, number>;
    features: { city: string; starts_at: string; ends_at: string; active: boolean }[];
    requests: { id: string; names: string; brief: string; status: string; vendor_note: string | null; created_at: string | null; customer: string | null }[];
}

interface Props {
    listing: Listing | null;
    options: {
        categories: string[];
        cities: string[];
        occasions: string[];
        currencies: string[];
        calligraphy_bounds: Record<string, { min: number; max: number; exponent: number }>;
        feature_price: Record<string, string>;
        max_photos: number;
    };
    urls: { save: string; photos: string; feature: string };
}

const EXPONENT: Record<string, number> = { SYP: 0, SAR: 2, USD: 2 };

/**
 * GET /vendor-portal: a vendor's own listing (plan section 5): details, photos, contact
 * links, review state, featured spots, anonymous contact taps, and for calligraphers the
 * name logo requests hosts paid for.
 */
export default function VendorPortal({ listing, options, urls }: Props) {
    const { t, n, locale, sep } = useT();
    useFlashToasts();
    const photoInput = useRef<HTMLInputElement>(null);
    const form = useForm({
        name_ar: listing?.name_ar ?? '',
        name_en: listing?.name_en ?? '',
        category: listing?.category ?? options.categories[0],
        cities: listing?.cities ?? [],
        occasions: listing?.occasions ?? [],
        description_ar: listing?.description_ar ?? '',
        description_en: listing?.description_en ?? '',
        price_min: listing?.price_min ?? (null as number | null),
        price_max: listing?.price_max ?? (null as number | null),
        price_currency: listing?.price_currency ?? (null as string | null),
        whatsapp: listing?.whatsapp ?? '',
        instagram: listing?.instagram ?? '',
        calligraphy_prices: (listing?.calligraphy_prices ?? {}) as Record<string, number>,
    });
    const errors = form.errors as Record<string, string>;
    const exp = EXPONENT[form.data.price_currency ?? 'USD'] ?? 2;
    const [rangeText, setRangeText] = useState({ min: toMajor(listing?.price_min, exp), max: toMajor(listing?.price_max, exp) });
    const [callText, setCallText] = useState<Record<string, string>>(() =>
        Object.fromEntries(Object.entries(options.calligraphy_bounds).map(([c, b]) => [c, toMajor(listing?.calligraphy_prices?.[c], b.exponent)])),
    );
    const date = (iso: string) => new Intl.DateTimeFormat(locale === 'ar' ? 'ar-u-nu-arab' : 'en-GB', { dateStyle: 'medium' }).format(new Date(iso));

    const toggle = (key: 'cities' | 'occasions', value: string) => {
        const list = form.data[key];
        form.setData(key, list.includes(value) ? list.filter((v) => v !== value) : [...list, value]);
    };

    const setRange = (which: 'min' | 'max', text: string) => {
        setRangeText((r) => ({ ...r, [which]: text }));
        form.setData(which === 'min' ? 'price_min' : 'price_max', toMinor(text, exp));
    };

    const setCalligraphy = (currency: string, text: string) => {
        setCallText((p) => ({ ...p, [currency]: text }));
        const minor = toMinor(text, options.calligraphy_bounds[currency].exponent);
        const prices = { ...form.data.calligraphy_prices };
        if (minor === null) delete prices[currency];
        else prices[currency] = minor;
        form.setData('calligraphy_prices', prices);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(urls.save, { preserveScroll: true });
    };

    const upload = (file: File | undefined) => file && router.post(urls.photos, { photo: file }, { forceFormData: true, preserveScroll: true });
    const removePhoto = (i: number) => router.delete(`/vendor-portal/photos/${i}`, { preserveScroll: true });
    const setRequest = (id: string, status: string) => router.patch(`/vendor-portal/calligraphy/${id}`, { status }, { preserveScroll: true });

    const chip = (selected: boolean) => cn('min-h-10 rounded-full border px-3 text-sm', selected ? 'border-primary bg-primary text-primary-foreground' : 'bg-white hover:border-foreground/30');

    return (
        <AppLayout>
            <Head title={t('vendors.portal.title')} />
            <Toaster />
            <div className="grid gap-6" data-vendor-portal>
                <header className="flex flex-wrap items-end justify-between gap-3">
                    <div className="grid gap-1">
                        <h1 className="flex items-center gap-2 text-2xl font-bold">
                            <Store className="text-primary size-6" /> {t('vendors.portal.title')}
                        </h1>
                        <p className="text-muted-foreground text-sm">{t('vendors.portal.intro')}</p>
                    </div>
                    {listing && (
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusBadge status={listing.status} group="vendor" />
                            {listing.public_url && (
                                <Button asChild variant="outline" className="h-11">
                                    <a href={listing.public_url} target="_blank" rel="noreferrer">
                                        <ExternalLink /> {t('vendors.portal.public_page')}
                                    </a>
                                </Button>
                            )}
                        </div>
                    )}
                </header>

                {listing?.status === 'pending' && <p className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">{t('vendors.portal.pending')}</p>}
                {listing && ['rejected', 'hidden'].includes(listing.status) && (
                    <p className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-900">
                        {t(`vendors.portal.${listing.status}`)} {listing.status_reason && <bdi>{listing.status_reason}</bdi>}
                    </p>
                )}

                {listing?.status === 'approved' && (
                    <section className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-2 rounded-xl border bg-white p-4" data-vendor-clicks>
                            <h2 className="text-muted-foreground flex items-center gap-1.5 text-sm font-semibold">
                                <MessageCircle className="size-4" /> {t('vendors.portal.clicks')}
                            </h2>
                            <p className="text-sm">
                                {t('vendors.portal.clicks_whatsapp', { count: listing.clicks.whatsapp ?? 0 })}{sep}{t('vendors.portal.clicks_instagram', { count: listing.clicks.instagram ?? 0 })}
                            </p>
                        </div>
                        <div className="grid gap-2 rounded-xl border bg-white p-4" data-vendor-features>
                            <h2 className="text-muted-foreground flex items-center gap-1.5 text-sm font-semibold">
                                <Star className="size-4" /> {t('vendors.feature.title')}
                            </h2>
                            {listing.features.filter((f) => f.active).length > 0 ? (
                                <ul className="text-sm">
                                    {listing.features
                                        .filter((f) => f.active)
                                        .map((f) => (
                                            <li key={f.city + f.ends_at}>{t('vendors.feature.until', { city: t(`vendors.cities.${f.city}`), date: date(f.ends_at) })}</li>
                                        ))}
                                </ul>
                            ) : (
                                <p className="text-muted-foreground text-sm">{t('vendors.feature.pitch', { price: Object.values(options.feature_price).join(sep) })}</p>
                            )}
                            <Button asChild size="sm" className="h-10 justify-self-start">
                                <Link href={urls.feature}>{t('vendors.feature.buy')}</Link>
                            </Button>
                        </div>
                    </section>
                )}

                <form onSubmit={submit} className="grid gap-5 rounded-xl border bg-white p-4 sm:p-6">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="v-name-ar">{t('vendors.portal.name_ar')}</Label>
                            <Input id="v-name-ar" dir="rtl" value={form.data.name_ar} onChange={(e) => form.setData('name_ar', e.target.value)} maxLength={120} required />
                            <FieldError message={errors.name_ar} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="v-name-en">{t('vendors.portal.name_en')}</Label>
                            <Input id="v-name-en" dir="ltr" value={form.data.name_en} onChange={(e) => form.setData('name_en', e.target.value)} maxLength={120} required />
                            <FieldError message={errors.name_en} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="v-category">{t('vendors.public.category')}</Label>
                            <NativeSelect id="v-category" value={form.data.category} onChange={(e) => form.setData('category', e.target.value)}>
                                {options.categories.map((c) => (
                                    <option key={c} value={c}>
                                        {t(`vendors.categories.${c}`)}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                    </div>

                    <fieldset className="grid gap-2">
                        <legend className="mb-1 text-sm font-medium">{t('vendors.portal.cities')}</legend>
                        <div className="flex flex-wrap gap-2">
                            {options.cities.map((c) => (
                                <button key={c} type="button" aria-pressed={form.data.cities.includes(c)} className={chip(form.data.cities.includes(c))} onClick={() => toggle('cities', c)}>
                                    {t(`vendors.cities.${c}`)}
                                </button>
                            ))}
                        </div>
                        <FieldError message={errors.cities ?? errors['cities.0']} />
                    </fieldset>

                    <fieldset className="grid gap-2">
                        <legend className="mb-1 text-sm font-medium">{t('vendors.portal.occasions')}</legend>
                        <div className="flex flex-wrap gap-2">
                            {options.occasions.map((o) => (
                                <button key={o} type="button" aria-pressed={form.data.occasions.includes(o)} className={chip(form.data.occasions.includes(o))} onClick={() => toggle('occasions', o)}>
                                    {t(`wizard.occasions.${o}`)}
                                </button>
                            ))}
                        </div>
                        <p className="text-muted-foreground text-xs">{t('vendors.portal.occasions_help')}</p>
                    </fieldset>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="v-desc-ar">{t('vendors.portal.description_ar')}</Label>
                            <Textarea id="v-desc-ar" dir="rtl" rows={4} value={form.data.description_ar} onChange={(e) => form.setData('description_ar', e.target.value)} maxLength={3000} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="v-desc-en">{t('vendors.portal.description_en')}</Label>
                            <Textarea id="v-desc-en" dir="ltr" rows={4} value={form.data.description_en} onChange={(e) => form.setData('description_en', e.target.value)} maxLength={3000} />
                        </div>
                    </div>

                    <fieldset className="grid gap-2">
                        <legend className="mb-1 text-sm font-medium">{t('vendors.portal.price_range')}</legend>
                        <div className="grid grid-cols-[1fr_1fr_auto] gap-2">
                            <Input dir="ltr" inputMode="decimal" aria-label={t('vendors.portal.price_from')} placeholder={t('vendors.portal.price_from')} value={rangeText.min} onChange={(e) => setRange('min', e.target.value)} />
                            <Input dir="ltr" inputMode="decimal" aria-label={t('vendors.portal.price_to')} placeholder={t('vendors.portal.price_to')} value={rangeText.max} onChange={(e) => setRange('max', e.target.value)} />
                            <NativeSelect aria-label={t('checkout.currency')} value={form.data.price_currency ?? ''} onChange={(e) => form.setData('price_currency', e.target.value || null)}>
                                <option value="">—</option>
                                {options.currencies.map((c) => (
                                    <option key={c} value={c}>
                                        {c}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        <FieldError message={errors.price_max ?? errors.price_min ?? errors.price_currency} />
                    </fieldset>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="v-wa">{t('vendors.portal.whatsapp')}</Label>
                            <Input id="v-wa" dir="ltr" inputMode="tel" placeholder="+963 9…" value={form.data.whatsapp} onChange={(e) => form.setData('whatsapp', e.target.value)} />
                            <FieldError message={errors.whatsapp} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="v-ig">{t('vendors.portal.instagram')}</Label>
                            <Input id="v-ig" dir="ltr" placeholder="@" value={form.data.instagram} onChange={(e) => form.setData('instagram', e.target.value)} />
                            <FieldError message={errors.instagram} />
                        </div>
                    </div>

                    {form.data.category === 'calligraphy' && (
                        <fieldset className="grid gap-2 rounded-lg border p-3" data-calligraphy-prices>
                            <legend className="px-1 text-sm font-medium">{t('vendors.calligraphy.prices')}</legend>
                            <p className="text-muted-foreground text-xs">{t('vendors.calligraphy.prices_help')}</p>
                            <div className="grid gap-3 sm:grid-cols-3">
                                {Object.entries(options.calligraphy_bounds).map(([c, b]) => (
                                    <div key={c} className="grid gap-1">
                                        <Label htmlFor={`cal-${c}`}>{t(`checkout.currencies.${c}`)}</Label>
                                        <Input id={`cal-${c}`} dir="ltr" inputMode="decimal" value={callText[c] ?? ''} onChange={(e) => setCalligraphy(c, e.target.value)} placeholder={toMajor(b.min, b.exponent)} />
                                        <span className="text-muted-foreground text-xs">{t('marketplace.editor.bounds', { min: n(toMajor(b.min, b.exponent)), max: n(toMajor(b.max, b.exponent)) })}</span>
                                        <FieldError message={errors[`calligraphy_prices.${c}`]} />
                                    </div>
                                ))}
                            </div>
                        </fieldset>
                    )}

                    <Button type="submit" className="h-11 justify-self-start" disabled={form.processing} data-save-listing>
                        {listing ? t('vendors.portal.save') : t('vendors.portal.create')}
                    </Button>
                </form>

                {listing && (
                    <section className="grid gap-3 rounded-xl border bg-white p-4" aria-labelledby="vendor-photos">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 id="vendor-photos" className="font-semibold">
                                {t('vendors.portal.photos', { count: listing.photos.length, max: options.max_photos })}
                            </h2>
                            <input ref={photoInput} type="file" accept="image/jpeg,image/png,image/webp" className="hidden" onChange={(e) => upload(e.target.files?.[0])} />
                            <Button type="button" variant="outline" disabled={listing.photos.length >= options.max_photos} onClick={() => photoInput.current?.click()}>
                                <ImagePlus /> {t('vendors.portal.add_photo')}
                            </Button>
                        </div>
                        <FieldError message={errors.photo} />
                        {listing.photos.length === 0 ? (
                            <p className="text-muted-foreground text-sm">{t('vendors.portal.no_photos')}</p>
                        ) : (
                            <ul className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                {listing.photos.map((src, i) => (
                                    <li key={src} className="relative">
                                        <img src={src} alt="" className="aspect-[4/3] w-full rounded-lg object-cover" />
                                        <Button type="button" size="icon" variant="secondary" className="absolute end-1 top-1 size-8" aria-label={t('vendors.portal.remove_photo')} onClick={() => removePhoto(i)}>
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}

                {listing?.category === 'calligraphy' && (
                    <section className="grid gap-3 rounded-xl border bg-white p-4" aria-labelledby="calligraphy-requests" data-calligraphy-requests>
                        <h2 id="calligraphy-requests" className="font-semibold">
                            {t('vendors.calligraphy.requests')}
                        </h2>
                        {listing.requests.length === 0 ? (
                            <p className="text-muted-foreground text-sm">{t('vendors.calligraphy.no_requests')}</p>
                        ) : (
                            <ul className="grid gap-2">
                                {listing.requests.map((r) => (
                                    <li key={r.id} className="grid gap-2 rounded-lg border p-3">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <span className="font-semibold">
                                                <bdi>{r.names}</bdi>
                                            </span>
                                            <StatusBadge status={r.status} group="calligraphy" />
                                        </div>
                                        <p className="text-muted-foreground text-sm whitespace-pre-line">
                                            <bdi>{r.brief}</bdi>
                                        </p>
                                        {r.customer && (
                                            <p className="text-muted-foreground text-xs">
                                                <bdi>{r.customer}</bdi>{sep}{r.created_at && date(r.created_at)}
                                            </p>
                                        )}
                                        {['new', 'in_progress'].includes(r.status) && (
                                            <div className="flex gap-2">
                                                {r.status === 'new' && (
                                                    <Button size="sm" variant="outline" onClick={() => setRequest(r.id, 'in_progress')}>
                                                        {t('vendors.calligraphy.start')}
                                                    </Button>
                                                )}
                                                <Button size="sm" onClick={() => setRequest(r.id, 'delivered')}>
                                                    {t('vendors.calligraphy.deliver')}
                                                </Button>
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
