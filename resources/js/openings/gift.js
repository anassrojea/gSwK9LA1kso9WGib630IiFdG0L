/*
 * Gift box opening (ported from docs/samples/invite-birthday.html): the bow
 * flies off, the lid pops, balloons rise and confetti bursts with
 * scroll-driven "physics" (position is a pure function of progress), then
 * the box drops and the name and age appear.
 *
 * options.color tints the wrapping (default: theme label), options.accentColor
 * the ribbon and bow (default: theme gold). Markup: resources/views/invite/openings/gift.blade.php.
 */
import { NS, applyColors, clamp, ease, part, seeded } from './shared.js';

const COLORS = ['var(--o-color)', 'var(--o-accent)', '#1FB5A8', '#E04F9A', '#ffffff', '#F28B2B'];
const BALLOONS = [[-74, '#1FB5A8', 1.05], [-36, 'var(--o-accent)', 0.9], [0, '#E04F9A', 1.15], [38, 'var(--o-color)', 0.95], [76, '#ffffff', 1]];

let els = null;

export function init(stageEl, options = {}) {
    applyColors(stageEl, options);
    const rnd = seeded(5);

    if (options.effects?.sparkles !== false) {
        const dots = part(stageEl, 'dots');
        for (let i = 0; i < 18; i++) {
            const s = document.createElement('span');
            const z = 8 + rnd() * 26;
            Object.assign(s.style, { left: `${rnd() * 100}%`, top: `${rnd() * 100}%`, width: `${z}px`, height: `${z}px`, background: COLORS[i % 5] });
            dots.appendChild(s);
        }
    }

    // balloons and confetti only exist mid-animation: with reduced motion we jump to the end
    const balloons = [];
    const bits = [];
    if (!options.reducedMotion) {
        const bw = part(stageEl, 'balloons');
        BALLOONS.forEach(([x, c, sp], i) => {
            const s = document.createElementNS(NS, 'svg');
            s.setAttribute('viewBox', '0 0 40 90');
            s.setAttribute('class', 'balloon');
            s.style.fill = c;
            s.innerHTML = '<ellipse cx="20" cy="22" rx="17" ry="21"/><ellipse cx="13" cy="14" rx="4" ry="6" fill="#fff" opacity=".45"/><path d="M17 43h6l-3 4z"/><path d="M20 47q-6 12 0 22t0 20" fill="none" stroke="#fff" stroke-width="1" opacity=".8"/>';
            bw.appendChild(s);
            balloons.push({ el: s, x, sp, ph: i * 1.3 });
        });

        const cw = part(stageEl, 'confetti');
        for (let i = 0; i < 40; i++) {
            const s = document.createElement('span');
            s.style.background = COLORS[i % COLORS.length];
            if (i % 3 === 0) s.className = 'dot';
            cw.appendChild(s);
            const a = ((-160 + rnd() * 140) * Math.PI) / 180;
            const v = 170 + rnd() * 190;
            bits.push({ el: s, vx: Math.cos(a) * v, vy: Math.sin(a) * v, r: rnd() * 720 });
        }
    }

    els = { balloons, bits, ...Object.fromEntries(['gift', 'bow', 'lid', 'body', 'reveal', 'hint', 'top'].map((n) => [n, part(stageEl, n)])) };
}

export function render(progress) {
    if (!els) return;
    const p = clamp(progress);
    const { balloons, bits, gift, bow, lid, body, reveal, hint, top } = els;

    gift.classList.toggle('wiggle', p < 0.02);

    // phases: bow .03–.17, lid .15–.35, balloons .3–.75, confetti .3–.7, box drops + reveal .7–.96
    const b = ease(clamp((p - 0.03) / 0.14));
    const l = ease(clamp((p - 0.15) / 0.2));
    const q = clamp((p - 0.3) / 0.45);
    const c = clamp((p - 0.3) / 0.4);
    const f = ease(clamp((p - 0.7) / 0.26));

    hint.style.opacity = String(1 - clamp(p * 8));
    bow.style.transform = `translateY(${-b * 30}px) rotate(${b * 40}deg) scale(${1 - b})`;
    bow.style.opacity = String(1 - b);
    lid.style.transform = `translate(${-l * 90}px,${-l * 170}px) rotate(${-l * 35}deg)`;
    lid.style.opacity = String(1 - clamp((l - 0.6) / 0.4));

    balloons.forEach((o) => {
        o.el.style.transform = `translate(${o.x * q + Math.sin(q * 6 + o.ph) * 10}px,${-q * 420 * o.sp}px)`;
    });
    bits.forEach((o) => {
        o.el.style.opacity = String(c > 0 ? 1 - clamp((c - 0.65) / 0.35) : 0);
        o.el.style.transform = `translate(${o.vx * c * 1.3}px,${o.vy * c * 1.3 + 300 * c * c}px) rotate(${o.r * c}deg)`;
    });

    body.style.transform = `translateY(${f * 90}px)`;
    body.style.opacity = String(1 - f);
    reveal.style.opacity = String(f);
    reveal.style.transform = `translateY(calc(-50% + ${(1 - f) * 30}px)) scale(${0.8 + 0.2 * f})`;
    top.style.opacity = String(1 - f);
}
