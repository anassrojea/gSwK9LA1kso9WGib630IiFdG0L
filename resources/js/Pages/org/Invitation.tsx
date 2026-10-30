import { Head, Link, router, usePage } from '@inertiajs/react';
import { Building2, MailCheck } from 'lucide-react';

import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout';
import { useT } from '@/lib/i18n';

interface Props {
    invitation: {
        org: string;
        logo_url: string | null;
        role: 'staff' | 'door' | 'client';
        event: string | null;
        email: string;
        inviter: string | null;
        expires_at: string;
        state: 'pending' | 'accepted' | 'revoked' | 'expired';
    };
    signed_in_as: string | null;
    email_matches: boolean;
    accept_url: string;
    login_url: string;
    register_url: string;
}

/**
 * GET /org-invitations/{id}/{token} (phase 8A): a team or client invitation.
 */
export default function OrgInvitation({ invitation, signed_in_as, email_matches, accept_url, login_url, register_url }: Props) {
    const { t } = useT();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const text =
        invitation.role === 'client'
            ? t('org.invite.client_text', { org: invitation.org, event: invitation.event ?? '' })
            : t('org.invite.team_text', { org: invitation.org, inviter: invitation.inviter ?? invitation.org, role: t(`org.roles.${invitation.role}`) });

    return (
        <AppLayout>
            <Head title={t('org.invite.title')} />
            <div className="mx-auto grid max-w-lg gap-5 rounded-xl border bg-white p-6 text-center" data-invitation={invitation.state}>
                {invitation.logo_url ? (
                    <img src={invitation.logo_url} alt="" className="mx-auto size-16 rounded-xl object-contain" />
                ) : (
                    <span className="bg-primary/10 text-primary mx-auto grid size-16 place-items-center rounded-xl">
                        <Building2 className="size-8" />
                    </span>
                )}
                <h1 className="text-xl font-bold">{text}</h1>
                <p className="text-muted-foreground flex items-center justify-center gap-2 text-sm">
                    <MailCheck className="size-4" /> {t('org.invite.sent_to', { email: invitation.email })}
                </p>

                {invitation.state !== 'pending' ? (
                    <p className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-950">{t(`org.invite.${invitation.state}`)}</p>
                ) : !signed_in_as ? (
                    <>
                        <p className="text-sm">{t('org.invite.signed_out', { email: invitation.email })}</p>
                        <div className="grid gap-2 sm:grid-cols-2">
                            <Button asChild size="lg" className="h-12">
                                <Link href={register_url}>{t('org.invite.register')}</Link>
                            </Button>
                            <Button asChild size="lg" variant="outline" className="h-12">
                                <Link href={login_url}>{t('org.invite.login')}</Link>
                            </Button>
                        </div>
                    </>
                ) : email_matches ? (
                    <Button size="lg" className="h-12" onClick={() => router.post(accept_url)} data-accept>
                        {t('org.invite.accept')}
                    </Button>
                ) : (
                    <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-900">{t('org.invite.wrong_email', { current: signed_in_as, email: invitation.email })}</p>
                )}
                {errors?.invitation && <p className="text-destructive text-sm">{errors.invitation}</p>}
            </div>
        </AppLayout>
    );
}
