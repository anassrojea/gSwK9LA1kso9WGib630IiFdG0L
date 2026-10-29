import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { LocaleSwitch } from '@/components/LocaleSwitch';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { useT } from '@/lib/i18n';

export default function GuestLayout({ title, description, children }: { title: string; description?: string; children: ReactNode }) {
    const { t } = useT();

    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-gradient-to-b from-rose-50 to-background p-4">
            <div className="flex w-full max-w-sm items-center justify-between">
                <Link href="/" className="text-xl font-bold text-primary">
                    {t('name')}
                </Link>
                <LocaleSwitch />
            </div>
            <Card className="w-full max-w-sm">
                <CardHeader>
                    <CardTitle className="text-xl">{title}</CardTitle>
                    {description && <CardDescription>{description}</CardDescription>}
                </CardHeader>
                <CardContent>{children}</CardContent>
            </Card>
        </div>
    );
}
