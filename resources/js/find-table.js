/*
 * "Find your table" (phase 8B): the entrance QR's page (resources/views/invite/find-table.blade.php).
 * Sends the name (+ last 4 digits) or the phone to POST /e/{slug}/find and shows
 * the table with the hall map. Plain DOM, no framework, no cookies.
 */
import { hallMap } from './invite/day.js';

const ARABIC = '٠١٢٣٤٥٦٧٨٩';
const digitsFor = (locale) => (value) => (locale === 'ar' ? String(value).replace(/[0-9]/g, (d) => ARABIC[Number(d)]) : String(value));

function boot() {
    const root = document.querySelector('[data-find-table]');
    const form = root?.querySelector('[data-find-form]');
    if (!root || !form) return;

    const msg = root.querySelector('[data-find-msg]');
    const result = root.querySelector('[data-find-result]');
    const submit = root.querySelector('[data-find-submit]');
    const strings = JSON.parse(root.querySelector('[data-find-strings]')?.textContent || '{}');
    const digits = digitsFor(root.dataset.locale);

    const say = (text, error = false) => {
        msg.hidden = !text;
        msg.textContent = text || '';
        msg.classList.toggle('is-error', error);
    };

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const data = Object.fromEntries(new FormData(form));
        if (!String(data.name || '').trim() && !String(data.phone || '').trim()) {
            form.querySelector('[data-find-name]').focus();
            return;
        }
        submit.disabled = true;
        say('');
        try {
            const res = await fetch(root.dataset.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                credentials: 'omit',
                body: JSON.stringify(data),
            });
            if (res.status === 429) return say(strings.too_many, true);
            const body = await res.json().catch(() => ({}));
            if (!res.ok || !body.found || !body.seated) return say(body.message || strings.not_found, true);

            root.querySelector('[data-find-table-label]').textContent = body.label;
            root.querySelector('[data-find-map]').innerHTML = body.map ? hallMap(body.map, digits) : '';
            form.hidden = true;
            result.hidden = false;
            result.querySelector('[data-find-again]').focus();
        } catch {
            say(strings.not_found, true);
        } finally {
            submit.disabled = false;
        }
    });

    root.querySelector('[data-find-again]')?.addEventListener('click', () => {
        form.reset();
        result.hidden = true;
        form.hidden = false;
        say('');
        form.querySelector('[data-find-name]').focus();
    });
}

boot();
