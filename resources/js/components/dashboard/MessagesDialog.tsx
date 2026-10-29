import { useForm } from '@inertiajs/react';
import { RotateCcw } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { Field } from '@/components/wizard/fields';
import { toastFlash } from '@/lib/dashboard/flash';
import { useT } from '@/lib/i18n';
import type { DashboardProps } from '@/types/dashboard';

/**
 * The WhatsApp wording per event: one text for the invitation, one for
 * reminders. :name, :title and :link are filled in for each family.
 */
export function MessagesDialog({ open, onOpenChange, messages, url, sample, max, dir }: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    messages: DashboardProps['messages'];
    url: string;
    sample: { name: string; title: string; link: string };
    max: number;
    dir: 'rtl' | 'ltr';
}) {
    const { t } = useT();
    const form = useForm({ invite: messages.invite, reminder: messages.reminder });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch(url, {
            preserveScroll: true,
            onSuccess: (page) => {
                toastFlash(page);
                onOpenChange(false);
            },
        });
    };

    const fill = (text: string) => text.replaceAll(':name', sample.name).replaceAll(':title', sample.title).replaceAll(':link', sample.link);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent closeLabel={t('wizard.common.close')} className="max-h-[92svh] overflow-y-auto sm:max-w-xl" data-messages>
                <DialogHeader>
                    <DialogTitle>{t('dashboard.messages.title')}</DialogTitle>
                    <DialogDescription>
                        <bdi>{t('dashboard.messages.subtitle')}</bdi>
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    {(['invite', 'reminder'] as const).map((kind) => (
                        <Field key={kind} label={t(`dashboard.messages.${kind}`)} error={form.errors[kind]}>
                            <Textarea dir={dir} rows={4} maxLength={max} className="text-base" value={form.data[kind]} onChange={(e) => form.setData(kind, e.target.value)} />
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <p className="text-muted-foreground bg-muted/50 min-w-0 flex-1 rounded-md p-2 text-xs whitespace-pre-line" dir={dir}>
                                    <b>{t('dashboard.messages.preview')}: </b>
                                    {fill(form.data[kind])}
                                </p>
                                {form.data[kind] !== messages.defaults[kind] && (
                                    <Button type="button" variant="ghost" size="sm" onClick={() => form.setData(kind, messages.defaults[kind])}>
                                        <RotateCcw /> {t('dashboard.messages.reset')}
                                    </Button>
                                )}
                            </div>
                        </Field>
                    ))}
                    <Button type="submit" className="h-11" disabled={form.processing}>
                        {t('dashboard.messages.save')}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}
