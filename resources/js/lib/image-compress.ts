/**
 * Shrink a photo on the device before upload (plan section 11): at most
 * 2560 px on the long edge, re-encoded as WebP (JPEG where the browser
 * cannot encode WebP). GIFs and anything the canvas cannot read pass as-is.
 * No framework or HTTP client here: the wizard (React) and the guest album
 * (vanilla JS) both use it.
 */
export const MAX_EDGE = 2560;

/** Scaled size so the long edge is at most maxEdge (never scaled up). */
export function fitWithin(width: number, height: number, maxEdge = MAX_EDGE): { width: number; height: number; scale: number } {
    const scale = Math.min(1, maxEdge / Math.max(width, height, 1));
    return { width: Math.max(1, Math.round(width * scale)), height: Math.max(1, Math.round(height * scale)), scale };
}

export async function compressImage(file: File, maxEdge = MAX_EDGE, quality = 0.85): Promise<File> {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) return file;

    let bitmap: ImageBitmap;
    try {
        bitmap = await createImageBitmap(file);
    } catch {
        return file;
    }

    const { width, height, scale } = fitWithin(bitmap.width, bitmap.height, maxEdge);
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
