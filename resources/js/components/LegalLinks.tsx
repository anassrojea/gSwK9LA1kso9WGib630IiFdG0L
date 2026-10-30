import { Fragment } from 'react';

import { useT } from '@/lib/i18n';

type LegalPage = 'terms' | 'privacy' | 'refund' | 'cookies';

/**
 * A sentence from lang app.php "site" with :placeholders replaced by links to the
 * legal pages (public site, Blade), e.g. "By creating an account you agree to the :terms and the :privacy."
 * `links` maps each placeholder to a page; the link text is t('site.{page}').
 */
export function LegalLinks({ text, links }: { text: string; links: Record<string, LegalPage> }) {
    const { t } = useT();
    const parts = text.split(/(:[a-z]+)/g);

    return (
        <>
            {parts.map((part, i) => {
                const page = part.startsWith(':') ? links[part.slice(1)] : undefined;
                if (!page) return <Fragment key={i}>{part}</Fragment>;
                return (
                    <a key={i} href={t(`site.urls.${page}`)} target="_blank" rel="noopener" className="text-primary underline underline-offset-2" data-legal-link={page}>
                        {t(`site.${page}`)}
                    </a>
                );
            })}
        </>
    );
}
