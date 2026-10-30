import { Link, router } from '@inertiajs/react';
import { CalendarHeart, Sparkles } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { useT } from '@/lib/i18n';

/**
 * A save-the-date's dashboard (phase 8D): start the full invitation with the
 * same names, date, place and families (POST /events/{event}/save-the-date/upgrade).
 */
export function SaveTheDatePanel({ data }: { data: { upgrade_url: string; occasions: string[]; invitation_url: string | null } }) {
    const { t } = useT();
    const [occasion, setOccasion] = useState(data.occasions.includes('wedding') ? 'wedding' : data.occasions[0]);
    const [busy, setBusy] = useState(false);

    return (
        <section className="bg-primary/5 border-primary/30 grid gap-3 rounded-xl border p-4" data-save-the-date>
            <div className="flex items-start gap-3">
                <span className="bg-primary text-primary-foreground grid size-10 shrink-0 place-items-center rounded-full">
                    <CalendarHeart className="size-5" />
                </span>
                <div className="grid gap-1">
                    <h2 className="font-bold">{t('journey.save_the_date.upgrade_title')}</h2>
                    <p className="text-muted-foreground text-sm">{t('journey.save_the_date.upgrade_text')}</p>
                </div>
            </div>
            {data.invitation_url ? (
                <Button asChild className="h-11 w-full sm:w-fit">
                    <Link href={data.invitation_url}>
                        <Sparkles /> {t('journey.save_the_date.open_invitation')}
                    </Link>
                </Button>
            ) : (
                <div className="grid gap-2 sm:grid-cols-[minmax(0,16rem)_auto] sm:items-end">
                    <div className="grid gap-1.5">
                        <Label htmlFor="std-occasion">{t('journey.save_the_date.occasion')}</Label>
                        <NativeSelect id="std-occasion" value={occasion} onChange={(e) => setOccasion(e.target.value)}>
                            {data.occasions.map((o) => (
                                <option key={o} value={o}>
                                    {t(`wizard.occasions.${o}`)}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <Button
                        className="h-11"
                        disabled={busy}
                        onClick={() => {
                            setBusy(true);
                            router.post(data.upgrade_url, { occasion }, { onFinish: () => setBusy(false) });
                        }}
                        data-save-the-date-upgrade
                    >
                        <Sparkles /> {t('journey.save_the_date.upgrade')}
                    </Button>
                </div>
            )}
        </section>
    );
}
