/*
 * Envelope opening (ported from docs/samples/invite-layali.html): the seal
 * fades, the flap rotates open (rotateX), the letter rises, then the
 * envelope drops away and the letter grows into the page.
 *
 * Contract shared by every opening: init(stageEl, options), render(progress).
 * Markup: resources/views/invite/openings/envelope.blade.php.
 */
import { clamp, ease, part } from './shared.js';

let els = null;

export function init(stageEl, options = {}) {
    els = {
        flap: part(stageEl, 'flap'),
        seal: part(stageEl, 'seal'),
        letter: part(stageEl, 'letter'),
        front: part(stageEl, 'front'),
        to: part(stageEl, 'to'),
        back: part(stageEl, 'back'),
        hint: part(stageEl, 'hint'),
    };

    // options.color tints the envelope, options.accentColor the seal (default: theme accent and gold)
    const section = stageEl.closest('.opening');
    if (options.color) section?.style.setProperty('--c-accent', options.color);
    if (options.accentColor) section?.style.setProperty('--c-gold', options.accentColor);

    // gold dust twinkling around the envelope
    if (!options.reducedMotion && options.effects?.sparkles !== false) {
        const dust = part(stageEl, 'dust');
        for (let i = 0; i < 26; i++) {
            const s = document.createElement('span');
            s.style.left = `${Math.random() * 100}%`;
            s.style.top = `${Math.random() * 100}%`;
            s.style.animationDelay = `${-Math.random() * 3}s`;
            dust.appendChild(s);
        }
    }
}

export function render(progress) {
    if (!els) return;
    const p = clamp(progress);
    const { flap, seal, letter, front, to, back, hint } = els;

    // phases: seal 0–.12, flap .05–.32, letter rises .32–.65, envelope falls .68–.96
    const s = clamp(p / 0.12);
    const a = ease(clamp((p - 0.05) / 0.27));
    const q = ease(clamp((p - 0.32) / 0.33));
    const f = ease(clamp((p - 0.68) / 0.28));

    hint.style.opacity = String(1 - clamp(p * 8));
    seal.style.opacity = String(1 - s);
    seal.style.transform = `scale(${1 + s * 0.4})`;
    flap.style.transform = `rotateX(${a * 180}deg)`;
    flap.style.zIndex = a > 0.5 ? '1' : '5';
    letter.style.zIndex = q > 0.97 ? '6' : '2';
    letter.style.transform = `translateY(${-62 * q * (1 - f)}%) scale(${1 + 0.28 * f})`;
    [back, front, to, flap].forEach((el) => (el.style.opacity = String(1 - f)));
    back.style.transform = front.style.transform = to.style.transform = `translateY(${f * 70}px)`;
}
