/**
 * Album helpers shared by the guest upload page (resources/js/album.js), the
 * wall and the host's album page. No imports, so the Node tests run them as is.
 * The server checks everything again (App\Services\Album\AlbumUploads).
 */
export const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
export const VIDEO_TYPES = ['video/mp4', 'video/quicktime', 'video/webm'];

export interface UploadLimits {
    photoMaxMb: number;
    video: { max_seconds: number; max_mb: number };
}

export type UploadProblem = 'bad_type' | 'too_large' | 'too_long';

export function kindOf(type: string): 'photo' | 'video' | null {
    if (PHOTO_TYPES.includes(type)) return 'photo';
    if (VIDEO_TYPES.includes(type)) return 'video';
    // HEIC from iPhones: the browser converts it when the canvas can read it, else it is refused
    if (/^image\/hei[cf]$/.test(type)) return 'photo';
    return null;
}

/**
 * What is wrong with a file before it is sent, if anything. Photos are checked
 * after compression; a video's duration comes from a <video> element (null when unknown).
 */
export function uploadProblem(file: { type: string; size: number }, duration: number | null, limits: UploadLimits): UploadProblem | null {
    const kind = kindOf(file.type);
    if (!kind) return 'bad_type';
    const maxMb = kind === 'photo' ? limits.photoMaxMb : limits.video.max_mb;
    if (file.size > maxMb * 1024 * 1024) return 'too_large';
    if (kind === 'video' && duration !== null && duration > limits.video.max_seconds + 0.5) return 'too_long';
    return null;
}

/** "3.4 MB", "820 KB", in the page's digits. */
export function formatBytes(bytes: number, locale = 'en'): string {
    const units = locale === 'ar' ? ['بايت', 'ك.ب', 'م.ب', 'غ.ب'] : ['B', 'KB', 'MB', 'GB'];
    let value = Math.max(0, bytes);
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }
    const text = new Intl.NumberFormat(locale === 'ar' ? 'ar-u-nu-arab' : 'en', { maximumFractionDigits: unit >= 2 ? 1 : 0 }).format(value);
    return `${text} ${units[unit]}`;
}

/** Wall slideshow: the next photo index, newest first, wrapping around. */
export function nextSlide(current: number, count: number): number {
    return count === 0 ? 0 : (current + 1) % count;
}

/**
 * Merge a feed into the wall's list: new ids go first (newest first), known
 * ones keep their place, at most `max` are kept.
 */
export function mergeFeed<T extends { id: string }>(current: T[], incoming: T[], max = 60): { list: T[]; added: string[] } {
    const known = new Set(current.map((item) => item.id));
    const added = incoming.filter((item) => !known.has(item.id));
    const fresh = new Map(incoming.map((item) => [item.id, item]));
    const list = [...added, ...current.map((item) => fresh.get(item.id) ?? item)].slice(0, max);
    return { list, added: added.map((item) => item.id) };
}
