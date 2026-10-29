/*
 * Rose opening (ported from docs/samples/invite-warda.html): a bud whose
 * petals rotate open layer by layer (outer layers first), a glow, then loose
 * petals fall over the couple's names.
 *
 * Contract shared by every opening: init(stageEl, options), render(progress).
 * Markup: resources/views/invite/openings/rose.blade.php.
 */
import { NS, clamp, ease, lerp, part, petalPath, seeded } from './shared.js';

// Layers drawn back to front. Each petal pivots at the top of the stem;
// closed = narrow and upright (a bud), open = wide and spread.
const LAYERS = [
    { name: 'sepal', fill: 'url(#rz-sepal)', L: 54, w: 9, closed: [-38, -16, 16, 38], open: [-120, -95, 95, 120], sx: [1, 1], sy: [1, 1], delay: 0 },
    { name: 'back', fill: 'url(#rz-back)', L: 118, w: 46, closed: [-11, -4, 4, 11], open: [-58, -24, 24, 58], sx: [0.45, 1.05], sy: [1, 0.95], delay: 0.15 },
    { name: 'mid', fill: 'url(#rz-mid)', L: 104, w: 40, closed: [-7, 0, 7], open: [-34, 0, 34], sx: [0.45, 1.0], sy: [1, 0.92], delay: 0.3 },
    { name: 'inner', fill: 'url(#rz-mid)', L: 80, w: 30, closed: [-3, 3], open: [-12, 12], sx: [0.5, 0.9], sy: [1, 1], delay: 0.3 },
    { name: 'front', fill: 'url(#rz-front)', L: 96, w: 44, closed: [-15, -6, 6, 15], open: [-82, -50, 50, 82], sx: [0.45, 1.1], sy: [1, 0.8], delay: 0.05 },
];

const LOOSE_COLORS = ['#E8839F', '#F4A6BB', '#FAD0DC'];

let els = null;

export function init(stageEl, options = {}) {
    const head = part(stageEl, 'head');
    const petals = [];
    const rnd = seeded(9);

    LAYERS.forEach((ly) => {
        ly.closed.forEach((c, i) => {
            const p = document.createElementNS(NS, 'path');
            p.setAttribute('d', petalPath(ly.L, ly.w));
            p.setAttribute('fill', ly.fill);
            if (ly.name !== 'sepal') {
                p.setAttribute('stroke', '#C4567A');
                p.setAttribute('stroke-width', '.6');
                p.setAttribute('stroke-opacity', '.45');
            }
            head.appendChild(p);
            petals.push({ el: p, ly, c, o: ly.open[i] });
        });
        if (ly.name === 'inner') {
            const center = document.createElementNS(NS, 'g');
            center.innerHTML =
                '<path d="M0 -6 C -14 -22, -10 -48, 0 -54 C 10 -48, 14 -22, 0 -6Z" fill="#C9506F"/>' +
                '<path d="M-6 -30 Q 0 -44 8 -32" fill="none" stroke="#F4A6BB" stroke-width="2"/>' +
                '<path d="M-4 -40 Q 2 -50 6 -42" fill="none" stroke="#FAD0DC" stroke-width="1.6"/>';
            head.appendChild(center);
        }
    });

    // gold sparkles around the bloom
    const sparks = [];
    if (options.effects?.sparkles !== false) {
        const spark = part(stageEl, 'spark');
        for (let i = 0; i < 14; i++) {
            const s = document.createElement('span');
            const a = (i / 14) * Math.PI * 2;
            s.style.left = `calc(50% + ${Math.cos(a) * (110 + (i % 3) * 18)}px)`;
            s.style.top = `calc(38% + ${Math.sin(a) * (80 + (i % 2) * 20)}px)`;
            s.style.animationDelay = `${-(i * 0.3)}s`;
            spark.appendChild(s);
            sparks.push(s);
        }
    }

    // loose petals that fall over the names at the end
    const loose = [];
    const falling = part(stageEl, 'falling');
    const petalColor = options.petalColor;
    for (let i = 0; i < 10; i++) {
        const s = document.createElementNS(NS, 'svg');
        s.setAttribute('viewBox', '-12 -26 24 28');
        const fill = i % 3 === 1 && petalColor ? petalColor : LOOSE_COLORS[i % 3];
        s.innerHTML = `<path d="${petalPath(24, 10)}" fill="${fill}"/>`;
        falling.appendChild(s);
        loose.push({ el: s, dx: (rnd() - 0.5) * 300, dy: 280 + rnd() * 220, r: (rnd() - 0.5) * 540, d: rnd() * 0.25 });
    }

    els = {
        petals,
        sparks,
        loose,
        rose: part(stageEl, 'rose'),
        glow: part(stageEl, 'glow'),
        reveal: part(stageEl, 'reveal'),
        hint: part(stageEl, 'hint'),
        top: part(stageEl, 'top'),
    };
}

export function render(progress) {
    if (!els) return;
    const p = clamp(progress);
    const { petals, sparks, loose, rose, glow, reveal, hint, top } = els;

    rose.classList.toggle('sway', p < 0.02);

    // phases: bloom .04–.54, glow .4–.6, fall + reveal .62–.94
    const b = clamp((p - 0.04) / 0.5);
    const g = ease(clamp((p - 0.4) / 0.2));
    const f = ease(clamp((p - 0.62) / 0.32));

    hint.style.opacity = String(1 - clamp(p * 8));

    petals.forEach((pt) => {
        // outer layers open first, inner ones follow, so the bloom unfolds from the outside in
        const delay = pt.ly.delay;
        const t = ease(clamp((b - delay) / (1 - delay)));
        const a = lerp(pt.c, pt.o, t);
        const sx = lerp(pt.ly.sx[0], pt.ly.sx[1], t);
        const sy = lerp(pt.ly.sy[0], pt.ly.sy[1], t);
        pt.el.setAttribute('transform', `rotate(${a}) scale(${sx} ${sy})`);
    });

    glow.setAttribute('opacity', String(g * 0.9));
    sparks.forEach((s) => (s.style.opacity = String(g * (1 - f * 0.6))));
    rose.style.transform = `translateY(${-f * 70}px) scale(${1 - f * 0.22})`;

    loose.forEach((l) => {
        const k = clamp((f - l.d) / (1 - l.d));
        l.el.style.opacity = String(k > 0 ? 1 - clamp((k - 0.75) / 0.25) : 0);
        l.el.style.transform = `translate(${l.dx * k}px,${l.dy * k}px) rotate(${l.r * k}deg)`;
    });

    reveal.style.opacity = String(f);
    reveal.style.transform = `translateY(${(1 - f) * 24}px)`;
    top.style.opacity = String(1 - f);
}
