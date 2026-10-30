import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, BookImage, Check, Clapperboard, Download, ExternalLink, FileText, HeartHandshake, Loader2, Lock, Truck } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { toast } from 'sonner';

import { CheckoutPanel } from '@/components/checkout/CheckoutPanel';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Toaster } from '@/components/ui/sonner';
import { SwitchField, TextField } from '@/components/wizard/fields';
import AppLayout from '@/layouts/AppLayout';
import { useT } from '@/lib/i18n';
import { chargeCurrency, formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';
import type { CheckoutOptions } from '@/types/payments';

interface Photo {
    id: string;
    thumb: string | null;
    at: string | null;
}

interface VideoOutput {
    width: number;
    height: number;
    duration: number;
    bytes: number;
    url: string | null;
    poster: string | null;
    download: string | null;
}

interface Props {
    [key: string]: unknown;
    event: { id: string; title: string; starts_at: string | null; storage_until: string | null; published: boolean };
    can: { update: boolean };
    photos: Photo[];
    thankYou: {
        unlocked: boolean;
        exists: boolean;
        published: boolean;
        show_on_invite: boolean;
        title: string | null;
        message: string;
        signature: string | null;
        photos: string[];
        max_photos: number;
        show_after_hours: number;
        preview_url: string | null;
        checkout: CheckoutOptions | null;
    };
    video: {
        unlocked: boolean;
        available: boolean;
        latest: {
            id: string;
            status: 'queued' | 'rendering' | 'ready' | 'failed';
            formats: string[];
            error: string | null;
            photo_count: number;
            clip_count: number;
            outputs: Record<string, VideoOutput>;
        } | null;
        tracks: { id: string; title: string; artist: string | null }[];
        photo_count: number;
        clip_count: number;
        checkout: CheckoutOptions | null;
    };
    book: {
        id: string;
        status: string;
        title: string;
        subtitle: string | null;
        size: 'a4_landscape' | 'square_21';
        pages: 20 | 40;
        auto_pick: boolean;
        photo_ids: string[];
        chosen: string[];
        capacity: number;
        capacities: Record<string, number>;
        copies: number;
        ship: { name: string | null; country: string | null; city: string | null; address: string | null; phone: string | null };
        pdf_url: string | null;
        pdf_pages: number | null;
        can_render: boolean;
        checkout: CheckoutOptions | null;
    } | null;
    ordered: { id: string; status: string; pages: number; copies: number; tracking_notes: string | null; order: string | null } | null;
    urls: Record<'thank_you' | 'thank_you_publish' | 'video' | 'book' | 'book_pdf' | 'book_order' | 'album' | 'dashboard', string>;
    errors?: Record<string, string>;
}

function Card({ icon, title, intro, unlocked, children, name }: { icon: ReactNode; title: string; intro: string; unlocked: boolean; children: ReactNode; name: string }) {
    const { t } = useT();
    return (
        <section className="bg-card grid gap-5 rounded-xl border p-4 sm:p-6" data-keepsake={name}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex min-w-0 items-start gap-3">
                    <span className="bg-primary/10 text-primary grid size-11 shrink-0 place-items-center rounded-full">{icon}</span>
                    <div className="grid gap-1">
                        <h2 className="text-lg font-bold">{title}</h2>
                        <p className="text-muted-foreground text-sm leading-relaxed">{intro}</p>
                    </div>
                </div>
                <span className={cn('inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold', unlocked ? 'bg-emerald-100 text-emerald-800' : 'bg-muted text-muted-foreground')}>
                    {unlocked ? <Check className="size-3.5" /> : <Lock className="size-3.5" />} {t(unlocked ? 'keepsakes.unlocked' : 'keepsakes.locked')}
                </span>
            </div>
            {children}
        </section>
    );
}

/** Pick album photos (a grid of thumbnails), up to max. */
function PhotoPicker({ photos, selected, max, onChange }: { photos: Photo[]; selected: string[]; max: number; onChange: (ids: string[]) => void }) {
    const { t, n } = useT();
    const toggle = (id: string) =>
        onChange(selected.includes(id) ? selected.filter((s) => s !== id) : selected.length < max ? [...selected, id] : selected);

    if (photos.length === 0) return <p className="text-muted-foreground rounded-lg border border-dashed p-4 text-sm">{t('keepsakes.thank_you.no_photos')}</p>;

    return (
        <div className="grid gap-2">
            <span className="text-muted-foreground text-xs">{t('keepsakes.thank_you.picked', { count: n(selected.length), max: n(max) })}</span>
            <ul className="grid grid-cols-4 gap-1.5 sm:grid-cols-6 lg:grid-cols-8">
                {photos.map((p) => {
                    const at = selected.indexOf(p.id);
                    return (
                        <li key={p.id}>
                            <button
                                type="button"
                                onClick={() => toggle(p.id)}
                                aria-pressed={at >= 0}
                                className={cn('relative block aspect-square w-full overflow-hidden rounded-md border-2', at >= 0 ? 'border-primary' : 'border-transparent opacity-80 hover:opacity-100')}
                                data-photo={p.id}
                            >
                                {p.thumb && <img src={p.thumb} alt="" loading="lazy" className="size-full object-cover" />}
                                {at >= 0 && <span className="bg-primary text-primary-foreground absolute end-1 top-1 grid size-5 place-items-center rounded-full text-[11px] font-bold">{n(at + 1)}</span>}
                            </button>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

function ThankYouCard({ props }: { props: Props }) {
    const { t, n } = useT();
    const ty = props.thankYou;
    const [title, setTitle] = useState(ty.title ?? '');
    const [message, setMessage] = useState(ty.message);
    const [signature, setSignature] = useState(ty.signature ?? '');
    const [photos, setPhotos] = useState(ty.photos);
    const [showOnInvite, setShowOnInvite] = useState(ty.show_on_invite);
    const [busy, setBusy] = useState(false);

    const save = () => {
        setBusy(true);
        router.put(props.urls.thank_you, { title, message, signature, photos, show_on_invite: showOnInvite }, { preserveScroll: true, onFinish: () => setBusy(false) });
    };
    const publish = (on: boolean) => router.post(props.urls.thank_you_publish, { publish: on }, { preserveScroll: true });

    return (
        <Card name="thank-you" icon={<HeartHandshake className="size-5" />} title={t('keepsakes.thank_you.section')} intro={t('keepsakes.thank_you.intro')} unlocked={ty.unlocked}>
            <div className="grid gap-4 lg:grid-cols-2">
                <div className="grid content-start gap-4">
                    <TextField label={t('keepsakes.thank_you.card_title')} value={title} maxLength={80} onChange={setTitle} placeholder={t('keepsakes.thank_you.title')} />
                    <TextField label={t('keepsakes.thank_you.message')} value={message} maxLength={2000} multiline onChange={setMessage} error={props.errors?.message} />
                    <TextField label={t('keepsakes.thank_you.signature')} value={signature} maxLength={120} onChange={setSignature} />
                    <SwitchField
                        label={t('keepsakes.thank_you.show_on_invite')}
                        description={t('keepsakes.thank_you.show_on_invite_hint', { hours: n(ty.show_after_hours) })}
                        checked={showOnInvite}
                        onChange={setShowOnInvite}
                    />
                </div>
                <div className="grid content-start gap-2">
                    <Label>{t('keepsakes.thank_you.photos')}</Label>
                    <p className="text-muted-foreground text-xs">{t('keepsakes.thank_you.pick', { max: n(ty.max_photos) })}</p>
                    <PhotoPicker photos={props.photos} selected={photos} max={ty.max_photos} onChange={setPhotos} />
                </div>
            </div>
            {props.errors?.thank_you && <p className="text-destructive text-sm">{props.errors.thank_you}</p>}
            {props.can.update && (
                <div className="flex flex-wrap items-center gap-2">
                    <Button variant="outline" className="h-11" onClick={save} disabled={busy} data-thank-you-save>
                        {t('keepsakes.thank_you.save')}
                    </Button>
                    {ty.published ? (
                        <Button variant="ghost" className="h-11" onClick={() => publish(false)}>
                            {t('keepsakes.thank_you.unpublish')}
                        </Button>
                    ) : (
                        <Button className="h-11" onClick={() => publish(true)} disabled={!ty.unlocked || !ty.exists} data-thank-you-publish>
                            {t('keepsakes.thank_you.publish')}
                        </Button>
                    )}
                    <span className={cn('rounded-full px-2.5 py-1 text-xs font-semibold', ty.published ? 'bg-emerald-100 text-emerald-800' : 'bg-muted text-muted-foreground')}>
                        {t(ty.published ? 'keepsakes.thank_you.live' : 'keepsakes.thank_you.draft')}
                    </span>
                    {ty.preview_url && (
                        <Button asChild variant="link" className="h-11">
                            <a href={ty.preview_url} target="_blank" rel="noopener">
                                <ExternalLink /> {t('keepsakes.thank_you.preview')}
                            </a>
                        </Button>
                    )}
                </div>
            )}
            {ty.checkout && <CheckoutPanel options={ty.checkout} from="dashboard" errors={props.errors} />}
        </Card>
    );
}

function VideoCard({ props }: { props: Props }) {
    const { t, n, sep } = useT();
    const v = props.video;
    const [formats, setFormats] = useState<string[]>(['vertical', 'landscape']);
    const [track, setTrack] = useState('');
    const latest = v.latest;
    const busy = latest?.status === 'queued' || latest?.status === 'rendering';

    useEffect(() => {
        if (!busy) return;
        const timer = window.setInterval(() => router.reload({ only: ['video'] }), 5000);
        return () => window.clearInterval(timer);
    }, [busy]);

    const build = () => router.post(props.urls.video, { formats, music_track_id: track || null }, { preserveScroll: true });
    const toggle = (f: string) => setFormats((cur) => (cur.includes(f) ? cur.filter((x) => x !== f) : [...cur, f]));

    return (
        <Card name="video" icon={<Clapperboard className="size-5" />} title={t('keepsakes.video.section')} intro={t('keepsakes.video.intro')} unlocked={v.unlocked}>
            <p className="text-muted-foreground text-sm">{t('keepsakes.video.uses', { photos: n(v.photo_count), clips: n(v.clip_count) })}</p>
            {v.unlocked ? (
                <div className="grid gap-4">
                    <div className="grid gap-2 sm:grid-cols-2">
                        {(['vertical', 'landscape'] as const).map((f) => (
                            <label key={f} className={cn('flex cursor-pointer items-center gap-3 rounded-xl border p-3 text-sm', formats.includes(f) && 'border-primary bg-primary/5')}>
                                <input type="checkbox" className="accent-primary size-5" checked={formats.includes(f)} onChange={() => toggle(f)} />
                                {t(`keepsakes.video.formats.${f}`)}
                            </label>
                        ))}
                    </div>
                    <div className="grid gap-1.5 sm:max-w-sm">
                        <Label htmlFor="video-music">{t('keepsakes.video.music')}</Label>
                        <NativeSelect id="video-music" value={track} onChange={(e) => setTrack(e.target.value)}>
                            <option value="">{t('keepsakes.video.no_music')}</option>
                            {v.tracks.map((tr) => (
                                <option key={tr.id} value={tr.id}>
                                    {tr.title}
                                    {tr.artist ? `${sep}${tr.artist}` : ''}
                                </option>
                            ))}
                        </NativeSelect>
                        <span className="text-muted-foreground text-xs">{t('keepsakes.video.music_hint')}</span>
                    </div>
                    {props.errors?.video && <p className="text-destructive text-sm">{props.errors.video}</p>}
                    {props.can.update && (
                        <Button className="h-11 w-full sm:w-fit" onClick={build} disabled={busy || formats.length === 0 || !v.available || v.photo_count === 0} data-video-build>
                            {busy ? <Loader2 className="animate-spin" /> : <Clapperboard />} {latest?.status === 'ready' ? t('keepsakes.video.rebuild') : t('keepsakes.video.build')}
                        </Button>
                    )}
                    {!v.available && <p className="text-muted-foreground text-sm">{t('keepsakes.video.errors.unavailable')}</p>}
                    {latest && (
                        <div className="grid gap-3 rounded-xl border p-3" data-video-status={latest.status}>
                            <span className="text-sm font-semibold">{t(`keepsakes.video.status.${latest.status}`)}</span>
                            {latest.error && <p className="text-destructive text-sm">{latest.error}</p>}
                            <div className="grid gap-3 sm:grid-cols-2">
                                {Object.entries(latest.outputs).map(([format, o]) => (
                                    <figure key={format} className="grid gap-2">
                                        {o.url && <video src={o.url} poster={o.poster ?? undefined} controls playsInline preload="metadata" className={cn('w-full rounded-lg bg-black', format === 'vertical' ? 'mx-auto max-h-96 w-auto' : '')} />}
                                        <figcaption className="flex flex-wrap items-center justify-between gap-2 text-xs">
                                            <span>
                                                {t(`keepsakes.video.formats.${format}`)}
                                                {sep}
                                                {t('keepsakes.video.duration', { seconds: n(Math.round(o.duration)) })}
                                            </span>
                                            {o.download && (
                                                <a href={o.download} className="text-primary inline-flex items-center gap-1 font-semibold">
                                                    <Download className="size-3.5" /> {t('keepsakes.video.download')}
                                                </a>
                                            )}
                                        </figcaption>
                                    </figure>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            ) : (
                v.checkout && <CheckoutPanel options={v.checkout} from="dashboard" errors={props.errors} />
            )}
        </Card>
    );
}

function BookCard({ props }: { props: Props }) {
    const { t, n, locale, sep } = useT();
    const book = props.book!;
    const [form, setForm] = useState({
        title: book.title,
        subtitle: book.subtitle ?? '',
        size: book.size,
        pages: book.pages,
        auto_pick: book.auto_pick,
        photo_ids: book.photo_ids.length ? book.photo_ids : book.chosen,
        copies: book.copies,
        ship_name: book.ship.name ?? '',
        ship_country: book.ship.country ?? '',
        ship_city: book.ship.city ?? '',
        ship_address: book.ship.address ?? '',
        ship_phone: book.ship.phone ?? '',
    });
    const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) => setForm((f) => ({ ...f, [key]: value }));
    const options = book.checkout;
    const [currency, setCurrency] = useState(options?.currency ?? 'USD');
    const [gateway, setGateway] = useState(options?.gateways[0]?.id ?? '');
    const capacity = book.capacities[String(form.pages)] ?? book.capacity;
    const addon = options?.addons[0];
    const method = options?.gateways.find((g) => g.id === gateway);
    const charged = method && options ? chargeCurrency(method.currencies, currency, options.fallback_currency) : null;
    const unit = addon && charged ? addon.prices[charged] : null;

    const save = () => router.put(props.urls.book, form, { preserveScroll: true });
    const order = () => router.post(props.urls.book_order, { currency, gateway }, { preserveScroll: true });

    return (
        <Card name="photo-book" icon={<BookImage className="size-5" />} title={t('keepsakes.photo_book.section')} intro={t('keepsakes.photo_book.intro')} unlocked={!!props.ordered}>
            {props.ordered && (
                <div className="grid gap-1 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-950" data-book-ordered>
                    <span className="flex items-center gap-2 font-semibold">
                        <Truck className="size-4" /> {t('keepsakes.photo_book.ordered')}: {t(`keepsakes.photo_book.status.${props.ordered.status}`)}
                    </span>
                    <span className="text-xs">
                        {t('keepsakes.photo_book.pages_n', { count: n(props.ordered.pages) })}
                        {sep}
                        {t('keepsakes.photo_book.copies')} {n(props.ordered.copies)}
                        {props.ordered.order && (
                            <>
                                {sep}
                                <bdi dir="ltr">{props.ordered.order}</bdi>
                            </>
                        )}
                    </span>
                    {props.ordered.tracking_notes && <pre className="font-sans text-xs whitespace-pre-wrap">{props.ordered.tracking_notes}</pre>}
                </div>
            )}
            <div className="grid gap-4 sm:grid-cols-2">
                <TextField label={t('keepsakes.photo_book.book_title')} value={form.title} maxLength={80} onChange={(v) => set('title', v)} error={props.errors?.title} />
                <TextField label={t('keepsakes.photo_book.subtitle')} value={form.subtitle} maxLength={120} onChange={(v) => set('subtitle', v)} />
                <div className="grid gap-1.5">
                    <Label htmlFor="book-size">{t('keepsakes.photo_book.size')}</Label>
                    <NativeSelect id="book-size" value={form.size} onChange={(e) => set('size', e.target.value as typeof form.size)}>
                        {(['a4_landscape', 'square_21'] as const).map((s) => (
                            <option key={s} value={s}>
                                {t(`keepsakes.photo_book.sizes.${s}`)}
                            </option>
                        ))}
                    </NativeSelect>
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="book-pages">{t('keepsakes.photo_book.pages')}</Label>
                    <NativeSelect id="book-pages" value={form.pages} onChange={(e) => set('pages', Number(e.target.value) as 20 | 40)}>
                        {[20, 40].map((p) => (
                            <option key={p} value={p}>
                                {t('keepsakes.photo_book.pages_n', { count: n(p) })}
                            </option>
                        ))}
                    </NativeSelect>
                </div>
            </div>
            <SwitchField label={t('keepsakes.photo_book.auto_pick')} checked={form.auto_pick} onChange={(v) => set('auto_pick', v)} />
            {!form.auto_pick && (
                <div className="grid gap-2">
                    <p className="text-muted-foreground text-xs">{t('keepsakes.photo_book.pick', { max: n(capacity) })}</p>
                    <PhotoPicker photos={props.photos} selected={form.photo_ids} max={capacity} onChange={(ids) => set('photo_ids', ids)} />
                </div>
            )}
            <fieldset className="grid gap-3">
                <legend className="mb-2 text-sm font-semibold">{t('keepsakes.photo_book.ship.title')}</legend>
                <div className="grid gap-3 sm:grid-cols-2">
                    <TextField label={t('keepsakes.photo_book.ship.name')} value={form.ship_name} maxLength={120} onChange={(v) => set('ship_name', v)} error={props.errors?.ship_name} />
                    <TextField label={t('keepsakes.photo_book.ship.phone')} value={form.ship_phone} maxLength={32} type="tel" dir="ltr" onChange={(v) => set('ship_phone', v)} error={props.errors?.ship_phone} />
                    <TextField label={t('keepsakes.photo_book.ship.country')} value={form.ship_country} maxLength={2} dir="ltr" onChange={(v) => set('ship_country', v.toUpperCase())} error={props.errors?.ship_country} />
                    <TextField label={t('keepsakes.photo_book.ship.city')} value={form.ship_city} maxLength={80} onChange={(v) => set('ship_city', v)} error={props.errors?.ship_city} />
                    <TextField className="sm:col-span-2" label={t('keepsakes.photo_book.ship.address')} value={form.ship_address} maxLength={255} onChange={(v) => set('ship_address', v)} error={props.errors?.ship_address} />
                </div>
            </fieldset>
            <div className="grid max-w-40 gap-1.5">
                <Label htmlFor="book-copies">{t('keepsakes.photo_book.copies')}</Label>
                <NativeSelect id="book-copies" value={form.copies} onChange={(e) => set('copies', Number(e.target.value))}>
                    {Array.from({ length: 10 }, (_, i) => i + 1).map((c) => (
                        <option key={c} value={c}>
                            {n(c)}
                        </option>
                    ))}
                </NativeSelect>
            </div>
            {(props.errors?.book || props.errors?.photo_ids) && <p className="text-destructive text-sm">{props.errors.book ?? props.errors.photo_ids}</p>}
            {props.can.update && book.status === 'draft' && (
                <div className="flex flex-wrap gap-2">
                    <Button variant="outline" className="h-11" onClick={save} data-book-save>
                        {t('keepsakes.photo_book.save')}
                    </Button>
                    <Button variant="outline" className="h-11" onClick={() => router.post(props.urls.book_pdf, {}, { preserveScroll: true })} disabled={!book.can_render} data-book-pdf>
                        <FileText /> {t('keepsakes.photo_book.make_pdf')}
                    </Button>
                    {book.pdf_url && (
                        <Button asChild variant="link" className="h-11">
                            <a href={book.pdf_url} target="_blank" rel="noopener">
                                <ExternalLink /> {t('keepsakes.photo_book.open_pdf')}
                                {book.pdf_pages ? ` (${t('keepsakes.photo_book.pages_n', { count: n(book.pdf_pages) })})` : ''}
                            </a>
                        </Button>
                    )}
                </div>
            )}
            {props.can.update && book.status === 'draft' && options && (
                <div className="grid gap-3 rounded-xl border p-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                    <div className="grid gap-1.5">
                        <Label htmlFor="book-currency">{t('checkout.currency')}</Label>
                        <NativeSelect id="book-currency" value={currency} onChange={(e) => setCurrency(e.target.value)}>
                            {options.currencies.map((c) => (
                                <option key={c.code} value={c.code}>
                                    {t(`checkout.currencies.${c.code}`)}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="book-gateway">{t('checkout.method')}</Label>
                        <NativeSelect id="book-gateway" value={gateway} onChange={(e) => setGateway(e.target.value)}>
                            {options.gateways.map((g) => (
                                <option key={g.id} value={g.id}>
                                    {t(`checkout.methods.${g.id}.name`)}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    {options.gateways.length === 0 && <p className="text-muted-foreground text-sm sm:col-span-3">{t('checkout.no_methods')}</p>}
                    <Button className="h-11" onClick={order} disabled={!gateway || unit == null} data-book-order>
                        {t('keepsakes.photo_book.order')}
                        {unit != null && charged ? `${sep}${formatMoney(unit * form.copies, charged, locale)}` : ''}
                    </Button>
                </div>
            )}
        </Card>
    );
}

/**
 * GET /dashboard/events/{event}/keepsakes (phase 8D): the thank-you card, the
 * highlight video and the printed photo book.
 */
export default function Keepsakes(props: Props) {
    const { t, n, sep } = useT();
    const { flash } = usePage<SharedProps>().props;

    useEffect(() => {
        if (flash?.warning) toast.error(flash.warning);
        if (flash?.status) toast.success(flash.status);
    }, [flash?.warning, flash?.status]);

    return (
        <AppLayout>
            <Head title={`${t('keepsakes.title')} · ${props.event.title}`} />
            <Toaster />
            <div className="mx-auto grid max-w-4xl gap-5">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <Link href={props.urls.dashboard} className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm">
                        <ArrowLeft className="size-4 rtl:rotate-180" /> {t('keepsakes.back')}
                    </Link>
                    <Link href={props.urls.album} className="text-primary text-sm font-semibold">
                        {t('keepsakes.album')}
                        {sep}
                        {t('keepsakes.photos_count', { count: n(props.photos.length) })}
                    </Link>
                </div>
                <div className="grid gap-1">
                    <h1 className="text-2xl font-bold">
                        {t('keepsakes.title')}
                        {sep}
                        <bdi>{props.event.title}</bdi>
                    </h1>
                    <p className="text-muted-foreground">{t('keepsakes.subtitle')}</p>
                </div>
                <ThankYouCard props={props} />
                <VideoCard props={props} />
                {props.book && <BookCard props={props} />}
            </div>
        </AppLayout>
    );
}
