/*
 * Ring box opening (ported from docs/samples/invite-khutuba.html): the velvet
 * lid swings open (rotateX), the ring rises with a glow and a sparkle, then
 * the box drops away and the names appear.
 *
 * options.color tints the velvet (default: theme accent), options.accentColor
 * the metal (default: theme gold). Markup: resources/views/invite/openings/ringbox.blade.php.
 */
import { applyColors, clamp, ease, part, scatter } from './shared.js';

let els = null;

export function init(stageEl, options = {}) {
    applyColors(stageEl, options);
    if (!options.reducedMotion && options.effects?.sparkles !== false) scatter(part(stageEl, 'stars'), 40);

    els = Object.fromEntries(['lid', 'ring', 'glow', 'spark', 'reveal', 'hint', 'base', 'inside'].map((n) => [n, part(stageEl, n)]));
}

export function render(progress) {
    if (!els) return;
    const p = clamp(progress);
    const { lid, ring, glow, spark, reveal, hint, base, inside } = els;

    // phases: lid .04–.34, ring rises .32–.62, box drops + names .66–.96
    const a = ease(clamp((p - 0.04) / 0.3));
    const q = ease(clamp((p - 0.32) / 0.3));
    const f = ease(clamp((p - 0.66) / 0.3));

    hint.style.opacity = String(1 - clamp(p * 8));

    const deg = a * 165;
    lid.style.transform = `rotateX(${deg}deg)`;
    lid.classList.toggle('satin', deg > 90); // past vertical we see the satin lining
    lid.style.zIndex = deg > 90 ? '1' : '5';

    const lift = -q * 58 - f * 30;
    ring.style.transform = `translateY(${lift}px) scale(${1 + q * 0.55 + f * 0.5}) rotate(${q * -8}deg)`;
    spark.setAttribute('opacity', String(q));
    glow.style.opacity = String(q * 0.9);
    glow.style.transform = `translateY(${lift}px) scale(${1 + f * 0.8})`;

    [lid, base, inside].forEach((el) => (el.style.opacity = String(1 - f)));
    base.style.transform = inside.style.transform = `translateY(${f * 80}px)`;

    reveal.style.opacity = String(f);
    reveal.style.transform = `translateY(${(1 - f) * 24}px)`;
}
