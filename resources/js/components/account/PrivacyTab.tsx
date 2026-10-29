import { router, useForm } from '@inertiajs/react';
import { Download, Mail, ShieldAlert, Trash2 } from 'lucide-react';
import { useState, type FormEvent } from 'react';

import { Section } from '@/components/account/Section';
import { FieldError } from '@/components/FieldError';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import type { AccountSummary, PrivacyData } from '@/types/account';

function DeleteDialog({ open, onOpenChange, privacy, email }: { open: boolean; onOpenChange: (open: boolean) => void; privacy: PrivacyData; email: string | null }) {
    const { t } = useT();
    const form = useForm({ password: '', code: '' });
    const [sending, setSending] = useState(false);
    const [sent, setSent] = useState(false);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/account/deletion', { preserveScroll: true, onSuccess: () => onOpenChange(false), onFinish: () => form.reset() });
    };

    const sendCode = () => {
        setSending(true);
        router.post('/account/deletion/code', {}, { preserveScroll: true, preserveState: true, onSuccess: () => setSent(true), onFinish: () => setSending(false) });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent closeLabel={t('account.privacy.cancel')} data-delete-dialog>
                <DialogHeader>
                    <DialogTitle>{t('account.privacy.confirm_title')}</DialogTitle>
                    <DialogDescription>{t('account.privacy.delete_text', { days: privacy.grace_days })}</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    {privacy.confirm_with === 'password' ? (
                        <div className="grid gap-2">
                            <Label htmlFor="delete-password">{t('account.privacy.confirm_password')}</Label>
                            <Input id="delete-password" type="password" dir="ltr" autoComplete="current-password" autoFocus required value={form.data.password}
                                onChange={(e) => form.setData('password', e.target.value)} aria-invalid={!!form.errors.password} />
                            <FieldError message={form.errors.password} />
                        </div>
                    ) : (
                        <div className="grid gap-2">
                            <p className="text-muted-foreground text-sm">{t('account.privacy.confirm_code', { email: email ?? '' })}</p>
                            <Button type="button" variant="outline" className="h-10 w-fit" onClick={sendCode} disabled={sending} data-delete-send-code>
                                <Mail /> {t('account.privacy.send_code')}
                            </Button>
                            {sent && <p className="text-sm text-emerald-700" role="status">{t('account.privacy.code_sent', { email: email ?? '' })}</p>}
                            <Label htmlFor="delete-code">{t('account.privacy.code')}</Label>
                            <Input id="delete-code" inputMode="numeric" autoComplete="one-time-code" dir="ltr" className="font-mono tracking-widest" maxLength={12} required
                                value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} aria-invalid={!!form.errors.code} />
                            <FieldError message={form.errors.code} />
                        </div>
                    )}
                    <FieldError message={(form.errors as Record<string, string>).deletion} />
                    <div className="grid gap-2 sm:grid-cols-2">
                        <Button type="button" variant="outline" className="h-11" onClick={() => onOpenChange(false)}>
                            {t('account.privacy.cancel')}
                        </Button>
                        <Button type="submit" variant="destructive" className="h-11" disabled={form.processing} data-delete-confirm>
                            {t('account.privacy.confirm')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function PrivacyTab({ privacy, account }: { privacy: PrivacyData; account: AccountSummary }) {
    const { t } = useT();
    const [deleting, setDeleting] = useState(false);
    const blocked = privacy.blockers.length > 0;

    return (
        <div className="grid gap-5">
            <Section title={t('account.privacy.consent_title')} name="consent">
                <div className="text-muted-foreground grid gap-2 text-sm leading-relaxed">
                    <p>{t('account.privacy.consent')}</p>
                    <p>{t('account.privacy.retention')}</p>
                </div>
            </Section>

            <Section title={t('account.privacy.export_title')} description={t('account.privacy.export_text')} name="export">
                <Button asChild variant="outline" className="h-11 w-full sm:w-fit">
                    <a href={privacy.export_url} download data-export>
                        <Download /> {t('account.privacy.export')}
                    </a>
                </Button>
            </Section>

            {account.deletion ? (
                <Section title={t('account.privacy.scheduled_title')} tone="danger" name="scheduled">
                    <p className="text-sm" data-deletion-date>
                        {t('account.privacy.scheduled_text', { date: account.deletion.date })}
                    </p>
                    <Button className="h-11 w-full sm:w-fit" onClick={() => router.delete('/account/deletion', { preserveScroll: true })} data-deletion-cancel>
                        {t('account.privacy.cancel')}
                    </Button>
                </Section>
            ) : (
                <Section title={t('account.privacy.delete_title')} description={t('account.privacy.delete_text', { days: privacy.grace_days })} tone="danger" name="delete">
                    {blocked && (
                        <ul className="grid gap-2" data-deletion-blocked>
                            {privacy.blockers.map((b) => (
                                <li key={b} className="flex items-start gap-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-950">
                                    <ShieldAlert className="mt-0.5 size-4 shrink-0" />
                                    {t(`account.privacy.blocked.${b}`)}
                                </li>
                            ))}
                        </ul>
                    )}
                    <Button variant="destructive" className="h-11 w-full sm:w-fit" disabled={blocked} onClick={() => setDeleting(true)} data-delete-open>
                        <Trash2 /> {t('account.privacy.delete')}
                    </Button>
                    <DeleteDialog open={deleting} onOpenChange={setDeleting} privacy={privacy} email={account.email} />
                </Section>
            )}
        </div>
    );
}
