import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { MailCheck } from 'lucide-react';
import { useState } from 'react';

import { RESEND_URL } from '@/components/account/VerifyEmailNotice';
import { Button } from '@/components/ui/button';
import GuestLayout from '@/layouts/GuestLayout';
import { useT } from '@/lib/i18n';

/**
 * GET /email/verify (Fortify's verification notice): "Check your inbox", with
 * a throttled resend button. The link in the email lands back on the dashboard.
 */
export default function VerifyEmail({ email, status }: { email: string | null; status?: string | null }) {
    const { t } = useT();
    const [state, setState] = useState<'idle' | 'busy' | 'sent' | 'throttled' | 'failed'>(status === 'verification-link-sent' ? 'sent' : 'idle');

    const resend = async () => {
        setState('busy');
        try {
            await axios.post(RESEND_URL, {}, { headers: { Accept: 'application/json' } });
            setState('sent');
        } catch (err) {
            setState(axios.isAxiosError(err) && err.response?.status === 429 ? 'throttled' : 'failed');
        }
    };

    return (
        <GuestLayout title={t('verify.title')} description={t('verify.description')}>
            <Head title={t('verify.title')} />
            <div className="grid gap-4">
                <div className="bg-muted/60 flex items-center gap-3 rounded-lg p-3">
                    <MailCheck className="text-primary size-6 shrink-0" />
                    <p className="text-sm">
                        {t('verify.sent_to')}{' '}
                        <bdi dir="ltr" className="font-semibold">
                            {email}
                        </bdi>
                    </p>
                </div>
                <p className="text-muted-foreground text-sm">{t('verify.why')}</p>
                {state === 'sent' && <p className="text-sm text-emerald-700" data-verify-status="sent">{t('verify.resent')}</p>}
                {state === 'throttled' && <p className="text-sm text-red-700">{t('verify.throttled')}</p>}
                {state === 'failed' && <p className="text-sm text-red-700">{t('verify.failed')}</p>}
                <Button type="button" onClick={resend} disabled={state === 'busy'} data-verify-resend>
                    {t('verify.resend')}
                </Button>
                <div className="flex items-center justify-between text-sm">
                    <Link href="/account" className="text-muted-foreground hover:text-foreground underline-offset-4 hover:underline">
                        {t('verify.change_email')}
                    </Link>
                    <button type="button" onClick={() => router.post('/logout')} className="text-muted-foreground hover:text-foreground underline-offset-4 hover:underline">
                        {t('logout')}
                    </button>
                </div>
            </div>
        </GuestLayout>
    );
}
