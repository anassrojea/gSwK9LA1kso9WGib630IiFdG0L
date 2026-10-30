import { Head, useForm } from '@inertiajs/react';
import { KeyRound, Lock } from 'lucide-react';

import { LocaleSwitch } from '@/components/LocaleSwitch';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useT } from '@/lib/i18n';

interface Props {
    event: { id: string; title: string };
    pass: { label: string; active: boolean };
    errors?: { pin?: string };
}

/**
 * GET /door/{event}/pass/{pass} (signed): door staff without an account type
 * the 6-digit PIN the hosts gave them, separately from the link.
 */
export default function Pin({ event, pass }: Props) {
    const { t, sep } = useT();
    const form = useForm({ pin: '' });

    return (
        <div className="bg-muted/40 grid min-h-svh place-items-center px-4 py-8">
            <Head title={t('door.pin.title')} />
            <div className="absolute end-3 top-3">
                <LocaleSwitch />
            </div>
            <section className="grid w-full max-w-sm gap-5 rounded-2xl border bg-white p-6 text-center shadow-sm" data-pin>
                <span className="bg-primary/10 text-primary mx-auto grid size-14 place-items-center rounded-full">
                    {pass.active ? <KeyRound className="size-7" /> : <Lock className="size-7" />}
                </span>
                <div className="grid gap-1">
                    <h1 className="text-xl font-bold">{t('door.pin.title')}</h1>
                    <p className="text-muted-foreground text-sm">
                        <bdi>{event.title}</bdi>
                        {sep}
                        <bdi>{pass.label}</bdi>
                    </p>
                </div>
                {pass.active ? (
                    <form
                        className="grid gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(window.location.pathname + window.location.search, { preserveScroll: true, onError: () => form.reset('pin') });
                        }}
                    >
                        <p className="text-sm">{t('door.pin.text')}</p>
                        <label htmlFor="pin" className="sr-only">
                            {t('door.pin.label')}
                        </label>
                        <Input
                            id="pin"
                            value={form.data.pin}
                            onChange={(e) => form.setData('pin', e.target.value.replace(/\D/g, '').slice(0, 6))}
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            pattern="\d{6}"
                            maxLength={6}
                            dir="ltr"
                            className="h-14 text-center font-mono text-3xl tracking-[0.5em]"
                            aria-invalid={!!form.errors.pin}
                            aria-describedby={form.errors.pin ? 'pin-error' : undefined}
                            autoFocus
                        />
                        {form.errors.pin && (
                            <p id="pin-error" className="text-destructive text-sm" role="alert">
                                {form.errors.pin}
                            </p>
                        )}
                        <Button type="submit" className="h-12" disabled={form.processing || form.data.pin.length !== 6}>
                            {t('door.pin.submit')}
                        </Button>
                    </form>
                ) : (
                    <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">{t('door.pin.inactive')}</p>
                )}
            </section>
        </div>
    );
}
