import { Loader2 } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';

import { useT } from '@/lib/i18n';

type ScanError = 'denied' | 'unsupported';

interface Detector {
    detect(source: HTMLVideoElement): Promise<{ rawValue: string }[]>;
}
interface DetectorClass {
    new (options: { formats: string[] }): Detector;
    getSupportedFormats(): Promise<string[]>;
}

/**
 * The camera: the browser's own BarcodeDetector where it reads QR codes
 * (Chrome on Android), else html5-qrcode, loaded only then (iPhone Safari,
 * desktop Firefox). Calls onCode with every code it reads; the door app
 * ignores repeats.
 */
export function Scanner({ onCode, onError }: { onCode: (text: string) => void; onError: (error: ScanError) => void }) {
    const { t } = useT();
    const videoRef = useRef<HTMLVideoElement>(null);
    const fallbackId = `qr-${useId().replace(/[^a-z0-9]/gi, '')}`;
    const [mode, setMode] = useState<'loading' | 'native' | 'fallback'>('loading');
    const handlers = useRef({ onCode, onError });
    handlers.current = { onCode, onError };

    useEffect(() => {
        let stopped = false;
        let stream: MediaStream | null = null;
        let timer = 0;
        let fallback: { stop: () => Promise<void>; isScanning?: boolean } | null = null;

        const fail = (e: unknown) => {
            if (stopped) return;
            const name = (e as { name?: string })?.name ?? '';
            handlers.current.onError(name === 'NotAllowedError' || name === 'SecurityError' || String(e).includes('Permission') ? 'denied' : 'unsupported');
        };

        (async () => {
            if (!navigator.mediaDevices?.getUserMedia) return fail(new Error('unsupported'));

            const Native = (window as unknown as { BarcodeDetector?: DetectorClass }).BarcodeDetector;
            const native = Native ? await Native.getSupportedFormats().then((f) => f.includes('qr_code')).catch(() => false) : false;

            if (Native && native) {
                try {
                    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
                    if (stopped) return;
                    const video = videoRef.current!;
                    video.srcObject = stream;
                    await video.play();
                    setMode('native');
                    const detector = new Native({ formats: ['qr_code'] });
                    const tick = async () => {
                        if (stopped) return;
                        try {
                            const codes = await detector.detect(video);
                            if (codes[0]?.rawValue) handlers.current.onCode(codes[0].rawValue);
                        } catch {
                            // a frame that could not be read: try the next one
                        }
                        timer = window.setTimeout(tick, 180);
                    };
                    tick();
                } catch (e) {
                    fail(e);
                }
                return;
            }

            try {
                const { Html5Qrcode } = await import('html5-qrcode');
                if (stopped) return;
                setMode('fallback');
                const reader = new Html5Qrcode(fallbackId, { verbose: false });
                fallback = reader;
                await reader.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 220, height: 220 } }, (text) => handlers.current.onCode(text), () => {});
                if (stopped) await reader.stop().catch(() => {});
            } catch (e) {
                fail(e);
            }
        })();

        return () => {
            stopped = true;
            window.clearTimeout(timer);
            stream?.getTracks().forEach((track) => track.stop());
            if (fallback?.isScanning !== false) fallback?.stop().catch(() => {});
        };
    }, [fallbackId]);

    return (
        <div className="relative mx-auto aspect-square w-full max-w-sm overflow-hidden rounded-2xl bg-black" data-scanner={mode}>
            <video ref={videoRef} className={mode === 'native' ? 'size-full object-cover' : 'hidden'} muted playsInline />
            <div id={fallbackId} className={mode === 'fallback' ? 'size-full [&_video]:!h-full [&_video]:!w-full [&_video]:object-cover' : 'hidden'} />
            {mode === 'loading' && (
                <div className="absolute inset-0 grid place-items-center text-sm text-white/80">
                    <span className="inline-flex items-center gap-2">
                        <Loader2 className="size-4 animate-spin" /> {t('door.scan.loading')}
                    </span>
                </div>
            )}
            {mode === 'native' && (
                <div className="pointer-events-none absolute inset-[18%] rounded-2xl border-4 border-white/80 shadow-[0_0_0_999px_rgba(0,0,0,.35)]" aria-hidden />
            )}
        </div>
    );
}
