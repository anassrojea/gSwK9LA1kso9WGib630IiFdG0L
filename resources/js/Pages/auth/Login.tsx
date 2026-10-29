import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { KeyRound, Mail } from 'lucide-react';
import { useState, type FormEvent } from 'react';

import { GoogleButton, OrDivider, useGoogleEnabled } from '@/components/auth/GoogleButton';
import { FieldError } from '@/components/FieldError';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import GuestLayout from '@/layouts/GuestLayout';
import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';

interface Props {
    status?: string;
    canResetPassword: boolean;
    /** "Email me a code" is available */
    loginCodes?: boolean;
    /** a code was sent to this address: show the code step */
    codeEmail?: string | null;
}

function PasswordForm({ canResetPassword }: { canResetPassword: boolean }) {
    const { t } = useT();
    const form = useForm({ email: '', password: '', remember: false });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    };

    return (
        <form onSubmit={submit} className="grid gap-4" data-login-password>
            <div className="grid gap-2">
                <Label htmlFor="email">{t('email')}</Label>
                <Input id="email" type="email" dir="ltr" autoComplete="username" autoFocus required
                    value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} aria-invalid={!!form.errors.email} />
                <FieldError message={form.errors.email} />
            </div>
            <div className="grid gap-2">
                <div className="flex items-center justify-between">
                    <Label htmlFor="password">{t('password')}</Label>
                    {canResetPassword && (
                        <Link href="/forgot-password" className="text-sm text-muted-foreground underline-offset-4 hover:underline">
                            {t('forgot_password')}
                        </Link>
                    )}
                </div>
                <Input id="password" type="password" dir="ltr" autoComplete="current-password" required
                    value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} aria-invalid={!!form.errors.password} />
                <FieldError message={form.errors.password} />
            </div>
            <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} />
                {t('remember')}
            </label>
            <Button type="submit" className="h-11 text-base" disabled={form.processing}>{t('login')}</Button>
        </form>
    );
}

function CodeRequestForm() {
    const { t } = useT();
    const form = useForm({ email: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/login/code', { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="grid gap-4" data-login-code-request>
            <p className="text-muted-foreground text-sm">{t('auth.code.email_hint')}</p>
            <div className="grid gap-2">
                <Label htmlFor="code-email">{t('email')}</Label>
                <Input id="code-email" type="email" dir="ltr" autoComplete="email" autoFocus required
                    value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} aria-invalid={!!form.errors.email} />
                <FieldError message={form.errors.email} />
            </div>
            <Button type="submit" className="h-11 text-base" disabled={form.processing}>
                <Mail /> {t('auth.code.send')}
            </Button>
        </form>
    );
}

function CodeVerifyForm({ email }: { email: string }) {
    const { t } = useT();
    const form = useForm({ email, code: '', name: '', remember: true });
    const resend = useForm({ email });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/login/code/verify', { preserveScroll: true, onError: () => form.reset('code') });
    };

    return (
        <div className="grid gap-4" data-login-code-verify>
            <form onSubmit={submit} className="grid gap-4">
                <p className="text-muted-foreground text-sm">
                    {t('auth.code.sent_to', { email: '' })}
                    <bdi dir="ltr" className="text-foreground font-medium">{email}</bdi>
                </p>
                <div className="grid gap-2">
                    <Label htmlFor="code">{t('auth.code.code_label')}</Label>
                    <Input id="code" inputMode="numeric" autoComplete="one-time-code" dir="ltr" autoFocus required maxLength={12}
                        className="h-12 text-center font-mono text-2xl tracking-[0.4em]"
                        value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} aria-invalid={!!form.errors.code} />
                    <FieldError message={form.errors.code} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="code-name">{t('auth.code.name_label')}</Label>
                    <Input id="code-name" autoComplete="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                    <p className="text-muted-foreground text-xs">{t('auth.code.name_hint')}</p>
                    <FieldError message={form.errors.name} />
                </div>
                <Button type="submit" className="h-11 text-base" disabled={form.processing}>{t('auth.code.verify')}</Button>
            </form>
            <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                <button type="button" className="text-primary underline-offset-4 hover:underline disabled:opacity-50" disabled={resend.processing}
                    onClick={() => resend.post('/login/code', { preserveScroll: true })} data-code-resend>
                    {t('auth.code.resend')}
                </button>
                <button type="button" className="text-muted-foreground underline-offset-4 hover:underline" onClick={() => router.post('/login/code/reset')} data-code-change-email>
                    {t('auth.code.change_email')}
                </button>
            </div>
            <FieldError message={resend.errors.email} />
        </div>
    );
}

export default function Login({ status, canResetPassword, loginCodes = false, codeEmail }: Props) {
    const { t } = useT();
    const { flash } = usePage<SharedProps>().props;
    const google = useGoogleEnabled();
    const [mode, setMode] = useState<'password' | 'code'>(codeEmail ? 'code' : 'password');
    const message = flash?.status ?? status;

    return (
        <GuestLayout title={t('login')}>
            <Head title={t('login')} />
            {message && <p className="mb-4 rounded-md bg-emerald-50 p-3 text-sm text-emerald-800" role="status" data-login-status>{message}</p>}
            {flash?.warning && <p className="mb-4 rounded-md bg-amber-50 p-3 text-sm text-amber-900" role="alert" data-login-warning>{flash.warning}</p>}

            <div className="grid gap-4">
                {google && !(mode === 'code' && codeEmail) && (
                    <>
                        <GoogleButton />
                        <OrDivider />
                    </>
                )}

                {mode === 'password' || !loginCodes ? <PasswordForm canResetPassword={canResetPassword} /> : codeEmail ? <CodeVerifyForm email={codeEmail} /> : <CodeRequestForm />}

                {loginCodes && (
                    <Button
                        type="button"
                        variant="ghost"
                        className="h-11"
                        onClick={() => {
                            if (mode === 'code' && codeEmail) router.post('/login/code/reset', {}, { onSuccess: () => setMode('password') });
                            else setMode(mode === 'password' ? 'code' : 'password');
                        }}
                        data-login-mode={mode === 'password' ? 'code' : 'password'}
                    >
                        {mode === 'password' ? <Mail /> : <KeyRound />}
                        {mode === 'password' ? t('auth.code.use_code') : t('auth.code.use_password')}
                    </Button>
                )}

                <p className="text-center text-sm text-muted-foreground">
                    {t('no_account')}{' '}
                    <Link href="/register" className="text-primary underline-offset-4 hover:underline">{t('register')}</Link>
                </p>
            </div>
        </GuestLayout>
    );
}
