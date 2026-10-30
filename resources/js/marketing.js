/*
 * Public site (resources/views/marketing): the cookie consent banner and, only
 * after "Accept", page views and CTA clicks to PostHog (resources/js/lib/analytics.ts).
 * No framework; a few hundred bytes when analytics is off.
 */
import { analyticsConfigured, capture, getConsent, setConsent } from './lib/analytics';

const banner = document.getElementById('consent');

function showBanner(show) {
    if (banner) banner.hidden = !show;
}

function pageview() {
    capture('$pageview', { page: document.body.dataset.page });
}

if (analyticsConfigured() && getConsent() === null) showBanner(true);
pageview();

banner?.addEventListener('click', (event) => {
    const choice = event.target.closest('[data-consent]')?.dataset.consent;
    if (choice !== 'granted' && choice !== 'denied') return;
    setConsent(choice);
    showBanner(false);
    if (choice === 'granted') pageview();
});

// Cookie notice: "Change my cookie choice"
document.addEventListener('click', (event) => {
    if (event.target.closest('[data-consent-reset]')) {
        setConsent(null);
        showBanner(true);
        banner?.querySelector('button')?.focus();
    }
});

// CTA clicks: data-track="cta_create" data-track-place="hero" …
document.addEventListener('click', (event) => {
    const el = event.target.closest('[data-track]');
    if (!el) return;
    const props = {};
    for (const [name, value] of Object.entries(el.dataset)) {
        if (name.startsWith('track') && name !== 'track') props[name.slice(5).toLowerCase()] = value;
    }
    capture(el.dataset.track, props);
});
