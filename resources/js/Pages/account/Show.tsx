import { Head, Link, usePage } from '@inertiajs/react';
import { CreditCard, Lock, ShieldCheck, UserRound } from 'lucide-react';

import { BillingTab } from '@/components/account/BillingTab';
import { PrivacyTab } from '@/components/account/PrivacyTab';
import { ProfileTab } from '@/components/account/ProfileTab';
import { SecurityTab } from '@/components/account/SecurityTab';
import AppLayout from '@/layouts/AppLayout';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';
import type { AccountPageProps, AccountTab } from '@/types/account';

const ICONS: Record<AccountTab, typeof UserRound> = { profile: UserRound, security: Lock, billing: CreditCard, privacy: ShieldCheck };

/**
 * GET /account[/security|/billing|/privacy]: one URL per tab, so a tab can be linked and reloaded.
 */
export default function AccountShow(props: AccountPageProps) {
    const { t } = useT();
    const { flash } = usePage<SharedProps>().props;
    const { tab, urls, account } = props;

    return (
        <AppLayout>
            <Head title={`${t(`account.tabs.${tab}`)} · ${t('account.title')}`} />
            <div className="mx-auto grid max-w-3xl gap-5" data-account={tab}>
                <header className="grid gap-1">
                    <h1 className="text-2xl font-bold">{t('account.title')}</h1>
                    <p className="text-muted-foreground truncate text-sm">
                        <bdi>{account.name}</bdi>
                        {account.email && (
                            <>
                                {' · '}
                                <bdi dir="ltr">{account.email}</bdi>
                            </>
                        )}
                    </p>
                </header>

                <nav aria-label={t('account.title')}>
                    <ul className="bg-muted grid grid-cols-2 gap-1 rounded-lg p-1 sm:grid-cols-4">
                        {(Object.keys(urls) as AccountTab[]).map((key) => {
                            const Icon = ICONS[key];
                            return (
                                <li key={key}>
                                    <Link
                                        href={urls[key]}
                                        preserveScroll
                                        className={cn(
                                            'inline-flex h-10 w-full items-center justify-center gap-1.5 rounded-md px-3 text-sm font-medium whitespace-nowrap transition-colors',
                                            key === tab ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                                        )}
                                        aria-current={key === tab ? 'page' : undefined}
                                        data-account-tab={key}
                                    >
                                        <Icon className="size-4" />
                                        {t(`account.tabs.${key}`)}
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </nav>

                {flash?.status && (
                    <p className="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-900" role="status" data-account-status>
                        {flash.status}
                    </p>
                )}
                {flash?.warning && (
                    <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-950" role="alert" data-account-warning>
                        {flash.warning}
                    </p>
                )}

                {tab === 'profile' && props.profile && props.options && <ProfileTab profile={props.profile} options={props.options} hasPassword={account.has_password} />}
                {tab === 'security' && props.security && <SecurityTab security={props.security} hasPassword={account.has_password} />}
                {tab === 'billing' && props.billing && <BillingTab billing={props.billing} />}
                {tab === 'privacy' && props.privacy && <PrivacyTab privacy={props.privacy} account={account} />}
            </div>
        </AppLayout>
    );
}
