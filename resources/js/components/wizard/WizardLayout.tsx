import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Check, Eye, X } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { toast } from 'sonner';

import { LocaleSwitch } from '@/components/LocaleSwitch';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogTitle, SheetContent } from '@/components/ui/dialog';
import { Toaster } from '@/components/ui/sonner';
import { PreviewFrame } from '@/components/wizard/PreviewFrame';
import { SaveIndicator } from '@/components/wizard/SaveIndicator';
import type { SaveState } from '@/hooks/useAutosave';
import { useMediaQuery } from '@/hooks/useMediaQuery';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';
import type { Step } from '@/types/wizard';

export const STEPS: Step[] = ['occasion', 'design', 'details', 'guests', 'review'];

export function stepUrl(eventId: string, step: Step) {
    return `/create/${eventId}/${step}`;
}

interface Props {
    step: Step;
    eventId?: string;
    title: string;
    subtitle?: string;
    children: ReactNode;
    saveState?: SaveState;
    previewUrl?: string;
    previewVersion?: number;
    /** Runs before moving to another step (flush autosave). */
    beforeLeave?: () => Promise<void>;
    next?: { label?: string; onClick?: () => void; disabled?: boolean; hidden?: boolean };
    /** Pages that manage their own wide layout (no preview column). */
    wide?: boolean;
}

/**
 * Shell of every wizard step: progress, autosave state, the live phone
 * preview beside the form on desktop and behind a "Preview" button on phones.
 */
export default function WizardLayout({ step, eventId, title, subtitle, children, saveState, previewUrl, previewVersion = 0, beforeLeave, next, wide }: Props) {
    const { t, n, dir } = useT();
    const { flash } = usePage<SharedProps>().props;
    const desktop = useMediaQuery('(min-width: 1024px)');
    const [previewOpen, setPreviewOpen] = useState(false);
    const index = STEPS.indexOf(step);
    const showPreview = !!previewUrl && !wide;

    useEffect(() => {
        if (flash?.status) toast.success(flash.status);
    }, [flash?.status]);

    const go = async (target: Step) => {
        if (!eventId) return;
        await beforeLeave?.();
        router.visit(stepUrl(eventId, target));
    };

    const Back = dir === 'rtl' ? ArrowRight : ArrowLeft;
    const Forward = dir === 'rtl' ? ArrowLeft : ArrowRight;
    const nextStep = STEPS[index + 1];

    return (
        <div className="bg-muted/40 min-h-svh">
            <Head title={title} />
            <Toaster />
            <header className="bg-background/95 sticky top-0 z-30 border-b backdrop-blur">
                <div className="mx-auto flex h-14 max-w-6xl items-center gap-3 px-4">
                    <Link href="/" className="text-primary shrink-0 text-lg font-bold">
                        {t('name')}
                    </Link>
                    <div className="min-w-0 flex-1">{saveState && <SaveIndicator state={saveState} />}</div>
                    <LocaleSwitch />
                </div>
                <nav aria-label={t('wizard.steps_label')} className="mx-auto max-w-6xl px-4 pb-3">
                    <ol className="flex items-center gap-1.5">
                        {STEPS.map((s, i) => {
                            const done = i < index;
                            const current = i === index;
                            const reachable = !!eventId && s !== step;
                            return (
                                <li key={s} className="flex flex-1 items-center gap-1.5">
                                    <button
                                        type="button"
                                        disabled={!reachable}
                                        onClick={() => go(s)}
                                        aria-current={current ? 'step' : undefined}
                                        className={cn(
                                            'flex min-h-9 w-full items-center gap-2 rounded-full text-xs font-medium transition-colors disabled:cursor-default',
                                            current ? 'text-primary' : done ? 'text-foreground' : 'text-muted-foreground',
                                        )}
                                    >
                                        <span
                                            className={cn(
                                                'grid size-7 shrink-0 place-items-center rounded-full border text-xs',
                                                current && 'border-primary bg-primary text-primary-foreground',
                                                done && 'border-primary/40 bg-primary/10 text-primary',
                                            )}
                                        >
                                            {done ? <Check className="size-3.5" /> : n(i + 1)}
                                        </span>
                                        <span className={cn('truncate', !current && 'hidden md:inline')}>{t(`wizard.steps.${s}`)}</span>
                                    </button>
                                </li>
                            );
                        })}
                    </ol>
                </nav>
            </header>

            <div className={cn('mx-auto grid max-w-6xl gap-8 px-4 pt-6 pb-32 lg:pb-12', showPreview && 'lg:grid-cols-[minmax(0,1fr)_400px]')}>
                <main className="min-w-0">
                    <div className="mb-6 grid gap-1.5">
                        <p className="text-muted-foreground text-xs font-semibold tracking-wide">{t('wizard.step_of', { step: index + 1, total: STEPS.length })}</p>
                        <h1 className="text-2xl leading-tight font-bold">{title}</h1>
                        {subtitle && <p className="text-muted-foreground">{subtitle}</p>}
                    </div>
                    {children}

                    {/* desktop navigation sits under the form */}
                    {eventId && (
                        <div className="mt-10 hidden items-center justify-between gap-3 lg:flex">
                            {index > 0 ? (
                                <Button variant="outline" size="lg" className="h-12" onClick={() => go(STEPS[index - 1])}>
                                    <Back /> {t('wizard.back')}
                                </Button>
                            ) : (
                                <span />
                            )}
                            {nextStep && !next?.hidden && (
                                <Button size="lg" className="h-12 min-w-40 text-base" disabled={next?.disabled} onClick={next?.onClick ?? (() => go(nextStep))}>
                                    {next?.label ?? t('wizard.next')} <Forward />
                                </Button>
                            )}
                        </div>
                    )}
                </main>

                {showPreview && desktop && (
                    <aside className="hidden lg:block">
                        <div className="sticky top-32 grid justify-items-center gap-3">
                            <div className="h-[min(760px,calc(100svh-10rem))] w-[375px] overflow-hidden rounded-[2.25rem] border-[10px] border-neutral-900 bg-neutral-900 shadow-xl">
                                <PreviewFrame url={previewUrl} version={previewVersion} className="rounded-[1.6rem]" />
                            </div>
                            <a href={previewUrl} target="_blank" rel="noopener" className="text-muted-foreground text-xs underline-offset-4 hover:underline">
                                {t('wizard.preview.open_tab')}
                            </a>
                        </div>
                    </aside>
                )}
            </div>

            {/* phones: big buttons always in reach */}
            {eventId && (
                <div className="bg-background/95 fixed inset-x-0 bottom-0 z-30 border-t p-3 backdrop-blur lg:hidden" style={{ paddingBottom: 'max(0.75rem, env(safe-area-inset-bottom))' }}>
                    <div className="mx-auto flex max-w-xl items-center gap-2">
                        {index > 0 && (
                            <Button variant="outline" className="h-12 px-3" onClick={() => go(STEPS[index - 1])} aria-label={t('wizard.back')}>
                                <Back />
                            </Button>
                        )}
                        {previewUrl && (
                            <Button variant="secondary" className="h-12 flex-1 text-base" onClick={() => setPreviewOpen(true)}>
                                <Eye /> {t('wizard.preview.button')}
                            </Button>
                        )}
                        {nextStep && !next?.hidden && (
                            <Button className="h-12 flex-[1.4] text-base" disabled={next?.disabled} onClick={next?.onClick ?? (() => go(nextStep))}>
                                {next?.label ?? t('wizard.next')} <Forward />
                            </Button>
                        )}
                    </div>
                </div>
            )}

            {previewUrl && !desktop && (
                <Dialog open={previewOpen} onOpenChange={setPreviewOpen}>
                    <SheetContent aria-describedby={undefined}>
                        <div className="flex h-14 shrink-0 items-center justify-between border-b px-4">
                            <DialogTitle>{t('wizard.preview.title')}</DialogTitle>
                            <DialogClose asChild>
                                <Button variant="ghost" size="icon" className="size-11" aria-label={t('wizard.common.close')}>
                                    <X className="size-5" />
                                </Button>
                            </DialogClose>
                        </div>
                        <div className="mx-auto w-full max-w-[430px] flex-1">{previewOpen && <PreviewFrame url={previewUrl} version={previewVersion} />}</div>
                    </SheetContent>
                </Dialog>
            )}
        </div>
    );
}
