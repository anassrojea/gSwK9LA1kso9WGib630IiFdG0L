/**
 * Which wallet buttons to show on the RSVP ticket (phase 7B), detected loosely:
 * an iPhone sees Apple Wallet, an Android phone Google Wallet, everything else
 * (desktops, tablets we can't tell) both. The web pass is always shown.
 */
export type Device = 'ios' | 'android' | 'other';

export function deviceFrom(userAgent: string, maxTouchPoints = 0): Device {
    if (/android/i.test(userAgent)) return 'android';
    if (/iphone|ipod|ipad/i.test(userAgent)) return 'ios';
    // iPadOS reports itself as a Mac with a touch screen
    if (/macintosh/i.test(userAgent) && maxTouchPoints > 1) return 'ios';
    return 'other';
}

export function walletPlatformsFor(device: Device): Array<'apple' | 'google'> {
    if (device === 'ios') return ['apple'];
    if (device === 'android') return ['google'];
    return ['apple', 'google'];
}
