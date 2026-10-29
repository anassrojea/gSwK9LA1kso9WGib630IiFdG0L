# Daawa (دعوة): Interactive Digital Invitations

Implementation plan. Everything decided so far, in one place, in the order you will need it.
"Daawa" is a working name. Prices are in USD as a starting point; localize them before launch.

Prepared 27 October 2026.

---

## Contents

1. [Product summary](#1-product-summary)
2. [Who uses it](#2-who-uses-it)
3. [The easiest creation flow](#3-the-easiest-creation-flow)
4. [Dashboards](#4-dashboards)
5. [Features by phase](#5-features-by-phase)
6. [The invitation engine](#6-the-invitation-engine)
7. [Hall and seating](#7-hall-and-seating)
8. [Guests, personal links and RSVP](#8-guests-personal-links-and-rsvp)
9. [QR codes](#9-qr-codes)
10. [Wallet passes](#10-wallet-passes)
11. [Photos and the event album](#11-photos-and-the-event-album)
12. [Payments](#12-payments)
13. [Pricing and monetization](#13-pricing-and-monetization)
14. [New ideas (1–9)](#14-new-ideas-19)
15. [Tech stack](#15-tech-stack)
16. [Database](#16-database)
17. [Project structure](#17-project-structure)
18. [Routes and actions](#18-routes-and-actions)
19. [Integrations](#19-integrations)
20. [Security, privacy and legal](#20-security-privacy-and-legal)
21. [Performance rules](#21-performance-rules)
22. [Analytics and KPIs](#22-analytics-and-kpis)
23. [Roadmap](#23-roadmap)
24. [Running costs](#24-running-costs)
25. [Launch and marketing](#25-launch-and-marketing)
26. [Risks](#26-risks)
27. [Sample invitations](#27-sample-invitations)
28. [Before you write code](#28-before-you-write-code)

---

## 1. Product summary

**What it is:** a website where anyone can create an animated invitation for a wedding, engagement, henna night, birthday, graduation or any celebration. They send it to guests over WhatsApp, collect RSVPs, seat guests in a virtual hall, check them in with QR codes, and collect the party photos afterward.

**Verdict:** worth building, if the product is about the whole event and not just a pretty card.

| | Score | Why |
|---|---|---|
| Demand | 8/10 | Every wedding needs invites; digital invites over WhatsApp are already normal. |
| Differentiation | 7/10 | Seating, seat limits per family, photo wall and Arabic/RTL together are rare. |
| Willingness to pay | 7/10 | Weddings are emotional and expensive; $30–80 is small next to the hall bill. |
| Repeat usage | 4/10 | A couple marries once. Fixed by venues, planners and the "family journey" (section 14). |
| Build difficulty | 6/10 | The editor and seating drag-and-drop are the hardest parts. |

**What makes it different**

- Seating plan on a generated hall, with men/women split halls.
- Personal link per family with a seat limit ("خصّصنا لكم مقعدين"), which stops uninvited extras.
- The invitation follows the event: invite → RSVP → table and QR → live day page → album → thank-you.
- Arabic-first, RTL, Hijri dates, regional styles.
- Venues and planners as a sales channel, not only couples.

**Three strategic rules**

1. Sell to venues and planners as well as to couples. A hall that loads its layout once brings every couple that books it.
2. Personal guest links with seat limits from day one.
3. WhatsApp first: the link preview and a fast first screen on a mid-range phone matter more than any effect.

---

## 2. Who uses it

| Role | What they do | Where |
|---|---|---|
| **Host** (couple, parent, birthday family) | Creates the invite, imports guests, pays, sends links, tracks replies, seats people, downloads photos. | Creation wizard + host dashboard |
| **Guest** | Opens the link, sees the invite, replies, gets a table and QR, adds to wallet, uploads photos. | Public invitation page (no login) |
| **Venue / planner** | Saves its hall layouts, creates events for clients, sees meal counts, runs check-in at the door. | Venue dashboard + door app |
| **Door staff** | Scans QR codes on the night. | Door app (a page on the phone, no install) |
| **Admin (you)** | Approves bank transfers, manages templates, customers, refunds, revenue. | Admin dashboard |
| **Designer** (later) | Uploads templates to the marketplace and earns a share. | Designer portal (V3) |

---

## 3. The easiest creation flow

Goal: someone who has never used the site can create, pay and send in under 10 minutes on their phone, without help.

**Rules**

- **No account until it matters.** The host starts creating straight away. The draft is kept in the browser and on the server with an anonymous session. They sign up only at "Pay & send".
- **Live phone preview at every step.** On desktop the preview sits beside the form; on mobile it is one tap away ("Preview").
- **Everything has a good default.** A template already contains sample names, a date, a story and a program. The host replaces text; they never face an empty page.
- **Autosave everything.** No "Save" button.
- **One question per screen on mobile.** Big buttons, Arabic first, English toggle.
- **Price is shown before the last step**, never as a surprise.

**The wizard (5 steps)**

| Step | Screen | Notes |
|---|---|---|
| 1. Occasion | Big tiles: wedding, engagement, henna, birthday, graduation, baby welcome, iftar, other | Sets defaults: sections, wording, icons. |
| 2. Design | A gallery of templates, filtered by occasion. Each card plays its opening when tapped. Pick an **opening** (envelope, rose, doors…) and a **theme** (colors + fonts) separately. | Free / Premium / Signature badges on each. |
| 3. Details | Names, families, date and time (Hijri shown automatically), place (search on map), story (or "Write it for me" with AI), program, dress code, photos, cover video, music on/off. | Each section can be hidden with a toggle. |
| 4. Guests | Three ways: paste a list ("أم فيصل - 3"), import contacts / CSV, or one open link for everyone. Set seats per family. | AI cleans a messy pasted list (section 14.3). |
| 5. Pay & send | Choose plan, add-ons, pay (card, Apple Pay, bank transfer). Then: "Send on WhatsApp" per guest, or copy all links. | Sign up here: Google, email code or phone code. |

After sending, the host lands on the **host dashboard**.

---

## 4. Dashboards

All dashboards follow the same pattern: **numbers on top, one list below, actions on each row.** No settings screens unless needed.

### 4.1 Host dashboard (one screen)

- **Top cards:** Invited families · Opened · Replied · Attending (people) · Seats left · Days left.
- **Funnel bar:** opened → replied → attending, so the host sees who to chase.
- **Guest table:** name, seats allowed, status chip (not opened / opened / attending / declined), table number, last opened. Filters by status. Row actions: send WhatsApp, copy link, edit seats, mark arrived.
- **Big buttons:** "Remind those who didn't reply", "Seat guests", "Open album", "Edit invitation".
- **Live day mode** (on the event day): arrivals counter, live photo wall link, door app link.

### 4.2 Venue dashboard

- Calendar of events in the venue.
- Hall layouts (create from the generator, edit, reuse).
- Per event: meal count per section (men / women / kids), arrivals live, export seating chart PDF.
- Team members (door staff accounts).

### 4.3 Admin dashboard (you)

- **Today:** revenue, new events, transfers waiting for approval (with count badge).
- **Transfer approvals:** order code, customer, plan, amount, receipt time, Approve / Reject (see section 12).
- **Customers and events:** search, open as the customer (read-only), refund, extend storage.
- **Templates:** upload and publish templates, openings and themes; mark Free / Premium / Signature; preview.
- **Plans and add-ons:** prices per currency, discount codes.
- **Audit log:** who approved what and when.

Build the host and venue dashboards with Inertia + React + shadcn/ui tables (TanStack Table), inside the same Laravel app. Build the **admin dashboard with Filament**: it generates tables, filters, forms and actions (Approve / Reject) from your models in hours instead of weeks, and only you and your staff use it.

---

## 5. Features by phase

| Feature | What it does | Phase | Tier |
|---|---|---|---|
| Card templates | Wedding, engagement, henna, birthday, graduation, baby welcome, Eid, iftar. Defaults, all editable. | MVP | Free / Premium |
| Card editor | Text, colors, fonts, photos, cover video, effects on/off, Arabic & English. | MVP | Basic free, full paid |
| Opening effects | Envelope, ring box, arched doors, gift box, diploma, rose. Chosen separately from the theme. | MVP | Premium |
| "Our story" section | Timeline, notes, or a letter; AI can write it. | MVP | Premium |
| RSVP | Name, attending yes/no, number of people, message. | MVP | Free |
| Personal guest links | One link per family, with name and seat limit. | MVP | Premium |
| Event details | Countdown, map, add to calendar, dress code, program. | MVP | Free |
| Bank transfer via WhatsApp | Manual approval flow. | MVP | All paid plans |
| Personal WhatsApp preview | Link preview image with the guest's name. | MVP | Premium |
| Open tracking | Who opened, who replied; remind only the silent ones. | MVP | Premium |
| Hall & seating planner | Generated SVG hall, drag guests onto chairs, split halls. | V2 | Add-on / Signature |
| Table lookup | "You are at table 7" on the invite and at the door. | V2 | Signature |
| QR check-in + door app | Scan guests, live arrivals, duplicate warnings. | V2 | Add-on |
| Wallet pass | Apple / Google Wallet pass with design, table, QR. | V2 | Signature / add-on |
| Reminders | Automatic WhatsApp / email reminders to non-responders. | V2 | Premium |
| Photo wall / album | Guests upload; host downloads ZIP. | V2 | Add-on |
| Live day page | Directions, table, live stream, live photo wall. | V2 | Signature |
| Video guestbook | Short congratulation videos. | V3 | Add-on |
| Thank-you card | After the event, with best photos. | V3 | Premium |
| Highlight video, photo book | Auto video from uploads; printed book. | V3 | Add-on |
| Hall designer | Venues draw their own layout. | V3 | Venue Pro |
| Template marketplace | Designers sell templates. | V3 | Revenue share |
| Vendor listings | Photographers, kosha designers, bands pay for featured spots. | V3 | Ads |

---

## 6. The invitation engine

### 6.1 Core idea: an invitation is data

Every invitation is **one JSON document** (stored in `invitations.config`). One renderer draws every invitation. New templates are new JSON + assets, not new code. This is what lets non-developers (you, designers) add templates.

An invitation = **opening** + **theme** + **sections** + **content**.

- **Opening:** the scroll-driven intro (envelope, rose…). A small JS module with one function: `render(progress 0→1)`.
- **Theme:** colors, fonts, ornaments, background textures.
- **Sections:** ordered list (names, date, story, program, place, gallery, RSVP, album…). Each can be hidden.
- **Content:** the host's text, photos, dates.

Because openings and themes are separate, **6 openings × 20 themes = 120 looks** from a small amount of design work (idea 14.1).

### 6.2 Config example

```json
{
  "version": 1,
  "occasion": "wedding",
  "locale": "ar",
  "opening": { "id": "rose", "options": { "petalColor": "#F4A6BB" } },
  "theme": {
    "id": "blush-garden",
    "colors": { "bg": "#FFF6F8", "ink": "#3B2330", "accent": "#A8325A", "soft": "#8E6B78", "gold": "#C9A35C" },
    "fonts": { "display": "Katibeh", "body": "Amiri", "label": "Almarai" }
  },
  "effects": { "fallingPetals": true, "sparkles": true, "music": false },
  "sections": [
    { "type": "intro_line", "text": "كما تتفتّح الوردةُ على مهل… تفتّحت حكايتنا" },
    { "type": "hosts", "lines": ["يتشرف السيد ماجد الزين وحرمه", "والسيد نبيل القاسم وحرمه"] },
    { "type": "names", "first": "ريم", "second": "عمر" },
    { "type": "date", "start": "2026-12-17T20:30:00+03:00", "showHijri": true, "countdown": true },
    { "type": "story", "style": "stem", "chapters": [
      { "year": "2021", "title": "آخر وردة في المحل", "text": "…" }
    ]},
    { "type": "program", "items": [{ "time": "20:30", "label": "استقبال الضيوف" }] },
    { "type": "place", "name": "قاعة الورود الملكية", "address": "…", "lat": 31.95, "lng": 35.91 },
    { "type": "dress_code", "colors": ["#F9D3DD", "#E8839F", "#86B184"] },
    { "type": "send_rose" },
    { "type": "rsvp", "askMessage": true, "askAllergies": false },
    { "type": "album_teaser" }
  ]
}
```

Validate the config twice: with a Zod schema in the React editor (instant feedback), and with a JSON Schema on the server when saving (e.g. `opis/json-schema` in Laravel). Keep a `version` so old invitations keep rendering after the schema changes.

### 6.3 Opening effects library

| Opening | Occasions | How it works |
|---|---|---|
| Envelope | Wedding | Seal fades, flap rotates (rotateX), letter rises, envelope drops. |
| Ring box | Engagement | Lid rotates open, ring rises with glow, box drops, names appear. |
| Arched doors | Henna, Eid, iftar | Doors rotateY inward, henna pattern draws (stroke-dashoffset), zoom through. |
| Gift box | Birthday | Bow flies, lid pops, balloons rise, confetti with scroll-driven physics. |
| Diploma | Graduation | Ribbon slips off, paper height grows, caps thrown. |
| Rose | Wedding, engagement | Petals rotate from bud to bloom, outer layers first, petals fall on names. |

All openings share the same contract:

- A sticky stage inside a tall section (about 330vh).
- `progress = -section.top / (section.height - viewport.height)`, clamped to 0..1, guarded against division by zero.
- Each phase is a sub-range of progress with easing; everything is a pure function of progress, so scrolling back reverses it.
- Respects `prefers-reduced-motion` (show the final frame).
- Uses only transforms and opacity (GPU friendly). SVG for shapes. Three.js only for Signature 3D openings, lazy-loaded.

### 6.4 Rendering

- Public invitation page: a **Blade** view rendered on the server from the config (fast first paint, correct WhatsApp preview, no framework runtime), plus one small vanilla JS module for the chosen opening. This is exactly how the 7 samples are built.
- Cached by Cloudflare at the edge; the cache for an invitation is purged through the Cloudflare API when the host edits it. Per-guest parts (name, seats, RSVP state) are filled by a tiny JSON request so the page itself stays cacheable.
- Animations: plain CSS/JS for most openings (as in the samples), GSAP + ScrollTrigger only where it saves real work. Lottie for illustrated motion if designers deliver it.
- The editor preview in the wizard renders the same Blade view inside an iframe, so what the host sees is exactly what guests get.
- Fonts: Google Fonts subset per theme, `display=swap`, Arabic + Latin.
- Numbers: Arabic-Indic digits in Arabic invites, Western digits in English ones.
- Hijri date: compute with `Intl.DateTimeFormat('ar-SA-u-ca-islamic-umalqura')` and let the host correct it (sightings can differ by a day).

---

## 7. Hall and seating

### 7.1 Hall layout schema

```json
{
  "id": "hall_al_noor_main",
  "name": "Al Noor Ballroom",
  "size": { "w": 800, "h": 580 },
  "stage": { "x": 270, "y": 24, "w": 260, "h": 62, "label": "Kosha" },
  "danceFloor": { "x": 310, "y": 100, "w": 180, "h": 70 },
  "entrances": [{ "x": 360, "y": 566, "w": 80 }],
  "sections": [
    { "id": "men", "label": "Men", "area": { "x": 24, "y": 190, "w": 360, "h": 350 } },
    { "id": "women", "label": "Women", "area": { "x": 416, "y": 190, "w": 360, "h": 350 } }
  ],
  "tables": [
    { "id": "T1", "section": "men", "shape": "round", "x": 84, "y": 248, "seats": 8 }
  ]
}
```

### 7.2 Generator

Inputs: number of tables, chairs per table, layout (mixed or split), table shape. Algorithm (already prototyped on the plan page):

1. Split the area by section.
2. `cols = round(sqrt(n × w / h))`, `rows = ceil(n / cols)`; center the last row.
3. Chair radius = 7.5% of the cell, ring radius = half the cell minus the chair radius; table radius inside the ring.
4. Output the `tables` array, so the venue can then nudge tables by hand.

Later table shapes: long banquet tables, sofas, VIP row, kids table.

### 7.3 Seating planner

- Left: list of confirmed families (with seat counts). Right: the SVG hall.
- Drag a family onto a table; seats fill in order. Click a chair to move one person.
- Warnings: table over capacity, a family split across tables, a men-section table holding a women-section guest.
- **Auto-seat** (idea 14.3): keep families together, fill tables evenly, put kids together, respect sections. The host only adjusts.
- Export: printable seating chart PDF for the hall, and per-table name cards.
- Guests see "طاولة ٧" in the RSVP confirmation and in the wallet pass.

---

## 8. Guests, personal links and RSVP

- Each family/household gets a **random, unguessable token** (10+ chars, base62): `daawa.link/x7Ka9QpL2m`.
- The page greets them by name, shows the seat limit, and limits the RSVP count to it.
- An optional **open link** (one link for everyone) for casual events, with a max total.
- **Open tracking:** log the first and last open per token (no tracking pixels, no personal data beyond the token). Dashboard shows "opened but didn't reply".
- **Reminders:** one tap sends a reminder only to those who opened but didn't reply, or never opened.
- RSVP can be changed until a cutoff date set by the host.
- Optional fields per event: message to the couple, allergies, henna artist booking, meal choice, kids count.

---

## 9. QR codes

Every QR is the same household token, so one code works everywhere (door, wallet, table lookup).

1. **Entry check-in:** door staff scan; host sees live arrivals; duplicate scans are flagged.
2. **Find your table:** scan at the entrance → hall map with the guest's table highlighted.
3. **Photo upload QR on each table:** scan → upload straight to the album, no app.
4. **Printed QR on paper invites:** for older relatives; opens the digital invite and RSVP.
5. **Designed QR:** couple's colors, initials or logo in the middle (keep error correction level H).
6. **Gift QR:** gift list or bank details for money gifts (check regulations).
7. **Video guestbook QR:** record a short congratulation video.
8. **Thank-you QR:** on favors or thank-you cards; opens the highlight gallery.
9. **Venue QR:** at the hall reception, "Book your invitation with us".
10. **Live screen QR:** on the hall screen; photos guests upload appear live.

Bundle 1 + 2 + 10 as **"Smart Hall"** for venues.

Libraries: `endroid/qr-code` (generate on the server in PHP), `qr-code-styling` (designed QR in the editor), native `BarcodeDetector` with `html5-qrcode` fallback (scan in the door app).

---

## 10. Wallet passes

- **Apple Wallet:** signed `.pkpass` (event ticket style), generated on the server in PHP (e.g. the `pkpass/pkpass` package). Needs an Apple Developer account ($99/year) and a Pass Type ID certificate.
- **Google Wallet:** an Event Ticket class per event and an object per guest via the Google Wallet REST API (`google/apiclient`), delivered with a "Save to Google Wallet" link (signed JWT, `firebase/php-jwt`). Issuer account is free.
- **Design** comes from the invitation theme: background, text and label colors, names, a strip/hero image rendered from the theme.
- **Fields:** date, hall, time, table, seats, guest name, QR (household token).
- **Location + date relevance** so the pass can appear on the lock screen near the hall on the day.
- **Updates:** if time or table changes, push an update (Apple web service + APNs; Google API PATCH).
- **Fallback** for phones without a wallet: the same pass as a web page with "Add to home screen".
- Pricing: included in Signature, $5 add-on on Premium.

---

## 11. Photos and the event album

- Guests upload from the invite link or a table QR, no account. Optional PIN set by the host.
- Compress on the device before upload (max 2560px, WebP/JPEG); videos capped by plan (e.g. 60s).
- Direct upload to object storage with signed URLs; thumbnails generated after upload.
- Host can hide/delete items, download all as ZIP.
- **Live photo wall** on event night (realtime feed), for the hall screen.
- Storage period by plan; afterwards a paid extension, then deletion with a warning email.
- Later: auto highlight video, printed photo book, anniversary "re-live" page.

---

## 12. Payments

### 12.1 Methods

| Method | Unlocks | Notes |
|---|---|---|
| Card & Apple Pay | Instantly | Through a gateway: Stripe where available, otherwise a local one (Tap, HyperPay, PayTabs, Moyasar, Paymob depending on country). |
| Bank transfer via WhatsApp | After admin approval | The main alternative for customers who don't pay online. |
| Local instant transfers | Later | CliQ, STC Pay, InstaPay etc. depending on country. Start manual, automate when volume grows. |

### 12.2 Bank transfer flow

1. Customer picks a plan and "Bank transfer" at checkout.
2. Order is created with status `awaiting_payment` and a unique code (e.g. `DW-4821`), plus bank details and the amount.
3. Customer transfers and writes the code in the transfer note.
4. One tap opens WhatsApp with a ready message containing the code; they attach the receipt.
5. Admin checks the bank account, finds the order by code, clicks **Approve**.
6. Plan activates; customer gets a WhatsApp and email confirmation.

**Order states:** `awaiting_payment` → `receipt_received` → `approved` | `rejected` | `expired` (48h).

**Rules**

- Approve only after seeing the money in the bank, never from a screenshot alone.
- One code per order; reject duplicate or reused receipts.
- Customers can keep editing and previewing while waiting; sending invites unlocks after approval.
- Every approval is logged with who and when.

Pre-filled WhatsApp link: `https://wa.me/<your number>?text=<url-encoded message with order code>`.

---

## 13. Pricing and monetization

### 13.1 Plans

| Plan | Price | For | Includes |
|---|---|---|---|
| Free | $0 | Trying it, spreading the brand | Free templates, up to 50 guests, RSVP, map, countdown, calendar, small "Made with Daawa" badge |
| Premium | $29 / event | Engagements, birthdays, small weddings | All Premium templates and openings, up to 300 guests, all fonts/colors, cover video, personal links, open tracking, reminders, no badge, custom link name |
| Signature | $79 / event | Full weddings | Everything in Premium, 3D openings, up to 1,000 guests, seating planner, QR check-in, wallet passes, live day page, photo wall 90 days |
| Venue & Planner Pro | $49–149 / month | Halls, hotels, planners | Own hall layouts, 15+ Signature events/month, white label, team accounts, directory listing |

### 13.2 Fifteen ways it earns

| Item | When the host sees it | Price |
|---|---|---|
| Premium & Signature plans | Choosing a template | $29 / $79 per event |
| Single template unlock | Likes one card, stays on Free | $9 |
| Seating planner | After first RSVPs | $15 |
| Photo cloud | Day after the event | $9 for 90 days · $25 for 1 year · $49 forever |
| Extra guest packs | Hits the guest limit | $10 per 200 guests |
| QR check-in | A week before | $10 |
| Wallet pass for every guest | Turning on RSVP | Included in Signature · $5 on Premium |
| WhatsApp sending & reminders | Sending | Message cost + margin, e.g. $5 per 100 |
| Video invitation editing | Wants a cinematic video | $20–40 (service) |
| Printed extras | Near the date | Table cards, QR stands |
| Video guestbook | Editing the card | $12 |
| Thank-you card + highlight gallery | After the event | $7 |
| Venue & planner subscriptions | B2B | $49–149 / month |
| Template marketplace | Browsing | Designers 70%, you 30% |
| Vendor listings | Planning page | Featured spots |

New from section 14: calligraphy name logo ($15–30), AI poem, highlight video, printed photo book, family journey bundles, digital gifts commission (where allowed).

### 13.3 Tactics

- Build for free, pay when sending.
- Show Signature as "everything for the wedding"; add-ons feel like a deal inside it.
- The Free badge is marketing: every free invite reaches ~50 people.
- Early-bird discounts before wedding season and Eid.

---

## 14. New ideas (1–9)

### 14.1 Ideas the samples point to

- **Separate openings from designs.** Any opening with any theme. 6 openings × 20 themes = 120 looks.
- **Personal WhatsApp preview per guest.** The preview image says "دعوة خاصة لعائلة خالد العلي". Cheap, raises open rate, rare in the region.
- **Who opened, who replied.** "45 opened, 30 replied, 15 opened but didn't reply." Remind only those 15. Strong Premium reason.
- **The invite changes on the day.** Live page with directions, table, live stream, live photo wall; afterwards the album and thank-you. One link for the whole event.

### 14.2 More events: the "family journey"

- Bundles across life: engagement → henna → wedding → baby welcome (عقيقة) → first birthday. One account, discount on each next event.
- New types: **Ramadan iftar** (families and companies), **كتب الكتاب**, Eid gatherings, housewarming (بيت جديد), **"حمد الله على السلامة"** (Hajj/Umrah return), welcome-home gatherings.
- **Corporate:** company iftars, launches, conferences (B2B, higher prices).
- **Regional styles:** Gulf, Levant (شامي), Egyptian, Moroccan (zellige/Andalusian), each with copy in its dialect.

### 14.3 AI

- **Write the story:** host answers 3 questions; AI writes "حكايتنا" in elegant Arabic or their dialect.
- **A short poem (أبيات)** with the couple's names. Paid add-on.
- **Colors from a photo:** upload the dress or flowers; the theme takes its palette.
- **Clean up a guest list:** paste a messy WhatsApp list → families, seat counts, phone numbers.
- **Auto-seating:** families together, kids together, sections respected.

Use the Claude API (called from a Laravel queued job): a small, fast model (Claude Haiku 5.5) for list cleanup and palette extraction, a stronger one (Claude Sonnet 5.5) for story and poem writing. Always let the host edit the result.

### 14.4 Diaspora and guests abroad

- Countdown in the guest's own time zone.
- Private live stream link only for invited guests abroad.
- **Digital gifts (نقوط / money gifts):** guests send a gift with a message. Check payment regulations in your country before building.

### 14.5 After the event

- Automatic highlight video from guest uploads (paid).
- Printed photo book from the album (physical, high margin).
- Anniversary reminder after one year that reopens the invite and album; often leads to a baby welcome invite.
- **"Save the date"** as a separate, cheap product months before the invite.

### 14.6 Calligraphy and designers

- Custom calligraphy logo of the couple's names by real Arabic calligraphers ($15–30).
- Marketplace of designers and calligraphers with a commission.

### 14.7 Growth and marketing

- **Every invite is an ad:** "صمّم دعوتك" at the end of each invite. Each wedding shows the product to ~300 guests.
- **Short videos:** screen recordings of the openings for TikTok, Reels, Snapchat. One sample a day.
- **Partners:** dress shops, jewelers, kosha designers, print shops. Discount code + commission.
- **Brides who create content:** a free Signature event in exchange for a video.
- **Seasons:** push before summer wedding season and after Ramadan/Eid.

### 14.8 For halls and venues (B2B)

- Meal count export per section (men/women/kids) from confirmed RSVPs.
- Door staff app: scan, live count vs capacity, duplicate warnings.
- White label for halls and hotels on a monthly subscription.

### 14.9 Watch out for

- **Data protection laws** (e.g. Saudi PDPL, UAE PDPL, Jordan's data protection law, Egypt's law 151/2020): consent, purpose, easy deletion.
- **Music copyright:** only your own or royalty-free zaffa/nasheed tracks.
- **Speed:** every effect adds weight; protect the first screen on cheap phones.

### 14.10 What to build first

1. Openings separate from themes.
2. Personal WhatsApp preview with the guest's name.
3. Open tracking with reminders to the silent ones.
4. AI story and poem in Arabic.
5. "صمّم دعوتك" at the end of every invite + daily short videos of the openings.

---

## 15. Tech stack

Decided: **Laravel + PostgreSQL, self-hosted on Hetzner with Forge, Cloudflare in front.** Chosen for: one developer can build it, everything runs on hosting you already have, fastest possible guest pages, strong security defaults, and good Arabic/RTL support.

**Why Laravel over Next.js here:** on your own server, Laravel already includes auth, queues, scheduler, mail, notifications, file storage, realtime (Reverb) and payments (Cashier), and Forge deploys it natively. With Next.js you would assemble those yourself. Guest invitations as Blade + vanilla JS ship ~30–40 KB instead of a React runtime. SEO/GEO is equally good with both; Blade makes it effortless.

**Why PostgreSQL over MySQL:** similar speed at this scale, but JSONB with GIN indexes (invitation configs, hall layouts), arrays, UUIDs, `timestamptz`, check constraints and partial indexes fit this data better.

### 15.1 Application

| Layer | Choice | Why |
|---|---|---|
| Backend | **Laravel 12** (PHP 8.4) | Batteries included; first-class on Forge. |
| Database | **PostgreSQL** (latest version Forge offers) | See above. Eloquent models + migrations in the repo. |
| Public pages (landing, occasion pages, blog, pricing) | **Blade** + Tailwind | Pure server HTML: fastest pages, best SEO/GEO. |
| Guest invitations | **Blade + vanilla JS opening modules** | ~30–40 KB per page, like the samples; edge-cached by Cloudflare. |
| Wizard, host & venue dashboards, seating editor | **Inertia.js + React + TypeScript** | Rich UI without a separate API; same routes, controllers and auth. |
| UI kit | **Tailwind CSS + shadcn/ui** | Ready tables/forms/dialogs; RTL with logical properties (`ms-`, `me-`, `ps-`, `pe-`). |
| Admin dashboard | **Filament** | Approvals, customers, templates, plans, refunds in hours. |
| Auth | **Laravel Fortify** (or Breeze starter) + **Socialite** (Google) | Email + password, email login codes, Google; phone/WhatsApp OTP later via a provider. |
| Authorization | **Policies + Gates** | A host sees only their events; org members only their org's. Replaces database row-level security. |
| Guest links | Random tokens + **rate limiting** (`RateLimiter`) | Unguessable, no login. Signed URLs for downloads. |
| Validation | **Form Requests** (server) + **Zod** (React editor) + JSON Schema for configs | Same rules everywhere. |
| Forms in React | **React Hook Form** | Wizard steps with autosave. |
| i18n / RTL | Laravel localization (`lang/ar`, `lang/en`) + `react-i18next` in Inertia pages | Arabic first, English toggle, Arabic-Indic digits. |
| Animations | Vanilla CSS/JS, **GSAP** where helpful, **Lottie** optional, **Three.js** only for Signature | Scroll-driven openings. |
| Seating editor | React + SVG + **dnd-kit** | Drag families onto tables. |
| Realtime | **Laravel Reverb** + Laravel Echo | Live RSVP counts, arrivals, live photo wall. Self-hosted WebSockets. |
| Queues & jobs | **Redis + Horizon** | Reminders, ZIP exports, wallet updates, AI calls, OG images, storage expiry. |
| Scheduler | Laravel scheduler (Forge runs `schedule:run`) | Daily reminders, expiring transfer orders after 48h, storage cleanup. |
| File storage | Laravel Storage (S3 driver) → **Hetzner Object Storage** or **Cloudflare R2** | Photos/videos off the server disk. R2 has no download (egress) fees. |
| Images | Client-side compression before upload; thumbnails in a queued job with **Intervention Image** (Imagick) | Small uploads, fast albums. |
| Email | Laravel Mail with the **Resend** (or Postmark / SES) driver | Receipts, confirmations, reminders. |
| WhatsApp | `wa.me` links first; **WhatsApp Cloud API** via Laravel Notifications later | Start free, automate when it pays. |
| Payments | **Laravel Cashier (Stripe)** where available + a local gateway via its SDK/webhooks + manual transfer | Card, Apple Pay, transfer. |
| Wallet | `pkpass/pkpass` (Apple), Google Wallet REST API | Section 10. |
| QR | `endroid/qr-code`, `qr-code-styling`, `BarcodeDetector` / `html5-qrcode` | Section 9. |
| PDF (seating charts, table cards) | **Browsershot** (headless Chrome) | Correct Arabic shaping; many PHP PDF libraries handle Arabic badly. |
| Link preview images | Rendered per guest with **Browsershot**, cached in storage | Correct Arabic text in the WhatsApp preview. |
| AI | **Claude API** from queued jobs | Section 14.3. |
| Short links | Second domain (e.g. `daawa.link`) routed to the same app | Clean WhatsApp links. |
| Errors / analytics | **Sentry** (Laravel SDK), **PostHog**; **Laravel Pulse** for server health | Section 22. |
| Tests | **Pest** + Laravel Dusk or Playwright for the wizard | Payments, RSVP and seat limits must never break. |

### 15.2 Hosting and deployment (Hetzner + Forge)

| Piece | Setup |
|---|---|
| Server | One Hetzner cloud server with about **4 GB RAM** to start (roughly €5–10/month). Provision it from Forge with PHP 8.4, Nginx, PostgreSQL, Redis. Upgrade size later with a few clicks. |
| Deploys | Forge zero-downtime deployments from the Git branch (`main` → production, `staging` → a staging site). Deploy script: `composer install --no-dev`, `npm ci && npm run build`, `php artisan migrate --force`, `php artisan optimize`. |
| Daemons | Forge daemons for **Horizon** (`php artisan horizon`) and **Reverb** (`php artisan reverb:start`). Nginx proxies WebSocket traffic to Reverb. |
| Scheduler | Enable the Laravel scheduler in Forge. |
| Database backups | Forge database backups every day to **Hetzner Object Storage** (S3-compatible), kept 30 days. Test a restore before launch. |
| Cloudflare | DNS proxied (orange cloud), **Full (strict)** SSL with a Cloudflare Origin certificate on the server, cache rules for `/i/*` and static assets, WAF and rate limiting on the free plan. |
| Secrets | `.env` managed in Forge; never in Git. |
| Monitoring | Forge server monitoring + Pulse + Sentry; uptime check on the invite route. |
| Scale later | Laravel Octane (FrankenPHP or Swoole) for more throughput; a separate database server; a second app server behind a Hetzner load balancer. |

Hetzner has no Middle East data center (closest are in Germany and Finland). Cloudflare caching of invitation pages and assets keeps them fast for guests in the region; dashboards are less sensitive to the extra latency.

---

## 16. Database

PostgreSQL, self-hosted on the Forge server. All ids are UUIDs unless noted (Laravel `HasUuids`). Timestamps `created_at`, `updated_at` on every table (omitted below). JSON columns are `jsonb`.

```sql
-- people & organizations
users           (id uuid pk, name, email unique null, phone unique null, password null, google_id null, locale,
                 role text check (role in ('user','admin')))
organizations   (id, name, type text check (type in ('venue','planner','company')), logo_url, plan_id, white_label jsonb)
org_members     (org_id, user_id, role text check (role in ('owner','staff','door')), pk(org_id,user_id))

-- design catalog
openings        (id text pk, name_ar, name_en, tier text, module_path, preview_url, active bool)
themes          (id text pk, name_ar, name_en, tier text, tokens jsonb, fonts jsonb, preview_url, designer_id null, active bool)
templates       (id text pk, occasion text, name_ar, name_en, tier text, opening_id, theme_id, default_config jsonb, sort int, active bool)

-- events & invitations
events          (id, owner_id, org_id null, occasion, title, starts_at timestamptz, timezone, venue_name, address,
                 lat, lng, hall_layout_id null, status text check (status in ('draft','active','ended','archived')),
                 plan_id, guest_limit int, storage_until date)
invitations     (id, event_id unique, template_id, config jsonb, config_version int, published_at, slug unique)

-- guests
households      (id, event_id, display_name, phone, seats_allowed int, section text null, token text unique,
                 tags text[], first_opened_at, last_opened_at, open_count int default 0)
rsvps           (id, household_id unique, attending bool, people_count int, kids_count int, message text,
                 extras jsonb, responded_at)
reminders       (id, event_id, household_id, channel text, sent_at, status)

-- halls & seating
hall_layouts    (id, org_id null, owner_id null, name, layout jsonb)        -- schema in section 7.1
seat_assignments(id, event_id, household_id, table_id text, seat_index int, person_label text,
                 unique(event_id, table_id, seat_index))

-- day of the event
checkins        (id, event_id, household_id, scanned_by, people_in int, scanned_at)

-- media
media           (id, event_id, household_id null, kind text check (kind in ('photo','video')), storage_key,
                 thumb_key, width, height, bytes, hidden bool default false)
guestbook       (id, event_id, household_id null, kind text, text, media_id null)
roses           (event_id pk, count int)                                     -- "send a rose" counter

-- wallet
wallet_passes   (id, household_id, platform text check (platform in ('apple','google')), serial, pass_url, updated_at)

-- money
plans           (id text pk, name, price_cents int, currency, interval text null, limits jsonb, active bool)
addons          (id text pk, name, price_cents int, currency, active bool)
orders          (id, code text unique, user_id, event_id null, org_id null, items jsonb, total_cents int, currency,
                 method text check (method in ('card','transfer','wallet_local')),
                 status text check (status in ('awaiting_payment','receipt_received','paid','approved','rejected','expired','refunded')),
                 expires_at, paid_at)
payments        (id, order_id, provider, provider_ref, amount_cents, status, raw jsonb)
transfer_receipts(id, order_id, received_via text, received_at, note, approved_by null, decided_at null, decision text null)
entitlements    (id, event_id null, org_id null, feature text, until timestamptz null, source_order_id)

-- growth & ops
link_opens      (id bigserial, household_id, opened_at, ua_hash)             -- for open tracking
referrals       (id, referrer_user_id, referred_user_id, reward_status)
ai_generations  (id, event_id, kind text, input jsonb, output text, model, cost_cents)
audit_log       (id bigserial, actor_id, action, entity, entity_id, data jsonb, at)
```

**Key rules**

- **Access control in Laravel Policies:** a host sees only their events; org members see their org's events; admins use Filament. Guests never touch tables directly; they go through rate-limited routes using the household token.
- **Draft events before sign-up** belong to an anonymous session id and are attached to the user when they register at "Pay & send".
- **GIN index** on `invitations.config` and `hall_layouts.layout` if you query inside them.
- **Entitlements** decide what is unlocked (plan features + add-ons), so paid features are checked in one place: `hasFeature(eventId, 'seating')`.
- **Indexes:** `households(token)`, `households(event_id)`, `rsvps(household_id)`, `orders(code)`, `link_opens(household_id, opened_at)`, `media(event_id)`.
- **Soft limits:** guest count and storage checked on write against the plan.

---

## 17. Project structure

```
daawa/
├─ app/
│  ├─ Models/                 User, Organization, Event, Invitation, Household, Rsvp, HallLayout,
│  │                          SeatAssignment, Checkin, Media, Order, Payment, TransferReceipt, Entitlement …
│  ├─ Http/
│  │  ├─ Controllers/
│  │  │  ├─ Marketing/         landing, occasion pages, pricing, blog
│  │  │  ├─ Create/            wizard steps (Inertia)
│  │  │  ├─ Dashboard/         host dashboard, guests, seating, album (Inertia)
│  │  │  ├─ Venue/             venue dashboard, hall designer (Inertia)
│  │  │  ├─ Door/              door app check-in
│  │  │  ├─ Invite/            public invitation, RSVP, rose, wallet pass, uploads (Blade + JSON)
│  │  │  └─ Webhooks/          payment gateways, Apple Wallet web service
│  │  ├─ Requests/            Form Requests (validation)
│  │  └─ Middleware/          locale/RTL, household token, rate limits
│  ├─ Policies/               EventPolicy, HouseholdPolicy, OrganizationPolicy …
│  ├─ Services/
│  │  ├─ InvitationConfig/    JSON Schema validation, defaults, migrations between versions
│  │  ├─ Entitlements.php     hasFeature($event, 'seating')
│  │  ├─ HallGenerator.php
│  │  ├─ Hijri.php, Digits.php
│  │  ├─ Payments/            StripeGateway, LocalGateway, TransferFlow
│  │  ├─ Wallet/              ApplePass, GooglePass
│  │  └─ WhatsApp.php, Qr.php, Ai.php, OgImage.php
│  ├─ Jobs/                   SendReminders, ExportAlbumZip, ExpireOrders, UpdateWalletPasses,
│  │                          GenerateOgImage, AiWriteStory, MakeThumbnails, PurgeEdgeCache
│  ├─ Events/ + Listeners/    RsvpSubmitted, GuestCheckedIn, MediaUploaded (broadcast via Reverb)
│  ├─ Notifications/          order approved, reminders (mail + WhatsApp channels)
│  └─ Filament/               admin resources: Orders/Approvals, Customers, Events, Templates, Plans
├─ resources/
│  ├─ views/
│  │  ├─ marketing/           Blade pages (SEO/GEO)
│  │  └─ invite/              invitation layout + sections/*.blade.php
│  ├─ js/
│  │  ├─ Pages/               Inertia React pages (wizard, dashboards, seating, door)
│  │  ├─ components/          shadcn/ui, seating/*, preview iframe
│  │  ├─ openings/            envelope.js, rose.js, ringbox.js, doors.js, gift.js, diploma.js
│  │  └─ lib/                 config schema (Zod), hall generator (shared logic), digits
│  └─ css/
├─ templates/                 JSON templates + theme tokens (data, not code), seeded into the DB
├─ database/migrations, seeders, factories
├─ lang/ar, lang/en
├─ routes/web.php, routes/channels.php, routes/console.php
└─ tests/                     Pest feature tests (RSVP limits, payments, approvals, policies)
```

---

## 18. Routes and actions

| Area | Routes / actions |
|---|---|
| Wizard (Inertia) | `POST /create` (draft for occasion), `PATCH /events/{event}/config` (autosave patch), `POST /events/{event}/media`, `POST /events/{event}/ai/story`, `POST /events/{event}/ai/poem` |
| Guests | `POST /events/{event}/guests/import` (text or CSV), `POST /events/{event}/guests/clean` (AI), `PATCH /households/{household}`, `POST /events/{event}/remind` (silent only) |
| Public invite | `GET /i/{token}` (Blade, logs open), `GET /i/{token}/state` (JSON: name, seats, RSVP), `POST /i/{token}/rsvp`, `POST /e/{slug}/rose`, `GET /i/{token}/wallet/{platform}`, `GET /i/{token}/og.png` |
| Seating | `POST /halls/generate`, `POST /events/{event}/seating/auto`, `PUT /events/{event}/seats`, `GET /events/{event}/seating.pdf` |
| Door | `POST /door/{event}/checkin` → name, seats, table, duplicate warning (broadcast to dashboard) |
| Media | `POST /i/{token}/uploads` (signed upload URL), `PATCH /media/{media}` (hide), `POST /events/{event}/album/export` (queued ZIP, emailed link) |
| Payments | `POST /checkout` (create order: card or transfer), `POST /webhooks/stripe` (Cashier), `POST /webhooks/{gateway}`, `POST /orders/{code}/receipt` |
| Admin (Filament) | Approve / reject transfers, templates and themes, plans and add-ons, refunds, customers, audit log |
| Realtime (Reverb channels) | `private-event.{id}` (RSVPs, check-ins), `public-wall.{slug}` (approved photos for the live wall) |

---

## 19. Integrations

| Integration | Start with | Later |
|---|---|---|
| WhatsApp | `wa.me` share links per guest | WhatsApp Cloud API with approved templates for reminders and confirmations |
| Maps | Google Maps / Apple Maps deep links, OpenStreetMap preview | Places autocomplete in the wizard |
| Calendar | `.ics` file + Google Calendar link | — |
| Email | Resend | — |
| Payments | Gateway + manual transfer | Local instant transfer APIs |
| Wallet | Apple + Google passes | Pass updates via push |
| AI | Claude API | Photo palette extraction, auto-seating |
| Live stream | Paste a YouTube/Instagram live link | Private stream embed |
| Analytics | PostHog, Sentry, Laravel Pulse | — |
| Edge cache | Cloudflare cache rules | Purge per invitation via Cloudflare API on edit |

---

## 20. Security, privacy and legal

- **Tokens:** unguessable, rate-limited lookups, no listing of guests on public pages.
- **Public pages:** `noindex`; no event appears in search engines.
- **Photo albums:** optional PIN; signed URLs that expire.
- **Personal data:** collect only what is needed (name, phone, RSVP). Consent text on the RSVP. Delete on request; auto-delete after the storage period.
- **Data protection laws:** check the law of each country you sell in (e.g. Saudi PDPL, UAE PDPL, Jordan, Egypt law 151/2020). Some require local storage or registration; ask a local lawyer before launch.
- **Payments:** never store card data (gateway handles it). Admin approvals logged.
- **Music:** only your own or licensed royalty-free tracks.
- **Money gifts:** may need a payment license; partner with a licensed provider instead of holding money.
- **Terms & refund policy** visible at checkout.

---

## 21. Performance rules

- First screen of an invite visible in under **2 seconds on 4G** on a mid-range Android.
- Invite page total JS under ~60 KB (no framework on guest pages; one opening module).
- Fonts: only the weights used, Arabic subset, self-hosted or Google Fonts with `display=swap`.
- Images: responsive sizes, WebP/AVIF, lazy below the fold.
- Animations: transforms and opacity only; `requestAnimationFrame`; respect reduced motion.
- Three.js openings only on Signature, after the first screen, with a still fallback.
- Cache public invite HTML at Cloudflare; per-guest data from a small JSON call; purge on edit.
- Dashboards: Vite code-splitting per Inertia page; seating editor loaded only on its page.
- Server: `php artisan optimize`, OPcache on (Forge default), Redis for cache/sessions/queues; Octane later if needed.

---

## 22. Analytics and KPIs

| KPI | Why |
|---|---|
| Draft → paid conversion | Is the wizard working? |
| Time to first send | Should be under 10 minutes. |
| Open rate per invite | Effect of the personal preview. |
| RSVP rate | Effect of reminders. |
| Add-on attach rate | Revenue per event. |
| "صمّم دعوتك" clicks per invite | Viral loop strength. |
| Repeat customers (family journey) | Lifetime value. |
| Venues active per month | B2B channel health. |

---

## 23. Roadmap

| When | Goal | Work |
|---|---|---|
| Weeks 1–2 | **Validate** | Sell 3 hand-made invites to ~10 real couples. Talk to 5 halls about seating and Smart Hall. Pick country, currency, gateway. |
| Weeks 3–4 | Foundations | Laravel 12 + PostgreSQL + Redis on a Forge server, Cloudflare, staging site; auth (Fortify + Google), Inertia React + shadcn, Filament admin; migrations; config JSON Schema; Blade invitation renderer with 2 openings (envelope, rose) and 4 themes. |
| Weeks 5–7 | Creation | 5-step wizard with autosave and live preview; template gallery; media upload. |
| Weeks 8–9 | Guests | Households, personal links, RSVP, open tracking, host dashboard, WhatsApp share links, personal OG previews. |
| Weeks 10–11 | Money | Plans, entitlements, gateway checkout, bank transfer flow, admin approvals. |
| Week 12 | **Launch MVP** | Free + Premium, 10 templates, 4 openings, Arabic + English. |
| Months 4–5 | V2: event tools | Seating planner + auto-seat, QR check-in door app, wallet passes, reminders, photo wall, live day page, Signature plan, AI story/poem. |
| Months 6–9 | V3: platform | Venue Pro + hall designer, white label, marketplace, vendor listings, thank-you cards, highlight video, photo book, family journey bundles, iftar/corporate. |

---

## 24. Running costs

Approximate, at the start. Check current prices before committing.

| Item | Monthly |
|---|---|
| Hetzner cloud server (~4 GB RAM) | ~€5–10 (a second one for staging is optional) |
| Laravel Forge | Your existing plan |
| Hetzner Object Storage or Cloudflare R2 | A few € to start (R2 ~$0.015 per GB stored, no egress fees) |
| Database backups | Included in the object storage above |
| Cloudflare | Free plan |
| Email (Resend / Postmark / SES) | Free tier, then ~$10–20 |
| Sentry, PostHog | Free tiers |
| Domain + short domain | ~$2–4 |
| Apple Developer | $99 / year |
| WhatsApp Cloud API | Per message, varies by country |
| Claude API | Pay per use; a story or poem costs cents |
| Payment gateway | % per transaction (typically 2.5–3.5% + fixed fee) |

Expect **about €15–30/month** before real traffic, plus the Apple fee once a year.

---

## 25. Launch and marketing

- Instagram/TikTok/Snapchat account posting one opening video per day (envelope, rose, doors…).
- Five sample invitations live on the landing page (links in section 27).
- 10 launch partners: halls, dress shops, jewelers, kosha designers, print shops, each with a discount code and commission.
- 3–5 brides who create content: free Signature event in exchange for a video.
- "صمّم دعوتك" footer on every invite, with a referral credit.
- Campaigns before summer wedding season and after Ramadan/Eid; iftar invites during Ramadan.

---

## 26. Risks

| Risk | Answer |
|---|---|
| Heavy effects make invites slow | Performance budget (section 21); 3D only on Signature. |
| One-time customers | Many event types, family journey, venue subscriptions, referrals. |
| Photo storage costs | Compression, size limits per plan, paid extensions, auto-delete. |
| Privacy | Unlisted links, PIN, deletion, legal check per country. |
| Seasonality | Birthdays, graduations, iftar and corporate in quiet months. |
| Copycats | The moat is guest data, seating, venue network and speed, not visuals. |
| Fake transfer receipts | Approve only from the bank account; unique codes; audit log. |
| Arabic rendering bugs (previews, PDFs) | Test Arabic shaping early in every image/PDF pipeline. |

---

## 27. Sample invitations

Built as references for the openings and sections. Private links; share them from the page menu when needed.

| Sample | Opening | Source · Link |
|---|---|---|
| Plan page (features, hall generator, pricing, wallet, payments) | — | [`samples/index.html`](samples/index.html) · https://claude.ai/artifact/CUPbBAqeens66kjbcLMWA8 |
| Wedding · Layali | Envelope | [`samples/invite-layali.html`](samples/invite-layali.html) · https://claude.ai/artifact/GEyJQaijABdopNBTNthf5H |
| Wedding · Reem & Omar | Pink rose blooming | [`samples/invite-warda.html`](samples/invite-warda.html) · https://claude.ai/artifact/5sUZt51HWK8oBZRXui9PVp |
| Engagement · Rana & Karim | Ring box | [`samples/invite-khutuba.html`](samples/invite-khutuba.html) · https://claude.ai/artifact/CQrHSCW4K2qjpTFRzhcZqr |
| Henna night · Salma | Arched doors + henna drawing | [`samples/invite-henna.html`](samples/invite-henna.html) · https://claude.ai/artifact/DaBh4nKEEfBRq1uWi6V6tH |
| Birthday · Adam turns 7 | Gift box, balloons, confetti | [`samples/invite-birthday.html`](samples/invite-birthday.html) · https://claude.ai/artifact/HCE6D6DcrX29Zn6Npft8GT |
| Graduation · Rami Haddad | Diploma unrolling, caps | [`samples/invite-graduation.html`](samples/invite-graduation.html) · https://claude.ai/artifact/D5qsS522KYCY7VSkLciBit |

Source files live in [`docs/samples/`](samples/): `invite-layali.html`, `invite-warda.html`, `invite-khutuba.html`, `invite-henna.html`, `invite-birthday.html`, `invite-graduation.html`, `index.html`. The envelope and rose openings are already ported to `resources/js/openings/envelope.js` and `rose.js`. Each opening's scroll logic (the `frame()` function) can be moved almost as-is into `resources/js/openings/`, and each sample's sections map directly to Blade partials in `resources/views/invite/sections/`.

---

## 28. Before you write code

- [ ] Pick the first country (sets currency, gateway, data law, WhatsApp pricing).
- [ ] Sell 3 hand-made invites to real couples; note what they ask for.
- [ ] Talk to 5 halls; confirm they want seating + meal counts + door scanning.
- [ ] Register the name and both domains (main + short link).
- [ ] Provision the Forge server on Hetzner (PHP 8.4, PostgreSQL, Redis), a staging site, Cloudflare DNS, object storage bucket, daily DB backups (and test one restore).
- [ ] Open accounts: payment gateway (business verification takes time), Apple Developer, Google Wallet issuer, Meta WhatsApp Business, email provider.
- [ ] Get a local bank account for transfers and decide who approves them.
- [ ] Ask a lawyer about data protection and money gifts in your country.
- [ ] Commission or design 4 themes and confirm font licenses (Google Fonts are free for commercial use).
- [ ] Freeze the invitation config schema v1 (section 6.2) before building the wizard.
