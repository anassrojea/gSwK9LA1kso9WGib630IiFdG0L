import { Link } from '@inertiajs/react';
import { Check, ShoppingBag, Store } from 'lucide-react';

import { DesignPoster } from '@/components/marketplace/common';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { InvitationConfig } from '@/lib/config-schema';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export interface MarketplaceCard {
    id: string;
    occasion: string;
    name: string;
    designer: string | null;
    designer_url: string | null;
    price: string | null;
    owned: boolean;
    included: boolean;
    cover: string | null;
    opening: InvitationConfig['opening'];
    theme: InvitationConfig['theme'] | null;
    effects: InvitationConfig['effects'] | null;
    buy_url: string;
}

/** Price, "yours" or "in Signature" on a marketplace card. */
export function MarketplaceBadge({ card }: { card: MarketplaceCard }) {
    const { t } = useT();
    if (card.owned)
        return (
            <Badge variant="free" data-owned>
                <Check /> {t('marketplace.wizard.owned')}
            </Badge>
        );
    if (card.included) return <Badge variant="signature">{t('marketplace.wizard.included')}</Badge>;
    return (
        <Badge variant="premium" data-price>
            {card.price}
        </Badge>
    );
}

/**
 * The wizard's marketplace gallery (design step): designers' templates with their name and
 * price. Picking one applies it like a house template; it is bought before publishing.
 */
export function MarketplaceGrid({ cards, selected, onPick }: { cards: MarketplaceCard[]; selected: string | null; onPick: (card: MarketplaceCard) => void }) {
    const { t } = useT();
    if (cards.length === 0) return <p className="text-muted-foreground text-sm">{t('marketplace.wizard.empty')}</p>;

    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3" data-marketplace-grid>
            {cards.map((card) => (
                <div key={card.id} className={cn('bg-card relative grid gap-2 rounded-2xl border-2 p-2 shadow-xs', selected === card.id ? 'border-primary' : 'border-transparent')} data-marketplace-card={card.id}>
                    <button type="button" onClick={() => onPick(card)} aria-pressed={selected === card.id} aria-label={card.name} className="grid gap-2 text-start focus-visible:outline-none">
                        {selected === card.id && (
                            <span className="bg-primary text-primary-foreground absolute end-3 top-3 z-10 grid size-7 place-items-center rounded-full shadow">
                                <Check className="size-4" />
                            </span>
                        )}
                        <DesignPoster theme={card.theme} title={card.name} subtitle={t(`wizard.openings.${card.opening.id}`)} cover={card.cover} />
                        <span className="grid gap-1 px-1">
                            <span className="flex flex-wrap items-center justify-between gap-1">
                                <span className="font-semibold">{card.name}</span>
                                <MarketplaceBadge card={card} />
                            </span>
                            {card.designer && <span className="text-muted-foreground text-xs">{t('marketplace.wizard.by', { designer: card.designer })}</span>}
                        </span>
                    </button>
                </div>
            ))}
        </div>
    );
}

/**
 * Review step: the invitation uses a paid marketplace template the event doesn't own yet.
 */
export function MarketplaceBuyCard({ card }: { card: MarketplaceCard }) {
    const { t } = useT();

    return (
        <section className="grid gap-3 rounded-xl border border-amber-200 bg-amber-50/60 p-4 sm:grid-cols-[6rem_1fr]" data-marketplace-buy>
            <DesignPoster theme={card.theme} title={card.name} cover={card.cover} className="w-24" />
            <div className="grid content-start gap-2">
                <h2 className="flex items-center gap-2 font-bold">
                    <Store className="text-primary size-5" /> {t('marketplace.wizard.buy_title')}
                </h2>
                <p className="text-sm">
                    {t('marketplace.wizard.buy_text', { name: card.name, designer: card.designer ?? '' })} <span className="font-semibold">{card.price}</span>
                </p>
                <p className="text-muted-foreground text-xs">{t('marketplace.wizard.buy_note')}</p>
                <Button asChild className="h-11 justify-self-start">
                    <Link href={card.buy_url}>
                        <ShoppingBag /> {t('marketplace.wizard.buy', { price: card.price ?? '' })}
                    </Link>
                </Button>
            </div>
        </section>
    );
}
