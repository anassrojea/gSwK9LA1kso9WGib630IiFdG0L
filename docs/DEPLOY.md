# Deploying Daawa (Hetzner + Laravel Forge + Cloudflare)

How to run the app in production as decided in [`PROJECT_PLAN.md` section 15.2](PROJECT_PLAN.md#152-hosting-and-deployment-hetzner--forge).
Forge's UI changes from time to time; the menu names below describe what to set, not exact click paths.

Contents: [1. Server](#1-provision-the-server) · [2. Sites](#2-create-the-sites) · [3. Environment](#3-environment) ·
[4. Deploy script](#4-deploy-script) · [5. Daemons](#5-daemons-horizon-and-reverb) · [6. Scheduler](#6-scheduler) ·
[7. Backups](#7-database-backups) · [8. Cloudflare](#8-cloudflare) · [9. First deploy](#9-first-deploy-checklist) · [10. Later](#10-scaling-later)

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
every minute). Scheduled today: `horizon:snapshot` every five minutes (`routes/console.php`). Reminders,
expiring transfer orders after 48 h and storage cleanup (plan section 15.1) will be added there.

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
- Built assets in `/build/assets/*` have content hashes in their names.

Rules (Caching → Cache Rules), in this order:

| # | Name | When incoming requests match | Then |
|---|---|---|---|
| 1 | Invite JSON: bypass | `(http.request.uri.path wildcard "/i/*/state") or (http.request.uri.path wildcard "/i/*/rsvp") or (starts_with(http.request.uri.path, "/e/"))` | **Bypass cache** |
| 2 | Invitations | `starts_with(http.request.uri.path, "/i/") and http.request.method eq "GET"` | **Eligible for cache**; Edge TTL: *use cache-control header if present* (300 s); Browser TTL: *respect origin*; Cache key: ignore query string |
| 3 | Build assets | `starts_with(http.request.uri.path, "/build/")` | **Eligible for cache**; Edge TTL 1 month; Browser TTL 1 year |

Everything else (dashboard, `/admin`, `/horizon`, `/login`, `/livewire*`, `/broadcasting/auth`) stays
uncached, which is Cloudflare's default for HTML with cookies; do not add "cache everything" rules for them.

Open tracking keeps working with the edge cache: the page calls `/state?open=1` on every load, which is
never cached, and the server de-duplicates opens per device for 10 minutes.

**Purge on edit** (plan section 6.4): when a host edits an invitation, its `/i/*` pages must be purged
through the Cloudflare API with `CLOUDFLARE_ZONE_ID` and `CLOUDFLARE_API_TOKEN` (permission: Zone → Cache Purge).
Not built yet: it lands with the wizard (`PurgeEdgeCache` job, see `InvitationConfigController`). Until then
the 5-minute edge TTL bounds staleness.

### WAF and rate limiting (free plan)

- Security → WAF → Rate limiting rule: `starts_with(http.request.uri.path, "/i/") and http.request.method eq "POST"`,
  e.g. 20 requests per 10 seconds per IP → Block for 10 s. The app has its own per-token limits
  (`AppServiceProvider::configureRateLimiting`); this stops floods before they reach PHP.
- Managed rules / Bot Fight Mode: on, but check that WhatsApp's link preview fetcher (`WhatsApp/2.x` user agent)
  can still read `/i/*` pages; add a skip rule for it if previews break.

## 9. First deploy checklist

1. Deploy once manually from Forge; watch the deploy log.
2. `php artisan migrate --force` ran (the deploy script does it). Seed the catalog only:
   `php artisan db:seed --class=PlanSeeder --force` and `php artisan db:seed --class=CatalogSeeder --force`.
3. Create your admin: `php artisan daawa:make-admin you@example.com` (prints a temporary password), log in at `/admin`.
4. Check `/up` (health), `/horizon` (admins only), and the browser console on a dashboard page for a working
   Reverb connection.
5. Create a test event with one household and open its `/i/{token}` link through Cloudflare twice: the second
   response should carry `cf-cache-status: HIT`, and the household's open count should still go up only once per device.
6. Uptime monitoring: Forge server monitoring + an external check on `/up` and on one test invitation link.
   Add Sentry (`sentry/sentry-laravel`) and Laravel Pulse when the first real customers arrive (plan section 22).

## 10. Scaling later

- More throughput: Laravel Octane (FrankenPHP or Swoole) on the same server.
- Separate database server, then a second app server behind a Hetzner load balancer (sessions, cache and
  queues are already in Redis, uploads in object storage, so app servers stay stateless).
