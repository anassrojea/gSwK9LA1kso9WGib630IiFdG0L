/** App\Services\Payments\CheckoutService::options */
export interface CheckoutOptions {
    endpoint: string;
    /** POST {event_id, code} → {discount: DiscountRules} or 422 */
    discount_endpoint: string;
    event_id: string;
    currencies: { code: string; exponent: number }[];
    countries: { code: string; currency: string }[];
    country: string;
    currency: string;
    fallback_currency: string;
    current_plan: string;
    required_plan: string;
    plans: CheckoutPlan[];
    current_features: string[];
    addons: CheckoutAddon[];
    gateways: CheckoutGateway[];
}

export interface CheckoutPlan {
    id: string;
    name: string;
    guests: number | null;
    features: string[];
    prices: Record<string, number | null>;
}

export interface CheckoutAddon {
    id: string;
    name: string;
    description: string | null;
    feature: string;
    /** a plan feature that already includes this add-on */
    covered_by: string | null;
    days: number | null;
    stackable: boolean;
    max_quantity: number;
    owned: boolean;
    prices: Record<string, number | null>;
}

export interface CheckoutGateway {
    id: string;
    method: 'transfer' | 'card' | 'wallet_local';
    currencies: string[];
}

export type OrderStatus = 'awaiting_payment' | 'receipt_received' | 'paid' | 'approved' | 'rejected' | 'expired' | 'refunded';

/** App\Services\Payments\OrderPresenter::present */
export interface OrderSummary {
    code: string;
    status: OrderStatus;
    method: 'transfer' | 'card' | 'wallet_local';
    gateway: string;
    currency: string;
    requested_currency: string | null;
    total: number;
    total_formatted: string;
    /** the code used at checkout and what it took off (total is already net of it) */
    discount: { code: string; amount: number; amount_formatted: string; subtotal_formatted: string } | null;
    summary: string;
    created_at: string | null;
    expires_at: string | null;
    paid_at: string | null;
    event: { id: string; title: string } | null;
    url: string;
}

export interface TransferInstructions {
    account: { field: string; label: string; value: string }[];
    note: string | null;
    local_note: string | null;
    amount: string;
    code: string;
    whatsapp_url: string;
    expires_at: string | null;
}

export interface OrderView extends OrderSummary {
    items: { type: 'plan' | 'addon'; id: string; name: string; quantity: number; amount: number; amount_formatted: string }[];
    rejection_reason: string | null;
    receipt_sent_at: string | null;
    transfer: TransferInstructions | null;
    can_retry: boolean;
    urls: { status: string; receipt: string; retry: string; back: string };
}
