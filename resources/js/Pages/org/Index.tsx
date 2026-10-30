import { Head, Link } from '@inertiajs/react';
import { Building2, ChevronLeft, ChevronRight, Plus } from 'lucide-react';

import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout';
import { useT } from '@/lib/i18n';

interface OrgRow {
    id: string;
    name: string;
    type: string;
    city: string | null;
    logo_url: string | null;
    role: string;
    tier: string | null;
    events: number;
    url: string;
}

/**
 * GET /org: the organizations the user belongs to (phase 8A).
 */
export default function OrgIndex({ organizations }: { organizations: OrgRow[] }) {
    const { t, n, dir, sep } = useT();
    const Chevron = dir === 'rtl' ? ChevronLeft : ChevronRight;

    return (
        <AppLayout>
            <Head title={t('org.title')} />
            <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
                <h1 className="text-2xl font-bold">{t('org.title')}</h1>
                <Button asChild className="h-11">
                    <Link href="/org/create" data-org-new>
                        <Plus /> {t('org.new')}
                    </Link>
                </Button>
            </div>

            {organizations.length === 0 ? (
                <p className="text-muted-foreground rounded-xl border border-dashed bg-white p-6 text-center text-sm">{t('org.none')}</p>
            ) : (
                <ul className="grid gap-3 sm:grid-cols-2">
                    {organizations.map((o) => (
                        <li key={o.id}>
                            <Link href={o.url} className="flex items-center gap-3 rounded-xl border bg-white p-4 transition hover:shadow-sm" data-org-link={o.id}>
                                {o.logo_url ? (
                                    <img src={o.logo_url} alt="" className="size-12 rounded-lg object-contain" />
                                ) : (
                                    <span className="bg-primary/10 text-primary grid size-12 place-items-center rounded-lg">
                                        <Building2 className="size-6" />
                                    </span>
                                )}
                                <span className="grid min-w-0 flex-1 gap-0.5">
                                    <span className="truncate font-semibold">
                                        <bdi>{o.name}</bdi>
                                    </span>
                                    <span className="text-muted-foreground truncate text-sm">
                                        {[t(`org.types.${o.type}`), t(`org.roles.${o.role}`), t('org.events_count', { count: n(o.events) })].join(sep)}
                                    </span>
                                    {o.tier && <span className="text-xs font-medium text-violet-800">{t(`org.plans.${o.tier.replace(/_yearly$/, '')}.name`)}</span>}
                                </span>
                                <Chevron className="text-muted-foreground size-5 shrink-0" />
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </AppLayout>
    );
}
