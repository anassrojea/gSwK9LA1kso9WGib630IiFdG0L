import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { FieldError } from '@/components/FieldError';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import GuestLayout from '@/layouts/GuestLayout';
import { useT } from '@/lib/i18n';

export default function ResetPassword({ email, token }: { email: string; token: string }) {
    const { t } = useT();
    const form = useForm({ token, email, password: '', password_confirmation: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <GuestLayout title={t('reset_password')}>
            <Head title={t('reset_password')} />
            <form onSubmit={submit} className="grid gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="email">{t('email')}</Label>
                    <Input id="email" type="email" dir="ltr" required value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                    <FieldError message={form.errors.email} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="password">{t('password')}</Label>
                    <Input id="password" type="password" dir="ltr" required value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                    <FieldError message={form.errors.password} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="password_confirmation">{t('password_confirmation')}</Label>
                    <Input id="password_confirmation" type="password" dir="ltr" required value={form.data.password_confirmation}
                        onChange={(e) => form.setData('password_confirmation', e.target.value)} />
                </div>
                <Button type="submit" disabled={form.processing}>{t('reset_password')}</Button>
            </form>
        </GuestLayout>
    );
}
