# Stage 4 — Business dashboard

**Status: PASSED** · commit `27a4716` · tag `stage-4-dashboard`
Baseline for this stage: tag `stage-4-start` (`7ab82b9`).

The signed-in business side of the app now uses the original design's dashboard
shell and components. Every number on every page comes from the database. Two
pieces of fake content that were in the purchased app are gone.

---

## 1. Pages completed

| Page | What it is |
|---|---|
| **`dashboard.php`** | **New.** The overview page. Eight redirects already pointed at it and it did not exist. Real totals, the newest reviews, private feedback and the card list. |
| `card_home.php` | Card overview: totals, review link + copy, QR code and its modal, quick actions. |
| `card_reviews.php` | Reviews and private feedback: filters, search, pagination, detail modals, mark-read / delete. |
| `card_settings.php` | Business details, contact links, theme, AI topics. |
| `card_membership.php` | Current plan, plan progress, available plans, WhatsApp upgrade, trial reactivation. |
| `includes/dashboard_layout.php` | **New.** One shell (sidebar, topbar, mobile menu) used by every signed-in page. |
| `includes/card_layout.php` | Now delegates to that one shell instead of holding its own Tailwind copy. |
| `list.php` | The old "My Review Cards" page is now a redirect to `dashboard.php`. Old links keep working. |

## 2. Files

**Created:** `app/dashboard.php`, `app/includes/dashboard_layout.php`,
`app/assets/css/dashboard-green.css` (built, not hand-written),
`tools/test-dashboard-flow.mjs`, `tools/audit-dashboard.mjs`, this report.

**Changed:** `app/card_home.php`, `app/card_reviews.php`, `app/card_settings.php`,
`app/card_membership.php`, `app/includes/card_layout.php`, `app/includes/functions.php`
(`excerpt()` moved here so the dashboard can use it), `app/includes/public_layout.php`,
`app/list.php`, `app/.htaccess` (added `/dashboard/`), `tools/build-assets.mjs`,
`tools/render-page.mjs`.

**Removed:** nothing. No customer or public page was touched.

## 3. Design assets used

- `app/assets/css/dashboard-green.css` — the design's own `dashboard.css`,
  `design-system.css`, `qr-test.css`, `production-qr.css` and `billing.css`,
  concatenated verbatim by `tools/build-assets.mjs`.
- `app/assets/css/fonts.css` (DM Sans, vendored in Stage 1).
- Classes taken from the design: `.layout .sidebar .brand .mark .workspace .workspace-label
  .nav .nav-label .nav-icon .side-bottom .side-user .avatar .main-column .topbar .top-left
  .crumb .top-right .top-email .menu-button .veil .content .page-heading .kicker .pill
  .stats .stat .stat-title .stat-symbol .stat-value .stat-note .overview-grid .overview-bottom
  .panel .panel-head .filters .empty-panel .empty-icon .empty-title .empty-copy .meter
  .meter-caption .form-panel .form-section .form-grid .field .hint .check .checks .form-footer
  .status .alert .billing-scroll .billing-cards .billing-price .button .button-dark
  .button-outline .button-danger .button-small .small-note`.

### One deliberate palette decision

The reference dashboard file ships **two** themes: the base green one, and a later
style block (`dashboard-redesign.css`) that repaints the whole dashboard violet
(`--rg-forest:#4c24d8`). The locked rule for this project is that the dashboard
stays green, so that one sheet is excluded and the design's base dashboard look is
used. The choice is documented in `tools/build-assets.mjs` next to the bundle
definition.

### Locally written classes (all of them listed by the audit)

`rg-actions`, `rg-modal` (reviews, membership), `rg-themes`, `rg-theme`, `swatch`,
`name`, `logo-box`, `logo-row` (settings), `show` (the toast the pages add at
runtime) and `current` (the plan the card is on). Each is a few lines, styled with
the design's own tokens, and commented in the page it lives in.

## 4. Fake content removed

1. **Invented plans.** `card_membership.php` used to fall back to three made-up
   plans ("Trial / Pro / Enterprise", with invented prices and feature lists)
   whenever the `plans` table was empty. That fallback is gone; an empty table now
   shows an honest empty state.
2. **A sentence-template helper.** `card_settings.php` carried a
   `refreshAiPreview()` function that built a canned sentence ("I had an amazing
   experience at … Highly recommend!"). It was dead code — no element it targeted
   existed — and it is exactly the template generator this project must not ship.
   Deleted. The logo preview that was next to it is kept and converted to plain JS.

Pricing display was also tightened: a "sale" price is only shown when it is
genuinely lower than the list price, so a blank or `NULL` sale price can never
render as ₹0.

## 5. Design comparison — what was and was not possible

**What I could not do:** render the design's dashboard side by side. That reference
is a single-page app that boots through Supabase auth, and it redirects to
`/onboarding/` without a session. This project must never ship the Supabase client,
so there is no honest way to boot it in this workspace. I did not fake a comparison.

**What I did instead:**

1. **Class audit** (`tools/audit-dashboard.mjs`): every class used by the seven
   rendered dashboard pages is checked against the design's stylesheet.

   ```
   dashboard 44/44 design · card-reviews 43 design + 2 local · card-settings 37 design + 6 local
   card-membership 42 design + 1 local · card-home 44/44 design
   PASS: 0 page(s) with undocumented classes (308 class usages checked)
   no remote stylesheets or scripts on any dashboard page
   ```

2. **Rendered every page and looked at it** at 1280px and 390px — screenshots in
   `docs/screenshots/stage2-dashboard-*.png` and `stage2-card-*-{desktop,mobile}.png`,
   plus `stage4-*` for the interactive states.

So: the components are the design's components, and the palette is the design's
green. The page *compositions* are mine, because the PHP app has different pages
than the design's single-page dashboard.

## 6. Tests actually run

| Test | Command | Result |
|---|---|---|
| Dashboard interaction test | `node tools/test-dashboard-flow.mjs` | **43/43 checks** |
| Class / asset audit | `node tools/audit-dashboard.mjs` | **308 class usages, 0 undocumented, 0 remote assets** |
| Render, 7 states, 2 viewports | `node tools/render-page.mjs dashboard dashboard-empty card-home card-reviews card-reviews-feedback card-settings card-membership` | **0 PHP fatals, 0 JS errors** |
| PHP lint | `node tools/php-lint.mjs app` | **32/32** |
| Payment assertions | `node tools/php-run.mjs tests/php/test_payments.php --require app/includes/payments.php` | **89 passed, 0 failed** |
| Stage 2 + 3 regression | `node tools/test-review-flow.mjs` | **51/51** (re-run after the `excerpt()` move) |

The 43 checks cover:

- shell: sidebar visible, correct active nav item, five nav links, the slug on the
  four card links, breadcrumb, signed-in email, no JS errors
- mobile: menu opens the sidebar and the veil, `aria-expanded` flips, Escape closes
- overview: four totals read from the data (46 / 4.5 / 3 / 2), both cards listed,
  Manage links to `card_home.php?slug=…`
- card overview: Copy link really writes to the clipboard, the toast confirms, the
  QR modal opens on click and closes on Escape
- reviews: table rows, View modal with real content, Escape closes it, Delete posts
  `review_id=1` to `review_delete.php`
- feedback: Mark read posts `feedback_id=7` to `feedback_mark_read.php`, the detail
  modal shows the message
- settings: all 13 form fields still present, the form still posts to
  `card_update_settings.php` as `multipart/form-data`, the logo preview updates when
  a file is picked
- membership: three plans from the data, no invented "Enterprise", a plan opens the
  upgrade modal, the WhatsApp link carries the plan name, Escape closes it

## 7. Known issues

- **QR image** comes from `https://api.qrserver.com` (as before this stage). It
  works, but it is an external service; swapping it for a vendored QR library is a
  Stage 6 decision. If it fails to load, the card page now says so instead of showing
  a broken image.
- **`list.php` now redirects.** Anything that linked to it still works; it no longer
  has its own page.
- **Not yet rebuilt:** `owner.php`, `owner_cards.php`, `owner_plans.php`,
  `owner_settings.php` and `includes/owner_layout.php` still hold the old Tailwind
  chrome. That is Stage 5 (admin panel).
- **CSRF** on `card_update_settings.php`, `review_delete.php`, `feedback_delete.php`,
  `feedback_mark_read.php` and `card_reactivate_trial.php` is still missing. Stage 6.
  All four JSON endpoints do verify ownership through a JOIN on `review_cards.user_id`.
- **`.htaccess` still cannot be executed here** (no Apache in this workspace), so
  `/dashboard/` is verified by inspection only. It joins the Hostinger checks listed
  in `docs/STAGE-2-PUBLIC.md` §7.

## 8. Reproduce

```bash
node tools/build-assets.mjs
node tools/render-page.mjs dashboard dashboard-empty card-home card-reviews card-reviews-feedback card-settings card-membership
node tools/test-dashboard-flow.mjs
node tools/audit-dashboard.mjs
```
