import { Loader2, Upload } from 'lucide-react';
import { useRef, useState, type ReactNode } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { LIMITS_MB, UploadError, uploadMedia, type UploadType, type Uploaded } from '@/lib/wizard/upload';

const ACCEPT: Record<UploadType, string> = {
    image: 'image/jpeg,image/png,image/webp',
    video: 'video/mp4',
    audio: 'audio/mpeg,audio/mp4,audio/x-m4a,.mp3,.m4a',
};

interface Props {
    url: string;
    type: UploadType;
    onUploaded: (file: Uploaded) => void;
    label: ReactNode;
    multiple?: boolean;
    variant?: 'outline' | 'default' | 'secondary';
    className?: string;
}

/** Picks a file, compresses photos on the device, uploads, reports progress and errors. */
export function UploadButton({ url, type, onUploaded, label, multiple, variant = 'outline', className }: Props) {
    const { t, n } = useT();
    const input = useRef<HTMLInputElement>(null);
    const [progress, setProgress] = useState<number | null>(null);

    const upload = async (files: FileList | null) => {
        if (!files?.length) return;
        for (const file of Array.from(files)) {
            // photos shrink before upload, so only videos and audio are checked here
            if (type !== 'image' && file.size > LIMITS_MB[type] * 1024 * 1024) {
                toast.error(t('wizard.media.too_large', { max: LIMITS_MB[type] }));
                continue;
            }
            setProgress(0);
            try {
                onUploaded(await uploadMedia(url, type, file, setProgress));
            } catch (error) {
                const e = error as UploadError;
                toast.error(e.status === 413 ? t('wizard.media.too_large', { max: LIMITS_MB[type] }) : e.message || t('wizard.media.failed'));
            } finally {
                setProgress(null);
            }
        }
        if (input.current) input.current.value = '';
    };

    return (
        <>
            <input ref={input} type="file" accept={ACCEPT[type]} multiple={multiple} className="sr-only" tabIndex={-1} onChange={(e) => upload(e.target.files)} data-upload={type} />
            <Button type="button" variant={variant} className={className ?? 'h-11'} disabled={progress !== null} onClick={() => input.current?.click()}>
                {progress !== null ? <Loader2 className="animate-spin" /> : <Upload />}
                {progress !== null ? t('wizard.media.uploading', { percent: n(Math.round(progress * 100)) }) : label}
            </Button>
        </>
    );
}
