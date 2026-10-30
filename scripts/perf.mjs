#!/usr/bin/env node
/*
 * Performance budget (plan section 21), `npm run perf`.
 *
 * Loads the six demo invitations, the landing page and pricing in Chromium on a
 * throttled "mid-range Android on 4G" (Slow 4G: 1.6 Mbps down, 750 kbps up,
 * 150 ms RTT; 4× CPU slowdown; 412×915 phone viewport; empty cache) and prints
 * FCP, LCP, CLS, requests, total transfer, JS (raw and gzip) and font bytes,
 * the median of PERF_RUNS runs. Exits 1 when the budget is exceeded:
 *   - guest invitation pages: JavaScript over 60 KB gzipped
 *   - every page: LCP over 2.5 s (throttled)
 *
 * It uses the server at PERF_URL (default http://127.0.0.1:8018) or starts
 * `php artisan serve` there, with the built assets (npm run build) and the demo
 * data (php artisan migrate --seed outside production).
 *
 *   PERF_URL=http://127.0.0.1:8018 PERF_RUNS=3 PERF_JSON=perf.json npm run perf
 *
 * Note: `php artisan serve` does not compress responses, so "transfer" is what
 * an uncompressed origin would send; behind Cloudflare (Brotli/gzip) pages get
 * smaller and faster. JS is also reported gzipped (what the budget counts).
 */
import { spawn } from 'node:child_process';
import { existsSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import { gzipSync } from 'node:zlib';

const require = createRequire(import.meta.url);
const ROOT = resolve(import.meta.dirname, '..');
const BASE = (process.env.PERF_URL || 'http://127.0.0.1:8018').replace(/\/$/, '');
const RUNS = Math.max(1, Number(process.env.PERF_RUNS || 3));

const BUDGET = { guestJsGzip: 60 * 1024, lcp: 2500 };
const NETWORK = { offline: false, latency: 150, downloadThroughput: (1.6 * 1024 * 1024) / 8, uploadThroughput: (750 * 1024) / 8 };
const CPU_SLOWDOWN = 4;

const PAGES = [
    { name: 'invite: envelope (Layali)', path: '/i/LayaliDemo01', guest: true },
    { name: 'invite: rose (Warda)', path: '/i/WardaDemo001', guest: true },
    { name: 'invite: ring box (Khutuba)', path: '/i/KhutubaDemo1', guest: true },
    { name: 'invite: doors (Henna)', path: '/i/HennaDemo001', guest: true },
    { name: 'invite: gift (Birthday)', path: '/i/BirthdayDemo', guest: true },
    { name: 'invite: diploma (Graduation)', path: '/i/GradDemo0001', guest: true },
    { name: 'landing /', path: '/', guest: false },
    { name: 'pricing /pricing', path: '/pricing', guest: false },
];

function loadPlaywright() {
    for (const id of ['playwright', '/opt/node-tools/node_modules/playwright']) {
        try {
            return require(id);
        } catch {
            // next
        }
    }
    console.error('Playwright not found: npm i -g playwright && npx playwright install chromium');
    process.exit(2);
}

async function up(url) {
    try {
        const response = await fetch(`${url}/up`);
        return response.ok;
    } catch {
        return false;
    }
}

async function ensureServer() {
    if (await up(BASE)) return null;
    const { hostname, port } = new URL(BASE);
    console.log(`Starting php artisan serve on ${hostname}:${port}…`);
    const server = spawn('php', ['artisan', 'serve', `--host=${hostname}`, `--port=${port}`], { cwd: ROOT, stdio: 'ignore' });
    for (let i = 0; i < 50; i++) {
        await new Promise((r) => setTimeout(r, 200));
        if (await up(BASE)) return server;
    }
    server.kill();
    throw new Error(`The app did not start on ${BASE}`);
}

const median = (values) => {
    const sorted = [...values].sort((a, b) => a - b);
    return sorted[Math.floor((sorted.length - 1) / 2)];
};

async function measure(browser, page) {
    const context = await browser.newContext({
        viewport: { width: 412, height: 915 },
        deviceScaleFactor: 2.625,
        isMobile: true,
        hasTouch: true,
        userAgent: 'Mozilla/5.0 (Linux; Android 13; Pixel 6a) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36',
    });
    await context.addInitScript(() => {
        window.__perf = { lcp: 0, cls: 0 };
        new PerformanceObserver((list) => {
            for (const entry of list.getEntries()) window.__perf.lcp = entry.startTime;
        }).observe({ type: 'largest-contentful-paint', buffered: true });
        new PerformanceObserver((list) => {
            for (const entry of list.getEntries()) if (!entry.hadRecentInput) window.__perf.cls += entry.value;
        }).observe({ type: 'layout-shift', buffered: true });
    });

    const tab = await context.newPage();
    const cdp = await context.newCDPSession(tab);
    await cdp.send('Network.enable');
    await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });
    await cdp.send('Network.emulateNetworkConditions', NETWORK);
    await cdp.send('Emulation.setCPUThrottlingRate', { rate: CPU_SLOWDOWN });

    const requests = new Map();
    cdp.on('Network.responseReceived', (e) => requests.set(e.requestId, { type: e.type, url: e.response.url, status: e.response.status }));
    cdp.on('Network.loadingFinished', (e) => {
        const r = requests.get(e.requestId);
        if (r) r.bytes = e.encodedDataLength;
    });

    await tab.goto(BASE + page.path, { waitUntil: 'load', timeout: 60_000 });
    await tab.waitForTimeout(2500);
    const metrics = await tab.evaluate(() => ({
        fcp: performance.getEntriesByName('first-contentful-paint')[0]?.startTime ?? 0,
        lcp: window.__perf.lcp,
        cls: window.__perf.cls,
        load: performance.getEntriesByType('navigation')[0]?.loadEventEnd ?? 0,
    }));

    let js = 0;
    let jsGzip = 0;
    let fonts = 0;
    let total = 0;
    let count = 0;
    for (const [id, r] of requests) {
        if (r.bytes === undefined || r.url.startsWith('data:')) continue;
        count++;
        total += r.bytes;
        if (r.type === 'Font') fonts += r.bytes;
        if (r.type === 'Script') {
            js += r.bytes;
            try {
                const body = await cdp.send('Network.getResponseBody', { requestId: id });
                jsGzip += gzipSync(Buffer.from(body.body, body.base64Encoded ? 'base64' : 'utf8'), { level: 9 }).length;
            } catch {
                jsGzip += r.bytes;
            }
        }
    }

    await context.close();
    return { ...metrics, requests: count, total, js, jsGzip, fonts };
}

const kb = (n) => `${(n / 1024).toFixed(1)} KB`;
const ms = (n) => `${Math.round(n)} ms`;

const { chromium } = loadPlaywright();
if (!existsSync(resolve(ROOT, 'public/build/manifest.json'))) {
    console.error('No build found: run `npm run build` first.');
    process.exit(2);
}
if (existsSync(resolve(ROOT, 'public/hot'))) {
    console.error('public/hot exists (Vite dev server): stop `npm run dev` so the built assets are measured.');
    process.exit(2);
}

const server = await ensureServer();
const browser = await chromium.launch();
const rows = [];
let failed = false;
try {
    for (const page of PAGES) {
        const runs = [];
        for (let i = 0; i < RUNS; i++) runs.push(await measure(browser, page));
        const pick = (key) => median(runs.map((r) => r[key]));
        const row = {
            page: page.name,
            path: page.path,
            fcp: pick('fcp'),
            lcp: pick('lcp'),
            load: pick('load'),
            cls: pick('cls'),
            requests: pick('requests'),
            total: pick('total'),
            js: pick('js'),
            jsGzip: pick('jsGzip'),
            fonts: pick('fonts'),
            problems: [],
        };
        if (page.guest && row.jsGzip > BUDGET.guestJsGzip) row.problems.push(`JS ${kb(row.jsGzip)} gz > ${kb(BUDGET.guestJsGzip)}`);
        if (row.lcp > BUDGET.lcp) row.problems.push(`LCP ${ms(row.lcp)} > ${ms(BUDGET.lcp)}`);
        failed ||= row.problems.length > 0;
        rows.push(row);
        process.stdout.write('.');
    }
} finally {
    await browser.close();
    server?.kill();
}
process.stdout.write('\n\n');

const table = [
    ['Page', 'FCP', 'LCP', 'Load', 'CLS', 'Req', 'Transfer', 'JS', 'JS gz', 'Fonts', 'Budget'],
    ...rows.map((r) => [r.page, ms(r.fcp), ms(r.lcp), ms(r.load), r.cls.toFixed(3), String(r.requests), kb(r.total), kb(r.js), kb(r.jsGzip), kb(r.fonts), r.problems.length ? `FAIL: ${r.problems.join('; ')}` : 'ok']),
];
const widths = table[0].map((_, c) => Math.max(...table.map((row) => row[c].length)));
const line = (row) => `| ${row.map((cell, c) => cell.padEnd(widths[c])).join(' | ')} |`;
console.log(line(table[0]));
console.log(`|${widths.map((w) => '-'.repeat(w + 2)).join('|')}|`);
table.slice(1).forEach((row) => console.log(line(row)));
console.log(`\nSlow 4G (1.6 Mbps, 150 ms RTT), ${CPU_SLOWDOWN}× CPU, 412×915, median of ${RUNS} run(s). Budget: guest JS ≤ ${kb(BUDGET.guestJsGzip)} gz, LCP ≤ ${ms(BUDGET.lcp)}.`);

if (process.env.PERF_JSON) writeFileSync(process.env.PERF_JSON, JSON.stringify(rows, null, 2));

if (failed) {
    console.error('\nPerformance budget exceeded.');
    process.exit(1);
}
console.log('Within budget.');
