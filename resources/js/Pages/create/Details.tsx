import { DndContext, KeyboardSensor, PointerSensor, closestCenter, useSensor, useSensors, type DragEndEvent } from '@dnd-kit/core';
import { SortableContext, arrayMove, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { AlertCircle, ArrowLeft, ArrowRight, ChevronDown, ChevronLeft, ChevronRight, ChevronUp, Eye, EyeOff, GripVertical, Image as ImageIcon, Languages, Music, Plus, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Field, SwitchField, TextField, patch } from '@/components/wizard/fields';
import { SectionEditor, type EditorContext } from '@/components/wizard/SectionEditor';
import { TierBadge } from '@/components/wizard/TierBadge';
import { UploadButton } from '@/components/wizard/UploadButton';
import WizardLayout from '@/components/wizard/WizardLayout';
import { useAutosave } from '@/hooks/useAutosave';
import type { InvitationConfig, InvitationSection, SectionType } from '@/lib/config-schema';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { hasErrorsUnder } from '@/lib/wizard/validate';
import { NEEDS_UPLOAD, missingTypes, newSection, sectionSummary } from '@/lib/wizard/sections';
import { resolveMedia, type Uploaded } from '@/lib/wizard/upload';
import type { MediaMap, WizardProps } from '@/types/wizard';

interface Props extends WizardProps {
    media: MediaMap;
}

type Open = number | 'cover' | 'settings' | null;

let nextId = 0;
const newId = () => `s${++nextId}`;

function SortableRow({ id, children }: { id: string; children: (handle: React.HTMLAttributes<HTMLButtonElement>) => React.ReactNode }) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id });
    return (
        <li ref={setNodeRef} style={{ transform: CSS.Transform.toString(transform), transition }} className={cn('relative', isDragging && 'z-10 opacity-80 shadow-lg')}>
            {children({ ...attributes, ...listeners })}
        </li>
    );
}

/**
 * Step 3: every section of the invitation. Phones get one section per
 * screen; the list reorders by dragging, and each section can be hidden.
 */
export default function Details(props: Props) {
    const { t, n, sep, dir, locale } = useT();
    const autosave = useAutosave(props.urls.config, props.config);
    const { config, update } = autosave;
    const [media, setMedia] = useState<MediaMap>(props.media);
    const [open, setOpen] = useState<Open>(null);
    const [adding, setAdding] = useState(false);
    const ids = useRef<string[]>(props.config.sections.map(() => newId()));
    const top = useRef<HTMLDivElement>(null);

    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 6 } }), useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }));

    const addMedia = (file: Uploaded) => setMedia((m) => ({ ...m, [file.id]: { url: file.url, kind: file.kind } }));
    const ctx: EditorContext = { errorAt: autosave.errorAt, media, addMedia, uploadUrl: props.urls.media, occasion: config.occasion };

    const show = (target: Open) => {
        setOpen(target);
        requestAnimationFrame(() => top.current?.scrollIntoView({ block: 'start' }));
    };

    const setSections = (change: (sections: InvitationSection[]) => InvitationSection[]) => update((c) => ({ ...c, sections: change(c.sections) as InvitationConfig['sections'] }));
    const setSection = (index: number, section: InvitationSection) => setSections((list) => list.map((s, i) => (i === index ? section : s)));
    const toggleHidden = (index: number) =>
        setSections((list) => list.map((s, i) => (i === index ? (s.hidden ? (patch(s, { hidden: undefined }) as InvitationSection) : { ...s, hidden: true }) : s)));

    const removeSection = (index: number) => {
        ids.current = ids.current.filter((_, i) => i !== index);
        setSections((list) => list.filter((_, i) => i !== index));
        show(null);
    };

    const addSection = (type: SectionType, section?: InvitationSection) => {
        // the closing words go last, everything else just before the RSVP
        const created = section ?? newSection(type, t, config.occasion);
        const list = config.sections;
        const rsvpAt = list.findIndex((s) => s.type === 'rsvp');
        const at = type === 'closing' || type === 'album_teaser' || rsvpAt < 0 ? list.length : rsvpAt;
        ids.current.splice(at, 0, newId());
        setSections((l) => [...l.slice(0, at), created, ...l.slice(at)]);
        setAdding(false);
        show(at);
    };

    const onDragEnd = ({ active, over }: DragEndEvent) => {
        if (!over || active.id === over.id) return;
        const from = ids.current.indexOf(String(active.id));
        const to = ids.current.indexOf(String(over.id));
        ids.current = arrayMove(ids.current, from, to);
        setSections((list) => arrayMove(list, from, to));
    };

    const move = (index: number, by: number) => {
        const to = index + by;
        if (to < 0 || to >= config.sections.length) return;
        ids.current = arrayMove(ids.current, index, to);
        setSections((list) => arrayMove(list, index, to));
    };

    const Prev = dir === 'rtl' ? ChevronRight : ChevronLeft;
    const Next = dir === 'rtl' ? ChevronLeft : ChevronRight;
    const BackArrow = dir === 'rtl' ? ArrowRight : ArrowLeft;
    const typeName = (type: SectionType) => t(`wizard.section_types.${type}`);

    const layout = (body: React.ReactNode) => (
        <WizardLayout
            step="details"
            eventId={props.event.id}
            title={t('wizard.details.title')}
            subtitle={t('wizard.details.subtitle')}
            saveState={autosave.state}
            previewUrl={props.urls.preview}
            previewVersion={autosave.savedCount}
            beforeLeave={autosave.flush}
        >
            <div ref={top} className="scroll-mt-32" />
            {body}
        </WizardLayout>
    );

    // ---------- one section (or cover / settings) per screen ----------
    if (open !== null) {
        const index = typeof open === 'number' ? open : -1;
        const section = index >= 0 ? config.sections[index] : undefined;
        const heading = open === 'cover' ? t('wizard.details.cover_music') : open === 'settings' ? t('wizard.details.settings') : section ? typeName(section.type) : '';

        return layout(
            <div className="grid gap-5">
                <div className="flex items-center justify-between gap-2">
                    <Button variant="ghost" className="-ms-3 h-11" onClick={() => show(null)}>
                        <BackArrow /> {t('wizard.details.all_sections')}
                    </Button>
                    {section && (
                        <Button variant="ghost" className="text-destructive h-11" onClick={() => removeSection(index)}>
                            <Trash2 /> {t('wizard.details.remove_section')}
                        </Button>
                    )}
                </div>
                <h2 className="text-xl font-bold">{heading}</h2>
                <div className="bg-card grid gap-5 rounded-xl border p-4 sm:p-5">
                    {section && <SwitchField label={t('wizard.details.show_section')} checked={!section.hidden} onChange={() => toggleHidden(index)} />}
                    {section && <SectionEditor section={section} index={index} ctx={ctx} onChange={(s) => setSection(index, s)} />}
                    {open === 'cover' && <CoverAndMusic config={config} update={update} ctx={ctx} />}
                    {open === 'settings' && <Settings config={config} update={update} />}
                </div>
                {section && (
                    <div className="flex items-center justify-between gap-3">
                        <Button variant="outline" className="h-12 flex-1" disabled={index === 0} onClick={() => show(index - 1)}>
                            <Prev /> {t('wizard.details.prev_section')}
                        </Button>
                        <Button variant="outline" className="h-12 flex-1" disabled={index === config.sections.length - 1} onClick={() => show(index + 1)}>
                            {t('wizard.details.next_section')} <Next />
                        </Button>
                    </div>
                )}
            </div>,
        );
    }

    // ---------- the list ----------
    const extras = missingTypes(config);

    return layout(
        <div className="grid gap-6">
            <div className="grid gap-2 sm:grid-cols-2">
                <button type="button" onClick={() => show('cover')} className="bg-card hover:border-primary/50 flex min-h-16 items-center gap-3 rounded-xl border p-3 text-start">
                    <span className="bg-primary/10 text-primary grid size-10 place-items-center rounded-full">
                        <ImageIcon className="size-5" />
                    </span>
                    <span className="grid flex-1">
                        <span className="font-semibold">{t('wizard.details.cover_music')}</span>
                        <span className="text-muted-foreground flex items-center gap-1 text-xs">
                            {config.media?.cover ? t(`wizard.details.cover_${config.media.cover.type}`) : t('wizard.details.no_cover')}
                            {sep}
                            <Music className="size-3" /> {config.effects?.music ? t('wizard.common.on') : t('wizard.common.off')}
                        </span>
                    </span>
                    <Next className="text-muted-foreground size-5" />
                </button>
                <button type="button" onClick={() => show('settings')} className="bg-card hover:border-primary/50 flex min-h-16 items-center gap-3 rounded-xl border p-3 text-start">
                    <span className="bg-primary/10 text-primary grid size-10 place-items-center rounded-full">
                        <Languages className="size-5" />
                    </span>
                    <span className="grid flex-1">
                        <span className="font-semibold">{t('wizard.details.settings')}</span>
                        <span className="text-muted-foreground text-xs">{t(`wizard.languages.${config.locale}`)}</span>
                    </span>
                    <Next className="text-muted-foreground size-5" />
                </button>
            </div>

            <section className="grid gap-3">
                <div className="flex items-end justify-between gap-2">
                    <h2 className="font-semibold">{t('wizard.details.sections')}</h2>
                    <span className="text-muted-foreground text-xs">{t('wizard.details.drag_hint')}</span>
                </div>
                <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
                    <SortableContext items={ids.current} strategy={verticalListSortingStrategy}>
                        <ol className="grid gap-2" data-section-list>
                            {config.sections.map((section, i) => (
                                <SortableRow key={ids.current[i]} id={ids.current[i]}>
                                    {(handle) => (
                                        <div className={cn('bg-card flex min-h-16 items-center gap-1 rounded-xl border pe-1', section.hidden && 'bg-muted/60')}>
                                            <button type="button" className="text-muted-foreground grid h-16 w-9 shrink-0 cursor-grab touch-none place-items-center active:cursor-grabbing" aria-label={t('wizard.details.reorder', { name: typeName(section.type) })} {...handle}>
                                                <GripVertical className="size-5" />
                                            </button>
                                            <button type="button" className="grid min-w-0 flex-1 py-2 text-start" onClick={() => show(i)} data-section={section.type}>
                                                <span className={cn('flex items-center gap-2 font-semibold', section.hidden && 'text-muted-foreground')}>
                                                    <span className="text-muted-foreground text-xs tabular-nums">{n(i + 1)}</span>
                                                    {typeName(section.type)}
                                                    {hasErrorsUnder(autosave.errors, `/sections/${i}`) && <AlertCircle className="text-destructive size-4" aria-label={t('wizard.save.invalid')} />}
                                                </span>
                                                <span className="text-muted-foreground truncate text-xs">{section.hidden ? t('wizard.details.hidden') : sectionSummary(section, t, n, locale, sep)}</span>
                                            </button>
                                            <div className="hidden items-center sm:flex">
                                                <Button variant="ghost" size="icon" className="size-10" disabled={i === 0} onClick={() => move(i, -1)} aria-label={t('wizard.common.move_up')}>
                                                    <ChevronUp className="size-5" />
                                                </Button>
                                                <Button variant="ghost" size="icon" className="size-10" disabled={i === config.sections.length - 1} onClick={() => move(i, 1)} aria-label={t('wizard.common.move_down')}>
                                                    <ChevronDown className="size-5" />
                                                </Button>
                                            </div>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-11"
                                                onClick={() => toggleHidden(i)}
                                                aria-pressed={!section.hidden}
                                                aria-label={section.hidden ? t('wizard.details.show') : t('wizard.details.hide')}
                                            >
                                                {section.hidden ? <EyeOff className="size-5" /> : <Eye className="size-5" />}
                                            </Button>
                                        </div>
                                    )}
                                </SortableRow>
                            ))}
                        </ol>
                    </SortableContext>
                </DndContext>
                {extras.length > 0 && (
                    <Button variant="outline" className="h-12 border-dashed" onClick={() => setAdding(true)}>
                        <Plus /> {t('wizard.details.add_section')}
                    </Button>
                )}
            </section>

            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent closeLabel={t('wizard.common.close')} className="max-h-[85svh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>{t('wizard.details.add_section')}</DialogTitle>
                        <DialogDescription>{t('wizard.details.add_section_hint')}</DialogDescription>
                    </DialogHeader>
                    <div className="grid grid-cols-2 gap-2">
                        {extras.map((type) =>
                            NEEDS_UPLOAD.includes(type) ? (
                                <UploadButton
                                    key={type}
                                    url={props.urls.media}
                                    type="image"
                                    variant="outline"
                                    className="h-12 justify-start"
                                    label={typeName(type)}
                                    onUploaded={(file) => {
                                        addMedia(file);
                                        addSection(type, { type: 'gallery', photos: [{ src: file.ref }] });
                                    }}
                                />
                            ) : (
                                <Button key={type} variant="outline" className="h-12 justify-start" onClick={() => addSection(type)}>
                                    <Plus /> {typeName(type)}
                                </Button>
                            ),
                        )}
                    </div>
                </DialogContent>
            </Dialog>
        </div>,
    );
}

type Update = (change: (config: InvitationConfig) => InvitationConfig) => void;

/** media.cover (photo or video), effects.music + media.music. */
function CoverAndMusic({ config, update, ctx }: { config: InvitationConfig; update: Update; ctx: EditorContext }) {
    const { t } = useT();
    const cover = config.media?.cover;
    const music = config.media?.music;
    const setMedia = (changes: Partial<NonNullable<InvitationConfig['media']>>) =>
        update((c) => {
            const media = patch(c.media ?? {}, changes);
            return patch(c, { media: Object.keys(media).length ? media : undefined }) as InvitationConfig;
        });
    const onCover = (file: Uploaded) => {
        ctx.addMedia(file);
        setMedia({ cover: { type: file.kind === 'video' ? 'video' : 'image', src: file.ref } });
    };
    const coverUrl = resolveMedia(cover?.src, ctx.media);

    return (
        <>
            <Field label={t('wizard.details.cover')} hint={t('wizard.details.cover_hint')} error={ctx.errorAt('/media/cover/src') ?? ctx.errorAt('/media/cover')}>
                {cover && coverUrl && (
                    <div className="bg-muted overflow-hidden rounded-lg">
                        {cover.type === 'video' ? <video src={coverUrl} muted playsInline controls className="max-h-64 w-full" /> : <img src={coverUrl} alt="" className="max-h-64 w-full object-cover" />}
                    </div>
                )}
                <div className="flex flex-wrap gap-2">
                    <UploadButton url={ctx.uploadUrl} type="image" label={t('wizard.details.cover_photo')} onUploaded={onCover} />
                    <UploadButton url={ctx.uploadUrl} type="video" label={<>{t('wizard.details.cover_video')}</>} onUploaded={onCover} />
                    {cover && (
                        <Button type="button" variant="ghost" className="text-destructive h-11" onClick={() => setMedia({ cover: undefined })}>
                            <Trash2 /> {t('wizard.common.remove')}
                        </Button>
                    )}
                </div>
                <span className="text-muted-foreground flex items-center gap-1.5 text-xs">
                    {t('wizard.details.cover_video_tier')} <TierBadge tier="premium" />
                </span>
            </Field>

            <SwitchField
                label={t('wizard.details.music')}
                description={t('wizard.details.music_hint')}
                checked={config.effects?.music ?? false}
                onChange={(v) => update((c) => ({ ...c, effects: { ...(c.effects ?? {}), music: v } }))}
            />
            {config.effects?.music && (
                <div className="grid gap-4 rounded-lg border p-3">
                    {music && resolveMedia(music.src, ctx.media) && <audio src={resolveMedia(music.src, ctx.media)} controls className="w-full" />}
                    <UploadButton
                        url={ctx.uploadUrl}
                        type="audio"
                        label={t('wizard.details.music_upload')}
                        onUploaded={(file) => {
                            ctx.addMedia(file);
                            setMedia({ music: { src: file.ref, ...(music?.title ? { title: music.title } : {}) } });
                        }}
                    />
                    <TextField
                        label={t('wizard.details.music_link')}
                        hint={t('wizard.details.music_link_hint')}
                        dir="ltr"
                        type="url"
                        value={music?.src?.startsWith('media:') ? '' : music?.src}
                        onChange={(v) => setMedia({ music: v ? { ...(music ?? {}), src: v } : undefined })}
                        error={ctx.errorAt('/media/music/src')}
                    />
                    {music && <TextField label={t('wizard.details.music_title')} value={music.title} maxLength={80} onChange={(v) => setMedia({ music: patch(music, { title: v }) })} error={ctx.errorAt('/media/music/title')} />}
                    {!music && <p className="text-muted-foreground text-xs">{t('wizard.details.music_missing')}</p>}
                </div>
            )}
        </>
    );
}

/** Invitation language and ambient effects. */
function Settings({ config, update }: { config: InvitationConfig; update: Update }) {
    const { t } = useT();
    return (
        <>
            <Field label={t('wizard.details.invitation_language')} hint={t('wizard.details.invitation_language_hint')}>
                <div className="grid grid-cols-2 gap-2">
                    {(['ar', 'en'] as const).map((l) => (
                        <Button key={l} type="button" variant={config.locale === l ? 'default' : 'outline'} className="h-12" onClick={() => update((c) => ({ ...c, locale: l }))} aria-pressed={config.locale === l}>
                            {t(`wizard.languages.${l}`)}
                        </Button>
                    ))}
                </div>
            </Field>
            <SwitchField label={t('wizard.details.petals')} checked={config.effects?.fallingPetals ?? false} onChange={(v) => update((c) => ({ ...c, effects: { ...(c.effects ?? {}), fallingPetals: v } }))} />
            <SwitchField label={t('wizard.details.sparkles')} checked={config.effects?.sparkles ?? false} onChange={(v) => update((c) => ({ ...c, effects: { ...(c.effects ?? {}), sparkles: v } }))} />
        </>
    );
}
