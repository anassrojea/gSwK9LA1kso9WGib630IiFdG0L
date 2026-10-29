import type { InvitationConfig } from '@/lib/config-schema';

export type Step = 'occasion' | 'design' | 'details' | 'guests' | 'review';
export type Tier = 'free' | 'premium' | 'signature';

export interface WizardEvent {
    id: string;
    title: string;
    occasion: InvitationConfig['occasion'];
    status: 'draft' | 'active' | 'ended' | 'archived';
    published: boolean;
}

/** Props every step page receives from WizardController::show. */
export interface WizardProps {
    [key: string]: unknown;
    event: WizardEvent;
    config: InvitationConfig;
    templateId: string | null;
    steps: Step[];
    step: Step;
    urls: { config: string; media: string; preview: string };
    tiers: { opening: Tier; theme: Tier };
}

export interface MediaItem {
    url: string;
    kind: 'photo' | 'video' | 'audio';
}

export type MediaMap = Record<string, MediaItem>;

export interface GuestRow {
    id: string;
    name: string;
    seats: number;
    link?: string;
    message?: string;
}

/** GET/PUT /events/{event}/open-link (App\Services\OpenLinks::payload). */
export interface OpenLinkSettings {
    available: boolean;
    enabled: boolean;
    cap: number | null;
    max_cap: number;
    max_per_reply: number;
    url: string | null;
    people: number;
    seats_left: number | null;
    replies: number;
    opens: number;
    first_opened_at: string | null;
    last_opened_at: string | null;
    published: boolean;
    endpoint: string;
}

export interface PlanSummary {
    id: string;
    name: string;
    price_cents: number;
    currency: string;
    guests: number | null;
}
