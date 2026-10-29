/*
 * Arched doors opening (ported from docs/samples/invite-henna.html): two
 * lattice doors swing inward (rotateY), a henna mandala draws itself stroke by
 * stroke (stroke-dashoffset on pathLength=1), the names fade in, then we zoom
 * through the arch.
 *
 * options.color tints the doors (default: theme accent), options.accentColor
 * the henna drawing (default: theme label). Markup: resources/views/invite/openings/doors.blade.php.
 */
import { NS, applyColors, clamp, ease, part } from './shared.js';

let els = null;

function mandala(svg) {
    const add = (tag, attrs) => {
        const e = document.createElementNS(NS, tag);
        for (const k in attrs) e.setAttribute(k, attrs[k]);
        e.setAttribute('pathLength', '1');
        svg.appendChild(e);
    };
    [12, 20, 44, 88].forEach((r) => add('circle', { r }));
    for (let i = 0; i < 12; i++) {
        const a = i * 30;
        add('path', { d: 'M0 -22 Q14 -40 0 -62 Q-14 -40 0 -22', transform: `rotate(${a})` });
        add('path', { d: 'M0 -48 Q6 -54 0 -60 Q-6 -54 0 -48', transform: `rotate(${a})` });
    }
    for (let i = 0; i < 12; i++) {
        const a = i * 30 + 15;
        add('path', { d: 'M0 -64 Q20 -72 0 -86 Q-20 -72 0 -64', transform: `rotate(${a})` });
        add('circle', { cx: 0, cy: -94, r: 2.5, transform: `rotate(${a - 15})` });
    }
    for (let i = 0; i < 8; i++) add('path', { d: 'M0 -12 L4 -4 L0 0 L-4 -4 Z', transform: `rotate(${i * 45})` });
    return [...svg.children];
}

export function init(stageEl, options = {}) {
    applyColors(stageEl, options);
    els = {
        strokes: mandala(part(stageEl, 'mandala')),
        doorL: part(stageEl, 'door-l'),
        doorR: part(stageEl, 'door-r'),
        arch: part(stageEl, 'arch'),
        txt: part(stageEl, 'txt'),
        top: part(stageEl, 'top'),
        hint: part(stageEl, 'hint'),
    };
}

export function render(progress) {
    if (!els) return;
    const p = clamp(progress);
    const { strokes, doorL, doorR, arch, txt, top, hint } = els;

    // phases: doors .04–.36, drawing .22–.64, names .5–.68, zoom through .74–.98
    const d = ease(clamp((p - 0.04) / 0.32));
    const dr = clamp((p - 0.22) / 0.42);
    const t = ease(clamp((p - 0.5) / 0.18));
    const f = ease(clamp((p - 0.74) / 0.24));

    hint.style.opacity = String(1 - clamp(p * 8));
    doorL.style.transform = `rotateY(${d * 100}deg)`;
    doorR.style.transform = `rotateY(${-d * 100}deg)`;

    const n = strokes.length;
    strokes.forEach((s, i) => (s.style.strokeDashoffset = String(1 - clamp((dr - (i / n) * 0.6) / 0.4))));

    txt.style.opacity = String(t);
    txt.style.transform = `translateY(${(1 - t) * 14}px)`;
    arch.style.transform = `scale(${1 + f * 1.6})`;
    arch.style.opacity = String(1 - f);
    top.style.opacity = String(1 - f);
}

/** With reduced motion show the open doors and the finished drawing, not the empty frame after the zoom. */
export const still = 0.72;
