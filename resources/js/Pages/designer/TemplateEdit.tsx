import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Code2, Eye, ImageUp, LayoutTemplate, Send } from 'lucide-react';
import { useMemo, useRef, useState, type FormEvent } from 'react';

import { FieldError } from '@/components/FieldError';
import { fontsHref, StatusBadge, toMajor, toMinor, useFlashToasts } from '@/components/marketplace/common';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Toaster } from '@/components/ui/sonner';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { PreviewFrame } from '@/components/wizard/PreviewFrame';
import { TierBadge } from '@/components/wizard/TierBadge';
import AppLayout from '@/layouts/AppLayout';
import { validateConfig, type InvitationConfig } from '@/lib/config-schema';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

interface Props {
    template: { id: string; status: string; published: boolean; cover: string | null; live_revision: number | null } | null;
    revision: { id: string; number: number; status: string; rejection_reason: string | null; editable: boolean; updated_at: string | null; preview_url: string } | null;
    revisions: { number: number; status: string; rejection_reason: string | null; submitted_at: string | null; reviewed_at: string | null }[];
    form: {
        name_ar: string;
        name_en: string;
        description_ar: string;
        description_en: string;
        occasion: string;
        opening_id: string;
        theme_id: string | null;
        default_config: InvitationConfig;
        prices: Record<string, number>;
        included_in_signature: boolean;
    };
    options: {
        occasions: string[];
        openings: { id: string; name: string; tier: string }[];
        themes: { id: string; name: string; config: InvitationConfig['theme'] }[];
        currencies: Record<string, { min: number; max: number; exponent: number }>;
        share_bp: number;
    };
    urls: { save: string; submit: string | null; unpublish: string | null; republish: string | null; cover: string | null; index: string };
}

type Section = InvitationConfig['sections'][number];

/**
 * A marketplace template: opening, one of the designer's themes, prices per currency
 * and the default config (structured fields or the whole JSON, checked like the wizard
 * with Zod here and the frozen v1 schema on the server), with the saved revision shown
 * in the real guest view beside it. Editing a published template starts a new revision.
 */
export default function TemplateEdit({ template, revision, revisions, form: initial, options, urls }: Props) {
    const { t, n, locale } = useT();
    useFlashToasts();
    const form = useForm({ ...initial, theme_id: initial.theme_id ?? options.themes[0]?.id ?? '' });
    const errors = form.errors as Record<string, string>;
    const config = form.data.default_config;
    const [json, setJson] = useState(() => JSON.stringify(initial.default_config, null, 2));
    const [jsonError, setJsonError] = useState<string | null>(null);
    const [priceText, setPriceText] = useState<Record<string, string>>(() =>
        Object.fromEntries(Object.entries(options.currencies).map(([c, b]) => [c, toMajor(initial.prices[c], b.exponent)])),
    );
    const coverInput = useRef<HTMLInputElement>(null);

    const locked = revision?.status === 'in_review';
    const theme = options.themes.find((th) => th.id === form.data.theme_id)?.config ?? null;
    const href = fontsHref(options.themes.map((th) => th.config));
    const zod = useMemo(() => validateConfig({ ...config, opening: { ...config.opening, id: form.data.opening_id }, occasion: form.data.occasion, ...(theme ? { theme } : {}) }), [config, form.data.opening_id, form.data.occasion, theme]);

    const setConfig = (next: InvitationConfig) => {
        form.setData('default_config', next);
        setJson(JSON.stringify(next, null, 2));
    };
    const setSection = (type: Section['type'], changes: Record<string, unknown>) => {
        const sections = config.sections.map((s) => (s.type === type ? ({ ...s, ...changes } as Section) : s));
        setConfig({ ...config, sections });
    };
    const section = <T extends Section['type']>(type: T) => config.sections.find((s) => s.type === type) as Extract<Section, { type: T }> | undefined;
    const names = section('names');
    const intro = section('intro_line');

    const applyJson = () => {
        try {
            const parsed = JSON.parse(json) as InvitationConfig;
            setJsonError(null);
            form.setData('default_config', parsed);
        } catch (e) {
            setJsonError(t('marketplace.editor.json_invalid', { message: (e as Error).message }));
        }
    };

    const setPrice = (currency: string, text: string) => {
        setPriceText((p) => ({ ...p, [currency]: text }));
        const minor = toMinor(text, options.currencies[currency].exponent);
        const prices = { ...form.data.prices };
        if (minor === null) delete prices[currency];
        else prices[currency] = minor;
        form.setData('prices', prices);
    };

    const save = (e?: FormEvent) => {
        e?.preventDefault();
        // after a save the draft is the new baseline: "Submit for review" needs no unsaved edits
        const opts = { preserveScroll: true, preserveState: true, onSuccess: () => form.setDefaults() };
        if (template) form.put(urls.save, opts);
        else form.post(urls.save, opts);
    };

    const action = (url: string | null) => url && router.post(url, {}, { preserveScroll: true });

    const uploadCover = (file: File | undefined) => {
        if (!file || !urls.cover) return;
        router.post(urls.cover, { cover: file }, { forceFormData: true, preserveScroll: true });
    };

    const share = locale === 'ar' ? n(String(options.share_bp / 100)) + '٪' : `${options.share_bp / 100}%`;
    const firstError = (prefix: string) => Object.entries(errors).find(([k]) => k === prefix || k.startsWith(prefix + '.'))?.[1];

    return (
        <AppLayout wide>
            <Head title={template ? t('marketplace.editor.edit_title') : t('marketplace.editor.new_title')}>{href && <link rel="stylesheet" href={href} />}</Head>
            <Toaster />
            <form onSubmit={save} className="grid gap-5" data-template-editor>
                <header className="flex flex-wrap items-center justify-between gap-3">
                    <div className="grid gap-1">
                        <Link href={urls.index} className="text-muted-foreground inline-flex items-center gap-1 text-sm">
                            <ArrowLeft className="size-4 rtl:rotate-180" /> {t('marketplace.portal.title')}
                        </Link>
                        <h1 className="flex flex-wrap items-center gap-2 text-2xl font-bold">
                            <LayoutTemplate className="text-primary size-6" /> {template ? t('marketplace.editor.edit_title') : t('marketplace.editor.new_title')}
                            {template && <StatusBadge status={template.status} group="template" />}
                        </h1>
                        {revision && (
                            <p className="text-muted-foreground flex flex-wrap items-center gap-1.5 text-xs" data-revision-status={revision.status}>
                                {t('marketplace.portal.revision', { number: revision.number })} <StatusBadge status={revision.status} group="revision" />
                                {template?.live_revision && template.live_revision !== revision.number && <span>· {t('marketplace.editor.live', { number: template.live_revision })}</span>}
                            </p>
                        )}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {template?.status === 'published' && (
                            <Button type="button" variant="outline" className="h-11" onClick={() => action(urls.unpublish)}>
                                {t('marketplace.editor.unpublish')}
                            </Button>
                        )}
                        {template?.status === 'unpublished' && template.live_revision && (
                            <Button type="button" variant="outline" className="h-11" onClick={() => action(urls.republish)}>
                                {t('marketplace.editor.republish')}
                            </Button>
                        )}
                        <Button type="submit" variant={template ? 'outline' : 'default'} className="h-11" disabled={form.processing || locked} data-save-draft>
                            {template ? t('marketplace.editor.save_draft') : t('marketplace.editor.create')}
                        </Button>
                        {template && (
                            <Button type="button" className="h-11" disabled={locked || form.isDirty || !revision || !['draft', 'rejected'].includes(revision.status)} onClick={() => action(urls.submit)} data-submit-review>
                                <Send /> {t('marketplace.editor.submit')}
                            </Button>
                        )}
                    </div>
                </header>

                {locked && <p className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">{t('marketplace.editor.locked')}</p>}
                {revision?.status === 'rejected' && revision.rejection_reason && (
                    <p className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-900" data-rejection>
                        {t('marketplace.portal.rejected_because')} <bdi>{revision.rejection_reason}</bdi>
                    </p>
                )}
                {template?.published && revision && ['approved', 'superseded'].includes(revision.status) && (
                    <p className="text-muted-foreground rounded-xl border bg-white p-3 text-sm">{t('marketplace.editor.new_revision_note')}</p>
                )}

                <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_24rem]">
                    <fieldset disabled={locked} className="grid min-w-0 gap-5">
                        <section className="grid gap-4 rounded-xl border bg-white p-4 sm:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor="tpl-name-ar">{t('marketplace.editor.name_ar')}</Label>
                                <Input id="tpl-name-ar" dir="rtl" value={form.data.name_ar} onChange={(e) => form.setData('name_ar', e.target.value)} maxLength={120} required />
                                <FieldError message={errors.name_ar} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="tpl-name-en">{t('marketplace.editor.name_en')}</Label>
                                <Input id="tpl-name-en" dir="ltr" value={form.data.name_en} onChange={(e) => form.setData('name_en', e.target.value)} maxLength={120} required />
                                <FieldError message={errors.name_en} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="tpl-desc-ar">{t('marketplace.editor.description_ar')}</Label>
                                <Textarea id="tpl-desc-ar" dir="rtl" rows={2} value={form.data.description_ar} onChange={(e) => form.setData('description_ar', e.target.value)} maxLength={2000} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="tpl-desc-en">{t('marketplace.editor.description_en')}</Label>
                                <Textarea id="tpl-desc-en" dir="ltr" rows={2} value={form.data.description_en} onChange={(e) => form.setData('description_en', e.target.value)} maxLength={2000} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="tpl-occasion">{t('marketplace.editor.occasion')}</Label>
                                <NativeSelect id="tpl-occasion" value={form.data.occasion} onChange={(e) => form.setData('occasion', e.target.value)}>
                                    {options.occasions.map((o) => (
                                        <option key={o} value={o}>
                                            {t(`wizard.occasions.${o}`)}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <FieldError message={errors.occasion} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="tpl-theme">{t('marketplace.editor.theme')}</Label>
                                <NativeSelect id="tpl-theme" value={form.data.theme_id} onChange={(e) => form.setData('theme_id', e.target.value)}>
                                    {options.themes.map((th) => (
                                        <option key={th.id} value={th.id}>
                                            {th.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <FieldError message={errors.theme_id} />
                            </div>
                        </section>

                        <section className="grid gap-3 rounded-xl border bg-white p-4">
                            <h2 className="font-semibold">{t('marketplace.editor.opening')}</h2>
                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3" role="radiogroup" aria-label={t('marketplace.editor.opening')}>
                                {options.openings.map((o) => (
                                    <button
                                        key={o.id}
                                        type="button"
                                        role="radio"
                                        aria-checked={form.data.opening_id === o.id}
                                        onClick={() => form.setData('opening_id', o.id)}
                                        className={cn('flex min-h-11 items-center justify-between gap-2 rounded-lg border p-2 text-start text-sm', form.data.opening_id === o.id ? 'border-primary bg-primary/5 ring-primary/30 ring-2' : 'hover:border-foreground/30')}
                                    >
                                        <span className="font-medium">{o.name}</span>
                                        <TierBadge tier={o.tier} />
                                    </button>
                                ))}
                            </div>
                            <FieldError message={errors.opening_id} />
                        </section>

                        <section className="grid gap-3 rounded-xl border bg-white p-4">
                            <div className="flex flex-wrap items-baseline justify-between gap-2">
                                <h2 className="font-semibold">{t('marketplace.editor.prices')}</h2>
                                <span className="text-muted-foreground text-xs">{t('marketplace.editor.your_share', { share })}</span>
                            </div>
                            <div className="grid gap-3 sm:grid-cols-3">
                                {Object.entries(options.currencies).map(([currency, bounds]) => (
                                    <div key={currency} className="grid gap-1.5">
                                        <Label htmlFor={`price-${currency}`}>{t(`checkout.currencies.${currency}`)}</Label>
                                        <Input id={`price-${currency}`} dir="ltr" inputMode="decimal" value={priceText[currency] ?? ''} onChange={(e) => setPrice(currency, e.target.value)} placeholder={toMajor(bounds.min, bounds.exponent)} />
                                        <p className="text-muted-foreground text-xs">{t('marketplace.editor.bounds', { min: n(toMajor(bounds.min, bounds.exponent)), max: n(toMajor(bounds.max, bounds.exponent)) })}</p>
                                        <FieldError message={errors[`prices.${currency}`]} />
                                    </div>
                                ))}
                            </div>
                            <label className="flex items-start justify-between gap-3 rounded-lg border p-3">
                                <span className="grid gap-0.5">
                                    <span className="text-sm font-medium">{t('marketplace.editor.signature')}</span>
                                    <span className="text-muted-foreground text-xs">{t('marketplace.editor.signature_help')}</span>
                                </span>
                                <Switch checked={form.data.included_in_signature} onCheckedChange={(v) => form.setData('included_in_signature', v)} aria-label={t('marketplace.editor.signature')} />
                            </label>
                        </section>

                        <section className="grid gap-3 rounded-xl border bg-white p-4">
                            <h2 className="font-semibold">{t('marketplace.editor.content')}</h2>
                            <Tabs defaultValue="fields" dir={locale === 'ar' ? 'rtl' : 'ltr'}>
                                <TabsList>
                                    <TabsTrigger value="fields">{t('marketplace.editor.fields')}</TabsTrigger>
                                    <TabsTrigger value="json">
                                        <Code2 className="size-4" /> JSON
                                    </TabsTrigger>
                                </TabsList>
                                <TabsContent value="fields" className="grid gap-4 pt-2 sm:grid-cols-2">
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="cfg-locale">{t('marketplace.editor.locale')}</Label>
                                        <NativeSelect id="cfg-locale" value={config.locale} onChange={(e) => setConfig({ ...config, locale: e.target.value as InvitationConfig['locale'] })}>
                                            <option value="ar">العربية</option>
                                            <option value="en">English</option>
                                        </NativeSelect>
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="cfg-headline">{t('wizard.design.headline')}</Label>
                                        <Input
                                            id="cfg-headline"
                                            value={config.opening.options?.headline ?? ''}
                                            maxLength={80}
                                            onChange={(e) => setConfig({ ...config, opening: { ...config.opening, options: { ...(config.opening.options ?? {}), headline: e.target.value || undefined } } })}
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="cfg-kicker">{t('wizard.design.kicker')}</Label>
                                        <Input
                                            id="cfg-kicker"
                                            value={config.opening.options?.kicker ?? ''}
                                            maxLength={60}
                                            onChange={(e) => setConfig({ ...config, opening: { ...config.opening, options: { ...(config.opening.options ?? {}), kicker: e.target.value || undefined } } })}
                                        />
                                    </div>
                                    {intro && (
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="cfg-intro">{t('marketplace.editor.intro_line')}</Label>
                                            <Input id="cfg-intro" value={intro.text} maxLength={300} onChange={(e) => setSection('intro_line', { text: e.target.value })} />
                                        </div>
                                    )}
                                    {names && (
                                        <>
                                            <div className="grid gap-1.5">
                                                <Label htmlFor="cfg-first">{t('marketplace.editor.first_name')}</Label>
                                                <Input id="cfg-first" value={names.first} maxLength={60} onChange={(e) => setSection('names', { first: e.target.value })} />
                                            </div>
                                            <div className="grid gap-1.5">
                                                <Label htmlFor="cfg-second">{t('marketplace.editor.second_name')}</Label>
                                                <Input id="cfg-second" value={names.second ?? ''} maxLength={60} onChange={(e) => setSection('names', { second: e.target.value || undefined })} />
                                            </div>
                                        </>
                                    )}
                                    <div className="flex flex-wrap gap-4 sm:col-span-2">
                                        {(['fallingPetals', 'sparkles'] as const).map((effect) => (
                                            <label key={effect} className="flex items-center gap-2 text-sm">
                                                <Switch checked={!!config.effects?.[effect]} onCheckedChange={(v) => setConfig({ ...config, effects: { ...(config.effects ?? {}), [effect]: v } })} />
                                                {t(`marketplace.editor.effects.${effect}`)}
                                            </label>
                                        ))}
                                    </div>
                                    <p className="text-muted-foreground text-xs sm:col-span-2">{t('marketplace.editor.fields_help', { count: config.sections.length })}</p>
                                </TabsContent>
                                <TabsContent value="json" className="grid gap-2 pt-2">
                                    <Textarea dir="ltr" rows={18} spellCheck={false} className="font-mono text-xs" value={json} onChange={(e) => setJson(e.target.value)} aria-label="JSON" data-config-json />
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Button type="button" variant="outline" size="sm" onClick={applyJson}>
                                            {t('marketplace.editor.apply_json')}
                                        </Button>
                                        <span className="text-muted-foreground text-xs">{t('marketplace.editor.json_help')}</span>
                                    </div>
                                    {jsonError && <FieldError message={jsonError} />}
                                </TabsContent>
                            </Tabs>
                            {!zod.success && (
                                <ul className="grid gap-0.5 rounded-lg bg-amber-50 p-2 text-xs text-amber-950" data-zod-errors>
                                    {Object.entries(zod.errors)
                                        .slice(0, 6)
                                        .map(([path, messages]) => (
                                            <li key={path} dir="ltr">
                                                {path}: {messages[0]}
                                            </li>
                                        ))}
                                </ul>
                            )}
                            {firstError('default_config') && (
                                <ul className="grid gap-0.5 rounded-lg bg-red-50 p-2 text-xs text-red-900" data-server-errors>
                                    {Object.entries(errors)
                                        .filter(([k]) => k.startsWith('default_config'))
                                        .map(([k, m]) => (
                                            <li key={k} dir="ltr">
                                                {m}
                                            </li>
                                        ))}
                                </ul>
                            )}
                        </section>

                        {template && (
                            <section className="grid gap-3 rounded-xl border bg-white p-4">
                                <h2 className="font-semibold">{t('marketplace.editor.cover')}</h2>
                                <div className="flex flex-wrap items-center gap-3">
                                    {template.cover ? <img src={template.cover} alt="" className="h-24 w-20 rounded-lg object-cover" /> : <span className="bg-muted grid h-24 w-20 place-items-center rounded-lg text-xs">—</span>}
                                    <input ref={coverInput} type="file" accept="image/jpeg,image/png,image/webp" className="hidden" onChange={(e) => uploadCover(e.target.files?.[0])} />
                                    <Button type="button" variant="outline" onClick={() => coverInput.current?.click()}>
                                        <ImageUp /> {t('marketplace.editor.upload_cover')}
                                    </Button>
                                    <p className="text-muted-foreground text-xs">{t('marketplace.editor.cover_help')}</p>
                                </div>
                                <FieldError message={errors.cover} />
                            </section>
                        )}

                        {revisions.length > 0 && (
                            <section className="grid gap-2 rounded-xl border bg-white p-4">
                                <h2 className="font-semibold">{t('marketplace.editor.history')}</h2>
                                <ol className="grid gap-1.5 text-sm">
                                    {revisions.map((r) => (
                                        <li key={r.number} className="flex flex-wrap items-center gap-2">
                                            <span>{t('marketplace.portal.revision', { number: r.number })}</span>
                                            <StatusBadge status={r.status} group="revision" />
                                            {r.rejection_reason && <span className="text-muted-foreground text-xs">— <bdi>{r.rejection_reason}</bdi></span>}
                                        </li>
                                    ))}
                                </ol>
                            </section>
                        )}
                    </fieldset>

                    <aside className="grid content-start gap-2 lg:sticky lg:top-4">
                        <h2 className="text-muted-foreground flex items-center gap-1.5 text-sm font-semibold">
                            <Eye className="size-4" /> {t('marketplace.editor.preview')}
                        </h2>
                        {revision ? (
                            <div className="mx-auto h-[640px] w-full max-w-[360px] overflow-hidden rounded-[2rem] border-8 border-neutral-900 bg-white shadow-xl">
                                <PreviewFrame url={revision.preview_url} version={Date.parse(revision.updated_at ?? '') || 0} />
                            </div>
                        ) : (
                            <p className="rounded-xl border border-dashed bg-white p-6 text-center text-sm">{t('marketplace.editor.preview_after_save')}</p>
                        )}
                        <p className="text-muted-foreground text-xs">{t('marketplace.editor.preview_help')}</p>
                    </aside>
                </div>
            </form>
        </AppLayout>
    );
}
