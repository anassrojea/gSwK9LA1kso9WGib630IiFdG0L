import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

/** One block of an account tab: a titled card. */
export function Section({ title, description, children, className, tone, name }: { title: string; description?: ReactNode; children: ReactNode; className?: string; tone?: 'danger'; name?: string }) {
    return (
        <section className={cn('bg-card grid gap-4 rounded-xl border p-4 sm:p-6', tone === 'danger' && 'border-red-200', className)} data-section={name}>
            <div className="grid gap-1">
                <h2 className={cn('text-lg font-semibold', tone === 'danger' && 'text-red-700')}>{title}</h2>
                {description && <div className="text-muted-foreground text-sm">{description}</div>}
            </div>
            {children}
        </section>
    );
}
