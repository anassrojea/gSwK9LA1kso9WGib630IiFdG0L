# After the event and the family journey (phase 8D)

Save the date, the thank-you card, the highlight video, the printed photo book, family journey bundles, the
loyalty discount, referrals and the anniversary email. Plan sections 5, 11, 13.2, 14.2, 14.5 and 14.7.
Config: `config/journey.php`, `config/keepsakes.php`. Strings: `lang/{ar,en}/journey.php`, `keepsakes.php`.

## Order line types (payments core, additive)

Plans and add-ons are still fulfilled by `OrderFulfillment` itself. Every other line type is a small class
implementing `App\Services\Payments\Items\OrderItemHandler` (`fulfill`, `refund`, `name`, `discountable`),
registered with `OrderItemHandlers` (`JourneyServiceProvider::ITEMS`). `OrderFulfillment` calls the registry
with one line in `complete()` (after `grant()`) and one in `refund()`. `OrderPresenter::itemName` and
`TransferGateway::itemsLabel` ask the handler for the line's name, so orders, transfer notes and invoices
(`Invoicer` uses the presenter) name every line. Unknown types are paid and invoiced with their snapshot name.

| Type | Sold as | Handler |
|---|---|---|
| `save_the_date`, `thank_you`, `highlight_video` | add-ons with `item` in `config/payments.php` | `FeatureItem`: entitlement with the order as source |
| `photo_book` | `photo_book_20` / `photo_book_40`, quantity = copies | `PhotoBookItem`: the book goes `draft → pending` |
| `bundle` | `POST /bundles/{bundle}/checkout`, no event | `BundleItem`: issues the credits; refund voids unused ones |
| `bundle_credit` | negative line paying the plan line | `BundleCreditItem`: marks the credit used; refund releases it |
| `referral_credit` | negative line, account credit | `ReferralCreditItem`: ledger spend; refund restores |
| `loyalty`, `referral_welcome` | negative line, % of the plan | `PlanReductionItem`: name only |

Another feature adds its types from its own provider:
`$this->app->afterResolving(OrderItemHandlers::class, fn ($h) => $h->register('type', Handler::class))`.

### Placeholder prices (`PlanSeeder`, `JourneySeeder`; SAR halalas, SYP whole pounds)

| Item | USD | SAR | SYP |
|---|---|---|---|
| Save the date | 5 | 19 | 60,000 |
| Thank-you card + highlight gallery | 7 | 25 | 85,000 |
| Highlight video | 15 | 55 | 180,000 |
| Photo book, 20 pages (per copy) | 49 | 185 | 590,000 |
| Photo book, 40 pages (per copy) | 79 | 299 | 950,000 |
| Bundle: engagement + henna + wedding (2 Premium + 1 Signature, $137 separately) | 109 | 409 | 1,300,000 |
| Bundle: wedding + baby welcome (1 Signature + 1 Premium, $108 separately) | 89 | 335 | 1,070,000 |
| Referral reward (referrer's credit) | 5 | 19 | 60,000 |

Loyalty 10% and referral welcome 10% (`JOURNEY_LOYALTY_PERCENT`, `JOURNEY_REFERRAL_WELCOME_PERCENT`).

## Checkout rules (`App\Services\Journey\CheckoutExtras`, twin `resources/js/lib/journey.ts`)

1. A bundle credit (exact plan tier, not expired, not held by an unpaid order) adds a `bundle_credit` line equal
   to the plan line. The code is dropped. With nothing else to pay the total is zero and the order is paid at once
   through `OrderFulfillment::fulfillFree`, like a 100% code.
2. Otherwise the better automatic reduction (`loyalty`: a paid plan on another event; `referral_welcome`: a referred
   user's first paid order) competes with the discount code: whichever takes off more applies, never both.
3. "Use my credit" adds a `referral_credit` line, up to the available balance in the charged currency.

## Save the date

An event whose invitation config has kind `save_the_date` (wizard tile "احفظ الموعد", template
`save-the-date-envelope`). No RSVP (the wizard never offers it; `PlanAdvisor` reports `save_the_date_rsvp`).
Publishing needs the `save_the_date` product instead of a plan; a paid one sends personal links. "Start the full
invitation" (`POST /events/{event}/save-the-date/upgrade {occasion}`) makes a new draft (`events.source_event_id`)
from the occasion's template with the names, date (and Hijri setting), the city as the address, and every family
with a new link.

## Thank-you card

`thank_you_cards` (one per event): a v2 config with kind `thank_you` (names, date, `thank_you_gallery`, closing),
drafted from the invitation with nine photos spread over the evening. Published only with the `thank_you`
add-on. Guests see it at `/t/{token}` (household or open link), and `/i/{token}` turns into it
`THANK_YOU_SHOW_AFTER_HOURS` (12) after the event starts when "show on the invitation" is on. The HTML is
cacheable: the family's name comes from `/i/{token}/state` (not counted as an open). Album photos are served
through their signed preview URLs.

## Highlight video

`BuildHighlightVideo` (queued): the album's visible photos (hidden and unapproved never), spread over the evening,
up to three guest clips, a title card rendered by Chromium (Arabic shaped; a plain card without Chromium), and
`HighlightBuilder` runs ffmpeg: one segment per part with a Ken Burns zoom, joined with crossfades, the admin's
track faded in and out. `HighlightPlan` aims at `HIGHLIGHT_TARGET_SECONDS` (75) within 60–90 s; few photos give a
shorter video, never longer than 90 s. Vertical 1080×1920 and wide 1920×1080. `FFMPEG_PATH`, `HIGHLIGHT_TIMEOUT`,
`HIGHLIGHT_MAX_MB`, `HIGHLIGHT_PRESET`, `HIGHLIGHT_CRF`, `HIGHLIGHT_FPS`. Files: `events/{id}/highlight/` on the
media disk (purged by `events:expire-storage`). The host gets signed download links by email
(`keepsakes.video.link_days`), and the album page previews the video.

**Music:** none ships with the app. Admin → Catalog → Video music: upload licensed tracks (MP3/M4A) and record the
licence. Hosts pick one or none.

## Photo book

The host picks photos (or auto-pick), title, A4 landscape or 21×21 cm, 20 or 40 pages, copies and the shipping
address, then orders through the normal gateways. `PhotoBookPdf` prints the PDF with Chromium: page = trim +
bleed (`PHOTO_BOOK_BLEED_MM`, 3) + slug with crop marks (`PHOTO_BOOK_SLUG_MM`, 6; `PHOTO_BOOK_CROP_MARKS`),
originals resampled down to `PHOTO_BOOK_DPI` (300). Admin → Sales → Photo books: download or regenerate the PDF and
move a paid book `pending → printing → shipped → delivered` (or cancel) with tracking notes the host sees.

## Bundles, referrals, anniversary

- **Bundles** are managed in Admin → Catalog → Bundles (credits per plan, prices per currency, validity). Credits
  last `JOURNEY_BUNDLE_VALIDITY_DAYS` (730). Hosts buy them and see their credits on `/account/journey`.
- **Referrals:** `/r/{code}` sets the `daawa_ref` cookie for 30 days; so does "صمّم دعوتك" on invitations of hosts
  who opted in. Sign-up (any method) records the referral; same normalised email, same phone, or same IP + same
  email domain is blocked as a self-referral. The first paid order (total above zero) credits the referrer, at
  most `JOURNEY_REFERRAL_MONTHLY_CAP` per month. `/account/journey` shows the link, stats and credit; the Billing
  tab has an "Invite friends" panel.
- **Anniversary:** `journey:anniversaries` (daily, idempotent through `anniversary_reminders`) emails hosts on the
  date of each event that took place, reopening the invitation and, while kept, the album, with the loyalty
  discount. Signed unsubscribe link plus one-click `List-Unsubscribe` set `users.marketing_opt_out`.

Local demo: `php artisan migrate:fresh --seed` runs `JourneyDemoSeeder` (`/t/WardaDemo001`, `/i/SaveDateDemo`,
`/account/journey` for host@daawa.test).
