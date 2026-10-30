import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { MailCheck } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

/** POST /email/verification-notification (Fortify), throttled to 3 per 10 minutes. */
export const RESEND_URL = '/email/verification-notification';

/** True while the signed-in account still has to verify its email (shared auth.user.email_verified). */
export function useNeedsVerification(): boolean {
    const { auth } = usePage<SharedProps>().props;
    return !!auth.user && auth.user.email_verified === false;
}

type State = 'idle' | 'busy' | 'sent' | 'throttled' | 'failed';

/**
 * "Confirm your email to pay or publish", with a resend button. Shown above
 * the pay button, next to Publish and on the profile tab. The server refuses
 * the same actions (App\Services\Account\EmailVerification); drafts stay editable.
 */
export function VerifyEmailNotice({ action, className }: { action: 'checkout' | 'publish' | 'profile'; className?: string }) {
    const { t } = useT();
    const { auth } = usePage<SharedProps>().props;
    const [state, setState] = useState<State>('idle');

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
        <div role="status" data-verify-notice={action} className={cn('grid gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-amber-950', className)}>
            <div className="flex items-start gap-2">
                <MailCheck className="mt-0.5 size-5 shrink-0 text-amber-700" />
                <div className="grid gap-1">
                    <p className="text-sm font-semibold">{t(`verify.prompt.${action}`)}</p>
                    <p className="text-xs">
                        {t('verify.prompt.sent_to')} <bdi dir="ltr" className="font-medium">{auth.user?.email}</bdi>
                    </p>
                </div>
            </div>
            <div className="flex flex-wrap items-center gap-2 ps-7">
                <Button type="button" size="sm" variant="outline" className="h-9 bg-white" onClick={resend} disabled={state === 'busy' || state === 'sent'} data-verify-resend>
                    {t('verify.resend')}
                </Button>
                {state === 'sent' && <span className="text-xs text-emerald-700">{t('verify.resent')}</span>}
                {state === 'throttled' && <span className="text-xs text-red-700">{t('verify.throttled')}</span>}
                {state === 'failed' && <span className="text-xs text-red-700">{t('verify.failed')}</span>}
            </div>
        </div>
    );
}
