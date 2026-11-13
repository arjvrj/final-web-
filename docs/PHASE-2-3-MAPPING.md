# PHASE 2 & 3 — UI/UX Analysis + Backend Mapping

**Design source of truth:** the ReviewGrow UI in `~/ReviewGrow-UI-Integration`, which itself
matches `/home/user/uploads/Review AI SaaS Product Showcase.png`. Copied into
`app/assets/css/` so the PHP app renders *identically* — not "similar".

## The design system, extracted exactly

```css
--rg-forest:#173c2d;      /* primary brand */
--rg-forest-hover:#245640;
--rg-ink:#172c22;         /* body text */
--rg-muted:#5c6d63;       /* secondary text */
--rg-ivory:#f7f8f4;       /* page background */
--rg-mint:#e6f1e2;        /* soft accents */
--rg-border:#dfe7dd;
--rg-radius:10px;
--rg-font:Inter, ui-sans-serif, system-ui, …;
--rg-shadow:0 12px 35px rgba(24,58,43,.06);
```

Buttons: `.button` base, `.button-dark` (forest fill), `.button-outline`, `.button-small`.
Focus ring `3px #8ab686`. Kickers use letter-spacing 1.3px, headings -2px to -3.3px.
**These exact classes are reused**, so no screen can drift from the brand.

---

## Page-by-page mapping

Every new screen keeps the ReviewGrow layout and posts to an **existing** PHP endpoint.
No endpoint is renamed, no field name changes, nothing deleted.

### Public / customer

| ReviewGrow screen | New file | Backs onto (unchanged) | Fields | Status |
|---|---|---|---|---|
| Landing page | `app/index.php` *(currently 580 B)* | none — static | — | 🔨 to build |
| Customer review page | `app/review/index.php` | `review.php` | `slug`, `rating`, `keywords[]` | 🔨 to build |
| AI drafts | *(same page)* | `review.php?action=generate` | → `{reviews[], google_review_url}` | ✅ endpoint works |
| Submit review | *(same page)* | `review.php?action=submit` | `slug, rating, body, customer_name` | ✅ endpoint works |
| Private feedback | *(same page)* | `review.php?action=feedback` | `slug, rating, message, name, phone, email` | ✅ endpoint works |
| Login | `app/login.php` | itself | `email, password` | 🔨 restyle only |
| Signup | `app/signup.php` | itself | `email, password, password_confirm` | 🔨 restyle only |
| Forgot password | `app/forgot-password.php` | itself | `email`, `token` | 🔨 restyle **+ security fix** |

### Business owner (customer of yours)

| ReviewGrow screen | New file | Backs onto | Status |
|---|---|---|---|
| Dashboard w/ stats | `app/card_home.php` | `review_cards`, `reviews`, `feedbacks` | 🔨 restyle |
| Onboarding (create card) | `app/onboard.php` | `review_cards` insert | 🔨 restyle |
| My cards list | `app/list.php` | `review_cards` | 🔨 restyle |
| Reviews + feedback inbox | `app/card_reviews.php` | `reviews`, `feedbacks` | 🔨 restyle |
| Card settings | `app/card_settings.php` | `review_cards.details` JSON | 🔨 restyle |
| **QR / share** | `app/card_home.php` section | **rebuilt** — local QR, downloadable | 🔧 fix (currently a 3rd-party `<img>`) |
| **Membership / plans** | `app/card_membership.php` | `plans` + **new** `payments` | 🔧 mixed Razorpay + WhatsApp |
| Trial reactivation | `app/card_reactivate_trial.php` | `review_cards` | 🔨 restyle |

### Super admin (you)

| ReviewGrow screen | New file | Backs onto | Status |
|---|---|---|---|
| Owner dashboard | `app/owner.php` | all cards | 🔨 restyle |
| All customer cards | `app/owner_cards.php` | `review_cards` | 🔨 restyle |
| Plans & pricing | `app/owner_plans.php` | `plans` (+ new `razorpay_plan_*`) | 🔧 add plan-id fields |
| Settings (app, colours, AI key) | `app/owner_settings.php` | `settings` | 🔧 add payment settings |
| **Payments ledger** | `app/owner_payments.php` | **new** `payments` | 🆕 to build |
| **Confirm a WhatsApp payment** | `app/owner_payments.php` | **new** `payments` | 🆕 to build |

🆕 = genuinely new, because the purchased code has no payments at all.

---

## The contract that must not change

The whole integration surface is these fields. The new UI may restyle freely but must
send exactly these names:

| Endpoint | Method | Sends | Returns |
|---|---|---|---|
| `login.php` | POST | `email`, `password` | session + redirect |
| `signup.php` | POST | `email`, `password`, `password_confirm` | session |
| `review.php?slug=` | GET | — | HTML |
| `review.php?action=generate` | POST | `slug`, `rating`, `keywords[]` | `{reviews[], google_review_url}` |
| `review.php?action=submit` | POST | `slug`, `rating`, `body`, `customer_name` | `{ok}` |
| `review.php?action=feedback` | POST | `slug`, `rating`, `message`, `name`, `phone`, `email` | `{ok}` |
| `card_update_settings.php` | POST | card fields incl. `google_review_url` | redirect |
| `review_delete.php` | POST | `id` | redirect |
| `feedback_delete.php` | POST | `id` | redirect |
| `owner_plans.php?action=save` | POST | `name, description, price, sale_price, features, cta, duration` | redirect |

**New endpoints** (added, nothing replaced):

| Endpoint | Method | Purpose |
|---|---|---|
| `api/payments/config.php` | GET | public-safe payment state for the UI |
| `api/payments/subscribe.php` | POST | create a Razorpay subscription |
| `api/payments/verify.php` | POST | verify signature + provider + amount, record, activate |
| `api/payments/webhook.php` | POST | Razorpay webhook (signature over raw bytes) |
| `api/payments/whatsapp.php` | POST | create a PENDING manual request |

---

## Two channels, one truth

```
Customer clicks Upgrade
        │
        ├── Card payment chosen ──► Razorpay subscription
        │        signature ──► provider says captured ──► amount matches
        │        └──► payments row (channel=razorpay, environment=test|live)
        │                 └──► membership expires_at extended
        │
        └── WhatsApp chosen ─────► payments row (channel=whatsapp, status=PENDING)
                 │                  membership NOT extended
                 └──► owner sees it in owner_payments.php, confirms receipt
                          └──► owner marks paid ──► membership extended by hand
```

**Rule:** a WhatsApp request never grants access by itself. Only a verified Razorpay
payment or an explicit owner confirmation does. That is enforced in
`rg_whatsapp_request_row()` which hardcodes `status => 'pending'`.

---

## Build order (so nothing is ever half-broken)

1. Security fixes (install.php, forgot-password, key echo, redirect) — backend only, no UI impact
2. `sql/001_payments.sql` applied by you
3. Design system dropped in (done — CSS copied)
4. Customer review page (highest value, public-facing)
5. Login / signup / forgot-password
6. Owner dashboard + cards
7. Membership page with the two channels
8. Super admin + payments ledger
9. Landing page last (it's just marketing)
