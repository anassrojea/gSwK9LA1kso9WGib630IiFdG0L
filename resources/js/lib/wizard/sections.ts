import type { InvitationConfig, InvitationSection, SectionType } from '@/lib/config-schema';
import { SECTION_TYPES_V1 } from '@/lib/config-schema';

import { defaultStart } from './dates';

type Translate = (key: string, replace?: Record<string, string | number>) => string;

export const COUPLE_OCCASIONS: InvitationConfig['occasion'][] = ['wedding', 'engagement', 'henna', 'katb_kitab'];

/** Sections that need an upload first (gallery photos are required). */
export const NEEDS_UPLOAD: SectionType[] = ['gallery'];

/**
 * A ready-to-edit section of each type, valid as it is: the host only
 * replaces the sample text (plan section 3, "Everything has a good default").
 */
export function newSection(type: SectionType, t: Translate, occasion: InvitationConfig['occasion']): InvitationSection {
    const d = (key: string) => t(`wizard.defaults.${key}`);
    switch (type) {
        case 'intro_line':
            return { type, text: d('intro') };
        case 'hosts':
            return { type, lines: [d('host_line')], outro: d(`outro.${occasion}`) };
        case 'names':
            return COUPLE_OCCASIONS.includes(occasion)
                ? { type, first: d('first_name'), second: d('second_name') }
                : { type, first: d(`names.${occasion}`), ...(occasion === 'birthday' ? { age: 7 } : {}) };
        case 'date':
            return { type, start: defaultStart(), showHijri: true, countdown: true };
        case 'story':
            return { type, style: 'timeline', chapters: [{ year: String(new Date().getFullYear()), title: d('chapter_title'), text: d('chapter_text') }] };
        case 'program':
            return {
                type,
                items: [
                    { time: '20:00', label: d('program_1') },
                    { time: '21:00', label: d('program_2') },
                ],
            };
        case 'place':
            return { type, name: d('place') };
        case 'dress_code':
            return { type, colors: ['#F5E6D3', '#C9A35C'], note: d('dress_note') };
        case 'gallery':
            // filled with the first upload: a gallery needs at least one photo
            return { type, photos: [] as unknown as [{ src: string }] };
        case 'favorites':
            return { type, items: [{ icon: '☕', text: d('favorite') }] };
        case 'wishes':
            return { type, items: [{ text: d('wish'), name: d('wish_name') }] };
        case 'send_rose':
            return { type };
        case 'rsvp':
            return { type, askMessage: true };
        case 'album_teaser':
            return { type, text: d('album') };
        case 'closing':
            return { type, text: d('closing') };
        case 'thank_you_gallery':
            return { type, message: t('keepsakes.thank_you.default_message'), photos: [] };
    }
}

/**
 * Sections the wizard offers to add. The thank-you gallery belongs to the
 * thank-you card (dashboard → keepsakes); a save-the-date has no RSVP.
 */
export function missingTypes(config: InvitationConfig): SectionType[] {
    const present = new Set(config.sections.map((s) => s.type));
    const excluded: SectionType[] = config.kind === 'save_the_date' ? ['rsvp', 'send_rose'] : [];
    return SECTION_TYPES_V1.filter((type) => !present.has(type) && !excluded.includes(type));
}

/** A short summary for the section list ("ريم و عمر", "٤ فصول"…). */
export function sectionSummary(section: InvitationSection, t: Translate, n: (v: string | number) => string, locale = 'ar', sep = '، '): string {
    switch (section.type) {
        case 'intro_line':
        case 'closing':
            return section.text;
        case 'names':
            return [section.first, section.second].filter(Boolean).join(sep);
        case 'hosts':
            return section.lines[0] ?? '';
        case 'date':
            try {
                // the wall-clock time printed on the invitation, whatever the host's own zone
                return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-u-nu-arab' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'UTC' }).format(
                    new Date(section.start.slice(0, 19) + 'Z'),
                );
            } catch {
                return n(section.start.slice(0, 10));
            }
        case 'place':
            return section.name;
        case 'story':
            return t('wizard.details.count_chapters', { count: section.chapters.length });
        case 'program':
            return t('wizard.details.count_items', { count: section.items.length });
        case 'gallery':
            return t('wizard.details.count_photos', { count: section.photos.length });
        case 'favorites':
        case 'wishes':
            return t('wizard.details.count_items', { count: section.items.length });
        case 'dress_code':
            return section.note ?? '';
        case 'album_teaser':
            return section.text ?? '';
        default:
            return 'title' in section ? (section.title ?? '') : '';
    }
}
