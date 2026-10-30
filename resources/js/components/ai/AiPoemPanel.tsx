import { Gem, Loader2, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { Field } from '@/components/wizard/fields';
import { useAiGeneration } from '@/hooks/useAiGeneration';
import { useT } from '@/lib/i18n';

const TONES = ['formal', 'warm', 'playful'] as const;
const DIALECTS = ['fusha', 'gulf', 'levantine', 'egyptian'] as const;

/**
 * "Write a poem with AI" in the intro_line editor (phase 7B, paid add-on
 * "ai_poem"): the poem comes back as an editable suggestion; "Use this poem"
 * puts it in the section as verse.
 */
export function AiPoemPanel({ eventId, locale, signedIn, onInsert }: { eventId: string; locale: 'ar' | 'en'; signedIn: boolean; onInsert: (poem: { text: string; caption?: string }) => void }) {
    const { t } = useT();
    const [open, setOpen] = useState(false);
    const [tone, setTone] = useState<(typeof TONES)[number]>('warm');
    const [dialect, setDialect] = useState<(typeof DIALECTS)[number]>('fusha');
    const [draft, setDraft] = useState<string | null>(null);
    const ai = useAiGeneration<{ text: string; caption?: string }>();

    const write = async () => {
        setDraft(null);
        await ai.start(`/events/${eventId}/ai/poem`, { tone, language: locale, ...(locale === 'ar' ? { dialect } : {}) });
    };
    const poem = draft ?? ai.result?.text ?? null;

    if (!open) {
        return (
            <Button type="button" variant="secondary" className="h-11 w-fit" onClick={() => setOpen(true)} data-ai-poem>
                <Sparkles /> {t('ai.poem.button')}
            </Button>
        );
    }

    return (
        <div className="border-primary/30 bg-primary/5 grid gap-3 rounded-xl border p-3" data-ai-poem-panel>
            <div className="grid gap-1">
                <h3 className="flex items-center gap-2 font-semibold">
                    <Sparkles className="text-primary size-4" /> {t('ai.poem.title')}
                </h3>
                <p className="text-muted-foreground text-xs">{t('ai.poem.intro')}</p>
            </div>
            {!signedIn ? (
                <p className="text-sm">{t('ai.sign_in')}</p>
            ) : poem !== null ? (
                <div className="grid gap-2" data-ai-suggestion>
                    <Textarea dir="auto" rows={6} maxLength={2000} className="text-base leading-loose" value={poem} onChange={(e) => setDraft(e.target.value)} />
                    <p className="text-muted-foreground text-xs">{t('ai.review_note')}</p>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            className="h-11"
                            disabled={!poem.trim()}
                            onClick={() => {
                                onInsert({ text: poem.trim(), caption: ai.result?.caption });
                                toast.success(t('ai.poem.inserted'));
                                setOpen(false);
                                setDraft(null);
                                ai.reset();
                            }}
                            data-ai-accept="poem"
                        >
                            {t('ai.poem.insert')}
                        </Button>
                        <Button type="button" variant="ghost" className="h-11" onClick={write} disabled={ai.busy}>
                            {t('ai.retry')}
                        </Button>
                    </div>
                </div>
            ) : (
                <>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label={t('ai.story.tone')}>
                            <NativeSelect value={tone} onChange={(e) => setTone(e.target.value as typeof tone)}>
                                {TONES.map((v) => (
                                    <option key={v} value={v}>
                                        {t(`ai.story.tones.${v}`)}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        {locale === 'ar' && (
                            <Field label={t('ai.story.dialect')}>
                                <NativeSelect value={dialect} onChange={(e) => setDialect(e.target.value as typeof dialect)}>
                                    {DIALECTS.map((v) => (
                                        <option key={v} value={v}>
                                            {t(`ai.story.dialects.${v}`)}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                        )}
                    </div>
                    {ai.error && (
                        <div className="grid gap-2" role="alert">
                            <p className="text-destructive text-sm">{ai.error.message}</p>
                            {ai.error.reason === 'addon' && (
                                <Button asChild variant="outline" className="h-10 w-fit">
                                    <a href={`/dashboard/events/${eventId}/upgrade`}>
                                        <Gem /> {t('ai.poem.locked_cta')}
                                    </a>
                                </Button>
                            )}
                        </div>
                    )}
                    <div className="flex gap-2">
                        <Button type="button" className="h-11" onClick={write} disabled={ai.busy}>
                            {ai.busy ? <Loader2 className="animate-spin" /> : <Sparkles />}
                            {ai.busy ? t('ai.working') : t('ai.poem.write')}
                        </Button>
                        <Button type="button" variant="ghost" className="h-11" onClick={() => setOpen(false)}>
                            {t('ai.cancel')}
                        </Button>
                    </div>
                </>
            )}
        </div>
    );
}
