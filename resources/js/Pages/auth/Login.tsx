import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { FieldError } from '@/components/FieldError';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import GuestLayout from '@/layouts/GuestLayout';
import { useT } from '@/lib/i18n';

export default function Login({ status, canResetPassword }: { status?: string; canResetPassword: boolean }) {
    const { t } = useT();
    const form = useForm({ email: '', password: '', remember: false });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    };

    return (
        <GuestLayout title={t('login')}>
            <Head title={t('login')} />
            {status && <p className="mb-4 text-sm text-emerald-700">{status}</p>}
            <form onSubmit={submit} className="grid gap-4">
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
                <Button type="submit" disabled={form.processing}>{t('login')}</Button>
                <p className="text-center text-sm text-muted-foreground">
                    {t('no_account')}{' '}
                    <Link href="/register" className="text-primary underline-offset-4 hover:underline">{t('register')}</Link>
                </p>
            </form>
        </GuestLayout>
    );
}
