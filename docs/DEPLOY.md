# Deploying Daawa (Hetzner + Laravel Forge + Cloudflare)

How to run the app in production as decided in [`PROJECT_PLAN.md` section 15.2](PROJECT_PLAN.md#152-hosting-and-deployment-hetzner--forge).
Forge's UI changes from time to time; the menu names below describe what to set, not exact click paths.

Contents: [1. Server](#1-provision-the-server) · [2. Sites](#2-create-the-sites) · [3. Environment](#3-environment) ·
[4. Deploy script](#4-deploy-script) · [5. Daemons](#5-daemons-horizon-and-reverb) · [6. Scheduler](#6-scheduler) ·
[7. Backups](#7-database-backups) · [8. Cloudflare](#8-cloudflare) · [9. First deploy](#9-first-deploy-checklist) ·
[10. Payments](#10-payments) · [11. Later](#11-scaling-later)

---

## 1. Provision the server

In Forge, create a server on **Hetzner Cloud**:

| Setting | Value |
|---|---|
| Type | App server |
| Size | ~4 GB RAM to start (e.g. CPX21 / CX32). Resize later from Hetzner. |
| Region | Falkenstein / Nuremberg / Helsinki (Hetzner has no Middle East region; Cloudflare caching covers guests in the region) |
| PHP | **8.4** |
| Database | **PostgreSQL** (latest version Forge offers; the app needs 14+, tested on 16) |
| Cache / queues | **Redis** (installed by Forge on app servers) |

Then, on the server:

1. **Database**: create database `daawa` and user `daawa` with a strong password (Forge → Database).
2. **PHP extensions**: Forge's PHP 8.4 includes `pgsql`, `redis`, `intl`, `mbstring`, `bcmath`, `gd`, `zip`. `intl` is required (Hijri dates and Arabic month names use ICU).
3. **Node**: Node 22 LTS (Forge → Server → Node / `n`), used only at deploy time for `npm run build`.
4. **Firewall**: Forge's firewall opens 22, 80 and 443. Restrict 80/443 to
   [Cloudflare's IP ranges](https://www.cloudflare.com/ips/) and 22 to your own IP. This matters:
   the app trusts `X-Forwarded-For` from any proxy (`bootstrap/app.php`, `trustProxies(at: '*')`)
   because Cloudflare sits in front, so the origin must not be reachable directly. Rate limits
   on guest links key on the client IP. Do not open Reverb's port (8080); Nginx proxies to it locally.
5. **OPcache** is on by default on Forge. Keep it on.
6. **Upload limits**: the wizard accepts photos up to 10 MB (compressed in the browser first), music up to 10 MB
   and cover videos up to 50 MB. Raise PHP's limits (Forge → Server → PHP → Max File Upload Size: **60 MB**,
   which sets `upload_max_filesize` and `post_max_size`) and Nginx's `client_max_body_size 60M;` in the site config.
   Cloudflare's free plan accepts request bodies up to 100 MB.
7. **Chromium** for the WhatsApp link preview images (`/i/{token}/og.png`, plan sections 14.1 and 15.1).
   The app runs Chromium's own `--headless --screenshot` CLI through `symfony/process` on a small Blade view
   (`resources/views/og/card.blade.php`), so Arabic is shaped by a real browser. No Node, Puppeteer or Browsershot
   is needed at runtime, only the binary. Install Google Chrome from its `.deb` (it adds Google's apt repository,
   so `apt upgrade` keeps it patched):

   ```bash
   wget -q https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb
   sudo apt-get install -y ./google-chrome-stable_current_amd64.deb fonts-noto-core
   rm google-chrome-stable_current_amd64.deb
   ```

   Do **not** use Ubuntu's `chromium-browser` package: it is a snap, and snaps get a private `/tmp`, so Chromium
   cannot read the page the app writes there. `fonts-noto-core` brings Noto Naskh/Kufi Arabic, the fallback when the
   theme's Google Fonts cannot be downloaded (the app downloads each font set once into `storage/app/private/og-fonts`
   and inlines it; Chromium itself never touches the network).

   The app finds `/usr/bin/google-chrome-stable` by itself; set `OG_CHROME_PATH` for any other location (e.g. a
   `chrome-headless-shell` from `npx @puppeteer/browsers install chrome-headless-shell@stable`). If renders fail with
   "No usable sandbox" (Ubuntu 24.04 restricts unprivileged user namespaces), set `OG_CHROME_NO_SANDBOX=true`: the
   page is the app's own HTML with every request blocked, so the sandbox adds little here. Check it as the `forge` user:

   ```bash
   php artisan tinker --execute="echo app(App\Services\OgImage\Chromium::class)->binary();"
   curl -sI https://daawa.link/i/<a token>/og.png   # 200 image/png (a 302 to /og-fallback.png means the render failed: see the log)
   ```

## 2. Create the sites

| Site | Domain(s) | Branch | Notes |
|---|---|---|---|
| Production | `daawa.app` (alias the short link domain, e.g. `daawa.link`) | `main` | Zero-downtime deployments on |
| Staging | `staging.daawa.app` | `staging` | Separate database `daawa_staging`, `APP_ENV=staging` |

For each site: project type **Laravel**, web directory `/public`, repository from GitLab
(the owner mirrors this branch there), **zero-downtime deployments enabled**, "Install Composer
dependencies" off (the deploy script does it).

Guest links use the short domain: set `INVITE_URL=https://daawa.link` so the dashboard and the
seeder print short links. Both domains serve the same app.

## 3. Environment

Forge → Site → Environment holds the `.env`; it never goes into Git. Start from `.env.example` and set at least:

```dotenv
APP_NAME=Daawa
APP_ENV=production
APP_KEY=            # php artisan key:generate --show (once), then keep it forever
APP_DEBUG=false
APP_URL=https://daawa.app
INVITE_URL=https://daawa.link
APP_LOCALE=ar
APP_FALLBACK_LOCALE=en

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=daawa
DB_USERNAME=daawa
DB_PASSWORD=...

REDIS_CLIENT=phpredis
SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis
BROADCAST_CONNECTION=reverb

# Reverb: the server listens locally, browsers connect through Nginx on 443
REVERB_APP_ID=...          # generate once: php artisan reverb:install (or random strings)
REVERB_APP_KEY=...
REVERB_APP_SECRET=...
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
REVERB_HOST=daawa.app
REVERB_PORT=443
REVERB_SCHEME=https

GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=https://daawa.app/auth/google/callback

MAIL_MAILER=resend
RESEND_API_KEY=...
MAIL_FROM_ADDRESS=hello@daawa.app

# Object storage for uploads (Hetzner Object Storage or Cloudflare R2)
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=auto
AWS_BUCKET=daawa-media
AWS_ENDPOINT=https://<account>.r2.cloudflarestorage.com

CLOUDFLARE_ZONE_ID=...
CLOUDFLARE_API_TOKEN=...   # token with Zone → Cache Purge only

# Link preview images (config/og.php); defaults shown
OG_CHROME_PATH=            # empty: /usr/bin/chromium, /usr/bin/google-chrome-stable, …
OG_CHROME_NO_SANDBOX=false
OG_TIMEOUT=30              # seconds per render in the queue
OG_SYNC_TIMEOUT=8          # seconds og.png waits for a missing image before redirecting to /og-fallback.png

# Payments (config/payments.php, section 10 below). A card gateway is off while any of its keys is empty.
PAYMENTS_WHATSAPP_NUMBER=9665XXXXXXXX     # receives transfer receipts; digits only
PAYMENTS_BANK_SAR_ACCOUNT_NAME=...        # one account per currency: SYP, SAR, USD
PAYMENTS_BANK_SAR_BANK=...
PAYMENTS_BANK_SAR_IBAN=SA...
PAYMENTS_BANK_SYP_ACCOUNT_NUMBER=...
PAYMENTS_BANK_SYP_NOTE=...                # e.g. the local transfer company accepted in Syria
STRIPE_KEY=pk_live_...
STRIPE_SECRET=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
LEMON_SQUEEZY_API_KEY=...
LEMON_SQUEEZY_STORE=12345
LEMON_SQUEEZY_SIGNING_SECRET=...
LEMON_SQUEEZY_VARIANT_ID=67890

# Invoices and VAT (config/invoicing.php, docs/INVOICES.md). Empty VAT number: receipts without VAT.
INVOICE_SELLER_NAME_AR=...                # legal name as on the commercial registration
INVOICE_SELLER_NAME_EN=...
INVOICE_SELLER_ADDRESS_AR=...
INVOICE_SELLER_ADDRESS_EN=...
INVOICE_CR_NUMBER=
INVOICE_VAT_NUMBER=                       # 15 digits once VAT-registered
INVOICE_PRICES_INCLUDE_VAT=true
INVOICE_VAT_CURRENCIES=SAR

# Storage period (config/retention.php): days between deleting an event's media and archiving it
STORAGE_ARCHIVE_GRACE_DAYS=30
```

The `VITE_*` variables in `.env.example` are read at build time by `npm run build`, so they must be
present in the site's environment before deploying.

Never set `SEED_DEMO=true` in production: the demo seeder creates accounts with the password `password`.

## 4. Deploy script

Forge → Site → Deployments → Deploy script. With zero-downtime deployments Forge provides the release
macros; keep the ones it generates and put the build steps between them:

```bash
$CREATE_RELEASE()

cd $FORGE_RELEASE_DIRECTORY

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci
npm run build

$FORGE_PHP artisan migrate --force
$FORGE_PHP artisan optimize
$FORGE_PHP artisan filament:optimize

$ACTIVATE_RELEASE()

# pick up the new code in long-running processes
$FORGE_PHP artisan horizon:terminate
$FORGE_PHP artisan reverb:restart
```

Notes:

- `npm run build` runs `tsc --noEmit` first, so a type error fails the deploy before anything is activated.
  `npm ci` installs devDependencies (Vite, TypeScript), which the build needs.
- `composer install` runs `filament:upgrade` (post-autoload-dump), which publishes Filament's assets into
  `public/` for the release (they are git-ignored).
- `php artisan optimize` caches config, routes, views and events. Run `php artisan optimize:clear` if you
  ever change `.env` and see stale values.
- Turn on **Quick deploy** for `main` only after the first manual deploy succeeds.

## 5. Daemons (Horizon and Reverb)

Forge → Server → Daemons (supervisor). Use the site's `current` directory so each release is picked up:

| Daemon | Command | Directory | User |
|---|---|---|---|
| Horizon | `php8.4 artisan horizon` | `/home/forge/daawa.app/current` | forge |
| Reverb | `php8.4 artisan reverb:start --host=127.0.0.1 --port=8080` | `/home/forge/daawa.app/current` | forge |

(Newer Forge versions can also enable Horizon and Reverb per site; either way, one process each.)

Horizon's dashboard is at `/horizon` and is gated to `role = admin` (`App\Providers\HorizonServiceProvider`).

**Nginx proxy for Reverb**: browsers connect to `wss://daawa.app/app/...`. If Forge's Reverb integration
is not used, add this to the site's Nginx config inside the `server { listen 443 ... }` block:

```nginx
location ~ ^/(app|apps)/ {
    proxy_http_version 1.1;
    proxy_set_header Host $http_host;
    proxy_set_header Scheme $scheme;
    proxy_set_header SERVER_PORT $server_port;
    proxy_set_header REMOTE_ADDR $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_read_timeout 120s;
    proxy_pass http://127.0.0.1:8080;
}
```

Cloudflare passes WebSockets through on all plans (Network → WebSockets: on).

## 6. Scheduler

Forge → Server → Scheduler: enable the Laravel scheduler for the site (`php8.4 /home/forge/daawa.app/current/artisan schedule:run`
every minute). Scheduled today (`routes/console.php`): `horizon:snapshot` every five minutes, and `orders:expire`
every fifteen minutes (transfer orders with no receipt 48 h after checkout become `expired`, and the customer is
emailed), `account:purge-deleted` daily, and `events:expire-storage` daily at 04:23 (storage warnings 14 and 3 days
ahead, media deletion after `events.storage_until`, archive after the grace period; run it with `--dry-run` first on a
new server, see [INVOICES.md, section 3](INVOICES.md#3-storage-expiry)).

## 7. Database backups

Forge → Server → Database Backups:

1. Storage provider: **Custom / S3-compatible** pointed at **Hetzner Object Storage**
   (endpoint e.g. `https://fsn1.your-objectstorage.com`, a private bucket such as `daawa-backups`,
   an access key limited to that bucket).
2. Databases: `daawa` (and `daawa_staging` on the staging server).
3. Frequency: **daily**; retention: **30 days**.

**Test a restore before launch**, and again after big schema changes:

```bash
# on a scratch server or locally, with the downloaded .sql.gz
createdb daawa_restore
gunzip -c daawa-backup.sql.gz | psql daawa_restore
DB_DATABASE=daawa_restore php artisan migrate:status   # every migration should show "Ran"
psql daawa_restore -c "select count(*) from households; select count(*) from rsvps;"
dropdb daawa_restore
```

Media lives in object storage, not in these backups; enable versioning or lifecycle rules on the media bucket separately.

## 8. Cloudflare

### DNS and SSL

1. Add `daawa.app` (and `daawa.link`) to Cloudflare; point the nameservers at Cloudflare.
2. DNS: `A` records for `@`, `www`, `staging` (and `daawa.link` `@`) to the server IP, **proxied** (orange cloud).
3. SSL/TLS mode: **Full (strict)**.
4. SSL/TLS → Origin Server → **Create Certificate** (RSA, hostnames `daawa.app, *.daawa.app, daawa.link, *.daawa.link`,
   15 years). In Forge → Site → SSL → **Install existing certificate**, paste the certificate and private key.
   Full (strict) then validates the origin against Cloudflare's CA.
5. Edge Certificates: **Always Use HTTPS** on, minimum TLS 1.2. Enable HSTS once everything works over HTTPS.
6. Optional extra lock: SSL/TLS → Origin Server → **Authenticated Origin Pulls**, with Nginx requiring the client certificate.

### Cache rules

Guest invitations are built to be cached at the edge (plan sections 6.4 and 21):

- `GET /i/{token}` returns HTML with `Cache-Control: public, max-age=0, s-maxage=300`, **no cookies**
  (the `invite` middleware group has no session), and `X-Robots-Tag: noindex`. Nothing personal is in
  the HTML: the guest's name, seats and RSVP come from `GET /i/{token}/state`.
- `GET /i/{token}/state` and every `POST` answer `Cache-Control: no-store, private`.
- `GET /i/{token}/og.png` (the link preview image) is linked from the page as `og.png?v={hash}`; the hash changes
  whenever the guest's name, the invitation or its theme changes. With the current `v` it answers
  `Cache-Control: public, max-age=86400, s-maxage=31536000, immutable`; with any other `v` (or none) only 5 minutes.
  So its cache key **must include the query string**, unlike the HTML. It is never counted as an open.
- Built assets in `/build/assets/*` have content hashes in their names.

Rules (Caching → Cache Rules), in this order. When several rules match, the later one wins for each setting, so the
`og.png` rule must come after "Invitations":

| # | Name | When incoming requests match | Then |
|---|---|---|---|
| 1 | Invite JSON: bypass | `(http.request.uri.path wildcard "/i/*/state") or (http.request.uri.path wildcard "/i/*/rsvp") or (starts_with(http.request.uri.path, "/e/"))` | **Bypass cache** |
| 2 | Invitations | `starts_with(http.request.uri.path, "/i/") and http.request.method eq "GET"` | **Eligible for cache**; Edge TTL: *use cache-control header if present* (300 s); Browser TTL: *respect origin*; Cache key: ignore query string |
| 3 | Invite preview images | `http.request.uri.path wildcard "/i/*/og.png"` | **Eligible for cache**; Edge TTL: *use cache-control header if present*; Browser TTL: *respect origin*; Cache key: **include the query string** (all parameters) |
| 4 | Build assets | `starts_with(http.request.uri.path, "/build/")` | **Eligible for cache**; Edge TTL 1 month; Browser TTL 1 year |

Preview images are rendered ahead of time by the `GenerateOgImage` job (Horizon, `default` queue) when a household
is created or renamed, an invitation is published or edited (30 s later, so autosaves render once) or the open link
is switched on. They are stored on the media disk under `og/{event_id}/{household_id|open}-{hash}.png`; the previous
version is deleted when a new one is written. A request for a missing image renders it on the spot (one render per
image at a time, at most `OG_SYNC_TIMEOUT` seconds), otherwise it redirects to the static `public/og-fallback.png`.

Everything else (dashboard, `/admin`, `/horizon`, `/login`, `/livewire*`, `/broadcasting/auth`, `/webhooks/*`) stays
uncached, which is Cloudflare's default for HTML with cookies; do not add "cache everything" rules for them.

Open tracking keeps working with the edge cache: the page calls `/state?open=1` on every load, which is
never cached, and the server de-duplicates opens per device for 10 minutes. Link-preview fetchers
(`WhatsApp/`, `facebookexternalhit`, `TelegramBot`, `Twitterbot`, `Slackbot`, `Discordbot`) are never counted
(`App\Services\OpenTracker::CRAWLERS`). An event's open link counts its opens on the link itself (`open_links`).

**Purge on edit** (plan section 6.4): when a host edits an invitation, its `/i/*` pages must be purged
through the Cloudflare API with `CLOUDFLARE_ZONE_ID` and `CLOUDFLARE_API_TOKEN` (permission: Zone → Cache Purge).
Not built yet: it lands with the wizard (`PurgeEdgeCache` job, see `InvitationConfigController`). Until then
the 5-minute edge TTL bounds staleness.

### WAF and rate limiting (free plan)

- Security → WAF → Rate limiting rule: `starts_with(http.request.uri.path, "/i/") and http.request.method eq "POST"`,
  e.g. 20 requests per 10 seconds per IP → Block for 10 s. The app has its own per-token limits
  (`AppServiceProvider::configureRateLimiting`); this stops floods before they reach PHP.
- **Payment webhooks must reach the app untouched.** Add a WAF custom rule *before* the others:
  `starts_with(http.request.uri.path, "/webhooks/")` → **Skip** (all remaining custom rules, rate limiting rules,
  managed rules, Super Bot Fight Mode / Bot Fight Mode, Browser Integrity Check and Security Level), and a cache rule
  `starts_with(http.request.uri.path, "/webhooks/")` → **Bypass cache**. Stripe and Lemon Squeezy cannot solve a
  challenge, so a challenged webhook is simply lost until they retry. The app verifies every signature itself and
  throttles `/webhooks/*` at 300 a minute per IP. Bot Fight Mode (free plan) cannot be skipped per path: if it is on,
  watch the webhook delivery logs in Stripe and Lemon Squeezy, and turn it off if deliveries get 403s.
- Managed rules / Bot Fight Mode: on, but check that WhatsApp's link preview fetcher (`WhatsApp/2.x` user agent)
  can still read `/i/*` pages and `/i/*/og.png`; add a skip rule for it if previews break. Facebook's Sharing
  Debugger (or sending the link to yourself on WhatsApp) shows the card as WhatsApp will.

## 9. First deploy checklist

1. Deploy once manually from Forge; watch the deploy log.
2. `php artisan migrate --force` ran (the deploy script does it). Seed the catalog only:
   `php artisan db:seed --class=PlanSeeder --force` and `php artisan db:seed --class=CatalogSeeder --force`.
   `CatalogSeeder` is safe to run on every deploy: it inserts openings, themes and templates missing from the
   database and updates the ones nobody edited in the admin; a row saved in Filament (`admin_edited_at` set) is
   kept and listed as "Kept admin edits". To take a file's version again, use "Reset to templates/" on that row
   (or "Import from templates/" with "Also overwrite admin edits") in the admin.
3. Create your admin: `php artisan daawa:make-admin you@example.com` (prints a temporary password), log in at `/admin`.
4. Check `/up` (health), `/horizon` (admins only), and the browser console on a dashboard page for a working
   Reverb connection.
5. Create a test event with one household and open its `/i/{token}` link through Cloudflare twice: the second
   response should carry `cf-cache-status: HIT`, and the household's open count should still go up only once per device.
6. Uptime monitoring: Forge server monitoring + an external check on `/up` and on one test invitation link.
   Add Sentry (`sentry/sentry-laravel`) and Laravel Pulse when the first real customers arrive (plan section 22).

## 10. Payments

Plan section 12. Three methods behind `App\Services\Payments\PaymentGateway`, listed in `config/payments.php`:
bank transfer with the receipt on WhatsApp (approved by an admin in `/admin` → Orders → *Transfers waiting*), Stripe
through Laravel Cashier, and Lemon Squeezy. Each card gateway is switched off until all its keys are set, and the
checkout only shows the methods that are on. Card data never reaches the server.

**Bank transfer.** Set `PAYMENTS_WHATSAPP_NUMBER` and, for each currency you accept, `PAYMENTS_BANK_{SYP|SAR|USD}_*`
(`ACCOUNT_NAME`, `BANK`, `IBAN` or `ACCOUNT_NUMBER`, optional `SWIFT` and `NOTE`). A currency without an IBAN or
account number is not offered for transfers. Approve **only after seeing the money in the bank account**: the
approval asks for the bank reference, which can approve one order only, and every decision is in the audit log.

**Stripe** (Dashboard → Developers):

1. API keys → `STRIPE_KEY` (publishable) and `STRIPE_SECRET` (secret; a restricted key needs write access to
   Customers and Checkout Sessions).
2. Webhooks → Add endpoint `https://daawa.app/webhooks/stripe`, events `checkout.session.completed` and
   `checkout.session.async_payment_succeeded`. Copy its signing secret into `STRIPE_WEBHOOK_SECRET`.
3. Stripe charges SAR and USD; SYP orders are charged the USD price (Stripe does not serve Syria) and the
   checkout says so.

**Lemon Squeezy** (Settings):

1. API → new key → `LEMON_SQUEEZY_API_KEY`; Stores → the store id (the number after `#`) → `LEMON_SQUEEZY_STORE`.
2. Products → one product "Daawa order" with a single-payment variant (any price: every checkout sets its own
   `custom_price`). Its variant id → `LEMON_SQUEEZY_VARIANT_ID`. The store currency must be USD (`LEMON_SQUEEZY_CURRENCY`).
3. Webhooks → URL `https://daawa.app/webhooks/lemon-squeezy`, events `order_created` and `order_refunded`, a signing
   secret of your choice → `LEMON_SQUEEZY_SIGNING_SECRET`. The order code travels in `checkout_data.custom.order_code`.

**Prices.** `PlanSeeder` writes USD prices from the plans and **placeholder** SAR and SYP prices, shown as
"Placeholder" in `/admin` → Prices until an admin saves them. Set the real ones there before launch (SYP is typed in
whole pounds, SAR and USD may have two decimals). Re-seeding never overwrites a price saved in the admin.

**Test-mode checklist** (staging, with test keys; nothing here needs real money):

1. Stripe test keys in the staging `.env`, and a test-mode webhook endpoint pointing at staging. Locally:
   `stripe listen --forward-to http://127.0.0.1:8015/webhooks/stripe` and use the secret it prints.
2. Create an event, pick Premium, pay by card with `4242 4242 4242 4242`: the success page shows "Confirming your
   payment…" until the webhook arrives, then the order is `paid`, the plan is set and publishing unlocks.
3. Resend the same event from the Stripe dashboard: nothing changes (one `payments` row).
4. Choose SYP and Stripe: the checkout shows the USD price, the order has `currency=USD, requested_currency=SYP`.
5. Lemon Squeezy test mode: same flow with its test card; resend the webhook from Settings → Webhooks.
6. Bank transfer: create an order, tap *Send receipt on WhatsApp* (check the number, code and amount in the
   message), then *I sent the receipt*; approve it in `/admin` with a bank reference; try the same reference on another
   order (refused); reject one with a reason; check the emails in the log (`MAIL_MAILER=log`) or the mail provider.
7. Leave an order without a receipt, run `php artisan orders:expire` after moving the clock (or wait 48 h): it expires.
8. Check that `/webhooks/*` returns 403 (bad signature), not a Cloudflare challenge page: `curl -i -X POST https://staging.daawa.app/webhooks/stripe`.

**Invoices.** Every paid or approved order gets a receipt, or a ZATCA phase-1 simplified tax invoice once
`INVOICE_VAT_NUMBER` is set; refunds get a credit note. PDFs are printed by the same Chromium as the link previews.
ZATCA phase 2 (e-invoicing integration) is **not** implemented. Details, rounding rules and what the owner must
provide: [INVOICES.md](INVOICES.md).

## 11. Scaling later

- More throughput: Laravel Octane (FrankenPHP or Swoole) on the same server.
- Separate database server, then a second app server behind a Hetzner load balancer (sessions, cache and
  queues are already in Redis, uploads in object storage, so app servers stay stateless).
