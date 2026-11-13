# ReviewGrow — Page-by-Page Integration Plan

**Status: inspection only. No file has been modified.** Every number below comes from reading the
actual files, not from documentation or assumption.

- PHP app: `/home/user/reviewgrow-php/app/` — 29 files, 928 KB, 8 database tables
- Original design: `/home/user/ReviewGrow-UI-Integration/` — 191 files, 5.1 MB
- Git checkpoint: `dbb86ac` (clean tree, safe rollback point)

---

## 1. The one decision that shapes everything

Your design is **not a set of pages**. It is a JavaScript application: every page is a near-empty
shell that fills itself in from Supabase at runtime.

```html
<!-- login/index.html — all 951 bytes of it -->
<main class="shell" id="app"><div class="card loading">Loading your account…</div></main>
<script defer src="/app.js"></script>
```

The PHP app is the opposite: each page arrives from the server already finished.

So there is no way to "swap the frontend" by copying files. The integration is a **markup
transplant**, in three parts:

| Layer | What happens to it |
|---|---|
| **CSS** | Copied verbatim. This is your visual identity — colours, radii, shadows, spacing. Untouched. |
| **Markup** | Extracted from the design's render functions and moved into PHP templates, with PHP values printed where the JS used to print Supabase values. |
| **JavaScript (data layer)** | Discarded. Supabase client, auth tokens, and the Workers fetch calls are replaced by PHP forms and PHP JSON endpoints. |

Your UI stays yours. What changes is *where the data comes from*.

**Good news:** your design also ships three self-contained reference files that already contain the
finished markup, so nothing has to be re-invented by eye:

| Reference file | Size | What it gives us |
|---|---|---|
| `Review-AI-Reference-Homepage.html` | 38 KB | Complete homepage markup + 25 KB of inline CSS |
| `Review-AI-Reference-Customer.html` | 366 KB | Customer review flow, all states + 8.4 KB CSS |
| `Review-AI-Reference-Dashboard.html` | 695 KB | Every dashboard panel + 35 KB CSS |

Those three files are the pixel source of truth for this work.

---

## 2. The two projects side by side

### PHP app — what exists

| Zone | Files | Runs on |
|---|---|---|
| Public | `index.php` → `home.php`, `login.php`, `signup.php`, `onboard.php` | PHP session |
| Customer | `review.php` (958 lines) | no login needed |
| Business owner | `list.php`, `card_home.php`, `card_reviews.php`, `card_settings.php`, `card_membership.php` | `requireLogin()` |
| Super admin | `owner.php`, `owner_cards.php`, `owner_plans.php`, `owner_settings.php` | `requireOwner()` |
| Endpoints (no UI) | `card_update_settings.php`, `card_reactivate_trial.php`, `feedback_mark_read.php`, `feedback_delete.php`, `review_delete.php`, `logout.php` | POST only |
| Shared | `includes/db.php`, `includes/auth.php`, `includes/functions.php` (25 functions), `includes/payments.php` (tested, 89/89) | — |
| Setup | `install.php` (creates all 8 tables) | must be deleted after install |

### Design — what exists

| Design file | Size | Renders |
|---|---|---|
| `index.html` + `home-redesign.css` + `reference-home.css` | 8.5 KB + 18.6 KB + 6.6 KB | Homepage |
| `login/` `register/` `onboarding/` (same 951 B shell) + `app.css` + `design-system.css` + `app.js` | 17.6 KB JS | All auth screens |
| `dashboard/` shell + `dashboard.css` + `dashboard-redesign.css` + `dashboard.js` + `dashboard-overview.js` + `production-qr.js` | 27.7 KB JS + 18 KB CSS | Business dashboard, QR, settings |
| `review/` shell + `review-smart.css` + `review.css` + `review-smart.js` | 22 KB JS | Customer flow |
| `admin/` shell + `admin.css` + `admin.js` | 20.3 KB JS | Super admin |
| `pricing/` + `marketing-pages.css` + `marketing-pages.js` | 5.6 KB CSS | Public pricing / legal pages |
| `billing.css` + `billing.js` | 14.3 KB JS | Plan checkout |
| `analytics.css/js`, `support.js`, `security.js` | 15.4 KB JS | Analytics, support, MFA |
| `vendor/qrcode-generator.js`, `vendor/pdf-lib.min.js` | 56 KB + 525 KB | Local QR + PDF export |
| `vendor/supabase.js`, `supabase-config.js` | 206 KB | ⚠️ **never ships** |

---

## 3. Page-by-page master map

`REPLACE` = keep the PHP file's logic, swap its markup. `KEEP` = untouched.

### Zone A — Public

| # | PHP file | Now | Design source | Action |
|---|---|---|---|---|
| A1 | `index.php` | Router → `home.php` | — | **KEEP** (unchanged) |
| A2 | `home.php` (20.6 KB) | Landing page, Tailwind CDN | `index.html` + `home-redesign.css` + `reference-home.css` | **REPLACE** markup |
| A3 | `login.php` (4.3 KB) | Login form, Tailwind | `login/` shell + `app.css` + `design-system.css`; login view from `app.js` | **REPLACE** markup, **KEEP** POST handling |
| A4 | `signup.php` (4.9 KB) | Signup form | `register/` view in `app.js` | **REPLACE** markup, **KEEP** logic |
| A5 | `onboard.php` (6.7 KB) | "Create Review Card" form | `onboarding/` view in `app.js` | **REPLACE** markup, **KEEP** logic |

**On A2:** the homepage's pricing block currently comes from `home-pricing.js` fetching Supabase.
In PHP it becomes a plain `foreach` over the `plans` table — the same server data the admin panel
already edits. `home-redesign.js` (menu, reveal, tilt) is kept as-is.

### Zone B — Customer (no login)

| # | PHP file | Now | Design source | Action |
|---|---|---|---|---|
| B1 | `review.php` (47.7 KB, 958 lines) | Server-rendered flow + inline JS; actions `generate`, `submit`, `feedback` | `review/` + `review-smart.css` + `review.css`; flow states `formView` → `generate` → `resultView` from `review-smart.js` | **REPLACE** markup only |

**On B1 — highest-risk file in the project.** It is 958 lines and mixes three things: PHP endpoints
at the top (lines 19–200), HTML in the middle (lines ~450–640), and ~300 lines of inline JS at the
bottom that calls the AI. Replacing the markup without breaking the JS means: **every `id` and
`onclick` the inline JS touches must survive the transplant**. I have the list of those selectors;
it becomes a checklist that the replacement must satisfy, verified in a browser before it is
accepted.

The flow maps 1:1 onto your design's three states, so no behaviour changes:

```
your design:  formView  →  generate()  →  resultView
PHP today:    rating+keywords form  →  action=generate (Groq)  →  drafts + Copy / Open Google
```

**Deliberately not ported:** the design's `template-flow` engine and its 342 KB of sentence templates.
That engine was removed in the AI refactor because it produced canned text. In PHP the drafts come
from Groq. Porting it back would undo tested work and break your own "no fake experience" rule.

### Zone C — Business owner (logged in)

| # | PHP file | Now | Design source | Action |
|---|---|---|---|---|
| C1 | `list.php` (6.7 KB) | "My Review Cards" grid | `dashboard.js` card-grid view | **REPLACE** markup |
| C2 | `card_home.php` (16.7 KB) | Per-card dashboard + QR | `dashboard.js` overview panel | **REPLACE** markup |
| C3 | `card_reviews.php` (24.8 KB) | Reviews + feedback tables | `dashboard.js` reviews panel | **REPLACE** markup |
| C4 | `card_settings.php` (14.7 KB) | Business settings form | `dashboard.js` settings panel + `business-form` | **REPLACE** markup, **KEEP** field names |
| C5 | `card_membership.php` (17.3 KB) | Plan + membership | `billing.js` + `pricing` panel | **REPLACE** + wire Razorpay/WhatsApp |
| C6 | `includes/card_layout.php` (9.5 KB) | Shared sidebar/header for C1–C5 | `dashboard.css` shell | **REPLACE** |
| C7 | **`dashboard.php`** | **does not exist** | `dashboard/` | **NEW** — see issue #10 |

### Zone D — Super admin

| # | PHP file | Now | Design source | Action |
|---|---|---|---|---|
| D1 | `owner.php` (13.5 KB) | Owner dashboard | `admin.js` overview view | **REPLACE** markup |
| D2 | `owner_cards.php` (23.8 KB) | All cards + memberships | `admin.js` cards/owners table | **REPLACE** markup |
| D3 | `owner_plans.php` (16.6 KB) | Plan CRUD | `admin.js` plans editor | **REPLACE** markup |
| D4 | `owner_settings.php` (11.2 KB) | App name, Groq key, WhatsApp | `admin.js` settings view | **REPLACE** markup, **KEEP** field names |
| D5 | `includes/owner_layout.php` (9.4 KB) | Shared admin shell | `admin.css` | **REPLACE** |

### Zone E — No UI (must remain byte-identical)

`card_update_settings.php` · `card_reactivate_trial.php` · `feedback_mark_read.php` ·
`feedback_delete.php` · `review_delete.php` · `logout.php` — **6 files, zero HTML, never touched.**
They are the write-path of the app; a stray class name or wrapping `<div>` here breaks data saving
silently. All form `name=` attributes in Zone C/D must stay exactly as they are so these keep working.

### Zone F — Shared backend (never touched)

`includes/db.php` · `includes/auth.php` · `includes/functions.php` · `includes/payments.php` ·
`config.php` · `sql/001_payments.sql` — **6 files. No changes in this plan.**
That is where the 89 passing payment assertions live.

### Summary

| Action | Count | Files |
|---|---|---|
| Markup replaced | 15 | home, login, signup, onboard, review, list, card_home, card_reviews, card_settings, card_membership, owner, owner_cards, owner_plans, owner_settings, + 2 layouts |
| New file | 1 | `dashboard.php` |
| Untouched PHP | 12 | 6 endpoints + 6 backend/infra |
| New CSS bundles | 6 | public, auth, dashboard, review, admin, marketing |
| Ported JS | 5 | review flow, dashboard interactivity, QR, billing, admin helpers |
| Never shipped | 1 | `vendor/supabase.js` + `supabase-config.js` |

---

## 4. How the UI will connect to the PHP backend

Every interactive element in your design gets exactly one PHP destination. Nothing is decorative.

| UI element (your design) | Today | After integration |
|---|---|---|
| Login form | Supabase `signInWithPassword` | `POST login.php` → PHP session cookie |
| Register form | Supabase `signUp` | `POST signup.php` |
| "Create your first QR" | Supabase insert | `POST onboard.php` (`name`, `slug`) |
| Dashboard numbers | Supabase `select` with RLS | `getCardStats()`, `getRatingBreakdown()` — already exist |
| Reviews / feedback lists | Supabase `select` | server-rendered from `reviews`, `feedbacks` |
| Feedback "mark read" / delete | Supabase update/delete | existing POST endpoints |
| Business settings form | Supabase update | `POST card_update_settings.php` — **11 fields, unchanged** |
| QR image | Supabase QR campaign tables | `qrcode-generator.js` locally (see issue #9) |
| "Generate reviews" (customer) | Workers `/api/generate-reviews` | `POST review.php?action=generate` → Groq |
| Customer submit / feedback | Workers + Supabase | existing `action=submit` / `action=feedback` |
| Pricing cards (public) | `home-pricing.js` → Supabase | `SELECT * FROM plans` in PHP |
| "Pay by card" | Cloudflare billing | `POST` → `payments.php` → Razorpay checkout |
| "Pay on WhatsApp" | Cloudflare billing | `POST` → `payments.php` → pending request |
| Admin plan editor | Supabase admin RPC | `POST owner_plans.php` (`action`, `id`, …) |
| Admin app settings | Supabase settings | `POST owner_settings.php` |
| Logout | Supabase signOut | `logout.php` |

**Data shape note:** the JSON endpoints that stay (AI generation, payments) keep their current
response shape (`{ok, reviews[]}`, `{enabled, reason, message}`). Only the *caller* changes, so the
tested backend never has to be rewritten.

---

## 5. Compatibility issues

Ordered by how much they can hurt. Each one has a proposed fix and says whether it touches backend.

### #1 — SPA vs server-rendered *(structural, the main work)*
Your design builds DOM in JS; PHP prints it. **Fix:** transplant markup into PHP templates; keep
only interaction JS. **Backend: untouched.**

### #2 — Tailwind CDN fights your CSS *(visual, guaranteed to look broken)*
**Nine** PHP files load `cdn.tailwindcss.com` — essentially every page with a UI:
`home.php:45`, `login.php:64`, `signup.php`, `onboard.php`, `list.php:43`, `review.php`,
`includes/card_layout.php:41`, `includes/owner_layout.php:29`, `install.php`.

Tailwind's preflight injects global resets (`*{margin:0}`, `h1{font-size:inherit}` …) that will
override your design's typography and spacing, and the CDN build is explicitly not for production
(it compiles in the browser, ~400 KB). **Fix:** remove Tailwind from each page **at the moment that
page is converted** — never site-wide in one go, or the pages still unconverted collapse. **Backend: none.**

### #3 — jQuery and Font Awesome *(dependency, low risk)*
`jquery-3.7.1` and Font Awesome load in the same files, for icons like `<i class="fas fa-qrcode">`.
Your design uses no icon font — it uses inline SVG and glyphs (`✧ ✳ ▥ ◎`). **Fix:** replace icons
with the design's glyphs; drop jQuery where the converted page's own JS doesn't need it.
**Backend: none.**

### #4 — Two palettes inside your own design *(needs your decision)*
Reading the actual hex values, your design is **not one visual language**:

| File | Dominant colour | Reads as |
|---|---|---|
| `home-redesign.css` | `#4c24dd` | violet "Review AI" |
| `review-smart.css` | `#4d23df` | violet |
| `dashboard-redesign.css` | `#4c24d8` | violet |
| `review.css` | `#173d2e` | forest green |
| `app.css` | `#183a2d` | forest green |
| `admin.css` | `#1f513b` | forest green |
| `marketing-pages.css` | `#173d2e` | forest green |
| `design-system.css` | `#617667` / `#f8faf7` | sage green |

The public homepage and the customer review page are currently **violet**; the auth, admin and
marketing pages are **green**. **Options:** (a) preserve each page exactly as it is — maximum
fidelity, visibly mixed product; (b) unify to one of the two. **I recommend (a) unless you say
otherwise**, because your instruction was to not redesign. But you should decide knowingly:
right now a customer goes from a violet homepage to a green login screen.

### #5 — Supabase client must never ship *(security)*
`vendor/supabase.js` (206 KB) plus `supabase-config.js` currently point at your real project
`goxgkgbmdxfoswzlrehs`. **Fix:** excluded from the build. The PHP app uses MySQL + PHP sessions;
shipping the Supabase client would give the browser direct database access it must not have.

### #6 — Auth model mismatch *(high risk if done casually)*
The design keeps the session in `localStorage` as a Supabase JWT and guards pages in JS — meaning
the page is served *then* checked. PHP guards on the server (`requireLogin()` / `requireOwner()`,
`includes/auth.php`) and serves nothing until the session is valid. **Fix:** never port the JS
guard. Every Zone C/D page keeps its `requireLogin()` line above all HTML. **Backend: guards stay.**

### #7 — No CSRF protection anywhere *(pre-existing security gap)*
`grep -ri csrf app/` returns nothing. All 11 form POSTs are unprotected, so a third-party page can
make a logged-in owner change settings or delete reviews. **Fix:** add a single CSRF helper in
`functions.php` + one hidden input per form + one check per endpoint. Small, additive, and it
**does** touch the backend contracts — which is why it is a decision for you, not something I do
silently mid-redesign. It should be done, so I've listed it as decision #3.

### #8 — Design features with no PHP backend *(honesty risk)*
Your design ships modules the PHP app has no tables or endpoints for:

| Design module | PHP backend | Verdict |
|---|---|---|
| `analytics.js` (15 KB) — period filters, campaigns, comparisons | **none** | cannot be shown as working |
| `security.js` — authenticator/MFA setup | **none** | must not be shown |
| `support.js` — support tickets | **none** | must not be shown |
| Coupons / redemption | **none** | must not be shown |
| Account suspension | **none** | must not be shown |
| PDF export (`pdf-lib`, 525 KB) | **none** | optional later |
| QR campaigns (multiple, tracked) | **partial** — one QR per card | collapse to what exists |

Your own rule is no fake functionality, so these are **hidden or clearly marked "not available"**
until a backend exists. Showing a dashboard with pretty fake charts is exactly the thing we agreed
not to do. This is decision #4.

### #9 — QR codes come from a third party *(privacy + uptime)*
`card_home.php:49` fetches the QR image from `https://api.qrserver.com/v1/create-qr-code/?data=…`
That sends every customer review URL to an outside service, breaks if they rate-limit, and fails
offline. Your design ships `vendor/qrcode-generator.js` (56 KB, local, no network). **Fix:** generate
locally, overlay logo in-browser. Improvement, no schema change.

### #10 — `dashboard.php` does not exist *(broken links today)*
Seven places redirect to a file that isn't there:

```
includes/auth.php:59        requireOwner()  → dashboard.php   ← a logged-in non-owner hits a 404
card_home.php:23            → dashboard.php
card_reviews.php:22         → dashboard.php
card_settings.php:23        → dashboard.php
card_membership.php:22      → dashboard.php
card_update_settings.php:7,16 → dashboard.php
```

**Fix:** create `dashboard.php` as the real entry point (your design's dashboard landing, listing the
user's cards) and leave every redirect as-is. This makes the design's `/dashboard/` concept match a
real file — the cleanest possible fix, and it *closes* a bug rather than papering over it.

### #11 — Deleted pages are still in the design *(must stay deleted)*
Your design has `forgot-password/` and `reset-password/`. The PHP app's equivalents were removed at
your instruction (commit `23e1091`) because they printed a working reset link on screen to anyone.
**Fix:** do not port those two folders. Password recovery stays support-assisted.

### #12 — Fonts *(performance)*
The design ships `fonts/NotoSansDevanagari.ttf` (642 KB) + `fonts/NotoSansTelugu.ttf` (889 KB), and
`home.php:49` pulls Inter from Google Fonts via `@import`. **Fix:** self-host Inter woff2; load the
Noto files only on the customer page and only if Telugu/Devanagari output is enabled. Saves ~1.5 MB
from every page that doesn't need them.

### #13 — URLs *(cosmetic but user-visible)*
Design links are `/login/`, `/dashboard/`, `/pricing/`. PHP is flat: `login.php`, `list.php`.
**Options:** (a) keep flat `.php` URLs and rewrite the design's links; (b) add `.htaccess` rewrites so
`/login/` maps to `login.php`. **Recommendation: (b)** — one file, keeps your design's links working
and gives cleaner URLs, with (a) as a fallback if Hostinger rewrites misbehave.

### #14 — Razorpay checkout is an external script *(only matters at payment time)*
The design's `billing.js` calls Cloudflare endpoints that don't exist here. The PHP module creates
the subscription server-side (tested). The browser still needs `checkout.razorpay.com/v1/checkout.js`
loaded at click time. **Fix:** keep the two-step pattern — PHP creates the subscription, browser opens
checkout, PHP verifies the signature. **Backend: already done and tested; do not touch.**

---

## 6. Files that will change, exactly

### New
```
app/dashboard.php                      the missing landing page (fixes #10)
app/includes/public_layout.php         header/footer for the public + customer pages
app/assets/css/public.css              homepage + pricing (from home-redesign + reference-home)
app/assets/css/auth.css                login / signup / onboard (from app.css + design-system)
app/assets/css/dashboard.css           owner zone (from dashboard.css + dashboard-redesign)
app/assets/css/review.css              customer flow (from review-smart + review.css)
app/assets/css/admin.css               super admin (from admin.css)
app/assets/js/review-flow.js           customer interaction, calls PHP endpoints
app/assets/js/dashboard-ui.js          tabs, QR render, copy, modals
app/assets/js/checkout.js              Razorpay + WhatsApp buttons → PHP
app/assets/js/admin-ui.js              admin helpers
app/assets/fonts/inter.woff2           self-hosted
```

### Markup replaced (logic preserved)
```
home.php  login.php  signup.php  onboard.php  review.php
list.php  card_home.php  card_reviews.php  card_settings.php  card_membership.php
owner.php  owner_cards.php  owner_plans.php  owner_settings.php
includes/card_layout.php  includes/owner_layout.php
```

### Never touched
```
includes/db.php  includes/auth.php  includes/functions.php  includes/payments.php
config.php  sql/001_payments.sql
card_update_settings.php  card_reactivate_trial.php  feedback_mark_read.php
feedback_delete.php  review_delete.php  logout.php
```

---

## 7. Execution order

Each step ends with a working site and a git checkpoint. If a step goes wrong, we go back one commit
— never a half-converted page.

| Step | Work | Verify | Rollback |
|---|---|---|---|
| **0** | Freeze: backup DB, tag `pre-integration` | `git tag` visible | tag |
| **1** | Asset pipeline: fonts, CSS bundles, icon swap. No page touched | all current pages look identical | none needed |
| **2** | Public zone: `home`, `login`, `signup`, `onboard` | log in, sign up, create a card, see pricing | revert step 2 |
| **3** | Customer zone: `review.php` | rate → generate → copy → submit → negative path to feedback | revert step 3 |
| **4** | Business zone: `list`, `card_*`, new `dashboard.php` | edit settings, save, mark feedback read, delete review | revert step 4 |
| **5** | Super admin: `owner_*` | create/edit plan, change app name, toggle membership | revert step 5 |
| **6** | Payments on the new UI: Razorpay + WhatsApp | the 89 assertions still pass + a browser click-through | revert step 6 |
| **7** | Polish, mobile, then deploy prep | mobile check at 390 px, lighthouse, deploy checklist | — |

Order is deliberate: **public first** (safe, low risk, immediately visible), **customer second**
(most user-facing value), **owner/admin last** (only you see them, they touch the write path).

---

## 8. Decisions I need from you

1. **Palette** — keep the violet homepage + violet review page next to green auth/admin (exact
   fidelity, slightly mixed), or unify everything to one palette? *I recommend keeping as-is.*
2. **URLs** — `.htaccess` rewrites so `/login/` works, or keep `login.php`? *I recommend rewrites.*
3. **CSRF** — add protection to all 11 forms? It touches every endpoint. *I recommend yes, as its own
   step so it can be reviewed separately.*
4. **Design modules with no backend** — hide analytics / MFA / support / coupons / suspension until
   they're built, or build backends for them (much bigger project)?
5. **`install.php`** — delete it, or add the step-3 guard I proposed? *I recommend both.*

---

## 9. What this plan guarantees

- Your design's CSS is copied, not rewritten — colours, spacing, radii and shadows stay exact.
- No database table, column, function signature, session key or API response shape changes.
- The 89 passing payment assertions remain valid — `payments.php` is not in the change list.
- The 6 write-path endpoints and 6 backend files are untouched.
- Every converted page is verified in a real browser before the next one starts.
- Anything your design shows that PHP cannot actually do gets hidden, not faked.
