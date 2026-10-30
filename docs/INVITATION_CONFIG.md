# Invitation config (v1 frozen, v2 current)

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

## Openings

Each opening is `resources/views/invite/openings/{id}.blade.php` plus `resources/js/openings/{id}.js`
(`init(stage, options)`, `render(progress)`). What the generic options tint, and their theme fallbacks:

| `id` | `color` | `accentColor` | `kicker` | `headline` |
|---|---|---|---|---|
| `envelope` | envelope (`accent`) | seal (`gold`) | line above the headline | headline |
| `rose` | — (`petalColor`: petals) | — | — | headline |
| `ringbox` | velvet box (`accent`) | ring metal (`gold`) | line above the names | headline |
| `doors` | doors (`accent`) | henna drawing and name (`label`) | line above the name | headline |
| `gift` | wrapping (`label`) | ribbon, bow, age badge (`gold`) | line above the age | headline |
| `diploma` | ribbon and seal (`#9E2B2B`) | rods and gold details (`gold`) | first line of the certificate | headline |

The gift shows `names.age` in its badge (the first letter of the name when there is no age).

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

## v2 (phase 8D, current)

`schemas/v2.json` is v1 with only these changes. Everything else (openings, theme, effects, media, every v1
section) is identical.

| Change | Values |
|---|---|
| `version` | `2` |
| `occasion` | v1's list plus `katb_kitab`, `eid`, `housewarming`, `hajj_return`, `welcome_home`, `corporate`, `save_the_date`, `thank_you` |
| `kind` (new, optional) | `invitation` (the default when absent), `save_the_date`, `thank_you` |
| section `thank_you_gallery` (new) | required `message` (≤ 2000); optional `photos` `[{src, caption?, alt?}]` (0–24, `media:<uuid>` of album photos), `signature` (≤ 120), `label`, `title` |

### Versions side by side

- `InvitationConfigValidator::SUPPORTED_VERSIONS = [1, 2]`, `CURRENT_VERSION = 2`. Each document is checked
  against **its own** version: a v1 document stays valid as v1 (it may not use v2 occasions, `kind` or
  `thank_you_gallery`), and renders as before.
- The Zod twin exports `invitationConfigSchemaV1` and `invitationConfigSchemaV2`; `validateConfig()` picks one by
  `version`. `npm run test:js` checks both against the same fixtures as the Pest tests
  (`tests/Fixtures/config-example.json` for v1, `config-example-v2.json` for v2) and that the enums match the JSON.
- The validator's API is unchanged (`validate`, `isValid`, `assertValid`, `schemaId`, `schemaPath`).

### Upgrade rule (v1 → v2)

`InvitationConfig::upgrade()` sets `version` to 2 and changes nothing else (`upgradeConfig()` in TypeScript does
the same). v2 only adds optional things, so every v1 document is a valid v2 document once its version says so.

Where it runs:

- **Rendering** (`Invitation::renderConfig()`): every config is upgraded in memory; the stored v1 JSON is untouched.
- **The wizard writes v2**: `WizardController` hands the editor the upgraded config, `useAutosave` upgrades
  what it loads, and `UpdateInvitationConfigRequest` upgrades an older document before validating, so a v1 draft
  is saved back as v2 on its next autosave. New drafts (`DraftCreator`) are v2, whatever their template's version.
- Templates in `templates/invitations/` keep their own version: the phase 1–7 ones stay v1, the 8D ones are v2.

### Kinds

| `kind` | Made by | Notes |
|---|---|---|
| `invitation` | the wizard | the default |
| `save_the_date` | the wizard tile "احفظ الموعد" (template `save-the-date-envelope`) | names, date (with Hijri), the city as `place.name`, a closing "the invitation follows". No RSVP: the wizard never offers `rsvp` or `send_rose`, and publishing refuses them (`App\Services\Journey\SaveTheDate`). Published with the `save_the_date` product. |
| `thank_you` | dashboard → Keepsakes (`thank_you_cards.config`, template `thank-you-rose`) | names, a `thank_you_gallery` and a closing; the event's opening and theme. Needs the `thank_you` add-on. |
