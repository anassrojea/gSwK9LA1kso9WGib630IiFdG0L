import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { ArrowLeft, Check, Copy, Download, Eye, EyeOff, Gem, ImagePlus, Images, KeyRound, Loader2, MonitorPlay, MoreVertical, Play, QrCode, Star, Trash2, Video } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Toaster } from '@/components/ui/sonner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { SwitchField } from '@/components/wizard/fields';
import AppLayout from '@/layouts/AppLayout';
import { formatBytes } from '@/lib/album';
import { copyText } from '@/lib/clipboard';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

interface Item {
    id: string;
    kind: 'photo' | 'video';
    thumb: string | null;
    preview: string | null;
    original: string | null;
    width: number | null;
    height: number | null;
    duration: number | null;
    name: string | null;
    at: string | null;
    hidden: boolean;
    approved: boolean;
    household_id: string | null;
    bytes: number;
}

interface Settings {
    upload_before_event: boolean;
    moderate: boolean;
    has_pin: boolean;
    wall_private: boolean;
}

interface Props {
    [key: string]: unknown;
    event: { id: string; title: string; starts_at: string | null; timezone: string; storage_until: string | null };
    enabled: boolean;
    wall: boolean;
    can: { update: boolean };
    settings: Settings;
    open: boolean;
    usage: { used: number; max: number };
    items: Item[];
    households: Array<{ id: string; name: string }>;
    guestbook: Array<{ id: string; kind: 'text' | 'video' | 'audio'; text: string | null; name: string | null; video: string | null; at: string | null }>;
    export: { status: 'running' | 'ready' | 'failed'; at: string | null; url: string | null; count: number | null } | null;
    links: { upload: string; upload_qr: string; wall: string | null } | null;
    urls: { settings: string; export: string; dashboard: string; upgrade: string };
    /** phase 8D: the latest highlight video build (App\Services\Keepsakes\HighlightVideos::present) */
    highlight?: {
        status: 'queued' | 'rendering' | 'ready' | 'failed';
        outputs: Record<string, { url: string | null; poster: string | null; download: string | null; duration: number }>;
    } | null;
    keepsakesUrl?: string;
}

type Filter = 'all' | 'pending' | 'hidden';

/**
 * The host's album (phase 7B, plan section 11): guests' photos and videos,
 * moderation, cover / gallery, the guestbook, the wall and the ZIP download.
 */
export default function Album(props: Props) {
    const { t, tc, n, sep, locale } = useT();
    const [items, setItems] = useState(props.items);
    const [guestbook, setGuestbook] = useState(props.guestbook);
    const [family, setFamily] = useState('all');
    const [filter, setFilter] = useState<Filter>('all');
    const [exportState, setExportState] = useState(props.export);
    const [viewing, setViewing] = useState<Item | null>(null);

    const visible = useMemo(
        () => items.filter((i) => (family === 'all' || i.household_id === family) && (filter === 'all' || (filter === 'pending' ? i.hidden && !i.approved : i.hidden))),
        [items, family, filter],
    );
    const pending = items.filter((i) => i.hidden && !i.approved).length;
    const usedShare = props.usage.max ? Math.min(100, (props.usage.used / props.usage.max) * 100) : 0;
    const dateFmt = (iso: string) => new Intl.DateTimeFormat(locale === 'ar' ? 'ar-u-nu-arab' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short', timeZone: props.event.timezone }).format(new Date(iso));

    const errorOf = (err: unknown) => {
        const data = axios.isAxiosError(err) ? (err.response?.data as { message?: string; errors?: Record<string, string[]> }) : undefined;
        return (data?.errors && Object.values(data.errors)[0]?.[0]) || data?.message || t('wizard.save.error');
    };

    const setHidden = async (item: Item, hidden: boolean) => {
        try {
            const { data } = await axios.patch(`/media/${item.id}`, { hidden });
            setItems((list) => list.map((i) => (i.id === item.id ? { ...i, hidden: data.hidden, approved: data.approved } : i)));
        } catch (err) {
            toast.error(errorOf(err));
        }
    };

    const remove = async (item: Item) => {
        if (!window.confirm(t('album.host.delete_confirm'))) return;
        try {
            await axios.delete(`/media/${item.id}`);
            setItems((list) => list.filter((i) => i.id !== item.id));
            toast.success(t('album.host.deleted'));
        } catch (err) {
            toast.error(errorOf(err));
        }
    };

    const use = async (item: Item, as: 'cover' | 'gallery') => {
        try {
            const { data } = await axios.post(`/media/${item.id}/use`, { as });
            setItems((list) => list.map((i) => (i.id === item.id ? { ...i, hidden: false, approved: true } : i)));
            toast.success(data.message);
        } catch (err) {
            toast.error(errorOf(err));
        }
    };

    const startExport = async () => {
        try {
            const { data } = await axios.post(props.urls.export);
            setExportState(data.export);
            if (data.message) toast.success(data.message);
        } catch (err) {
            toast.error(errorOf(err));
        }
    };

    // the ZIP is made in the background: check back while it runs
    useEffect(() => {
        if (exportState?.status !== 'running') return;
        const timer = setInterval(() => {
            router.reload({
                only: ['export'],
                onSuccess: (page) => {
                    const next = (page.props as unknown as Props).export;
                    if (next && next.status !== 'running') setExportState(next);
                },
            });
        }, 5000);
        return () => clearInterval(timer);
    }, [exportState?.status]);

    const removeEntry = async (id: string) => {
        if (!window.confirm(t('album.host.delete_confirm'))) return;
        await axios.delete(`/guestbook/${id}`);
        setGuestbook((list) => list.filter((g) => g.id !== id));
    };

    return (
        <AppLayout>
            <Head title={`${t('album.host.title')} · ${props.event.title}`} />
            <Toaster />
            <div className="grid gap-5" data-album-page>
                <header className="grid gap-3">
                    <Link href={props.urls.dashboard} className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1 text-sm">
                        <ArrowLeft className="size-4 rtl:rotate-180" /> {t('album.host.back')}
                    </Link>
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div className="grid gap-1">
                            <h1 className="flex items-center gap-2 text-2xl font-bold sm:text-3xl">
                                <Images className="text-primary size-7" /> {t('album.host.title')}
                            </h1>
                            <p className="text-muted-foreground text-sm">
                                <bdi>{props.event.title}</bdi>
                                {sep}
                                {tc('album.host.items', items.length)}
                                {props.event.storage_until && <>{sep}{t('album.host.storage_until', { date: new Intl.DateTimeFormat(locale === 'ar' ? 'ar-u-nu-arab' : 'en-GB', { dateStyle: 'medium' }).format(new Date(props.event.storage_until)) })}</>}
                            </p>
                        </div>
                        {props.enabled && (
                            <div className="flex flex-wrap items-center gap-2">
                                {exportState?.status === 'ready' && exportState.url ? (
                                    <Button asChild className="h-11" data-export-ready>
                                        <a href={exportState.url}>
                                            <Download /> {t('album.host.export_download')}
                                        </a>
                                    </Button>
                                ) : null}
                                <Button variant={exportState?.status === 'ready' ? 'outline' : 'default'} className="h-11" onClick={startExport} disabled={exportState?.status === 'running' || items.length === 0} data-export>
                                    {exportState?.status === 'running' ? <Loader2 className="animate-spin" /> : <Download />}
                                    {exportState?.status === 'running' ? t('album.host.export_running') : t('album.host.download_all')}
                                </Button>
                            </div>
                        )}
                    </div>
                    {exportState?.status === 'failed' && <p className="text-destructive text-sm">{t('album.host.export_failed')}</p>}
                </header>

                {!props.enabled ? (
                    <section className="grid justify-items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-950" data-album-locked>
                        <p>{t('album.host.not_enabled')}</p>
                        <Button asChild className="h-11">
                            <Link href={props.urls.upgrade}>
                                <Gem /> {t('album.host.not_enabled_cta')}
                            </Link>
                        </Button>
                    </section>
                ) : (
                    <>
                        <div className="bg-card grid gap-2 rounded-xl border p-4 sm:grid-cols-[1fr_auto] sm:items-center">
                            <div className="grid gap-1.5">
                                <div className="flex justify-between text-sm">
                                    <span className="font-medium">{t('album.host.usage', { used: formatBytes(props.usage.used, locale), max: formatBytes(props.usage.max, locale) })}</span>
                                </div>
                                <div className="bg-muted h-2 overflow-hidden rounded-full">
                                    <div className={cn('h-full rounded-full', usedShare > 90 ? 'bg-amber-500' : 'bg-primary')} style={{ width: `${usedShare}%` }} />
                                </div>
                            </div>
                            <span className={cn('inline-flex h-8 items-center gap-1.5 rounded-full px-3 text-xs font-medium', props.open ? 'bg-emerald-100 text-emerald-900' : 'bg-muted text-muted-foreground')}>
                                <span className={cn('size-2 rounded-full', props.open ? 'bg-emerald-500' : 'bg-zinc-400')} />
                                {props.open ? t('album.host.open_now') : t('album.host.closed_now')}
                            </span>
                        </div>

                        {props.highlight && (
                            <section className="bg-card grid gap-3 rounded-xl border p-4" data-album-highlight={props.highlight.status}>
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <h2 className="flex items-center gap-2 font-semibold">
                                        <Video className="text-primary size-5" /> {t('keepsakes.video.album_title')}
                                    </h2>
                                    <span className="text-muted-foreground text-xs">{t(`keepsakes.video.status.${props.highlight.status}`)}</span>
                                </div>
                                {props.highlight.status === 'ready' && (
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        {Object.entries(props.highlight.outputs).map(([format, o]) =>
                                            o.url ? (
                                                <video
                                                    key={format}
                                                    src={o.url}
                                                    poster={o.poster ?? undefined}
                                                    controls
                                                    playsInline
                                                    preload="metadata"
                                                    className={cn('w-full rounded-lg bg-black', format === 'vertical' && 'mx-auto max-h-80 w-auto')}
                                                />
                                            ) : null,
                                        )}
                                    </div>
                                )}
                                {props.keepsakesUrl && (
                                    <Link href={props.keepsakesUrl} className="text-primary w-fit text-sm font-semibold">
                                        {t('keepsakes.video.album_open')}
                                    </Link>
                                )}
                            </section>
                        )}

                        <Tabs defaultValue="photos">
                            <TabsList>
                                <TabsTrigger value="photos">
                                    {t('album.host.tabs.photos')}
                                    {pending > 0 && <span className="ms-1.5 rounded-full bg-amber-500 px-1.5 text-[0.7rem] text-white">{n(pending)}</span>}
                                </TabsTrigger>
                                <TabsTrigger value="guestbook">{t('album.host.tabs.guestbook')}</TabsTrigger>
                                <TabsTrigger value="share">{t('album.host.tabs.share')}</TabsTrigger>
                            </TabsList>

                            <TabsContent value="photos" className="grid gap-4 pt-2">
                                <div className="flex flex-wrap items-center gap-2">
                                    <NativeSelect className="w-auto min-w-48" value={family} onChange={(e) => setFamily(e.target.value)} aria-label={t('album.host.filter_label')}>
                                        <option value="all">{t('album.host.filter_all')}</option>
                                        {props.households.map((h) => (
                                            <option key={h.id} value={h.id}>
                                                {h.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                    <div className="flex gap-1" role="group">
                                        {(['all', 'pending', 'hidden'] as const).map((f) => (
                                            <Button key={f} size="sm" variant={filter === f ? 'default' : 'outline'} className="h-9" onClick={() => setFilter(f)} aria-pressed={filter === f}>
                                                {f === 'all' ? t('album.host.filter_all') : f === 'pending' ? t('album.host.pending') : t('album.host.hidden')}
                                            </Button>
                                        ))}
                                    </div>
                                </div>

                                {visible.length === 0 ? (
                                    <p className="text-muted-foreground rounded-xl border border-dashed p-10 text-center text-sm">{t('album.host.empty')}</p>
                                ) : (
                                    <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5" data-album-grid>
                                        {visible.map((item) => (
                                            <li key={item.id} className={cn('bg-card group overflow-hidden rounded-xl border', item.hidden && 'opacity-75')} data-album-item={item.hidden ? (item.approved ? 'hidden' : 'pending') : 'shown'}>
                                                <button type="button" className="bg-muted relative block aspect-square w-full overflow-hidden" onClick={() => setViewing(item)}>
                                                    {item.thumb ? (
                                                        <img src={item.thumb} alt="" loading="lazy" decoding="async" className="size-full object-cover transition group-hover:scale-105" />
                                                    ) : (
                                                        <span className="text-muted-foreground grid size-full place-items-center">
                                                            <Video className="size-8" />
                                                        </span>
                                                    )}
                                                    {item.kind === 'video' && (
                                                        <span className="absolute start-2 bottom-2 inline-flex items-center gap-1 rounded-full bg-black/60 px-2 py-0.5 text-xs text-white">
                                                            <Play className="size-3" /> {item.duration ? `${item.duration}s` : ''}
                                                        </span>
                                                    )}
                                                    {item.hidden && (
                                                        <span className={cn('absolute end-2 top-2 rounded-full px-2 py-0.5 text-xs font-medium', item.approved ? 'bg-zinc-800 text-white' : 'bg-amber-400 text-amber-950')}>
                                                            {item.approved ? t('album.host.hidden') : t('album.host.pending')}
                                                        </span>
                                                    )}
                                                </button>
                                                <div className="flex items-center gap-1 p-2">
                                                    <span className="text-muted-foreground min-w-0 flex-1 truncate text-xs" title={item.name ?? ''}>
                                                        <bdi>{item.name ?? t('album.host.by_guest')}</bdi>
                                                        {item.at && <span className="block">{dateFmt(item.at)}</span>}
                                                    </span>
                                                    {props.can.update && (
                                                        <>
                                                            {item.hidden ? (
                                                                <Button
                                                                    size="icon"
                                                                    variant={item.approved ? 'ghost' : 'default'}
                                                                    className={cn('size-8', !item.approved && 'bg-emerald-600 hover:bg-emerald-700')}
                                                                    onClick={() => setHidden(item, false)}
                                                                    aria-label={item.approved ? t('album.host.show') : t('album.host.approve')}
                                                                    title={item.approved ? t('album.host.show') : t('album.host.approve')}
                                                                    data-action="show"
                                                                >
                                                                    {item.approved ? <Eye /> : <Check />}
                                                                </Button>
                                                            ) : (
                                                                <Button size="sm" variant="ghost" className="h-8 px-2" onClick={() => setHidden(item, true)} aria-label={t('album.host.hide')} data-action="hide">
                                                                    <EyeOff />
                                                                </Button>
                                                            )}
                                                            <DropdownMenu>
                                                                <DropdownMenuTrigger asChild>
                                                                    <Button size="icon" variant="ghost" className="size-8" aria-label="…">
                                                                        <MoreVertical />
                                                                    </Button>
                                                                </DropdownMenuTrigger>
                                                                <DropdownMenuContent align="end">
                                                                    {item.kind === 'photo' && (
                                                                        <>
                                                                            <DropdownMenuItem onSelect={() => use(item, 'cover')}>
                                                                                <Star /> {t('album.host.set_cover')}
                                                                            </DropdownMenuItem>
                                                                            <DropdownMenuItem onSelect={() => use(item, 'gallery')}>
                                                                                <ImagePlus /> {t('album.host.add_gallery')}
                                                                            </DropdownMenuItem>
                                                                        </>
                                                                    )}
                                                                    {item.original && (
                                                                        <DropdownMenuItem asChild>
                                                                            <a href={item.original} download>
                                                                                <Download /> {t('album.guest.download')}
                                                                            </a>
                                                                        </DropdownMenuItem>
                                                                    )}
                                                                    <DropdownMenuSeparator />
                                                                    <DropdownMenuItem className="text-destructive" onSelect={() => remove(item)}>
                                                                        <Trash2 /> {t('album.host.delete')}
                                                                    </DropdownMenuItem>
                                                                </DropdownMenuContent>
                                                            </DropdownMenu>
                                                        </>
                                                    )}
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </TabsContent>

                            <TabsContent value="guestbook" className="grid gap-3 pt-2" data-guestbook>
                                {guestbook.length === 0 && <p className="text-muted-foreground rounded-xl border border-dashed p-8 text-center text-sm">{t('album.host.guestbook_empty')}</p>}
                                <ul className="grid gap-3 sm:grid-cols-2">
                                    {guestbook.map((entry) => (
                                        <li key={entry.id} className="bg-card grid gap-2 rounded-xl border p-4">
                                            {entry.kind === 'video' && entry.video ? <video src={entry.video} controls playsInline preload="metadata" className="w-full rounded-lg bg-black" /> : null}
                                            {entry.text && (
                                                <p className="leading-relaxed whitespace-pre-line" dir="auto">
                                                    {entry.text}
                                                </p>
                                            )}
                                            <div className="text-muted-foreground flex items-center justify-between gap-2 text-xs">
                                                <span>
                                                    <bdi>{entry.name ?? (entry.kind === 'video' ? t('album.host.video_message') : t('album.host.by_guest'))}</bdi>
                                                    {entry.at && <>{sep}{dateFmt(entry.at)}</>}
                                                </span>
                                                {props.can.update && (
                                                    <Button size="icon" variant="ghost" className="text-destructive size-8" onClick={() => removeEntry(entry.id)} aria-label={t('album.host.delete')}>
                                                        <Trash2 className="size-4" />
                                                    </Button>
                                                )}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            </TabsContent>

                            <TabsContent value="share" className="grid gap-4 pt-2 lg:grid-cols-2">
                                <ShareLinks links={props.links} wall={props.wall} />
                                {props.can.update && <SettingsPanel initial={props.settings} url={props.urls.settings} wall={props.wall} />}
                            </TabsContent>
                        </Tabs>
                    </>
                )}
            </div>

            {viewing && (
                <div className="fixed inset-0 z-50 grid place-items-center bg-black/90 p-4" role="dialog" aria-modal="true" onClick={() => setViewing(null)}>
                    {viewing.kind === 'video' ? (
                        <video src={viewing.original ?? undefined} controls autoPlay playsInline className="max-h-[90svh] max-w-full" onClick={(e) => e.stopPropagation()} />
                    ) : (
                        <img src={viewing.preview ?? viewing.original ?? undefined} alt="" className="max-h-[90svh] max-w-full object-contain" />
                    )}
                </div>
            )}
        </AppLayout>
    );
}

function CopyRow({ label, hint, value, icon }: { label: string; hint: string; value: string; icon: React.ReactNode }) {
    const { t } = useT();
    const [copied, setCopied] = useState(false);
    return (
        <div className="grid gap-1.5">
            <span className="flex items-center gap-2 text-sm font-semibold">
                {icon} {label}
            </span>
            <div className="flex gap-2">
                <Input readOnly dir="ltr" value={value} className="h-10 font-mono text-xs" onFocus={(e) => e.target.select()} />
                <Button
                    variant="outline"
                    className="h-10"
                    onClick={async () => {
                        setCopied(await copyText(value));
                        setTimeout(() => setCopied(false), 1800);
                    }}
                >
                    {copied ? <Check /> : <Copy />} {copied ? t('album.host.copied') : t('album.host.copy')}
                </Button>
            </div>
            <span className="text-muted-foreground text-xs">{hint}</span>
        </div>
    );
}

function ShareLinks({ links, wall }: { links: Props['links']; wall: boolean }) {
    const { t } = useT();
    if (!links) return null;
    return (
        <section className="bg-card grid gap-5 rounded-xl border p-4" data-album-links>
            <h2 className="font-bold">{t('album.host.links')}</h2>
            <div className="grid gap-4 sm:grid-cols-[1fr_9rem] sm:items-start">
                <CopyRow label={t('album.host.upload_link')} hint={t('album.host.upload_link_hint')} value={links.upload} icon={<QrCode className="size-4" />} />
                <div className="rounded-lg border bg-white p-2" dangerouslySetInnerHTML={{ __html: links.upload_qr }} aria-label={t('album.host.upload_link')} />
            </div>
            {wall && links.wall && (
                <div className="grid gap-2">
                    <CopyRow label={t('album.host.wall_link')} hint={t('album.host.wall_hint')} value={links.wall} icon={<MonitorPlay className="size-4" />} />
                    <Button asChild variant="secondary" className="h-10 w-fit">
                        <a href={links.wall} target="_blank" rel="noopener" data-wall-link>
                            <MonitorPlay /> {t('album.host.wall_open')}
                        </a>
                    </Button>
                </div>
            )}
        </section>
    );
}

function SettingsPanel({ initial, url, wall }: { initial: Settings; url: string; wall: boolean }) {
    const { t } = useT();
    const [settings, setSettings] = useState(initial);
    const [pin, setPin] = useState('');
    const [pinError, setPinError] = useState<string | null>(null);

    const save = async (changes: Record<string, unknown>) => {
        try {
            const { data } = await axios.put(url, changes);
            setSettings(data.settings);
            setPinError(null);
            toast.success(t('album.host.saved'));
            return true;
        } catch (err) {
            const message = axios.isAxiosError(err) ? (err.response?.data?.errors?.pin?.[0] ?? err.response?.data?.message) : null;
            setPinError(message || t('wizard.save.error'));
            return false;
        }
    };

    return (
        <section className="bg-card grid gap-4 rounded-xl border p-4" data-album-settings>
            <h2 className="font-bold">{t('album.host.settings')}</h2>
            <SwitchField label={t('album.host.upload_before')} description={t('album.host.upload_before_hint')} checked={settings.upload_before_event} onChange={(v) => save({ upload_before_event: v })} />
            <SwitchField label={t('album.host.moderate')} description={t('album.host.moderate_hint')} checked={settings.moderate} onChange={(v) => save({ moderate: v })} />
            {wall && <SwitchField label={t('album.host.wall_private')} description={t('album.host.wall_private_hint')} checked={settings.wall_private} onChange={(v) => save({ wall_private: v }).then(() => window.location.reload())} />}
            <div className="grid gap-2 rounded-lg border p-3">
                <span className="flex items-center gap-2 text-sm font-semibold">
                    <KeyRound className="size-4" /> {t('album.host.pin')}
                    {settings.has_pin && <span className="rounded-full bg-emerald-100 px-2 text-xs text-emerald-900">{t('album.host.pin_on')}</span>}
                </span>
                <p className="text-muted-foreground text-xs">{t('album.host.pin_hint')}</p>
                <div className="flex flex-wrap gap-2">
                    <Input className="h-10 w-36" dir="ltr" inputMode="numeric" maxLength={8} value={pin} onChange={(e) => setPin(e.target.value)} aria-label={t('album.host.pin')} />
                    <Button variant="outline" className="h-10" disabled={pin.trim().length < 4} onClick={async () => (await save({ pin: pin.trim() })) && setPin('')}>
                        {t('album.host.pin_set')}
                    </Button>
                    {settings.has_pin && (
                        <Button variant="ghost" className="text-destructive h-10" onClick={() => save({ pin: null })}>
                            {t('album.host.pin_remove')}
                        </Button>
                    )}
                </div>
                {pinError && <p className="text-destructive text-sm">{pinError}</p>}
            </div>
        </section>
    );
}
