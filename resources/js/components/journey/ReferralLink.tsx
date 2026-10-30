import { Check, Copy, MessageCircle } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { copyText } from '@/lib/clipboard';
import { useT } from '@/lib/i18n';

/** The host's /r/{code} link with copy and WhatsApp share (journey page and the Billing tab panel). */
export function ReferralLink({ link }: { link: string }) {
    const { t } = useT();
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        if (await copyText(link)) {
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        }
    };

    return (
        <div className="grid gap-2">
            <div className="bg-muted/60 flex min-w-0 items-center gap-2 rounded-lg border p-2 ps-3">
                <bdi className="min-w-0 flex-1 truncate font-mono text-sm" dir="ltr" data-referral-link>
                    {link}
                </bdi>
                <Button type="button" size="sm" variant={copied ? 'secondary' : 'default'} className="h-9 shrink-0" onClick={copy} data-referral-copy>
                    {copied ? <Check /> : <Copy />} {copied ? t('journey.referrals.copied') : t('journey.referrals.copy')}
                </Button>
            </div>
            <Button asChild variant="outline" className="h-10 w-full sm:w-fit">
                <a href={`https://wa.me/?text=${encodeURIComponent(t('journey.referrals.share_text', { link }))}`} target="_blank" rel="noopener noreferrer">
                    <MessageCircle /> {t('journey.referrals.share')}
                </a>
            </Button>
        </div>
    );
}
