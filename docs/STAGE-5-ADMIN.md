# Stage 5 — Admin (owner) panel

**Status: PASSED** · commit `b5c5a32` · tag `stage-5-admin`
Baseline for this stage: tag `stage-5-start` (`5e43cf3`).

The five super-admin pages now use the original design's admin panel stylesheet.
Every action still runs inside the app's own `requireOwner()` gate, and the
secret that used to be printed into a form is no longer printed at all.

---

## 1. Pages completed

| Page | File | What it does |
|---|---|---|
| Dashboard | `owner.php` | Platform totals, ratings split, newest cards / users / reviews / feedback |
| Review cards | `owner_cards.php` | All cards, search + plan filter, pagination, change plan/expiry, delete |
| **Users** | `owner_users.php` | **New.** Read-only account list with card and review counts |
| Plans | `owner_plans.php` | Create / edit / delete subscription plans |
| Settings | `owner_settings.php` | WhatsApp number, app name, GROQ key |
| Shell | `includes/owner_layout.php` | Header, section tabs, skip link, modal styling |

## 2. Files

**Created:** `app/owner_users.php`, `tools/test-admin-flow.mjs`, this report.

**Changed:** `app/owner.php`, `app/owner_cards.php`, `app/owner_plans.php`,
`app/owner_settings.php`, `app/includes/owner_layout.php`, `tools/render-page.mjs`
(admin fixtures + a settings-aware stub), `tools/audit-dashboard.mjs` (now audits
the admin pages too).

**Removed:** nothing. No customer or business page was touched.

## 3. Design assets used

`app/assets/css/admin.css` — the design's own admin panel stylesheet, exactly as
shipped (already vendored in Stage 1). Classes used: `header nav main .skip .card
.alert .stats .num .muted .row .field .table-wrap table .status .grid .stack .tag
.message .center .btn .secondary details pre`.

Locally written classes (all listed by the audit): `modal`, `modal-head`,
`plan-card`, `plan-foot`, `plan-price`, `feature-row`, `row-actions`, `pager`,
`gap`. Each is a handful of lines drawn with the admin palette, because
`admin.css` has no modal or pager component.

Colours, typography and the dark-green header are the design's; nothing was
re-skinned. This matches the locked decision that admin is the green area.

## 4. Real admin functions connected

| Action | Endpoint | Verified |
|---|---|---|
| Change a card's plan + expiry | `owner_cards.php?action=update_membership` | yes — posts `card_id`, `membership`, `expiry_date` |
| Delete a card | `owner_cards.php?action=delete` | yes — double confirmation, posts `id` |
| Create / update a plan | `owner_plans.php?action=save` | yes — posts name, price, sale price, duration, cta, description, `features[]` |
| Delete a plan | `owner_plans.php?action=delete` | yes |
| Save platform settings | `owner_settings.php?action=save` | yes — whatsapp, app name, optional new key |
| Browse cards | `owner_cards.php` with `search` / `membership` / `page` | yes |
| Browse users | `owner_users.php` with `search` | yes (read-only) |

All four POST dispatchers run **after** `requireOwner()`, so a non-owner is
rejected before any query runs — not just hidden from the menu.

## 5. Features hidden because the backend is missing

These are not shown anywhere, not greyed out and not faked:

- **Analytics** — no events table, no scan tracking.
- **User suspension / banning / editing** — no column, no endpoint. The users page
  says so in plain words instead of showing dead buttons.
- **MFA, support tickets, coupons, impersonation** — none of it exists in this app.
- **Revenue reports** — payments are not stored in the app (`payments.php` handles
  Razorpay verification and plan activation, but there is no invoice table), so no
  money figure is displayed.

One dead link was removed by building the page behind it: `owner.php` linked to
`owner_users.php`, which did not exist. It exists now.

## 6. Security work in this stage

1. **The GROQ API key is no longer printed into the page.** Before, the key was
   echoed into an `<input value="…">`, so anyone with the admin page open — or a
   screenshot, or a browser extension — had the key. Now the page only says whether
   a key is saved, the field starts empty, and typing a new value replaces it.
2. **An empty key field can no longer wipe the key.** The server now ignores an
   empty submission; removing the key is a separate, explicit checkbox.
3. **Third-party advertising removed.** The old admin header injected a bouncing
   link to `sn24.pages.dev` plus a five-day `localStorage` timer to reveal it. It is
   not part of this application and it is gone.
4. All Tailwind / jQuery / Font Awesome CDNs are gone from the admin pages; the
   pages now load `admin.css` only.

## 7. Design comparison

Same honest limit as Stage 4: the design's admin views live inside the
Supabase-gated dashboard app, which redirects without a session and which this
project must never ship. So there is no side-by-side render. What was done instead:

- **`tools/audit-dashboard.mjs`** now audits the admin pages against `admin.css`:

  ```
  owner 10/10 design · owner-cards 12 design + 3 local · owner-users 9/9 design
  owner-plans 10 design + 5 local · owner-settings 10 design + 1 local
  PASS: 0 page(s) with undocumented classes or remote assets (368 class usages)
  ```

- Every page rendered at 1280px and 390px and reviewed by eye
  (`docs/screenshots/stage2-owner-*.png`, `stage5-owner-mobile.png`).

## 8. Tests actually run

| Test | Command | Result |
|---|---|---|
| Admin interaction test | `node tools/test-admin-flow.mjs` | **46/46 checks** |
| Access control (non-owner) | same test, section 0 — real `isOwner()` gate | **4/4 pages produce no output** |
| Secret exposure | same test, section 0b | **key not present in the HTML** |
| Class / asset audit | `node tools/audit-dashboard.mjs` | **368 class usages, 0 undocumented, 0 remote assets** |
| Render, 5 admin pages + 4 denial states | `node tools/render-page.mjs owner owner-cards owner-users owner-plans owner-settings owner-denied …` | **0 PHP fatals, 0 JS errors** |
| PHP lint | `node tools/php-lint.mjs app` | **33/33** |
| Payment assertions | `node tools/php-run.mjs tests/php/test_payments.php --require app/includes/payments.php` | **89 passed, 0 failed** |
| Stage 4 regression | `node tools/test-dashboard-flow.mjs` | **43/43** |
| Stage 3 regression | `node tools/test-review-flow.mjs` | **51/51** |

The 46 checks cover: header/badge/email, the five tabs marked current, **every tab
target exists on disk**, four numeric stat tiles, the cards table, the plan-change
modal (a plan selection fills type + expiry), the update-membership POST payload,
delete (double confirm), the plan modal and its `features[]` payload, plan delete,
the settings form (whatsapp prefilled, key field empty, empty key not submitted, a
typed key submitted and then cleared), the users page (no password column, no
invented controls), and mobile with zero horizontal overflow.

## 9. Known issues

- **CSRF tokens** on the four admin POST endpoints are still missing — Stage 6.
  They are owner-only and re-check `requireOwner()`, but a token is still needed.
- **`isOwner()` is a single email lookup.** There is no role column, so there is
  exactly one admin account (whatever `owner_email` holds). Making several admins
  needs a schema change — out of scope here, and Stage 6 should decide it.
- `install.php` still carries its own Tailwind/FA CDN tags and its known hole;
  that is the Stage 6 item already on the list.
- `.htaccess` remains unverifiable in this workspace (no Apache).

## 10. Reproduce

```bash
node tools/render-page.mjs owner owner-cards owner-users owner-plans owner-settings \
  owner-denied owner-cards-denied owner-plans-denied owner-settings-denied
node tools/test-admin-flow.mjs
node tools/audit-dashboard.mjs
```
