/*
 * Drives an opening module from scroll position. The opening section is
 * tall (~330vh) with a sticky stage; progress is how far it has scrolled:
 *   progress = -section.top / (section.height - viewport.height), clamped to 0..1
 * With prefers-reduced-motion the section collapses to one screen showing the final frame.
 */
import { clamp } from '../openings/shared.js';

export function mountOpening(section, mod, options = {}) {
    const stage = section.querySelector('[data-stage]');
    const motion = window.matchMedia('(prefers-reduced-motion: reduce)');

    mod.init(stage, { ...options, reducedMotion: motion.matches });

    if (motion.matches) {
        section.classList.add('is-static');
        mod.render(1);
        return;
    }

    const progress = () => {
        const total = Math.max(1, section.offsetHeight - window.innerHeight); // guard against division by zero
        return clamp(-section.getBoundingClientRect().top / total);
    };

    let ticking = false;
    const update = () => {
        ticking = false;
        mod.render(progress());
    };

    window.addEventListener('scroll', () => {
        if (!ticking) {
            ticking = true;
            requestAnimationFrame(update);
        }
    }, { passive: true });
    window.addEventListener('resize', update);
    update();
}
