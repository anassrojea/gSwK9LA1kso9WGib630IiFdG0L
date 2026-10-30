import axios from 'axios';

import { compressImage } from '@/lib/image-compress';

// the compression lives in a framework-free module shared with the guest album
export { MAX_EDGE, compressImage } from '@/lib/image-compress';

export const LIMITS_MB = { image: 10, video: 50, audio: 10 } as const;

export type UploadType = keyof typeof LIMITS_MB;

export interface Uploaded {
    id: string;
    ref: string;
    url: string;
    kind: 'photo' | 'video' | 'audio';
}

export class UploadError extends Error {
    constructor(
        message: string,
        public readonly status?: number,
    ) {
        super(message);
    }
}

/**
 * POST /events/{event}/media. Errors carry the server's (translated) message;
 * a 413 means the web server refused the size before Laravel saw it.
 */
export async function uploadMedia(url: string, type: UploadType, file: File, onProgress?: (fraction: number) => void): Promise<Uploaded> {
    const body = new FormData();
    body.append('type', type);
    body.append('file', type === 'image' ? await compressImage(file) : file);

    try {
        const { data } = await axios.post<Uploaded>(url, body, {
            headers: { Accept: 'application/json' },
            onUploadProgress: (e) => e.total && onProgress?.(e.loaded / e.total),
        });
        return data;
    } catch (error) {
        const response = axios.isAxiosError(error) ? error.response : undefined;
        const first = response?.data?.errors ? (Object.values(response.data.errors as Record<string, string[]>)[0]?.[0] ?? '') : '';
        throw new UploadError(first || response?.data?.message || '', response?.status);
    }
}

/** media:<uuid> → its URL (from the uploads list), anything else as-is. */
export function resolveMedia(ref: string | undefined, media: Record<string, { url: string }>): string | undefined {
    if (!ref) return undefined;
    return ref.startsWith('media:') ? media[ref.slice(6)]?.url : ref;
}
