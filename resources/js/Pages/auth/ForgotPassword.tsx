import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { FieldError } from '@/components/FieldError';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import GuestLayout from '@/layouts/GuestLayout';
import { useT } from '@/lib/i18n';

export default function ForgotPassword({ status }: { status?: string }) {
    const { t } = useT();
    const form = useForm({ email: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/forgot-password');
    };

    return (
        <GuestLayout title={t('forgot_password')}>
            <Head title={t('forgot_password')} />
            {status && <p className="mb-4 text-sm text-emerald-700">{status}</p>}
            <form onSubmit={submit} className="grid gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="email">{t('email')}</Label>
                    <Input id="email" type="email" dir="ltr" required value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)} aria-invalid={!!form.errors.email} />
                    <FieldError message={form.errors.email} />
                </div>
                <Button type="submit" disabled={form.processing}>{t('send_reset_link')}</Button>
            </form>
        </GuestLayout>
    );
}
