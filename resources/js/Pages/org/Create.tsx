import { Head, useForm } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import type { FormEvent } from 'react';

import { OrgProfileFields, type ProfileForm } from '@/components/org/OrgProfileFields';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout';
import { useT } from '@/lib/i18n';

/**
 * GET /org/create (phase 8A): a venue, planner or company, with its logo.
 */
export default function OrgCreate({ types }: { types: string[] }) {
    const { t } = useT();
    const form = useForm<ProfileForm>({ name: '', type: types[0] ?? 'venue', city: '', contact_phone: '', contact_email: '', logo: null });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/org', { forceFormData: true });
    };

    return (
        <AppLayout>
            <Head title={t('org.create.title')} />
            <form onSubmit={submit} className="mx-auto grid max-w-2xl gap-6 rounded-xl border bg-white p-5 sm:p-8" data-org-create>
                <div className="grid gap-1">
                    <h1 className="flex items-center gap-2 text-2xl font-bold">
                        <Building2 className="text-primary size-6" /> {t('org.create.title')}
                    </h1>
                    <p className="text-muted-foreground text-sm">{t('org.create.subtitle')}</p>
                </div>
                <OrgProfileFields form={{ data: form.data, errors: form.errors, setData: (k, v) => form.setData(k, v as never) }} types={types} />
                <Button type="submit" size="lg" className="h-12" disabled={form.processing}>
                    {t('org.create.submit')}
                </Button>
            </form>
        </AppLayout>
    );
}
