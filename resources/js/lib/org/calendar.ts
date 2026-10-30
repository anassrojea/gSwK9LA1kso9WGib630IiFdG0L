/**
 * The venue calendar (plan section 4.2, phase 8A): a month grid of whole
 * weeks and the events of each day, placed on the day in the event's own
 * time zone (a 23:30 wedding in Riyadh is on that Riyadh date wherever the
 * planner opens the page). Pure functions, tested in tests/js/org-calendar.test.ts.
 */

export interface DatedEvent {
    starts_at: string | null;
    timezone: string | null;
}

/** "2026-11" → "2026-12" (or -1: "2026-10"). */
export function shiftMonth(month: string, by: number): string {
    const [y, m] = month.split('-').map(Number);
    const d = new Date(Date.UTC(y, m - 1 + by, 1));
    return `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}`;
}

/** The event's local date, "YYYY-MM-DD", or null without a date. */
export function localDay(event: DatedEvent): string | null {
    if (!event.starts_at) return null;
    const date = new Date(event.starts_at);
    try {
        // en-CA formats as YYYY-MM-DD
        return new Intl.DateTimeFormat('en-CA', { timeZone: event.timezone || 'UTC', year: 'numeric', month: '2-digit', day: '2-digit' }).format(date);
    } catch {
        return date.toISOString().slice(0, 10);
    }
}

/**
 * Whole weeks covering the month: a list of weeks, each 7 "YYYY-MM-DD" days.
 * weekStart: 0 = Sunday, 6 = Saturday (the Arabic week starts on Saturday).
 */
export function monthGrid(month: string, weekStart = 6): string[][] {
    const [y, m] = month.split('-').map(Number);
    const first = new Date(Date.UTC(y, m - 1, 1));
    const last = new Date(Date.UTC(y, m, 0));
    const lead = (first.getUTCDay() - weekStart + 7) % 7;
    const start = new Date(first);
    start.setUTCDate(first.getUTCDate() - lead);

    const count = Math.ceil((lead + last.getUTCDate()) / 7);

    return Array.from({ length: count }, (_, w) =>
        Array.from({ length: 7 }, (_, d) => {
            const day = new Date(start);
            day.setUTCDate(start.getUTCDate() + w * 7 + d);
            return day.toISOString().slice(0, 10);
        }),
    );
}

/** Events by local day, each day's in time order; events without a date under "". */
export function byDay<T extends DatedEvent>(events: T[]): Map<string, T[]> {
    const days = new Map<string, T[]>();
    const sorted = [...events].sort((a, b) => (a.starts_at ?? '9999').localeCompare(b.starts_at ?? '9999'));
    for (const event of sorted) {
        const key = localDay(event) ?? '';
        days.set(key, [...(days.get(key) ?? []), event]);
    }
    return days;
}

/** Is the day inside the month ("2026-11-03" in "2026-11")? */
export function inMonth(day: string, month: string): boolean {
    return day.startsWith(`${month}-`);
}
