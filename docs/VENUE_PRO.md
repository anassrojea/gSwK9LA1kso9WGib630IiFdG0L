# Venue & Planner Pro (phase 8A)

Plan section 13.1: halls, hotels, planners and companies pay a monthly (or yearly) subscription for their own hall
layouts, a monthly allowance of Signature events, white label, team accounts and a directory listing. This page is
the reference for how it is built; setup steps for production are in [DEPLOY.md, section 10](DEPLOY.md#10-payments).

## 1. Tiers and prices

`Database\Seeders\OrgPlanSeeder` (run by `DatabaseSeeder` after `PlanSeeder`) writes one `plans` row per tier and
interval. Limits are data in `plans.limits` and can be edited in `/admin` → Plans.

| Tier | Monthly (USD / SAR / SYP) | Yearly | Signature events / month | Team seats | White label | Directory | Hall layouts |
|---|---|---|---|---|---|---|---|
| `venue_starter` | $49 / 189 ر.س / 600,000 ل.س | 10 × monthly | 15 | 3 | no | yes | 2 |
| `venue_pro` | $99 / 369 ر.س / 1,200,000 ل.س | 10 × monthly | 30 | 10 | yes | yes | 10 |
| `venue_business` | $149 / 559 ر.س / 1,800,000 ل.س | 10 × monthly | 75 | 25 | yes | yes | unlimited |

Yearly rows are `{tier}_yearly` (`interval = year`): two months free. SAR and SYP prices are **placeholders**
(`prices.placeholder`, flagged in `/admin` → Prices); set the real ones before launch. An organization without a
subscription has `ORG_FREE_SEATS` seats (default 2: the owner and one more) and one hall layout.

## 2. The subscription model

`User` stays the only Cashier billable: **the org owner pays**. Each organization has at most one live row in
`org_subscriptions` (a partial unique index):

| Column | Meaning |
|---|---|
| `org_id`, `user_id` | the organization, and the payer (the owner when it started) |
| `plan_id`, `interval` | the `plans` row (`venue_pro`, `venue_pro_yearly`, …) and `month` / `year` |
| `provider`, `provider_id`, `provider_customer_id`, `provider_price` | `stripe` (sub_…), `lemon_squeezy` (subscription id), `transfer` (none) |
| `currency`, `amount_cents` | what each period costs |
| `status` | `trialing`, `active`, `past_due`, `canceled`, `expired` |
| `current_period_start`, `current_period_end`, `cancel_at_period_end` | the period, and a cancellation that waits for its end |
| `next_plan_id` | transfer only: a tier change that starts with the next period |
| `past_due_since`, `canceled_at`, `ended_at`, `reminders` | the grace period, the end, and the emails already sent |

While a subscription is live (`trialing`, `active`, `past_due`), `organizations.plan_id` is its plan
(`OrgSubscriptions::applyOrgPlan`), so every event with that `org_id` gets the plan's features through
`App\Services\Entitlements` (already the rule: the event's plan, else its organization's). Without one it is `null`.

### Stripe

- **Checkout** in subscription mode through Cashier on the owner: `$owner->newSubscription('org:{org_id}', $price)`
  with `metadata = {org_id, plan_id, user_id}`. `$price` is `STRIPE_PRICE_{PLAN}_{CURRENCY}` when configured, else an
  inline `price_data` with `recurring.interval`. SAR and USD only (SYP is charged in USD).
- **Webhooks** (`App\Http\Controllers\Webhooks\StripeWebhookController`, signed with `STRIPE_WEBHOOK_SECRET`):
  `customer.subscription.created/updated/deleted` → Cashier's own `subscriptions` row (type `org:{org_id}`), then
  mirrored into `org_subscriptions` (`OrgSubscriptions::syncStripe`); `invoice.paid` → one `Order` per period (method
  card, item type `subscription`) marked paid through `OrderFulfillment::markPaid`, so it gets a `payments` row and an
  invoice like every other order (idempotent on the invoice id); `invoice.payment_failed` → `past_due`. An invoice that
  arrives before its subscription answers 409 so Stripe retries.
- **Changes**: tier swaps go through Cashier's `swap()` (Stripe prorates; needs the configured prices); cancel at period
  end and resume through Cashier; the **Billing Portal** (`/org/{org}/billing/portal`) changes the card, the plan or
  cancels. Configure the portal's products with the same prices.

### Lemon Squeezy

- **Checkout**: the package's `Checkout` builder for the plan's variant (`LEMON_SQUEEZY_VARIANT_VENUE_*`), with
  `custom = {org_id, plan_id, user_id, billable_id, billable_type, subscription_type}`. Charged in the store currency.
- **Webhooks** (the existing `/webhooks/lemon-squeezy`, same signing secret): `subscription_created/updated/
  cancelled/resumed/expired/paused/unpaused` → `org_subscriptions` and the package's UUID-safe
  `lemon_squeezy_subscriptions` row (phase 4); `subscription_payment_success` → an order + payment + invoice per
  period (idempotent); `subscription_payment_failed` → `past_due`. The first `order_created` of a subscription
  checkout is ignored (its money is recorded from `subscription_payment_success`).
- **Changes**: the package's `swap()` (Lemon Squeezy prorates; needs `LEMON_SQUEEZY_VENUE_PRODUCT_ID`), `cancel()`,
  `resume()`; the customer portal URL from the API.

### Bank transfer

1. The owner picks a tier and "Bank transfer": an `Order` (`DW-…`, 48 h for the receipt, item type `subscription`).
2. The admin approves it like any transfer (`/admin` → Orders, or the organization's page): `OrderFulfillment`
   calls `OrgSubscriptions::fulfill`, which starts the subscription (period = one interval from approval) and the
   invoice is issued on the same path.
3. `org:renewal-orders` (hourly) creates the next period's order **7 days before** `current_period_end`, once per
   period, and emails the owner; approving it extends the period **from the old end** (also when paid during the
   grace period). A tier change chosen meanwhile (`next_plan_id`) is the renewal order's plan.
4. `org:renewal-reminders` (daily) emails 7 days and 1 day before the end, once each.
5. `org:expire-subscriptions` (hourly): an unpaid period that ended → `past_due` (email); cancelled at period end →
   `canceled`; anything `past_due` for `ORG_GRACE_DAYS` (7) → `expired` (email). This last step applies to every
   provider, so a card subscription stuck in dunning also ends.

All three commands are idempotent (the `reminders` column and the order lookup by `subscription_id` + `period_end`).

### Expiry and downgrade

When a subscription ends, `organizations.plan_id` becomes `null`: no white label, no more Signature events from the
plan, the org's drafts need their own payment again. **Published events keep what they had**: publishing an org event
under a live plan stamps it with `plan_id = signature` (below), and the badge, seating, QR check-in and so on stay.

## 3. Signature events per month

`App\Services\Org\OrgQuota`. Publishing an org event (`WizardController::publish`) while the subscription is live uses one
slot: `events.org_quota_at` is set and the event gets `plan_id = signature` for good. The month is counted from the
subscription's period start (yearly plans have twelve monthly windows). At the limit, both creating a new client event
(`POST /org/{org}/events`) and publishing are refused with "You have used all N signature events of this month. More
open on DATE — or upgrade your plan." An event that already bought Signature itself uses no slot; re-publishing uses
no new slot.

## 4. Team, roles and invitations

`App\Services\Org\OrgTeam`, `org_invitations` (only a SHA-256 of the token is stored).

- Roles: **owner** (one, manages the team and billing), **staff** (client events, hall layouts), **door** (scans).
- Invitations by email with a role, a signed link `/org-invitations/{id}/{token}`, valid 7 days. Accepted by the
  signed-in user **with the invited email**, on the link, or right after logging in / registering (the token waits in
  the session; `OrgServiceProvider` listens to `Login`).
- Seats: members (owner included) + pending team invitations ≤ the plan's `team_seats`. Members beyond a smaller
  plan's seats stay; only new invitations are refused.
- Exactly one owner: the owner's role changes only through *Make owner* (the old owner stays as staff); the owner
  can't leave or be removed. Everything is audit-logged.

## 5. Client events — the collaborator choice

Org staff create events owned by the organization (`events.org_id`). To let the couple edit their invitation, staff
invite them by email (an `org_invitations` row with role `client` and `event_id`). **On accepting, the client becomes
the event's `owner_id`; `org_id` stays.** We chose this over a collaborators table because every existing host
feature (the dashboard list, the wizard, checkout, reminders, the event dashboard) already keys on `owner_id`:

- the client sees only their own event (`/dashboard` lists owned events plus the orgs one belongs to, and a client is
  not an org member);
- the organization keeps full access through `org_id` (`EventPolicy`: owner and staff edit, door staff view and scan);
- a client invitation takes no team seat and gives no org role.

Until the client accepts, the staff member who created the event is its owner.

## 6. White label

For plans with `white_label` (`App\Services\Org\WhiteLabel`):

- the invitation footer shows the org's logo and name (`resources/views/invite/partials/org-brand.blade.php`) instead
  of the "Made with Daawa" badge and the "Design yours" link;
- the org's brand color tops its `/org` pages;
- emails to its clients go out under its sender name (`white_label.sender_name`, else the org's name).

Branding is read from the event's organization at render time, so guest pages stay edge-cacheable: an
`Organization` update to its name, logo, white label or plan queues `PurgeEdgeCache` for each of its published
events (`App\Observers\EdgeCachePurger`).

**Out of scope:** a custom subdomain per organization (e.g. `invites.alnoor.com`). It needs a DNS / certificate flow
(Cloudflare for SaaS custom hostnames) and host-based routing of `/i/*`; left for a later phase.

## 7. Pages and routes

| Route | What |
|---|---|
| `GET /org`, `GET /org/create`, `POST /org` | my organizations; create (name, type, city, contact, logo) |
| `GET /org/{org}` | venue dashboard: month calendar (list on phones, `?month=YYYY-MM`), signature quota, new client event, team, hall layouts |
| `GET /org/{org}/events/{event}` | per event: guests and RSVPs, meal counts per section, arrivals live (Reverb), seating PDF, door app, client invitation |
| `POST /org/{org}/events`, `POST /org/{org}/events/{event}/client` | new client event (opens the wizard); invite the client |
| `GET /org/{org}/team`, `POST …/invitations`, `DELETE …/invitations/{id}`, `PATCH`/`DELETE …/members/{user}`, `POST …/transfer`, `POST …/leave` | team |
| `GET /org/{org}/billing`, `POST …/billing/{subscribe,change,cancel,resume,renew}`, `GET …/billing/portal` | plans and subscription (owner) |
| `GET /org/{org}/settings`, `POST /org/{org}/settings` | profile, logo, white label (owner) |
| `GET`/`POST /org-invitations/{id}/{token}` | invitation link (signed) |

Admin: `/admin/organizations` (members, subscriptions, orders with transfer approval, events, quota; *Extend period*
and *Cancel now*, audit-logged) and the *Subscriptions* widget on Today (MRR per currency, active, past due, churn).

## 8. Environment

```dotenv
ORG_FREE_SEATS=2                 # seats without a subscription (owner included)
ORG_GRACE_DAYS=7                 # past_due → expired
ORG_RENEW_DAYS_BEFORE=7          # transfer renewal order
STRIPE_PRICE_VENUE_PRO_USD=price_…            # one per plan row and currency (SAR, USD), optional
STRIPE_PRICE_VENUE_PRO_YEARLY_SAR=price_…
STRIPE_PORTAL_CONFIGURATION=bpc_…             # optional
LEMON_SQUEEZY_VENUE_PRODUCT_ID=…              # the subscription product
LEMON_SQUEEZY_VARIANT_VENUE_STARTER=…         # one variant per plan row
LEMON_SQUEEZY_VARIANT_VENUE_PRO_YEARLY=…
```

Stripe webhook events to add: `customer.subscription.created`, `customer.subscription.updated`,
`customer.subscription.deleted`, `invoice.paid`, `invoice.payment_failed`. Lemon Squeezy: `subscription_created`,
`subscription_updated`, `subscription_cancelled`, `subscription_resumed`, `subscription_expired`,
`subscription_paused`, `subscription_unpaused`, `subscription_payment_success`, `subscription_payment_failed`.

## 9. Demo data

`VenueProDemoSeeder` (local and staging): قاعة النور on Venue Pro by bank transfer, white label, `staff@daawa.test`,
`door@daawa.test`, a client `client@daawa.test` who owns "زفاف نور وسليم", events across this month, and the
white-labelled invitation `/i/VenueDemo01`. Passwords: `password`.

## 10. Not done here

- Custom subdomains (section 6).
- Refunding a subscription order issues the credit note but does not shorten the period; use *Cancel now*.
- Discount codes (phase 5B) apply to event checkouts only, not to subscriptions.
- Orders stay the payer's: after an ownership transfer, the new owner sees new renewal orders, not the old ones.
- The directory listing flag (`directory_listing`) is read by the venue directory (phase 8B).
