import { router, useForm } from '@inertiajs/react';
import { Check, KeyRound, LogOut, Mail, Monitor, Smartphone } from 'lucide-react';
import type { FormEvent } from 'react';

import { Section } from '@/components/account/Section';
import { FieldError } from '@/components/FieldError';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import type { DeviceSession, SecurityData, SignInMethod } from '@/types/account';

const METHOD_ICONS: Record<SignInMethod, typeof KeyRound> = { password: KeyRound, google: Check, email_code: Mail };

/** "Chrome on Android", "Safari on iPhone"... from the user agent, without a library. */
export function describeAgent(agent: string): { label: string; mobile: boolean } {
    const browser = /Edg\//.test(agent)
        ? 'Edge'
        : /OPR\/|Opera/.test(agent)
          ? 'Opera'
          : /Firefox\//.test(agent)
            ? 'Firefox'
            : /SamsungBrowser/.test(agent)
              ? 'Samsung Internet'
              : /Chrome\/|CriOS/.test(agent)
                ? 'Chrome'
                : /Safari\//.test(agent)
                  ? 'Safari'
                  : null;
    const os = /iPhone/.test(agent)
        ? 'iPhone'
        : /iPad/.test(agent)
          ? 'iPad'
          : /Android/.test(agent)
            ? 'Android'
            : /Windows/.test(agent)
              ? 'Windows'
              : /Mac OS X|Macintosh/.test(agent)
                ? 'Mac'
                : /Linux/.test(agent)
                  ? 'Linux'
                  : null;
    const label = [browser, os].filter(Boolean).join(' · ');

    return { label, mobile: /Mobile|iPhone|Android/.test(agent) };
}

function PasswordForm({ hasPassword }: { hasPassword: boolean }) {
    const { t } = useT();
    const form = useForm({ current_password: '', password: '', password_confirmation: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put('/account/password', { preserveScroll: true, onSuccess: () => form.reset(), onError: () => form.reset('password', 'password_confirmation') });
    };

    const input = (name: 'current_password' | 'password' | 'password_confirmation', label: string, autoComplete: string) => (
        <div className="grid gap-2">
            <Label htmlFor={`security-${name}`}>{label}</Label>
            <Input id={`security-${name}`} type="password" dir="ltr" autoComplete={autoComplete} required value={form.data[name]}
                onChange={(e) => form.setData(name, e.target.value)} aria-invalid={!!form.errors[name]} />
            <FieldError message={form.errors[name]} />
        </div>
    );

    return (
        <form onSubmit={submit} className="grid gap-4" data-password-form={hasPassword ? 'change' : 'set'}>
            {hasPassword && input('current_password', t('account.security.current_password'), 'current-password')}
            <div className="grid gap-4 sm:grid-cols-2">
                {input('password', t('account.security.new_password'), 'new-password')}
                {input('password_confirmation', t('account.security.confirm_password'), 'new-password')}
            </div>
            <Button type="submit" className="h-11 w-full sm:w-fit sm:px-6" disabled={form.processing}>
                {hasPassword ? t('account.security.change_password') : t('account.security.set_password')}
            </Button>
        </form>
    );
}

function SessionRow({ session }: { session: DeviceSession }) {
    const { t, locale } = useT();
    const { label, mobile } = describeAgent(session.agent);
    const Icon = mobile ? Smartphone : Monitor;
    const seen = new Intl.DateTimeFormat(locale === 'ar' ? 'ar-u-nu-arab' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(session.last_seen));

    return (
        <li className="flex items-center gap-3 py-3" data-session={session.current ? 'current' : 'other'}>
            <Icon className="text-muted-foreground size-5 shrink-0" />
            <div className="grid min-w-0 flex-1 gap-0.5">
                <span className="flex flex-wrap items-center gap-2 text-sm font-medium">
                    <bdi dir="ltr">{label || t('account.security.sessions.unknown_device')}</bdi>
                    {session.current && <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-900">{t('account.security.sessions.this_device')}</span>}
                </span>
                <span className="text-muted-foreground truncate text-xs">
                    {t('account.security.sessions.last_seen', { date: seen })}
                    {session.ip && (
                        <>
                            {' · '}
                            <bdi dir="ltr">{session.ip}</bdi>
                        </>
                    )}
                </span>
            </div>
            {!session.current && (
                <Button variant="ghost" size="sm" onClick={() => router.delete(`/account/sessions/${session.key}`, { preserveScroll: true })}>
                    {t('account.security.sessions.sign_out')}
                </Button>
            )}
        </li>
    );
}

export function SecurityTab({ security, hasPassword }: { security: SecurityData; hasPassword: boolean }) {
    const { t } = useT();
    const others = security.sessions.filter((s) => !s.current);

    return (
        <div className="grid gap-5">
            <Section title={t('account.security.methods_title')} name="methods">
                <ul className="flex flex-wrap gap-2" data-methods>
                    {security.methods.map((m) => {
                        const Icon = METHOD_ICONS[m];
                        return (
                            <li key={m} className="bg-muted inline-flex h-9 items-center gap-1.5 rounded-full px-3 text-sm">
                                <Icon className="size-4" /> {t(`account.security.methods.${m}`)}
                            </li>
                        );
                    })}
                </ul>
            </Section>

            <Section
                title={hasPassword ? t('account.security.password_title') : t('account.security.set_password_title')}
                description={hasPassword ? undefined : t('account.security.set_password_text')}
                name="password"
            >
                <PasswordForm hasPassword={hasPassword} />
            </Section>

            {security.google.enabled && (
                <Section
                    title={t('account.security.google_title')}
                    description={security.google.linked ? t('account.security.google_linked_text') : t('account.security.google_unlinked_text')}
                    name="google"
                >
                    {security.google.linked ? (
                        <div className="grid gap-2">
                            <Button
                                variant="outline"
                                className="h-11 w-full sm:w-fit"
                                disabled={!security.can_unlink_google}
                                onClick={() => router.delete('/account/google', { preserveScroll: true })}
                                data-google-unlink
                            >
                                {t('account.security.unlink_google')}
                            </Button>
                            {!security.can_unlink_google && <p className="text-muted-foreground text-xs">{t('account.security.google_only_hint')}</p>}
                        </div>
                    ) : (
                        <Button asChild variant="outline" className="h-11 w-full sm:w-fit">
                            <a href="/auth/google/redirect" data-google-link>
                                {t('account.security.link_google')}
                            </a>
                        </Button>
                    )}
                </Section>
            )}

            <Section title={t('account.security.sessions.title')} description={t('account.security.sessions.text')} name="sessions">
                <ul className="divide-y" data-sessions>
                    {security.sessions.map((s) => (
                        <SessionRow key={s.key} session={s} />
                    ))}
                </ul>
                {others.length === 0 && <p className="text-muted-foreground text-sm">{t('account.security.sessions.none')}</p>}
                <Button variant="outline" className="h-11 w-full sm:w-fit" onClick={() => router.delete('/account/sessions', { preserveScroll: true })} data-sign-out-others>
                    <LogOut className="rtl:rotate-180" /> {t('account.security.sessions.sign_out_others')}
                </Button>
            </Section>
        </div>
    );
}
