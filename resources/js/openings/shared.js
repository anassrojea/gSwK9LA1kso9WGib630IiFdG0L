/*
 * Helpers shared by the opening modules. Every opening is a pure function of
 * scroll progress (0 → 1), so scrolling back reverses it (plan section 6.3).
 */
export const clamp = (v, a = 0, b = 1) => Math.min(b, Math.max(a, v));
export const ease = (t) => (t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2);
export const lerp = (a, b, t) => a + (b - a) * t;
export const NS = 'http://www.w3.org/2000/svg';

/** A petal pointing up from the origin: length L, half-width w. */
export const petalPath = (L, w) =>
    `M0 0 C ${-w} ${-L * 0.25}, ${-w * 1.12} ${-L * 0.85}, ${-w * 0.38} ${-L} Q 0 ${-L * 0.9} ${w * 0.38} ${-L} C ${w * 1.12} ${-L * 0.85}, ${w} ${-L * 0.25}, 0 0 Z`;

/** Deterministic pseudo-random sequence, so every guest sees the same layout. */
export function seeded(seed) {
    let s = seed;
    return () => (s = (s * 9301 + 49297) % 233280) / 233280;
}

/** Finds [data-part="name"] inside the stage and throws early if the markup changed. */
export function part(stageEl, name) {
    const el = stageEl.querySelector(`[data-part="${name}"]`);
    if (!el) throw new Error(`opening markup is missing [data-part="${name}"]`);
    return el;
}
