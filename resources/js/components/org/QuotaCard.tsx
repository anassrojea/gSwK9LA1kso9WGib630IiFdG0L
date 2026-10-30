import { Crown } from 'lucide-react';

import { useT } from '@/lib/i18n';
import type { OrgQuota } from '@/types/org';

/** Signature events used this month (OrgQuota), with when the count resets. */
export function QuotaCard({ quota }: { quota: OrgQuota }) {
    const { t, locale } = useT();
    const pct = quota.limit > 0 ? Math.min(100, Math.round((quota.used / quota.limit) * 100)) : 0;
    const date = quota.resets_at ? new Intl.DateTimeFormat(locale === 'ar' ? 'ar-u-nu-arab' : 'en-GB', { day: 'numeric', month: 'long' }).format(new Date(quota.resets_at)) : '';

    return (
        <section className="grid gap-2 rounded-xl border bg-white p-4" data-quota={`${quota.used}/${quota.limit}`}>
            <h2 className="flex items-center gap-2 text-sm font-semibold">
                <Crown className="size-4 text-violet-700" /> {t('org.quota.label')}
            </h2>
            {quota.active ? (
                <>
                    <p className="text-2xl font-bold tabular-nums">{t('org.quota.used', { used: quota.used, limit: quota.limit })}</p>
                    <div className="bg-muted h-2 overflow-hidden rounded-full" role="progressbar" aria-valuenow={quota.used} aria-valuemin={0} aria-valuemax={quota.limit}>
                        <div className={pct >= 100 ? 'h-full bg-red-500' : pct >= 80 ? 'h-full bg-amber-500' : 'h-full bg-violet-600'} style={{ width: `${pct}%` }} />
                    </div>
                    {date && <p className="text-muted-foreground text-xs">{t('org.quota.resets', { date })}</p>}
                </>
            ) : (
                <p className="text-muted-foreground text-sm">{t('org.quota.none')}</p>
            )}
        </section>
    );
}
