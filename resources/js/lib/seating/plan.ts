/**
 * Seating planner logic (plan section 7.3), pure so it runs the same in the
 * browser and in node:test. Warnings follow app/Services/Seating/SeatingWarnings.php;
 * the server checks every save again (PUT /events/{event}/seats).
 */
import type { HallLayout, HallTable } from '../hall-generator';

export type Section = 'men' | 'women';

export interface Family {
    id: string;
    name: string;
    section: Section | null;
    people: number;
    kids: number;
    seats: number;
    meal: string | null;
    tags: string[];
}

export interface Assignment {
    household_id: string;
    table_id: string;
    seat_index: number;
    person_label?: string | null;
}

export type Warning =
    | { type: 'over_capacity'; table: string; count: number; seats: number }
    | { type: 'section_mismatch'; table: string; household: string; name: string; section: Section }
    | { type: 'split'; household: string; name: string; tables: string[] }
    | { type: 'unseated'; household: string; name: string; count: number }
    | { type: 'stale'; household: string; name: string | null; count: number };

/** Wraps a name in Unicode isolates, so an Arabic name inside an English sentence (or the reverse) keeps its place. */
export const iso = (text: string | null | undefined) => `\u2068${text ?? ''}\u2069`;

/** "T7" → "7"; ids without a number are shown as they are. */
export function tableNumber(id: string): string {
    const m = /(\d+)/.exec(id);
    return m ? String(Number(m[1])) : id;
}

export const seatKey = (table: string, seat: number) => `${table}#${seat}`;

/** Who sits where: "T3#2" → assignment. */
export function seatMap(assignments: Assignment[]): Map<string, Assignment> {
    return new Map(assignments.map((a) => [seatKey(a.table_id, a.seat_index), a]));
}

/** Seated people per family. */
export function seatedCounts(assignments: Assignment[]): Map<string, number> {
    const out = new Map<string, number>();
    for (const a of assignments) out.set(a.household_id, (out.get(a.household_id) ?? 0) + 1);
    return out;
}

/** The tables a family sits at, in hall order. */
export function tablesOf(layout: HallLayout | null, assignments: Assignment[], householdId: string): string[] {
    const ids = new Set(assignments.filter((a) => a.household_id === householdId).map((a) => a.table_id));
    const order = layout?.tables.map((t) => t.id) ?? [];
    return [...ids].sort((a, b) => order.indexOf(a) - order.indexOf(b));
}

export function freeSeats(table: HallTable, assignments: Assignment[]): number[] {
    const taken = new Set(assignments.filter((a) => a.table_id === table.id).map((a) => a.seat_index));
    return Array.from({ length: table.seats }, (_, i) => i).filter((i) => !taken.has(i));
}

/**
 * Drop a family on a table: their people fill its free chairs in order. A
 * family that already sits elsewhere moves over (whole). Whoever does not
 * fit stays without a seat; `missing` says how many.
 */
export function seatFamily(layout: HallLayout, families: Family[], assignments: Assignment[], householdId: string, tableId: string): { assignments: Assignment[]; placed: number; missing: number } {
    const family = families.find((f) => f.id === householdId);
    const table = layout.tables.find((t) => t.id === tableId);
    if (!family || !table) return { assignments, placed: 0, missing: 0 };

    const alreadyHere = assignments.filter((a) => a.household_id === householdId && a.table_id === tableId).length;
    const rest = assignments.filter((a) => a.household_id !== householdId || a.table_id === tableId);
    const free = freeSeats(table, rest);
    const wanted = Math.max(0, family.people - alreadyHere);
    const take = free.slice(0, wanted);

    const added = take.map((seat_index) => ({ household_id: householdId, table_id: tableId, seat_index }));
    return { assignments: [...rest, ...added], placed: alreadyHere + added.length, missing: wanted - added.length };
}

/** Seat one more person of a family on a given chair (no-op when taken or the family is complete). */
export function seatOne(families: Family[], assignments: Assignment[], householdId: string, tableId: string, seat: number): Assignment[] {
    const family = families.find((f) => f.id === householdId);
    if (!family) return assignments;
    if (seatMap(assignments).has(seatKey(tableId, seat))) return assignments;
    if ((seatedCounts(assignments).get(householdId) ?? 0) >= family.people) return assignments;
    return [...assignments, { household_id: householdId, table_id: tableId, seat_index: seat }];
}

/** Move the person on one chair to another; swap when the other chair is taken. */
export function moveSeat(assignments: Assignment[], from: { table: string; seat: number }, to: { table: string; seat: number }): Assignment[] {
    if (from.table === to.table && from.seat === to.seat) return assignments;
    const map = seatMap(assignments);
    const moving = map.get(seatKey(from.table, from.seat));
    if (!moving) return assignments;
    const other = map.get(seatKey(to.table, to.seat));

    return assignments.map((a) => {
        if (a === moving) return { household_id: a.household_id, table_id: to.table, seat_index: to.seat };
        if (other && a === other) return { household_id: a.household_id, table_id: from.table, seat_index: from.seat };
        return a;
    });
}

export function unassignSeat(assignments: Assignment[], table: string, seat: number): Assignment[] {
    return assignments.filter((a) => !(a.table_id === table && a.seat_index === seat));
}

export function unassignFamily(assignments: Assignment[], householdId: string): Assignment[] {
    return assignments.filter((a) => a.household_id !== householdId);
}

/** The seats the server would refuse: unknown tables or chairs, doubles, families no longer coming, too many per family. */
export function invalidSeats(layout: HallLayout | null, families: Family[], assignments: Assignment[]): Assignment[] {
    const tables = new Map(layout?.tables.map((t) => [t.id, t]) ?? []);
    const people = new Map(families.map((f) => [f.id, f.people]));
    const seen = new Set<string>();
    const counts = new Map<string, number>();
    const bad: Assignment[] = [];

    for (const a of assignments) {
        const table = tables.get(a.table_id);
        const key = seatKey(a.table_id, a.seat_index);
        const count = (counts.get(a.household_id) ?? 0) + 1;
        if (!table || a.seat_index < 0 || a.seat_index >= table.seats || seen.has(key) || !people.has(a.household_id) || count > (people.get(a.household_id) ?? 0)) {
            bad.push(a);
            continue;
        }
        seen.add(key);
        counts.set(a.household_id, count);
    }
    return bad;
}

export function computeWarnings(layout: HallLayout | null, families: Family[], assignments: Assignment[]): Warning[] {
    const tables = new Map(layout?.tables.map((t) => [t.id, t]) ?? []);
    const byId = new Map(families.map((f) => [f.id, f]));
    const perTable = new Map<string, Assignment[]>();
    const perFamily = new Map<string, Map<string, number>>();
    const outOfRange = new Set<string>();

    for (const a of assignments) {
        perTable.set(a.table_id, [...(perTable.get(a.table_id) ?? []), a]);
        const tablesOfFamily = perFamily.get(a.household_id) ?? new Map<string, number>();
        tablesOfFamily.set(a.table_id, (tablesOfFamily.get(a.table_id) ?? 0) + 1);
        perFamily.set(a.household_id, tablesOfFamily);
        const table = tables.get(a.table_id);
        if (!table || a.seat_index >= table.seats) outOfRange.add(a.table_id);
    }

    const warnings: Warning[] = [];

    for (const [tableId, rows] of perTable) {
        const seats = tables.get(tableId)?.seats ?? 0;
        if (rows.length > seats || outOfRange.has(tableId)) warnings.push({ type: 'over_capacity', table: tableId, count: rows.length, seats });
    }

    for (const [tableId, rows] of perTable) {
        const section = tables.get(tableId)?.section;
        if (section !== 'men' && section !== 'women') continue;
        const seen = new Set<string>();
        for (const a of rows) {
            const family = byId.get(a.household_id);
            if (family?.section && family.section !== section && !seen.has(family.id)) {
                seen.add(family.id);
                warnings.push({ type: 'section_mismatch', table: tableId, household: family.id, name: family.name, section });
            }
        }
    }

    for (const [householdId, byTable] of perFamily) {
        const family = byId.get(householdId);
        const count = [...byTable.values()].reduce((s, n) => s + n, 0);
        if (!family || count > family.people) {
            warnings.push({ type: 'stale', household: householdId, name: family?.name ?? null, count });
            continue;
        }
        if (byTable.size > 1) warnings.push({ type: 'split', household: householdId, name: family.name, tables: [...byTable.keys()] });
    }

    for (const family of families) {
        const seated = [...(perFamily.get(family.id)?.values() ?? [])].reduce((s, n) => s + n, 0);
        if (seated < family.people) warnings.push({ type: 'unseated', household: family.id, name: family.name, count: family.people - seated });
    }

    return warnings;
}

export type SeatFilter = 'all' | 'unseated' | 'seated';

export interface FamilyFilters {
    query: string;
    section: 'all' | Section | 'none';
    seated: SeatFilter;
}

/** Arabic-aware search: ignores case, diacritics, tatweel and the alef / ya / ta marbuta variants. */
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

export function filterFamilies(families: Family[], assignments: Assignment[], filters: FamilyFilters): Family[] {
    const counts = seatedCounts(assignments);
    const query = fold(filters.query);
    return families.filter((f) => {
        if (query && !fold(f.name).includes(query) && !f.tags.some((t) => fold(t).includes(query))) return false;
        if (filters.section === 'none' ? f.section !== null : filters.section !== 'all' && f.section !== filters.section) return false;
        const seated = counts.get(f.id) ?? 0;
        if (filters.seated === 'unseated' && seated >= f.people) return false;
        if (filters.seated === 'seated' && seated === 0) return false;
        return true;
    });
}

/** Totals for the planner header. */
export function summary(families: Family[], assignments: Assignment[], layout: HallLayout | null) {
    const people = families.reduce((s, f) => s + f.people, 0);
    const counts = seatedCounts(assignments);
    const seated = families.reduce((s, f) => s + Math.min(f.people, counts.get(f.id) ?? 0), 0);
    const chairs = layout?.tables.reduce((s, t) => s + t.seats, 0) ?? 0;
    return { people, seated, chairs, unseated: people - seated };
}

/** Move a table by (dx, dy) in hall units, kept inside the hall. */
export function nudgeTable(layout: HallLayout, tableId: string, dx: number, dy: number): HallLayout {
    const pad = (t: HallTable) => t.ring + t.chairR;
    return {
        ...layout,
        tables: layout.tables.map((t) =>
            t.id === tableId
                ? {
                      ...t,
                      x: Math.round(Math.min(layout.size.w - pad(t), Math.max(pad(t), t.x + dx)) * 10) / 10,
                      y: Math.round(Math.min(layout.size.h - pad(t), Math.max(pad(t), t.y + dy)) * 10) / 10,
                  }
                : t,
        ),
    };
}

/** "Family name 1/3" per chair, people numbered in hall order (same as SeatingPlan::label on the server). */
export function personLabels(layout: HallLayout | null, families: Family[], assignments: Assignment[]): Map<string, string> {
    const order = new Map(layout?.tables.map((t, i) => [t.id, i]) ?? []);
    const byId = new Map(families.map((f) => [f.id, f]));
    const sorted = [...assignments].sort((a, b) => (order.get(a.table_id) ?? 1e9) - (order.get(b.table_id) ?? 1e9) || a.seat_index - b.seat_index);
    const counter = new Map<string, number>();
    const out = new Map<string, string>();
    for (const a of sorted) {
        const i = (counter.get(a.household_id) ?? 0) + 1;
        counter.set(a.household_id, i);
        const f = byId.get(a.household_id);
        out.set(seatKey(a.table_id, a.seat_index), f ? `${f.name} ${i}/${f.people}` : '');
    }
    return out;
}
