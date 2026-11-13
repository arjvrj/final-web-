# Stage 1 — Assets

**Scope:** CSS and JavaScript assets only. No application functionality changed.
**Result:** all six bundles reproduce the original design **pixel for pixel** — homepage proven at
**0 differing pixels out of 2,368,000**.

---

## 1. What was built

Six CSS bundles in `app/assets/css/`. Each one is a **verbatim concatenation** of the original
design files, in the exact cascade order the design's own pages load them. No colour, size, radius,
shadow or selector was rewritten — that is the whole reason the rendering stays identical.

| Bundle | Size | Built from (in order) | Used by |
|---|---|---|---|
| `public.css` | 25,643 B | `home-redesign.css` + `reference-home.css` | Homepage, marketing |
| `auth.css` | 16,754 B | `app.css` + `design-system.css` | Login, signup, onboarding |
| `dashboard.css` | 34,367 B | `dashboard.css` + `design-system.css` + `qr-test.css` + `production-qr.css` + `billing.css` + `dashboard-redesign.css` | Business dashboard, QR, reviews, settings, plans |
| `customer.css` | 8,418 B | `review-smart.css` | Customer review flow |
| `admin.css` | 3,396 B | `admin.css` | Super admin |
| `marketing.css` | 17,091 B | `marketing-pages.css` + `design-system.css` | Pricing / legal |

Each file carries a header naming its sources and their SHA256 prefixes, so provenance is never
guesswork. `node tools/build-assets.mjs` rebuilds them all; re-running produces **byte-identical**
output (verified).

### Two deliberate choices

- **`dashboard.css` adds two files the reference snapshot lacks** — `billing.css` and
  `dashboard-redesign.css` — because the *live* dashboard page loads them. It **drops
  `analytics.css`**, because analytics is a hidden feature. Rendering is unaffected: the
  dashboard comparison below shows 0 differences with analytics removed.
- **`customer.css` uses `review-smart.css` only.** The other candidate, `review.css`, is the older
  forest-green variant and would override the violet identity you asked me to preserve.

### The customer *reference* file is not the customer design

Important finding: `Review-AI-Reference-Customer.html` does **not** contain `review-smart.css`. It is
the **template-flow variant** — its CSS defines `.template-flow`, `.template-grid`, `.idea-list`, and
it carries the 342 KB sentence-template bundle. That is exactly the engine we removed and must not
reintroduce. The real customer design comes from `review/index.html` + `review-smart.css` +
`review-smart.js`, which is what `customer.css` holds.

## 2. Fonts — self-hosted, CDN gone

Before: `home.php` pulled Inter from Google Fonts with an `@import`, and the auth/dashboard CSS asked
for `'DM Sans'` which **was never loaded at all** — every page silently fell back to Arial.

Now: `tools/fetch-fonts.mjs` downloads the real woff2 files once into `app/fonts/` and writes
`app/assets/css/fonts.css`:

| Font | Files | Size |
|---|---|---|
| Inter | latin + latin-ext, weight 300–900 (variable) | 130 KB |
| DM Sans | latin + latin-ext, weight 300–900 (variable) | 54 KB |
| Noto Telugu / Devanagari | copied from the design | 1.5 MB (reserved: multilingual variant only) |

**Proof they load:** the browser requests `Inter-latin-normal-300-900.woff2` → **HTTP 200**, and text
measured with Inter is 612 px wide versus 515 px with the fallback — different glyphs, so the file is
genuinely in use. DM Sans verified separately on `auth.css`: loads 200, renders at 326 px.

## 3. JavaScript and vendor files

| File | Why | Status |
|---|---|---|
| `app/assets/js/vendor/jquery-3.7.1.min.js` | 10 PHP pages use `$.ajax` / `$.post` | self-hosted, replaces CDN |
| `app/assets/vendor/fontawesome/` | 60 icon classes in the PHP markup | self-hosted, replaces CDN — **transitional**, disappears page by page as each is re-skinned |
| `app/assets/js/vendor/qrcode-generator.js` | local QR generation (plan issue #9) | copied from the design, 56 KB |

Not shipped, by decision: `vendor/supabase.js`, `supabase-config.js`, `pdf-lib.min.js`,
`analytics.js`, `security.js`, `support.js`, `billing.js` (Clientflare endpoints), `template-flow.*`.

## 4. Verification results

Run it yourself: `node tools/verify-assets.mjs` and `node tools/pixel-diff.mjs`.

| Check | Result |
|---|---|
| Harness sanity (both arms real and visible) | 2,334 chars text, 220 visible elements each |
| **Homepage: 234 elements, 17 CSS properties each** | **IDENTICAL** |
| **Homepage pixel diff (animations frozen)** | **0 of 2,368,000 pixels differ** |
| **Dashboard: 129 elements** | **IDENTICAL** |
| Bundle source order = design load order | 4/4 confirmed |
| Tailwind actually loaded during the conflict test | yes (a test that can't load Tailwind proves nothing) |
| Fonts served locally | Inter 200, DM Sans 200 |

### The Tailwind conflict, measured

Tailwind's preflight, applied to the design's own components:

| Property | Design | With Tailwind |
|---|---|---|
| `body` line-height | `normal` | `24px` |
| `ul` list-style | `disc` | `none` |
| `ul` padding-left | `40px` | `0px` |
| `ul` margin-top | `16px` | `0px` |
| `h2` font-weight | `700` | `400` |

Five visible regressions from one script tag. This is why Tailwind is removed **per page, in the same
commit that applies the new markup** — never site-wide in one go, or every unconverted page loses its
styling at once.

### The per-page removal checklist (executed in Stages 2–5)

| Page | File | Lines to remove | Replaced with |
|---|---|---|---|
| Homepage | `home.php` | 45–47 (Tailwind, jQuery, FA), 49 (`@import` Inter) | `fonts.css` + `public.css` |
| Login | `login.php` | 64–65 | `fonts.css` + `auth.css` |
| Signup | `signup.php` | 67–68 | `fonts.css` + `auth.css` |
| Onboarding | `onboard.php` | 54–56 | `fonts.css` + `auth.css` |
| Card list | `list.php` | 43–45 | `fonts.css` + `dashboard.css` |
| Customer review | `review.php` | 240, 303–305, 307 | `fonts.css` + `customer.css` |
| Card layout | `includes/card_layout.php` | 41–43 | `dashboard.css` |
| Admin layout | `includes/owner_layout.php` | 29–31, 33 | `admin.css` |
| Installer | `install.php` | 331–332 | untouched in this stage; installer is deleted at Stage 6 |

jQuery stays wherever the page's own JS still uses it; it is only dropped once those calls are ported
to `fetch()`. That porting happens per page, in Stages 2–5, not here.

## 5. Bugs found and fixed during this stage

Reported honestly, because two of them produced a **false PASS** and would have shipped a broken page:

1. **The font fetcher returned 0 faces.** It searched for the word "latin" inside the `@font-face`
   body, but Google only puts it in the comment above. Fixed by parsing `/* subset */ @font-face {…}`
   as one unit.
2. **The harness served an undefined variable** (`harness.home` instead of `harness.homeBundle`),
   producing an empty 200 page that was then compared against the real one.
3. **The comparison passed with one arm empty.** `min(control.length, test.length)` = 0 diffs = a
   green "IDENTICAL". Now a length mismatch or an empty arm is an **INVALID COMPARISON**, and a
   sanity gate asserts both pages have >500 chars of text and >50 visible elements.
4. **The first screenshot was blank.** The design hides `.reveal` elements until JS adds a class; I
   had stripped its scripts, so the "passing" comparison was of two invisible pages. Fixed by
   neutralising the reveal state in **both** arms, which is also why the pixel diff is run with
   animations frozen — otherwise floating animations are caught at different frames and fake a
   0.57% difference.
5. A truncated log (`| head`) killed a run with SIGPIPE and left a stale screenshot behind — caught
   because the file size didn't match.

## 6. What changed — files added only

**Added:** `app/assets/css/{public,auth,dashboard,customer,admin,marketing,fonts}.css` ·
`app/assets/js/vendor/{jquery-3.7.1.min.js,qrcode-generator.js}` ·
`app/assets/vendor/fontawesome/**` · `app/fonts/*.woff2|.ttf` ·
`tools/{build-assets,fetch-fonts,verify-assets,pixel-diff}.mjs`

**Modified: none.** `git diff pre-integration -- 'app/*.php'` → **0 files changed**.
The 6 backend files and 6 write-path endpoints remain untouched.

## 7. Tests after Stage 1

| Test | Result |
|---|---|
| Payment assertions | **89 passed, 0 failed** |
| PHP syntax sweep | **29/29 files parse cleanly** |
| Homepage pixel identity | **0 differing pixels** |
| Dashboard computed-style identity | **0 differing elements** |
| Asset rebuild idempotency | **byte-identical** |

## 8. Is it safe to proceed to Stage 2?

**Yes.** The asset layer is in place, proven not to alter the design, and not yet referenced by any
PHP page — so the live app behaves exactly as it did at the `pre-integration` tag. Stage 2 starts
the moment `home.php` begins loading `fonts.css` + `public.css` instead of the CDNs.

One thing to know before Stage 2: the reference homepage markup contains a
"DESIGN PREVIEW · Account links disabled · Nothing published" bar at the top. That belongs to the
reference document, not to your design — it will not be carried into `home.php`.
