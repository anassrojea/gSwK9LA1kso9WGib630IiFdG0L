/*
 * Diploma opening (ported from docs/samples/invite-graduation.html): the
 * ribbon slips off, the scroll unrolls between its two rods, then caps are
 * thrown into the air.
 *
 * The sample animated the paper's height; here the unroll is transforms only:
 * the paper is a mask that slides up while the sheet inside slides down by
 * twice as much, so the visible window grows from the middle exactly like
 * the sample while the rods move apart.
 *
 * options.color tints the ribbon and seal, options.accentColor the gold
 * (default: theme gold). Markup: resources/views/invite/openings/diploma.blade.php.
 */
import { NS, applyColors, clamp, ease, part, seeded } from './shared.js';

const CAPS = [[12, 0.95, -1], [30, 1.15, 1], [50, 1.3, -1], [68, 1.05, 1], [86, 0.9, -1]];

let els = null;

export function init(stageEl, options = {}) {
    applyColors(stageEl, options);

    if (options.effects?.sparkles !== false) {
        const rnd = seeded(3);
        const dust = part(stageEl, 'dust');
        for (let i = 0; i < 30; i++) {
            const s = document.createElement('span');
            s.style.left = `${rnd() * 100}%`;
            s.style.top = `${rnd() * 100}%`;
            dust.appendChild(s);
        }
    }

    const caps = [];
    if (!options.reducedMotion) {
        const wrap = part(stageEl, 'caps');
        CAPS.forEach(([x, h, dir], i) => {
            const s = document.createElementNS(NS, 'svg');
            s.setAttribute('viewBox', '0 0 64 44');
            s.setAttribute('class', 'cap');
            s.style.left = `calc(${x}% - 32px)`;
            s.innerHTML = '<path d="M32 2L2 14l30 12 30-12z" class="cp-top"/><path d="M14 19v9c0 4 8 7 18 7s18-3 18-7v-9l-18 7z" class="cp-band"/><path d="M56 15v15" class="cp-cord"/><path d="M53 30h6l-1 8h-4z" class="cp-tassel"/>';
            wrap.appendChild(s);
            caps.push({ el: s, h, dir, delay: i * 0.04 });
        });
    }

    const paper = part(stageEl, 'paper');
    els = { caps, paper, ...Object.fromEntries(['sheet', 'rod-top', 'rod-bottom', 'ribbon', 'dip', 'hint', 'top'].map((n) => [n, part(stageEl, n)])) };
    els.half = paper.offsetHeight / 2; // 170px with the default 340px sheet
}

export function render(progress) {
    if (!els) return;
    const p = clamp(progress);
    const { caps, paper, sheet, ribbon, dip, hint, top } = els;

    // phases: ribbon .03–.2, unroll .18–.52, caps .55–.95, settle .8–1
    const r = ease(clamp((p - 0.03) / 0.17));
    const u = ease(clamp((p - 0.18) / 0.34));
    const f = ease(clamp((p - 0.8) / 0.2));

    hint.style.opacity = String(1 - clamp(p * 8));
    ribbon.style.transform = `translate(${r * 40}px,${r * 120}px) rotate(${r * 50}deg)`;
    ribbon.style.opacity = String(1 - r);

    const h = els.half * (1 - u);
    els['rod-top'].style.transform = `translateY(${h}px)`;
    els['rod-bottom'].style.transform = `translateY(${-h}px)`;
    paper.style.transform = `translateY(${-h}px)`;
    sheet.style.transform = `translateY(${2 * h}px)`;

    caps.forEach((c) => {
        const k = clamp((p - 0.55 - c.delay) / 0.3);
        c.el.style.opacity = String(k > 0 ? 1 - clamp((k - 0.8) / 0.2) : 0);
        c.el.style.transform = `translateY(${-k * window.innerHeight * c.h * (1 - 0.35 * k)}px) rotate(${c.dir * k * 540}deg)`;
    });

    top.style.opacity = String(1 - f);
    dip.style.transform = `translateY(${-f * 20}px)`;
}
