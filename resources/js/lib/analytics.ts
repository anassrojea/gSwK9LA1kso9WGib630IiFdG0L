/*
 * Product analytics (plan section 22): a tiny first-party client for PostHog's
 * capture API, used by the public site (resources/js/marketing.js) and the
 * Inertia pages (resources/js/lib/monitoring.ts). Never loaded on guest
 * invitations. Sends nothing unless VITE_POSTHOG_KEY is set at build time AND
 * the visitor accepted the cookie banner; Do Not Track / Global Privacy
 * Control are respected. URLs are stripped of everything but UTM parameters.
 */

const KEY: string | undefined = import.meta.env.VITE_POSTHOG_KEY || undefined;
const HOST: string = (import.meta.env.VITE_POSTHOG_HOST || 'https://eu.i.posthog.com').replace(/\/+$/, '');

export const CONSENT_KEY = 'daawa:consent';
const ANON_KEY = 'daawa:aid';
const USER_KEY = 'daawa:uid';

export type Consent = 'granted' | 'denied';

function storage(): Storage | null {
    try {
        return window.localStorage;
    } catch {
        return null;
    }
}

export function analyticsConfigured(): boolean {
    return !!KEY;
}

export function getConsent(): Consent | null {
    const value = storage()?.getItem(CONSENT_KEY);
    return value === 'granted' || value === 'denied' ? value : null;
}

export function setConsent(value: Consent | null): void {
    const store = storage();
    if (!store) return;
    if (value === null) store.removeItem(CONSENT_KEY);
    else store.setItem(CONSENT_KEY, value);
    if (value !== 'granted') {
        store.removeItem(ANON_KEY);
        store.removeItem(USER_KEY);
    }
}

function optedOut(): boolean {
    const nav = navigator as Navigator & { globalPrivacyControl?: boolean };
    return nav.doNotTrack === '1' || nav.globalPrivacyControl === true;
}

export function trackingAllowed(): boolean {
    return analyticsConfigured() && getConsent() === 'granted' && !optedOut();
}

function randomId(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
}

function anonymousId(): string {
    const store = storage();
    let id = store?.getItem(ANON_KEY);
    if (!id) {
        id = randomId();
        store?.setItem(ANON_KEY, id);
    }
    return id;
}

function distinctId(): string {
    return storage()?.getItem(USER_KEY) || anonymousId();
}

/** Path and UTM parameters only: no emails, reset tokens or signatures leave the browser. */
export function cleanUrl(href: string): string {
    const url = new URL(href, window.location.origin);
    const keep = new URLSearchParams();
    url.searchParams.forEach((value, name) => {
        if (/^utm_[a-z]+$/.test(name) || name === 'currency') keep.set(name, value);
    });
    const path = url.pathname.replace(/^\/reset-password\/.*/, '/reset-password');
    const query = keep.toString();
    return `${url.origin}${path}${query ? `?${query}` : ''}`;
}

function send(event: string, distinct: string, properties: Record<string, unknown>): void {
    if (!KEY) return;
    const body = JSON.stringify({
        api_key: KEY,
        event,
        distinct_id: distinct,
        timestamp: new Date().toISOString(),
        properties: {
            $current_url: cleanUrl(window.location.href),
            $pathname: cleanUrl(window.location.href).replace(/^https?:\/\/[^/]+/, '').split('?')[0],
            $host: window.location.host,
            $referrer: document.referrer ? cleanUrl(document.referrer) : '$direct',
            $screen_width: window.screen?.width,
            $lib: 'daawa-web',
            locale: document.documentElement.lang,
            ...properties,
        },
    });
    fetch(`${HOST}/i/v0/e/`, { method: 'POST', body, keepalive: true, headers: { 'Content-Type': 'application/json' }, credentials: 'omit' }).catch(() => {});
}

export function capture(event: string, properties: Record<string, unknown> = {}): void {
    if (!trackingAllowed()) return;
    send(event, distinctId(), properties);
}

/** Links the anonymous visitor to the signed-in user (by id only), once per user. */
export function identify(userId: string | null | undefined): void {
    if (!trackingAllowed() || !userId) return;
    const store = storage();
    if (store?.getItem(USER_KEY) === userId) return;
    send('$identify', userId, { $anon_distinct_id: anonymousId() });
    store?.setItem(USER_KEY, userId);
}
