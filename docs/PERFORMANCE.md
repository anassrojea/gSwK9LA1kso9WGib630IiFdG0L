# Performance budget

Plan section 21: the first screen of an invitation in under **2 seconds on 4G** on a mid-range Android, and
**under ~60 KB of JavaScript** on guest pages. `npm run perf` (`scripts/perf.mjs`) checks it.

## Running it

```bash
npm run build                   # the budget is measured on the built assets
php artisan migrate:fresh --seed  # the six demo invitations (outside production)
npm run perf                    # uses the app at PERF_URL (default http://127.0.0.1:8018), or starts `php artisan serve` there
```

Options: `PERF_URL`, `PERF_RUNS` (default 3, the median is reported), `PERF_JSON=perf.json` (raw numbers).
It needs Playwright with Chromium (`npm i -g playwright && npx playwright install chromium`).

For each of the six demo invitations, the landing page and pricing, Chromium loads the page with an empty
cache under **Slow 4G** (1.6 Mbps down, 750 kbps up, 150 ms RTT), a **4× CPU slowdown** and a 412×915 phone
viewport, then reports:

| Column | What |
|---|---|
| FCP / LCP | First / Largest Contentful Paint (ms, throttled) |
| Load | the `load` event (all fonts and images in) |
| CLS | Cumulative Layout Shift |
| Req, Transfer | requests and bytes on the wire |
| JS, JS gz | JavaScript as served and gzipped (what the budget counts) |
| Fonts | web font bytes |

The script **exits 1** when a guest page ships more than 60 KB of gzipped JS or any page's LCP is over
2.5 s. `php artisan serve` does not compress responses, so the transfer column is pessimistic: behind
Cloudflare (Brotli) CSS and HTML are 4–5× smaller.

## Results

Measured 2026-10-30 on the phase 6A branch (median of 3 runs).

Before the changes below:

| Page | FCP | LCP | Load | CLS | Req | Transfer | JS | JS gz | Fonts |
|---|---|---|---|---|---|---|---|---|---|
| invite: envelope (Layali) | 920 ms | 920 ms | 2863 ms | 0.002 | 18 | 459.0 KB | 12.4 KB | 5.1 KB | 392.6 KB |
| invite: rose (Warda) | 860 ms | 860 ms | 2670 ms | 0.003 | 18 | 425.8 KB | 14.2 KB | 5.9 KB | 357.1 KB |
| invite: ring box (Khutuba) | 820 ms | 820 ms | 1321 ms | 0.001 | 14 | 151.2 KB | 12.2 KB | 5.0 KB | 86.8 KB |
| invite: doors (Henna) | 928 ms | 928 ms | 2385 ms | 0.001 | 16 | 363.9 KB | 12.7 KB | 5.2 KB | 299.3 KB |
| invite: gift (Birthday) | 824 ms | 824 ms | 1481 ms | 0.001 | 12 | 180.3 KB | 13.6 KB | 5.7 KB | 114.1 KB |
| invite: diploma (Graduation) | 872 ms | 872 ms | 2310 ms | 0.003 | 16 | 353.3 KB | 13.0 KB | 5.4 KB | 286.0 KB |
| landing / | 848 ms | 848 ms | 1210 ms | 0.000 | 15 | 161.0 KB | 1.9 KB | 0.9 KB | 60.8 KB |
| pricing /pricing | 888 ms | 888 ms | 1119 ms | 0.000 | 11 | 137.0 KB | 1.9 KB | 0.9 KB | 60.8 KB |

After:

| Page | FCP | LCP | Load | CLS | Req | Transfer | JS | JS gz | Fonts |
|---|---|---|---|---|---|---|---|---|---|
| invite: envelope (Layali) | 900 ms | 900 ms | 2844 ms | 0.002 | 18 | 459.0 KB | 12.4 KB | 5.1 KB | 392.5 KB |
| invite: rose (Warda) | 872 ms | 872 ms | 2643 ms | 0.003 | 18 | 425.9 KB | 14.2 KB | 5.9 KB | 357.0 KB |
| invite: ring box (Khutuba) | 828 ms | 828 ms | 1380 ms | 0.001 | 14 | 151.3 KB | 12.2 KB | 5.0 KB | 86.7 KB |
| invite: doors (Henna) | 812 ms | 812 ms | 2345 ms | 0.002 | 16 | 364.0 KB | 12.7 KB | 5.2 KB | 299.3 KB |
| invite: gift (Birthday) | 860 ms | 860 ms | 1438 ms | 0.001 | 12 | 180.4 KB | 13.6 KB | 5.7 KB | 114.1 KB |
| invite: diploma (Graduation) | 888 ms | 888 ms | 2311 ms | 0.003 | 16 | 353.4 KB | 13.0 KB | 5.4 KB | 285.9 KB |
| landing / | 888 ms | 888 ms | 1248 ms | 0.000 | 15 | 161.0 KB | 1.9 KB | 0.9 KB | 60.8 KB |
| pricing /pricing | 816 ms | 816 ms | 1145 ms | 0.000 | 11 | 137.0 KB | 1.9 KB | 0.9 KB | 60.8 KB |

Every page is within budget: the first screen paints in under a second on throttled Slow 4G, guest pages
ship 5–6 KB of gzipped JS (a tenth of the budget) and nothing shifts. The two runs differ by noise only:
the guest pages were already light, and the changes below shave round trips that this lab setup (one
local origin) barely shows.

## What was changed

- **Opening chunk preloaded** (`App\Support\InviteAssets`, one `<link rel="modulepreload">` in
  `invite/show.blade.php`): the opening's module downloads in parallel with `invite.js` instead of after it,
  so the opening can start one round trip earlier on a real network.
- **Public site built light**: Blade with a Tailwind build limited to `resources/views/marketing`
  (5 KB gzipped CSS), 0.9 KB of JS (the cookie banner; analytics only after consent), no React.
  Almarai in the two weights used, the brand's Katibeh font subset with `text=` to its own letters,
  `display=swap` and `preconnect`. Opening posters are 432×768 WebP (5–19 KB), with `width`/`height`,
  `loading="lazy"` and `decoding="async"`; the hero poster is `fetchpriority="high"`.
- **Images**: `public/og-fallback.png` re-encoded from 181 KB to 63 KB (256 colours, no visible change).
  The site's OG cards are 58–64 KB.
- **Guest pages**: no analytics or Sentry on `/i/*`; the RSVP privacy link and the "صمّم دعوتك" link add no JS.

## Known costs and next steps

- **Web fonts are the heaviest part of an invitation** (87–393 KB): each theme loads three Google Fonts
  families in weights 400 and 700, and the invitation CSS uses both weights for every role. They load
  after the first paint (`display=swap`), so they don't affect FCP/LCP, but they are most of the bytes.
  Options, in order of payoff: self-host per-theme subsets with `pyftsubset` (Arabic + the Latin used);
  drop weight 700 for the display families that are calligraphic (Aref Ruqaa, Katibeh) and let the CSS use
  400; or a `text=` request for the opening's display text. Each needs a design check of the themes.
- **Built assets must be cached for a year** (`/build/*` file names carry content hashes). `php artisan serve`
  sends no cache headers; in production Nginx and Cloudflare do (see `docs/DEPLOY.md`, "Caching headers for
  built assets", and cache rule 4).
- Guest page HTML is cached at the edge for 5 minutes and purged on edits (`PurgeEdgeCache`), so most
  guests get it from Cloudflare's nearest location instead of the origin in Germany.
