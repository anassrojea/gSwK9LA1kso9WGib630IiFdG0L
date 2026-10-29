/**
 * Invitation config (plan section 6.2), Zod edition for instant feedback in
 * the React editor. The authoritative check runs on the server against
 * app/Services/InvitationConfig/schemas/v1.json: keep both in sync, and
 * never change a released version; add v2 alongside it instead.
 */
import { z } from 'zod';

export const CONFIG_VERSION = 1 as const;

export const OCCASIONS = ['wedding', 'engagement', 'henna', 'birthday', 'graduation', 'baby_welcome', 'iftar', 'other'] as const;
export const OPENINGS = ['envelope', 'rose', 'ringbox', 'doors', 'gift', 'diploma'] as const;
export const SECTION_TYPES = [
    'intro_line', 'hosts', 'names', 'date', 'story', 'program', 'place', 'dress_code', 'send_rose', 'rsvp', 'album_teaser',
] as const;

const color = z.string().regex(/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/, 'Use a hex color like #A8325A');
const slug = z.string().max(64).regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/);
const font = z.string().regex(/^[A-Za-z0-9 ]{2,40}$/);
const text = z.string().min(1).max(300);
const longText = z.string().min(1).max(2000);
const label = z.string().max(60).optional();
const title = z.string().max(80).optional();
const hidden = z.boolean().optional();
// JSON Schema "format": "date-time" (RFC 3339, offset required)
const dateTime = z.iso.datetime({ offset: true });

const section = <T extends (typeof SECTION_TYPES)[number], S extends z.ZodRawShape>(type: T, shape: S) =>
    z.strictObject({ type: z.literal(type), hidden, ...shape });

export const introLineSection = section('intro_line', {
    text: longText,
    style: z.enum(['line', 'verse']).optional(),
    caption: z.string().max(80).optional(),
});

export const hostsSection = section('hosts', {
    label,
    lines: z.array(text).min(1).max(6),
    outro: z.string().max(200).optional(),
});

export const namesSection = section('names', {
    first: z.string().min(1).max(40),
    second: z.string().min(1).max(40).optional(),
    note: z.string().max(200).optional(),
});

export const dateSection = section('date', {
    label,
    start: dateTime,
    showHijri: z.boolean().optional(),
    hijriOverride: z.string().max(60).optional(),
    countdown: z.boolean().optional(),
});

export const storySection = section('story', {
    label,
    title,
    style: z.enum(['timeline', 'stem', 'letter']).optional(),
    chapters: z
        .array(z.strictObject({ year: z.string().max(20).optional(), title: z.string().min(1).max(80), text: longText }))
        .min(1)
        .max(12),
    quote: z.string().max(300).optional(),
});

export const programSection = section('program', {
    label,
    title,
    items: z
        .array(z.strictObject({ time: z.string().regex(/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/), label: z.string().min(1).max(80) }))
        .min(1)
        .max(20),
});

export const placeSection = section('place', {
    label,
    name: z.string().min(1).max(120),
    address: z.string().max(300).optional(),
    lat: z.number().min(-90).max(90).optional(),
    lng: z.number().min(-180).max(180).optional(),
}).refine((s) => (s.lat === undefined) === (s.lng === undefined), { message: 'lat and lng go together', path: ['lng'] });

export const dressCodeSection = section('dress_code', {
    label,
    colors: z.array(color).min(1).max(8),
    note: z.string().max(200).optional(),
});

export const sendRoseSection = section('send_rose', { label });

export const rsvpSection = section('rsvp', {
    label,
    title,
    askMessage: z.boolean().optional(),
    askAllergies: z.boolean().optional(),
    cutoff: dateTime.optional(),
});

export const albumTeaserSection = section('album_teaser', {
    label,
    title,
    text: z.string().max(300).optional(),
});

export const sectionSchema = z.union([
    introLineSection,
    hostsSection,
    namesSection,
    dateSection,
    storySection,
    programSection,
    placeSection,
    dressCodeSection,
    sendRoseSection,
    rsvpSection,
    albumTeaserSection,
]);

export const invitationConfigSchema = z.strictObject({
    version: z.literal(CONFIG_VERSION),
    occasion: z.enum(OCCASIONS),
    locale: z.enum(['ar', 'en']),
    opening: z.strictObject({
        id: z.enum(OPENINGS),
        options: z
            .strictObject({
                petalColor: color.optional(),
                envelopeColor: color.optional(),
                sealText: z.string().max(6).optional(),
                kicker: z.string().max(60).optional(),
                headline: z.string().max(80).optional(),
            })
            .optional(),
    }),
    theme: z.strictObject({
        id: slug,
        colors: z
            .record(z.string().regex(/^[a-z][a-zA-Z0-9]{0,23}$/), color)
            .refine((c) => ['bg', 'ink', 'accent', 'soft', 'gold'].every((k) => k in c), { message: 'bg, ink, accent, soft and gold are required' })
            .refine((c) => Object.keys(c).length <= 16, { message: 'At most 16 colors' }),
        fonts: z.strictObject({ display: font, body: font, label: font }),
        radius: z.number().int().min(0).max(32).optional(),
    }),
    effects: z
        .strictObject({ fallingPetals: z.boolean().optional(), sparkles: z.boolean().optional(), music: z.boolean().optional() })
        .optional(),
    sections: z.array(sectionSchema).min(1).max(30),
});

export type InvitationConfig = z.infer<typeof invitationConfigSchema>;
export type InvitationSection = z.infer<typeof sectionSchema>;
export type SectionType = (typeof SECTION_TYPES)[number];

/**
 * Validate a config and return errors keyed by JSON pointer, the same shape
 * the server returns (e.g. "/sections/3/start").
 */
export function validateConfig(input: unknown): { success: true; data: InvitationConfig } | { success: false; errors: Record<string, string[]> } {
    const result = invitationConfigSchema.safeParse(input);

    if (result.success) {
        return { success: true, data: result.data };
    }

    const errors: Record<string, string[]> = {};
    for (const issue of result.error.issues) {
        const pointer = '/' + issue.path.map(String).join('/');
        (errors[pointer] ??= []).push(issue.message);
    }

    return { success: false, errors };
}
