import { ChevronDown } from 'lucide-react';
import * as React from 'react';

import { cn } from '@/lib/utils';

/** A styled native <select>: the phone's own picker is the best one on mobile. */
function NativeSelect({ className, children, ...props }: React.ComponentProps<'select'>) {
    return (
        <div className="relative">
            <select
                data-slot="native-select"
                className={cn(
                    'border-input flex h-11 w-full appearance-none rounded-md border bg-transparent ps-3 pe-9 text-base shadow-xs outline-none disabled:opacity-50 md:text-sm',
                    'focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:border-destructive',
                    className,
                )}
                {...props}
            >
                {children}
            </select>
            <ChevronDown className="text-muted-foreground pointer-events-none absolute end-3 top-1/2 size-4 -translate-y-1/2" />
        </div>
    );
}

export { NativeSelect };
