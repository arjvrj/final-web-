# Stage 7 · Phase 1 — Findings before any change

**Nothing was modified in this phase.** No database change, no payment-flow change,
no file in `app/` changed. Checkpoint for the whole stage: tag `stage-7-start`
(`94ce216`).

---

## 1. The Razorpay library that already exists

`app/includes/payments.php` (~500 lines) is a **hardened library, but it is not wired
to anything**. `grep` confirms **zero call sites** outside the file itself and the
test suite.

What it already does correctly (89 assertions pass, no network, no key):

| Area | Function | Notes |
|---|---|---|
| Config resolution | `rg_payment_config()` | Reads `payments_mode`, `razorpay_key_id`, `razorpay_key_secret`, `razorpay_webhook_secret`, `razorpay_live_confirm`, `razorpay_timeout`, `whatsapp_number` |
| Gating | `rg_payment_status()` | Refuses to run unless mode ∈ {disabled,test,live}, key prefix matches the mode, secret present, and live mode carries the literal `I_AUTHORIZE_REAL_CHARGES` confirmation |
| Public surface | `rg_public_config()` | Returns `enabled`, `mode`, `reason`, `key_id` (only when enabled), `currency`, `whatsapp_available`. **No secret can leave this function** |
| Checkout signature | `rg_verify_checkout_signature()` | `hash_hmac('sha256', payment_id|subscription_id, secret)` + `hash_equals` + hex-shape check |
| Webhook signature | `rg_verify_webhook_signature()` | `hash_hmac('sha256', rawBody, webhook_secret)` + `hash_equals` |
| Provider call | `rg_razorpay_request()` | Injected transport for tests; never logs the secret |
| Provider re-check | `rg_confirm_payment_with_provider()` | Requires `status === 'captured'`, currency `INR`, exact paise, and subscription match — **a valid signature alone is not trusted** |
| Idempotency | `rg_payment_row()` + `rg_is_duplicate_error()` | Builds the row; duplicate-key errors are recognised |
| Amount | `rg_plan_amount_paise()` | Derives paise from the plan row (sale_price when lower) |

**What it does not have:** an orders flow, a `payments` table, a webhook endpoint, a
place in the UI, or any settings UI. `rg_create_subscription()` additionally needs
`plans.razorpay_plan_monthly` / `razorpay_plan_yearly` columns, **which do not exist**
— so the subscription path cannot run as shipped.

## 2. Existing database — what payments can hang off

```
users(id, email, password, created_at)
review_cards(id, user_id → users, name, slug UNIQUE, membership VARCHAR(50) default 'trial',
             expiry_date DATETIME NULL, details JSON, created_at)
plans(id, name, description, price DECIMAL(10,2), sale_price DECIMAL(10,2) NULL,
      features JSON, cta VARCHAR(255), duration INT default 30, created_at)
settings(setting_key UNIQUE, setting_value TEXT)
platform_settings(whatsapp, details JSON)
reviews, feedbacks, keywords
```

Answering your Phase 1 questions directly:

- **No subscription table exists.** There is no `payments`, `orders`, `subscriptions`
  or `webhook_events` table. `rg_payment_row()` documents the columns a `payments`
  table *would* need, and `rg_whatsapp_request_row()` a WhatsApp-request row — but
  nothing creates or writes either.
- **No `razorpay_*` column on `plans`.** Prices are `price` + optional `sale_price`,
  and the paid period is `duration` in days (30 / 90 in the sample data).
- **A paid membership is only two fields:** `review_cards.membership` (a free-text
  plan name) and `review_cards.expiry_date`. Activation = write those two.
- **No payment settings are seeded** by `install.php` and **there is no admin screen**
  for them. `owner_settings.php` only handles WhatsApp number, app name and the GROQ
  key.

## 3. The existing WhatsApp upgrade path (to be preserved)

`card_membership.php` → `upgradePlan(name, price)` → sets
`https://wa.me/<platform number>?text=…` on a link inside `#upgradeModal`.

- The platform number comes from `platform_settings.whatsapp` (`$platformWhatsapp`).
- It is a **pure deep link**: nothing is recorded in the database, no membership is
  granted, nothing is charged. It stays exactly as it is; a "Pay online" button is
  added beside it, not instead of it.

## 4. `card_reactivate_trial.php` — the unlimited-trial bug

```php
UPDATE review_cards SET membership = 'trial', expiry_date = DATE_ADD(NOW(), INTERVAL 3 DAY) WHERE id = ?
```

- It does verify CSRF and ownership → those are fine.
- It has **no record that a trial was ever used**, so it can be called unlimited
  times: an expired card is renewed free forever.
- It also **overwrites a paid membership** if it is called on a card that is
  currently paid (the button is only *shown* on an expired trial, but the endpoint
  itself does not check).
- Trials are granted in two more places: `onboard.php` (new card → `trial`, +3 days)
  and `install.php`'s schema default.

## 5. Your keys

The file you supplied contains **LIVE mode keys** (`rzp_live_…`, 23 characters, plus a
24-character secret).

- They are **not** in the repository, not in any file under `reviewgrow-php/`, and I
  have not printed them anywhere.
- They are **not usable for Phase 5**, which asks for Test Mode. Real checkout tests
  with live keys would take real money from real cards — exactly what you told me not
  to do. **Please generate Test Mode keys** (Razorpay dashboard → Test Mode → API
  keys) and send those; the live pair stays unused until you approve go-live.

---

## 6. Decisions I need from you before touching the database

### Decision 1 — Orders or Subscriptions? *(I recommend Orders)*

| | **Orders (one-time)** — recommended | Subscriptions (auto-recurring) |
|---|---|---|
| Amount  | Computed server-side from the plan row, in paise | Same, but a Razorpay **plan object must be created per DB plan** (new columns + sync) |
| Signature | `order_id|payment_id` | `payment_id|subscription_id` |
| Your rule "access continues until the paid-through date, no auto refund" | Natural: we write `expiry = now + duration days` | Needs mandate lifecycle + cancel-at-cycle-end handling |
| India recurring | n/a | Needs e-mandate setup and account approval |
| Testability without test keys | Server logic fully testable offline | Same, but more moving parts |
| Effort | 1 stage | 1–2 stages |

With Orders, a renewal is simply another payment that **extends** the existing expiry
(never shortens it). Nothing auto-debits a customer without them paying again — which
also matches "do not automatically refund / do not auto-charge".

**Please confirm: Orders (one-time per plan period).** If you want automatic renewal
later, the subscription helpers stay in the library for that.

### Decision 2 — Trial scope

Your wording: *"One 3-day trial per business account."* I will implement it **per
account (`users` row)**, not per card: once a business has used its trial, creating a
second review card does not grant a new one.

Migration will backfill: every account that already has a card on `trial` is marked as
having used its trial, so nobody gets a fresh 3 days from this change.

**Please confirm: per business account** (and not per review card).

### Decision 3 — Test Mode keys

Without `rzp_test_…` keys I can build everything and prove the server logic offline,
but I cannot honestly claim "a real checkout succeeded / failed / was cancelled".
**Please send Test Mode keys.** I will keep both pairs out of Git, put them in the
database (masked in the admin UI, exactly like the GROQ key), and never echo them.

### Decision 4 — Migrations (four changes, all additive)

| # | Change | Purpose |
|---|---|---|
| 1 | **New table `payments`** — `id, user_id, card_id, plan_id, razorpay_order_id, razorpay_payment_id (UNIQUE), razorpay_signature, amount_paise, currency, status ENUM('created','paid','failed','cancelled'), environment ENUM('test','live'), channel ENUM('razorpay','whatsapp'), paid_at, created_at, updated_at` | Transaction history + **duplicate protection by unique key**, not by hope |
| 2 | **New table `webhook_events`** — `id, event_id (UNIQUE), event_type, signature_ok, payload JSON, processed_at` | Duplicate/replayed webhooks are recognised and ignored |
| 3 | **`users.trial_started_at DATETIME NULL`** (+ backfill from existing trial cards) | One 3-day trial per business, enforced server-side |
| 4 | **Seed payment settings** (`payments_mode = disabled`, empty keys, `razorpay_timeout`) | So the app ships with payments **off** until you switch them on |

Nothing is dropped, renamed or narrowed. Existing rows are untouched apart from the
trial backfill. A `sql/migrations/` file will hold the exact statements, and
`install.php` will create the same tables for fresh installs.

---

## 7. Work planned after your approval (Phase 2–6)

**New files**
- `app/pay_create_order.php` — POST + CSRF + login + ownership; amount from the plan row; returns order id, amount, public key id.
- `app/pay_verify.php` — POST + CSRF; verifies `order_id|payment_id` HMAC **then** re-checks with `GET /orders/{id}` and `GET /payments/{id}` (status captured, exact paise, INR); activates once, idempotently.
- `app/payment_webhook.php` — raw body read, `X-Razorpay-Signature` verified, `webhook_events` insert for idempotency, `payment.captured` / `payment.failed` handled; always answers 200 quickly; no session, no CSRF (signature is the auth).
- `app/card_payments.php` — transaction history for the owner (design: dashboard shell, `billing-scroll` table), with a link from the membership page.
- `sql/migrations/2026-11-payments.sql` + `docs/STAGE-7-PAYMENTS.md`.

**Changed files**
- `app/includes/payments.php` — add the order functions (reuse the existing signature, request and confirmation helpers; nothing removed).
- `app/card_membership.php` — "Pay online" per plan beside the WhatsApp button, Razorpay checkout script loaded locally only when payments are enabled, result banner, link to history.
- `app/card_reactivate_trial.php` — one-trial rule, and refuse to overwrite any paid membership.
- `app/onboard.php` — record the first trial grant.
- `app/owner_settings.php` — payment settings (mode, key id, secret, webhook secret, timeout) with the secret masked, never printed.
- `app/install.php`, `app/.htaccess` (webhook route), tests and docs.

**Verification plan** — the ten cases you listed: success, failure, cancelled, bad
signature, wrong amount, wrong currency, duplicate webhook, replayed webhook, another
business reaching an order, activation/expiry, one-trial-only, WhatsApp preserved —
plus the whole existing suite (currently 24 + 73 + 89 + 51 + 56 + 45 + 47 assertions,
all green).

---

## 8. What I will NOT do

- No live charge, no live API call, no live key in the repository or in any log.
- No change to plan prices or durations.
- No removal of the WhatsApp path.
- No automatic refunds, no auto-debit mandates.
- No deployment, no upload to Hostinger, no DNS change.
