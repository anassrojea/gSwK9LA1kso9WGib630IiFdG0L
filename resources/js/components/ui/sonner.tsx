import { Toaster as Sonner, type ToasterProps } from 'sonner';

import { useT } from '@/lib/i18n';

function Toaster(props: ToasterProps) {
    const { dir } = useT();

    return (
        <Sonner
            dir={dir}
            position="top-center"
            className="toaster group"
            style={{ '--normal-bg': 'var(--popover)', '--normal-text': 'var(--popover-foreground)', '--normal-border': 'var(--border)' } as React.CSSProperties}
            {...props}
        />
    );
}

export { Toaster };
