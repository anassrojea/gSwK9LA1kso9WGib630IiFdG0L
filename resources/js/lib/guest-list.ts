/**
 * Live preview of a pasted guest list, one family per line ("أم فيصل - 3",
 * "Khaled Ali, 2"). Twin of App\Services\Wizard\GuestListParser, which is
 * the one that actually creates households. No imports, so the Node test
 * runner can load it directly (tests/js/guest-list.test.ts).
 */
export const MAX_SEATS = 100;
export const MAX_NAME = 120;

export interface ParsedGuest {
    name: string;
    seats: number;
}

const toWestern = (text: string) =>
    text.replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660)).replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06f0));

const SEATS =
    /^(?<name>.*?)[\s‏‎]*(?:[-–—,،:;|/=]|\t)?\s*[([]?\s*(?:(?<=[\s([])[x×]\s*)?(?<!\d)(?<n>\d{1,3})\s*[)\]]?\s*(?:seats?|people|persons?|pax|guests?|مقاعد|مقعد|أشخاص|اشخاص|شخص|أفراد|افراد|فرد|نفر|ضيوف)?\s*$/iu;

const clean = (name: string) =>
    name
        .replace(/\s+/gu, ' ')
        .replace(/^[\s\-–—,،:;|/=()[\]‎‏]+|[\s\-–—,،:;|/=([‎‏]+$/gu, '');

export function parseGuestLine(raw: string): ParsedGuest | null {
    let line = toWestern(raw).trim();
    line = line.replace(/^(?:\d{1,3}[.)]\s+|[-•*·▪]\s*)/u, '');

    let seats = 1;
    const match = SEATS.exec(line);
    if (match?.groups && clean(match.groups.name) !== '') {
        line = match.groups.name;
        seats = Math.max(1, Math.min(MAX_SEATS, Number(match.groups.n)));
    }

    const name = clean(line);

    return name === '' ? null : { name: [...name].slice(0, MAX_NAME).join(''), seats };
}

export function parseGuestList(text: string): ParsedGuest[] {
    return text
        .split(/\r\n|\r|\n/)
        .map(parseGuestLine)
        .filter((g): g is ParsedGuest => g !== null);
}
