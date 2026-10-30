/**
 * Door app logic (plan sections 9 and 14.8), pure for node:test. Warnings
 * mirror App\Services\Door\DoorDesk::warnings so the door can decide offline
 * from its cached guest list; the server checks again on every check-in.
 */

export interface DoorCheckin {
    at: string;
    time: string;
    people_in: number;
    by: string | null;
}

export interface DoorGuest {
    id: string;
    token: string;
    name: string;
    seats: number;
    status: 'attending' | 'declined' | 'no_reply';
    people: number | null;
    kids: number | null;
    section: 'men' | 'women' | null;
    table: string | null;
    in: number;
    checkins: DoorCheckin[];
    first_in_at: string | null;
}

export type DoorWarning =
    | { type: 'already_in'; people_in: number; at: string | null; time: string | null; by: string | null }
    | { type: 'over_limit'; allowed: number; total: number }
    | { type: 'not_attending'; status: 'declined' | 'no_reply' }
    | { type: 'partial'; expected: number; total: number }
    | { type: 'extra'; expected: number; total: number };

export interface Bucket {
    expected: number;
    arrived: number;
    families: number;
    capacity: number;
}

export interface DoorStats {
    sections: { men: Bucket; women: Bucket; none: Bucket };
    total: Bucket;
}

/** A scan waiting for the network (kept in localStorage). */
export interface QueuedScan {
    scan_id: string;
    token: string;
    people_in: number;
    scanned_at: string;
    confirm: boolean;
    /** people of the family this device knew were inside when it scanned */
    known_in: number;
}

export interface SyncResult {
    scan_id: string;
    token: string;
    status: 'synced' | 'conflict' | 'invalid';
    reason?: 'checked_in_elsewhere' | 'needs_confirmation';
    guest?: DoorGuest;
    warnings?: DoorWarning[];
}

/** The household token in a scanned code: a personal link …/i/{token} or the bare token. */
export function parseToken(scanned: string): string | null {
    const text = scanned.trim();
    const link = /\/i\/([A-Za-z0-9]{10,32})(?:[/?#]|$)/.exec(text);
    if (link) return link[1];
    return /^[A-Za-z0-9]{10,32}$/.test(text) ? text : null;
}

export function warnings(guest: DoorGuest, peopleIn: number): DoorWarning[] {
    const out: DoorWarning[] = [];
    const total = guest.in + peopleIn;
    if (guest.in > 0) {
        const first = guest.checkins[0];
        out.push({ type: 'already_in', people_in: guest.in, at: first?.at ?? null, time: first?.time ?? null, by: first?.by ?? null });
    }
    if (total > guest.seats) out.push({ type: 'over_limit', allowed: guest.seats, total });
    if (guest.status !== 'attending') out.push({ type: 'not_attending', status: guest.status });
    else if (total < (guest.people ?? 0)) out.push({ type: 'partial', expected: guest.people ?? 0, total });
    else if (total > (guest.people ?? 0) && total <= guest.seats) out.push({ type: 'extra', expected: guest.people ?? 0, total });
    return out;
}

/** How many people a scan lets in unless the staff change it. */
export function suggestedPeople(guest: DoorGuest): number {
    const expected = guest.status === 'attending' ? (guest.people ?? guest.seats) : guest.seats;
    return Math.max(1, expected - guest.in);
}

/** Record an entry locally (offline, or before the server's answer). */
export function addLocalCheckin(guests: DoorGuest[], token: string, peopleIn: number, at: Date, by: string | null): DoorGuest[] {
    const time = `${String(at.getHours()).padStart(2, '0')}:${String(at.getMinutes()).padStart(2, '0')}`;
    return guests.map((g) =>
        g.token === token
            ? { ...g, in: g.in + peopleIn, first_in_at: g.first_in_at ?? at.toISOString(), checkins: [...g.checkins, { at: at.toISOString(), time, people_in: peopleIn, by }] }
            : g,
    );
}

export function replaceGuest(guests: DoorGuest[], guest: DoorGuest): DoorGuest[] {
    return guests.some((g) => g.token === guest.token) ? guests.map((g) => (g.token === guest.token ? guest : g)) : [...guests, guest];
}

/**
 * After a sync: synced and invalid scans leave the queue, conflicts move to
 * the review list (with the server's fresh view of the family).
 */
export function applySync(queue: QueuedScan[], results: SyncResult[]): { queue: QueuedScan[]; conflicts: SyncResult[]; synced: number } {
    const byId = new Map(results.map((r) => [r.scan_id, r]));
    const conflicts = results.filter((r) => r.status === 'conflict');
    return {
        queue: queue.filter((s) => !byId.has(s.scan_id)),
        conflicts,
        synced: results.filter((r) => r.status === 'synced').length,
    };
}

/** Name search: ignores case, Arabic diacritics and alef / ya / ta marbuta variants. */
export function fold(text: string): string {
    return text
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[ً-ٰٟـ̀-ͯ]/g, '')
        .replace(/[أإآٱ]/g, 'ا')
        .replace(/ى/g, 'ي')
        .replace(/ة/g, 'ه')
        .trim();
}

export function searchGuests(guests: DoorGuest[], query: string, limit = 20): DoorGuest[] {
    const q = fold(query);
    if (!q) return [];
    return guests.filter((g) => fold(g.name).includes(q)).slice(0, limit);
}

export function uuid(): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) return crypto.randomUUID();
    const b = Array.from({ length: 16 }, () => Math.floor(Math.random() * 256));
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    const h = b.map((x) => x.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

/** localStorage, never throwing (private mode, quota). */
export const store = {
    get<T>(key: string, fallback: T): T {
        try {
            const raw = localStorage.getItem(key);
            return raw ? (JSON.parse(raw) as T) : fallback;
        } catch {
            return fallback;
        }
    },
    set(key: string, value: unknown) {
        try {
            localStorage.setItem(key, JSON.stringify(value));
        } catch {
            // full or disabled: the queue still lives in memory until the page closes
        }
    },
};
