import { Head, useForm } from '@inertiajs/react';
import { Brush, Clock, ShieldAlert } from 'lucide-react';
import type { FormEvent } from 'react';

import { FieldError } from '@/components/FieldError';
import { useFlashToasts } from '@/components/marketplace/common';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Toaster } from '@/components/ui/sonner';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout';
import { useT } from '@/lib/i18n';

interface Profile {
    status: 'pending' | 'approved' | 'suspended';
    status_reason: string | null;
    display_name_ar: string;
    display_name_en: string;
    bio_ar: string | null;
    bio_en: string | null;
    portfolio_links: string[];
    country: string | null;
    has_payout_details: boolean;
}

interface Props {
    profile: Profile | null;
    terms: string;
    countries: string[];
}

/**
 * GET /designer before approval: apply to sell templates (plan section 2) and agree to the
 * marketplace terms; afterwards the application's status (pending or suspended).
 */
export default function DesignerApply({ profile, terms, countries }: Props) {
    const { t } = useT();
    useFlashToasts();
    const form = useForm({
        display_name_ar: profile?.display_name_ar ?? '',
        display_name_en: profile?.display_name_en ?? '',
        bio_ar: profile?.bio_ar ?? '',
        bio_en: profile?.bio_en ?? '',
        portfolio_links: [...(profile?.portfolio_links ?? []), '', ''].slice(0, 3),
        country: profile?.country ?? '',
        payout_details: { holder: '', bank: '', iban: '', note: '' },
        accept_terms: false,
    });
    const errors = form.errors as Record<string, string>;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/designer/apply', { preserveScroll: true });
    };

    const suspended = profile?.status === 'suspended';

    return (
        <AppLayout>
            <Head title={t('marketplace.portal.apply_title')} />
            <Toaster />
            <div className="mx-auto grid max-w-2xl gap-6" data-designer-apply>
                <header className="grid gap-1">
                    <h1 className="flex items-center gap-2 text-2xl font-bold">
                        <Brush className="text-primary size-6" /> {t('marketplace.portal.apply_title')}
                    </h1>
                    <p className="text-muted-foreground text-sm">{t('marketplace.portal.apply_intro')}</p>
                </header>

                {profile?.status === 'pending' && (
                    <p className="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950" data-application-pending>
                        <Clock className="mt-0.5 size-4 shrink-0" /> {t('marketplace.portal.pending')}
                    </p>
                )}
                {suspended && (
                    <p className="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-950">
                        <ShieldAlert className="mt-0.5 size-4 shrink-0" />
                        <span>
                            {t('marketplace.portal.suspended')} {profile?.status_reason && <bdi>{profile.status_reason}</bdi>}
                        </span>
                    </p>
                )}

                {!suspended && (
                    <form onSubmit={submit} className="grid gap-5 rounded-xl border bg-white p-4 sm:p-6">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor="name_ar">{t('marketplace.portal.display_name_ar')}</Label>
                                <Input id="name_ar" dir="rtl" value={form.data.display_name_ar} onChange={(e) => form.setData('display_name_ar', e.target.value)} maxLength={80} required />
                                <FieldError message={errors.display_name_ar} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="name_en">{t('marketplace.portal.display_name_en')}</Label>
                                <Input id="name_en" dir="ltr" value={form.data.display_name_en} onChange={(e) => form.setData('display_name_en', e.target.value)} maxLength={80} required />
                                <FieldError message={errors.display_name_en} />
                            </div>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor="bio_ar">{t('marketplace.portal.bio_ar')}</Label>
                                <Textarea id="bio_ar" dir="rtl" rows={3} value={form.data.bio_ar} onChange={(e) => form.setData('bio_ar', e.target.value)} maxLength={2000} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="bio_en">{t('marketplace.portal.bio_en')}</Label>
                                <Textarea id="bio_en" dir="ltr" rows={3} value={form.data.bio_en} onChange={(e) => form.setData('bio_en', e.target.value)} maxLength={2000} />
                            </div>
                        </div>
                        <fieldset className="grid gap-2">
                            <legend className="mb-1 text-sm font-medium">{t('marketplace.portal.portfolio')}</legend>
                            {form.data.portfolio_links.map((link, i) => (
                                <div key={i} className="grid gap-1">
                                    <Input
                                        dir="ltr"
                                        type="url"
                                        inputMode="url"
                                        placeholder="https://behance.net/…"
                                        aria-label={t('marketplace.portal.portfolio')}
                                        value={link}
                                        onChange={(e) => form.setData('portfolio_links', form.data.portfolio_links.map((l, j) => (j === i ? e.target.value : l)))}
                                    />
                                    <FieldError message={errors[`portfolio_links.${i}`]} />
                                </div>
                            ))}
                        </fieldset>
                        <div className="grid gap-1.5 sm:max-w-xs">
                            <Label htmlFor="country">{t('marketplace.portal.country')}</Label>
                            <NativeSelect id="country" value={form.data.country} onChange={(e) => form.setData('country', e.target.value)}>
                                <option value="">—</option>
                                {countries.map((c) => (
                                    <option key={c} value={c}>
                                        {t(`marketplace.countries.${c}`)}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        <fieldset className="grid gap-3 rounded-lg border p-3">
                            <legend className="px-1 text-sm font-medium">{t('marketplace.portal.payout_title')}</legend>
                            <p className="text-muted-foreground text-xs">
                                {t('marketplace.portal.payout_help')} {profile?.has_payout_details && t('marketplace.portal.payout_saved')}
                            </p>
                            <div className="grid gap-3 sm:grid-cols-2">
                                {(['holder', 'bank', 'iban', 'note'] as const).map((key) => (
                                    <div key={key} className={key === 'note' ? 'grid gap-1.5 sm:col-span-2' : 'grid gap-1.5'}>
                                        <Label htmlFor={`payout_${key}`}>{t(`marketplace.portal.payout.${key}`)}</Label>
                                        <Input
                                            id={`payout_${key}`}
                                            dir={key === 'iban' ? 'ltr' : undefined}
                                            autoComplete="off"
                                            value={form.data.payout_details[key]}
                                            onChange={(e) => form.setData('payout_details', { ...form.data.payout_details, [key]: e.target.value })}
                                        />
                                    </div>
                                ))}
                            </div>
                        </fieldset>

                        <section className="grid gap-2">
                            <h2 className="text-sm font-semibold">{t('marketplace.portal.terms_title')}</h2>
                            <div
                                className="prose-sm text-muted-foreground max-h-56 overflow-y-auto rounded-lg border bg-slate-50 p-3 text-sm leading-relaxed [&_h1]:text-base [&_h1]:font-bold [&_h2]:mt-3 [&_h2]:font-semibold [&_li]:ms-4 [&_li]:list-disc [&_p]:mt-2"
                                dangerouslySetInnerHTML={{ __html: terms }}
                                data-designer-terms
                            />
                            <label className="flex items-start gap-2 text-sm">
                                <input type="checkbox" className="mt-1 size-4" checked={form.data.accept_terms} onChange={(e) => form.setData('accept_terms', e.target.checked)} />
                                <span>{t('marketplace.portal.accept_terms')}</span>
                            </label>
                            <FieldError message={errors.accept_terms} />
                        </section>

                        <Button type="submit" className="h-11 justify-self-start" disabled={form.processing || !form.data.accept_terms}>
                            {profile ? t('marketplace.portal.update_application') : t('marketplace.portal.apply')}
                        </Button>
                    </form>
                )}
            </div>
        </AppLayout>
    );
}
