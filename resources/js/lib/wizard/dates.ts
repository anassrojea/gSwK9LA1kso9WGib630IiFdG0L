/**
 * The date section stores an ISO 8601 time with its UTC offset
 * ("2026-12-17T20:30:00+03:00"). The editor works on the local parts so the
 * host types the time printed on the invitation, whatever their own timezone.
 */
export interface DateParts {
    date: string; // YYYY-MM-DD
    time: string; // HH:MM
    offset: string; // +03:00
}

const ISO = /^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})(?::\d{2}(?:\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/;

export const OFFSETS = ['+00:00', '+01:00', '+02:00', '+03:00', '+03:30', '+04:00', '+05:00', '+05:30', '-05:00', '-04:00', '-06:00', '-08:00', '+08:00', '+10:00'];

export function splitIso(iso: string | undefined, fallbackOffset = '+03:00'): DateParts {
    const match = iso ? ISO.exec(iso) : null;
    if (!match) return { date: '', time: '20:00', offset: fallbackOffset };
    return { date: match[1], time: match[2], offset: match[3] === 'Z' ? '+00:00' : match[3] };
}

export function joinIso({ date, time, offset }: DateParts): string {
    return `${date}T${time || '00:00'}:00${offset}`;
}

/** The host's own UTC offset, e.g. "+03:00". */
export function localOffset(): string {
    const minutes = -new Date().getTimezoneOffset();
    const sign = minutes >= 0 ? '+' : '-';
    const abs = Math.abs(minutes);
    return `${sign}${String(Math.floor(abs / 60)).padStart(2, '0')}:${String(abs % 60).padStart(2, '0')}`;
}

/**
 * The Hijri (Umm al-Qura) date for a calendar day, as printed on the invitation.
 * Noon UTC keeps the day from shifting across timezones.
 */
export function hijriDate(date: string, locale = 'ar-SA'): string {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return '';
    try {
        return new Intl.DateTimeFormat(`${locale}-u-ca-islamic-umalqura`, { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(
            new Date(`${date}T12:00:00Z`),
        );
    } catch {
        return '';
    }
}

/** Two months from today at 20:00 in the host's offset: the default for a new date. */
export function defaultStart(): string {
    const d = new Date();
    d.setMonth(d.getMonth() + 2);
    const date = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    return joinIso({ date, time: '20:00', offset: localOffset() });
}
