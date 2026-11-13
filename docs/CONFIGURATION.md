# CONFIGURATION — every knob, where it lives, and what it does

There are **two** configuration surfaces and no third:

| Surface | File / table | Holds | Who can change it |
|---|---|---|---|
| 1. Boot config | `app/config.php` (a PHP array) | database credentials, site URL, app name, owner email, installed flag | you, on the server |
| 2. Settings | `settings` table, key/value | everything a non-developer changes: branding, AI key, payment mode and keys, WhatsApp | site owner, in the browser |

There is **no `.env` file and the application reads no environment variables.**
If your host forbids storing credentials in files, §2 shows how to make
`config.php` read them from the environment instead — that is a documented
pattern, not code that ships enabled.

---

## 1. `app/config.php`

Created by `install.php` step 2. `app/config.example.php` is the annotated
template. `.htaccess` denies both from the browser; `config.php` is gitignored.

```php
return [
  'db_host'     => 'localhost',
  'db_name'     => 'u123456789_reviewgrow',
  'db_user'     => 'u123456789_owner',
  'db_pass'     => '…',                    // the only real secret in this file
  'site_url'    => 'https://example.com',  // no trailing slash
  'app_name'    => 'ReviewAI',
  'owner_email' => 'you@example.com',      // must match an existing users row
  'installed'   => true,                   // false = the app refuses to boot
];
```

| Key | Required | Notes |
|---|---|---|
| `db_host` | yes | `localhost` on Hostinger. A remote MySQL host needs the IP whitelisted in hPanel. |
| `db_name` | yes | with the host's prefix |
| `db_user` | yes | the user must be attached to that database |
| `db_pass` | yes | empty is allowed only for a password-less local MySQL |
| `site_url` | yes | absolute, `https://`, no trailing slash. Used for QR links, redirects and the Razorpay callback. |
| `app_name` | no | display fallback; the live value comes from the `app_name` setting |
| `owner_email` | yes | written by installer step 3 and mirrored to the `owner_email` setting. **The setting is what the code reads** — keep them equal. |
| `installed` | yes | `false` makes every page send you to `install.php`. |

Changing `db_pass`: edit the file, save, reload. There is no cache.

**Ownership:** `chmod 600 app/config.php`. If the file is 644 and the host runs
PHP as your user, it is still only readable by you — but 600 costs nothing.

---

## 2. Environment variables (optional pattern)

If you must keep secrets out of the filesystem, replace the array values with
`getenv()` calls. Nothing else in the code changes:

```php
return [
  'db_host'     => getenv('RG_DB_HOST') ?: 'localhost',
  'db_name'     => getenv('RG_DB_NAME') ?: '',
  'db_user'     => getenv('RG_DB_USER') ?: '',
  'db_pass'     => getenv('RG_DB_PASS') ?: '',
  'site_url'    => getenv('RG_SITE_URL') ?: 'https://example.com',
  'app_name'    => getenv('RG_APP_NAME') ?: 'ReviewAI',
  'owner_email' => getenv('RG_OWNER_EMAIL') ?: '',
  'installed'   => true,
];
```

Then set the variables outside the web root. How you set them depends on the
host:

| Host type | Where |
|---|---|
| Apache / LiteSpeed with `.htaccess` allowed | `SetEnv RG_DB_PASS "…"` in `.htaccess` — **outside** `public_html` if the host supports it, and never commit that file |
| PHP-FPM pool you control | `env[RG_DB_PASS] = …` in the pool config, then `clear_env = no` |
| cPanel / hPanel shared | usually not available. Use the file, or ask support to set a pool env |
| local dev | `export RG_DB_PASS=…` before `php -S` |

Note that on shared hosting `SetEnv` values are visible to other PHP code running
as you and readable by anyone who can read `.htaccess`, so it is not a strict
improvement over a 600 `config.php`.

**The Razorpay secrets are not configurable this way.** They belong in the
`settings` table, set through the owner panel, write-only. That is deliberate:
they must be rotatable without touching the server, and reading them from the
environment would tempt someone to `echo` them into a debug page.

---

## 3. The `settings` table

One row per key. Read with `getSetting('key', $default)` — cached per request,
returns the default when the row or the table is missing, so a half-installed
site does not fatal. Written with `setSetting('key', $value)` (UPSERT), which is
what the owner panel uses.

### Branding and identity

| Key | Default | Used by |
|---|---|---|
| `app_name` | the site name you entered | page titles, header, emails-if-any |
| `business_name` | same | default business name for a **new** review card (each card then stores its own in `review_cards.details`) |
| `business_description` | "We provide excellent service!" | default card description |
| `business_logo` | `''` | path under `assets/uploads/` |
| `business_banner` | `''` | ditto |
| `business_address` | `''` | card footer, WhatsApp message |
| `business_phone` | `''` | card footer |
| `business_whatsapp` | `''` | fallback for the card's "message us on WhatsApp" button when `details.whatsapp` is empty |
| `business_facebook` | `''` | footer social links — hidden when empty |
| `business_instagram` | `''` | ditto |
| `business_website` | `''` | ditto |
| `google_review_url` | a Google search URL | default target of "review us on Google" for a new card |
| `theme` | `purple` | colour preset name |
| `custom_primary` | `#7c3aed` | primary colour when theme = custom |
| `custom_gradient` | purple→indigo | hero gradient |
| `enable_keywords` | `1` | show the keyword chips on the customer page |
| `site_url` | from config | absolute URLs in QR codes and redirects |
| `owner_email` | `''` | **the admin check.** Only this account opens `owner.php` |
| `installed` | `1` | mirror of the config flag |

### AI

| Key | Default | Notes |
|---|---|---|
| `groq_api_key` | `''` | empty ⇒ template drafts, no error. Never printed back to a page. |
| `groq_model` | `llama-3.3-70b-versatile` | any chat model your GROQ plan serves |

### Payments

| Key | Default | Notes |
|---|---|---|
| `payments_mode` | `disabled` | `disabled` · `test` · `live`. Anything else is treated as `disabled`. |
| `razorpay_key_id` | `''` | `rzp_test_…` or `rzp_live_…`; shown in the owner panel because the browser needs it |
| `razorpay_key_secret` | `''` | **write-only.** Never rendered. Sent to the server only. |
| `razorpay_webhook_secret` | `''` | **write-only.** Verifies `X-Razorpay-Signature` on the webhook. |
| `razorpay_live_confirm` | `''` | must equal `I_AUTHORIZE_REAL_CHARGES` before `live` saves |
| `razorpay_timeout` | `15` | cURL timeout, clamped 2–30 s |

Mode rules, as implemented in `includes/payments.php`:

- `disabled` → no Pay button anywhere, no checkout script loaded, `/pay_*` returns 404-ish JSON, the webhook rejects everything.
- `test` → keys must start `rzp_test_`; the checkout script is served locally and only on pages that need it.
- `live` → keys must start `rzp_live_`. Switching to live requires typing `I_AUTHORIZE_REAL_CHARGES` in the owner panel; the phrase is checked when the mode is saved and is never stored. A key prefix that does not match the mode makes the whole payment surface inert instead of half-working.

### Platform WhatsApp

The number customers are sent to when they choose the WhatsApp route is stored in
its own single-row table, **`platform_settings.whatsapp`** — not in `settings`.
It is the required field on **Owner panel → Settings** and is read by
`card_membership.php` (the upgrade button) and by the dashboard footer.

`settings.whatsapp_number` is read by `rg_payment_config()` and exposed as
`whatsapp_available` in the payment status payload. Nothing in the interface
writes that key, so it stays empty and the flag reports `false`; the interface
uses `platform_settings.whatsapp` and is unaffected. See the known-issues list in
`docs/FINAL-DELIVERY.md`.

---

## 4. Business data (not settings)

| Table | What it holds | Who edits it |
|---|---|---|
| `plans` | name, price (₹), duration (days), sort order, active flag | Owner panel → Plans |
| `keywords` | the vocabulary offered on the customer page | Owner panel → Keywords |
| `users` | business accounts (email, bcrypt password hash, `trial_started_at`) | signup + owner panel |
| `review_cards` | one row per QR code: slug, name, details JSON (description, logo, Google URL), membership, expiry | business dashboard |
| `reviews` | generated + submitted review text, star rating, timestamps | customer flow |
| `feedbacks` | private 1–3 star complaints | customer flow |
| `payments` | order id, payment id, plan, amount (paise), status, mode, channel | server only |
| `webhook_events` | raw Razorpay payloads, unique per `event_id` | server only |

Business-level settings (a card's own business name, phone, colours) live in
`review_cards.details` as JSON so each card can differ; the `settings`-table
values above are the defaults a new card inherits.

---

## 5. Things that are constants, not settings

Changing these means editing code, on purpose:

| Value | Where | Why not a setting |
|---|---|---|
| `RG_TRIAL_DAYS = 3` | `includes/payment_flow.php` | trial length is a product decision; one trial per business account |
| price is computed server-side from `plans` | `includes/payments.php` | the browser can never send an amount |
| signature = HMAC-SHA256(`order_id|payment_id`, key secret) | `includes/payments.php` | — |
| webhook events handled: `payment.captured`, `payment.failed`, `order.paid` | `payment_webhook.php` | anything else is acknowledged and ignored |
| password hashing | `password_hash(PASSWORD_DEFAULT)` | bcrypt/argon per PHP version |
| session cookie: `HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS | `includes/security.php` | — |

---

## 6. Verifying a config change

There is no cache to clear and no restart. After any change:

```bash
# syntax of the file you edited
php -l app/config.php

# the whole app
php -l app/owner_settings.php
```

Then in the browser, hit `/owner.php` (reads settings), `/dashboard/` (reads
`site_url`), and one customer page (`/r/<slug>/`) — if all three render, the
settings table is being read correctly. If the app suddenly redirects to
`install.php`, `installed` is false or `config.php` is unreadable.
