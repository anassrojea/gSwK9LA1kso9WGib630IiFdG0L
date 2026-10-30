import { Head } from '@inertiajs/react';
import { Check } from 'lucide-react';

import { MarketplaceGrid, type MarketplaceCard } from '@/components/marketplace/MarketplaceTemplates';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { TextField, patch } from '@/components/wizard/fields';
import { TierBadge } from '@/components/wizard/TierBadge';
import WizardLayout from '@/components/wizard/WizardLayout';
import { useAutosave } from '@/hooks/useAutosave';
import type { InvitationConfig } from '@/lib/config-schema';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Tier, WizardProps } from '@/types/wizard';

interface TemplateCard {
    id: string;
    occasion: string;
    name: string;
    tier: Tier;
    opening: InvitationConfig['opening'];
    theme: InvitationConfig['theme'] | null;
    effects: InvitationConfig['effects'] | null;
}

interface Props extends WizardProps {
    templates: TemplateCard[];
    openings: { id: InvitationConfig['opening']['id']; name: string; tier: Tier }[];
    themes: { id: string; name: string; tier: Tier; config: InvitationConfig['theme'] }[];
    /** designers' templates on sale (phase 8C, MarketplaceCatalog::forWizard) */
    marketplace?: MarketplaceCard[];
}

/** One Google Fonts request for the display and label fonts of every design shown. */
function fontsHref(themes: (InvitationConfig['theme'] | null)[]): string {
    const families = new Set<string>();
    themes.forEach((theme) => theme && [theme.fonts.display, theme.fonts.label].forEach((f) => families.add(f)));
    const query = [...families].map((f) => 'family=' + f.replace(/ /g, '+')).join('&');
    return `https://fonts.googleapis.com/css2?${query}&display=swap`;
}

function Swatches({ theme }: { theme: InvitationConfig['theme'] | null }) {
    if (!theme) return null;
    const keys = ['bg', 'accent', 'gold', 'ink', 'soft'];
    return (
        <span className="flex -space-x-1.5 rtl:space-x-reverse" aria-hidden>
            {keys.map((k) => (
                <span key={k} className="size-6 rounded-full border-2 border-white shadow-sm" style={{ background: theme.colors[k] }} />
            ))}
        </span>
    );
}

/** A small poster of the design in its own colors and fonts. */
function Poster({ theme, title, subtitle }: { theme: InvitationConfig['theme'] | null; title: string; subtitle?: string }) {
    const c = theme?.colors ?? {};
    return (
        <div
            className="relative grid aspect-[4/5] w-full place-items-center overflow-hidden rounded-xl"
            style={{ background: `radial-gradient(circle at 50% 35%, ${c.surface ?? c.bg}, ${c.backdrop ?? c.accent})` }}
        >
            <div className="grid gap-1 rounded-lg px-4 py-6 text-center shadow-sm" style={{ background: c.bg, color: c.ink, borderRadius: theme?.radius ?? 12 }}>
                <span className="text-2xl leading-tight" style={{ fontFamily: `'${theme?.fonts.display}', serif`, color: c.accent }}>
                    {title}
                </span>
                {subtitle && (
                    <span className="text-[0.7rem]" style={{ fontFamily: `'${theme?.fonts.label}', sans-serif`, color: c.soft }}>
                        {subtitle}
                    </span>
                )}
                <span className="mx-auto mt-1 h-px w-10" style={{ background: c.gold }} />
            </div>
        </div>
    );
}

function Pick({ selected, onClick, children, label }: { selected: boolean; onClick: () => void; children: React.ReactNode; label: string }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={selected}
            aria-label={label}
            className={cn(
                'bg-card relative grid gap-2 rounded-2xl border-2 p-2 text-start shadow-xs transition-all hover:shadow-md focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                selected ? 'border-primary' : 'border-transparent',
            )}
        >
            {selected && (
                <span className="bg-primary text-primary-foreground absolute end-3 top-3 z-10 grid size-7 place-items-center rounded-full shadow">
                    <Check className="size-4" />
                </span>
            )}
            {children}
        </button>
    );
}

/**
 * Step 2: a template (opening + theme together), or the opening and theme on
 * their own (plan 6.1). Sections and the host's text are never touched.
 */
export default function Design(props: Props) {
    const { t, sep, dir, locale } = useT();
    const autosave = useAutosave(props.urls.config, props.config);
    const { config } = autosave;
    const names = config.sections.find((s) => s.type === 'names');
    const sample = names && names.type === 'names' ? [names.first, names.second].filter(Boolean).join(sep) : t(`wizard.occasions.${config.occasion}`);

    const forOccasion = props.templates.filter((tpl) => tpl.occasion === config.occasion);
    const others = props.templates.filter((tpl) => tpl.occasion !== config.occasion);

    const marketplace = props.marketplace ?? [];
    // a marketplace design is the designer's own theme: it is selected when the config uses that theme
    const currentMarketplace = marketplace.find((card) => card.theme?.id === config.theme.id)?.id ?? null;

    const applyTemplate = (tpl: Pick<TemplateCard, 'id' | 'opening' | 'theme' | 'effects'>) =>
        autosave.update(
            (c) => ({
                ...c,
                opening: tpl.opening,
                theme: tpl.theme ?? c.theme,
                effects: { ...(tpl.effects ?? {}), ...(c.effects?.music !== undefined ? { music: c.effects.music } : {}) },
            }),
            { template_id: tpl.id },
        );

    const currentTemplate = props.templates.find((tpl) => tpl.opening.id === config.opening.id && tpl.theme?.id === config.theme.id);
    const options = config.opening.options ?? {};
    const setOption = (key: keyof NonNullable<InvitationConfig['opening']['options']>, value: string) =>
        autosave.update((c) => ({ ...c, opening: { ...c.opening, options: patch(c.opening.options ?? {}, { [key]: value }) } }));

    const templateGrid = (list: TemplateCard[]) => (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
            {list.map((tpl) => (
                <Pick key={tpl.id} selected={currentTemplate?.id === tpl.id} onClick={() => applyTemplate(tpl)} label={tpl.name}>
                    <Poster theme={tpl.theme} title={tpl.name} subtitle={t(`wizard.openings.${tpl.opening.id}`)} />
                    <div className="flex flex-wrap items-center justify-between gap-1 px-1 pb-1">
                        <span className="font-semibold">{tpl.name}</span>
                        <TierBadge tier={tpl.tier} />
                    </div>
                </Pick>
            ))}
        </div>
    );

    return (
        <WizardLayout
            step="design"
            eventId={props.event.id}
            title={t('wizard.design.title')}
            subtitle={t('wizard.design.subtitle')}
            saveState={autosave.state}
            previewUrl={props.urls.preview}
            previewVersion={autosave.savedCount}
            beforeLeave={autosave.flush}
        >
            <Head>
                <link rel="stylesheet" href={fontsHref([...props.themes.map((th) => th.config), ...props.templates.map((tpl) => tpl.theme), ...marketplace.map((c) => c.theme), config.theme])} />
            </Head>
            <Tabs defaultValue="templates" dir={dir}>
                <TabsList>
                    <TabsTrigger value="templates">{t('wizard.design.templates')}</TabsTrigger>
                    <TabsTrigger value="openings">{t('wizard.design.openings')}</TabsTrigger>
                    <TabsTrigger value="themes">{t('wizard.design.themes')}</TabsTrigger>
                    {marketplace.length > 0 && <TabsTrigger value="marketplace">{t('marketplace.wizard.tab')}</TabsTrigger>}
                </TabsList>

                <TabsContent value="templates" className="grid gap-6">
                    {forOccasion.length > 0 ? templateGrid(forOccasion) : <p className="text-muted-foreground text-sm">{t('wizard.design.none_for_occasion')}</p>}
                    {others.length > 0 && (
                        <section className="grid gap-3">
                            <h2 className="text-muted-foreground text-sm font-semibold">{t('wizard.design.other_templates')}</h2>
                            {templateGrid(others)}
                        </section>
                    )}
                    <p className="text-muted-foreground text-xs">{t('wizard.design.keeps_content')}</p>
                </TabsContent>

                {marketplace.length > 0 && (
                    <TabsContent value="marketplace" className="grid gap-4">
                        <p className="text-muted-foreground text-sm">{t('marketplace.wizard.intro')}</p>
                        <MarketplaceGrid
                            cards={[...marketplace.filter((c) => c.occasion === config.occasion), ...marketplace.filter((c) => c.occasion !== config.occasion)]}
                            selected={currentMarketplace}
                            onPick={applyTemplate}
                        />
                        <a href={locale === 'ar' ? '/templates' : '/en/templates'} target="_blank" rel="noreferrer" className="text-primary text-sm font-semibold">
                            {t('marketplace.wizard.browse')}
                        </a>
                    </TabsContent>
                )}

                <TabsContent value="openings" className="grid gap-6">
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        {props.openings.map((o) => (
                            <Pick key={o.id} selected={config.opening.id === o.id} onClick={() => autosave.update((c) => ({ ...c, opening: { ...c.opening, id: o.id } }))} label={o.name}>
                                <Poster theme={config.theme} title={o.name} subtitle={sample} />
                                <div className="flex flex-wrap items-center justify-between gap-1 px-1 pb-1">
                                    <span className="font-semibold">{o.name}</span>
                                    <TierBadge tier={o.tier} />
                                </div>
                            </Pick>
                        ))}
                    </div>
                    <section className="bg-card grid gap-4 rounded-xl border p-4">
                        <h2 className="font-semibold">{t('wizard.design.opening_text')}</h2>
                        <TextField label={t('wizard.design.kicker')} value={options.kicker} maxLength={60} onChange={(v) => setOption('kicker', v)} error={autosave.errorAt('/opening/options/kicker')} />
                        <TextField
                            label={t('wizard.design.headline')}
                            value={options.headline}
                            maxLength={80}
                            onChange={(v) => setOption('headline', v)}
                            error={autosave.errorAt('/opening/options/headline')}
                        />
                        {config.opening.id === 'envelope' && (
                            <TextField
                                label={t('wizard.design.seal')}
                                hint={t('wizard.design.seal_hint')}
                                value={options.sealText}
                                maxLength={6}
                                onChange={(v) => setOption('sealText', v)}
                                error={autosave.errorAt('/opening/options/sealText')}
                            />
                        )}
                    </section>
                </TabsContent>

                <TabsContent value="themes">
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        {props.themes.map((theme) => (
                            <Pick key={theme.id} selected={config.theme.id === theme.id} onClick={() => autosave.update((c) => ({ ...c, theme: theme.config }))} label={theme.name}>
                                <Poster theme={theme.config} title={sample} subtitle={theme.name} />
                                <div className="flex flex-wrap items-center justify-between gap-1 px-1 pb-1">
                                    <Swatches theme={theme.config} />
                                    <TierBadge tier={theme.tier} />
                                </div>
                                <span className="px-1 pb-1 text-sm font-semibold">{theme.name}</span>
                            </Pick>
                        ))}
                    </div>
                </TabsContent>
            </Tabs>
        </WizardLayout>
    );
}
