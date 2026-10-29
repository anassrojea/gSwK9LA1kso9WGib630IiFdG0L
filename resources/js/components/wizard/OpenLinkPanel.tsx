import axios from 'axios';
import { Check, Copy, Link2, Send } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { copyText } from '@/lib/clipboard';
import { useT } from '@/lib/i18n';
import type { OpenLinkSettings } from '@/types/wizard';

/**
 * The open link (one link for everyone, with a people cap) on the wizard's
 * review step. Talks JSON to GET/PUT /events/{event}/open-link, so the event
 * dashboard can reuse it as is.
 */
export function OpenLinkPanel({ initial, title, onChange }: { initial: OpenLinkSettings; title: string; onChange?: (link: OpenLinkSettings) => void }) {
    const { t, n } = useT();
    const [link, setLink] = useState(initial);
    const [cap, setCap] = useState(String(initial.cap ?? Math.min(50, initial.max_cap)));
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);

    const save = async (body: { enabled?: boolean; cap?: number }) => {
        setBusy(true);
        setError(null);
        try {
            const { data } = await axios.put<OpenLinkSettings>(link.endpoint, body, { headers: { Accept: 'application/json' } });
            setLink(data);
            onChange?.(data);
            if (data.cap) setCap(String(data.cap));
            return true;
        } catch (e) {
            const response = axios.isAxiosError(e) ? e.response : undefined;
            const first = response?.data?.errors ? (Object.values(response.data.errors)[0] as string[])[0] : response?.data?.message;
            setError(first || t('wizard.review.open_link.error'));
            return false;
        } finally {
            setBusy(false);
        }
    };

    const capNumber = Number(cap);
    const capValid = Number.isInteger(capNumber) && capNumber >= 1 && capNumber <= link.max_cap;
    const whatsapp = link.url ? `https://wa.me/?text=${encodeURIComponent(t('wizard.review.open_link.whatsapp_message', { title, link: link.url }))}` : '';

    if (!link.available) return null;

    return (
        <section className="bg-card grid gap-3 rounded-xl border p-4" data-open-link>
            <div className="flex items-start justify-between gap-3">
                <div className="grid gap-1">
                    <h2 className="flex items-center gap-2 font-bold">
                        <Link2 className="size-4" /> {t('wizard.review.open_link.title')}
                    </h2>
                    <p className="text-muted-foreground text-sm">{t('wizard.review.open_link.text')}</p>
                </div>
                <Switch
                    checked={link.enabled}
                    disabled={busy || (!link.enabled && !capValid)}
                    onCheckedChange={(enabled) => save(enabled ? { enabled, cap: capNumber } : { enabled })}
                    aria-label={t('wizard.review.open_link.enable')}
                    data-open-link-toggle
                />
            </div>

            <form
                className="grid gap-1.5"
                onSubmit={async (e) => {
                    e.preventDefault();
                    if (capValid && (await save({ cap: capNumber }))) toast.success(t('wizard.review.open_link.saved'));
                }}
            >
                <label htmlFor="open-link-cap" className="text-sm font-semibold">
                    {t('wizard.review.open_link.cap')}
                </label>
                <div className="flex gap-2">
                    <Input
                        id="open-link-cap"
                        type="number"
                        inputMode="numeric"
                        min={1}
                        max={link.max_cap}
                        value={cap}
                        onChange={(e) => setCap(e.target.value)}
                        className="h-11 w-32"
                        dir="ltr"
                        data-open-link-cap
                    />
                    <Button type="submit" variant="outline" className="h-11" disabled={busy || !capValid || capNumber === link.cap}>
                        {t('wizard.review.open_link.save')}
                    </Button>
                </div>
                <p className="text-muted-foreground text-xs">
                    {t('wizard.review.open_link.cap_hint', { max: link.max_cap, per: link.max_per_reply })}
                </p>
                {error && (
                    <p className="text-destructive text-sm" role="alert">
                        {error}
                    </p>
                )}
            </form>

            {link.enabled && link.url && (
                <div className="grid gap-2">
                    <code className="bg-muted truncate rounded px-2 py-1.5 text-xs" dir="ltr" data-open-link-url>
                        {link.url}
                    </code>
                    {link.published ? (
                        <div className="grid grid-cols-2 gap-2">
                            <Button
                                variant="outline"
                                className="h-11"
                                onClick={async () => {
                                    if (link.url && (await copyText(link.url))) {
                                        setCopied(true);
                                        setTimeout(() => setCopied(false), 1800);
                                    }
                                }}
                            >
                                {copied ? <Check /> : <Copy />} {copied ? t('wizard.review.copied') : t('wizard.review.copy')}
                            </Button>
                            <Button asChild className="h-11 bg-[#1f9d55] text-white hover:bg-[#1a8a4a]">
                                <a href={whatsapp} target="_blank" rel="noopener">
                                    <Send /> {t('wizard.review.whatsapp')}
                                </a>
                            </Button>
                        </div>
                    ) : (
                        <p className="text-muted-foreground text-sm">{t('wizard.review.open_link.after_publish')}</p>
                    )}
                    {link.cap !== null && (
                        <p className="text-muted-foreground flex flex-wrap gap-x-3 text-sm" data-open-link-stats>
                            <span>{t('wizard.review.open_link.confirmed', { people: link.people, cap: link.cap })}</span>
                            <span>{t('wizard.review.open_link.left', { left: link.seats_left ?? 0 })}</span>
                            <span>{t('wizard.review.open_link.replies', { count: link.replies })}</span>
                            <span>{t('wizard.review.open_link.opens', { count: n(link.opens) })}</span>
                        </p>
                    )}
                </div>
            )}
        </section>
    );
}
