import { Link, router, usePage } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';

import { AccountMenu } from '@/components/AccountMenu';
import { LocaleSwitch } from '@/components/LocaleSwitch';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';

/** While an account deletion is pending, every page says so and offers the way back. */
function DeletionBanner({ date }: { date: string }) {
    const { t, locale } = useT();
    const formatted = new Intl.DateTimeFormat(locale === 'ar' ? 'ar-u-nu-arab' : 'en-GB', { dateStyle: 'long' }).format(new Date(date));

    return (
        <div className="border-b border-red-200 bg-red-50 text-red-950" role="alert" data-deletion-banner>
            <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-2 px-4 py-2 text-sm">
                <span className="inline-flex items-center gap-2">
                    <TriangleAlert className="size-4 shrink-0" />
                    {t('account.grace_banner', { date: formatted })}
                </span>
                <Button size="sm" variant="outline" className="h-8 border-red-300 bg-white" onClick={() => router.delete('/account/deletion', { preserveScroll: true })}>
                    {t('account.grace_cancel')}
                </Button>
            </div>
        </div>
    );
}

export default function AppLayout({ children }: { children: ReactNode }) {
    const { t } = useT();
    const { auth } = usePage<SharedProps>().props;

    return (
        <div className="min-h-svh bg-muted/40">
            <header className="border-b bg-background">
                <div className="mx-auto flex h-14 max-w-5xl items-center justify-between gap-2 px-4">
                    <Link href="/dashboard" className="text-lg font-bold text-primary">
                        {t('name')}
                    </Link>
                    <div className="flex items-center gap-1 sm:gap-2">
                        {auth.user ? (
                            <>
                                <Button variant="ghost" size="sm" asChild className="hidden sm:inline-flex">
                                    <Link href="/dashboard">{t('my_events')}</Link>
                                </Button>
                                <AccountMenu />
                            </>
                        ) : (
                            <LocaleSwitch />
                        )}
                    </div>
                </div>
            </header>
            {auth.user?.deletion_scheduled_for && <DeletionBanner date={auth.user.deletion_scheduled_for} />}
            <main className="mx-auto max-w-5xl px-4 py-8">{children}</main>
        </div>
    );
}
