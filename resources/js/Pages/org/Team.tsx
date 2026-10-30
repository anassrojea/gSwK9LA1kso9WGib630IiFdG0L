import { Head, router, useForm } from '@inertiajs/react';
import { Crown, LogOut, Mail, Trash2, UserPlus, X } from 'lucide-react';
import type { FormEvent } from 'react';

import { FieldError } from '@/components/FieldError';
import { OrgLayout } from '@/components/org/OrgLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { useT } from '@/lib/i18n';
import type { Member, Org } from '@/types/org';

interface Props {
    org: Org;
    members: Member[];
    invitations: { id: string; email: string; role: string; expires_at: string }[];
    can_manage: boolean;
    me: string;
}

/**
 * GET /org/{org}/team (phase 8A): members with their roles, pending
 * invitations, the seats the plan allows, ownership hand-over and leaving.
 */
export default function OrgTeam({ org, members, invitations, can_manage, me }: Props) {
    const { t, locale, dir } = useT();
    const form = useForm({ email: '', role: 'staff' });
    const date = (iso: string) => new Intl.DateTimeFormat(locale === 'ar' ? 'ar-u-nu-arab' : 'en-GB', { day: 'numeric', month: 'long' }).format(new Date(iso));
    const full = org.seats.used >= org.seats.limit;
    const base = `/org/${org.id}`;

    const invite = (e: FormEvent) => {
        e.preventDefault();
        form.post(`${base}/invitations`, { preserveScroll: true, onSuccess: () => form.reset('email') });
    };
    const confirmThen = (message: string, run: () => void) => {
        if (window.confirm(message)) run();
    };

    return (
        <OrgLayout org={org} active="team" wide={false}>
            <Head title={`${org.name} · ${t('org.team.title')}`} />
            <div className="grid gap-5">
                <section className="grid gap-3 rounded-xl border bg-white p-4 sm:p-5" data-members>
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 className="text-lg font-bold">{t('org.team.members')}</h2>
                        <span className={full ? 'text-sm font-medium text-amber-800' : 'text-muted-foreground text-sm'} data-seats={`${org.seats.used}/${org.seats.limit}`}>
                            {t('org.team.seats', { used: org.seats.used, limit: org.seats.limit })}
                        </span>
                    </div>
                    <ul className="divide-y">
                        {members.map((m) => (
                            <li key={m.user_id} className="flex flex-wrap items-center gap-3 py-3" data-member={m.role}>
                                <span className="bg-primary/10 text-primary grid size-10 shrink-0 place-items-center rounded-full font-bold" aria-hidden>
                                    {(m.name ?? '?').trim()[0]}
                                </span>
                                <span className="grid min-w-0 flex-1 gap-0.5">
                                    <span className="truncate font-medium">
                                        <bdi>{m.name}</bdi>
                                        {m.user_id === me && <span className="text-muted-foreground text-xs"> ({t('org.team.you')})</span>}
                                    </span>
                                    {m.email && (
                                        <bdi dir="ltr" className="text-muted-foreground truncate text-start text-xs">
                                            {m.email}
                                        </bdi>
                                    )}
                                </span>
                                {can_manage && m.role !== 'owner' ? (
                                    <div className="flex flex-wrap items-center gap-2">
                                        <NativeSelect
                                            dir={dir}
                                            aria-label={t('org.team.role')}
                                            className="h-9 w-36"
                                            value={m.role}
                                            onChange={(e) => router.patch(`${base}/members/${m.user_id}`, { role: e.target.value }, { preserveScroll: true })}
                                        >
                                            <option value="staff">{t('org.roles.staff')}</option>
                                            <option value="door">{t('org.roles.door')}</option>
                                        </NativeSelect>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className="h-9"
                                            onClick={() => confirmThen(t('org.team.transfer_confirm', { name: m.name ?? '' }), () => router.post(`${base}/transfer`, { user_id: m.user_id }, { preserveScroll: true }))}
                                            data-make-owner
                                        >
                                            <Crown /> {t('org.team.make_owner')}
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className="text-destructive h-9"
                                            onClick={() => confirmThen(t('org.team.remove_confirm', { name: m.name ?? '' }), () => router.delete(`${base}/members/${m.user_id}`, { preserveScroll: true }))}
                                            aria-label={t('org.team.remove')}
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                ) : (
                                    <span className={m.role === 'owner' ? 'rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-900' : 'bg-muted rounded-full px-2 py-0.5 text-xs'}>
                                        {t(`org.roles.${m.role}`)}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>

                {can_manage && (
                    <form onSubmit={invite} className="grid gap-4 rounded-xl border bg-white p-4 sm:p-5" data-invite-form>
                        <h2 className="flex items-center gap-2 text-lg font-bold">
                            <UserPlus className="text-primary size-5" /> {t('org.team.invite')}
                        </h2>
                        <div className="grid gap-4 sm:grid-cols-[1fr_200px]">
                            <div className="grid gap-2">
                                <Label htmlFor="invite-email">{t('org.team.email')}</Label>
                                <Input id="invite-email" type="email" dir="ltr" className="h-11 text-start" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} required />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="invite-role">{t('org.team.role')}</Label>
                                <NativeSelect id="invite-role" dir={dir} value={form.data.role} onChange={(e) => form.setData('role', e.target.value)}>
                                    <option value="staff">{t('org.roles.staff')}</option>
                                    <option value="door">{t('org.roles.door')}</option>
                                </NativeSelect>
                            </div>
                        </div>
                        <p className="text-muted-foreground text-xs">{t(`org.role_hints.${form.data.role}`)}</p>
                        <FieldError message={form.errors.email ?? form.errors.role} />
                        <Button type="submit" className="h-11 sm:w-fit" disabled={form.processing}>
                            <Mail /> {t('org.team.send')}
                        </Button>
                    </form>
                )}

                {can_manage && invitations.length > 0 && (
                    <section className="grid gap-2 rounded-xl border bg-white p-4 sm:p-5" data-pending-invitations>
                        <h2 className="font-bold">{t('org.team.pending')}</h2>
                        <ul className="divide-y">
                            {invitations.map((i) => (
                                <li key={i.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                    <span className="grid gap-0.5">
                                        <bdi dir="ltr" className="text-start">
                                            {i.email}
                                        </bdi>
                                        <span className="text-muted-foreground text-xs">
                                            {t(`org.roles.${i.role}`)} · {t('org.team.expires', { date: date(i.expires_at) })}
                                        </span>
                                    </span>
                                    <Button size="sm" variant="ghost" className="h-9" onClick={() => router.delete(`${base}/invitations/${i.id}`, { preserveScroll: true })}>
                                        <X /> {t('org.team.revoke')}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {org.role && (
                    <section className="flex flex-wrap items-center justify-between gap-2 rounded-xl border bg-white p-4 text-sm">
                        {org.role === 'owner' ? (
                            <span className="text-muted-foreground">{t('org.team.owner_cant_leave')}</span>
                        ) : (
                            <Button
                                variant="outline"
                                className="text-destructive h-10"
                                onClick={() => confirmThen(t('org.team.leave_confirm', { org: org.name }), () => router.post(`${base}/leave`))}
                                data-leave
                            >
                                <LogOut className="rtl:rotate-180" /> {t('org.team.leave')}
                            </Button>
                        )}
                    </section>
                )}
            </div>
        </OrgLayout>
    );
}
