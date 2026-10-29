import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { GoogleButton, OrDivider, useGoogleEnabled } from '@/components/auth/GoogleButton';
import { FieldError } from '@/components/FieldError';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import GuestLayout from '@/layouts/GuestLayout';
import { useT } from '@/lib/i18n';

export default function Register({ loginCodes = false }: { loginCodes?: boolean }) {
    const { t } = useT();
    const google = useGoogleEnabled();
    const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/register', { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    const field = (name: 'name' | 'email' | 'password' | 'password_confirmation', label: string, type = 'text', autoComplete?: string) => (
        <div className="grid gap-2">
            <Label htmlFor={name}>{label}</Label>
            <Input id={name} type={type} dir={type === 'text' ? undefined : 'ltr'} autoComplete={autoComplete} required
                value={form.data[name]} onChange={(e) => form.setData(name, e.target.value)} aria-invalid={!!form.errors[name]} />
            <FieldError message={form.errors[name]} />
        </div>
    );

    return (
        <GuestLayout title={t('register')}>
            <Head title={t('register')} />
            {google && (
                <div className="mb-4 grid gap-4">
                    <GoogleButton />
                    <OrDivider />
                </div>
            )}
            <form onSubmit={submit} className="grid gap-4">
                {field('name', t('name_label'), 'text', 'name')}
                {field('email', t('email'), 'email', 'username')}
                {field('password', t('password'), 'password', 'new-password')}
                {field('password_confirmation', t('password_confirmation'), 'password', 'new-password')}
                <Button type="submit" className="h-11 text-base" disabled={form.processing}>{t('register')}</Button>
                {loginCodes && (
                    <Button asChild variant="ghost" className="h-11">
                        <Link href="/login" data-register-code>{t('auth.code.use_code')}</Link>
                    </Button>
                )}
                <p className="text-center text-sm text-muted-foreground">
                    {t('have_account')}{' '}
                    <Link href="/login" className="text-primary underline-offset-4 hover:underline">{t('login')}</Link>
                </p>
            </form>
        </GuestLayout>
    );
}
