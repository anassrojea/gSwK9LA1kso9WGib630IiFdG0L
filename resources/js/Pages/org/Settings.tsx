import { Head, Link, useForm } from '@inertiajs/react';
import { Lock, Palette } from 'lucide-react';
import type { FormEvent } from 'react';

import { FieldError } from '@/components/FieldError';
import { OrgLayout } from '@/components/org/OrgLayout';
import { OrgProfileFields, type ProfileForm } from '@/components/org/OrgProfileFields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import type { Org } from '@/types/org';

type SettingsForm = ProfileForm & {
    brand_color: string;
    sender_name: string;
    remove_logo: boolean;
};

/**
 * GET /org/{org}/settings (owner, phase 8A): profile and logo, and white
 * label (brand color, sender name) with a preview of the invitation footer.
 */
export default function OrgSettings({ org, types }: { org: Org; types: string[] }) {
    const { t } = useT();
    const form = useForm<SettingsForm>({
        name: org.name,
        type: org.type,
        city: org.city ?? '',
        contact_phone: org.contact_phone ?? '',
        contact_email: org.contact_email ?? '',
        logo: null,
        brand_color: org.white_label.raw_brand_color ?? '#7a3e2b',
        sender_name: org.white_label.sender_name ?? '',
        remove_logo: false,
    });
    const locked = !org.white_label.enabled;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(org.urls.settings, { forceFormData: true, preserveScroll: true, onSuccess: () => form.setData('logo', null) });
    };

    return (
        <OrgLayout org={org} active="settings" wide={false}>
            <Head title={`${org.name} · ${t('org.settings.title')}`} />
            <form onSubmit={submit} className="grid gap-5" data-org-settings>
                <section className="grid gap-4 rounded-xl border bg-white p-4 sm:p-6">
                    <h2 className="text-lg font-bold">{t('org.settings.profile')}</h2>
                    <OrgProfileFields form={{ data: form.data, errors: form.errors, setData: (k, v) => form.setData(k, v as never) }} types={types} />
                    {org.logo_url && (
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={form.data.remove_logo} onChange={(e) => form.setData('remove_logo', e.target.checked)} />
                            {t('org.settings.remove_logo')}
                        </label>
                    )}
                </section>

                <section className="grid gap-4 rounded-xl border bg-white p-4 sm:p-6" data-white-label={locked ? 'locked' : 'on'}>
                    <h2 className="flex items-center gap-2 text-lg font-bold">
                        <Palette className="text-primary size-5" /> {t('org.settings.white_label')}
                    </h2>
                    <p className="text-muted-foreground text-sm">{t('org.settings.white_label_text')}</p>
                    {locked && (
                        <p className="flex items-center gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-950">
                            <Lock className="size-4" /> {t('org.settings.white_label_locked')}{' '}
                            {org.can.billing && (
                                <Link href={org.urls.billing} className="font-medium underline">
                                    {t('org.nav.billing')}
                                </Link>
                            )}
                        </p>
                    )}
                    <fieldset disabled={locked} className="grid gap-4 sm:grid-cols-2 disabled:opacity-60">
                        <div className="grid gap-2">
                            <Label htmlFor="brand-color">{t('org.settings.brand_color')}</Label>
                            <div className="flex items-center gap-2">
                                <input
                                    id="brand-color"
                                    type="color"
                                    value={form.data.brand_color}
                                    onChange={(e) => form.setData('brand_color', e.target.value)}
                                    className="h-11 w-14 cursor-pointer rounded-md border p-1"
                                />
                                <Input dir="ltr" className="h-11 font-mono" value={form.data.brand_color} onChange={(e) => form.setData('brand_color', e.target.value)} maxLength={7} />
                            </div>
                            <FieldError message={form.errors.brand_color} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="sender-name">{t('org.settings.sender_name')}</Label>
                            <Input id="sender-name" className="h-11" value={form.data.sender_name} onChange={(e) => form.setData('sender_name', e.target.value)} maxLength={60} placeholder={org.name} />
                            <p className="text-muted-foreground text-xs">{t('org.settings.sender_hint')}</p>
                            <FieldError message={form.errors.sender_name} />
                        </div>
                    </fieldset>
                    <div className="grid gap-2">
                        <span className="text-muted-foreground text-xs">{t('org.settings.preview')}</span>
                        <div className="flex items-center justify-center gap-2 rounded-lg border bg-[#fbf7f2] py-5 text-sm font-semibold" data-footer-preview>
                            {org.logo_url && <img src={org.logo_url} alt="" className="size-7 rounded object-contain" />}
                            <bdi>{form.data.name || org.name}</bdi>
                        </div>
                    </div>
                    <p className="text-muted-foreground text-xs">{t('org.settings.subdomain_note')}</p>
                </section>

                <Button type="submit" size="lg" className="h-12 sm:w-fit" disabled={form.processing}>
                    {t('org.settings.save')}
                </Button>
            </form>
        </OrgLayout>
    );
}
