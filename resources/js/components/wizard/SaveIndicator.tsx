import { AlertCircle, Check, CloudOff, Loader2 } from 'lucide-react';

import type { SaveState } from '@/hooks/useAutosave';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export function SaveIndicator({ state }: { state: SaveState }) {
    const { t } = useT();

    const view = {
        idle: null,
        pending: { icon: <Loader2 className="animate-spin" />, text: t('wizard.save.saving'), tone: 'text-muted-foreground' },
        saving: { icon: <Loader2 className="animate-spin" />, text: t('wizard.save.saving'), tone: 'text-muted-foreground' },
        saved: { icon: <Check />, text: t('wizard.save.saved'), tone: 'text-emerald-700' },
        invalid: { icon: <AlertCircle />, text: t('wizard.save.invalid'), tone: 'text-destructive' },
        offline: { icon: <CloudOff />, text: t('wizard.save.offline'), tone: 'text-amber-700' },
        error: { icon: <AlertCircle />, text: t('wizard.save.error'), tone: 'text-amber-700' },
    }[state];

    return (
        <span role="status" aria-live="polite" data-save-state={state} className={cn('inline-flex min-w-0 items-center gap-1.5 text-xs font-medium [&_svg]:size-4 [&_svg]:shrink-0', view?.tone)}>
            {view?.icon}
            <span className="truncate">{view?.text}</span>
        </span>
    );
}
