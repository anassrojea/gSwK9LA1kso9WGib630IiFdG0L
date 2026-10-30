/** Venue & Planner Pro (phase 8A): the props of the /org pages (App\Services\Org\OrgPresenter). */

export type OrgRole = 'owner' | 'staff' | 'door';
export type SubscriptionStatus = 'trialing' | 'active' | 'past_due' | 'canceled' | 'expired';
export type Provider = 'stripe' | 'lemon_squeezy' | 'transfer';
export type Interval = 'month' | 'year';

export interface OrgLimits {
    signature_events_per_month: number;
    team_seats: number;
    white_label: boolean;
    directory_listing: boolean;
    hall_layouts: number | null;
}

export interface OrgSubscription {
    id: string;
    status: SubscriptionStatus;
    plan_id: string;
    tier: string;
    interval: Interval;
    provider: Provider;
    currency: string;
    amount: number;
    current_period_end: string | null;
    cancel_at_period_end: boolean;
    next_plan_id: string | null;
    past_due_since: string | null;
    grace_until: string | null;
}

export interface OrgQuota {
    limit: number;
    used: number;
    remaining: number;
    resets_at: string | null;
    active: boolean;
}

export interface Org {
    id: string;
    name: string;
    type: 'venue' | 'planner' | 'company';
    city: string | null;
    contact_phone: string | null;
    contact_email: string | null;
    logo_url: string | null;
    role: OrgRole | null;
    can: { manage: boolean; create_events: boolean; billing: boolean };
    white_label: { enabled: boolean; brand_color: string | null; sender_name: string | null; raw_brand_color: string | null };
    plan: string | null;
    tier: string | null;
    limits: OrgLimits;
    subscription: OrgSubscription | null;
    quota: OrgQuota;
    seats: { used: number; limit: number };
    urls: { dashboard: string; team: string; billing: string; settings: string; halls: string };
}

export interface Member {
    user_id: string;
    name: string | null;
    email: string | null;
    role: OrgRole;
    since: string | null;
}

export interface CalendarEvent {
    id: string;
    title: string;
    occasion: string;
    status: string;
    starts_at: string | null;
    timezone: string | null;
    hall: string | null;
    published: boolean;
    client: string | null;
    stats: { invited: number; replied: number; attending: number; arrived: number };
    url: string;
}

export interface HallSummary {
    id: string;
    name: string;
    tables?: number;
    seats?: number;
    events: number;
    url: string;
}

export interface TierPrice {
    plan_id: string;
    amount: number;
    formatted: string;
    placeholder: boolean;
}

export interface Tier {
    tier: string;
    month: TierPrice | null;
    year: TierPrice | null;
    limits: OrgLimits;
}
