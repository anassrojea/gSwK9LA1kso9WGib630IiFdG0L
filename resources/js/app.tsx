import '../css/app.css';

import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

import { initMonitoring } from '@/lib/monitoring';

const appName = import.meta.env.VITE_APP_NAME || 'Daawa';

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    // one chunk per page (plan section 21: code-splitting per Inertia page)
    resolve: (name) => {
        const pages = import.meta.glob<{ default: ResolvedComponent }>('./Pages/**/*.tsx');
        const page = pages[`./Pages/${name}.tsx`];
        if (!page) throw new Error(`Inertia page not found: ${name}`);
        return page().then((module) => module.default);
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#A8325A' },
});

// Sentry (only with VITE_SENTRY_DSN) and PostHog (only with VITE_POSTHOG_KEY and consent)
initMonitoring();
