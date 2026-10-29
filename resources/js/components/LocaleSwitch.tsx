import { Languages } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';

export function LocaleSwitch() {
    const { t, locale } = useT();
    const next = locale === 'ar' ? 'en' : 'ar';

    return (
        <Button variant="ghost" size="sm" asChild>
            {/* full reload: <html dir> and the Blade root view change with the locale */}
            <a href={`/locale/${next}`} hrefLang={next}>
                <Languages />
                {t('switch_language')}
            </a>
        </Button>
    );
}
