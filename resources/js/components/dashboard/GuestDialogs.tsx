import { router, useForm } from '@inertiajs/react';
import { Minus, Plus } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Field } from '@/components/wizard/fields';
import { parseTags, type GuestRow } from '@/lib/dashboard/guests';
import { useT } from '@/lib/i18n';
import { toastFlash } from '@/lib/dashboard/flash';

/**
 * Edit a family: name, seats, WhatsApp number and tags.
 */
export function EditGuestDialog({ guest, onClose, limits }: { guest: GuestRow | null; onClose: () => void; limits: { seats: number; name: number; tags: number } }) {
    return (
        <Dialog open={guest !== null} onOpenChange={(open) => !open && onClose()}>
            {guest && <EditGuestForm key={guest.id} guest={guest} onClose={onClose} limits={limits} />}
        </Dialog>
    );
}

function EditGuestForm({ guest, onClose, limits }: { guest: GuestRow; onClose: () => void; limits: { seats: number; name: number; tags: number } }) {
    const { t, n } = useT();
    const form = useForm({ name: guest.name, seats: guest.seats, phone: guest.phone ?? '', tags: guest.tags.join('، ') });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({ ...data, tags: parseTags(data.tags, limits.tags), phone: data.phone.trim() || null }));
        form.patch(`/dashboard/households/${guest.id}`, {
            preserveScroll: true,
            onSuccess: (page) => {
                toastFlash(page);
                onClose();
            },
        });
    };

    const seats = Number(form.data.seats) || 1;
    const tagError = Object.entries(form.errors).find(([key]) => key.startsWith('tags'))?.[1];

    return (
        <DialogContent closeLabel={t('dashboard.edit.cancel')}>
            <DialogHeader>
                <DialogTitle>{t('dashboard.edit.title')}</DialogTitle>
                <DialogDescription>
                    <bdi>{guest.name}</bdi>
                </DialogDescription>
            </DialogHeader>
            <form onSubmit={submit} className="grid gap-4" data-edit-guest>
                <Field label={t('dashboard.edit.name')} htmlFor="edit-name" error={form.errors.name}>
                    <Input id="edit-name" className="h-11" dir="auto" value={form.data.name} maxLength={limits.name} onChange={(e) => form.setData('name', e.target.value)} required />
                </Field>
                <Field label={t('dashboard.edit.seats')} htmlFor="edit-seats" error={form.errors.seats}>
                    <div className="flex items-center gap-2">
                        <Button type="button" variant="outline" size="icon" className="size-11" disabled={seats <= 1} onClick={() => form.setData('seats', seats - 1)} aria-label={t('wizard.guests.fewer')}>
                            <Minus />
                        </Button>
                        <Input
                            id="edit-seats"
                            className="h-11 w-20 text-center text-base font-semibold"
                            inputMode="numeric"
                            value={n(form.data.seats)}
                            onChange={(e) => {
                                const value = Number(e.target.value.replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660)).replace(/\D/g, ''));
                                form.setData('seats', value || 1);
                            }}
                            aria-invalid={!!form.errors.seats}
                        />
                        <Button type="button" variant="outline" size="icon" className="size-11" disabled={seats >= limits.seats} onClick={() => form.setData('seats', seats + 1)} aria-label={t('wizard.guests.more')}>
                            <Plus />
                        </Button>
                    </div>
                </Field>
                <Field label={t('dashboard.edit.phone')} htmlFor="edit-phone" hint={t('dashboard.edit.phone_hint')} error={form.errors.phone}>
                    <Input id="edit-phone" className="h-11" dir="ltr" type="tel" inputMode="tel" autoComplete="off" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                </Field>
                <Field label={t('dashboard.edit.tags')} htmlFor="edit-tags" hint={t('dashboard.edit.tags_hint')} error={tagError}>
                    <Input id="edit-tags" className="h-11" dir="auto" value={form.data.tags} onChange={(e) => form.setData('tags', e.target.value)} />
                </Field>
                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <Button type="button" variant="outline" className="h-11" onClick={onClose}>
                        {t('dashboard.edit.cancel')}
                    </Button>
                    <Button type="submit" className="h-11" disabled={form.processing || !form.data.name.trim()}>
                        {t('dashboard.edit.save')}
                    </Button>
                </div>
            </form>
        </DialogContent>
    );
}

export function DeleteGuestDialog({ guest, onClose }: { guest: GuestRow | null; onClose: () => void }) {
    const { t } = useT();

    const confirm = () => {
        if (!guest) return;
        router.delete(`/dashboard/households/${guest.id}`, {
            preserveScroll: true,
            onSuccess: (page) => {
                toastFlash(page);
                onClose();
            },
        });
    };

    return (
        <Dialog open={guest !== null} onOpenChange={(open) => !open && onClose()}>
            {guest && (
                <DialogContent closeLabel={t('dashboard.edit.cancel')}>
                    <DialogHeader>
                        <DialogTitle>{t('dashboard.delete.title', { name: guest.name })}</DialogTitle>
                        <DialogDescription>{t('dashboard.delete.text')}</DialogDescription>
                    </DialogHeader>
                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <Button variant="outline" className="h-11" onClick={onClose}>
                            {t('dashboard.edit.cancel')}
                        </Button>
                        <Button variant="destructive" className="h-11" onClick={confirm} data-confirm-delete>
                            {t('dashboard.delete.confirm')}
                        </Button>
                    </div>
                </DialogContent>
            )}
        </Dialog>
    );
}
