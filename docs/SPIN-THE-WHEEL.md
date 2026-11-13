# Spin the Wheel — what was built

A customer completes a feedback round on the QR page, then gets **one** spin,
drawn from the business's own odds. The spin is decided on the server, real
coupons are issued, and the state the customer is in comes from the database —
never from the browser.

This page documents what changed, why, and how it was tested.

---

## 1. What a customer sees

1. Opens the QR link (`review.php?slug=…`) and rates 1–5 stars. Any rating works.
2. Picks topics / writes a line, then presses **Write my review**.
3. The wheel is visible from the start but locked: the centre button is off and
   the card reads *"Your spin unlocks after you submit your review."*
4. When the draft is written — or when the private feedback is sent — the server
   records the customer as eligible, the button turns on, and the card reads
   *"Your free spin is ready!"*
5. One tap turns the disc for about 4.6 seconds and the result screen shows one of:
   - a discount: the percentage, the coupon code, the expiry date, the business
     name, the redemption instructions, and a copy button;
   - "Better Luck Next Time!";
   - "Thank You for Your Feedback!".
6. That is the only spin for this customer in this campaign.

The seven prizes and the default odds:

## 2. How the draw stays honest

- **Server only.** The browser never chooses a prize. It posts a token and gets
  back what the server drew.
- **Integer odds.** Probabilities are stored in millionths of a percent
  (`RG_WHEEL_UNIT = 100000000`), so 100% is exact and there is no floating-point
  drift in the draw.
- **One secure draw.** `rg_wheel_pick()` takes a single `random_int(0, total-1)`
  and walks the ranges. No `mt_rand`, no seeded shuffle, no client input.
- **Exactly 100%.** `rg_wheel_validate()` refuses anything else, so a campaign
  that would quietly change the real odds can never be switched on.
- **One spin per customer.** `wheel_participations` has a UNIQUE key on
  `(card_id, participant_key)` and `wheel_spins` on
  `(campaign_id, session_key, spin_no)`, so a second spin for the same person
  raises 23000 and the page answers "already spun".
- **No double spin.** The winner is claimed with
  `UPDATE wheel_spins SET status='spinning' WHERE id=? AND status='pending'`
  inside a transaction and the `rowCount()` is checked; if two tabs race, only
  one wins.
- **No fake prizes.** A coupon exists only for a discount segment. A prize whose
  own stock ran out falls back to "Better Luck Next Time", and the spin is
  recorded as such.
- **Results cannot be rewritten.** Each spin and each coupon stores its own copy
  of the prize label, kind and percentage. Editing the campaign afterwards does
  not change a past result.

## 3. Database

New tables, added to `app/install.php` and `sql/schema.sql`, with a standalone
migration in `sql/migrations/2026-11-wheel.sql`:

| Table | Purpose |
|---|---|
| `wheel_campaigns` | one row per card: on/off, dates, coupon expiry days, coupon cap, redemption note |
| `wheel_segments` | the seven prizes: label, kind, discount %, weight (integer), own stock cap, order, on/off |
| `wheel_spins` | one row per spin: unique token, unique `feedback_id`, status, prize snapshot, coupon link, ip hash |
| `wheel_coupons` | one row per coupon: unique `code`, unique `spin_id`, discount, expiry, redeemed date |

Nothing existing was altered or dropped.

## 4. Owner dashboard

**Rewards** in the left menu (`card_wheel.php?slug=…`):

- switch the wheel on or off;
- campaign start and end dates (both inclusive; empty = no limit);
- coupon validity in days, campaign-wide coupon cap;
- redemption instructions printed with every coupon;
- each prize's name, its probability, its own stock cap, and on/off;
- a live total that must read exactly 100%;
- "Restore default odds" to get back the table above;
- totals: spins, coupons issued, coupons redeemed, unclaimed spins;
- the real prize distribution;
- the 50 most recent coupons, with **Mark used** / **Undo**.

Saving is CSRF-protected, POST-only, ownership-checked, and refused entirely if
the numbers do not add up to 100%. Saving never touches a completed spin.

## 5. Files

| File | What it does |
|---|---|
| `app/includes/wheel.php` | all wheel logic: defaults, validation, draw, tokens, spin, coupons, stats, SVG wheel |
| `app/wheel_spin.php` | the one endpoint the page posts to; CSRF-protected, JSON only |
| `app/card_wheel.php` | the Rewards page |
| `app/card_update_wheel.php` | saves campaign/prizes, marks coupons used |
| `app/assets/css/customer-wheel.css` | the wheel on the customer page |
| `app/assets/css/dashboard-wheel.css` | the Rewards page |
| `sql/migrations/2026-11-wheel.sql` | the four tables, for an existing database |

## 6. Tests

```
node tools/php-run.mjs tests/php/test_wheel.php --require app/includes/wheel.php
```
60 checks: the seven prizes and their odds, the total being exactly 100%,
validation refusing 99.999999% and 100.000001%, a single-prize wheel being
refused, every wedge reachable at both edges of its range, "Better Luck" never
taking a discount's range, dates being inclusive, a second spin on the same
feedback being refused, a spent token being refused, a 72-hour-old token
expiring, no coupon for a non-discount prize, and code format.

```
node tools/test-review-flow.mjs
```
133 browser checks, including the wheel: it is present but locked, seven wedges
are drawn, the odds are published, private feedback unlocks it, the spin posts
the token and slug to `wheel_spin.php`, the result shows the prize, code, expiry
and redemption line, the button is disabled afterwards, the page does not widen
on a phone, and a card with no campaign or an expired membership shows no wheel.

Also green: `php-lint` 47/47, `check-schema` 55/55, `test-security` 121,
`test-public-csrf` 12, `test-social-links` 27, `test-review-endpoint` 16,
`test-qr` 11, `verify-assets`, `audit-dashboard`.

---

## 7. The one-spin system (current behaviour)

1. Scan the QR code → rate 1–5 stars → pick topics, add words if wanted.
2. **Write my review** → the drafts appear. The successful answer marks this
   customer *eligible* on the server (a row in `wheel_participations`).
   Sending the private feedback does the same, so either completed feedback round
   unlocks it.
3. The wheel is locked until then: the centre button is disabled and the card says
   *"Your spin unlocks after you submit your review."*
4. Once eligible the button turns on, the card says *"Your free spin is ready!"*,
   and one tap turns the disc five times from the server's answer.
5. The result is recorded and shown — coupon code, expiry, business, copy button —
   and the wheel is done for that customer. There is no second spin.

### One customer, one spin

| Guard | Where |
|---|---|
| `wheel_participations` UNIQUE `(card_id, participant_key)` | one participation per customer per card |
| `wheel_spins` UNIQUE `(campaign_id, session_key, spin_no)` | one spin row per participation |
| `eligible = 0` until the server sets it | the browser cannot unlock the wheel |
| the spin is claimed with `UPDATE … WHERE status = 'pending'` in a transaction | two tabs cannot both win |
| `rowCount()` must be exactly 1 | proves the claim |
| `participant_key` is a 64-hex, HttpOnly, SameSite=Lax cookie, 365 days | survives refresh, restart and re-scan |
| the state is re-read from the database on every render | a refresh never resets the count |

What this cannot do: identify a person across different devices or after the
cookie is cleared. That needs a login or a phone OTP, and the code does not claim
otherwise.

### Database

Two tables carry the reward:

- `wheel_participations` — the customer's participation: `eligible`,
  `eligible_at`, `eligible_source` (`draft` or `feedback`), `feedback_id`,
  `spin_id`, `spun_at`, `prize_label`, `discount_percent`, `coupon_id`.
- `wheel_spins` — the spin itself: token, status, prize snapshot, coupon link.

Fresh installs need nothing: `install.php` and `sql/schema.sql` already create
both. Existing installs run `sql/migrations/2026-11-wheel-one-spin.sql` once.

### What the browser is allowed to know

The spin number comes from the row the token points at, never from a form field.
`wheel_spin.php` accepts `slug` and `token` and nothing else about the draw.
