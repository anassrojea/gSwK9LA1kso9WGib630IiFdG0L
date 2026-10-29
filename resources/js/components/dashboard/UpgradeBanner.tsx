import { Link } from '@inertiajs/react';
import { Gem, X } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { UpgradeHint } from '@/types/account';

const STORAGE_PREFIX = 'daawa.upgrade-hint.';

/**
 * A quiet, dismissible "what upgrading unlocks" note on the event dashboard:
 * Free events, or published ones whose plan no longer covers them. Dismissing
 * is remembered per event in this browser; a new reason (another gap in the
 * plan) shows it again.
 */
export function UpgradeBanner({ eventId, hint, upgradeUrl }: { eventId: string; hint: UpgradeHint; upgradeUrl: string }) {
    const { t, locale } = useT();
    const signature = [hint.plan, ...hint.missing].join(',');
    const key = STORAGE_PREFIX + eventId;
    const [hidden, setHidden] = useState(true);

    useEffect(() => {
        try {
            setHidden(window.localStorage.getItem(key) === signature);
        } catch {
            setHidden(false);
        }
    }, [key, signature]);

    if (hidden) return null;

    const dismiss = () => {
        setHidden(true);
        try {
            window.localStorage.setItem(key, signature);
        } catch {
            // private mode: dismissed for this visit only
        }
    };

    const list = (items: string[]) => items.join(locale === 'ar' ? '، ' : ', ');
    const unlocks = hint.unlocks.map((f) => t(`account.features.${f}`));
    if (hint.next_guests) unlocks.push(t('account.upgrade_hint.guests', { count: hint.next_guests }));

    return (
        <section className="border-primary/20 bg-primary/5 relative grid gap-3 rounded-xl border p-4 pe-11 sm:grid-cols-[1fr_auto] sm:items-center" data-upgrade-banner aria-labelledby="upgrade-banner-title">
            <div className="grid gap-1">
                <h2 id="upgrade-banner-title" className="flex items-center gap-2 font-semibold">
                    <Gem className="text-primary size-4" /> {t('account.upgrade_hint.title')}
                </h2>
                {hint.missing.length > 0 ? (
                    <p className="text-sm">{t('account.upgrade_hint.missing', { reasons: list(hint.missing.map((r) => t(`wizard.review.reasons.${r}`))) })}</p>
                ) : (
                    hint.next && (
                        <p className="text-muted-foreground text-sm">
                            {t('account.upgrade_hint.free', { plan: t(`wizard.tiers.${hint.next}`) })} {list(unlocks)}.
                        </p>
                    )
                )}
            </div>
            <Button asChild variant="outline" className="h-10 w-full sm:w-fit">
                <Link href={upgradeUrl} data-upgrade-banner-cta>
                    {t('account.upgrade_hint.cta')}
                </Link>
            </Button>
            <button type="button" onClick={dismiss} className="text-muted-foreground hover:text-foreground absolute end-2 top-2 grid size-8 place-items-center rounded-md" aria-label={t('account.upgrade_hint.dismiss')} data-upgrade-banner-dismiss>
                <X className="size-4" />
            </button>
        </section>
    );
}
