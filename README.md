# Daawa (دعوة)

Arabic-first interactive digital invitations: weddings, engagements, henna nights, birthdays, graduations.
The full product plan is in [`docs/PROJECT_PLAN.md`](docs/PROJECT_PLAN.md); the original HTML samples are in
[`docs/samples/`](docs/samples/). Production setup: [`docs/DEPLOY.md`](docs/DEPLOY.md).

This branch is the **foundation** (roadmap "Weeks 3–4"): the app skeleton, the database, the invitation
engine with two openings, guest links with RSVP, and the tooling around them.

## Stack

| Layer | Choice |
|---|---|
| Backend | Laravel 12, PHP 8.4, PostgreSQL, Redis |
| Guest invitations | Blade + vanilla JS opening modules (no framework runtime, ~10 KB JS per page) |
| Dashboards | Inertia + React 19 + TypeScript + Tailwind 4 + shadcn/ui |
| Admin | Filament 5 at `/admin` (users with `role = admin`) |
| Auth | Fortify (email + password); Socialite configured for Google |
| Queues / realtime | Horizon, Reverb |
| Tests | Pest 4 (on PostgreSQL), `node:test` for the shared TS logic |

## Run it locally

Requirements: PHP 8.4 with `pdo_pgsql`, `redis` (phpredis) and `intl`; Composer 2; Node 22; PostgreSQL 14+; Redis.

```bash
cp .env.example .env
```

Fill `DB_PASSWORD` (and the `REVERB_APP_*` keys if you want realtime: `php artisan reverb:install` generates them), then:

```bash
createdb daawa && createdb daawa_test
```

```bash
composer install
```

```bash
php artisan key:generate
```

```bash
php artisan migrate --seed
```

```bash
npm ci && npm run build
```

```bash
composer dev
```

`composer dev` runs the web server, Horizon, Reverb, log tailing and Vite together. Then open:

| What | URL |
|---|---|
| Home (lists the demo invitations outside production) | http://localhost:8000 |
| Envelope demo (Layali) | http://localhost:8000/i/LayaliDemo01 |
| Rose demo (Warda) | http://localhost:8000/i/WardaDemo001 |
| Host dashboard | http://localhost:8000/dashboard (`host@daawa.test` / `password`) |
| Admin | http://localhost:8000/admin (`admin@daawa.test` / `password`) |
| Horizon | http://localhost:8000/horizon (admins) |

The demo seeder only runs outside production. To make a real admin anywhere: `php artisan daawa:make-admin you@example.com`.

## Tests

```bash
php artisan test
```

```bash
npm run test:js
```

`php artisan test` runs against the `daawa_test` PostgreSQL database (see `phpunit.xml`): the schema relies on
`jsonb`, `text[]`, GIN indexes and check constraints, so SQLite is not used. `npm run test:js` checks that the
Zod schema and the TypeScript hall generator agree with their PHP counterparts on the same fixtures.
`npm run build` type-checks with `tsc` before bundling. Code style: `./vendor/bin/pint`.

## Where things are

| Path | What |
|---|---|
| `app/Services/InvitationConfig/` | The invitation config: JSON Schema per version (`schemas/v1.json`), validator (runs on every `Invitation` save), version upgrades, theme → CSS variables |
| `resources/js/lib/config-schema.ts` | The same schema in Zod, for the editor |
| `templates/` | Themes and invitation templates as JSON (data, not code), seeded by `CatalogSeeder` |
| `routes/invite.php`, `app/Http/Controllers/Invite/` | Guest routes: `GET /i/{token}`, `GET /i/{token}/state`, `POST /i/{token}/rsvp`, `POST /e/{slug}/rose`. No session or cookies, so pages can be cached at Cloudflare; all rate-limited |
| `resources/views/invite/` | Invitation layout, `openings/` markup and `sections/*.blade.php` partials |
| `resources/js/openings/` | Opening modules with the shared contract `init(stageEl, options)` / `render(progress)`; `resources/js/invite/driver.js` maps scroll to progress and shows the final frame under `prefers-reduced-motion` |
| `app/Services/Entitlements.php` | `hasFeature($event, $feature)` from the plan's limits + the `entitlements` table |
| `app/Services/HallGenerator.php`, `resources/js/lib/hall-generator.ts` | Hall layout generator (plan section 7), identical output in PHP and TS |
| `app/Policies/` | `EventPolicy`, `HouseholdPolicy`, `OrganizationPolicy` |
| `app/Http/Middleware/SetLocale.php` | Locale (Arabic by default, English on request) and `dir="rtl"` |
| `app/Filament/` | Admin (Arabic by default, English from the user menu; strings in `lang/*/admin.php`): Today, orders and transfers, prices, discount codes, customers and their events (read-only), templates, themes, openings, plans, add-ons, audit log |
| `app/Services/Catalog/` | `CatalogSync` (templates/ JSON → database, used by `CatalogSeeder` and the admin's import) and `CatalogValidator` (admin edits checked against the config schema) |
| `app/Services/Payments/Discounts.php` | Discount code rules (twin: `resources/js/lib/discount.ts`) |

### Catalog: files vs admin edits

`templates/` is the source of the catalog and `CatalogSeeder` syncs it into the database, but the admin can
edit openings, themes and templates too. The rule: the seeder inserts missing rows and updates rows nobody saved
in the admin; a row saved in Filament gets `admin_edited_at` and is never overwritten by re-seeding. "Reset to
templates/" on a row (or "Import from templates/" with "Also overwrite admin edits") takes the file's version
again. Tiers live in these tables, so a tier changed in the admin applies to `PlanAdvisor` and `PublishGate` at once.

### Adding an opening

1. Markup: `resources/views/invite/openings/{id}.blade.php` with a `section.opening#opening` and a `[data-stage]` inside.
2. Behaviour: `resources/js/openings/{id}.js` exporting `init(stageEl, options)` and `render(progress)`; everything a pure function of progress, transforms and opacity only.
3. Styles in `resources/css/invite.css` under `.op-{id}`, using the theme variables (`--c-*`, `--f-*`, `--radius`).
4. Mark it active in `CatalogSeeder`. Openings without a module fall back to a still cover.

## Not in this branch

Creation wizard, host/venue dashboards beyond the placeholder, payments (Cashier, bank-transfer approvals),
wallet passes, seating editor, AI features, Google sign-in routes (only the Socialite config is in place),
per-guest OG images, and Cloudflare cache purging on edit. See `docs/PROJECT_PLAN.md` section 23 for the roadmap.
