import { router } from '@inertiajs/react';
import {
    Baby,
    BriefcaseBusiness,
    Cake,
    CalendarHeart,
    DoorOpen,
    Flower2,
    Gem,
    GraduationCap,
    Heart,
    HeartHandshake,
    HouseHeart,
    Moon,
    MoonStar,
    PartyPopper,
    PlaneLanding,
    ScrollText,
    type LucideIcon,
} from 'lucide-react';
import { useState } from 'react';

import WizardLayout from '@/components/wizard/WizardLayout';
import { useAutosave } from '@/hooks/useAutosave';
import type { InvitationConfig } from '@/lib/config-schema';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { WizardProps } from '@/types/wizard';

type Occasion = InvitationConfig['occasion'];

export const OCCASION_ICONS: Record<Occasion, LucideIcon> = {
    wedding: Heart,
    engagement: Gem,
    henna: Flower2,
    birthday: Cake,
    graduation: GraduationCap,
    baby_welcome: Baby,
    iftar: Moon,
    katb_kitab: ScrollText,
    eid: MoonStar,
    housewarming: HouseHeart,
    hajj_return: PlaneLanding,
    welcome_home: DoorOpen,
    corporate: BriefcaseBusiness,
    save_the_date: CalendarHeart,
    thank_you: HeartHandshake,
    other: PartyPopper,
};

function Tiles({ occasions, selected, busy, onPick }: { occasions: Occasion[]; selected?: Occasion; busy?: Occasion | null; onPick: (o: Occasion) => void }) {
    const { t } = useT();

    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            {occasions.map((occasion) => {
                const Icon = OCCASION_ICONS[occasion];
                const active = occasion === selected;
                return (
                    <button
                        key={occasion}
                        type="button"
                        onClick={() => onPick(occasion)}
                        disabled={!!busy}
                        aria-pressed={active}
                        data-occasion={occasion}
                        className={cn(
                            'bg-card flex min-h-32 flex-col items-center justify-center gap-3 rounded-2xl border-2 p-4 text-center shadow-xs transition-all hover:border-primary/50 hover:shadow-md focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:opacity-60',
                            active ? 'border-primary bg-primary/5' : 'border-transparent',
                            busy === occasion && 'animate-pulse',
                        )}
                    >
                        <span className={cn('grid size-14 place-items-center rounded-full', active ? 'bg-primary text-primary-foreground' : 'bg-primary/10 text-primary')}>
                            <Icon className="size-7" />
                        </span>
                        <span className="text-base font-semibold">{t(`wizard.occasions.${occasion}`)}</span>
                    </button>
                );
            })}
        </div>
    );
}

/** Step 1 before a draft exists: picking a tile creates it (POST /create). */
function NewOccasion({ occasions }: { occasions: Occasion[] }) {
    const { t } = useT();
    const [busy, setBusy] = useState<Occasion | null>(null);

    const pick = (occasion: Occasion) => {
        setBusy(occasion);
        router.post('/create', { occasion }, { onFinish: () => setBusy(null) });
    };

    return (
        <WizardLayout step="occasion" title={t('wizard.occasion.title')} subtitle={t('wizard.occasion.subtitle')}>
            <Tiles occasions={occasions} busy={busy} onPick={pick} />
            <p className="text-muted-foreground mt-6 text-sm">{t('wizard.occasion.no_account')}</p>
        </WizardLayout>
    );
}

/** Step 1 for an existing draft: changing the occasion is an autosaved edit. */
function EditOccasion(props: WizardProps & { occasions: Occasion[] }) {
    const { t } = useT();
    const autosave = useAutosave(props.urls.config, props.config);

    const pick = (occasion: Occasion) => autosave.update((c) => ({ ...c, occasion }));

    return (
        <WizardLayout
            step="occasion"
            eventId={props.event.id}
            title={t('wizard.occasion.title')}
            subtitle={t('wizard.occasion.subtitle')}
            saveState={autosave.state}
            previewUrl={props.urls.preview}
            previewVersion={autosave.savedCount}
            beforeLeave={autosave.flush}
        >
            <Tiles occasions={props.occasions} selected={autosave.config.occasion} onPick={pick} />
        </WizardLayout>
    );
}

export default function Occasion(props: (WizardProps & { occasions: Occasion[] }) | { event: null; occasions: Occasion[] }) {
    if (!props.event) {
        return <NewOccasion occasions={props.occasions} />;
    }
    return <EditOccasion {...(props as WizardProps & { occasions: Occasion[] })} />;
}

