import { MessageCircle, Store, X } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';

export interface VendorCards {
    city: string | null;
    city_name: string | null;
    items: { slug: string; name: string; category: string; featured: boolean; photo: string | null; url: string; whatsapp: string | null }[];
    all_url: string;
}

const key = (eventId: string) => `daawa.vendors-card.dismissed.${eventId}`;

/**
 * "Vendors near your venue" on the host dashboard (phase 8C): a few vendors for the
 * event's city and occasion, WhatsApp in one tap. Dismissed per event in this browser.
 */
export function VendorsCard({ eventId, vendors }: { eventId: string; vendors: VendorCards }) {
    const { t, sep } = useT();
    const [hidden, setHidden] = useState(true);

    useEffect(() => {
        try {
            setHidden(window.localStorage.getItem(key(eventId)) === '1');
        } catch {
            setHidden(false);
        }
    }, [eventId]);

    const dismiss = () => {
        setHidden(true);
        try {
            window.localStorage.setItem(key(eventId), '1');
        } catch {
            // private mode: hidden until the page reloads
        }
    };

    if (hidden) return null;

    return (
        <section className="bg-card grid gap-3 rounded-xl border p-4" aria-labelledby="vendors-card-title" data-vendors-card>
            <div className="flex items-start justify-between gap-2">
                <div className="grid gap-0.5">
                    <h2 id="vendors-card-title" className="flex items-center gap-2 font-semibold">
                        <Store className="text-primary size-5" /> {vendors.city_name ? t('vendors.card.title_city', { city: vendors.city_name }) : t('vendors.card.title')}
                    </h2>
                    <p className="text-muted-foreground text-xs">{t('vendors.card.subtitle')}</p>
                </div>
                <Button variant="ghost" size="icon" className="size-9" onClick={dismiss} aria-label={t('vendors.card.dismiss')} data-vendors-dismiss>
                    <X className="size-4" />
                </Button>
            </div>
            <ul className="grid gap-2 sm:grid-cols-2">
                {vendors.items.map((v) => (
                    <li key={v.slug} className="flex items-center gap-3 rounded-lg border p-2">
                        {v.photo ? (
                            <img src={v.photo} alt="" className="size-12 shrink-0 rounded-md object-cover" loading="lazy" />
                        ) : (
                            <span className="bg-primary/10 text-primary grid size-12 shrink-0 place-items-center rounded-md font-bold">{v.name[0]}</span>
                        )}
                        <a href={v.url} target="_blank" rel="noreferrer" className="grid min-w-0 flex-1">
                            <bdi className="truncate text-sm font-semibold">{v.name}</bdi>
                            <span className="text-muted-foreground text-xs">
                                {t(`vendors.categories.${v.category}`)}
                                {v.featured && <>{sep}{t('vendors.public.featured')}</>}
                            </span>
                        </a>
                        {v.whatsapp && (
                            <Button asChild size="sm" className="h-9 shrink-0 bg-[#128c4b] hover:bg-[#0e7a40]">
                                <a href={v.whatsapp} target="_blank" rel="noopener noreferrer" data-vendor-whatsapp={v.slug}>
                                    <MessageCircle className="size-4" /> {t('vendors.card.whatsapp')}
                                </a>
                            </Button>
                        )}
                    </li>
                ))}
            </ul>
            <a href={vendors.all_url} target="_blank" rel="noreferrer" className="text-primary text-sm font-semibold">
                {t('vendors.card.all')}
            </a>
        </section>
    );
}
