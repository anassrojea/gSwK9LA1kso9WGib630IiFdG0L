# Guest links: the open link and the WhatsApp preview

How the two guest-link features of phase 3B work, and the endpoints the event dashboard calls.
Plan references: [section 8](PROJECT_PLAN.md#8-guests-personal-links-and-rsvp) (open link),
[14.1](PROJECT_PLAN.md#141-ideas-the-samples-point-to) and [15.1](PROJECT_PLAN.md#151-application) (personal preview image).

Contents: [1. Open link](#1-open-link) · [2. Host endpoints](#2-host-endpoints-for-the-dashboard) ·
[3. Entitlements](#3-entitlements) · [4. Link preview images](#4-link-preview-images-ogpng)

---

## 1. Open link

One link for everyone, for casual events, with a cap on the total number of people.

**Storage.** Table `open_links` (one row per event, `App\Models\OpenLink`): `token` (16 base62 characters, unique
across open links and household tokens), `enabled`, `cap` (1 to 10 000), and the link's own opens
(`open_count`, `first_opened_at`, `last_opened_at`). Switching the link off keeps the row, so a link already
shared works again when it is switched back on.

**Guest side.** The open-link token is served at the same URLs as a personal token; the guest controllers look for a
household first and hand anything else to `App\Http\Controllers\Invite\OpenLinkController`:

| Route | For an open link |
|---|---|
| `GET /i/{token}` | The same invitation; the RSVP form also asks for the guest's **name**. Edge-cacheable like personal pages. |
| `GET /i/{token}/state[?open=1][&reply={reply_token}]` | `open_link: {seats_left, max_per_reply, full}`, `reply_token`, and, with a reply token, that guest's own reply (`household.name`, `rsvp`). `household.seats_allowed` is how many people this reply may bring now. |
| `POST /i/{token}/rsvp` | `{name, attending, people_count, kids_count?, meal?, message?, allergies?, reply_token?}` |
| `GET /i/{token}/og.png` | The generic preview card ("يسعدنا حضوركم"). |

- Each reply creates a **Household** tagged `open-link` (`display_name` = the typed name, `seats_allowed` = the
  people in the reply, at least 1) plus its **Rsvp**, so the dashboard counts it like any other family.
  `RsvpSubmitted` is broadcast as for personal links.
- **The cap**: the reply runs in a transaction that locks the `open_links` row (`SELECT … FOR UPDATE`), sums the
  attending people of the event's `open-link` households, and rejects with a localized 422 on `people_count`
  ("بقي مكانان فقط." / "نعتذر، اكتمل العدد…") when the reply would go over. Declines use no places.
  One reply brings at most `OpenLink::MAX_PER_REPLY` (10) people.
- **Same device, same reply**: the response carries `reply_token` (the new household's own token). `invite.js`
  stores it in `localStorage` (`daawa:reply:/i/{token}/state`) and sends it back with `/state` and the next POST,
  so the guest edits their reply instead of creating another household. Tokens of other households (personal
  links, other events) are ignored and a new reply is created instead.
- **Rate limits** (`AppServiceProvider`, limiter `invite-rsvp`): per device as for personal links (10 a minute,
  60 an hour, keyed by token + IP), plus 1000 an hour for the whole link. Open-link tokens are recognised by length
  (16), so the limiter needs no query.
- **Opens** are counted on the link (`OpenTracker::recordOpenLink`), deduplicated per device for 10 minutes, never
  for link-preview crawlers.
- The cutoff of the RSVP section applies as usual. Opening the link of an unpublished invitation, an archived
  event or a switched-off link is a 404.

Open-link households have a personal token too (it is their reply token), but no personal link is ever sent for
them: the wizard's review step leaves them out of "Personal links", and they get no preview image of their own.
The dashboard can recognise them by `'open-link' = any(tags)` (`OpenLink::TAG`).

## 2. Host endpoints (for the dashboard)

Both are in `routes/web.php` behind `auth` and `can:update,event` (the event's owner, or an organization owner or
staff member), answer JSON, and are what the wizard's review step uses (`resources/js/components/wizard/OpenLinkPanel.tsx`,
which can be dropped into the dashboard as is: `<OpenLinkPanel initial={settings} title={event.title} />`).

### `GET /events/{event}/open-link` (`events.open-link.show`)

```json
{
  "available": true,          // Entitlements::OPEN_LINK
  "enabled": true,
  "cap": 40,                  // null until first configured
  "max_cap": 300,             // the plan's guest limit (Entitlements::limit($event, 'guests')), at most 10000
  "max_per_reply": 10,
  "url": "https://daawa.link/i/o0W3WQLIW8cbPM6l",   // on INVITE_URL; null until first configured
  "people": 12,               // attending people through the link
  "seats_left": 28,           // null until first configured
  "replies": 5,               // households created by the link (attending or not)
  "opens": 31,
  "first_opened_at": "2026-11-02T18:01:12+03:00",
  "last_opened_at": "2026-11-03T09:44:50+03:00",
  "published": true,          // the link only works once the invitation is published
  "endpoint": "/events/{event}/open-link"
}
```

### `PUT /events/{event}/open-link` (`events.open-link.update`)

Body `{enabled?: boolean, cap?: integer}`; answers the same JSON. A cap is required the first time the link is
switched on; it must be between 1 and `max_cap` (422 on `cap` otherwise). Switching on without the entitlement is
a 403. Lowering the cap below the people already confirmed is allowed: the link then reports `seats_left: 0` and
takes no new attending replies. Throttled to 60 a minute.

The wizard's review step also receives the same payload as the Inertia prop `openLink` (null for visitors who are not
signed in), and publishing is allowed with no households when the open link is on.

## 3. Entitlements

`App\Services\Entitlements` has `OPEN_LINK` (`open_link`), included in every plan, free too (`PlanSeeder`; the
migration `2026_10_31_200200_add_open_link_feature_to_plans` adds it to plans already in the database). It is checked
when the host switches the link on (`App\Services\OpenLinks::available`).

Personal links stay Premium in the plan (section 5) but are **not enforced yet**. When payments land, a later phase should:

1. Gate the writes that create personal households: `GuestController::import/store` (wizard) and the dashboard's guest
   import and add, with `$entitlements->hasFeature($event, Entitlements::PERSONAL_LINKS)`, or let them through and
   gate publishing instead (`WizardController::publish`) with a checkout step.
2. Offer the open link as the free way to send an invitation (the review step already lets a host publish with only
   the open link).
3. Keep open-link replies working on every plan: they create households, but must not be blocked by the personal-link check.
4. Optionally gate the personal preview image (plan section 5 lists "Personal WhatsApp preview" as Premium): in
   `InvitationController::show`, pass `$og->hash($invitation, $config)` without the household for free events, so
   they share the generic card.

## 4. Link preview images (`og.png`)

`GET /i/{token}/og.png` returns a 1200 × 630 PNG: the occasion, the couple's names, the date and, on personal links,
"دعوة خاصة لعائلة خالد العلي" (`OgImage::recipient` attaches لـ to "عائلة/آل/ال…" names, otherwise "إلى …"; English:
"A special invitation for …"), in the theme's colors and fonts. The open link gets the same card with "يسعدنا حضوركم".
`invite/show.blade.php` points `og:image`, `og:image:width/height` and `twitter:card` at it (absolute, on `INVITE_URL`,
with `?v={hash}`).

**Rendering** (`App\Services\OgImage`): `OgImage::html()` renders `resources/views/og/card.blade.php` with the theme's
CSS variables (`ThemeStyles`) and the theme's Google Fonts inlined as `data:` URIs (`FontEmbedder`, downloaded once per
font set into `storage/app/private/og-fonts`, Arabic and Latin subsets only). `Chromium::screenshot()` writes it to a
temporary directory and runs the Chromium binary's own CLI through `symfony/process`:
`--headless --screenshot --window-size=1200,630 --virtual-time-budget=5000`, with every network request blocked
(`--host-resolver-rules=MAP * ~NOTFOUND`, `--no-proxy-server`) and a throwaway profile per run (`HOME` points at the
temporary directory). This was chosen over Browsershot (needs Puppeteer, and Composer here could only clone it) and over
a Node + Playwright script (another runtime and package on the server): the only dependency is the binary, set with
`OG_CHROME_PATH`. Production setup: [DEPLOY.md, section 1 item 7](DEPLOY.md#1-provision-the-server).

**Caching**: on the media disk at `og/{event_id}/{household_id|open}-{hash}.png`. The hash covers the household's name,
the invitation's `updated_at` (so any edit), the theme and the locale, plus `OgImage::VERSION` (bump it when the card
design changes). Writing a new version deletes the old one. `GenerateOgImage` (queued, unique per card until it runs)
warms it when a household is created or renamed, the invitation is published or edited (after `OG_EDIT_DELAY`, 30 s), or
the open link is switched on (`App\Observers\OgImageWarmer`; `OG_WARM=false` turns this off, as in the tests). A request
for a missing image renders it synchronously within `OG_SYNC_TIMEOUT` (one render per image at a time, behind a cache
lock), else redirects to `public/og-fallback.png`. Edge headers: see [DEPLOY.md, Cache rules](DEPLOY.md#cache-rules).
