import { Link, router, usePage } from '@inertiajs/react';
import { CalendarDays, Check, Copy, CreditCard, LayoutDashboard, LogIn, MapPin, Send, UserPlus, Users } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { TierBadge } from '@/components/wizard/TierBadge';
import WizardLayout from '@/components/wizard/WizardLayout';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';
import type { GuestRow, PlanSummary, WizardProps } from '@/types/wizard';

interface Props extends WizardProps {
    households: GuestRow[];
    seats: number;
    limit: number | null;
    plan: PlanSummary | null;
    planReasons: string[];
    plans: PlanSummary[];
    publishUrl: string;
    errors?: Record<string, string>;
}

function usePrice() {
    const { locale } = useT();
    return (cents: number, currency: string) =>
        new Intl.NumberFormat(locale === 'ar' ? 'ar-u-nu-arab' : 'en', { style: 'currency', currency, maximumFractionDigits: cents % 100 ? 2 : 0 }).format(cents / 100);
}

async function copy(text: string) {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch {
        // older browsers / insecure origins
        const area = Object.assign(document.createElement('textarea'), { value: text });
        document.body.appendChild(area);
        area.select();
        const ok = document.execCommand('copy');
        area.remove();
        return ok;
    }
}

function LinkRow({ guest }: { guest: GuestRow }) {
    const { t, tc } = useT();
    const [copied, setCopied] = useState(false);
    const whatsapp = `https://wa.me/?text=${encodeURIComponent(guest.message ?? guest.link ?? '')}`;

    return (
        <li className="bg-card grid gap-2 rounded-xl border p-3" data-guest-link>
            <div className="flex items-center justify-between gap-2">
                <span className="font-semibold">{guest.name}</span>
                <span className="text-muted-foreground text-xs">{tc('wizard.guests.seat_count', guest.seats)}</span>
            </div>
            <code className="bg-muted truncate rounded px-2 py-1.5 text-xs" dir="ltr">
                {guest.link}
            </code>
            <div className="grid grid-cols-2 gap-2">
                <Button
                    variant="outline"
                    className="h-11"
                    onClick={async () => {
                        if (guest.link && (await copy(guest.link))) {
                            setCopied(true);
                            setTimeout(() => setCopied(false), 1800);
                        }
                    }}
                >
                    {copied ? <Check /> : <Copy />} {copied ? t('wizard.review.copied') : t('wizard.review.copy')}
                </Button>
                <Button asChild className="h-11 bg-[#1f9d55] text-white hover:bg-[#1a8a4a]">
                    <a href={whatsapp} target="_blank" rel="noopener">
                        <Send /> {t('wizard.review.whatsapp')}
                    </a>
                </Button>
            </div>
        </li>
    );
}

/**
 * Step 5: summary, the plan price (payments come later), sign-up for guests,
 * then Publish and every family's personal link with WhatsApp sharing.
 */
export default function Review(props: Props) {
    const { t, tc, sep, locale } = useT();
    const { auth } = usePage<SharedProps>().props;
    const price = usePrice();
    const planName = (plan: PlanSummary) => (['free', 'premium', 'signature'].includes(plan.id) ? t(`wizard.tiers.${plan.id}`) : plan.name);
    const [publishing, setPublishing] = useState(false);
    const { config, event } = props;
    const date = config.sections.find((s) => s.type === 'date');
    const place = config.sections.find((s) => s.type === 'place');
    const when =
        date && date.type === 'date'
            ? new Intl.DateTimeFormat(locale === 'ar' ? 'ar-u-nu-arab' : 'en-GB', { dateStyle: 'full', timeStyle: 'short', timeZone: 'UTC' }).format(
                  // show the wall-clock time printed on the invitation, whatever the viewer's zone
                  new Date(date.start.slice(0, 19) + 'Z'),
              )
            : null;

    const publish = () => {
        setPublishing(true);
        router.post(props.publishUrl, {}, { preserveScroll: true, onFinish: () => setPublishing(false) });
    };

    const copyAll = async () => {
        const text = props.households.map((g) => `${g.name}: ${g.link}`).join('\n');
        if (await copy(text)) toast.success(t('wizard.review.copied_all'));
    };

    return (
        <WizardLayout
            step="review"
            eventId={event.id}
            title={event.published ? t('wizard.review.sent_title') : t('wizard.review.title')}
            subtitle={event.published ? t('wizard.review.sent_subtitle') : t('wizard.review.subtitle')}
            previewUrl={props.urls.preview}
        >
            <div className="grid gap-6">
                <section className="bg-card grid gap-3 rounded-xl border p-4">
                    <h2 className="text-lg font-bold">{event.title}</h2>
                    <ul className="text-muted-foreground grid gap-2 text-sm">
                        {when && (
                            <li className="flex items-center gap-2">
                                <CalendarDays className="size-4 shrink-0" /> {when}
                            </li>
                        )}
                        {place && place.type === 'place' && (
                            <li className="flex items-center gap-2">
                                <MapPin className="size-4 shrink-0" /> {place.name}
                            </li>
                        )}
                        <li className="flex items-center gap-2">
                            <Users className="size-4 shrink-0" /> {tc('wizard.guests.families', props.households.length)}
                            {sep}
                            {tc('wizard.guests.seat_count', props.seats)}
                        </li>
                    </ul>
                </section>

                {!event.published && props.plan && (
                    <section className="bg-card grid gap-3 rounded-xl border p-4" data-plan={props.plan.id}>
                        <div className="flex items-start justify-between gap-3">
                            <div className="grid gap-1">
                                <span className="text-muted-foreground text-xs font-semibold">{t('wizard.review.your_plan')}</span>
                                <span className="flex items-center gap-2 text-lg font-bold">
                                    <TierBadge tier={props.plan.id} className="px-2.5 py-1 text-sm" />
                                </span>
                            </div>
                            <span className="text-primary text-2xl font-bold">{props.plan.price_cents === 0 ? t('wizard.review.free') : price(props.plan.price_cents, props.plan.currency)}</span>
                        </div>
                        {props.planReasons.length > 0 && (
                            <p className="text-muted-foreground text-sm">
                                {t('wizard.review.because')} {props.planReasons.map((r) => t(`wizard.review.reasons.${r}`)).join('، ')}
                            </p>
                        )}
                        <div className="grid grid-cols-3 gap-2 text-center text-xs">
                            {props.plans.map((p) => (
                                <div key={p.id} className={cn('rounded-lg border p-2', p.id === props.plan?.id && 'border-primary bg-primary/5')}>
                                    <div className="font-semibold">{planName(p)}</div>
                                    <div>{p.price_cents === 0 ? t('wizard.review.free') : price(p.price_cents, p.currency)}</div>
                                    {p.guests && <div className="text-muted-foreground">{t('wizard.review.up_to', { guests: p.guests })}</div>}
                                </div>
                            ))}
                        </div>
                        <p className="flex items-start gap-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
                            <CreditCard className="mt-0.5 size-4 shrink-0" /> {t('wizard.review.payments_soon')}
                        </p>
                    </section>
                )}

                {!event.published && !auth.user && (
                    <section className="border-primary/30 bg-primary/5 grid gap-3 rounded-xl border p-4" data-auth-prompt>
                        <h2 className="font-bold">{t('wizard.review.account_title')}</h2>
                        <p className="text-muted-foreground text-sm">{t('wizard.review.account_text')}</p>
                        <div className="grid gap-2 sm:grid-cols-2">
                            <Button asChild className="h-12 text-base">
                                <Link href="/register">
                                    <UserPlus /> {t('register')}
                                </Link>
                            </Button>
                            <Button asChild variant="outline" className="h-12 text-base">
                                <Link href="/login">
                                    <LogIn /> {t('login')}
                                </Link>
                            </Button>
                        </div>
                    </section>
                )}

                {!event.published && auth.user && (
                    <section className="grid gap-2">
                        {props.errors?.publish && (
                            <p className="text-destructive text-sm" role="alert">
                                {props.errors.publish}
                            </p>
                        )}
                        <Button className="h-14 text-lg" onClick={publish} disabled={publishing || props.households.length === 0} data-publish>
                            <Send /> {t('wizard.review.publish')}
                        </Button>
                        {props.households.length === 0 && <p className="text-muted-foreground text-center text-sm">{t('wizard.review.need_guests')}</p>}
                    </section>
                )}

                {event.published && (
                    <section className="grid gap-3">
                        <div className="flex items-center justify-between gap-2">
                            <h2 className="font-semibold">{t('wizard.review.links')}</h2>
                            <Button variant="outline" onClick={copyAll} className="h-10">
                                <Copy /> {t('wizard.review.copy_all')}
                            </Button>
                        </div>
                        <ul className="grid gap-2">
                            {props.households.map((guest) => (
                                <LinkRow key={guest.id} guest={guest} />
                            ))}
                        </ul>
                        <Button asChild className="h-12 text-base">
                            <Link href="/dashboard">
                                <LayoutDashboard /> {t('wizard.review.to_dashboard')}
                            </Link>
                        </Button>
                    </section>
                )}
            </div>
        </WizardLayout>
    );
}
