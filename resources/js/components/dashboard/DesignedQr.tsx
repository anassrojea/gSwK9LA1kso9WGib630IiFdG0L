import { Download, Palette } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { useT } from '@/lib/i18n';

/** Initials in a circle, as an SVG data URL for the middle of the code. */
function badge(text: string, color: string): string {
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="48" fill="#fff" stroke="${color}" stroke-width="4"/><text x="50" y="52" text-anchor="middle" dominant-baseline="middle" font-family="serif" font-weight="700" font-size="${text.length > 3 ? 26 : 36}" fill="${color}">${text.replace(/[<&>"]/g, '')}</text></svg>`;
    return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
}

/**
 * A "designed QR" (plan section 9): the guest's personal link in the hosts'
 * colours with their initials in the middle. Error correction stays at H,
 * so the logo covering the centre still scans. Host side only; qr-code-styling
 * is loaded with this panel.
 */
export default function DesignedQr({ guests, title }: { guests: { name: string; link: string }[]; title: string }) {
    const { t, dir } = useT();
    const box = useRef<HTMLDivElement>(null);
    const [index, setIndex] = useState(0);
    const [color, setColor] = useState('#5A1A2C');
    const [initials, setInitials] = useState(() =>
        title
            .split(/\s+/)
            .filter((w) => w.length > 1)
            .slice(-2)
            .map((w) => w[0])
            .join(' & ')
            .slice(0, 7),
    );
    const [qr, setQr] = useState<{ update: (o: object) => void; append: (el: HTMLElement) => void; download: (o: object) => Promise<void> } | null>(null);
    const options = useMemo(
        () => ({
            width: 220,
            height: 220,
            type: 'svg',
            data: guests[index]?.link ?? '',
            margin: 4,
            qrOptions: { errorCorrectionLevel: 'H' },
            dotsOptions: { type: 'rounded', color },
            cornersSquareOptions: { type: 'extra-rounded', color },
            cornersDotOptions: { type: 'dot', color },
            backgroundOptions: { color: '#ffffff' },
            image: initials.trim() ? badge(initials.trim(), color) : undefined,
            imageOptions: { margin: 3, imageSize: 0.32, hideBackgroundDots: true },
        }),
        [guests, index, color, initials],
    );

    useEffect(() => {
        let cancelled = false;
        import('qr-code-styling').then(({ default: QRCodeStyling }) => {
            if (cancelled || !box.current) return;
            const instance = new QRCodeStyling(options as never) as unknown as NonNullable<typeof qr>;
            box.current.replaceChildren();
            instance.append(box.current);
            setQr(instance);
        });
        return () => {
            cancelled = true;
        };
        // created once; later changes go through update()
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => qr?.update(options), [qr, options]);

    return (
        <section className="grid gap-3 rounded-xl border p-3 lg:col-span-2" aria-labelledby="designed-qr" data-designed-qr>
            <h3 id="designed-qr" className="flex items-center gap-2 font-bold">
                <Palette className="text-primary size-4" /> {t('seating.qr.designed')}
            </h3>
            <p className="text-muted-foreground text-sm">{t('seating.qr.designed_hint')}</p>
            <div className="grid items-center gap-4 sm:grid-cols-[1fr_auto]">
                <div className="grid gap-2">
                    <div className="grid gap-1">
                        <Label htmlFor="qr-guest">{t('seating.qr.for')}</Label>
                        <NativeSelect id="qr-guest" dir={dir} value={index} onChange={(e) => setIndex(Number(e.target.value))}>
                            {guests.map((g, i) => (
                                <option key={g.link} value={i}>
                                    {g.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid grid-cols-[1fr_auto] gap-2">
                        <div className="grid gap-1">
                            <Label htmlFor="qr-initials">{t('seating.qr.initials')}</Label>
                            <Input id="qr-initials" value={initials} maxLength={7} onChange={(e) => setInitials(e.target.value)} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="qr-color" className="sr-only">
                                {t('seating.qr.color')}
                            </Label>
                            <input id="qr-color" type="color" value={color} onChange={(e) => setColor(e.target.value)} className="mt-6 h-11 w-14 cursor-pointer rounded-md border" />
                        </div>
                    </div>
                    <Button variant="outline" className="w-fit" disabled={!qr} onClick={() => qr?.download({ name: 'daawa-qr', extension: 'png' })}>
                        <Download /> {t('seating.qr.download')}
                    </Button>
                </div>
                <div ref={box} className="mx-auto size-[220px] overflow-hidden rounded-lg border bg-white" />
            </div>
        </section>
    );
}
