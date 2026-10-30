/**
 * Applying AI suggestions (phase 7B) to the invitation, only ever after the
 * host clicks "accept". No imports, so the Node tests run them as is. Limits
 * mirror the frozen v1 schema (story chapters, intro_line).
 */
export const MAX_CHAPTERS = 12;
export const MAX_POEM = 2000;

export interface Chapter {
    year?: string;
    title: string;
    text: string;
}

export interface Family {
    name: string;
    seats: number;
    phone: string | null;
    notes: string | null;
    warnings: string[];
}

export type GenerationStatus = 'queued' | 'running' | 'done' | 'failed';

export interface Generation<R = unknown> {
    id: string;
    kind: 'story' | 'poem' | 'clean';
    status: GenerationStatus;
    result: R | null;
    error: string | null;
    message: string | null;
    poll: string;
}

/**
 * The story chapters after the host accepts a suggestion: replace theirs, or
 * add after them; never more than the schema allows.
 */
export function mergeChapters(current: Chapter[], suggested: Chapter[], mode: 'replace' | 'append'): Chapter[] {
    const clean = suggested.filter((c) => c.title.trim() && c.text.trim());
    const list = mode === 'replace' ? clean : [...current, ...clean];
    return list.slice(0, MAX_CHAPTERS);
}

/** An intro_line section holding the poem as verse (keeps a caption the host wrote unless the poem brings one). */
export function poemSection<S extends { type: 'intro_line'; text: string; style?: 'line' | 'verse'; caption?: string; hidden?: boolean }>(
    section: S,
    poem: { text: string; caption?: string },
): S {
    const caption = poem.caption ?? section.caption;
    return { ...section, text: poem.text.slice(0, MAX_POEM), style: 'verse', ...(caption ? { caption: caption.slice(0, 80) } : {}) };
}

/** Reviewed families → the import endpoint's rows ({guests: [...]}). */
export function importRows(families: Family[]): Array<{ name: string; seats: number; phone: string | null }> {
    return families
        .map((f) => ({ name: f.name.trim(), seats: Math.max(1, Math.min(100, Math.round(f.seats) || 1)), phone: f.phone?.trim() || null }))
        .filter((f) => f.name !== '');
}

/** Poll delay: quick at first, then slower (ms). */
export function pollDelay(attempt: number): number {
    return Math.min(4000, 800 + attempt * 400);
}
