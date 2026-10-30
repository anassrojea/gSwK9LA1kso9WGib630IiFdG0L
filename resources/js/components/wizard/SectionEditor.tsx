import { CalendarDays, MapPin, Plus, Trash2 } from 'lucide-react';
import { useId, type ReactNode } from 'react';

import { AiPoemPanel } from '@/components/ai/AiPoemPanel';
import { AiStoryButton } from '@/components/ai/AiStoryDialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Field, ListEditor, SwitchField, TextField, patch } from '@/components/wizard/fields';
import { UploadButton } from '@/components/wizard/UploadButton';
import { poemSection } from '@/lib/ai';
import type { InvitationConfig, InvitationSection } from '@/lib/config-schema';
import { useT } from '@/lib/i18n';
import { OFFSETS, hijriDate, joinIso, splitIso } from '@/lib/wizard/dates';
import { COUPLE_OCCASIONS } from '@/lib/wizard/sections';
import { resolveMedia, type Uploaded } from '@/lib/wizard/upload';
import type { MediaMap } from '@/types/wizard';

export interface EditorContext {
    errorAt: (pointer: string) => string | undefined;
    media: MediaMap;
    addMedia: (file: Uploaded) => void;
    uploadUrl: string;
    occasion: InvitationConfig['occasion'];
    /** AI writing (phase 7B): the event, whether a host is signed in, the invitation's language */
    eventId?: string;
    signedIn?: boolean;
    locale?: 'ar' | 'en';
}

type Of<T extends InvitationSection['type']> = Extract<InvitationSection, { type: T }>;

interface EditorProps<T extends InvitationSection['type']> {
    section: Of<T>;
    onChange: (section: Of<T>) => void;
    at: (field: string) => string | undefined;
    ctx: EditorContext;
}

/** Optional small heading + title, folded away: defaults come from lang/*\/invite.php. */
function Headings({ section, onChange, at, withTitle = true }: { section: { label?: string; title?: string }; onChange: (s: { label?: string; title?: string }) => void; at: (f: string) => string | undefined; withTitle?: boolean }) {
    const { t } = useT();
    return (
        <details className="group rounded-lg border px-3 py-2">
            <summary className="text-muted-foreground flex min-h-9 cursor-pointer items-center text-sm font-medium">{t('wizard.details.headings')}</summary>
            <div className="grid gap-4 pt-3 pb-1">
                <TextField label={t('wizard.fields.label')} hint={t('wizard.fields.default_hint')} value={section.label} maxLength={60} onChange={(v) => onChange(patch(section, { label: v }))} error={at('label')} />
                {withTitle && <TextField label={t('wizard.fields.title')} hint={t('wizard.fields.default_hint')} value={section.title} maxLength={80} onChange={(v) => onChange(patch(section, { title: v }))} error={at('title')} />}
            </div>
        </details>
    );
}

function IntroLine({ section, onChange, at, ctx }: EditorProps<'intro_line'>) {
    const { t } = useT();
    return (
        <>
            {ctx.eventId && <AiPoemPanel eventId={ctx.eventId} locale={ctx.locale ?? 'ar'} signedIn={!!ctx.signedIn} onInsert={(poem) => onChange(poemSection(section, poem))} />}
            <TextField label={t('wizard.fields.intro_text')} multiline value={section.text} maxLength={2000} onChange={(v) => onChange({ ...section, text: v })} error={at('text')} />
            <Field label={t('wizard.fields.intro_style')}>
                <NativeSelect value={section.style ?? 'line'} onChange={(e) => onChange({ ...section, style: e.target.value as 'line' | 'verse' })}>
                    <option value="line">{t('wizard.fields.style_line')}</option>
                    <option value="verse">{t('wizard.fields.style_verse')}</option>
                </NativeSelect>
            </Field>
            <TextField label={t('wizard.fields.caption')} value={section.caption} maxLength={80} onChange={(v) => onChange(patch(section, { caption: v }))} error={at('caption')} />
        </>
    );
}

function Hosts({ section, onChange, at }: EditorProps<'hosts'>) {
    const { t } = useT();
    return (
        <>
            <Field label={t('wizard.fields.host_lines')} error={at('lines')}>
                <ListEditor
                    items={section.lines}
                    min={1}
                    max={6}
                    addLabel={t('wizard.fields.add_line')}
                    newItem={() => t('wizard.defaults.host_line')}
                    onChange={(lines) => onChange({ ...section, lines: lines as typeof section.lines })}
                    renderItem={(line, update, i) => <Input className="h-11" dir="auto" value={line} maxLength={300} aria-invalid={!!at(`lines/${i}`)} onChange={(e) => update(e.target.value)} />}
                />
            </Field>
            <TextField label={t('wizard.fields.outro')} value={section.outro} maxLength={200} onChange={(v) => onChange(patch(section, { outro: v }))} error={at('outro')} />
            <Headings section={section} onChange={(s) => onChange(s as typeof section)} at={at} withTitle={false} />
        </>
    );
}

function Names({ section, onChange, at, ctx }: EditorProps<'names'>) {
    const { t } = useT();
    const couple = COUPLE_OCCASIONS.includes(ctx.occasion);
    return (
        <>
            <TextField label={couple ? t('wizard.fields.first_name') : t('wizard.fields.name')} value={section.first} maxLength={40} onChange={(v) => onChange({ ...section, first: v })} error={at('first')} />
            {(couple || section.second !== undefined) && (
                <TextField label={t('wizard.fields.second_name')} value={section.second} maxLength={40} onChange={(v) => onChange(patch(section, { second: v }))} error={at('second')} />
            )}
            {(ctx.occasion === 'birthday' || section.age !== undefined) && (
                <TextField
                    label={t('wizard.fields.age')}
                    type="number"
                    inputMode="numeric"
                    dir="ltr"
                    value={section.age === undefined ? '' : String(section.age)}
                    onChange={(v) => onChange(patch(section, { age: v === '' ? undefined : Math.max(0, Math.min(150, Math.round(Number(v)))) }))}
                    error={at('age')}
                />
            )}
            <TextField label={t('wizard.fields.names_note')} value={section.note} maxLength={200} onChange={(v) => onChange(patch(section, { note: v }))} error={at('note')} />
        </>
    );
}

function DateEditor({ section, onChange, at }: EditorProps<'date'>) {
    const { t, locale } = useT();
    const parts = splitIso(section.start);
    const set = (changes: Partial<typeof parts>) => onChange({ ...section, start: joinIso({ ...parts, ...changes }) });
    const hijri = hijriDate(parts.date);
    const dateId = useId();
    const timeId = useId();

    return (
        <>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label={t('wizard.fields.date')} htmlFor={dateId} error={at('start')}>
                    <Input id={dateId} type="date" dir="ltr" className="h-11" value={parts.date} onChange={(e) => set({ date: e.target.value })} aria-invalid={!!at('start')} />
                </Field>
                <Field label={t('wizard.fields.time')} htmlFor={timeId}>
                    <Input id={timeId} type="time" dir="ltr" className="h-11" value={parts.time} onChange={(e) => set({ time: e.target.value })} />
                </Field>
            </div>
            <Field label={t('wizard.fields.timezone')} hint={t('wizard.fields.timezone_hint')}>
                <NativeSelect dir="ltr" value={parts.offset} onChange={(e) => set({ offset: e.target.value })}>
                    {[...new Set([parts.offset, ...OFFSETS])].map((o) => (
                        <option key={o} value={o}>
                            UTC{o}
                        </option>
                    ))}
                </NativeSelect>
            </Field>
            <div className="bg-accent/50 grid gap-1 rounded-lg p-3">
                <span className="text-muted-foreground flex items-center gap-1.5 text-xs font-semibold">
                    <CalendarDays className="size-4" /> {t('wizard.fields.hijri')}
                </span>
                <span className="text-lg font-semibold" lang="ar" data-hijri>
                    {section.hijriOverride || hijri || '—'}
                </span>
                {locale !== 'ar' && hijri && !section.hijriOverride && <span className="text-muted-foreground text-xs">{hijriDate(parts.date, 'en')}</span>}
            </div>
            <TextField
                label={t('wizard.fields.hijri_override')}
                hint={t('wizard.fields.hijri_override_hint')}
                value={section.hijriOverride}
                placeholder={hijri}
                maxLength={60}
                onChange={(v) => onChange(patch(section, { hijriOverride: v }))}
                error={at('hijriOverride')}
            />
            <SwitchField label={t('wizard.fields.show_hijri')} checked={section.showHijri ?? false} onChange={(v) => onChange({ ...section, showHijri: v })} />
            <SwitchField label={t('wizard.fields.countdown')} checked={section.countdown ?? false} onChange={(v) => onChange({ ...section, countdown: v })} />
            <Headings section={section} onChange={(s) => onChange(s as typeof section)} at={at} withTitle={false} />
        </>
    );
}

/** "https://maps.google.com/?q=24.7,46.6" or ".../@24.7,46.6,15z" → coordinates. */
export function coordinatesFromLink(link: string): { lat: number; lng: number } | null {
    const match = /(?:@|[?&](?:q|ll|query)=)(-?\d{1,2}(?:\.\d+)?),\s*(-?\d{1,3}(?:\.\d+)?)/.exec(link);
    if (!match) return null;
    const lat = Number(match[1]);
    const lng = Number(match[2]);
    return Math.abs(lat) <= 90 && Math.abs(lng) <= 180 ? { lat, lng } : null;
}

function Place({ section, onChange, at }: EditorProps<'place'>) {
    const { t } = useT();
    const number = (v: string) => (v.trim() === '' || Number.isNaN(Number(v)) ? undefined : Number(v));

    return (
        <>
            <TextField label={t('wizard.fields.venue')} value={section.name} maxLength={120} onChange={(v) => onChange({ ...section, name: v })} error={at('name')} />
            <TextField label={t('wizard.fields.address')} multiline value={section.address} maxLength={300} onChange={(v) => onChange(patch(section, { address: v }))} error={at('address')} />
            <TextField
                label={t('wizard.fields.map_link')}
                hint={t('wizard.fields.map_link_hint')}
                dir="ltr"
                type="url"
                value={section.mapUrl}
                maxLength={500}
                onChange={(v) => {
                    const coords = coordinatesFromLink(v);
                    onChange(patch(section, { mapUrl: v, ...(coords ?? {}) }));
                }}
                error={at('mapUrl')}
            />
            <div className="grid grid-cols-2 gap-3">
                <TextField label={t('wizard.fields.lat')} dir="ltr" inputMode="decimal" value={section.lat?.toString()} onChange={(v) => onChange(patch(section, { lat: number(v) }))} error={at('lat')} />
                <TextField label={t('wizard.fields.lng')} dir="ltr" inputMode="decimal" value={section.lng?.toString()} onChange={(v) => onChange(patch(section, { lng: number(v) }))} error={at('lng')} />
            </div>
            {section.lat !== undefined && section.lng !== undefined && (
                <a className="text-primary inline-flex items-center gap-1.5 text-sm underline-offset-4 hover:underline" href={`https://www.google.com/maps?q=${section.lat},${section.lng}`} target="_blank" rel="noopener">
                    <MapPin className="size-4" /> {t('wizard.fields.check_map')}
                </a>
            )}
            <Headings section={section} onChange={(s) => onChange(s as typeof section)} at={at} withTitle={false} />
        </>
    );
}

function Story({ section, onChange, at, ctx }: EditorProps<'story'>) {
    const { t, n } = useT();
    return (
        <>
            {ctx.eventId && (
                <AiStoryButton
                    eventId={ctx.eventId}
                    chapters={section.chapters}
                    locale={ctx.locale ?? 'ar'}
                    signedIn={!!ctx.signedIn}
                    onAccept={(chapters) => onChange({ ...section, chapters: chapters as typeof section.chapters })}
                />
            )}
            <Field label={t('wizard.fields.story_style')}>
                <NativeSelect value={section.style ?? 'timeline'} onChange={(e) => onChange({ ...section, style: e.target.value as typeof section.style })}>
                    {(['timeline', 'stem', 'letter', 'messages'] as const).map((s) => (
                        <option key={s} value={s}>
                            {t(`wizard.fields.story_styles.${s}`)}
                        </option>
                    ))}
                </NativeSelect>
            </Field>
            <Field label={t('wizard.fields.chapters')} error={at('chapters')}>
                <ListEditor<(typeof section.chapters)[number]>
                    items={section.chapters}
                    min={1}
                    max={12}
                    addLabel={t('wizard.fields.add_chapter')}
                    itemLabel={(i) => t('wizard.fields.chapter_n', { n: n(i + 1) })}
                    newItem={() => ({ title: t('wizard.defaults.chapter_title'), text: t('wizard.defaults.chapter_text') })}
                    onChange={(chapters) => onChange({ ...section, chapters: chapters as typeof section.chapters })}
                    renderItem={(chapter, update, i) => (
                        <>
                            <div className="grid grid-cols-[6rem_1fr] gap-3">
                                <TextField label={t('wizard.fields.year')} value={chapter.year} maxLength={20} onChange={(v) => update(patch(chapter, { year: v }))} error={at(`chapters/${i}/year`)} />
                                <TextField label={t('wizard.fields.chapter_title')} value={chapter.title} maxLength={80} onChange={(v) => update({ ...chapter, title: v })} error={at(`chapters/${i}/title`)} />
                            </div>
                            <TextField label={t('wizard.fields.chapter_text')} multiline value={chapter.text} maxLength={2000} onChange={(v) => update({ ...chapter, text: v })} error={at(`chapters/${i}/text`)} />
                        </>
                    )}
                />
            </Field>
            <TextField label={t('wizard.fields.quote')} multiline value={section.quote} maxLength={300} onChange={(v) => onChange(patch(section, { quote: v }))} error={at('quote')} />
            <Headings section={section} onChange={(s) => onChange(s as typeof section)} at={at} />
        </>
    );
}

function Program({ section, onChange, at }: EditorProps<'program'>) {
    const { t } = useT();
    return (
        <>
            <Field label={t('wizard.fields.program_items')} error={at('items')}>
                <ListEditor
                    items={section.items}
                    min={1}
                    max={20}
                    addLabel={t('wizard.fields.add_item')}
                    newItem={() => ({ time: section.items[section.items.length - 1]?.time ?? '20:00', label: t('wizard.defaults.program_1') })}
                    onChange={(items) => onChange({ ...section, items: items as typeof section.items })}
                    renderItem={(item, update, i) => (
                        <div className="grid grid-cols-[7.5rem_1fr] gap-3">
                            <Field label={t('wizard.fields.time')} error={at(`items/${i}/time`)}>
                                <Input type="time" dir="ltr" className="h-11" value={item.time} onChange={(e) => update({ ...item, time: e.target.value })} aria-invalid={!!at(`items/${i}/time`)} />
                            </Field>
                            <TextField label={t('wizard.fields.what')} value={item.label} maxLength={80} onChange={(v) => update({ ...item, label: v })} error={at(`items/${i}/label`)} />
                        </div>
                    )}
                />
            </Field>
            <Headings section={section} onChange={(s) => onChange(s as typeof section)} at={at} />
        </>
    );
}

function DressCode({ section, onChange, at }: EditorProps<'dress_code'>) {
    const { t } = useT();
    const colors = section.colors ?? [];
    return (
        <>
            <Field label={t('wizard.fields.colors')} error={at('colors')}>
                <div className="flex flex-wrap items-center gap-2">
                    {colors.map((color, i) => (
                        <span key={i} className="relative">
                            <input
                                type="color"
                                value={color.length === 4 ? '#' + [...color.slice(1)].map((c) => c + c).join('') : color}
                                onChange={(e) => onChange({ ...section, colors: colors.map((c, j) => (j === i ? e.target.value.toUpperCase() : c)) })}
                                className="size-12 cursor-pointer rounded-full border p-1"
                                aria-label={t('wizard.fields.color_n', { n: i + 1 })}
                            />
                            <button
                                type="button"
                                className="bg-background absolute -end-1 -top-1 grid size-6 place-items-center rounded-full border shadow"
                                onClick={() => onChange(patch(section, { colors: colors.length > 1 ? colors.filter((_, j) => j !== i) : undefined }))}
                                aria-label={t('wizard.common.remove')}
                            >
                                <Trash2 className="size-3" />
                            </button>
                        </span>
                    ))}
                    {colors.length < 8 && (
                        <Button type="button" variant="outline" size="icon" className="size-12 rounded-full border-dashed" onClick={() => onChange({ ...section, colors: [...colors, '#C9A35C'] })} aria-label={t('wizard.fields.add_color')}>
                            <Plus />
                        </Button>
                    )}
                </div>
            </Field>
            <TextField label={t('wizard.fields.dress_note')} multiline value={section.note} maxLength={300} onChange={(v) => onChange(patch(section, { note: v }))} error={at('note')} />
            <Headings section={section} onChange={(s) => onChange(s as typeof section)} at={at} />
        </>
    );
}

function Gallery({ section, onChange, at, ctx }: EditorProps<'gallery'>) {
    const { t } = useT();
    return (
        <>
            <Field label={t('wizard.fields.photos')} error={at('photos')} hint={t('wizard.fields.photos_hint')}>
                <ListEditor
                    items={section.photos}
                    min={1}
                    max={section.photos.length}
                    addLabel=""
                    newItem={() => section.photos[0]}
                    onChange={(photos) => onChange({ ...section, photos: photos as typeof section.photos })}
                    renderItem={(photo, update, i) => (
                        <div className="grid grid-cols-[5rem_1fr] items-start gap-3">
                            <img src={resolveMedia(photo.src, ctx.media)} alt="" className="bg-muted aspect-square w-20 rounded-md object-cover" />
                            <TextField label={t('wizard.fields.caption')} value={photo.caption} maxLength={80} onChange={(v) => update(patch(photo, { caption: v }))} error={at(`photos/${i}/caption`)} />
                        </div>
                    )}
                />
            </Field>
            {section.photos.length < 12 && (
                <UploadButton
                    url={ctx.uploadUrl}
                    type="image"
                    multiple
                    label={t('wizard.fields.add_photos')}
                    onUploaded={(file) => {
                        ctx.addMedia(file);
                        onChange({ ...section, photos: [...section.photos, { src: file.ref }].slice(0, 12) as typeof section.photos });
                    }}
                />
            )}
            <Headings section={section} onChange={(s) => onChange(s as typeof section)} at={at} />
        </>
    );
}

function Favorites({ section, onChange, at }: EditorProps<'favorites'>) {
    const { t } = useT();
    return (
        <>
            <ListEditor
                items={section.items}
                min={1}
                max={12}
                addLabel={t('wizard.fields.add_item')}
                newItem={() => ({ icon: '✨', text: t('wizard.defaults.favorite') })}
                onChange={(items) => onChange({ ...section, items: items as typeof section.items })}
                renderItem={(item, update, i) => (
                    <div className="grid grid-cols-[4.5rem_1fr] gap-3">
                        <TextField label={t('wizard.fields.icon')} value={item.icon} maxLength={8} onChange={(v) => update(patch(item, { icon: v }))} error={at(`items/${i}/icon`)} />
                        <TextField label={t('wizard.fields.text')} value={item.text} maxLength={60} onChange={(v) => update({ ...item, text: v })} error={at(`items/${i}/text`)} />
                    </div>
                )}
            />
            <Headings section={section} onChange={(s) => onChange(s as typeof section)} at={at} />
        </>
    );
}

function Wishes({ section, onChange, at }: EditorProps<'wishes'>) {
    const { t } = useT();
    return (
        <>
            <ListEditor<(typeof section.items)[number]>
                items={section.items}
                min={1}
                max={12}
                addLabel={t('wizard.fields.add_item')}
                newItem={() => ({ text: t('wizard.defaults.wish') })}
                onChange={(items) => onChange({ ...section, items: items as typeof section.items })}
                renderItem={(item, update, i) => (
                    <>
                        <TextField label={t('wizard.fields.wish')} multiline value={item.text} maxLength={300} onChange={(v) => update({ ...item, text: v })} error={at(`items/${i}/text`)} />
                        <TextField label={t('wizard.fields.wish_from')} value={item.name} maxLength={60} onChange={(v) => update(patch(item, { name: v }))} error={at(`items/${i}/name`)} />
                    </>
                )}
            />
            <Headings section={section} onChange={(s) => onChange(s as typeof section)} at={at} />
        </>
    );
}

function Rsvp({ section, onChange, at }: EditorProps<'rsvp'>) {
    const { t } = useT();
    const meals = section.mealOptions;
    const cutoff = splitIso(section.cutoff);
    const cutoffId = useId();

    return (
        <>
            <SwitchField label={t('wizard.fields.ask_message')} checked={section.askMessage ?? false} onChange={(v) => onChange({ ...section, askMessage: v })} />
            <SwitchField label={t('wizard.fields.ask_allergies')} checked={section.askAllergies ?? false} onChange={(v) => onChange({ ...section, askAllergies: v })} />
            <SwitchField label={t('wizard.fields.ask_kids')} checked={section.askKids ?? false} onChange={(v) => onChange({ ...section, askKids: v })} />
            <SwitchField
                label={t('wizard.fields.meal_choice')}
                checked={!!meals}
                onChange={(v) => onChange(patch(section, { mealOptions: v ? [t('wizard.defaults.meal_1'), t('wizard.defaults.meal_2')] : undefined }))}
            />
            {meals && (
                <Field label={t('wizard.fields.meal_options')} error={at('mealOptions')}>
                    <ListEditor
                        items={meals}
                        min={2}
                        max={6}
                        addLabel={t('wizard.fields.add_meal')}
                        newItem={() => ''}
                        onChange={(items) => onChange({ ...section, mealOptions: items })}
                        renderItem={(meal, update, i) => <Input className="h-11" dir="auto" value={meal} maxLength={40} aria-invalid={!!at(`mealOptions/${i}`)} onChange={(e) => update(e.target.value)} />}
                    />
                </Field>
            )}
            <Field label={t('wizard.fields.cutoff')} hint={t('wizard.fields.cutoff_hint')} htmlFor={cutoffId} error={at('cutoff')}>
                <div className="flex gap-2">
                    <Input
                        id={cutoffId}
                        type="date"
                        dir="ltr"
                        className="h-11"
                        value={cutoff.date}
                        onChange={(e) => onChange(patch(section, { cutoff: e.target.value ? joinIso({ date: e.target.value, time: '23:59', offset: cutoff.offset }) : undefined }))}
                    />
                    {section.cutoff && (
                        <Button type="button" variant="ghost" className="h-11" onClick={() => onChange(patch(section, { cutoff: undefined }))}>
                            {t('wizard.common.clear')}
                        </Button>
                    )}
                </div>
            </Field>
            <Headings section={section} onChange={(s) => onChange(s as typeof section)} at={at} />
        </>
    );
}

function AlbumTeaser({ section, onChange, at }: EditorProps<'album_teaser'>) {
    const { t } = useT();
    return (
        <>
            <TextField label={t('wizard.fields.title')} value={section.title} maxLength={80} onChange={(v) => onChange(patch(section, { title: v }))} error={at('title')} />
            <TextField label={t('wizard.fields.text')} multiline value={section.text} maxLength={300} onChange={(v) => onChange(patch(section, { text: v }))} error={at('text')} />
            <p className="text-muted-foreground text-xs">{t('wizard.fields.album_later')}</p>
        </>
    );
}

function Closing({ section, onChange, at }: EditorProps<'closing'>) {
    const { t } = useT();
    return (
        <>
            <TextField label={t('wizard.fields.title')} value={section.title} maxLength={80} onChange={(v) => onChange(patch(section, { title: v }))} error={at('title')} />
            <TextField label={t('wizard.fields.text')} multiline value={section.text} maxLength={300} onChange={(v) => onChange({ ...section, text: v })} error={at('text')} />
        </>
    );
}

/** The thank-you card's gallery: its photos are picked from the album on the keepsakes page. */
function ThankYouGallery({ section, onChange, at }: EditorProps<'thank_you_gallery'>) {
    const { t } = useT();
    return (
        <>
            <TextField label={t('wizard.fields.title')} value={section.title} maxLength={80} onChange={(v) => onChange(patch(section, { title: v }))} error={at('title')} />
            <TextField label={t('keepsakes.thank_you.message')} multiline value={section.message} maxLength={2000} onChange={(v) => onChange({ ...section, message: v })} error={at('message')} />
            <TextField label={t('keepsakes.thank_you.signature')} value={section.signature} maxLength={120} onChange={(v) => onChange(patch(section, { signature: v }))} error={at('signature')} />
        </>
    );
}

function SendRose({ section, onChange, at }: EditorProps<'send_rose'>) {
    const { t } = useT();
    return (
        <>
            <p className="text-muted-foreground text-sm">{t('wizard.fields.send_rose_help')}</p>
            <TextField label={t('wizard.fields.label')} hint={t('wizard.fields.default_hint')} value={section.label} maxLength={60} onChange={(v) => onChange(patch(section, { label: v }))} error={at('label')} />
        </>
    );
}

/** One editor per v1 section type. */
export function SectionEditor({ section, index, onChange, ctx }: { section: InvitationSection; index: number; onChange: (s: InvitationSection) => void; ctx: EditorContext }) {
    const at = (field: string) => ctx.errorAt(`/sections/${index}/${field}`) ?? (field === '' ? ctx.errorAt(`/sections/${index}`) : undefined);
    const props = { onChange: onChange as never, at, ctx };
    const general = ctx.errorAt(`/sections/${index}`);

    const editors: Record<InvitationSection['type'], () => ReactNode> = {
        intro_line: () => <IntroLine section={section as Of<'intro_line'>} {...props} />,
        hosts: () => <Hosts section={section as Of<'hosts'>} {...props} />,
        names: () => <Names section={section as Of<'names'>} {...props} />,
        date: () => <DateEditor section={section as Of<'date'>} {...props} />,
        story: () => <Story section={section as Of<'story'>} {...props} />,
        program: () => <Program section={section as Of<'program'>} {...props} />,
        place: () => <Place section={section as Of<'place'>} {...props} />,
        dress_code: () => <DressCode section={section as Of<'dress_code'>} {...props} />,
        gallery: () => <Gallery section={section as Of<'gallery'>} {...props} />,
        favorites: () => <Favorites section={section as Of<'favorites'>} {...props} />,
        wishes: () => <Wishes section={section as Of<'wishes'>} {...props} />,
        send_rose: () => <SendRose section={section as Of<'send_rose'>} {...props} />,
        rsvp: () => <Rsvp section={section as Of<'rsvp'>} {...props} />,
        album_teaser: () => <AlbumTeaser section={section as Of<'album_teaser'>} {...props} />,
        closing: () => <Closing section={section as Of<'closing'>} {...props} />,
        thank_you_gallery: () => <ThankYouGallery section={section as Of<'thank_you_gallery'>} {...props} />,
    };

    return (
        <div className="grid gap-5">
            {general && (
                <p className="text-destructive text-sm" role="alert">
                    {general}
                </p>
            )}
            {editors[section.type]()}
        </div>
    );
}
