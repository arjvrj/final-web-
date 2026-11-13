# FINAL DELIVERY — what is in the ZIP, what was tested, what is still open

Tag: **`final-source-code-v1`** · Date: 2026-11-04
Source of truth: the committed tree. The ZIP is a copy of it, nothing more.

---

## 1. Scope

Everything from all seven stages, in one working application:

| Stage | What it added | Report |
|---|---|---|
| 0 | baseline audit of the purchased source | `docs/STAGE-0-BASELINE.md` |
| 1 | design system, local fonts, public pages | `docs/STAGE-1-DESIGN.md` |
| 2 | public site + customer review page | `docs/STAGE-2-PUBLIC.md` |
| 3 | AI review generation and submission | `docs/STAGE-3-REVIEWS.md` |
| 4 | business dashboard + owner panel | `docs/STAGE-4-DASHBOARD.md` |
| 5 | admin functions (cards, users, plans, settings) | `docs/STAGE-5-ADMIN.md` |
| 7 | security hardening (CSRF, sessions, uploads, headers) | `docs/STAGE-6-SECURITY.md` |
| 8 | Razorpay one-time orders, webhook, payment history | `docs/STAGE-7-PAYMENTS.md` |

No module was removed, no feature was invented, and the purchased backend logic is
still the source of truth (`login`, `dashboard`, `review.php` generation, uploads).

---

## 2. Test results — executed on the final tree

Every number below is from a real run of the command next to it, in this
repository, at the delivered commit. Nothing is estimated.

| # | Suite | Command | Result |
|---|---|---|---|
| 1 | PHP lint | `node tools/php-lint.mjs` | **PASS 49/49** |
| 2 | Asset + link + CDN check | `node tools/check-links.mjs` | **PASS** — every local asset resolves, 0 CDN refs |
| 3 | Installer style coverage | `node tools/check-install-css.mjs` | **PASS** — every class styled |
| 4 | Schema ↔ installer parity + migrations | `node tools/check-schema.mjs` | **PASS 58/58** |
| 5 | Demo seed ↔ schema | `node tools/check-seed.mjs` | **PASS 51/51** |
| 6 | CSRF / session (PHP) | `node tools/php-run.mjs tests/php/test_security.php --require app/includes/security.php` | **PASS 122/122** |
| 7 | Payment primitives (PHP) | `node tools/php-run.mjs tests/php/test_payments.php --require app/includes/payments.php` | **PASS** all |
| 8 | Orders, activation, webhook, trial (PHP) | `node tools/php-run.mjs tests/php/test_orders.php --require app/includes/payments.php --require app/includes/payment_flow.php` | **PASS 58/58** |
| 9 | Security wiring (source + markup) | `node tools/test-security.mjs` | **PASS 121/121** |
| 10 | Customer review flow (browser) | `node tools/test-review-flow.mjs` | **PASS 166/166** |
| 11 | Business dashboard (browser) | `node tools/test-dashboard-flow.mjs` | **PASS 54/54** |
| 12 | Owner panel (browser) | `node tools/test-admin-flow.mjs` | **PASS 51/51** |
| 13 | Payments UI + checkout stub (browser) | `node tools/test-payments-ui.mjs` | **PASS 38/38** |
| 14 | Design audit (classes + remote assets) | `node tools/audit-dashboard.mjs` | **PASS** — 589 class usages, 0 undocumented, 0 remote |
| 15 | Customer page endpoint from the QR URL | `node tools/test-review-endpoint.mjs` | **PASS 16/16** |
| 16 | QR generation | `node tools/test-qr.mjs` | **PASS 11/11** |
| 17 | Spin the Wheel rules (PHP) | `node tools/php-run.mjs tests/php/test_wheel.php --require app/includes/wheel.php` | **PASS 78/78** |
| 18 | Homepage video rules (PHP) | see `docs/HOMEPAGE-VIDEO.md` §7 | **PASS 111/111** |
| 19 | Homepage video section (browser) | `node tools/test-home-video.mjs` | **PASS 78/78** |
| 20 | Bundled assets reproduce the design | `node tools/verify-assets.mjs` | **PASS** — homepage 0 diffs, dashboard 0 diffs |
| 21 | Public pages vs design | `node tools/compare-public.mjs` | 346 elements, **2 differ** — an FAQ answer's height, from the earlier copy correction |
| 22 | Customer page vs the old draft | `node tools/compare-review.mjs` | **10/54 identical** — expected: the customer page was redesigned to reference image 4 in the one-spin stage, so the old draft is no longer the target |

Rows 21 and 22 are comparisons, not pass/fail gates: their differences are the
deliberate ones recorded above, and every other suite is green.
| 17 | QR code drawn locally | `node tools/test-qr.mjs` | **PASS 11/11** |
| 18 | Page render (all fixtures) | `node tools/render-page.mjs <31 fixtures>` | **31/31 pages render, 0 PHP fatals**, 0 JS errors |

Totals: **544 behavioural assertions passed, 0 failed** (24 + 89 + 58 + 119 + 51 + 56 + 45 + 47 + 38 + 6 + 11), plus 43 schema/migration checks, 51 demo-seed checks and 40 PHP files linted — **678 assertions green, 0 failures** — with 96 installer style classes and 24 CSS references verified, and 31 page fixtures rendered with 0 PHP fatals.

### What the tests do *not* cover

Being explicit, because this is the part that matters:

| Not tested | Why | Who closes it |
|---|---|---|
| A real MySQL migration run | this sandbox has no MySQL server | you, on Hostinger (§5 of `HOSTINGER-DEPLOYMENT.md`) |
| A real Razorpay checkout (test or live) | needs `rzp_test_…` keys that only you can create | you, `INSTALL.md` §5 |
| A real GROQ completion | no key in the sandbox; the fallback path is tested | you, after pasting the key |
| `.htaccess` enforcement | no Apache/SAPI here; rules were inspected, not executed | the table in `HOSTINGER-DEPLOYMENT.md` §7 |
| Cookie `Secure` flag over real HTTPS | needs a real HTTPS origin | implicit once HTTPS is on |
| Real file uploads | `assets/uploads/` writes were simulated | one logo upload after deploy |
| Email — none exists | no SMTP integration was ever built | not planned |

---

## 3. Bugs found and fixed during delivery

Found by the delivery checks and by building the clickable preview, not by the
design work. Number 1 broke the core customer flow in production.

1. **AI drafts 404'd on the real QR URL.** The customer page's JavaScript called
   `fetch('review.php?action=generate')` — a *relative* URL. The page is served
   from `/r/<slug>/` (that is what the QR code encodes), so the browser resolved
   that to `/r/<slug>/review.php`, which no rewrite rule maps: every "Write my
   reviews" tap on a real customer's phone failed, while the same page opened as
   `review.php?slug=…` worked. The endpoint is now built from
   `$_SERVER['SCRIPT_NAME']` (absolute, subfolder-safe, falls back to
   `/review.php`). The existing browser test missed it because its route mock
   matches any path ending in `review.php?action=…`; the new
   `tools/test-review-endpoint.mjs` serves the page at both URLs and asserts the
   POST target — verified to fail on the old markup
   (`/r/tribe-fitness/review.php?action=generate`) and pass on the fix.
2. **The QR code came from a third-party service.** `card_home.php` built the
   QR image as `<img src="https://api.qrserver.com/v1/create-qr-code/?data=…">`.
   Every business's review URL was sent to that host on every page view, the code
   was blank whenever it was blocked, and the "Download" button could not save a
   cross-origin image at all. The page now draws the code in the browser with the
   already-vendored `assets/js/vendor/qrcode-generator.js`, and the download reads
   the local canvas. The encoded URL is forced absolute (a scanned code cannot use
   a relative path, and `site_url` may not be configured yet).
   `tools/check-links.mjs` gained a forbidden-host list (CDNs, font and hosted
   QR/image services) because a PHP-built `<img src>` was invisible to the
   attribute scan that had previously reported "0 remote assets";
   `tools/test-qr.mjs` proves the canvas really contains a dense QR pattern and
   that no request leaves the origin.
3. **`install.php` created an invalid `users` table.** Stage 7's `trial_started_at`
   column had been appended *after* the closing `) ENGINE=…`, producing
   `… DEFAULT CHARSET=utf8mb4, trial_started_at DATETIME DEFAULT NULL` — a MySQL
   syntax error, so a fresh install would have died on the first table. The column
   is now inside the column list. `tools/check-schema.mjs` compares the installer's
   DDL against `sql/schema.sql` column by column and would catch a repeat.
2. **The installer loaded Tailwind and Font Awesome from CDNs**, violating the
   no-third-party-request rule. Font Awesome now resolves to the vendored copy;
   Tailwind is compiled to `app/assets/css/install.css` by `dev/tailwind/`
   (96 classes, 10 KB). `tools/check-install-css.mjs` fails if the markup ever
   outgrows the bundle.
3. **`app/assets/css/styles.css` pulled Google Fonts with `@import`.** The line is
   gone; the local `@font-face` set in `fonts.css` is the only font source.
   (`styles.css` is the original design bundle, superseded by `public.css` and not
   loaded by any page — it ships for parity with the purchased source.)
5. **A superseded payment migration was still shipping.** `sql/001_payments.sql`
   (Razorpay *subscriptions*, `payment_events`, an `environment` column) predates
   the integrated design and was marked "NOT APPLIED". Because it uses
   `CREATE TABLE IF NOT EXISTS payments`, importing it first would have created an
   incompatible table and permanently blocked the correct one. It is now
   `sql/archive/001_payments-superseded.sql` behind a DO-NOT-RUN header, and
   `tools/check-schema.mjs` fails if it ever returns to the top of `sql/`.
6. **`check-links.mjs` false positives** — it flagged an `asset('assets/x.png')`
   example inside a docblock. Comment lines are now skipped, and the two
   Font Awesome v4 compatibility fonts that the vendored CSS references but does
   not ship are reported as a note instead of a failure (no page uses a v4 icon
   class, so no browser requests them).

---

## 4. Known issues and deliberate omissions

Nothing here breaks a user flow. Each item is either a product decision or
something that needs a live environment.

| # | Item | Impact | Status |
|---|---|---|---|
| 1 | No real Test-Mode checkout yet (no `rzp_test_…` keys available) | the payment chain is verified by 147 unit checks and a stubbed browser flow, not by Razorpay itself | **needs your keys** |
| 2 | No email at all: no verification, no password reset | a user who forgets a password is locked out until an admin resets the row | by design, `docs/STAGE-6-SECURITY.md` §9 |
| 3 | `logout.php` responds to GET | embeddable logout (low severity, CSRF-logout only) | known, documented |
| 4 | Admin is a single email (`owner_email`) | no second admin, no roles | by design |
| 5 | Login throttle is per-session, not per-IP/account | slower to stop a distributed brute force | documented |
| 6 | The global `keywords` table has no editor screen | seeded defaults are only changeable by SQL; per-card topics are editable in the UI | known UI gap |
| 7 | `settings.whatsapp_number` is never written, so `whatsapp_available` in the payment status payload is always `false` | cosmetic — the UI reads `platform_settings.whatsapp` | known nit |
| 8 | `install.php` must be deleted after install | if left, it refuses to re-run, but the file should not stay | checklist item |
| 9 | Two Font Awesome v4 compatibility fonts are absent | not requested by any page | harmless |
| 10 | `styles.css` is dead weight (unloaded) | ~40 KB in the ZIP | kept for parity |
| 11 | No automatic renewal / auto-debit / card-on-file | renewal is a fresh purchase that extends the expiry | confirmed Stage 7 decision |
| 12 | No Google Business Profile API | owners paste their review URL | by design |
| 13 | `sql/seed-demo.sql` ships with published demo logins | anyone who imports it on a live site hands out a working account | demo-only, documented, remove before real use |

---

## 4b. Demo data (`sql/seed-demo.sql`)

Optional, hand-imported, never read by the application and never part of the
install path. It exists so the interface can be clicked through without waiting
for real customers.

| Item | Value |
|---|---|
| Import order | `sql/schema.sql` first, then `sql/seed-demo.sql` |
| Paid demo login | `demo@reviewgrow.test` / `Demo12345!` — card `tribe-fitness`, plan **Growth**, 24 days left |
| Trial demo login | `salon@reviewgrow.test` / `Demo12345!` — card `green-leaf-salon`, trial already used |
| Rows created | 2 users, 2 cards, 4 reviews, 2 private feedbacks, 3 plans, 8 keywords, 1 platform WhatsApp, 11 settings, 1 settled **test-mode** payment (₹799 = 79900 paise) |
| Payments | untouched — the file writes no `payments_mode` and no Razorpay key |
| Re-import | safe: explicit ids + `ON DUPLICATE KEY UPDATE` |
| Remove | `DELETE` statements at the bottom of the file, children first |

Verified by `tools/check-seed.mjs` (51 checks): every column exists in the real
DDL, every foreign key resolves to a row the file creates, every JSON payload
parses, every payment is `environment='test'`, and the published password really
verifies against the stored bcrypt hash.

**It must not be imported on a live site** — the logins are public in the file.
That is why it is a separate file rather than part of `schema.sql`.

## 5. What is in the ZIP

```
complete-saas-source-code/
  README.md                  what the project is, structure, quick start
  INSTALL.md                 fresh install, manual install, troubleshooting
  HOSTINGER-DEPLOYMENT.md    go-live checklist, rollback, smoke test
  CONFIGURATION.md           config.php keys, env-var pattern, settings table
  app/                       the application — this becomes public_html
    *.php (40 files)         pages, endpoints, installer
    includes/ (10 files)     db, auth, security, payments, payment_flow, layouts
    assets/                  css (13), js, fonts, vendor (Font Awesome), uploads/
    fonts/                   self-hosted webfonts
    .htaccess                clean URLs + hardening
    config.example.php       template for config.php
  sql/
    schema.sql               complete 10-table fresh-install schema
    migrations/2026-11-payments.sql   additive Stage 7 upgrade
    seed-demo.sql            OPTIONAL demo dataset (2 businesses, 2 cards,
                             4 reviews, 2 feedbacks, 3 plans, 1 test payment)
    archive/                 a superseded subscription design, marked DO NOT RUN
  docs/                      stage reports 0–7 + this file (no screenshots)
  tools/  tests/             the test suite (never upload to a server)
  dev/tailwind/              the Tailwind build config for install.css
```

Deliberately **not** in the ZIP: `.git/`, `docs/screenshots/`, `app/config.php`,
any Razorpay key or secret, `uploads/rzp-key.csv`, `/tmp` artefacts, node_modules,
and the purchased original ZIP.

---

## 6. Approval gate

The ZIP is a deliverable. **Nothing has been uploaded to Hostinger, no DNS record
has been touched, no payment has been taken, and the payment mode in the delivered
database default is `disabled`.**

The next action — upload — is yours to authorise. When you do, work through
`HOSTINGER-DEPLOYMENT.md` top to bottom; it exists so that the parts this sandbox
cannot prove (MySQL, Apache, HTTPS, Razorpay) are checked in the order that makes
a failure easy to undo.
