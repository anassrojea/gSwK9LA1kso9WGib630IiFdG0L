import type { Page } from '@inertiajs/core';
import { toast } from 'sonner';

import type { SharedProps } from '@/types';

/**
 * Show the session flash of an Inertia response as a toast. Called from each
 * action's onSuccess, so the same message twice in a row still shows twice.
 */
export function toastFlash(page: Page): void {
    const flash = (page.props as unknown as SharedProps).flash;
    if (flash?.warning) toast.warning(flash.warning);
    else if (flash?.status) toast.success(flash.status);
}
