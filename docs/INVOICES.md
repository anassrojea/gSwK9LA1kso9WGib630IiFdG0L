# Compliance: email verification, invoices and VAT, storage expiry

Phase 6B. Plan references: [section 11](PROJECT_PLAN.md#11-photos-and-the-event-album) (storage period),
[12](PROJECT_PLAN.md#12-payments), [13](PROJECT_PLAN.md#13-pricing-and-monetization),
[15.1](PROJECT_PLAN.md#151-application) (PDFs through headless Chrome), [20](PROJECT_PLAN.md#20-security-privacy-and-legal).

Contents: [1. Email verification](#1-email-verification) · [2. Invoices and VAT](#2-invoices-and-vat) ·
[3. Storage expiry](#3-storage-expiry) · [4. What the owner must provide](#4-what-the-owner-must-provide)

---

## 1. Email verification

Fortify's `emailVerification` feature is on and `User` implements `MustVerifyEmail`.

| Account | Verified |
|---|---|
| Email + password sign-up | When the link in the verification email is opened (`GET /email/verify/{id}/{hash}`, signed) |
| Google sign-in | At sign-in (Google says the address is verified) |
| Email login code | At sign-in (the code proves the inbox) |
| Email changed in Account → Profile with a password | Unverified again; a new link is sent at once |
| Email changed with a code (accounts without a password) | Verified (the code was sent to the new address) |

**What it gates.** `POST /checkout` and `POST /create/{event}/publish` call `App\Services\Account\EmailVerification::ensure`:
an unverified account goes back with the error `verify_email` (JSON callers get a 403 with the same message). Drafts,
autosave, uploads and guest lists never need it. The React pages read `auth.user.email_verified` (shared on every page)
and show `components/account/VerifyEmailNotice` instead of the pay / publish button, with a resend button; the
profile tab shows it under an unverified address.

**Pages and routes** (Fortify): `GET /email/verify` renders `auth/VerifyEmail` (Arabic and English);
`POST /email/verification-notification` resends the link. The named limiter `verification`
(`FortifyServiceProvider`) allows 3 resends per 10 minutes per account and 6 link opens a minute. The email is
localized (`app.verify.mail.*`, in the account's language).

**Admins** must be verified to reach `/admin` (the panel's `->emailVerification()`; Filament shows its own prompt at
`/admin/email-verification/prompt`). `php artisan daawa:make-admin` marks the address verified.

Accounts created before this phase with a password and no verification will be asked to verify the first time they pay
or publish.

## 2. Invoices and VAT

### Documents

`App\Services\Invoicing\Invoicer` issues one document per order, inside the same database transaction that marks it
`paid` or `approved` (`OrderFulfillment::complete`), and a **credit note** when the order is refunded
(`OrderFulfillment::refund`, from the admin's *Mark refunded* or a Lemon Squeezy refund webhook). Both are idempotent.

| Type | When |
|---|---|
| `simplified_tax_invoice` ("فاتورة ضريبية مبسطة") | The seller is VAT-registered (`INVOICE_VAT_NUMBER` set) and VAT applies to the order |
| `receipt` ("إيصال") | Everything else: not registered yet, or a currency / country outside VAT scope |

VAT applies when the seller is registered, the order currency is in `INVOICE_VAT_CURRENCIES` (default `SAR`) and, if
`INVOICE_VAT_COUNTRIES` is set, the customer's country (`users.country`) is listed. So by default SYP and USD orders get
receipts with no VAT. A credit note keeps the invoice's type and has its own series.

Table `invoices` (one row per document, never edited): `number`, `series`, `sequence`, `kind`
(`invoice` | `credit_note`), `type`, `credit_for_id`, `order_id`, `user_id`, snapshots of the `customer`
(name, email, country) and the `seller` (config at the time), `lines`, `currency`, `gross_cents`, `discount_cents`,
`subtotal_cents` (net), `vat_cents`, `total_cents`, `vat_rate_bp`, `prices_include_vat`, `locale`, `issued_at`, `qr`,
`pdf_path`. A credit note has the same lines and totals, negated. Orders paid before this phase have no invoice; a
refund of one is logged (`invoice.credit_note_skipped`) and issues nothing.

### Numbering

`INV-2026-000123`: prefix (`INVOICE_PREFIX`, upper-case letters and digits), the year in `INVOICE_TIMEZONE`
(Riyadh by default) and a 6-digit sequence. Credit notes: `CN-2026-000007` (`INVOICE_CREDIT_NOTE_PREFIX`).
Each series has a row in `invoice_counters`, locked with `SELECT … FOR UPDATE` and bumped inside the issuing
transaction (`InvoiceNumbers::next`). A rolled-back payment gives its number back, so numbers are sequential and
**gap-free**; concurrent payments wait on the row lock. (A PostgreSQL sequence would leave holes on rollback.)
Numbers are never reused: documents are never deleted (`invoices.order_id` restricts deleting the order).

### VAT and rounding rules

All amounts are integers in minor units of the order currency (halalas for SAR); the rate is in basis points
(1500 = 15 %). `App\Services\Invoicing\Vat`:

- **VAT-inclusive prices** (`INVOICE_PRICES_INCLUDE_VAT=true`, the default): the price list already includes VAT,
  so checkout charges the listed total and VAT is **extracted** from the order total after the discount:
  `vat = round(total × rate ÷ (10000 + rate))`, `net = total − vat`.
  Example: 109.00 SAR → 10900 × 1500 ÷ 11500 = 1421.74 → VAT 14.22, net 94.78.
- **VAT-exclusive prices** (`false`): prices are net; checkout adds `vat = round(net × rate ÷ 10000)` on top,
  stores it in `orders.vat_cents` and charges `net + vat` (the checkout and order pages show the VAT line). The invoice
  uses that amount as charged.
- **Rounding**: half away from zero, in integer arithmetic only, once on the document total, which is authoritative.
  Each line (plans, add-ons, and the discount as a negative line) gets its VAT by the same formula; any difference
  (at most a minor unit or two) goes on the line with the largest amount, so the lines always add up to the totals.
- SYP has no minor unit (exponent 0), so its amounts are whole pounds.

### ZATCA

**Phase 1** (generation): every simplified tax invoice and tax credit note carries the QR code: TLV
(tag byte, UTF-8 length byte, value) of 1 seller name (Arabic name), 2 VAT number, 3 timestamp (UTC, ISO 8601),
4 total with VAT, 5 VAT, base64-encoded (`ZatcaQr`, tested against ZATCA's published sample). The PDF prints it.

**Phase 2 (integration / "Fatoora") is not implemented**: no UBL 2.1 XML, no cryptographic stamp or invoice hash
chain, no device onboarding (CSID) and no reporting of simplified invoices to ZATCA within 24 hours. It is needed once
the owner is VAT-registered and ZATCA notifies them of their integration wave; until then this system is suitable only
for phase 1. Plan it as a separate project (an e-invoicing provider's API is usually the quickest route).

### PDF

`App\Services\Invoicing\InvoicePdf` renders `resources/views/invoices/pdf.blade.php` (A4, bilingual: the customer's
language first, the other under each label) and prints it with headless Chromium's own CLI
(`App\Services\OgImage\Chromium::pdf`, `--print-to-pdf`, network blocked, IBM Plex Sans Arabic inlined by
`FontEmbedder`), so Arabic is shaped by a real browser. The QR is an inline SVG (`chillerlan/php-qrcode`). Rendered on
first download and cached on the media disk at `invoices/{id}/{random 40}.pdf` (private visibility, unguessable name).
Same Chromium binary and flags as the link preview images (`OG_CHROME_PATH`, `OG_CHROME_NO_SANDBOX`).

### Access

| Who | Where |
|---|---|
| The customer | `GET /invoices/{number}.pdf` (`invoices.download`, auth, 30 a minute). Links on the order page (`/dashboard/orders/{code}`, "Invoices and receipts") and in Account → Billing (a PDF button next to each order). |
| Admins | `/admin/invoices` (read-only list: filters by issue date, type, kind and currency; **Export CSV** of the filtered list), and the *Invoices* table on each order page, both with a PDF action. |

`InvoicePolicy`: a customer sees only their own documents, admins see all; nobody edits or deletes one. Anyone else
gets a 404. The CSV (UTF-8 with BOM): number, kind, type, issue time (Riyadh), order, credited invoice, customer name,
email and country, currency, gross, discount, net, VAT rate, VAT, total (major units, credit notes negative); customer
text that starts with `=`, `+`, `-` or `@` is prefixed with `'`. Exports are audit-logged (`invoice.exported`).

When an account is deleted its documents stay (tax records), with `user_id` cleared; the customer snapshot is kept as
issued. The account's data export lists its invoices.

## 3. Storage expiry

`events.storage_until` is the last day an event's photos and uploads are kept (plan section 11):

- **Set** when the event is published (`WizardController::publish`) and when an order for it is paid or approved
  (`OrderFulfillment`): the event's date in its own time zone + the plan's `limits.storage_days` (Free 0, Premium 30,
  Signature 90; `null` keeps media for good and sets nothing). The later date always wins: photo cloud add-ons and the
  admin's *Extend storage* push it further, and nothing shortens it.
- **`php artisan events:expire-storage [--dry-run] [--date=Y-m-d]`**, daily at 04:23 (`routes/console.php`),
  `App\Services\Storage\StorageExpiry`:
  1. **Warning emails** (`StorageExpiring`) to the host 14 and 3 days before (`config/retention.php` `warn_days`), only
     when the event has media. Each is sent once per storage date (`events.storage_warnings`); a late run sends only
     the most urgent one. Extending storage starts a new date, which warns again. The button opens the event's upgrade
     page with the photo cloud add-on picked (`?addon=photo_cloud_90d#addons`, `STORAGE_EXTEND_ADDON`).
  2. **Purge**, the day after `storage_until`: deletes the event's media files (host uploads, gallery, cover, guest
     photos, `events/{id}/…`) and link preview images (`og/{id}/…`) and the `media` rows, then sets `media_purged_at`
     and status `ended`. `media:<uuid>` references in `invitations.config` resolve to nothing (`MediaUrl` returns null
     for a missing row). Households, RSVPs, the guest book text and the invitation's text stay.
  3. **Archive**, `STORAGE_ARCHIVE_GRACE_DAYS` (default 30) after `storage_until`: status `archived`, so `/i/*` returns
     404 as for any archived event.
  - A photo cloud bought **forever** (an entitlement without an end) skips all three.
  - Each step is audit-logged (`event.storage_warning`, `event.media_purged`, `event.archived`). `--dry-run` prints
    what would happen and changes nothing.

Cached copies at the edge (Cloudflare) of `/i/*` and `og.png` expire on their own (5 minutes for pages); purging them
on archive belongs with the cache purge work.

## 4. What the owner must provide

Set these in the production `.env` (see also [DEPLOY.md, section 3](DEPLOY.md#3-environment)):

| Key | What |
|---|---|
| `INVOICE_SELLER_NAME_AR`, `INVOICE_SELLER_NAME_EN` | The legal name exactly as on the commercial registration (Arabic and English). |
| `INVOICE_VAT_NUMBER` | The 15-digit VAT registration number (starts and ends with 3). **Leave empty until registered**: documents are then receipts without VAT. |
| `INVOICE_CR_NUMBER` | Commercial Registration number (printed when set). |
| `INVOICE_SELLER_ADDRESS_AR`, `INVOICE_SELLER_ADDRESS_EN` | Registered address (national address). |
| `INVOICE_SELLER_EMAIL` | Contact email on documents (defaults to `MAIL_FROM_ADDRESS`). |
| `INVOICE_VAT_RATE` | Percent, default 15. |
| `INVOICE_PRICES_INCLUDE_VAT` | `true` (default): SAR prices include VAT. Decide with the accountant before registering. |
| `INVOICE_VAT_CURRENCIES`, `INVOICE_VAT_COUNTRIES` | Default `SAR` and any country. Ask the accountant whether USD orders from Saudi customers are in scope. |
| `INVOICE_PREFIX`, `INVOICE_CREDIT_NOTE_PREFIX`, `INVOICE_TIMEZONE` | Defaults `INV`, `CN`, `Asia/Riyadh`. Don't change the prefixes once documents exist. |

Before switching VAT on, confirm with a Saudi accountant: the registration date (documents issued before it must stay
receipts), whether prices include VAT, and the ZATCA phase 2 wave.
