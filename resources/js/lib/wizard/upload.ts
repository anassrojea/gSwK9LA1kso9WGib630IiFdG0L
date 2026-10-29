import axios from 'axios';

export const MAX_EDGE = 2560;
export const LIMITS_MB = { image: 10, video: 50, audio: 10 } as const;

export type UploadType = keyof typeof LIMITS_MB;

export interface Uploaded {
    id: string;
    ref: string;
    url: string;
    kind: 'photo' | 'video' | 'audio';
}

/**
 * Shrink a photo on the device before upload (plan section 11): at most
 * 2560 px on the long edge, re-encoded as WebP (JPEG where the browser
 * cannot encode WebP). GIFs and anything the canvas cannot read pass as-is.
 */
export async function compressImage(file: File, maxEdge = MAX_EDGE, quality = 0.85): Promise<File> {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) return file;

    let bitmap: ImageBitmap;
    try {
        bitmap = await createImageBitmap(file);
    } catch {
        return file;
    }

    const scale = Math.min(1, maxEdge / Math.max(bitmap.width, bitmap.height));
    const width = Math.round(bitmap.width * scale);
    const height = Math.round(bitmap.height * scale);
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext('2d');
    if (!context) return file;
    context.drawImage(bitmap, 0, 0, width, height);
    bitmap.close();

    const encode = (type: string) => new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, type, quality));
    let blob = await encode('image/webp');
    if (!blob || blob.type !== 'image/webp') blob = await encode('image/jpeg');
    if (!blob) return file;

    // never make a small file bigger
    if (blob.size >= file.size && scale === 1) return file;

    const extension = blob.type === 'image/webp' ? 'webp' : 'jpg';
    return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.' + extension, { type: blob.type });
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
