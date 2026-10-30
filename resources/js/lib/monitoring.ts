/*
 * Error reporting and product analytics for the Inertia pages (never the guest
 * invitations, which load resources/js/invite.js only):
 *  - Sentry's browser SDK, imported on demand and only when VITE_SENTRY_DSN was
 *    set at build time (otherwise the code and its chunk are left out of the build).
 *  - PostHog events for the plan section 22 KPIs, through resources/js/lib/analytics.ts
 *    (needs VITE_POSTHOG_KEY and the visitor's consent): page views, draft_created,
 *    wizard_step, invitation_published, checkout_started. order_paid is sent by the server.
 */
import { router } from '@inertiajs/react';

import { capture, identify } from '@/lib/analytics';
import { scrubText, scrubValue } from '@/lib/scrub';

type Pending = { method: string; path: string; data: Record<string, unknown> };

function initSentry(): void {
    const dsn = import.meta.env.VITE_SENTRY_DSN;
    if (!dsn) return;

    import('@sentry/browser')
        .then((Sentry) => {
            Sentry.init({
                dsn,
                environment: import.meta.env.VITE_SENTRY_ENVIRONMENT || import.meta.env.MODE,
                tracesSampleRate: Number(import.meta.env.VITE_SENTRY_TRACES_SAMPLE_RATE || 0),
                sendDefaultPii: false,
                beforeSend(event) {
                    if (event.message) event.message = scrubText(event.message);
                    event.exception?.values?.forEach((value) => {
                        if (value.value) value.value = scrubText(value.value);
                    });
                    if (event.request) {
                        event.request.url = event.request.url ? scrubText(event.request.url.split('?')[0]) : event.request.url;
                        delete event.request.query_string;
                        delete event.request.cookies;
                        event.request.data = scrubValue(event.request.data);
                    }
                    event.extra = scrubValue(event.extra);
                    delete event.user;
                    return event;
                },
                beforeBreadcrumb(crumb) {
                    if (crumb.message) crumb.message = scrubText(crumb.message);
                    if (crumb.data) crumb.data = scrubValue(crumb.data);
                    return crumb;
                },
            });
        })
        .catch(() => {});
}

function pathOf(url: URL | string): string {
    return (typeof url === 'string' ? new URL(url, window.location.origin) : url).pathname;
}

function pick(data: Record<string, unknown>, keys: string[]): Record<string, unknown> {
    return Object.fromEntries(keys.filter((k) => data[k] !== undefined && typeof data[k] !== 'object').map((k) => [k, data[k]]));
}

function initAnalytics(): void {
    let pending: Pending | null = null;
    let lastUrl = '';

    router.on('before', (event) => {
        const visit = event.detail.visit;
        const data = visit.data instanceof FormData ? {} : ((visit.data ?? {}) as Record<string, unknown>);
        pending = { method: String(visit.method).toLowerCase(), path: pathOf(visit.url), data };

        // the checkout answers with a redirect to the gateway (no "success" event), so count the attempt
        if (pending.method === 'post' && pending.path === '/checkout') {
            capture('checkout_started', pick(data, ['plan', 'currency', 'gateway', 'from']));
        }
    });

    router.on('success', () => {
        const done = pending;
        pending = null;
        if (!done || done.method !== 'post') return;

        if (done.path === '/create') {
            capture('draft_created', pick(done.data, ['occasion', 'template_id']));
        } else if (/^\/create\/[0-9a-f-]{36}\/publish$/i.test(done.path)) {
            capture('invitation_published');
        }
    });

    router.on('navigate', (event) => {
        const page = event.detail.page;
        const props = page.props as { auth?: { user?: { id?: string } | null } };
        identify(props.auth?.user?.id);

        if (page.url === lastUrl) return;
        lastUrl = page.url;

        capture('$pageview', { component: page.component });
        const step = /^create\/(\w+)$/.exec(page.component)?.[1];
        if (step) capture('wizard_step', { step: step.toLowerCase() });
    });
}

export function initMonitoring(): void {
    initSentry();
    initAnalytics();
}
