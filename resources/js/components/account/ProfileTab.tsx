import { router, useForm } from '@inertiajs/react';
import { Mail } from 'lucide-react';
import { useState, type ComponentProps, type FormEvent } from 'react';

import { Section } from '@/components/account/Section';
import { FieldError } from '@/components/FieldError';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { useT } from '@/lib/i18n';
import type { ProfileData } from '@/types/account';

interface Props {
    profile: ProfileData;
    options: { locales: string[]; countries: string[]; currencies: string[] };
    hasPassword: boolean;
}

export function ProfileTab({ profile, options, hasPassword }: Props) {
    const { t } = useT();
    const form = useForm({
        name: profile.name,
        email: profile.email ?? '',
        phone: profile.phone ?? '',
        locale: profile.locale,
        country: profile.country ?? '',
        currency: profile.currency ?? '',
        current_password: '',
        email_code: '',
    });
    const [codeBusy, setCodeBusy] = useState(false);
    const [codeError, setCodeError] = useState<string | undefined>();
    const emailChanged = form.data.email.trim().toLowerCase() !== (profile.email ?? '').toLowerCase();

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const localeChanged = form.data.locale !== profile.locale;
        form.put('/account/profile', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('current_password', 'email_code');
                // <html dir> and the root view follow the language: reload once
                if (localeChanged) window.location.assign('/account');
            },
        });
    };

    const sendCode = () => {
        setCodeBusy(true);
        setCodeError(undefined);
        router.post(
            '/account/email-code',
            { email: form.data.email },
            { preserveScroll: true, preserveState: true, onError: (errors) => setCodeError(errors.email), onFinish: () => setCodeBusy(false) },
        );
    };

    const field = (name: 'name' | 'phone', label: string, extra: Partial<ComponentProps<typeof Input>> = {}, hint?: string) => (
        <div className="grid gap-2">
            <Label htmlFor={`profile-${name}`}>{label}</Label>
            <Input id={`profile-${name}`} value={form.data[name]} onChange={(e) => form.setData(name, e.target.value)} aria-invalid={!!form.errors[name]} {...extra} />
            {hint && <p className="text-muted-foreground text-xs">{hint}</p>}
            <FieldError message={form.errors[name]} />
        </div>
    );

    return (
        <Section title={t('account.profile.title')} name="profile">
            <form onSubmit={submit} className="grid gap-5" data-profile-form>
                {field('name', t('account.profile.name'), { autoComplete: 'name', required: true })}

                <div className="grid gap-2">
                    <Label htmlFor="profile-email" className="flex items-center gap-2">
                        {t('account.profile.email')}
                        {!profile.email_verified && profile.email && !emailChanged && (
                            <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-normal text-amber-900">{t('account.profile.email_unverified')}</span>
                        )}
                    </Label>
                    <Input id="profile-email" type="email" dir="ltr" autoComplete="email" required value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)} aria-invalid={!!form.errors.email} />
                    <FieldError message={form.errors.email} />
                </div>

                {emailChanged && hasPassword && (
                    <div className="bg-muted/60 grid gap-2 rounded-lg p-3" data-email-password>
                        <Label htmlFor="profile-current-password">{t('account.profile.current_password')}</Label>
                        <p className="text-muted-foreground text-xs">{t('account.profile.password_for_email')}</p>
                        <Input id="profile-current-password" type="password" dir="ltr" autoComplete="current-password" value={form.data.current_password}
                            onChange={(e) => form.setData('current_password', e.target.value)} aria-invalid={!!form.errors.current_password} />
                        <FieldError message={form.errors.current_password} />
                    </div>
                )}

                {emailChanged && !hasPassword && (
                    <div className="bg-muted/60 grid gap-2 rounded-lg p-3" data-email-code>
                        <p className="text-muted-foreground text-xs">{t('account.profile.code_for_email')}</p>
                        <Button type="button" variant="outline" className="h-10 w-fit" onClick={sendCode} disabled={codeBusy || !form.data.email}>
                            <Mail /> {t('account.profile.send_code')}
                        </Button>
                        <FieldError message={codeError} />
                        <Label htmlFor="profile-email-code">{t('account.profile.email_code')}</Label>
                        <Input id="profile-email-code" inputMode="numeric" autoComplete="one-time-code" dir="ltr" className="font-mono tracking-widest" maxLength={12}
                            value={form.data.email_code} onChange={(e) => form.setData('email_code', e.target.value)} aria-invalid={!!form.errors.email_code} />
                        <FieldError message={form.errors.email_code} />
                    </div>
                )}

                {field('phone', t('account.profile.phone'), { type: 'tel', dir: 'ltr', autoComplete: 'tel', inputMode: 'tel', placeholder: '+966 55 123 4567' }, t('account.profile.phone_hint'))}

                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="grid gap-2">
                        <Label htmlFor="profile-locale">{t('account.profile.language')}</Label>
                        <NativeSelect id="profile-locale" value={form.data.locale} onChange={(e) => form.setData('locale', e.target.value as 'ar' | 'en')}>
                            {options.locales.map((l) => (
                                <option key={l} value={l}>
                                    {t(`account.profile.languages.${l}`)}
                                </option>
                            ))}
                        </NativeSelect>
                        <FieldError message={form.errors.locale} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="profile-country">{t('account.profile.country')}</Label>
                        <NativeSelect id="profile-country" value={form.data.country} onChange={(e) => form.setData('country', e.target.value)}>
                            <option value="">{t('account.profile.country_none')}</option>
                            {options.countries.map((c) => (
                                <option key={c} value={c}>
                                    {t(`checkout.countries.${c}`)}
                                </option>
                            ))}
                        </NativeSelect>
                        <FieldError message={form.errors.country} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="profile-currency">{t('account.profile.currency')}</Label>
                        <NativeSelect id="profile-currency" value={form.data.currency} onChange={(e) => form.setData('currency', e.target.value)}>
                            <option value="">{t('account.profile.currency_auto')}</option>
                            {options.currencies.map((c) => (
                                <option key={c} value={c}>
                                    {t(`checkout.currencies.${c}`)}
                                </option>
                            ))}
                        </NativeSelect>
                        <FieldError message={form.errors.currency} />
                    </div>
                </div>
                <p className="text-muted-foreground -mt-2 text-xs">{t('account.profile.country_hint')}</p>

                <Button type="submit" className="h-11 w-full text-base sm:w-fit sm:px-8" disabled={form.processing} data-profile-save>
                    {form.processing ? t('account.saving') : t('account.save')}
                </Button>
            </form>
        </Section>
    );
}
