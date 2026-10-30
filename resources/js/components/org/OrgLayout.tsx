import { Link, usePage } from '@inertiajs/react';
import { Building2, CalendarDays, CreditCard, Settings, TriangleAlert, Users } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';

import { Toaster } from '@/components/ui/sonner';
import AppLayout from '@/layouts/AppLayout';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';
import type { Org } from '@/types/org';

type Tab = 'dashboard' | 'team' | 'billing' | 'settings';

/** Errors that are not tied to a form field: shown above the page. */
const PAGE_ERRORS = ['quota', 'publish', 'subscription', 'plan', 'gateway', 'currency', 'portal', 'renew', 'invitation', 'member', 'user_id', 'role'];

/**
 * The /org/{org} frame (phase 8A): the organization's name and logo on its
 * brand color (white label), the tabs, and the flash / page errors.
 */
export function OrgLayout({ org, active, children, wide = true }: { org: Org; active: Tab | null; children: ReactNode; wide?: boolean }) {
    const { t, sep } = useT();
    const { flash, errors } = usePage<SharedProps & { errors: Record<string, string> }>().props;
    const brand = org.white_label.brand_color;
    const pageErrors = PAGE_ERRORS.map((k) => errors?.[k]).filter(Boolean) as string[];

    const tabs: { id: Tab; href: string; label: string; icon: ReactNode; show: boolean }[] = [
        { id: 'dashboard', href: org.urls.dashboard, label: t('org.nav.dashboard'), icon: <CalendarDays />, show: true },
        { id: 'team', href: org.urls.team, label: t('org.nav.team'), icon: <Users />, show: true },
        { id: 'billing', href: org.urls.billing, label: t('org.nav.billing'), icon: <CreditCard />, show: org.can.billing },
        { id: 'settings', href: org.urls.settings, label: t('org.nav.settings'), icon: <Settings />, show: org.can.manage },
    ];

    return (
        <AppLayout wide={wide}>
            <Toaster />
            <div
                className={cn('mb-5 overflow-hidden rounded-xl border', brand ? 'text-white' : 'bg-white')}
                style={brand ? ({ backgroundColor: brand } as CSSProperties) : undefined}
                data-org-header
                data-brand={brand ?? undefined}
            >
                <div className="flex items-center gap-3 px-4 py-4">
                    {org.logo_url ? (
                        <img src={org.logo_url} alt="" className="size-12 shrink-0 rounded-lg bg-white object-contain p-1" />
                    ) : (
                        <span className={cn('grid size-12 shrink-0 place-items-center rounded-lg', brand ? 'bg-white/15' : 'bg-primary/10 text-primary')}>
                            <Building2 className="size-6" />
                        </span>
                    )}
                    <div className="grid min-w-0 gap-0.5">
                        <h1 className="truncate text-xl font-bold">
                            <bdi>{org.name}</bdi>
                        </h1>
                        <p className={cn('truncate text-sm', brand ? 'text-white/80' : 'text-muted-foreground')}>
                            {[t(`org.types.${org.type}`), org.city, org.role ? t(`org.roles.${org.role}`) : null].filter(Boolean).join(sep)}
                        </p>
                    </div>
                </div>
                <nav className={cn('flex gap-1 overflow-x-auto px-2 pb-2', brand ? '' : 'border-t pt-2')} aria-label={org.name}>
                    {tabs
                        .filter((tab) => tab.show)
                        .map((tab) => (
                            <Link
                                key={tab.id}
                                href={tab.href}
                                aria-current={active === tab.id ? 'page' : undefined}
                                data-org-tab={tab.id}
                                className={cn(
                                    'inline-flex h-10 shrink-0 items-center gap-1.5 rounded-lg px-3 text-sm font-medium [&>svg]:size-4',
                                    brand
                                        ? active === tab.id
                                            ? 'bg-white text-neutral-900'
                                            : 'text-white/90 hover:bg-white/15'
                                        : active === tab.id
                                          ? 'bg-primary text-primary-foreground'
                                          : 'text-muted-foreground hover:bg-muted',
                                )}
                            >
                                {tab.icon}
                                {tab.label}
                            </Link>
                        ))}
                </nav>
            </div>

            {flash?.status && (
                <p className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status" data-flash>
                    {flash.status}
                </p>
            )}
            {flash?.warning && (
                <p className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950" role="alert">
                    {flash.warning}
                </p>
            )}
            {pageErrors.map((message) => (
                <p key={message} className="mb-4 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert" data-page-error>
                    <TriangleAlert className="mt-0.5 size-4 shrink-0" /> {message}
                </p>
            ))}

            {children}
        </AppLayout>
    );
}
