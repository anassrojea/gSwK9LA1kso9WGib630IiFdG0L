import { Link, router, usePage } from '@inertiajs/react';
import { ChevronDown, Languages, LogOut, Receipt, ShieldCheck, UserRound } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';

/**
 * The top bar's account menu: name, My account, My orders, language, Admin, Log out.
 * On phones the trigger shrinks to the initial; the menu itself is the same.
 */
export function AccountMenu() {
    const { t, locale, dir } = useT();
    const { auth } = usePage<SharedProps>().props;
    const user = auth.user;

    if (!user) return null;

    const next = locale === 'ar' ? 'en' : 'ar';
    const initial = (user.name.trim()[0] ?? '?').toUpperCase();

    return (
        <DropdownMenu dir={dir}>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" className="h-10 gap-2 px-2" aria-label={t('account.menu_label')} data-account-menu>
                    <span className="bg-primary/10 text-primary grid size-8 place-items-center rounded-full text-sm font-bold" aria-hidden>
                        {initial}
                    </span>
                    <span className="hidden max-w-40 truncate text-sm sm:inline">
                        <bdi>{user.name}</bdi>
                    </span>
                    <ChevronDown className="text-muted-foreground size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-64">
                <div className="grid gap-0.5 px-2 py-2">
                    <span className="truncate text-sm font-semibold">
                        <bdi>{user.name}</bdi>
                    </span>
                    {user.email && (
                        <span className="text-muted-foreground truncate text-xs">
                            <bdi dir="ltr">{user.email}</bdi>
                        </span>
                    )}
                </div>
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                    <Link href="/account" data-nav-account>
                        <UserRound /> {t('account.menu')}
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Link href="/dashboard/orders" data-nav-orders>
                        <Receipt /> {t('orders.title')}
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    {/* full reload: <html dir> and the Blade root view change with the locale */}
                    <a href={`/locale/${next}`} hrefLang={next} data-nav-locale>
                        <Languages /> {t('switch_language')}
                    </a>
                </DropdownMenuItem>
                {user.role === 'admin' && (
                    <DropdownMenuItem asChild>
                        <a href="/admin" data-nav-admin>
                            <ShieldCheck /> Admin
                        </a>
                    </DropdownMenuItem>
                )}
                <DropdownMenuSeparator />
                <DropdownMenuItem onSelect={() => router.post('/logout')} data-nav-logout>
                    <LogOut className="rtl:rotate-180" /> {t('logout')}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
