/*
 * The live photo wall (resources/views/album/wall.blade.php, phase 7B): a
 * slideshow of the newest photos for the hall screen. New uploads arrive over
 * Reverb (public channel public-wall.{slug}, event "media.uploaded"); the
 * feed is also polled, so the wall keeps working when Reverb is down.
 */
import Pusher from 'pusher-js';

import { mergeFeed, nextSlide } from './lib/album';

const boot = JSON.parse(document.getElementById('wall-boot')?.textContent || '{}');
const stage = document.querySelector('[data-stage]');
const strip = document.querySelector('[data-strip]');
const empty = document.querySelector('[data-empty]');
const side = document.querySelector('[data-side]');
const count = document.querySelector('[data-count]');
const live = document.querySelector('[data-live]');
const SLIDE_MS = 8000;

let photos = [];
let index = 0;
let timer = null;
let pending = [];

async function fetchFeed() {
    const response = await fetch(boot.feed, { headers: { Accept: 'application/json' }, credentials: 'omit' });
    if (!response.ok) throw new Error(String(response.status));
    return response.json();
}

function preload(src) {
    return new Promise((resolve) => {
        const img = new Image();
        img.onload = () => resolve(true);
        img.onerror = () => resolve(false);
        img.src = src;
    });
}

async function show(i, fresh = false) {
    const photo = photos[i];
    if (!photo) return;
    await preload(photo.src);

    const slide = document.createElement('figure');
    slide.className = 'w-slide' + (fresh ? ' new' : '');
    const bg = document.createElement('div');
    bg.className = 'bg';
    bg.style.backgroundImage = `url("${photo.src}")`;
    const img = Object.assign(document.createElement('img'), { src: photo.src, alt: '' });
    slide.append(bg, img);
    if (photo.name) {
        const caption = document.createElement('figcaption');
        caption.textContent = photo.name;
        slide.appendChild(caption);
    }
    stage.appendChild(slide);
    requestAnimationFrame(() => requestAnimationFrame(() => slide.classList.add('on')));
    // keep the previous slide underneath until the new one has faded in
    [...stage.querySelectorAll('.w-slide')].slice(0, -2).forEach((old) => old.remove());
    setTimeout(() => [...stage.querySelectorAll('.w-slide')].slice(0, -1).forEach((old) => old.remove()), 1400);

    strip.querySelectorAll('li').forEach((li) => li.classList.toggle('on', li.dataset.id === photo.id));
}

function renderStrip() {
    strip.replaceChildren(...photos.slice(0, 20).map((photo) => {
        const li = document.createElement('li');
        li.dataset.id = photo.id;
        li.appendChild(Object.assign(document.createElement('img'), { src: photo.thumb || photo.src, alt: '', loading: 'lazy' }));
        return li;
    }));
}

function schedule() {
    clearTimeout(timer);
    timer = setTimeout(() => {
        // a photo that just arrived jumps the queue
        if (pending.length) {
            index = Math.max(0, photos.findIndex((p) => p.id === pending.shift()));
            show(index, true);
        } else {
            index = nextSlide(index, photos.length);
            show(index);
        }
        schedule();
    }, SLIDE_MS);
}

async function refresh() {
    try {
        const data = await fetchFeed();
        const firstLoad = photos.length === 0;
        const { list, added } = mergeFeed(photos, data.items);
        photos = list;
        if (count) count.textContent = data.count_label || '';
        empty.hidden = photos.length > 0;
        side.hidden = photos.length === 0;
        renderStrip();
        if (firstLoad && photos.length) {
            index = 0;
            show(0);
            schedule();
        } else if (added.length) {
            pending.push(...added.filter((id) => !pending.includes(id)));
            if (!timer) schedule();
        }
    } catch {
        // offline for a moment: the next poll tries again
    }
}

/* ---------- realtime, with polling as the fallback ---------- */
let pollEvery = (boot.poll || 15) * 1000;
let pollTimer = null;
const poll = () => {
    clearTimeout(pollTimer);
    pollTimer = setTimeout(async () => {
        await refresh();
        poll();
    }, pollEvery);
};

function connect() {
    const r = boot.reverb || {};
    if (!r.key || !r.host) return;
    try {
        const tls = (r.scheme || 'https') === 'https';
        const pusher = new Pusher(r.key, {
            wsHost: r.host,
            wsPort: r.port || 80,
            wssPort: r.port || 443,
            forceTLS: tls,
            enabledTransports: tls ? ['wss'] : ['ws'],
            disableStats: true,
            cluster: '',
        });
        pusher.connection.bind('state_change', ({ current }) => {
            live.hidden = current !== 'connected';
            // live: poll rarely, only to catch what a dropped connection missed
            pollEvery = (current === 'connected' ? 60 : boot.poll || 15) * 1000;
            if (current === 'unavailable' || current === 'failed') pusher.disconnect();
        });
        pusher.subscribe(boot.channel).bind('media.uploaded', () => refresh());
    } catch {
        // no realtime: polling carries on
    }
}

document.querySelector('[data-fullscreen]')?.addEventListener('click', () => document.documentElement.requestFullscreen?.().catch(() => {}));

refresh().then(poll);
connect();
