import type { OrderSummary, OrderView } from '@/types/payments';
import type { BillingJourney } from '@/types/journey';

export type AccountTab = 'profile' | 'security' | 'billing' | 'privacy';

export type SignInMethod = 'password' | 'google' | 'email_code';

export interface AccountSummary {
    name: string;
    email: string | null;
    has_password: boolean;
    deletion: { requested_at: string; scheduled_for: string | null; date: string } | null;
}

export interface ProfileData {
    name: string;
    email: string | null;
    phone: string | null;
    locale: 'ar' | 'en';
    country: string | null;
    currency: string | null;
    email_verified: boolean;
}

export interface DeviceSession {
    key: string;
    ip: string | null;
    agent: string;
    last_seen: string;
    current: boolean;
}

export interface SecurityData {
    google: { enabled: boolean; linked: boolean };
    methods: SignInMethod[];
    can_unlink_google: boolean;
    login_codes: boolean;
    sessions: DeviceSession[];
}

export interface BillingEvent {
    id: string;
    title: string;
    status: 'draft' | 'active' | 'ended' | 'archived';
    starts_at: string | null;
    plan: string;
    next: string | null;
    can_upgrade: boolean;
    unlocks: string[];
    features: string[];
    guests: number | null;
    next_guests: number | null;
    upgrade_url: string | null;
    url: string;
}

export interface BillingData {
    events: BillingEvent[];
    orders: OrderSummary[];
    pending: OrderView[];
    orders_url: string;
    /** phase 8D: the Invite friends panel (null when referrals are off) */
    journey?: BillingJourney | null;
}

export interface PrivacyData {
    export_url: string;
    confirm_with: 'password' | 'code';
    blockers: string[];
    grace_days: number;
}

export interface AccountPageProps {
    tab: AccountTab;
    account: AccountSummary;
    urls: Record<AccountTab, string>;
    profile?: ProfileData;
    options?: { locales: string[]; countries: string[]; currencies: string[] };
    security?: SecurityData;
    billing?: BillingData;
    privacy?: PrivacyData;
}

/** the "what upgrading unlocks" banner on the event dashboard */
export interface UpgradeHint {
    plan: string;
    next: string | null;
    unlocks: string[];
    missing: string[];
    next_guests: number | null;
}
