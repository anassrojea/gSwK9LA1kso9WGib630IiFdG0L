import { Loader2, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { Field } from '@/components/wizard/fields';
import { useAiGeneration } from '@/hooks/useAiGeneration';
import { mergeChapters, type Chapter } from '@/lib/ai';
import { useT } from '@/lib/i18n';

const TONES = ['formal', 'warm', 'playful'] as const;
const DIALECTS = ['fusha', 'gulf', 'levantine', 'egyptian'] as const;

/**
 * "Write it for me" in the story editor (phase 7B): three questions, a tone
 * and a language / dialect; the answer comes back as chapters shown as a
 * suggestion. Only "Replace" / "Add after" changes the section; the wizard's
 * autosave then saves it like any other edit.
 */
export function AiStoryButton({ eventId, chapters, locale, onAccept, signedIn }: { eventId: string; chapters: Chapter[]; locale: 'ar' | 'en'; onAccept: (chapters: Chapter[]) => void; signedIn: boolean }) {
    const { t } = useT();
    const [open, setOpen] = useState(false);
    const [answers, setAnswers] = useState({ how_met: '', moment: '', meaning: '' });
    const [tone, setTone] = useState<(typeof TONES)[number]>('warm');
    const [language, setLanguage] = useState<'ar' | 'en'>(locale);
    const [dialect, setDialect] = useState<(typeof DIALECTS)[number]>('fusha');
    const ai = useAiGeneration<{ chapters: Chapter[] }>();
    const suggestion = ai.result?.chapters ?? null;

    const write = () => ai.start(`/events/${eventId}/ai/story`, { ...answers, tone, language, ...(language === 'ar' ? { dialect } : {}) });

    const accept = (mode: 'replace' | 'append') => {
        if (!suggestion) return;
        onAccept(mergeChapters(chapters, suggestion, mode));
        toast.success(t('ai.story.accepted'));
        setOpen(false);
        ai.reset();
    };

    return (
        <>
            <Button type="button" variant="secondary" className="h-11 w-fit" onClick={() => setOpen(true)} data-ai-story>
                <Sparkles /> {t('ai.story.button')}
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent closeLabel={t('wizard.common.close')} className="max-h-[92svh] overflow-y-auto sm:max-w-xl" data-ai-story-dialog>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Sparkles className="text-primary size-5" /> {t('ai.story.title')}
                        </DialogTitle>
                        <DialogDescription>{t('ai.story.intro')}</DialogDescription>
                    </DialogHeader>

                    {!signedIn ? (
                        <p className="bg-muted rounded-lg p-3 text-sm">{t('ai.sign_in')}</p>
                    ) : suggestion ? (
                        <div className="grid gap-3" data-ai-suggestion>
                            <h3 className="font-semibold">{t('ai.story.suggestion')}</h3>
                            <ol className="grid gap-2">
                                {suggestion.map((chapter, i) => (
                                    <li key={i} className="bg-muted/50 grid gap-1 rounded-lg border p-3">
                                        <span className="flex items-baseline gap-2">
                                            {chapter.year && <span className="text-primary text-xs font-semibold">{chapter.year}</span>}
                                            <b dir="auto">{chapter.title}</b>
                                        </span>
                                        <p className="text-sm leading-relaxed" dir="auto">
                                            {chapter.text}
                                        </p>
                                    </li>
                                ))}
                            </ol>
                            <p className="text-muted-foreground text-xs">{t('ai.review_note')}</p>
                            <div className="grid gap-2 sm:grid-cols-2">
                                <Button className="h-11" onClick={() => accept('replace')} data-ai-accept="replace">
                                    {t('ai.story.replace')}
                                </Button>
                                <Button variant="outline" className="h-11" onClick={() => accept('append')} data-ai-accept="append">
                                    {t('ai.story.append')}
                                </Button>
                            </div>
                            <Button variant="ghost" className="h-10" onClick={ai.reset}>
                                {t('ai.retry')}
                            </Button>
                        </div>
                    ) : (
                        <form
                            className="grid gap-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                write();
                            }}
                        >
                            {(['how_met', 'moment', 'meaning'] as const).map((q) => (
                                <Field key={q} label={t(`ai.story.${q}`)}>
                                    <Textarea dir="auto" rows={2} maxLength={1500} value={answers[q]} placeholder={t(`ai.story.${q}_placeholder`)} onChange={(e) => setAnswers((a) => ({ ...a, [q]: e.target.value }))} data-ai-question={q} />
                                </Field>
                            ))}
                            <div className="grid gap-3 sm:grid-cols-3">
                                <Field label={t('ai.story.tone')}>
                                    <NativeSelect value={tone} onChange={(e) => setTone(e.target.value as typeof tone)}>
                                        {TONES.map((v) => (
                                            <option key={v} value={v}>
                                                {t(`ai.story.tones.${v}`)}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field label={t('ai.story.language')}>
                                    <NativeSelect value={language} onChange={(e) => setLanguage(e.target.value as 'ar' | 'en')}>
                                        {(['ar', 'en'] as const).map((v) => (
                                            <option key={v} value={v}>
                                                {t(`ai.story.languages.${v}`)}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                {language === 'ar' && (
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
                                <p className="text-destructive text-sm" role="alert">
                                    {ai.error.message}
                                </p>
                            )}
                            <Button type="submit" className="h-12" disabled={ai.busy || !Object.values(answers).some((a) => a.trim())}>
                                {ai.busy ? <Loader2 className="animate-spin" /> : <Sparkles />}
                                {ai.busy ? t('ai.working') : t('ai.story.write')}
                            </Button>
                        </form>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}
