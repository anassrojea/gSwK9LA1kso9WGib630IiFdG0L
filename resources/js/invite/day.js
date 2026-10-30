/*
 * Event-day parts of the guest page (phase 7A), filled from GET /i/{token}/state:
 * the guest's table ("طاولة ٧") with a small hall map, their door QR code on the
 * RSVP ticket, and the "today" panel (resources/views/invite/partials/live-day.blade.php)
 * inside the event-day window. Plain DOM, no framework: the page HTML stays cacheable.
 */

const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);

/**
 * A small SVG of the hall: every table as a dot, the guest's one highlighted.
 * map = {w, h, stage: [x, y, w, h] | null, tables: [[number, x, y, r] | [number, x, y, r, w, h], …], mine}
 * A table with a size (banquet table, sofa, row) is a w × h rectangle centred on x, y.
 */
function shape(cls, t, grow) {
    const [, x, y, r, w, h] = t;
    if (w === undefined) return `<circle class="${cls}" cx="${x}" cy="${y}" r="${r + grow}"/>`;
    const rw = w + grow * 2;
    const rh = h + grow * 2;
    return `<rect class="${cls}" x="${x - rw / 2}" y="${y - rh / 2}" width="${rw}" height="${rh}" rx="${Math.min(8, rh / 3)}"/>`;
}

export function hallMap(map, digits = String) {
    const { w, h, stage, tables = [], mine } = map;
    const labels = tables.length <= 40;
    let svg = `<svg viewBox="-8 -8 ${w + 16} ${h + 16}" aria-hidden="true"><rect class="m-hall" width="${w}" height="${h}" rx="18"/>`;
    if (stage) svg += `<rect class="m-stage" x="${stage[0]}" y="${stage[1]}" width="${stage[2]}" height="${stage[3]}" rx="10"/>`;
    let me = null;
    for (const t of tables) {
        const [n, x, y, r] = t;
        if (String(n) === String(mine)) {
            me = t;
            continue;
        }
        svg += shape('m-t', t, 0);
        if (labels) svg += `<text class="m-n" x="${x}" y="${y}" font-size="${Math.round(r * 0.75)}">${esc(digits(n))}</text>`;
    }
    if (me) {
        const [n, x, y, r] = me;
        svg += shape('m-ring', me, 9) + shape('m-mine', me, 2)
            + `<text class="m-mine-n" x="${x}" y="${y}" font-size="${Math.round(r * 1.05)}">${esc(digits(n))}</text>`;
    }
    return `${svg}</svg>`;
}

function tableLabel(el, number, digits) {
    if (el) el.textContent = (el.dataset.label || ':n').replace(':n', digits(number));
}

function showLink(el, href) {
    if (!el) return;
    el.hidden = !href;
    if (href) el.href = href;
}

function showQr(figure, src) {
    if (!figure) return;
    figure.hidden = !src;
    const img = figure.querySelector('img');
    if (src && img.getAttribute('src') !== src) img.src = src;
}

/**
 * @param {object} state  the /state payload (table, seat, qr, live)
 * @param {(n: number|string) => string} digits  numbers in the page's script
 */
export function renderDay(state, digits) {
    const seat = state.seat || null;
    const number = seat?.number ?? (state.table ? String(state.table).replace(/^T/, '') : null);
    const map = seat?.map ? hallMap(seat.map, digits) : '';

    // the RSVP ticket
    if (number !== null) tableLabel(document.querySelector('[data-table]'), number, digits);
    const ticketMap = document.querySelector('[data-table-map]');
    if (ticketMap) ticketMap.innerHTML = map;
    showQr(document.querySelector('[data-qr]'), state.qr);

    // the "today" panel
    const panel = document.querySelector('[data-live]');
    if (!panel) return;
    const live = state.live;
    panel.hidden = !live;
    if (!live) return;

    showLink(panel.querySelector('[data-live-directions]'), live.directions);
    showLink(panel.querySelector('[data-live-stream]'), live.stream);
    showLink(panel.querySelector('[data-live-wall]'), live.wall);

    const seatBox = panel.querySelector('[data-live-seat]');
    if (seatBox) {
        seatBox.hidden = number === null;
        if (number !== null) tableLabel(panel.querySelector('[data-live-table]'), number, digits);
        panel.querySelector('[data-live-map]').innerHTML = map;
    }
    showQr(panel.querySelector('[data-live-qr]'), state.qr);
}
