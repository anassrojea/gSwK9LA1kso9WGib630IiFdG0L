# Guest extras (phase 7B)

The event album and live photo wall, wallet passes, and AI writing. Plan sections 10, 11 and 14.3.

## Album and photo wall

- **Unlocked by** the `photo_wall` feature (Signature) or a photo cloud add-on (entitlement `photo_cloud`). The wall needs `photo_wall`.
- **Guests** open `/i/{token}/album`: a household link, the open link, or the album's own link (`events.album_token`, 14 characters, shown as a QR on the wall when the open link is off). A table QR can point at `/i/{token}?upload=1`; the invitation redirects to the album.
- **Uploads** open when the event starts, or earlier with "upload before the event". Photos are compressed in the browser (`resources/js/lib/image-compress.ts`, 2560 px WebP/JPEG). Videos are checked for length in the browser and for size on the server.
  - On the local disk the browser POSTs to `/i/{token}/uploads`.
  - On S3 (`MEDIA_DISK` with the `s3` driver) the same endpoint returns a signed PUT URL (`temporaryUploadUrl`), and `/i/{token}/uploads/complete` records the file.
- **Files** live on `config('filesystems.media_disk')` under `events/{event_id}/album/` (thumbnails in `album/thumbs/`, ZIPs in `exports/`), so the storage-expiry job deletes them with the event's media. Guests get signed URLs that expire: `/m/{media}/{variant}` locally, `temporaryUrl` on S3.
- **Thumbnails**: `MakeThumbnails` (GD) writes 400 px and 1200 px WebP files, or JPEG where PHP's GD has no WebP support. A video uses the poster frame its browser sends.
- **Moderation**: with "approve photos before they show", uploads stay `hidden` until the host approves them on `/dashboard/events/{event}/album`.
- **Wall**: `/e/{slug}/wall` (route `wall.show`). New photos are announced on the public Reverb channel `public-wall.{slug}` (event `media.uploaded`, with ids only), and the wall polls `/e/{slug}/wall/feed` as a fallback. A private wall needs `?k={secret}` (the link on the album page).
- **ZIP export**: `POST /events/{event}/album/export` queues `ExportAlbumZip`. When it finishes, the host gets an email with a signed link (7 days), and the album page shows the download.
- **Guestbook**: text messages from the album page. Video messages (up to 30 s) need the `video_guestbook` add-on; they are stored as media with source `guestbook` and never shown in the album or on the wall.

## Wallet passes

Shown on the RSVP ticket to attending families with a personal link, when the event has the `wallet` feature (Signature, or the wallet add-on). The web pass `/i/{token}/pass` needs only the feature.

### Apple Wallet

The owner provides:

1. An Apple Developer account and a **Pass Type ID** (e.g. `pass.com.daawa.invite`). Set `APPLE_PASS_TYPE_ID` and `APPLE_TEAM_ID`.
2. The Pass Type ID **certificate**, exported from Keychain as a `.p12` with its password. Set `APPLE_PASS_CERT_PATH` and `APPLE_PASS_CERT_PASSWORD`. A PEM file holding both the certificate and its key also works.
3. Apple's **WWDR intermediate certificate** (G4), converted to PEM. Set `APPLE_PASS_WWDR_PATH`.

`App\Services\Wallet\ApplePass` builds `pass.json`, the icon and strip images (the strip comes from the OG Chromium renderer, with a plain GD fallback), `manifest.json` and a detached DER `signature` (`openssl_pkcs7_sign`). It zips them with no extra package.

**Next step: pass updates.** Serial numbers and `authenticationToken`s are already stored in `wallet_passes`. `UpdateWalletPasses` is dispatched when the event time or hall changes, or a family's table changes; for now it only logs. To push updates:

1. Add `webServiceURL` to `pass.json`.
2. Implement Apple's web service:
   - `POST/DELETE /v1/devices/{device}/registrations/{passType}/{serial}` (store device push tokens)
   - `GET /v1/devices/{device}/registrations/{passType}?passesUpdatedSince=`
   - `GET /v1/passes/{passType}/{serial}` (rebuild the pass)
   - `POST /v1/log`
3. Have `UpdateWalletPasses` send an empty APNs push to each registered device.

### Google Wallet

The owner provides:

1. A Google Wallet **issuer account** (free). Set `GOOGLE_WALLET_ISSUER_ID`.
2. A Google Cloud **service account** with the Wallet API enabled and access to the issuer. Download its JSON key and set `GOOGLE_WALLET_SERVICE_ACCOUNT_JSON` to the file path.

`GET /i/{token}/wallet/google` redirects to `https://pay.google.com/gp/v/save/{jwt}`. The JWT is signed RS256 with the service account key, and carries the event's `eventTicketClass` and the family's `eventTicketObject` inline. The hero image is `/e/{slug}/wallet-strip.png`.

**Next step:** PATCH the class and objects through the REST API from `UpdateWalletPasses`.

## AI writing (Claude)

The owner provides `ANTHROPIC_API_KEY`. `AI_FAKE=true` binds a canned writer for local demos; it is never used in production.

| Feature | Where | Model | Effort | Output |
|---|---|---|---|---|
| Story | wizard → story section → "Write it for me" | `claude-sonnet-5-5` | medium | chapters `{year, title, text}` |
| Poem (add-on `ai_poem`) | wizard → opening line section | `claude-sonnet-5-5` | medium | `{poem, caption}` → intro_line verse |
| Guest-list cleanup | wizard guests step, dashboard "Add guests" | `claude-haiku-5-5` | low | `{families: [{name, seats, phone, notes}]}` |

Each feature runs as a queued job (`AiWriteStory`, `AiWritePoem`, `AiCleanGuestList`) that the page polls through `GET /ai/generations/{id}`.

- **Requests** carry a stable system prompt cached with `cache_control: ephemeral`, and structured output through `output_config.format` (JSON schema). They never send `temperature`, `top_p`, `top_k` or `thinking`.
- **Validation**: refusals and `max_tokens` cut-offs are recorded and shown as errors. Every output is validated and trimmed by `App\Services\Ai\AiOutput` to the v1 schema (story chapters, intro_line ≤ 2000 characters) and to the guest import rules (phones normalized).
- **Never auto-saved**: results are suggestions. The host accepts them, then they save through the normal wizard autosave or guest import.
- **Costs**: every call is recorded in `ai_generations`, with tokens in `input.usage` and `cost_cents` computed from `config/ai.php` prices (Sonnet 5.5 $2 / $10 per MTok, Haiku 5.5 $0.10 / $0.50 per MTok), rounded up to whole cents.
- **Limits**: per user and per event per hour (`AI_PER_USER_HOURLY`, `AI_PER_EVENT_HOURLY`), plus a monthly allowance per plan (`config/ai.php` → `monthly`).

The AI poem add-on is in `config/payments.php` and `PlanSeeder`, with placeholder SAR/SYP prices. On an existing database, run `php artisan db:seed --class=PlanSeeder`; it keeps prices an admin already set.

Local demo data: `php artisan db:seed --class=GuestExtrasDemoSeeder`, after the main seed.
