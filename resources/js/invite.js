/*
 * Guest invitation page (resources/views/invite/show.blade.php). Vanilla JS,
 * no framework: loads only the chosen opening module, reveals sections,
 * runs the countdown and fills the per-guest parts from GET /i/{token}/state.
 */
import { mountOpening } from './invite/driver.js';
import { renderDay } from './invite/day.js';
import { NS, petalPath, seeded } from './openings/shared.js';
import { toLocalDigits } from './lib/digits';
import { openLinkView, replyStorageKey, stateQuery } from './lib/open-link';
import { deviceFrom, walletPlatformsFor } from './lib/wallet';

document.documentElement.classList.add('js');

const boot = JSON.parse(document.getElementById('invite-boot')?.textContent || '{}');
const locale = boot.locale || document.documentElement.lang || 'ar';
const digits = (n) => toLocalDigits(n, locale);
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/* ---------- opening (one lazily loaded chunk per opening) ---------- */
const openings = import.meta.glob(['./openings/*.js', '!./openings/shared.js']);
const openingSection = document.getElementById('opening');
const loadOpening = openings[`./openings/${boot.opening?.id}.js`];
if (openingSection && loadOpening) {
    loadOpening().then((mod) =>
        mountOpening(openingSection, mod, { ...(boot.opening.options || {}), effects: boot.effects || {} }),
    );
}

/* ---------- reveal on scroll ---------- */
const io = 'IntersectionObserver' in window
    ? new IntersectionObserver((entries) => entries.forEach((e) => {
        if (e.isIntersecting) {
            e.target.classList.add('in');
            io.unobserve(e.target);
        }
    }), { threshold: 0.15 })
    : null;
document.querySelectorAll('.rv').forEach((el) => (io ? io.observe(el) : el.classList.add('in')));

/* ---------- countdown ---------- */
document.querySelectorAll('[data-countdown]').forEach((el) => {
    const target = new Date(el.dataset.countdown).getTime();
    const out = Object.fromEntries([...el.querySelectorAll('[data-cd]')].map((b) => [b.dataset.cd, b]));
    const tick = () => {
        let left = Math.max(0, target - Date.now());
        const d = Math.floor(left / 864e5); left -= d * 864e5;
        const h = Math.floor(left / 36e5); left -= h * 36e5;
        const m = Math.floor(left / 6e4); left -= m * 6e4;
        const s = Math.floor(left / 1e3);
        out.d.textContent = digits(d);
        out.h.textContent = digits(h);
        out.m.textContent = digits(m);
        out.s.textContent = digits(s);
    };
    tick();
    setInterval(tick, 1000);
});

/* ---------- ambient falling petals in the story ---------- */
if (boot.effects?.fallingPetals && !reducedMotion) {
    const rnd = seeded(13);
    document.querySelectorAll('[data-drift]').forEach((section) => {
        for (let i = 0; i < 8; i++) {
            const s = document.createElementNS(NS, 'svg');
            s.setAttribute('class', 'drift');
            s.setAttribute('viewBox', '-12 -26 24 28');
            s.setAttribute('aria-hidden', 'true');
            s.innerHTML = `<path d="${petalPath(24, 10)}" fill="${boot.opening?.options?.petalColor || '#F4A6BB'}"/>`;
            s.style.left = `${5 + rnd() * 90}%`;
            s.style.animationDelay = `${-(rnd() * 12)}s`;
            s.style.animationDuration = `${10 + rnd() * 6}s`;
            section.appendChild(s);
        }
    });
}

/* ---------- toast ---------- */
const toastEl = document.getElementById('toast');
let toastTimer;
function toast(message) {
    if (!toastEl) return;
    toastEl.textContent = message;
    toastEl.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toastEl.classList.remove('show'), 2600);
}

/* ---------- requests ---------- */
async function request(url, body) {
    const response = await fetch(url, {
        method: body === undefined ? 'GET' : 'POST',
        headers: { Accept: 'application/json', ...(body === undefined ? {} : { 'Content-Type': 'application/json' }) },
        body: body === undefined ? undefined : JSON.stringify(body),
        credentials: 'omit',
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const first = data.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw Object.assign(new Error(first || data.message || response.statusText), { status: response.status });
    }
    return data;
}

/* ---------- per-guest state + RSVP ---------- */
const rsvp = document.querySelector('[data-rsvp]');
const strings = rsvp ? JSON.parse(rsvp.dataset.strings || '{}') : {};
const form = rsvp?.querySelector('[data-rsvp-form]');
let state = null;

/* open link (one link for everyone): the guest types their name; the reply token stays on this device */
const nameField = form?.querySelector('[data-name]');
const replyKey = nameField && boot.urls?.state ? replyStorageKey(boot.urls.state) : null;
const storage = (value) => {
    try {
        if (value === undefined) return replyKey ? localStorage.getItem(replyKey) : null;
        if (replyKey && value) localStorage.setItem(replyKey, value);
        else if (replyKey) localStorage.removeItem(replyKey);
    } catch {
        // private mode / storage disabled: the reply still works, it just can't be changed later
    }
    return null;
};
let replyToken = storage();

function show(el, visible) {
    if (el) el.hidden = !visible;
}

/** Kids are counted within the party: 0 … people − 1. */
function fillKids(selected = 0) {
    const kids = form?.querySelector('[data-kids]');
    if (!kids) return;
    const max = Math.max(0, Number(form.querySelector('[data-people]').value) - 1);
    kids.replaceChildren(...Array.from({ length: max + 1 }, (_, i) => {
        const option = document.createElement('option');
        option.value = String(i);
        option.textContent = digits(i);
        return option;
    }));
    kids.value = String(Math.min(selected, max));
}

function setAttendingFields(attending) {
    form?.querySelectorAll('[data-people], [data-kids], [name=meal]').forEach((field) => (field.disabled = !attending));
}

function applyState(next) {
    state = next;
    renderDay(next, digits);
    if (next.household.name) document.querySelectorAll('[data-guest-name]').forEach((el) => (el.textContent = next.household.name));
    if (replyKey) {
        replyToken = next.reply_token || null;
        storage(replyToken);
        if (next.household.name) nameField.value = next.household.name;
    }

    const roseCount = document.querySelector('[data-rose-count]');
    if (roseCount && typeof next.roses === 'number') roseCount.textContent = digits(next.roses);

    if (!rsvp) return;

    const seats = next.household.seats_allowed;
    rsvp.querySelector('[data-seats]').textContent = strings.seats?.[seats] ?? '';

    const people = form.querySelector('[data-people]');
    people.replaceChildren(...Array.from({ length: seats }, (_, i) => {
        const option = document.createElement('option');
        option.value = String(i + 1);
        option.textContent = strings.people?.[i + 1] ?? digits(i + 1);
        return option;
    }));
    people.value = String(Math.min(seats, next.rsvp?.people_count || seats));
    fillKids(next.rsvp?.kids_count ?? 0);

    const reply = next.rsvp;
    if (reply) {
        form.querySelector(reply.attending ? '#att-yes' : '#att-no').checked = true;
        const message = form.querySelector('[name=message]');
        if (message && reply.message) message.value = reply.message;
        const allergies = form.querySelector('[name=allergies]');
        if (allergies && reply.allergies) allergies.value = reply.allergies;
        const meal = form.querySelector('[name=meal]');
        if (meal && reply.meal) meal.value = reply.meal;
    }
    setAttendingFields(reply ? reply.attending : true);

    const done = rsvp.querySelector('[data-done]');
    const sorry = rsvp.querySelector('[data-sorry]');
    const open = next.open_link ? openLinkView(next.open_link, !!reply, next.rsvp_open) : null;
    if (open) rsvp.querySelector('[data-seats]').textContent = open.seatsLeft ? strings.seatsLeft?.[open.seatsLeft] ?? '' : '';
    show(form, open ? open.form : !reply && next.rsvp_open);
    show(rsvp.querySelector('[data-full]'), !!open?.full);
    show(done, !!reply?.attending);
    show(sorry, !!reply && !reply.attending);
    show(rsvp.querySelector('[data-closed]'), !reply && !next.rsvp_open);
    rsvp.querySelectorAll('[data-edit]').forEach((b) => show(b, next.rsvp_open));

    if (reply?.attending) {
        rsvp.querySelector('[data-confirmed-people]').textContent =
            (strings.confirmedPeople || ':people').replace(':people', strings.people?.[reply.people_count] ?? digits(reply.people_count));
        show(rsvp.querySelector('[data-table-wrap]'), !!next.table);
    }
}

if (boot.urls?.state) {
    request(boot.urls.state + stateQuery(true, replyToken)).then(applyState).catch(() => {});
}

if (form) {
    const people = form.querySelector('[data-people]');
    form.querySelectorAll('[name=attending]').forEach((radio) =>
        radio.addEventListener('change', () => setAttendingFields(form.querySelector('#att-yes').checked)),
    );
    people.addEventListener('change', () => fillKids(Number(form.querySelector('[data-kids]')?.value || 0)));

    rsvp.querySelectorAll('[data-edit]').forEach((button) =>
        button.addEventListener('click', () => {
            show(rsvp.querySelector('[data-done]'), false);
            show(rsvp.querySelector('[data-sorry]'), false);
            show(form, true);
        }),
    );

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const error = form.querySelector('[data-error]');
        const submit = form.querySelector('[type=submit]');
        const attending = form.querySelector('#att-yes').checked;
        const payload = { attending };
        if (nameField) {
            payload.name = nameField.value.trim();
            if (!payload.name) return nameField.reportValidity();
            if (replyToken) payload.reply_token = replyToken;
        }
        if (attending) {
            payload.people_count = Number(people.value);
            const kids = form.querySelector('[data-kids]');
            if (kids) payload.kids_count = Number(kids.value);
            const meal = form.querySelector('[name=meal]');
            if (meal) payload.meal = meal.value;
        }
        ['message', 'allergies'].forEach((name) => {
            const field = form.querySelector(`[name=${name}]`);
            if (field) payload[name] = field.value.trim() || null;
        });

        show(error, false);
        submit.disabled = true;
        submit.textContent = strings.sending || '…';
        try {
            applyState(await request(boot.urls.rsvp, payload));
            toast(attending ? strings.thanksYes : strings.thanksNo);
        } catch (e) {
            error.textContent = e.status === 422 || e.status === 429 ? e.message : strings.error;
            show(error, true);
            // open link: places may have run out meanwhile, refresh what is left
            if (replyKey && e.status === 422) request(boot.urls.state + stateQuery(false, replyToken)).then(applyState).catch(() => {});
        } finally {
            submit.disabled = false;
            submit.textContent = strings.submit || '';
        }
    });
}

/* ---------- send a rose ---------- */
const roseButton = document.querySelector('[data-send-rose]');
if (roseButton && boot.urls?.rose) {
    roseButton.addEventListener('click', async () => {
        roseButton.disabled = true;
        try {
            const { count } = await request(boot.urls.rose, {});
            document.querySelector('[data-rose-count]').textContent = digits(count);
            if (!reducedMotion) {
                const box = document.querySelector('[data-roses]');
                const s = document.createElementNS(NS, 'svg');
                s.setAttribute('class', 'fly');
                s.setAttribute('viewBox', '-12 -26 24 28');
                s.innerHTML = `<path d="${petalPath(24, 10)}" fill="#F9D3DD"/>`;
                s.style.left = `${40 + Math.random() * 20}%`;
                s.style.bottom = '40px';
                box.appendChild(s);
                setTimeout(() => s.remove(), 1700);
            }
            toast(roseButton.dataset.sent);
        } catch {
            // rate-limited or offline: nothing to tell the guest
        } finally {
            setTimeout(() => (roseButton.disabled = false), 1200);
        }
    });
}

/* ---------- background music (only when the host turned it on, never autoplays) ---------- */
const musicButton = document.querySelector('[data-music]');
if (musicButton) {
    let audio = null;
    musicButton.addEventListener('click', () => {
        audio ??= Object.assign(new Audio(musicButton.dataset.music), { loop: true });
        const playing = !audio.paused;
        if (playing) audio.pause();
        else audio.play().catch(() => {});
        musicButton.setAttribute('aria-pressed', String(!playing));
        musicButton.setAttribute('aria-label', playing ? musicButton.dataset.labelPlay : musicButton.dataset.labelPause);
    });
}

/* ---------- album and wallet (phase 7B) ---------- */
// a table QR (/i/{token}?upload=1) goes straight to the album's upload page
const albumLink = document.querySelector('[data-album-link]');
if (albumLink && new URLSearchParams(location.search).get('upload') === '1') location.replace(albumLink.href);

// an iPhone gets the Apple Wallet button, an Android phone Google's; other devices both
const walletShown = walletPlatformsFor(deviceFrom(navigator.userAgent, navigator.maxTouchPoints || 0));
document.querySelectorAll('[data-wallet-platform]').forEach((a) => (a.hidden = !walletShown.includes(a.dataset.walletPlatform)));
