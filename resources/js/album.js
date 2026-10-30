/*
 * The guests' album page (resources/views/album/show.blade.php, phase 7B).
 * Vanilla JS like the invitation: photos are compressed on the device
 * (lib/image-compress), videos checked for length, then uploaded to
 * /i/{token}/uploads (or straight to S3 with a signed URL). No cookies: the
 * album PIN's key is kept in localStorage and sent as X-Album-Key.
 */
import { compressImage } from './lib/image-compress';
import { kindOf, uploadProblem } from './lib/album';
import { toLocalDigits } from './lib/digits';

const boot = JSON.parse(document.getElementById('album-boot')?.textContent || '{}');
const S = boot.strings || {};
const locale = boot.locale || 'ar';
const $ = (selector) => document.querySelector(selector);
const fill = (text, replace = {}) => Object.entries(replace).reduce((t, [k, v]) => t.replaceAll(`:${k}`, typeof v === 'number' ? toLocalDigits(v, locale) : v), text || '');

/* ---------- PIN key (per album link, on this device) ---------- */
const keyName = `daawa:album:${boot.urls?.items || ''}`;
const store = {
    get: () => { try { return localStorage.getItem(keyName); } catch { return null; } },
    set: (v) => { try { v ? localStorage.setItem(keyName, v) : localStorage.removeItem(keyName); } catch { /* private mode */ } },
};
let key = store.get();

function headers(json = false) {
    return { Accept: 'application/json', ...(key ? { 'X-Album-Key': key } : {}), ...(json ? { 'Content-Type': 'application/json' } : {}) };
}

async function request(url, options = {}) {
    const response = await fetch(url, { credentials: 'omit', ...options, headers: { ...headers(typeof options.body === 'string'), ...(options.headers || {}) } });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const first = data.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw Object.assign(new Error(first || data.message || ''), { status: response.status, reason: data.reason });
    }
    return data;
}

/* ---------- toast ---------- */
const toastEl = $('[data-toast]');
let toastTimer;
function toast(message) {
    if (!toastEl || !message) return;
    toastEl.textContent = message;
    toastEl.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toastEl.classList.remove('show'), 3200);
}

/* ---------- gallery ---------- */
const grid = $('[data-grid]');
const more = $('[data-more]');
const items = [];
let next = null;

function tile(item) {
    const li = document.createElement('li');
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'al-tile';
    button.setAttribute('aria-label', item.name ? fill(S.by, { name: item.name }) : S.gallery || '');
    if (item.thumb) {
        const img = document.createElement('img');
        img.src = item.thumb;
        img.alt = '';
        img.loading = 'lazy';
        img.decoding = 'async';
        button.appendChild(img);
    }
    if (item.kind === 'video') {
        const badge = document.createElement('span');
        badge.className = 'al-badge';
        badge.textContent = `▶ ${S.video || ''}`;
        button.appendChild(badge);
    }
    button.addEventListener('click', () => openLightbox(items.indexOf(item)));
    li.appendChild(button);
    return li;
}

function render(list, prepend = false) {
    const nodes = list.map(tile);
    if (prepend) grid.prepend(...nodes);
    else grid.append(...nodes);
    $('[data-empty]').hidden = items.length > 0;
}

async function load(before = null) {
    try {
        const data = await request(boot.urls.items + (before ? `?before=${encodeURIComponent(before)}` : ''));
        items.push(...data.items);
        render(data.items);
        next = data.next;
        more.hidden = !next;
    } catch (e) {
        if (e.reason === 'pin') lock();
    }
}
more?.addEventListener('click', () => next && load(next));

/* ---------- lightbox ---------- */
const lightbox = $('[data-lightbox]');
let current = -1;
function openLightbox(index) {
    const item = items[index];
    if (!item || !lightbox) return;
    current = index;
    const box = lightbox.querySelector('[data-media]');
    box.replaceChildren();
    if (item.kind === 'video') {
        const video = Object.assign(document.createElement('video'), { src: item.original, controls: true, playsInline: true, preload: 'metadata' });
        if (item.thumb) video.poster = item.thumb;
        box.appendChild(video);
    } else {
        box.appendChild(Object.assign(document.createElement('img'), { src: item.preview || item.original, alt: '' }));
    }
    lightbox.querySelector('[data-caption]').textContent = item.name ? fill(S.by, { name: item.name }) : '';
    lightbox.querySelector('[data-download]').href = item.original;
    lightbox.querySelector('[data-prev]').disabled = index <= 0;
    lightbox.querySelector('[data-next]').disabled = index >= items.length - 1;
    if (!lightbox.open) lightbox.showModal?.();
}
lightbox?.querySelector('[data-close]').addEventListener('click', () => lightbox.close());
lightbox?.querySelector('[data-prev]').addEventListener('click', () => openLightbox(current - 1));
lightbox?.querySelector('[data-next]').addEventListener('click', () => openLightbox(current + 1));
lightbox?.addEventListener('close', () => lightbox.querySelector('video')?.pause());

/* ---------- uploads ---------- */
const queue = $('[data-queue]');
const nameField = $('[data-name]');

function videoInfo(file) {
    return new Promise((resolve) => {
        const video = document.createElement('video');
        const url = URL.createObjectURL(file);
        const done = (value) => { URL.revokeObjectURL(url); resolve(value); };
        video.preload = 'metadata';
        video.muted = true;
        video.playsInline = true;
        video.onloadedmetadata = () => {
            const duration = Number.isFinite(video.duration) ? video.duration : null;
            // a poster frame for the thumbnails (the server has no video tools)
            video.currentTime = Math.min(0.5, (duration || 1) / 2);
            video.onseeked = () => {
                const canvas = document.createElement('canvas');
                const scale = Math.min(1, 1200 / Math.max(video.videoWidth, video.videoHeight, 1));
                canvas.width = Math.round(video.videoWidth * scale) || 1;
                canvas.height = Math.round(video.videoHeight * scale) || 1;
                try {
                    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
                    canvas.toBlob((blob) => done({ duration, poster: blob }), 'image/jpeg', 0.8);
                } catch {
                    done({ duration, poster: null });
                }
            };
        };
        video.onerror = () => done({ duration: null, poster: null });
        video.src = url;
    });
}

function send(form, onProgress) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', boot.urls.upload);
        Object.entries(headers()).forEach(([k, v]) => xhr.setRequestHeader(k, v));
        xhr.upload.onprogress = (e) => e.lengthComputable && onProgress(e.loaded / e.total);
        xhr.onload = () => {
            let data = {};
            try { data = JSON.parse(xhr.responseText); } catch { /* not JSON */ }
            if (xhr.status >= 200 && xhr.status < 300) return resolve(data);
            const first = data.errors ? Object.values(data.errors)[0]?.[0] : null;
            reject(Object.assign(new Error(first || data.message || ''), { status: xhr.status, reason: data.reason }));
        };
        xhr.onerror = () => reject(Object.assign(new Error(''), { status: 0 }));
        xhr.send(form);
    });
}

/** S3: ask for a signed URL, PUT the file there, then confirm. */
async function sendDirect(file, meta, onProgress) {
    const { direct } = await request(boot.urls.upload, { method: 'POST', body: JSON.stringify({ mime: file.type, bytes: file.size, ...meta }) });
    await new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('PUT', direct.url);
        Object.entries(direct.headers || {}).forEach(([k, v]) => k.toLowerCase() !== 'host' && xhr.setRequestHeader(k, Array.isArray(v) ? v[0] : v));
        xhr.upload.onprogress = (e) => e.lengthComputable && onProgress(e.loaded / e.total);
        xhr.onload = () => (xhr.status < 300 ? resolve() : reject(Object.assign(new Error(''), { status: xhr.status })));
        xhr.onerror = () => reject(Object.assign(new Error(''), { status: 0 }));
        xhr.send(file);
    });
    return request(boot.urls.complete, { method: 'POST', body: JSON.stringify({ upload: direct.upload }) });
}

function row(file) {
    const li = document.createElement('li');
    li.innerHTML = '<span class="al-q-name"></span><span class="al-q-state"></span><span class="al-q-bar"><i></i></span>';
    li.querySelector('.al-q-name').textContent = file.name;
    queue.prepend(li);
    return {
        state: (text, kind = '') => {
            li.querySelector('.al-q-state').textContent = text;
            li.dataset.state = kind;
        },
        progress: (f) => (li.querySelector('.al-q-bar i').style.width = `${Math.round(f * 100)}%`),
    };
}

async function uploadOne(original, { guestbook = false } = {}) {
    const ui = row(original);
    ui.state(S.preparing);
    const limits = guestbook && boot.guestbookVideo
        ? { photoMaxMb: 0, video: boot.guestbookVideo }
        : { photoMaxMb: boot.photoMaxMb, video: boot.video };

    let file = original;
    let duration = null;
    let poster = null;
    if (kindOf(original.type) === 'photo') {
        file = await compressImage(original, boot.maxEdge || 2560);
    } else if (kindOf(original.type) === 'video') {
        ({ duration, poster } = await videoInfo(original));
    }

    const problem = uploadProblem(file, duration, limits);
    if (problem) {
        const max = problem === 'too_long' ? limits.video.max_seconds : kindOf(file.type) === 'photo' ? limits.photoMaxMb : limits.video.max_mb;
        ui.state(fill(S[problem], { max }), 'error');
        return;
    }

    const meta = { ...(duration !== null ? { duration } : {}), ...(nameField?.value.trim() ? { name: nameField.value.trim() } : {}), ...(guestbook ? { guestbook: 1 } : {}) };
    const progress = (f) => {
        ui.progress(f);
        ui.state(fill(S.uploading, { percent: Math.round(f * 100) }));
    };

    try {
        let data;
        if (boot.direct) {
            data = await sendDirect(file, meta, progress);
        } else {
            const form = new FormData();
            form.append('file', file);
            Object.entries(meta).forEach(([k, v]) => form.append(k, String(v)));
            if (poster) form.append('poster', poster, 'poster.jpg');
            data = await send(form, progress);
        }
        ui.progress(1);
        ui.state(data.message || S.uploaded, 'done');
        if (!data.pending && !guestbook && data.item) {
            items.unshift(data.item);
            render([data.item], true);
        }
    } catch (e) {
        if (e.reason === 'pin') lock();
        ui.state(e.status === 429 ? S.rate : e.status === 413 ? fill(S.too_large, { max: limits.video.max_mb }) : e.message || S.failed, 'error');
    }
}

async function uploadAll(files, options) {
    for (const file of Array.from(files || [])) {
        // one at a time: phones on hall Wi-Fi do better than with parallel uploads
        await uploadOne(file, options);
    }
}

['[data-files]', '[data-camera]'].forEach((selector) => {
    const input = $(selector);
    input?.addEventListener('change', async () => {
        await uploadAll(input.files);
        input.value = '';
    });
});
const bookVideo = $('[data-book-video]');
bookVideo?.addEventListener('change', async () => {
    await uploadAll(bookVideo.files, { guestbook: true });
    bookVideo.value = '';
});

/* ---------- guestbook ---------- */
const book = $('[data-guestbook]');
book?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const error = book.querySelector('[data-book-error]');
    const submit = book.querySelector('[type=submit]');
    error.hidden = true;
    submit.disabled = true;
    try {
        const body = { text: book.elements.text.value.trim() };
        if (book.elements.name) body.name = book.elements.name.value.trim();
        const data = await request(boot.urls.guestbook, { method: 'POST', body: JSON.stringify(body) });
        toast(data.message);
        book.elements.text.value = '';
    } catch (e) {
        if (e.reason === 'pin') lock();
        error.textContent = e.status === 429 ? S.rate : e.message || S.failed;
        error.hidden = false;
    } finally {
        submit.disabled = false;
    }
});

/* ---------- PIN ---------- */
const pinForm = $('[data-pin]');
function lock() {
    key = null;
    store.set(null);
    $('[data-unlocked]').hidden = true;
    pinForm.hidden = false;
    pinForm.elements.pin.focus();
}
function unlock() {
    pinForm && (pinForm.hidden = true);
    $('[data-unlocked]').hidden = false;
    load();
}
pinForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const error = pinForm.querySelector('[data-pin-error]');
    error.hidden = true;
    try {
        const data = await request(boot.urls.pin, { method: 'POST', body: JSON.stringify({ pin: pinForm.elements.pin.value.trim() }) });
        key = data.key;
        store.set(key);
        unlock();
    } catch (e) {
        error.textContent = e.status === 429 ? S.rate : e.message || S.pin_wrong;
        error.hidden = false;
    }
});

if (boot.enabled) {
    if (boot.pin && !key) lock();
    else unlock();
}
