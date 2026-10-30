/** App\Http\Controllers\Journey\JourneyController::show (phase 8D) */
export interface ReferralData {
    enabled: boolean;
    link: string;
    code: string;
    footer: boolean;
    stats: { signups: number; rewarded: number; pending: number; capped: number };
    /** available account credit per currency (minor units) */
    credit: Record<string, number>;
    reward: Record<string, number>;
    welcome_percent: number;
    cap: number;
}

export interface BundleView {
    id: string;
    name: string;
    description: string | null;
    credits: { plan: string; count: number; name: string }[];
    validity_days: number;
    prices: Record<string, { price: number | null; separately: number | null }>;
}

export interface BundleCreditView {
    id: string;
    plan: string;
    plan_name: string;
    bundle: string | null;
    expires_at: string;
    used_at: string | null;
    used_event: string | null;
    available: boolean;
}

export interface JourneyPageProps {
    referral: ReferralData;
    loyalty_percent: number | null;
    bundles: BundleView[];
    credits: BundleCreditView[];
    checkout: {
        currency: string;
        currencies: { code: string; exponent: number }[];
        fallback_currency: string;
        gateways: { id: string; method: string; currencies: string[] }[];
    };
    urls: { footer: string; billing: string };
}

/** The "Invite friends" panel on the Billing tab (AccountController::billing → billing.journey). */
export interface BillingJourney {
    link: string;
    credit: Record<string, number>;
    url: string;
}
