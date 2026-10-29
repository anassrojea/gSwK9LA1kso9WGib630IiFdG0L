# Invitation config v1 (frozen)

Every invitation is one JSON document in `invitations.config` (plan section 6). **v1 is frozen**: new fields
or changed meanings go into `schemas/v2.json` with an upgrade step in `InvitationConfig::upgrade()`, so
invitations already sent keep rendering.

| Where | What |
|---|---|
| `app/Services/InvitationConfig/schemas/v1.json` | Authoritative JSON Schema (server, on every save) |
| `resources/js/lib/config-schema.ts` | Zod twin for the editor; `npm run test:js` checks they agree |
| `templates/invitations/*.json` | Full examples (`default_config`) |

## Top level

| Key | Required | Notes |
|---|---|---|
| `version` | yes | `1` |
| `occasion` | yes | `wedding`, `engagement`, `henna`, `birthday`, `graduation`, `baby_welcome`, `iftar`, `other` |
| `locale` | yes | `ar` (RTL, Arabic-Indic digits) or `en` |
| `opening` | yes | `id`: `envelope`, `rose`, `ringbox`, `doors`, `gift`, `diploma`; `options`: `color`, `accentColor`, `petalColor`, `sealText`, `kicker`, `headline` |
| `theme` | yes | `id`, `colors` (`bg`, `ink`, `accent`, `soft`, `gold` required; optional `goldLight`, `surface`, `backdrop`, `label`, …), `fonts` (`display`, `body`, `label`: Google Fonts names), `radius` (0–32) |
| `effects` | no | `fallingPetals`, `sparkles`, `music` (booleans) |
| `media` | no | `cover` `{type: image\|video, src, poster?}`, `music` `{src, title?}` |
| `sections` | yes | Ordered list, 1–30 items; every section accepts `hidden: true` |

Media references (`src`) are an `https://` URL, a `/storage/...` path, or `media:<uuid>` for an upload,
which only resolves for uploads of the same event.

## Sections

| `type` | Required | Optional |
|---|---|---|
| `intro_line` | `text` | `style` (`line`, `verse`), `caption` |
| `hosts` | `lines` (1–6) | `label`, `outro` |
| `names` | `first` | `second`, `note`, `age` (birthdays) |
| `date` | `start` (ISO 8601 with offset) | `label`, `showHijri`, `hijriOverride`, `countdown` |
| `story` | `chapters` `[{title, text, year?}]` | `label`, `title`, `style` (`timeline`, `stem`, `letter`, `messages`), `quote` |
| `program` | `items` `[{time "HH:MM", label}]` | `label`, `title` |
| `place` | `name` | `label`, `address`, `lat` + `lng`, `mapUrl` |
| `dress_code` | `colors` or `note` | `label`, `title` |
| `gallery` | `photos` `[{src, caption?, alt?}]` (1–12) | `label`, `title` |
| `favorites` | `items` `[{text, icon?}]` | `label`, `title` |
| `wishes` | `items` `[{text, name?}]` | `label`, `title` |
| `send_rose` | | `label` |
| `rsvp` | | `label`, `title`, `askMessage`, `askAllergies`, `askKids`, `mealOptions` (2–6), `cutoff` |
| `album_teaser` | | `label`, `title`, `text` |
| `closing` | `text` | `label`, `title` |

Labels and titles fall back to the defaults in `lang/{ar,en}/invite.php` when omitted.
