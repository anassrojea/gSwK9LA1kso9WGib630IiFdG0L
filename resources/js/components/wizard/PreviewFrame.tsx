import { useEffect, useRef } from 'react';

import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * The real guest page (GET /create/{event}/preview) in an iframe at phone
 * width. Reloads after each successful save and keeps the scroll position,
 * so the host sees the part they just edited.
 */
export function PreviewFrame({ url, version, className }: { url: string; version: number; className?: string }) {
    const { t } = useT();
    const frame = useRef<HTMLIFrameElement>(null);
    const scrollY = useRef(0);
    const first = useRef(true);

    useEffect(() => {
        const el = frame.current;
        if (!el) return;
        if (first.current) {
            first.current = false;
            return;
        }
        try {
            scrollY.current = el.contentWindow?.scrollY ?? 0;
        } catch {
            scrollY.current = 0;
        }
        el.src = `${url}?v=${version}`;
    }, [url, version]);

    const restore = () => {
        const win = frame.current?.contentWindow;
        if (win && scrollY.current > 0) {
            // after the opening has laid out its scroll space
            win.requestAnimationFrame(() => win.scrollTo({ top: scrollY.current, behavior: 'instant' as ScrollBehavior }));
        }
    };

    return (
        <iframe
            ref={frame}
            src={url}
            title={t('wizard.preview.title')}
            onLoad={restore}
            className={cn('bg-background block h-full w-full border-0', className)}
            data-preview-frame
        />
    );
}
