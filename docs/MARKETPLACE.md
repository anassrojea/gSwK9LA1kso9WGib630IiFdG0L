# Template marketplace and vendor listings (phase 8C)

Plan sections 2 (Designer), 5, 6, 13.2 ("designers 70%, you 30%"; "vendor listings: featured spots") and 14.6.
Settings: `config/marketplace.php`. Strings: `lang/{ar,en}/marketplace.php`, `lang/{ar,en}/vendors.php`.

## Designers

| Step | Where | What |
|---|---|---|
| Apply | `/designer` (`designer/Apply`) | display names ar/en, bio, portfolio links, country, payout details (`encrypted:array`), accepts `resources/content/legal/{ar,en}/designers.md` (DRAFT, `terms_version`) |
| Approve / suspend / share | Filament → Marketplace → Designers | `App\Services\Marketplace\Designers`, audit-logged. Suspending takes the designer's templates off sale (`Template::onSale`) |
| Themes | `/designer/themes/*` | same checks as the admin theme form (`CatalogValidator::themeErrors`); ids `d-…`, `themes.designer_id` set; never listed in the wizard's theme tab |
| Templates | `/designer/templates/*` | opening + one of the designer's themes + `default_config` (structured fields or JSON, Zod in the browser, `InvitationConfigValidator` v1 on save), prices per currency within `price_bounds` (USD required), "included in Signature", cover on the media disk under `designers/{user_id}/covers/` |
| Preview | `/designer/revisions/{id}/preview` | the real guest view; only the designer and admins |

### Review states and revisions

```
template   draft → in_review → published ⇄ unpublished
                             → rejected (reason) → draft …
revision   draft → in_review → approved → superseded (by the next approved one)
                             → rejected (reason)
```

`templates` holds the live columns hosts get; `template_revisions` holds every version. Before the first approval the
template row mirrors its draft (inactive). After it, editing creates revision n+1 and the live template is untouched until an
admin approves it (Filament → Marketplace → Review queue: preview, approve, reject with a reason). Invitations keep their own
copy of the config, so nothing a designer or reviewer does changes an invitation already made.

## Buying a template

- The wizard's design step has a **Marketplace** tab (`MarketplaceCatalog::forWizard`): designer name, price in the host's
  currency, "Yours" / "In your plan". `/templates` and `/designers/{slug}` are the public (Blade, SEO) versions.
- A marketplace design is **in use** when the config's theme is a designer theme (`TemplateAccess::inUse`).
  `PlanAdvisor` adds the reason `template` (and no longer raises the plan for that template's own opening/theme);
  `PublishGate` covers it with the event entitlement `template:{id}`, or Signature when the designer opted in.
- Order item `template_purchase` (`/buy/template/{id}?event=`, `MarketplaceCheckout`), through
  `CheckoutService::placeLines` (same gateways, transfer approval, discount codes, VAT, invoices):

```json
{"type": "template_purchase", "id": "m-…", "name": "…", "quantity": 1, "unit_amount": 11500, "amount": 11500,
 "designer_id": "…", "prices": {"SAR": 11500, "SYP": 150000, "USD": 1200}}
```

- **Signature pool:** a Signature event publishing an "included in Signature" template it didn't buy writes one
  `marketplace_signature_uses` row (template, event, designer, `period` YYYY-MM). Nothing is paid from it yet: a later phase
  divides a monthly pool between designers by these rows.

## Revenue share (`RevenueShare`, `DesignerLedger`)

Integer minor units of the order's currency, never converted (SYP has no subunit):

1. line net = the line amount without VAT, using 6B's per-line split (`Vat::allocate`; inclusive prices: VAT extracted,
   exclusive: the amount is already net);
2. the discount line (also net of VAT) is shared among the lines the code covered, by amount, largest remainder first
   (`RevenueShare::allocate`); a template line is covered by codes that apply to every add-on;
3. designer share = round_half_up(net × share_bp / 10000), `share_bp` = `designer_profiles.commission_bp` or
   `marketplace.designer_share_bp` (7000); the platform keeps net − share.

Example: SAR 115.00 VAT-inclusive at 15 %, 20 % code → paid 92.00, VAT 12.00, net 80.00 → designer 56.00, platform 24.00.

`designer_ledger_entries` is append-only (model guard + PostgreSQL trigger). Each posting (`txn_id`) balances:

| Kind | Debit | Credit |
|---|---|---|
| sale | sales N | designer_payable S, platform_share N−S |
| refund (6B credit note) | designer_payable S, platform_share N−S | sales N |
| payout | designer_payable P | payouts_cash P |

## Payouts

`marketplace:payout-statements` (1st of the month, 05:41 UTC; `--month=YYYY-MM`) issues, per designer and currency,
`earned up to the month's end − all earlier statements`, only when it reaches `marketplace.payout_minimum.{CUR}`; smaller or
negative amounts carry over. Admins pay by bank transfer and **Mark paid** with the reference (Filament → Payouts), which posts
the payout and is audit-logged. Designers see statements and the balance in `/designer`.

## Vendors

- `/vendor-portal`: one listing per user (category, cities, occasions, ar/en text, photos under `vendors/{id}/`, price range,
  WhatsApp, Instagram; calligraphers: name-logo prices within `calligraphy_bounds`). Admin approves / rejects / hides /
  features by hand (Filament → Vendors).
- Public: `/vendors` (filters city, category, occasion; canonical URLs keep the filters), `/vendors/{slug}` (JSON-LD
  `LocalBusiness`), sitemap entries through the `sitemap.entries` tag read by `SeoController`.
- Featured spots: order item `vendor_feature` `{id: listing, city, category, days: 30}` (`/buy/feature`); featured listings
  sort first with a badge until `ends_at`; `vendors:end-features` (hourly) closes them; a refund ends them at once.
- Contact: `/go/vendor/{slug}/{whatsapp|instagram}` counts a daily anonymous tap (`vendor_contact_clicks`) and redirects.
- Host dashboard card "Vendors near your venue": city guessed from the event's address / venue name, else its time zone's country.
- Calligraphy logos: order item `calligraphy_request` `{id: listing, request_id}`; paid → `new`; the calligrapher moves it to
  `in_progress` / `delivered`; refund → `cancelled`. The calligrapher is paid outside Daawa for now.

## Payment-core hooks (for merging with 8A/8D)

All in `App\Services\Marketplace\MarketplaceOrders` (ignores orders without its types), one line each:

- `OrderFulfillment::complete()` right after `grant()`: `app(MarketplaceOrders::class)->fulfill($order, $paidAt);`
- `OrderFulfillment::refund()` after the credit note: `app(MarketplaceOrders::class)->refund($order);`
- `OrderPresenter::itemName()` / `TransferGateway::itemsLabel()`: `MarketplaceOrders::name($item, $locale)`
- `Discounts::covers()` default branch: `MarketplaceOrders::discountable($code, $type, $id)`
- `CheckoutService::place()` delegates to the new `placeLines()` (lines priced by a callback, event optional).
