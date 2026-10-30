/**
 * Invitation config (plan section 6.2), Zod edition for instant feedback in
 * the React editor. The authoritative check runs on the server against
 * app/Services/InvitationConfig/schemas/v{1,2}.json: keep both in sync, and
 * never change a released version. v1 is frozen; v2 (phase 8D) adds
 * occasions, the optional `kind` and the thank_you_gallery section.
 * validateConfig picks the schema by the document's own version.
 */
import { z } from 'zod';

/** What the editor writes. */
export const CONFIG_VERSION = 2 as const;
export const SUPPORTED_VERSIONS = [1, 2] as const;

export const OCCASIONS_V1 = ['wedding', 'engagement', 'henna', 'birthday', 'graduation', 'baby_welcome', 'iftar', 'other'] as const;
export const OCCASIONS = [
    'wedding', 'engagement', 'henna', 'birthday', 'graduation', 'baby_welcome', 'iftar',
    'katb_kitab', 'eid', 'housewarming', 'hajj_return', 'welcome_home', 'corporate', 'save_the_date', 'thank_you', 'other',
] as const;
export const KINDS = ['invitation', 'save_the_date', 'thank_you'] as const;
export const OPENINGS = ['envelope', 'rose', 'ringbox', 'doors', 'gift', 'diploma'] as const;
export const SECTION_TYPES_V1 = [
    'intro_line', 'hosts', 'names', 'date', 'story', 'program', 'place', 'dress_code', 'gallery', 'favorites', 'wishes',
    'send_rose', 'rsvp', 'album_teaser', 'closing',
] as const;
export const SECTION_TYPES = [...SECTION_TYPES_V1, 'thank_you_gallery'] as const;

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
/** An https URL, a public /storage path, or media:<uuid> for an uploaded file. */
export const mediaRef = z
    .string()
    .max(500)
    .regex(/^(https:\/\/[^\s"'<>]+|\/storage\/[A-Za-z0-9/_.-]+|media:[0-9a-fA-F-]{36})$/, 'Use an https link or an uploaded file');

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
    age: z.number().int().min(0).max(150).optional(),
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
    style: z.enum(['timeline', 'stem', 'letter', 'messages']).optional(),
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
    mapUrl: z.string().max(500).regex(/^https:\/\//).optional(),
}).refine((s) => (s.lat === undefined) === (s.lng === undefined), { message: 'lat and lng go together', path: ['lng'] });

export const dressCodeSection = section('dress_code', {
    label,
    title,
    colors: z.array(color).min(1).max(8).optional(),
    note: z.string().max(300).optional(),
}).refine((s) => s.colors !== undefined || s.note !== undefined, { message: 'Add colors or a note', path: ['colors'] });

export const gallerySection = section('gallery', {
    label,
    title,
    photos: z
        .array(z.strictObject({ src: mediaRef, caption: z.string().max(80).optional(), alt: z.string().max(120).optional() }))
        .min(1)
        .max(12),
});

export const favoritesSection = section('favorites', {
    label,
    title,
    items: z.array(z.strictObject({ icon: z.string().max(8).optional(), text: z.string().min(1).max(60) })).min(1).max(12),
});

export const wishesSection = section('wishes', {
    label,
    title,
    items: z.array(z.strictObject({ name: z.string().max(60).optional(), text: z.string().min(1).max(300) })).min(1).max(12),
});

export const sendRoseSection = section('send_rose', { label });

export const rsvpSection = section('rsvp', {
    label,
    title,
    askMessage: z.boolean().optional(),
    askAllergies: z.boolean().optional(),
    askKids: z.boolean().optional(),
    mealOptions: z
        .array(z.string().min(1).max(40))
        .min(2)
        .max(6)
        .refine((o) => new Set(o).size === o.length, { message: 'Meal options must be different' })
        .optional(),
    cutoff: dateTime.optional(),
});

export const albumTeaserSection = section('album_teaser', {
    label,
    title,
    text: z.string().max(300).optional(),
});

export const closingSection = section('closing', { label, title, text: z.string().min(1).max(300) });

/** v2: after the event, a message, album photos (media:<uuid>) and the hosts' signature. */
export const thankYouGallerySection = section('thank_you_gallery', {
    label,
    title,
    message: longText,
    photos: z
        .array(z.strictObject({ src: mediaRef, caption: z.string().max(80).optional(), alt: z.string().max(120).optional() }))
        .max(24)
        .optional(),
    signature: z.string().max(120).optional(),
});

const v1Sections = [
    introLineSection,
    hostsSection,
    namesSection,
    dateSection,
    storySection,
    programSection,
    placeSection,
    dressCodeSection,
    gallerySection,
    favoritesSection,
    wishesSection,
    sendRoseSection,
    rsvpSection,
    albumTeaserSection,
    closingSection,
] as const;

export const sectionSchemaV1 = z.union(v1Sections);
export const sectionSchema = z.union([...v1Sections, thankYouGallerySection]);

const common = {
    locale: z.enum(['ar', 'en']),
    opening: z.strictObject({
        id: z.enum(OPENINGS),
        options: z
            .strictObject({
                color: color.optional(),
                accentColor: color.optional(),
                petalColor: color.optional(),
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
    media: z
        .strictObject({
            cover: z.strictObject({ type: z.enum(['image', 'video']), src: mediaRef, poster: mediaRef.optional() }).optional(),
            music: z.strictObject({ src: mediaRef, title: z.string().max(80).optional() }).optional(),
        })
        .optional(),
};

/** v1, frozen. */
export const invitationConfigSchemaV1 = z.strictObject({
    version: z.literal(1),
    occasion: z.enum(OCCASIONS_V1),
    ...common,
    sections: z.array(sectionSchemaV1).min(1).max(30),
});

/** v2: v1 plus the new occasions, the optional kind and thank_you_gallery. */
export const invitationConfigSchemaV2 = z.strictObject({
    version: z.literal(2),
    occasion: z.enum(OCCASIONS),
    kind: z.enum(KINDS).optional(),
    ...common,
    sections: z.array(sectionSchema).min(1).max(30),
});

/** The current version (what the editor saves). */
export const invitationConfigSchema = invitationConfigSchemaV2;

type V2 = z.infer<typeof invitationConfigSchemaV2>;
/** A config of any supported version: v1 documents are a subset of v2 apart from the version number. */
export type InvitationConfig = Omit<V2, 'version'> & { version: (typeof SUPPORTED_VERSIONS)[number] };
export type InvitationSection = z.infer<typeof sectionSchema>;
export type SectionType = (typeof SECTION_TYPES)[number];
export type ConfigKind = (typeof KINDS)[number];

/** The twin of InvitationConfig::upgrade(): v1 → v2 only changes the version. */
export function upgradeConfig(config: InvitationConfig): InvitationConfig {
    return config.version === 1 ? { ...config, version: CONFIG_VERSION } : config;
}

export function configKind(config: Pick<InvitationConfig, 'kind'>): ConfigKind {
    return config.kind ?? 'invitation';
}

/**
 * Validate a config and return errors keyed by JSON pointer, the same shape
 * the server returns (e.g. "/sections/3/start").
 */
export function validateConfig(input: unknown): { success: true; data: InvitationConfig } | { success: false; errors: Record<string, string[]> } {
    const version = (input as { version?: unknown } | null)?.version;
    if (version !== 1 && version !== 2) {
        return { success: false, errors: { '/version': [`Unsupported config version; expected one of ${SUPPORTED_VERSIONS.join(', ')}.`] } };
    }
    const result = (version === 1 ? invitationConfigSchemaV1 : invitationConfigSchemaV2).safeParse(input);

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
