import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Palette } from 'lucide-react';
import type { FormEvent } from 'react';

import { FieldError } from '@/components/FieldError';
import { DesignPoster, fontsHref, useFlashToasts } from '@/components/marketplace/common';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Toaster } from '@/components/ui/sonner';
import AppLayout from '@/layouts/AppLayout';
import type { InvitationConfig } from '@/lib/config-schema';
import { useT } from '@/lib/i18n';

interface Props {
    theme: { id: string; name_ar: string; name_en: string; tokens: { colors: Record<string, string>; radius: number | null }; fonts: Record<string, string> } | null;
    required_colors: string[];
    optional_colors: string[];
    font_roles: string[];
    font_suggestions: string[];
    urls: { save: string };
}

const DEFAULT_COLORS: Record<string, string> = { bg: '#FFF8F3', ink: '#2A1219', accent: '#8A2846', soft: '#7A6268', gold: '#C8A45D' };
const DEFAULT_FONTS: Record<string, string> = { display: 'Aref Ruqaa', body: 'Amiri', label: 'Almarai' };
const HEX = /^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/;

/**
 * A designer's theme: colours (pickers or hex) and Google Fonts, checked on the server
 * against the invitation schema's theme block, exactly like the admin's theme form.
 */
export default function ThemeEdit({ theme, required_colors, optional_colors, font_roles, font_suggestions, urls }: Props) {
    const { t } = useT();
    useFlashToasts();
    const form = useForm({
        name_ar: theme?.name_ar ?? '',
        name_en: theme?.name_en ?? '',
        tokens: { colors: { ...DEFAULT_COLORS, ...(theme?.tokens.colors ?? {}) } as Record<string, string>, radius: (theme?.tokens.radius ?? 16) as number | null },
        fonts: { ...DEFAULT_FONTS, ...(theme?.fonts ?? {}) } as Record<string, string>,
    });
    const errors = form.errors as Record<string, string>;

    const setColor = (key: string, value: string) => form.setData('tokens', { ...form.data.tokens, colors: { ...form.data.tokens.colors, [key]: value } });
    const setFont = (role: string, value: string) => form.setData('fonts', { ...form.data.fonts, [role]: value });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (theme) form.put(urls.save, { preserveScroll: true });
        else form.post(urls.save, { preserveScroll: true });
    };

    const preview = { id: theme?.id ?? 'preview', colors: form.data.tokens.colors, fonts: form.data.fonts, radius: form.data.tokens.radius ?? undefined } as unknown as InvitationConfig['theme'];
    const href = fontsHref([preview]);

    const colorField = (key: string, required: boolean) => {
        const value = form.data.tokens.colors[key] ?? '';
        return (
            <div key={key} className="grid gap-1.5">
                <Label htmlFor={`color-${key}`}>
                    {t(`marketplace.theme.colors.${key}`)} {!required && <span className="text-muted-foreground text-xs">({t('marketplace.theme.optional')})</span>}
                </Label>
                <div className="flex items-center gap-2">
                    <input
                        type="color"
                        aria-label={t(`marketplace.theme.colors.${key}`)}
                        className="h-11 w-12 shrink-0 cursor-pointer rounded-md border bg-white p-1"
                        value={HEX.test(value) && value.length === 7 ? value : '#ffffff'}
                        onChange={(e) => setColor(key, e.target.value.toUpperCase())}
                    />
                    <Input id={`color-${key}`} dir="ltr" value={value} onChange={(e) => setColor(key, e.target.value)} placeholder={required ? '#RRGGBB' : '—'} maxLength={7} aria-invalid={!!errors[`tokens.colors.${key}`]} />
                </div>
                <FieldError message={errors[`tokens.colors.${key}`]} />
            </div>
        );
    };

    return (
        <AppLayout>
            <Head title={theme ? t('marketplace.theme.edit_title') : t('marketplace.theme.new_title')}>{href && <link rel="stylesheet" href={href} />}</Head>
            <Toaster />
            <form onSubmit={submit} className="grid gap-6" data-theme-editor>
                <header className="flex flex-wrap items-center justify-between gap-3">
                    <div className="grid gap-1">
                        <Link href="/designer" className="text-muted-foreground inline-flex items-center gap-1 text-sm">
                            <ArrowLeft className="size-4 rtl:rotate-180" /> {t('marketplace.portal.title')}
                        </Link>
                        <h1 className="flex items-center gap-2 text-2xl font-bold">
                            <Palette className="text-primary size-6" /> {theme ? t('marketplace.theme.edit_title') : t('marketplace.theme.new_title')}
                        </h1>
                    </div>
                    <Button type="submit" className="h-11" disabled={form.processing}>
                        {t('marketplace.theme.save')}
                    </Button>
                </header>

                <div className="grid gap-6 lg:grid-cols-[1fr_18rem]">
                    <div className="grid gap-5">
                        <section className="grid gap-4 rounded-xl border bg-white p-4 sm:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor="theme-name-ar">{t('marketplace.theme.name_ar')}</Label>
                                <Input id="theme-name-ar" dir="rtl" value={form.data.name_ar} onChange={(e) => form.setData('name_ar', e.target.value)} required maxLength={120} />
                                <FieldError message={errors.name_ar} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="theme-name-en">{t('marketplace.theme.name_en')}</Label>
                                <Input id="theme-name-en" dir="ltr" value={form.data.name_en} onChange={(e) => form.setData('name_en', e.target.value)} required maxLength={120} />
                                <FieldError message={errors.name_en} />
                            </div>
                        </section>
                        <section className="grid gap-3 rounded-xl border bg-white p-4">
                            <h2 className="font-semibold">{t('marketplace.theme.colors_title')}</h2>
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {required_colors.map((k) => colorField(k, true))}
                                {optional_colors.map((k) => colorField(k, false))}
                            </div>
                        </section>
                        <section className="grid gap-3 rounded-xl border bg-white p-4">
                            <h2 className="font-semibold">{t('marketplace.theme.fonts_title')}</h2>
                            <p className="text-muted-foreground text-xs">{t('marketplace.theme.fonts_help')}</p>
                            <datalist id="font-suggestions">
                                {font_suggestions.map((f) => (
                                    <option key={f} value={f} />
                                ))}
                            </datalist>
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                {font_roles.map((role) => (
                                    <div key={role} className="grid gap-1.5">
                                        <Label htmlFor={`font-${role}`}>{t(`marketplace.theme.fonts.${role}`)}</Label>
                                        <Input id={`font-${role}`} dir="ltr" list="font-suggestions" value={form.data.fonts[role] ?? ''} onChange={(e) => setFont(role, e.target.value)} maxLength={40} />
                                        <FieldError message={errors[`fonts.${role}`]} />
                                    </div>
                                ))}
                                <div className="grid gap-1.5">
                                    <Label htmlFor="radius">{t('marketplace.theme.radius')}</Label>
                                    <Input
                                        id="radius"
                                        dir="ltr"
                                        type="number"
                                        min={0}
                                        max={32}
                                        value={form.data.tokens.radius ?? ''}
                                        onChange={(e) => form.setData('tokens', { ...form.data.tokens, radius: e.target.value === '' ? null : Number(e.target.value) })}
                                    />
                                    <FieldError message={errors['tokens.radius']} />
                                </div>
                            </div>
                        </section>
                    </div>
                    <aside className="grid content-start gap-2">
                        <h2 className="text-muted-foreground text-sm font-semibold">{t('marketplace.theme.preview')}</h2>
                        <DesignPoster theme={preview} title={form.data.name_ar || form.data.name_en || t('marketplace.theme.sample')} subtitle={form.data.name_en} />
                    </aside>
                </div>
            </form>
        </AppLayout>
    );
}
