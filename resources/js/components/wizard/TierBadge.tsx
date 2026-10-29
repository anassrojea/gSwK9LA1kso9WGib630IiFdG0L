import { Crown, Gem, Sparkle } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { useT } from '@/lib/i18n';
import type { Tier } from '@/types/wizard';

export function TierBadge({ tier, className }: { tier: Tier | string; className?: string }) {
    const { t } = useT();
    const known: Tier = tier === 'premium' || tier === 'signature' ? tier : 'free';
    const icon = { free: <Sparkle />, premium: <Gem />, signature: <Crown /> }[known];

    return (
        <Badge variant={known} className={className}>
            {icon}
            {t(`wizard.tiers.${known}`)}
        </Badge>
    );
}
