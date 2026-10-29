import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { LocaleSwitch } from '@/components/LocaleSwitch';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';

export default function AppLayout({ children }: { children: ReactNode }) {
    const { t } = useT();
    const { auth } = usePage<SharedProps>().props;

    return (
        <div className="min-h-svh bg-muted/40">
            <header className="border-b bg-background">
                <div className="mx-auto flex h-14 max-w-5xl items-center justify-between gap-4 px-4">
                    <Link href="/dashboard" className="text-lg font-bold text-primary">
                        {t('name')}
                    </Link>
                    <div className="flex items-center gap-2">
                        <span className="hidden text-sm text-muted-foreground sm:inline">{auth.user?.name}</span>
                        {auth.user && (
                            <Button variant="ghost" size="sm" asChild>
                                <Link href="/dashboard/orders" data-nav-orders>
                                    {t('orders.menu')}
                                </Link>
                            </Button>
                        )}
                        <LocaleSwitch />
                        {auth.user?.role === 'admin' && (
                            <Button variant="outline" size="sm" asChild>
                                <a href="/admin">Admin</a>
                            </Button>
                        )}
                        <Button variant="ghost" size="sm" asChild>
                            <Link href="/logout" method="post" as="button">
                                {t('logout')}
                            </Link>
                        </Button>
                    </div>
                </div>
            </header>
            <main className="mx-auto max-w-5xl px-4 py-8">{children}</main>
        </div>
    );
}
