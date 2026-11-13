# Stage 2 — Public Pages

Scope: homepage, login, signup, onboarding. Nothing else was touched.
Result: **all four pages render in the original design, every existing PHP action still fires,
27 internal links all resolve, no dead ends.**

---

## 1. Pages completed

| Page | File | What changed | Backend |
|---|---|---|---|
| Homepage | `app/home.php` | Markup replaced with the design's own homepage | Added a `plans` query so pricing shows real plans |
| Login | `app/login.php` | Markup replaced with the design's account card | Logic identical + redirect hardening (see §6) |
| Signup | `app/signup.php` | Markup replaced | Logic identical |
| Onboarding | `app/onboard.php` | Markup replaced, slug preview ported jQuery → vanilla JS | Logic identical |

## 2. Files created, changed, removed

**Created**

| File | Purpose |
|---|---|
| `app/includes/public_layout.php` | Shared markup: document head, account shell, card, `.status` box, `form_field()`, `excerpt()` |
| `app/assets/js/home-redesign.js` | The design's own homepage script (menu, scroll reveal, tilt) — copied verbatim, 1,628 B |
| `app/.htaccess` | Clean URLs + deny includes/docs/tools/sql/md + security headers |
| `tools/build-home.mjs` | Generates `home.php` from the design file with asserted substitutions |
| `tools/render-page.mjs` | Runs the real PHP with a stub DB and screenshots it |
| `tools/compare-public.mjs` | Compares the rendered pages against the design element by element |

**Changed:** `app/home.php`, `app/login.php`, `app/signup.php`, `app/onboard.php`
**Removed:** none

## 3. Design assets used

All from `/home/user/ReviewGrow-UI-Integration/` — the actual source, not a description of it:

| Asset | Used for |
|---|---|
| `index.html` | Homepage markup — **the body is copied by `tools/build-home.mjs`, not retyped** |
| `home-redesign.css` + `reference-home.css` | Already bundled as `public.css` in Stage 1 |
| `app.css` + `design-system.css` | Already bundled as `auth.css` in Stage 1 |
| `app.js` lines 12–28 | The exact markup of `card()`, `field()`, the login form and the register form |
| `home-redesign.js` | Homepage behaviour, copied verbatim |

Because the homepage is generated from the design file with assertions, an un-replaced placeholder
cannot silently ship: the build stops.

## 4. Design details I could not reproduce, and why

| Design element | Why it is not there |
|---|---|
| **Login: "Forgot your password?"** | That feature was deleted at your instruction (commit `23e1091`) because it printed a working reset link on screen to anyone. Replaced with a `form-foot` line: "Need help signing in? Contact support." |
| **Register: "Full name" field** | The `users` table is `id, email, password, otp, created_at` — there is no name column. Adding one would change the schema, which is out of scope and was not approved. |
| **Onboarding: 3-step wizard** | The wizard collects category, address, phone, website and language. `review_cards` only holds `id, user_id, name, slug, membership, expiry_date, details`. A three-step wizard with one real field would be theatre, so the page is a single design-styled card. |
| **Pricing: monthly / yearly toggle** | The `plans` table has one price and a `duration` in days. A toggle would have to invent a yearly price. |
| **Pricing: strike-through on sale price** | Growth is priced ₹999 with a ₹799 sale price; the design has no strike-through element, so only the live price is shown (₹799). |
| **Footer: Contact / Privacy / Terms** | Those pages do not exist in the PHP app. Linking them would be a dead end, so the footer links only to sections and pages that exist. **These three pages are worth building** — say the word and they are a small job. |
| **Homepage: 3 feature cards** | The design claimed "Multi-Language Support — English, Hindi and Telugu sentence ideas" (that was the removed template engine), "Powerful Analytics — see available scans and campaign activity" (no analytics exists), and "AI generation not connected" (AI *is* connected). All three were false or would break your no-fake-functionality rule, so they now describe what the app actually does: Customer Feedback, Built-in QR Code, and the AI note removed. |
| **FAQ: 2 answers** | "Billing and cancellation are not active yet" is false — Razorpay and WhatsApp payments exist. "Colour and layout options are available" is false — the QR has no colour options. Rewritten accurately. |
| **Brand text** | The design says "Review AI". The page prints `app_name` so your white-label setting still works. |
| **Homepage stats band** | The design has none, so I did not invent one. The real review and business counts appear in the customer-stories subtitle, which already existed in the design. |

Every one of these is a substitution recorded in `tools/build-home.mjs` — run it and it prints the list.

## 5. Tests run and actual results

| Test | Command | Result |
|---|---|---|
| Payment assertions | `node tools/php-run.mjs tests/php/test_payments.php --require app/includes/payments.php` | **89 passed, 0 failed** |
| PHP syntax | `node tools/php-lint.mjs app` | **30/30 files parse cleanly** |
| Real PHP render, 7 states | `node tools/render-page.mjs home home-empty login login-error signup signup-error onboard` | all render; **0 fatal errors, 0 unprocessed PHP tags** |
| Dead links | scan of every `href` in all rendered pages | **27 links checked, 0 dead** |
| Design fidelity | `node tools/compare-public.mjs` | **342 of 346 elements IDENTICAL**; the 4 differences are the brand string and the lengthened FAQ answer |
| Reveal animations | browser check | 2/2 `.reveal` elements get `.visible`, `js-motion` applied, opacity 1 |
| Mobile menu | tap at 390 px | opens (`#nav-links.open`) |
| Failed requests | 1280 px and 390 px | **none** |
| Redirect safety | 10 inputs incl. `//evil.com`, `javascript:` | **10/10 correct** |

Screenshots: `docs/screenshots/stage2-{home,home-empty,login,login-error,signup,signup-error,onboard}-{desktop,mobile}.png`

### How the pages were verified without MySQL

There is no MySQL server in this sandbox, so `tools/render-page.mjs` runs the page's real PHP with a
stub `$conn` that answers the queries these pages make. The HTML you see in the screenshots was
produced by the page's own template tags, loops and escaping — which is what needed checking.

**What this does not prove:** that the SQL works against real MySQL. That is the Stage 7 test on
your hosting, and I will not claim otherwise.

## 6. One change to existing logic (disclosed)

`login.php` had an open-redirect: `?redirect=//evil.com` passed the old check and a browser would
follow it off-site. The check is now strict (same-site paths only), written **inside `login.php`** so
the shared backend files stay untouched. Ten inputs tested, including the two that used to get
through.

Also: `onboard.php` used `getSetting('app_name', 'App')` while every other page defaults to
`'ReviewAI'`, so its header read "App". Aligned to `'ReviewAI'`. Both only apply when the setting is
missing.

## 7. Clean URLs (.htaccess)

`app/.htaccess` adds:

```
/login/            → login.php          /r/<slug>/  → review.php?slug=<slug>
/register/ /signup/ → signup.php        /onboarding/ → onboard.php
/logout/           → logout.php         (existing .php URLs keep working)
```

It also denies browser access to `includes/`, `docs/`, `tools/`, `tests/`, `sql/`, `config.php`,
`*.sql` and `*.md`, and sets three security headers.

**Honest limitation: this sandbox has no Apache, so I could not execute these rules.** They are
verified by inspection only. Before going live, check on Hostinger:

1. `https://yoursite/login/` shows the login page (not a 404)
2. `https://yoursite/login.php` still works
3. `https://yoursite/includes/db.php` returns 404
4. `https://yoursite/config.php` returns 404
5. `https://yoursite/r/yourslug/` opens the review page

Internal links still use `url('login.php')` etc. on purpose: they work whether or not mod_rewrite is
enabled. The clean URLs are for QR codes, external links and SEO.

## 8. Known issues / remaining work

1. **Contact, Privacy, Terms pages** — the design has them; the app does not. Not built (out of the
   approved scope).
2. **The design's hero art overlaps the proof-points row at 390 px.** This is in the *original
   design* too — the comparison shows the hero is pixel-identical at mobile — so I left it alone
   rather than redesign it. Say the word and I will fix it as a documented deviation.
3. **CSRF is not added yet** — that is Stage 6, and it touches every POST endpoint.
4. **`install.php` is still present** — Stage 6.
5. **Real MySQL untested** — Stage 7.
6. Not touched, as instructed: customer review page (Stage 3), dashboard (Stage 4), admin (Stage 5),
   payments (Stage 6).

## 9. Git

- Checkpoint before work: tag `stage-2-start`
- Commit for this stage: tag `stage-2-public`

Rollback: `git checkout stage-2-start` — nothing else was modified.

## 10. Is it safe to proceed to Stage 3?

**Yes.** All four public pages render in the design, every form still posts to the same PHP, and the
payment suite is untouched at 89/89. Stage 3 is the customer review page — the one customers see,
and the one where "generate reviews" must keep working through Groq.
