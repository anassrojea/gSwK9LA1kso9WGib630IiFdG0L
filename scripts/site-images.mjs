#!/usr/bin/env node
/*
 * Regenerates the public site's images from the running app (demo data seeded):
 *   public/images/openings/{id}.webp   poster of each opening (first screen of its demo invitation)
 *   public/images/og-site-{ar,en}.png  Open Graph card of the site (1200×630)
 *   public/images/logo-512.png         logo for the Organization JSON-LD
 *
 *   APP_URL=http://127.0.0.1:8018 node scripts/site-images.mjs
 *
 * Needs Playwright (global install is fine) and ImageMagick's `convert` for WebP.
 */
import { execFileSync } from 'node:child_process';
import { mkdirSync, rmSync, statSync } from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

const require = createRequire(import.meta.url);
const { chromium } = loadPlaywright();

const ROOT = resolve(import.meta.dirname, '..');
const BASE = (process.env.APP_URL || 'http://127.0.0.1:8018').replace(/\/$/, '');
const OPENINGS = {
    envelope: 'LayaliDemo01',
    rose: 'WardaDemo001',
    ringbox: 'KhutubaDemo1',
    doors: 'HennaDemo001',
    gift: 'BirthdayDemo',
    diploma: 'GradDemo0001',
};
// how far into the opening the poster is taken (0 = closed, 1 = fully open)
const PROGRESS = Number(process.env.POSTER_PROGRESS || 0.22);

function loadPlaywright() {
    for (const id of ['playwright', '/opt/node-tools/node_modules/playwright', `${process.env.NODE_PATH || ''}/playwright`]) {
        try {
            return require(id);
        } catch {
            // next
        }
    }
    throw new Error('Playwright not found: npm i -g playwright (or set NODE_PATH)');
}

function webp(input, output, width, height, quality = 70) {
    execFileSync('convert', [input, '-resize', `${width}x${height}^`, '-gravity', 'center', '-extent', `${width}x${height}`, '-strip', '-quality', String(quality), '-define', 'webp:method=6', output]);
}

const tmp = join(tmpdir(), `daawa-site-images-${process.pid}`);
mkdirSync(tmp, { recursive: true });
mkdirSync(join(ROOT, 'public/images/openings'), { recursive: true });

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
try {
    const phone = await browser.newContext({ viewport: { width: 360, height: 640 }, deviceScaleFactor: 1.5, locale: 'ar' });
    for (const [id, token] of Object.entries(OPENINGS)) {
        const page = await phone.newPage();
        await page.goto(`${BASE}/i/${token}`, { waitUntil: 'networkidle' });
        await page.evaluate(() => document.fonts.ready);
        await page.evaluate((progress) => {
            const opening = document.getElementById('opening');
            if (opening) window.scrollTo(0, (opening.offsetHeight - window.innerHeight) * progress);
        }, PROGRESS);
        await page.waitForTimeout(700);
        const png = join(tmp, `${id}.png`);
        await page.screenshot({ path: png });
        const out = join(ROOT, `public/images/openings/${id}.webp`);
        webp(png, out, 432, 768, 68);
        console.log(`${out.replace(ROOT + '/', '')}  ${(statSync(out).size / 1024).toFixed(1)} KB`);
        await page.close();
    }

    const card = await browser.newContext({ viewport: { width: 1200, height: 630 }, deviceScaleFactor: 1 });
    for (const locale of ['ar', 'en']) {
        const page = await card.newPage();
        const poster = (id) => `${BASE}/images/openings/${id}.webp`;
        const title = locale === 'ar' ? 'دعوات رقمية متحرّكة لكل مناسبة' : 'Animated digital invitations';
        const sub = locale === 'ar' ? 'رابط لكل عائلة، تأكيد الحضور، واتساب' : 'A link per family · RSVPs · WhatsApp';
        await page.setContent(`<!doctype html><html lang="${locale}" dir="${locale === 'ar' ? 'rtl' : 'ltr'}"><head>
            <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Almarai:wght@400;700&family=Katibeh&display=block">
            <style>
              *{margin:0;box-sizing:border-box}
              body{width:1200px;height:630px;display:flex;align-items:center;gap:56px;padding:0 80px;font-family:Almarai,sans-serif;
                background:radial-gradient(700px 420px at 90% 0,#f3d5dc,transparent 60%),radial-gradient(600px 400px at 0 100%,#f4e6c8,transparent 55%),#fbf6f2;color:#2a1219}
              .copy{flex:1}
              .brand{font-family:Katibeh,serif;font-size:120px;line-height:1;color:#5a1a2c}
              .latin{font-family:Katibeh,serif;font-size:40px;color:#c8a45d;margin-inline-start:16px}
              h1{font-size:54px;line-height:1.25;margin-top:28px;color:#5a1a2c}
              p{font-size:28px;margin-top:20px;color:#6e5a60}
              .phones{display:flex;gap:18px}
              .phones img{width:190px;height:338px;object-fit:cover;border-radius:26px;border:8px solid #1d0b11;box-shadow:0 30px 50px -20px rgba(90,26,44,.5)}
              .phones img:nth-child(2){transform:translateY(-28px)}
            </style></head><body>
            <div class="copy"><span class="brand">دعوة</span><span class="latin">Daawa</span><h1>${title}</h1><p>${sub}</p></div>
            <div class="phones"><img src="${poster('envelope')}"><img src="${poster('rose')}"></div>
            </body></html>`, { waitUntil: 'networkidle' });
        await page.evaluate(() => document.fonts.ready);
        const out = join(ROOT, `public/images/og-site-${locale}.png`);
        const png = join(tmp, `og-${locale}.png`);
        await page.screenshot({ path: png });
        execFileSync('convert', [png, '-strip', '-define', 'png:compression-level=9', '-colors', '256', out]);
        console.log(`${out.replace(ROOT + '/', '')}  ${(statSync(out).size / 1024).toFixed(1)} KB`);
        await page.close();
    }

    const logo = await browser.newContext({ viewport: { width: 512, height: 512 } });
    const page = await logo.newPage();
    await page.setContent(`<!doctype html><html><head><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Katibeh&display=block">
        <style>*{margin:0}body{width:512px;height:512px;display:grid;place-items:center;background:#5a1a2c;font-family:Katibeh,serif;color:#fbf6f2;font-size:220px;line-height:1}</style>
        </head><body>دعوة</body></html>`, { waitUntil: 'networkidle' });
    await page.evaluate(() => document.fonts.ready);
    const out = join(ROOT, 'public/images/logo-512.png');
    await page.screenshot({ path: join(tmp, 'logo.png') });
    execFileSync('convert', [join(tmp, 'logo.png'), '-strip', '-colors', '64', out]);
    console.log(`${out.replace(ROOT + '/', '')}  ${(statSync(out).size / 1024).toFixed(1)} KB`);
} finally {
    await browser.close();
    rmSync(tmp, { recursive: true, force: true });
}
