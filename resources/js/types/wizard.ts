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

export interface PlanSummary {
    id: string;
    name: string;
    price_cents: number;
    currency: string;
    guests: number | null;
}
