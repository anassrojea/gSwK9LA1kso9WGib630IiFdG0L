import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, ExternalLink, ImagePlus, Lock, Printer, QrCode, Save, Store, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Toaster } from '@/components/ui/sonner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout';
import { toastFlash } from '@/lib/dashboard/flash';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Option = { value: string; label: string };
type Status = 'draft' | 'pending' | 'approved' | 'hidden';

interface ProfileData {
    exists: boolean;
    slug: string;
    name_en: string | null;
    venue_type: string;
    city: string;
    area: string | null;
    description_ar: string | null;
    description_en: string | null;
    capacity: number | null;
    amenities: string[];
    whatsapp: string | null;
    map_url: string | null;
    listed: boolean;
    status: Status;
}

interface Props {
    organizations: { id: string; name: string }[];
    org: { id: string; name: string } | null;
    feature?: boolean;
    profile?: ProfileData;
    public?: boolean;
    photos?: { id: string; url: string }[];
    logo?: { id: string; url: string } | null;
    halls?: { id: string; name: string; public: boolean; tables: number; seats: number }[];
    scans?: number;
    urls?: { page: string | null; poster_a4: string | null; poster_a5: string | null; short: string | null; halls: string };
    options: { cities: Option[]; types: Option[]; amenities: Option[]; max_photos: number };
    errors?: Record<string, string>;
}

const STATUS_TONE: Record<Status, string> = {
    draft: 'bg-zinc-100 text-zinc-700',
    pending: 'bg-amber-100 text-amber-900',
    approved: 'bg-emerald-100 text-emerald-800',
    hidden: 'bg-red-100 text-red-800',
};

/**
 * GET /dashboard/venue (phase 8B): the venue's page in the directory (plan
 * section 13.1), its photos and logo, which halls it shows, the listing
 * switch, and the "Book your invitation with us" QR posters.
 */
export default function VenueProfile(props: Props) {
    const { t, tc, dir } = useT();
    const { org, profile, options } = props;

    if (!org || !profile) {
        return (
            <AppLayout>
                <Head title={t('venues.dashboard.title')} />
                <div className="grid gap-4">
                    <h1 className="flex items-center gap-2 text-2xl font-bold">
                        <Store className="text-primary size-6" /> {t('venues.dashboard.title')}
                    </h1>
                    <p className="rounded-xl border border-dashed bg-white p-6 text-sm">{t('venues.dashboard.no_org')}</p>
                </div>
            </AppLayout>
        );
    }

    return <ProfileForm {...props} org={org} profile={profile} options={options} t={t} tc={tc} dir={dir} />;
}

function ProfileForm({
    organizations,
    org,
    feature = false,
    profile,
    photos = [],
    logo,
    halls = [],
    scans = 0,
    urls,
    options,
    errors = {},
    t,
    tc,
    dir,
}: Props & { org: { id: string; name: string }; profile: ProfileData } & Pick<ReturnType<typeof useT>, 't' | 'tc' | 'dir'>) {
    const form = useForm({
        org_id: org.id,
        slug: profile.slug,
        name_en: profile.name_en ?? '',
        venue_type: profile.venue_type,
        city: profile.city,
        area: profile.area ?? '',
        description_ar: profile.description_ar ?? '',
        description_en: profile.description_en ?? '',
        capacity: profile.capacity ? String(profile.capacity) : '',
        amenities: profile.amenities,
        whatsapp: profile.whatsapp ?? '',
        map_url: profile.map_url ?? '',
        listed: profile.listed,
        public_halls: halls.filter((h) => h.public).map((h) => h.id),
    });
    const [uploading, setUploading] = useState<'logo' | 'photo' | null>(null);
    const photoInput = useRef<HTMLInputElement>(null);
    const logoInput = useRef<HTMLInputElement>(null);
    const firstError = Object.values(errors)[0];

    useEffect(() => {
        if (firstError) toast.error(firstError);
    }, [firstError]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, capacity: d.capacity ? Number(d.capacity) : null }));
        form.put('/dashboard/venue', { preserveScroll: true, onSuccess: toastFlash });
    };

    const upload = (kind: 'logo' | 'photo', file: File | undefined) => {
        if (!file) return;
        setUploading(kind);
        router.post('/dashboard/venue/media', { org_id: org.id, kind, file }, { forceFormData: true, preserveScroll: true, onFinish: () => setUploading(null) });
    };

    const toggle = (list: string[], value: string, on: boolean) => (on ? [...new Set([...list, value])] : list.filter((v) => v !== value));
    const err = (key: string) => (errors[key] ? <p className="text-destructive text-xs">{errors[key]}</p> : null);

    return (
        <AppLayout wide>
            <Head title={t('venues.dashboard.title')} />
            <Toaster />
            <form onSubmit={submit} className="grid gap-5" data-venue-profile>
                <Link href={urls?.halls ?? '/dashboard/halls'} className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1 text-sm">
                    <ArrowLeft className="size-4 rtl:rotate-180" /> {t('seating.halls.title')}
                </Link>
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="grid gap-1">
                        <h1 className="flex items-center gap-2 text-2xl font-bold">
                            <Store className="text-primary size-6" /> {t('venues.dashboard.title')}
                        </h1>
                        <p className="text-muted-foreground text-sm">{t('venues.dashboard.subtitle')}</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {organizations.length > 1 && (
                            <NativeSelect dir={dir} value={org.id} onChange={(e) => router.get('/dashboard/venue', { org: e.target.value })} aria-label={t('venues.dashboard.organization')} className="h-9">
                                {organizations.map((o) => (
                                    <option key={o.id} value={o.id}>
                                        {o.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        )}
                        {urls?.page && (
                            <Button asChild variant="outline" className="h-9">
                                <a href={urls.page} target="_blank" rel="noopener" data-view-page>
                                    <ExternalLink /> {t('venues.dashboard.view_page')}
                                </a>
                            </Button>
                        )}
                        <Button type="submit" className="h-9" disabled={form.processing} data-save-venue>
                            <Save /> {t('venues.dashboard.save')}
                        </Button>
                    </div>
                </div>

                <div className="grid items-start gap-5 lg:grid-cols-[1fr_22rem]">
                    <div className="grid gap-5">
                        <Card title={<bdi>{org.name}</bdi>}>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field id="v-name-en" label={t('venues.dashboard.name_en')} error={err('name_en')}>
                                    <Input id="v-name-en" dir="ltr" value={form.data.name_en} maxLength={120} onChange={(e) => form.setData('name_en', e.target.value)} />
                                </Field>
                                <Field id="v-slug" label={t('venues.dashboard.slug')} hint={t('venues.dashboard.slug_hint')} error={err('slug')}>
                                    <Input id="v-slug" dir="ltr" value={form.data.slug} maxLength={80} onChange={(e) => form.setData('slug', e.target.value.toLowerCase())} />
                                </Field>
                                <Field id="v-type" label={t('venues.dashboard.type')}>
                                    <NativeSelect id="v-type" dir={dir} value={form.data.venue_type} onChange={(e) => form.setData('venue_type', e.target.value)}>
                                        {options.types.map((o) => (
                                            <option key={o.value} value={o.value}>
                                                {o.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field id="v-city" label={t('venues.dashboard.city')}>
                                    <NativeSelect id="v-city" dir={dir} value={form.data.city} onChange={(e) => form.setData('city', e.target.value)}>
                                        {options.cities.map((o) => (
                                            <option key={o.value} value={o.value}>
                                                {o.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field id="v-area" label={t('venues.dashboard.area')} error={err('area')}>
                                    <Input id="v-area" value={form.data.area} maxLength={80} onChange={(e) => form.setData('area', e.target.value)} />
                                </Field>
                                <Field id="v-capacity" label={t('venues.dashboard.capacity')} hint={t('venues.dashboard.capacity_hint')} error={err('capacity')}>
                                    <Input id="v-capacity" inputMode="numeric" dir="ltr" value={form.data.capacity} onChange={(e) => form.setData('capacity', e.target.value.replace(/[^\d]/g, ''))} />
                                </Field>
                                <Field id="v-wa" label={t('venues.dashboard.whatsapp')} hint={t('venues.dashboard.whatsapp_hint')} error={err('whatsapp')}>
                                    <Input id="v-wa" inputMode="tel" dir="ltr" value={form.data.whatsapp} maxLength={32} onChange={(e) => form.setData('whatsapp', e.target.value)} />
                                </Field>
                                <Field id="v-map" label={t('venues.dashboard.map_url')} error={err('map_url')}>
                                    <Input id="v-map" dir="ltr" value={form.data.map_url} maxLength={500} placeholder="https://maps.google.com/…" onChange={(e) => form.setData('map_url', e.target.value)} />
                                </Field>
                            </div>
                            <Field id="v-desc-ar" label={t('venues.dashboard.description_ar')} error={err('description_ar')}>
                                <Textarea id="v-desc-ar" dir="rtl" rows={4} maxLength={3000} value={form.data.description_ar} onChange={(e) => form.setData('description_ar', e.target.value)} />
                            </Field>
                            <Field id="v-desc-en" label={t('venues.dashboard.description_en')} error={err('description_en')}>
                                <Textarea id="v-desc-en" dir="ltr" rows={4} maxLength={3000} value={form.data.description_en} onChange={(e) => form.setData('description_en', e.target.value)} />
                            </Field>
                        </Card>

                        <Card title={t('venues.dashboard.amenities')}>
                            <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3" data-amenities>
                                {options.amenities.map((a) => (
                                    <li key={a.value}>
                                        <label className="flex items-center gap-2 text-sm">
                                            <input type="checkbox" className="accent-primary size-4" checked={form.data.amenities.includes(a.value)} onChange={(e) => form.setData('amenities', toggle(form.data.amenities, a.value, e.target.checked))} />
                                            {a.label}
                                        </label>
                                    </li>
                                ))}
                            </ul>
                        </Card>

                        <Card title={t('venues.dashboard.halls')} subtitle={t('venues.dashboard.halls_hint')}>
                            {halls.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    {t('venues.dashboard.no_halls')}{' '}
                                    <Link href="/dashboard/halls/create" className="text-primary underline">
                                        {t('seating.halls.new')}
                                    </Link>
                                </p>
                            ) : (
                                <ul className="grid gap-2" data-public-halls>
                                    {halls.map((h) => (
                                        <li key={h.id} className="flex items-center justify-between gap-3 rounded-lg border px-3 py-2">
                                            <span className="grid">
                                                <bdi className="font-medium">{h.name}</bdi>
                                                <span className="text-muted-foreground text-xs">
                                                    {tc('seating.hall.tables', h.tables)} · {t('seating.hall.seats', { count: h.seats })}
                                                </span>
                                            </span>
                                            <Switch checked={form.data.public_halls.includes(h.id)} onCheckedChange={(on) => form.setData('public_halls', toggle(form.data.public_halls, h.id, on))} aria-label={h.name} />
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    </div>

                    <aside className="grid gap-5">
                        <Card title={t('venues.dashboard.listing')}>
                            <div className="flex items-center justify-between gap-2">
                                <span className={cn('rounded-full px-2.5 py-0.5 text-xs font-semibold', STATUS_TONE[profile.status])} data-status={profile.status}>
                                    {t(`venues.dashboard.status.${profile.status}`)}
                                </span>
                            </div>
                            <label className="flex items-start justify-between gap-3 text-sm">
                                <span className="grid gap-0.5">
                                    <span className="font-medium">{t('venues.dashboard.listed')}</span>
                                    <span className="text-muted-foreground text-xs">{t('venues.dashboard.listed_hint')}</span>
                                </span>
                                <Switch checked={form.data.listed} disabled={!feature} onCheckedChange={(on) => form.setData('listed', on)} data-listed />
                            </label>
                            {!feature && (
                                <p className="flex items-start gap-2 rounded-lg bg-amber-50 p-2 text-xs text-amber-900" data-needs-plan>
                                    <Lock className="mt-0.5 size-3.5 shrink-0" /> {t('venues.dashboard.needs_plan')}
                                </p>
                            )}
                            {err('listed')}
                        </Card>

                        <Card title={t('venues.dashboard.logo')}>
                            <div className="flex items-center gap-3">
                                <div className="bg-muted grid size-16 place-items-center overflow-hidden rounded-xl border">{logo ? <img src={logo.url} alt="" className="size-full object-cover" /> : <Store className="text-muted-foreground size-6" />}</div>
                                <input ref={logoInput} type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={(e) => upload('logo', e.target.files?.[0])} data-logo-input />
                                <Button type="button" variant="outline" size="sm" disabled={uploading !== null} onClick={() => logoInput.current?.click()}>
                                    <ImagePlus /> {uploading === 'logo' ? t('venues.dashboard.uploading') : t('venues.dashboard.upload')}
                                </Button>
                            </div>
                        </Card>

                        <Card title={t('venues.dashboard.photos')} subtitle={t('venues.dashboard.photos_hint', { count: options.max_photos })}>
                            <ul className="grid grid-cols-3 gap-2" data-photos>
                                {photos.map((p) => (
                                    <li key={p.id} className="group relative aspect-square overflow-hidden rounded-lg border">
                                        <img src={p.url} alt="" className="size-full object-cover" />
                                        <button
                                            type="button"
                                            className="absolute end-1 top-1 rounded-full bg-white/90 p-1 text-red-700 shadow"
                                            aria-label={t('venues.dashboard.remove')}
                                            onClick={() => router.delete(`/dashboard/venue/media/${p.id}`, { preserveScroll: true })}
                                        >
                                            <Trash2 className="size-3.5" />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                            <input ref={photoInput} type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={(e) => upload('photo', e.target.files?.[0])} data-photo-input />
                            <Button type="button" variant="outline" size="sm" disabled={uploading !== null || photos.length >= options.max_photos} onClick={() => photoInput.current?.click()}>
                                <ImagePlus /> {uploading === 'photo' ? t('venues.dashboard.uploading') : t('venues.dashboard.upload')}
                            </Button>
                            {err('file')}
                        </Card>

                        <Card title={t('venues.dashboard.qr')} subtitle={t('venues.dashboard.qr_text')}>
                            {urls?.poster_a4 ? (
                                <>
                                    <div className="flex flex-wrap gap-2">
                                        <Button asChild variant="outline" size="sm">
                                            <a href={urls.poster_a4} target="_blank" rel="noopener" data-poster="A4">
                                                <Printer /> {t('venues.dashboard.poster_a4')}
                                            </a>
                                        </Button>
                                        {urls.poster_a5 && (
                                            <Button asChild variant="outline" size="sm">
                                                <a href={urls.poster_a5} target="_blank" rel="noopener" data-poster="A5">
                                                    <Printer /> {t('venues.dashboard.poster_a5')}
                                                </a>
                                            </Button>
                                        )}
                                    </div>
                                    <p className="text-muted-foreground flex items-center gap-2 text-xs">
                                        <QrCode className="size-4" />
                                        <bdi dir="ltr">{urls.short}</bdi>
                                    </p>
                                    <p className="text-sm font-medium" data-scans>
                                        {tc('venues.dashboard.scans', scans)}
                                    </p>
                                </>
                            ) : (
                                <p className="text-muted-foreground text-xs">{t('venues.dashboard.save')}</p>
                            )}
                        </Card>
                    </aside>
                </div>
            </form>
        </AppLayout>
    );
}

function Card({ title, subtitle, children }: { title: ReactNode; subtitle?: string; children: ReactNode }) {
    return (
        <section className="grid gap-3 rounded-xl border bg-white p-4">
            <div className="grid gap-0.5">
                <h2 className="font-bold">{title}</h2>
                {subtitle && <p className="text-muted-foreground text-xs">{subtitle}</p>}
            </div>
            {children}
        </section>
    );
}

function Field({ id, label, hint, error, children }: { id: string; label: string; hint?: string; error?: ReactNode; children: ReactNode }) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint && <p className="text-muted-foreground text-xs">{hint}</p>}
            {error}
        </div>
    );
}
