# Stage 6 — Payments and security

**Status: PASSED** · commit `d7e5c32` · tag `stage-6-security`
Baseline for this stage: tag `stage-6-start` (`0c75044`).

Every state-changing request now needs a per-session CSRF token, the session
cookie is hardened, the installer can no longer be replayed, and uploads must be
real images. **The one thing this stage did not do is wire up a card checkout —
because no checkout exists.** See §3.

---

## 1. What was found before any edit

| # | Finding | Severity |
|---|---|---|
| 1 | **`install.php?step=3` takeover.** After a completed install, only steps 1 and 2 were redirected. A stranger could POST to `?step=3` with their own email and password, which inserted a user, rewrote `owner_email` in the database **and** in `config.php`. Full admin takeover of a live site. | **Critical** |
| 2 | **No CSRF anywhere.** All 13 POST endpoints accepted a request from any other site. `card_update_settings.php` (redirects on success), the delete endpoints and the owner endpoints all change or destroy data. | **High** |
| 3 | **Session cookie defaults.** No `HttpOnly`, no `SameSite`, no `Secure`; no `use_strict_mode`. A JavaScript bug (or any injected script) could read the session id. | **High** |
| 4 | **Upload trusted the browser.** The logo upload checked the *extension in the filename* and the size, nothing else. `logo.png` containing PHP is harmless only because the extension is checked — but a `.phtml`/`.phar` variant or an image-parser exploit had no second line of defence, and files landed in a web-served folder with no execution guard. | **Medium** |
| 5 | **Database driver messages shown to visitors.** `signup.php` echoed `$e->getMessage()`; `install.php` step 2 echoed the driver message (host, DB user). | **Medium** |
| 6 | **6-character minimum password**, and no throttling on the login form. | **Medium** |
| 7 | **The GROQ API key was printed into the admin settings page** (fixed in Stage 5, recorded here). | **High** |

Also confirmed *not* broken:

- Every card page scopes its card with `WHERE slug = ? AND user_id = ?`; the three
  JSON endpoints and `card_update_settings.php` / `card_reactivate_trial.php` all
  verify ownership through a join or an explicit `user_id` check. One business
  cannot read or change another's data.
- Passwords are stored with `password_hash()` / verified with `password_verify()`,
  never in plain text, and the session id is regenerated on login.
- `app/config.php` is git-ignored; no secret is in the repository or in the JS.

## 2. What was changed

**New file — `app/includes/security.php`**

- `rg_start_session()` — `HttpOnly` always, `Secure` when the request is HTTPS,
  `SameSite=Lax`, cookie path scoped to the app folder, `use_strict_mode`,
  `use_only_cookies`.
- `csrfToken()` — 64 hex characters from `random_bytes(32)`; an existing value is
  only reused if it is exactly that shape, so a tampered session value is dropped.
- `csrfField()` / `csrfMeta()` — HTML-escaped token for forms and for page scripts.
- `csrfValid()` — compares with `hash_equals()` (constant time). Accepts
  `$_POST['_csrf']`, `$_POST['csrf_token']` or the `X-CSRF-Token` header.
- `requireCsrf()` — 403 and stop; JSON for JSON callers, a readable page otherwise.
- `requirePost()` — 405 for anything that is not a POST.

**Every POST endpoint now checks the token** (13 files): `login.php`, `signup.php`,
`onboard.php`, `review.php` (all three actions: generate / submit / feedback),
`card_update_settings.php`, `card_reactivate_trial.php`, `review_delete.php`,
`feedback_delete.php`, `feedback_mark_read.php`, `owner_cards.php`,
`owner_plans.php`, `owner_settings.php`, `install.php` (steps 2 and 3).

**The token reaches the browser** through: a hidden field in every real `<form>`,
a `<meta name="csrf-token">` in the public, dashboard and admin layouts, and a
`$.ajaxPrefilter` on `review.php` so every existing `$.ajax` call carries it without
touching the calls themselves. The dashboard and admin fetch helpers
(`postForm`, `postJson`, reactivation, settings save) attach it explicitly.

**`install.php` is locked.** Once `config.php` says installed **and** the owner
account exists, every step except the "done" page redirects to `login.php` — the
POST handlers are never reached. The done page now tells the owner to delete the
file, and the deployment checklist repeats it.

**Uploads.** `getimagesize()` must identify the file as JPEG/PNG/GIF/WebP; the
extension is derived from the detected type, never from the submitted name; the
stored name is `logo_{cardId}_{16 hex}.{ext}`; the previous logo is deleted;
`app/assets/uploads/.htaccess` refuses to execute anything in that folder.

**Messages and passwords.** Driver messages are logged (`error_log`) and replaced
with a code-only message for visitors; the minimum password is 8 characters on
signup and in the installer; a login throttle allows 5 wrong guesses per browser
and then a 60-second wait.

## 3. Payments — what was checked, and the honest answer

**The live upgrade path is WhatsApp, and that is what is tested.** A business owner
on the membership page picks a plan, which opens `wa.me/<owner number>` with a
pre-filled message. Nothing is charged in the app, no card data is touched, and no
membership is granted automatically. That path was verified in Stage 4's test suite
(the WhatsApp link carries the plan name and the platform number).

**Razorpay: present as a library, not connected to anything.** `includes/payments.php`
holds a hardened implementation — mode/key-prefix agreement, `hash_hmac` signature
verification for both checkout and webhooks with `hash_equals`, a server-side
"captured" + amount + currency re-check (a valid signature alone is not trusted),
replay protection for webhooks, and a `rg_public_config()` that exposes only the
public `key_id`. It is covered by **89 assertions**, all passing, with an injected
transport so no network call and no charge happens.

But **no page calls it**: `grep` finds zero call sites outside the library. Building
a checkout page, an order table and a webhook endpoint is new functionality, and
your instruction was not to switch providers or change plan rules without approval.
**So it is not wired up, and I am not claiming payments work end-to-end.**
Wiring it is a decision for you (see §7).

**A real payment-related bug found and NOT silently changed:** `card_reactivate_trial.php`
sets `membership = 'trial', expiry_date = NOW() + 3 days` every time it is called,
with no record that a trial was already used. An expired card can therefore be
renewed free, forever. That is a product decision (one trial per card? per user?
never?), so it is reported, not changed.

## 4. Password reset

**There is no password-reset flow in this application** — no `forgot.php`, no
`reset.php`, no token column, no reset mail. There is nothing to review or fix, and
I did not invent one. If you want one, it needs: a token table (hash of the token,
expiry, single use), an email sender, and rate limiting. Say the word and I will
scope it as its own stage.

## 5. Tests actually run

| Test | Command | Result |
|---|---|---|
| CSRF behaviour, real PHP 8.4 | `node tools/php-run.mjs tests/php/test_security.php --require app/includes/security.php` | **24/24** |
| Security wiring (source-level) | `node tools/test-security.mjs` | **73/73** |
| Payment logic | `node tools/php-run.mjs tests/php/test_payments.php --require app/includes/payments.php` | **89 passed, 0 failed** |
| Public review flow (incl. AI + feedback) | `node tools/test-review-flow.mjs` | **51/51** |
| Design-vs-PHP comparison (review page) | `node tools/compare-review.mjs` | **56/56** |
| Business dashboard | `node tools/test-dashboard-flow.mjs` | **45/45** |
| Admin panel | `node tools/test-admin-flow.mjs` | **47/47** |
| PHP syntax | `node tools/php-lint.mjs app` | **34/34** |
| Class / asset audit | `node tools/audit-dashboard.mjs` | **368 usages, 0 undocumented, 0 remote assets** |

What the 24 CSRF assertions cover: token shape, stability within a session, refusal
of missing/empty/wrong/truncated/other-session tokens, acceptance from all three
carriers, no pass without a session, escaping of the printed field, and a fresh
token for a new session.

What the 73 wiring assertions cover: **a `requireCsrf()` call in every one of the 13
POST endpoints** (the list is derived from the files, so a new endpoint is caught
automatically), the token present in all six forms and four layouts, every fetch
helper attaching it, all the cookie flags, the installer lock, the upload checks,
the absence of secrets in output, ownership scoping on eleven queries, and the
absence of driver messages.

The review-flow suite passing is the important one: it drives the real public page
in a browser, so it proves the customer can still generate a review, submit it and
send private feedback **with** the token in place.

## 6. Known issues still open

1. **Razorpay is not wired** (§3). No checkout, no order table, no webhook endpoint.
2. **One free trial per card is not enforced** (§3) — needs a product decision.
3. **Login throttling is per-session**, not per-account or per-IP. It slows a single
   browser down; it does not stop a distributed attempt. A proper fix needs a login
   table or a server/WAF rule.
4. **No password reset** (§4).
5. **`logout.php` is a GET link.** A third-party page could log a user out. Low
   impact (no data change), left as-is to keep the navigation behaviour identical.
6. **`isOwner()` is still a single-email lookup** — one admin account only.
7. **`install.php` must be deleted on Hostinger after install.** The lock is
   server-side, but deleting the file is still better.
8. **`.htaccess` and cookie `Secure` cannot be exercised in this workspace** (no
   Apache, no HTTPS). Verify both on the live host — they are in the checklist.

## 7. Decision needed from you

**Do you want the Razorpay checkout wired up?** If yes, that is its own stage:
an orders table, a create-order endpoint (server-side amount from the plan row), the
checkout hand-off, a signature-verified webhook endpoint, idempotency keys, and a
test-mode run before live. I would want your approval on the plan/pricing rules and
on what happens to a card when a subscription is cancelled.

## 8. Reproduce

```bash
node tools/php-run.mjs tests/php/test_security.php --require app/includes/security.php
node tools/php-run.mjs tests/php/test_payments.php --require app/includes/payments.php
node tools/test-security.mjs
node tools/test-review-flow.mjs && node tools/test-dashboard-flow.mjs && node tools/test-admin-flow.mjs
node tools/php-lint.mjs app
```
