import type { UpgradeHint } from '@/types/account';
import type { OpenLinkSettings } from '@/types/wizard';
import type { GuestRow, Stats } from '@/lib/dashboard/guests';

export interface DashboardEvent {
    id: string;
    title: string;
    occasion: string;
    status: 'draft' | 'active' | 'ended' | 'archived';
    starts_at: string | null;
    timezone: string;
    venue_name: string | null;
    invite_locale: 'ar' | 'en';
}

export interface DashboardProps {
    event: DashboardEvent;
    stats: Stats;
    guests: GuestRow[];
    tags: string[];
    messages: { invite: string; reminder: string; defaults: { invite: string; reminder: string } };
    can: { update: boolean; check_in: boolean; send: boolean; remind: boolean };
    plan: string;
    pendingOrder: { code: string; status: string } | null;
    urls: {
        edit: string;
        export: string;
        paste: string;
        csv_preview: string;
        csv_import: string;
        reminders: string;
        messages: string;
        upgrade: string;
        orders: string;
    };
    limits: { seats: number; name: number; tags: number; message: number };
    openLink: OpenLinkSettings | null;
    /** Free or under-covered events: what upgrading unlocks (phase 5A) */
    upgradeHint?: UpgradeHint | null;
    /** phase 8D: a save-the-date's way to its full invitation */
    saveTheDate?: { upgrade_url: string; occasions: string[]; invitation_url: string | null } | null;
    /** phase 8D: thank-you card, highlight video, photo book */
    keepsakesUrl?: string;
}

export interface CsvPreviewRow {
    line: number;
    name: string;
    seats: number;
    phone: string | null;
    tags: string[];
    errors: string[];
    warnings: string[];
}

export interface CsvPreview {
    rows: CsvPreviewRow[];
    header: boolean;
    valid: number;
    invalid: number;
    seats: number;
    limit: number | null;
    left: number | null;
    fits: boolean;
}

export interface ReminderGuest {
    reminder_id: string;
    household_id: string;
    name: string;
    phone: string | null;
    opened: boolean;
    last_opened_at: string | null;
    last_reminded_at: string | null;
    whatsapp_url: string;
}
