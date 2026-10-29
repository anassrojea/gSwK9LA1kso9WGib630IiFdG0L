import { router, useForm } from '@inertiajs/react';
import { Minus, Plus, Trash2, UserPlus, Users } from 'lucide-react';
import { useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field } from '@/components/wizard/fields';
import WizardLayout from '@/components/wizard/WizardLayout';
import { parseGuestList } from '@/lib/guest-list';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { GuestRow, WizardProps } from '@/types/wizard';

interface Props extends WizardProps {
    households: GuestRow[];
    limit: number | null;
    seats: number;
    errors?: Record<string, string>;
}

function SeatStepper({ value, onChange, label, max = 100 }: { value: number; onChange: (n: number) => void; label: string; max?: number }) {
    const { t, n } = useT();
    return (
        <div className="flex items-center gap-1" role="group" aria-label={label}>
            <Button type="button" variant="outline" size="icon" className="size-10" disabled={value <= 1} onClick={() => onChange(value - 1)} aria-label={t('wizard.guests.fewer')}>
                <Minus />
            </Button>
            <span className="w-8 text-center text-base font-semibold tabular-nums" aria-live="polite">
                {n(value)}
            </span>
            <Button type="button" variant="outline" size="icon" className="size-10" disabled={value >= max} onClick={() => onChange(value + 1)} aria-label={t('wizard.guests.more')}>
                <Plus />
            </Button>
        </div>
    );
}

function GuestItem({ guest }: { guest: GuestRow }) {
    const { t } = useT();
    const [name, setName] = useState(guest.name);
    const [error, setError] = useState<string | null>(null);

    const save = (data: { name: string; seats: number }) =>
        router.patch(`/households/${guest.id}`, data, {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => setError(errors.seats ?? errors.name ?? null),
            onSuccess: () => setError(null),
        });

    return (
        <li className="bg-card grid gap-2 rounded-xl border p-3" data-guest>
            <div className="flex items-center gap-2">
                <Input
                    className="h-11 flex-1"
                    dir="auto"
                    value={name}
                    maxLength={120}
                    aria-label={t('wizard.guests.name')}
                    onChange={(e) => setName(e.target.value)}
                    onBlur={() => name.trim() && name !== guest.name && save({ name, seats: guest.seats })}
                />
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="text-destructive size-11"
                    onClick={() => router.delete(`/households/${guest.id}`, { preserveScroll: true })}
                    aria-label={t('wizard.guests.delete', { name: guest.name })}
                >
                    <Trash2 className="size-5" />
                </Button>
            </div>
            <div className="flex items-center justify-between gap-2">
                <span className="text-muted-foreground text-sm">{t('wizard.guests.seats')}</span>
                <SeatStepper value={guest.seats} label={t('wizard.guests.seats')} onChange={(seats) => save({ name: name.trim() || guest.name, seats })} />
            </div>
            {error && (
                <p className="text-destructive text-sm" role="alert">
                    {error}
                </p>
            )}
        </li>
    );
}

/**
 * Step 4: paste a list (one family per line, "أم فيصل - 3"), then adjust
 * seats per family. The plan's soft guest limit is checked on every write.
 */
export default function Guests(props: Props) {
    const { t, tc, n } = useT();
    const paste = useForm({ text: '' });
    const single = useForm({ name: '', seats: 2 });
    const parsed = useMemo(() => parseGuestList(paste.data.text), [paste.data.text]);
    const parsedSeats = parsed.reduce((sum, g) => sum + g.seats, 0);
    const limit = props.limit;
    const left = limit === null ? null : Math.max(0, limit - props.seats);

    const importList = (e: React.FormEvent) => {
        e.preventDefault();
        paste.post(`/events/${props.event.id}/guests/import`, { preserveScroll: true, onSuccess: () => paste.reset() });
    };

    const addOne = (e: React.FormEvent) => {
        e.preventDefault();
        single.post(`/events/${props.event.id}/guests`, { preserveScroll: true, onSuccess: () => single.reset('name') });
    };

    return (
        <WizardLayout step="guests" eventId={props.event.id} title={t('wizard.guests.title')} subtitle={t('wizard.guests.subtitle')} previewUrl={props.urls.preview}>
            <div className="grid gap-6">
                {limit !== null && (
                    <div className="bg-card grid gap-2 rounded-xl border p-4">
                        <div className="flex items-center justify-between gap-2 text-sm">
                            <span className="flex items-center gap-2 font-semibold">
                                <Users className="size-4" /> {t('wizard.guests.usage', { used: props.seats, limit })}
                            </span>
                            <span className="text-muted-foreground">{t('wizard.guests.left', { left: left ?? 0 })}</span>
                        </div>
                        <div className="bg-muted h-2 overflow-hidden rounded-full">
                            <div className={cn('h-full rounded-full', props.seats >= limit ? 'bg-amber-500' : 'bg-primary')} style={{ width: `${Math.min(100, (props.seats / limit) * 100)}%` }} />
                        </div>
                        <p className="text-muted-foreground text-xs">{t('wizard.guests.limit_hint')}</p>
                    </div>
                )}

                <form onSubmit={importList} className="bg-card grid gap-3 rounded-xl border p-4">
                    <Field label={t('wizard.guests.paste_label')} hint={t('wizard.guests.paste_hint')} error={paste.errors.text}>
                        <Textarea
                            dir="auto"
                            rows={6}
                            className="min-h-40 text-base"
                            placeholder={t('wizard.guests.paste_placeholder')}
                            value={paste.data.text}
                            onChange={(e) => paste.setData('text', e.target.value)}
                            aria-invalid={!!paste.errors.text}
                            data-guest-paste
                        />
                    </Field>
                    {parsed.length > 0 && (
                        <div className="bg-muted/50 grid gap-2 rounded-lg p-3">
                            <p className="text-sm font-semibold">{t('wizard.guests.found', { families: tc('wizard.guests.families', parsed.length), seats: tc('wizard.guests.seat_count', parsedSeats) })}</p>
                            <ul className="flex flex-wrap gap-1.5">
                                {parsed.slice(0, 12).map((g, i) => (
                                    <li key={i} className="bg-background rounded-full border px-2.5 py-1 text-xs">
                                        {g.name} <b className="text-primary ms-1">{n(g.seats)}</b>
                                    </li>
                                ))}
                                {parsed.length > 12 && <li className="text-muted-foreground px-1 text-xs">+{n(parsed.length - 12)}</li>}
                            </ul>
                        </div>
                    )}
                    <Button type="submit" className="h-12 text-base" disabled={paste.processing || parsed.length === 0}>
                        <UserPlus /> {parsed.length ? t('wizard.guests.add_families', { families: tc('wizard.guests.families', parsed.length) }) : t('wizard.guests.add_list')}
                    </Button>
                </form>

                <section className="grid gap-3">
                    <h2 className="font-semibold">
                        {t('wizard.guests.list')} <span className="text-muted-foreground text-sm font-normal">({tc('wizard.guests.families', props.households.length)})</span>
                    </h2>
                    {props.households.length === 0 && <p className="text-muted-foreground rounded-xl border border-dashed p-6 text-center text-sm">{t('wizard.guests.empty')}</p>}
                    <ul className="grid gap-2">
                        {props.households.map((guest) => (
                            <GuestItem key={`${guest.id}-${guest.seats}-${guest.name}`} guest={guest} />
                        ))}
                    </ul>

                    <form onSubmit={addOne} className="bg-card grid gap-3 rounded-xl border border-dashed p-3">
                        <Field label={t('wizard.guests.add_one')} error={single.errors.name ?? single.errors.seats}>
                            <Input className="h-11" dir="auto" placeholder={t('wizard.guests.name_placeholder')} value={single.data.name} maxLength={120} onChange={(e) => single.setData('name', e.target.value)} />
                        </Field>
                        <div className="flex items-center justify-between gap-2">
                            <SeatStepper value={single.data.seats} label={t('wizard.guests.seats')} onChange={(seats) => single.setData('seats', seats)} />
                            <Button type="submit" variant="secondary" className="h-11" disabled={single.processing || !single.data.name.trim()}>
                                <Plus /> {t('wizard.guests.add')}
                            </Button>
                        </div>
                    </form>
                </section>
            </div>
        </WizardLayout>
    );
}
