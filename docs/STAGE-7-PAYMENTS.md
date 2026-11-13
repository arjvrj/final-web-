# Stage 7 — Razorpay payments

**Status: BUILT AND TESTED OFFLINE — checkout not yet exercised against Razorpay**
Commit `9d07bf9` · tag `stage-7-payments` · checkpoint before the work: `stage-7-start` (`94ce216`)

Phase 1 findings: `docs/STAGE-7-PHASE-1-FINDINGS.md`.
Your four decisions: **Orders** (one-time per plan period) · trial **per business
account** · **test keys will follow** · **all four migrations approved**.

---

## 1. What was built

| Piece | File | What it does |
|---|---|---|
| Order creation | `app/pay_create_order.php` | POST + CSRF + login. Takes a **card id and plan id only**; the amount is read from the `plans` row on the server. Stores a `payments` row with status `created`. |
| Checkout verification | `app/pay_verify.php` | POST + CSRF. Checks the order belongs to this business, verifies `order_id\|payment_id` HMAC, then asks Razorpay whether the order is `paid` and the payment `captured` **for the stored amount, in INR, on that order** — and only then activates. |
| Webhook | `app/payment_webhook.php` | Reads the **raw body**, verifies `X-Razorpay-Signature` against the separate webhook secret, records the event id **before** acting (so a retry is a no-op), handles `payment.captured` / `order.paid` / `payment.failed`, always answers 2xx once handled. No session, no CSRF — the signature is the authentication. |
| Shared DB logic | `app/includes/payment_flow.php` | Order storage, the **single `created → paid` transition** that makes activation happen at most once, failure marking, webhook event dedupe, trial eligibility. |
| Order helpers | `app/includes/payments.php` (extended) | `rg_order_payload`, `rg_create_order`, `rg_verify_order_signature`, `rg_confirm_order_payment`, `rg_next_expiry`. Reuses the existing signature/request/amount helpers. Nothing removed. |
| UI | `app/card_membership.php` | "Pay ₹X online" beside "Ask on WhatsApp" per paid plan. Razorpay checkout opens with the **server's** order id, amount and key. Closing the window says *"nothing was charged"*. |
| History | `app/card_payments.php` (new) | Last 100 payments for the card: date, plan, amount, status, **test/live**, reference. Read-only, scoped to the owner. |
| Admin | `app/owner_settings.php` | Mode (off/test/live), key id, key secret, webhook secret, timeout. **Secrets are write-only** — the page shows whether one is stored, never the value. Switching to live needs the phrase `I_AUTHORIZE_REAL_CHARGES`. |
| Trial fix | `app/card_reactivate_trial.php`, `app/onboard.php` | One 3-day trial per account, enforced server-side; a paid card is never downgraded. |
| Schema | `sql/migrations/2026-11-payments.sql` + `app/install.php` | `payments`, `webhook_events`, `users.trial_started_at` (+ backfill), payment settings seeded **off**. |

### The exact rules implemented

- **Amount** — `rg_plan_amount_paise($plan)` from the plan row (sale price when lower). The order builder has **no amount parameter at all**, so a browser cannot pass one; the test asserts this by reflection.
- **Activation** — `UPDATE payments SET status='paid' … WHERE id=? AND status<>'paid'`. One row, one transition, one activation. A replayed webhook, a double callback and a re-clicked link all find the row already paid and do nothing.
- **Renewal / expiry** — `rg_next_expiry()` extends from the current expiry when it is in the future, otherwise from now. Paid-through dates are never shortened. **Cancellation is therefore "do nothing"**: access runs to the expiry, no auto-debit, no refund.
- **Trial** — `users.trial_started_at` written under `WHERE trial_started_at IS NULL`; a second attempt gets `rowCount() === 0` and is refused. Cards already on trial count as used (covers pre-migration rows). Paid cards are rejected by `rg_card_is_paid()`.
- **Secrets** — the key secret and webhook secret live in the `settings` table, are never printed back, and never reach the browser. Only `key_id` is public, which is how Razorpay checkout works.

## 2. Migrations (as approved)

```sql
CREATE TABLE payments (…, UNIQUE KEY uniq_payment (razorpay_payment_id),
                           UNIQUE KEY uniq_order (razorpay_order_id), …);
CREATE TABLE webhook_events (…, UNIQUE KEY uniq_event (event_id), …);
ALTER TABLE users ADD COLUMN trial_started_at DATETIME DEFAULT NULL;
UPDATE users SET trial_started_at = … WHERE (already has a card on trial);
INSERT INTO settings … 'payments_mode'='disabled', keys empty, timeout 15;
```
`app/install.php` creates the same tables and seeds the same settings for fresh
installs. Nothing is dropped, renamed or narrowed.

## 3. Tests actually run

| Suite | Command | Result |
|---|---|---|
| **Orders, activation, idempotency, trial** | `node tools/php-run.mjs tests/php/test_orders.php --require app/includes/payments.php --require app/includes/payment_flow.php` | **58 / 58** |
| Existing payment library | `node tools/php-run.mjs tests/php/test_payments.php --require app/includes/payments.php` | **89 / 89** |
| CSRF behaviour | `node tools/php-run.mjs tests/php/test_security.php --require app/includes/security.php` | **24 / 24** |
| Security + payment wiring | `node tools/test-security.mjs` | **116 / 116** |
| **Payment UI (browser)** | `node tools/test-payments-ui.mjs` | **38 / 38** |
| Public review flow | `node tools/test-review-flow.mjs` | **51 / 51** |
| Design-vs-PHP comparison | `node tools/compare-review.mjs` | **56 / 56** |
| Business dashboard | `node tools/test-dashboard-flow.mjs` | **45 / 45** |
| Admin panel | `node tools/test-admin-flow.mjs` | **47 / 47** |
| PHP syntax | `node tools/php-lint.mjs app` | **39 / 39** |
| Class / asset audit | `node tools/audit-dashboard.mjs` | **451 usages, 0 undocumented, 1 documented remote (Razorpay checkout.js)** |

Your Phase 5 checklist, item by item:

| # | Case | Covered by | Result |
|---|---|---|---|
| 1 | Successful payment | orders: full confirm; UI: handler → `pay_verify` | pass |
| 2 | Failed payment | orders: `payment.failed` marking; UI: `payment.failed` handler shows the reason | pass |
| 3 | Cancelled checkout | UI: dismiss → "nothing was charged", no verify call | pass |
| 4 | Invalid signature | orders: wrong/other-order/other-payment/random/empty all refused | pass |
| 5 | Wrong amount | orders: expected ≠ provider amount, and provider order ≠ our amount | pass |
| 6 | Wrong currency | orders: `USD` payment refused | pass |
| 7 | Duplicate webhook | orders: same event id recorded once; activation stays a single transition | pass |
| 8 | Replayed webhook | as above — second delivery is an explicit no-op | pass |
| 9 | Another business's order | wiring: order lookup is scoped by `user_id`; card lookup by `user_id` | pass |
| 10 | Activation and expiry | orders: fresh / expired / stacking / never-shortened (5 assertions) | pass |
| 11 | One-time trial | orders: marker guard, second grant refused, on-trial card counts as used, paid card not downgraded | pass |
| 12 | WhatsApp still works | UI: modal opens, `wa.me` link carries plan name; payment-off page has no pay button | pass |

**What is NOT proven yet: a real Razorpay checkout.** No test key has been supplied
and no live call was ever made. Everything above runs against a stub transport and a
stubbed checkout script, which proves our server logic and our browser wiring — not
Razorpay's side. The moment you send `rzp_test_…` keys, the remaining run is: one ₹1
test order, pay with a Razorpay test card, watch `pay_verify` succeed, watch the
webhook arrive. I will not claim it works end-to-end until that has happened.

## 4. Live mode (when you approve it)

1. Migrations applied on the host (`sql/migrations/2026-11-payments.sql`).
2. Owner panel → Settings → payments: key id `rzp_live_…`, the live key secret, the
   webhook secret; **Save**.
3. Razorpay dashboard → Settings → Webhooks → add `https://<your-domain>/payment_webhook.php`,
   subscribe to `payment.captured`, `payment.failed` and `order.paid`, and put the
   same secret in the form.
4. Set Mode to **Live** and type `I_AUTHORIZE_REAL_CHARGES`.
5. Make one small real payment yourself, refund it from the Razorpay dashboard, and
   confirm the row on the transaction history page says **Live / Paid**.
6. Your keys are **not** in this repository and were never written to a log. They go
   into the database through the admin form, at which point they are masked and
   never rendered again.

## 5. Known limitations

- **One external script**, `https://checkout.razorpay.com/v1/checkout.js`, is loaded
  on the membership page **only when payments are enabled**. Razorpay does not allow
  it to be self-hosted. Every other asset in this app is local; the audit prints this
  exception explicitly rather than hiding it.
- **Orders only.** No auto-recurring mandate, per your decision. A customer renews by
  paying again; their expiry stacks.
- **The webhook needs a public HTTPS URL.** It cannot be exercised from this
  sandbox; step 3 above is the real test.
- **`install.php`** must still be deleted on the host after install.
- **No email receipts** — nothing in this app sends mail yet. The transaction history
  page is the record.
- `.htaccess` and cookie `Secure` remain unverifiable here (no Apache, no HTTPS).

## 6. Files

**New:** `app/pay_create_order.php`, `app/pay_verify.php`, `app/payment_webhook.php`,
`app/card_payments.php`, `app/includes/payment_flow.php`,
`sql/migrations/2026-11-payments.sql`, `tests/php/test_orders.php`,
`tools/test-payments-ui.mjs`, `docs/STAGE-7-PHASE-1-FINDINGS.md`, this file.

**Changed:** `app/includes/payments.php` (order functions added), `app/card_membership.php`,
`app/card_reactivate_trial.php`, `app/onboard.php`, `app/owner_settings.php`,
`app/install.php`, `app/.htaccess` (`/razorpay/webhook/`), `tools/render-page.mjs`,
`tools/test-security.mjs`, `tools/audit-dashboard.mjs`.

**Removed:** nothing.

## 7. Reproduce

```bash
node tools/php-run.mjs tests/php/test_orders.php --require app/includes/payments.php --require app/includes/payment_flow.php
node tools/php-run.mjs tests/php/test_payments.php --require app/includes/payments.php
node tools/test-security.mjs
node tools/render-page.mjs card-membership-pay card-membership card-payments card-payments-empty
node tools/test-payments-ui.mjs
```
