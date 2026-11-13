# HOSTINGER — go-live checklist

Written for Hostinger **shared hosting** (hPanel, LiteSpeed/Apache, PHP 8.x,
MySQL). Every step is something you do in a browser or over SFTP; nothing here
touches DNS until the last section, and nothing here is done automatically.

Work top to bottom. Each block ends with a **check** that either passes or tells
you what to fix.

---

## 1. Before you upload

- [ ] **Back up what is on the server now.** hPanel → *Files → Backups*, or
      download `public_html` and the database (*Databases → phpMyAdmin → Export*)
      to your computer.
- [ ] **Back up your database separately** if you have one live already:
      phpMyAdmin → select the DB → Export → *Go*. Keep the `.sql` file.
- [ ] Have ready: the database name/user/password, the site URL, a note of whether
      you are going live on the main domain or a subfolder.

---

## 2. PHP version and extensions

hPanel → **Advanced → PHP Configuration** (or *Websites → Advanced → PHP Configuration*):

- PHP version: **8.1, 8.2 or 8.3**. Not 7.4 — the code uses 8.x features.
- Extensions enabled: `pdo_mysql`, `curl`, `json`, `openssl`, `mbstring`,
  `fileinfo`. These are on by default on Hostinger; confirm anyway.
- `display_errors`: **Off**. `log_errors`: **On**. (`error_log` is where the app
  writes failures.)
- Memory limit: 256 MB is comfortable.

**Check:** open `https://yourdomain.com/` — you should get the app's landing page
or a redirect to the installer, never a 500 or a raw PHP error dump.

---

## 3. Database

hPanel → **Databases → MySQL Databases**:

1. Database name — Hostinger prefixes it, e.g. `u123456789_reviewgrow`.
2. User + a strong password (use the generator; 20+ characters).
3. **Add user to database → All privileges.**
4. Charset: `utf8mb4` / collation `utf8mb4_unicode_ci`. The schema also declares
   it per table, so this only matters for the database default.

Write the three values down now; the installer needs them once.

**Migration path — pick one:**

| Situation | What to do |
|---|---|
| Brand new site (no database) | Run `install.php` (step 4 below). It creates all 10 tables. |
| An **older ReviewGrow** is already running (pre-Stage 7) | Import `sql/migrations/2026-11-payments.sql` in phpMyAdmin → *Import*. It is additive only: creates `payments`, `webhook_events`, adds `users.trial_started_at` and backfills it, seeds payment settings. **Do not** import `sql/schema.sql` over a populated database. |
| You prefer SQL over the wizard | Import `sql/schema.sql`, then follow `INSTALL.md` §6. |

Before importing the migration, make sure the backup from §1 exists. The
migration is additive but there is no undo script.

**Check:** phpMyAdmin → your database → **10 tables**:
`users, review_cards, reviews, feedbacks, platform_settings, plans, keywords, settings, payments, webhook_events`.
Then `SELECT * FROM settings WHERE setting_key LIKE 'payments%';` → `disabled`
and empty key fields.

---

## 4. Upload the files

Use **hPanel → Files → File Manager** or SFTP — not a drag-and-drop FTP client
that hides dotfiles.

- Upload the **contents of `app/`** into `public_html/` (domain root) or into
  `public_html/<subfolder>/`.
- Make sure `.htaccess` arrived. It starts with a dot, so hidden-file views must
  be enabled (*File Manager → Settings → Show hidden files*, or `ls -a` over SSH).
- If the site lives in a **subfolder**, open `.htaccess` and uncomment
  `RewriteBase /subfolder/`.

Permissions: directories `755`, files `644`, and **`assets/uploads/` writable**
(`755` is enough when the files are owned by your Hostinger user — do not use
`777`).

**Do not upload:** `tools/`, `tests/`, `docs/`, the ZIP, `.git/`, `app/config.php` from another
site, `sql/` (optional, harmless if you leave it — `.htaccess` blocks it).

**Check:** `https://yourdomain.com/install.php` loads and step 1 shows every
requirement green.

---

## 5. Run the installer

Follow `INSTALL.md` §3 (four steps: welcome → database → owner account → done).

**Check:** you can sign in at `/login/` and `/dashboard/` loads with the review-card
empty state.

**Then, immediately:**

- [ ] **Delete `app/install.php`** (File Manager → delete). The wizard already
      disables itself, but delete it anyway.
- [ ] Confirm `app/config.php` exists and is `600`.

**Check:** `https://yourdomain.com/install.php` → 404. Done.

---

## 6. HTTPS

hPanel → **Security → SSL**:

- [ ] SSL is active for the domain (Hostinger issues free Let's Encrypt certs; the
      first issue can take 15–60 minutes).
- [ ] **Force HTTPS** is on.
- [ ] Open `http://yourdomain.com` → it must redirect to `https://`.

Then verify `config.php` has `'site_url' => 'https://…'` and that
**Owner panel → Settings → Site URL** matches. The QR codes and the Razorpay
callback both use it — a wrong scheme here produces QR links that break on phones.

**Check:** scan a review-card QR code with a phone on mobile data. The page must
open over `https`, show the logo, and reach the Google review link.

---

## 7. `.htaccess` verification

The rules are written for Apache 2.4 (`Require all denied`), which LiteSpeed
accepts. Test them from a browser — each line must return **404 or 403**, never
the file:

| URL | Expected |
|---|---|
| `/includes/db.php` | 404 |
| `/docs/` | 404 |
| `/tools/` | 404 (not uploaded anyway) |
| `/sql/schema.sql` | 404 |
| `/config.php` | 403 |
| `/config.example.php` | 403 |
| `/assets/uploads/../../../config.php` | 403/404 |
| `/dashboard/` | 200 or a redirect to login (clean URL works) |
| `/r/<your-slug>/` | 200 (the customer review page) |
| `/razorpay/webhook` | POST only — a GET should not return a page |

If `/dashboard/` 404s while `/dashboard.php` works, `mod_rewrite` is off or
`.htaccess` is missing/not allowed. If *every* URL 500s after uploading
`.htaccess`, the host is running Apache 2.2 — remove the `<IfModule mod_headers.c>`
block and replace `Require all denied` with `Order allow,deny / Deny from all`.

---

## 8. PHP mail / notifications

This build sends **no email** — there is no SMTP configuration and no password
reset (see `docs/STAGE-6-SECURITY.md` §9 for the reasoning). Nothing to set up,
but know it before a customer asks "I forgot my password": the owner resets a
password by editing the `users` row or by creating a new account.

WhatsApp is **not** an API integration: the buttons open `wa.me` links in the new
tab with a prefilled message. No tokens, no numbers to register.

---

## 9. AI provider (Groq or xAI / Grok)

Owner panel → **Settings → AI provider**. Choose **Groq** (the default) or **xAI
(Grok)**, paste that provider's key, and keep or change the model.

- Groq keys start with `gsk_`; the default model is `openai/gpt-oss-20b`, with
  `openai/gpt-oss-120b`, `qwen/qwen3.8-27b` and `allam-2-7b` also listed. Older
  Groq models such as `llama-3.1-8b-instant` are retired — if you type one the
  Test connection result says *model unavailable* and names it.
- xAI keys are created at `console.x.ai`; the endpoint is
  `https://api.x.ai/v1/chat/completions`.
- **The two are not interchangeable.** A Groq key sent to the Grok endpoint comes
  back as an invalid key — the most common mistake, and it is now reported as
  exactly that instead of "something went wrong".

**Test it:** press **Test connection** (owner panel → Settings → AI provider). It makes one real request and reports one
of: success (with the model and the latency), invalid key, model unavailable,
request failed, or timed out. The key is never shown again by any page or API
response — only its last four characters, for the owner's own sanity check.

**No panel needed (optional).** The key can also be baked into the server, so
nobody has to type it: put it in `includes/ai_key.php` (it already has a slot for
Groq and one for xAI). That file sits under `includes/`, which `.htaccess` answers
404 for, prints nothing, and is never sent to a browser or written to a log. A key
saved in the panel always wins over the baked-in one. Treat the ZIP like a
password while a key is inside it — and if the package has been passed around,
rotate the key in the console and paste the new one in.

**If the key is wrong, nothing breaks.** The customer page shows a short message
with a **Try again** button and the Google link keeps working; the customer can
always write their own review. There is no silent "template draft" any more.

Rate limits are per key: a busy counter during a promo can hit the provider's
limit, which the page reports as "busy right now — try again in a minute".

---

## 9a. Upgrading a site that is already live

This build replaces the customer review page, so do not cherry-pick single files —
copy the whole `app/` folder over the old one.

1. **Back up first** (§1): `public_html`, plus a database export.
2. Upload the contents of `app/` into `public_html/`, overwriting what is there.
   Keep your existing `config.php` and `assets/uploads/` — neither is in the ZIP
   and neither must be replaced.
3. There is **no database migration** in this release: no table, column or stored
   setting changed, so `sql/migrations/` is untouched and your data stays as it is.
4. Open each business's **Business Settings** and fill in the social links you
   want to show, then choose a **default draft language** (English, हिन्दी or
   తెలుగు). A button you leave empty is never drawn.
5. Owner panel → **Settings → Test connection** to confirm the AI key still works.
6. Hard-refresh the customer page on your phone (or open it in a private window):
   browsers cache CSS hard, and `customer-premium.css` is a new file.

If you upgrade only *some* files, `review.php` will fail to load — it needs
`includes/ai.php` and `includes/links.php`, which older builds did not ship.
Copy the whole folder.

---

## 10. Razorpay (only if you are selling plans)

Do this in **Test mode first**, even on the live domain.

1. Razorpay → **Settings → API keys → Generate Test key**. Copy the **Key ID** and
   **Key Secret**.
2. Razorpay → **Settings → Webhooks → Add**:
   - URL: `https://yourdomain.com/razorpay/webhook`
   - Secret: generate one (32+ random characters) and keep it
   - Events: `payment.captured`, `payment.failed`, `order.paid`
3. Owner panel → **Settings → Card payments**:
   mode = `Test` · Key ID · Key Secret · Webhook Secret · timeout 15s → **Save**.
4. Buy a plan with the test card `4111 1111 1111 1111`.

**Check:**
- membership page shows the paid plan + an expiry date,
- the card's **Transaction history** page (membership → *Transaction history*) lists the order with channel and mode = Test,
- Razorpay's webhook log shows `200` for `payment.captured`.

Then go live, deliberately:

5. Razorpay → switch the dashboard to **Live mode**, generate **Live** keys, add
   the live webhook (same URL, new secret).
6. Owner panel → Card payments: mode = `Live`, live Key ID + secret + webhook
   secret, and type `I_AUTHORIZE_REAL_CHARGES` in the confirmation box. Without
   that exact string the save is refused.
7. Make **one real ₹1-equivalent purchase** on your own plan and refund it, to
   prove the live chain end to end.

Never paste live keys into chat, a ticket, a screenshot, or the repo. They live
in the `settings` table only, and the screen that sets them never displays them
back.

---

## 11. Cron, backups, monitoring

| Task | How on Hostinger |
|---|---|
| Database backup | hPanel → *Files → Backups* → daily automatic; plus manual phpMyAdmin export before every update. |
| File backup | Same panel. Take one immediately after go-live. |
| Expiry of paid plans | **No cron required.** Expiry is computed from `expiry_date` at page load; an expired card simply shows the renewal state. |
| Webhook retries | Razorpay retries on failure; duplicate deliveries are deduplicated by `event_id`, so retries cannot double-activate a plan. |
| Error log | `public_html/error_log` or hPanel → *Advanced → Error logs*. |

---

## 12. Rollback

If something is wrong after go-live:

1. hPanel → *Files* → restore the `public_html` backup from §1.
2. phpMyAdmin → *Import* the `.sql` backup from §1.
3. Keep `config.php` — it is not in the backup unless you copied it, and the
   restored files will look for it.

Database and files must be restored **together**: a Stage-7 database with
Stage-6 files works (the extra tables are ignored), but Stage-7 files against a
Stage-6 database will fail on the missing `payments` table.

---

## 13. Post-deploy smoke test (five minutes)

Run these in order on the live domain, on a phone as well as a laptop:

1. `/` loads, no mixed-content warnings.
2. `/signup/` → create a throwaway business account → onboarding completes.
3. Create a review card with a logo → QR appears.
4. Scan the QR on a phone → the business's Instagram / Facebook / Website /
   WhatsApp buttons show **above** the stars (only the ones you filled in) →
   answer 5 stars → tap one topic → **Write my review** (the words box is
   optional; a topic alone is enough) → edit a draft → **copy** works → the
   Google link opens the right place.
5. Scan again → 2 stars → the private feedback form is offered and the AI writer
   stays available, submit → the entry shows in the dashboard.
6. Sign in as the owner → `/owner.php` opens; the throwaway business shows in the
   list.
7. Membership page → the plan and price are what you set; the WhatsApp button
   opens `wa.me` with a prefilled message.
8. If payments are on: one test purchase (or one live purchase you refund).
9. Delete the throwaway account and card when you are done.

---

## 14. If the site shows ERR_TOO_MANY_REDIRECTS

The installer used to redirect `install.php?step=3` back to itself whenever
`config.php` said `installed` but no `users` row matched `owner_email` — for
example when step 3 never finished, or when config.php came from a different
attempt than the database. Fixed in `install.php` (function
`rg_install_redirect_target`): step 3 now renders its form instead of looping.

If a site is already stuck in that state:

1. Upload the fixed `install.php`.
2. Open `/install.php?step=3` and create the owner account (or sign up at
   `/signup.php` with exactly the `owner_email` stored in `config.php`).
3. Delete `install.php` when you are done — you do not need it after install.

A 500 on `/` with a body that stops right after `<meta charset="UTF-8">` was a
second, unrelated bug: `includes/public_layout.php` called `csrfMeta()` without
loading `includes/security.php`. It now loads it itself, so the landing page
renders on a plain PHP host.

---

## What is deliberately not included

These were left out on purpose, not forgotten — see the stage reports in `docs/`:

- email (no SMTP, no password reset, no verification mail),
- multi-admin / roles,
- analytics dashboards, coupons, invoices, MFA, support tickets,
- automatic plan renewal / card-on-file / auto-debit,
- Google Business Profile API integration (owners paste their review URL),
- automatic posting of a review to Google — only the customer can publish it.
