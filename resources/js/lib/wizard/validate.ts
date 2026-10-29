import type { z } from 'zod';

import {
    albumTeaserSection,
    closingSection,
    dateSection,
    dressCodeSection,
    favoritesSection,
    gallerySection,
    hostsSection,
    introLineSection,
    invitationConfigSchema,
    namesSection,
    placeSection,
    programSection,
    rsvpSection,
    sendRoseSection,
    storySection,
    wishesSection,
    type SectionType,
} from '@/lib/config-schema';

/** Errors keyed by JSON pointer ("/sections/3/first"), already translated. */
export type ConfigErrors = Record<string, string[]>;

type Translate = (key: string, replace?: Record<string, string | number>) => string;

const SECTION_SCHEMAS: Record<SectionType, z.ZodType> = {
    intro_line: introLineSection,
    hosts: hostsSection,
    names: namesSection,
    date: dateSection,
    story: storySection,
    program: programSection,
    place: placeSection,
    dress_code: dressCodeSection,
    gallery: gallerySection,
    favorites: favoritesSection,
    wishes: wishesSection,
    send_rose: sendRoseSection,
    rsvp: rsvpSection,
    album_teaser: albumTeaserSection,
    closing: closingSection,
};

function message(issue: z.core.$ZodIssue, t: Translate): string {
    switch (issue.code) {
        case 'too_small':
            if (issue.origin === 'string') return Number(issue.minimum) <= 1 ? t('wizard.errors.required') : t('wizard.errors.too_short', { min: Number(issue.minimum) });
            if (issue.origin === 'array') return t('wizard.errors.min_items', { min: Number(issue.minimum) });
            return t('wizard.errors.too_small', { min: Number(issue.minimum) });
        case 'too_big':
            if (issue.origin === 'string') return t('wizard.errors.too_long', { max: Number(issue.maximum) });
            if (issue.origin === 'array') return t('wizard.errors.max_items', { max: Number(issue.maximum) });
            return t('wizard.errors.too_big', { max: Number(issue.maximum) });
        case 'invalid_format':
            return issue.format === 'datetime' ? t('wizard.errors.date') : issue.format === 'regex' && String(issue.pattern).includes('#') ? t('wizard.errors.color') : t('wizard.errors.format');
        case 'invalid_type':
            return issue.input === undefined ? t('wizard.errors.required') : t('wizard.errors.invalid');
        case 'custom':
            if (issue.message.includes('lat and lng')) return t('wizard.errors.lat_lng');
            if (issue.message.includes('colors or a note')) return t('wizard.errors.colors_or_note');
            if (issue.message.includes('Meal')) return t('wizard.errors.meal_unique');
            return t('wizard.errors.invalid');
        default:
            return t('wizard.errors.invalid');
    }
}

function collect(result: z.ZodSafeParseResult<unknown>, prefix: string, t: Translate, into: ConfigErrors) {
    if (result.success) return;
    for (const issue of result.error.issues) {
        const pointer = prefix + (issue.path.length ? '/' + issue.path.map(String).join('/') : '');
        (into[pointer || '/'] ??= []).push(message(issue, t));
    }
}

/**
 * Client-side check before every autosave. The config schema's sections are a
 * plain union, whose errors only point at the section; each section is checked
 * again with its own schema so the error lands on the exact field.
 */
export function validateForEditor(config: unknown, t: Translate): { ok: true } | { ok: false; errors: ConfigErrors } {
    const result = invitationConfigSchema.safeParse(config);
    if (result.success) return { ok: true };

    const errors: ConfigErrors = {};
    const sections = (config as { sections?: { type?: SectionType }[] })?.sections ?? [];

    for (const issue of result.error.issues) {
        const [first, index] = issue.path;
        if (first === 'sections' && typeof index === 'number' && issue.path.length === 2 && issue.code === 'invalid_union') {
            const section = sections[index];
            const schema = section?.type ? SECTION_SCHEMAS[section.type] : undefined;
            if (schema) {
                collect(schema.safeParse(section), `/sections/${index}`, t, errors);
                continue;
            }
        }
        const pointer = '/' + issue.path.map(String).join('/');
        (errors[pointer] ??= []).push(message(issue, t));
    }

    return { ok: false, errors };
}

/** "config.sections.3.start" (Laravel) → "/sections/3/start". */
export function serverErrors(errors: Record<string, string[]>, t: Translate): ConfigErrors {
    const out: ConfigErrors = {};
    for (const key of Object.keys(errors)) {
        const pointer = '/' + key.replace(/^config\.?/, '').split('.').filter(Boolean).join('/');
        out[pointer] = [t('wizard.errors.server')];
    }
    return out;
}

/** Errors at a pointer or below it, for a section badge. */
export function hasErrorsUnder(errors: ConfigErrors, pointer: string): boolean {
    return Object.keys(errors).some((key) => key === pointer || key.startsWith(pointer + '/'));
}
