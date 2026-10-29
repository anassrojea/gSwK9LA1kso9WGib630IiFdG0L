/**
 * Guest rows of the host dashboard and the pure helpers around them:
 * filtering, live updates and "last opened 3 hours ago". Twin of
 * App\Services\Dashboard\GuestBoard (status rules, stats keys). No imports,
 * so the Node test runner can load it directly (tests/js/dashboard.test.ts).
 */
export type GuestStatus = 'not_opened' | 'opened' | 'attending' | 'declined';

export const STATUSES: GuestStatus[] = ['not_opened', 'opened', 'attending', 'declined'];

export interface GuestRow {
    id: string;
    name: string;
    phone: string | null;
    seats: number;
    tags: string[];
    status: GuestStatus;
    people: number | null;
    kids: number | null;
    note: string | null;
    meal: string | null;
    allergies: string | null;
    responded_at: string | null;
    table: string | null;
    open_count: number;
    first_opened_at: string | null;
    last_opened_at: string | null;
    last_reminded_at: string | null;
    checked_in_at: string | null;
    people_in: number | null;
    link: string;
    whatsapp_url: string;
}

export interface Stats {
    invited: number;
    opened: number;
    replied: number;
    attending: number;
    attending_families: number;
    kids: number;
    declined: number;
    opened_not_replied: number;
    never_opened: number;
    seats_allowed: number;
    seats_left: number;
    guest_limit: number | null;
    limit_left: number | null;
    days_left: number | null;
    is_event_day: boolean;
    arrived_families: number;
    arrived_people: number;
}

export interface GuestFilters {
    status: GuestStatus | 'all';
    tag: string | 'all';
    query: string;
}

const WESTERN = (text: string) =>
    text.replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660)).replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06f0));

/**
 * Search folds Arabic letter variants (أ/إ/آ → ا, ة → ه, ى → ي), drops
 * diacritics and tatweel, and reads any digits, so "ام فيصل" finds "أم فيصل".
 */
export function fold(text: string): string {
    return WESTERN(text)
        .toLowerCase()
        .replace(/[ً-ٰٟـ]/g, '')
        .replace(/[أإآ]/g, 'ا')
        .replace(/ة/g, 'ه')
        .replace(/ى/g, 'ي')
        .replace(/\s+/g, ' ')
        .trim();
}

export function matchesGuest(guest: GuestRow, filters: GuestFilters): boolean {
    if (filters.status !== 'all' && guest.status !== filters.status) return false;
    if (filters.tag !== 'all' && !guest.tags.includes(filters.tag)) return false;

    const query = fold(filters.query);
    if (query === '') return true;

    const phoneQuery = query.replace(/\D/g, '');
    return fold(guest.name).includes(query) || (phoneQuery.length >= 3 && (guest.phone ?? '').replace(/\D/g, '').includes(phoneQuery));
}

export function filterGuests(guests: GuestRow[], filters: GuestFilters): GuestRow[] {
    return guests.filter((guest) => matchesGuest(guest, filters));
}

export function countByStatus(guests: GuestRow[]): Record<GuestStatus, number> {
    const counts: Record<GuestStatus, number> = { not_opened: 0, opened: 0, attending: 0, declined: 0 };
    for (const guest of guests) counts[guest.status]++;
    return counts;
}

/** Order of the status column when sorting: who needs attention first. */
export const STATUS_ORDER: Record<GuestStatus, number> = { not_opened: 0, opened: 1, declined: 2, attending: 3 };

/**
 * Put a row that came over the socket into the list: replace it in place,
 * or add it at the end when it is new.
 */
export function upsertGuest(guests: GuestRow[], row: GuestRow): GuestRow[] {
    const index = guests.findIndex((g) => g.id === row.id);
    if (index === -1) return [...guests, row];

    const next = guests.slice();
    next[index] = row;
    return next;
}

const UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 365 * 24 * 3600],
    ['month', 30 * 24 * 3600],
    ['week', 7 * 24 * 3600],
    ['day', 24 * 3600],
    ['hour', 3600],
    ['minute', 60],
    ['second', 1],
];

/**
 * "3 hours ago" / "منذ ٣ ساعات", with Arabic-Indic digits in Arabic.
 */
export function relativeTime(iso: string, locale: string, now: number = Date.now()): string {
    const seconds = Math.round((new Date(iso).getTime() - now) / 1000);
    const intlLocale = locale.startsWith('ar') ? 'ar-u-nu-arab' : 'en';
    const format = new Intl.RelativeTimeFormat(intlLocale, { numeric: 'auto' });

    if (Math.abs(seconds) < 45) return format.format(0, 'second');

    for (const [unit, size] of UNITS) {
        if (Math.abs(seconds) >= size || unit === 'second') {
            return format.format(Math.round(seconds / size), unit);
        }
    }

    return format.format(0, 'second');
}

/** Share of a total as a whole percent (0 when there is nothing to divide). */
export function percent(part: number, total: number): number {
    return total > 0 ? Math.round((part / total) * 100) : 0;
}

/**
 * Tags typed as "family, friends" or "أقارب، أصدقاء": trimmed, unique, at most `max`.
 */
export function parseTags(text: string, max = 10): string[] {
    const tags = text
        .split(/[|,،;؛]+/)
        .map((t) => t.trim().replace(/\s+/g, ' '))
        .filter((t) => t !== '');
    return [...new Set(tags)].slice(0, max);
}

/** "8:15 PM" / "٨:١٥ م" in the event's timezone. */
export function clockTime(iso: string, locale: string, timeZone?: string): string {
    const intlLocale = locale.startsWith('ar') ? 'ar-u-nu-arab' : 'en';
    try {
        return new Intl.DateTimeFormat(intlLocale, { hour: 'numeric', minute: '2-digit', timeZone }).format(new Date(iso));
    } catch {
        return new Intl.DateTimeFormat(intlLocale, { hour: 'numeric', minute: '2-digit' }).format(new Date(iso));
    }
}
