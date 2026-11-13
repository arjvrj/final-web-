# INSTALL — from an empty hosting account to a working site

This is the manual for `app/` as shipped. It works the same on Hostinger, on any
cPanel host, or on a laptop. For the Hostinger-specific go-live checklist see
`HOSTINGER-DEPLOYMENT.md`.

**Time:** about 15 minutes, most of it waiting for DNS or the database.

---

## 0. What you need before you start

| Requirement | Detail |
|---|---|
| PHP | **8.1 or newer**. 8.2 / 8.3 are fine. |
| PHP extensions | `pdo_mysql`, `curl`, `json`, `openssl`, `mbstring`, `fileinfo` |
| Database | MySQL 5.7+ or MariaDB 10.2+, `utf8mb4` |
| HTTPS | a certificate. The customer review page is opened from a QR code on a phone; Google review links and copy-to-clipboard need a secure origin. |
| Optional | a GROQ API key (AI review text) · a Razorpay account (online payments) |

Everything else — Font Awesome, the fonts, the JS libraries — is already inside
`app/assets/`. **Nothing is loaded from a CDN**, so the site works with no
outbound requests except to the AI and payment providers when you enable them.

---

## 1. Upload

Upload the **contents of the `app/` folder** into `public_html` (or into a
subfolder such as `public_html/review` — the application detects its own base
path).

Do **not** upload `tools/`, `tests/`, `docs/`, or the ZIP itself. `sql/` is only needed for a
manual database build; uploading it is harmless but unnecessary.

After the upload, `public_html` should look like this:

```
public_html/
  index.php  login.php  signup.php  onboard.php  dashboard.php  review.php …
  install.php            ← delete this when you are done
  config.php             ← created by the installer
  includes/  assets/  .htaccess
```

**Permissions:** folders `755`, files `644`. One folder must be writable:
`assets/uploads/` (business logos). On Hostinger `755` owned by your user is
enough; do not use `777`.

---

## 2. Create the database

hPanel → **Databases → MySQL Databases** (cPanel: *MySQL® Databases*):

1. Create a database, e.g. `u123456789_reviewgrow`.
2. Create a user, e.g. `u123456789_owner`, with a long random password.
3. Add the user to the database with **all privileges**.
4. Note the three values. On Hostinger the host is `localhost`.

Write the password down somewhere safe *before* you close the page — you will
paste it into the installer, and into `app/config.php` later if you need to
recreate it.

---

## 3. Run the installer

Open `https://yourdomain.com/install.php`.

### Step 1 — Welcome
A short welcome list (you will need the database credentials, an app name and an
owner email/password). It does **not** test your server — check PHP 8.1+ and the
extensions yourself (§0, or hPanel → PHP Configuration) before you start.

### Step 2 — Database and site
Enter the database host, name, user, password, and the site URL
(pre-filled correctly — check that it says `https://`). Submit.

The installer then:
- creates the 10 tables (`users`, `review_cards`, `reviews`, `feedbacks`,
  `platform_settings`, `plans`, `keywords`, `settings`, `payments`,
  `webhook_events`),
- seeds default settings — **payments start `disabled`**,
- seeds eight starter review keywords,
- writes `app/config.php`.

If a table already exists it is left alone; nothing is dropped.

### Step 3 — Owner account
Your email and a password (minimum 8 characters). This single account is the
**site owner**: it is the only account that can open `owner.php`. The email is
stored in two places — `config.php` and the `owner_email` setting — because the
dashboard's "am I the admin?" check reads the setting.

### Step 4 — Done
The wizard disables itself (any further request redirects to the login page).

> **Delete `app/install.php` now.** The wizard refuses to run twice, but the file
> has no business sitting on a production server. This is the one step people
> skip.

---

## 4. First sign-in and the ten-minute setup

Sign in at `/login/`. You land on `/dashboard/` (business view). The owner panel is
`/owner.php` — its nav is **Dashboard · Review cards · Users · Plans · Settings**.

1. **Owner panel → Settings → WhatsApp payments** — this platform WhatsApp number
   is required and is the number customers reach when they pick the WhatsApp route
   on the membership page.
2. **Owner panel → Settings → App name** — what the header and page titles say.
3. **Owner panel → Settings → GROQ API key** — paste the key. Until you do, the
   review page falls back to a template draft built from the chosen topics; it does
   not error, it just reads less naturally. Model default
   `llama-3.3-70b-versatile`.
4. **Owner panel → Plans** — create at least one paid plan (name, price in ₹,
   duration in days). With no plan a business cannot buy anything; the free trial
   still works.
5. **Dashboard → New review card** — name, description, Google review URL, logo,
   theme. The card produces a QR code and a short link `/r/<slug>/`.
6. **Card → Settings → Topics** — the per-card keyword list the AI draft draws on
   (the chips the customer can pick on the review page). The installer also seeds a
   global list of eight starter words into the `keywords` table, used as a fallback
   when a card has no topics of its own. That global list has **no editor screen** —
   change it with one `UPDATE` in phpMyAdmin if you need different defaults.
7. **Print the QR code**, put it on the counter, scan it with a phone and walk the
   customer flow end to end once. This is the only way to be sure the logo, the
   Google link and the AI draft all behave.

---

## 5. Payments (optional)

Payments ship **off**. Turning them on:

1. Razorpay dashboard → **Settings → API keys** → generate a **Test mode** key
   pair first.
2. Owner panel → Settings → **Card payments**: mode = `Test`, paste the
   **Key ID** (starts `rzp_test_`), paste the **Key Secret**, paste the **Webhook
   Secret** you set in Razorpay (Razorpay → Settings → Webhooks → add
   `https://yourdomain.com/razorpay/webhook` with the `payment.captured`,
   `payment.failed`, `order.paid` events).
3. Save. Both secrets are write-only: after saving, the fields show a masked
   placeholder and the stored value is never printed back to the browser.
4. Buy one plan yourself with a Razorpay test card (`4111 1111 1111 1111`,
   any future expiry, any CVV). Check that:
   - the membership page switches to the paid plan and shows an expiry date,
   - the card's **Transaction history** (membership page → *Transaction history**) lists the payment with mode = Test,
   - Razorpay's webhook log shows a `200`.

Switching to **Live** requires typing `I_AUTHORIZE_REAL_CHARGES` into the
confirmation box in the same screen. Without that exact string the save is
rejected — a deliberate speed bump so nobody goes live by accident.

Full detail, including the signature verification chain and the failure cases
that were tested: `docs/STAGE-7-PAYMENTS.md`.

---

## 6. Manual install (no installer)

If the browser installer is blocked, build the database from SQL and write the
config by hand:

```bash
mysql -u USER -p DBNAME < sql/schema.sql
```

**Want demo data to click through first?** After the schema, import
`sql/seed-demo.sql` and sign in as `demo@reviewgrow.test` / `Demo12345!`
(paid business) or `salon@reviewgrow.test` / `Demo12345!` (trial business). It
creates two businesses, two cards, four reviews, two private feedbacks, three
plans, the eight keywords and one settled test-mode payment. It never switches
payments on. **Do not import it on a live site, and change or delete those
accounts before real customers exist** — the passwords are published in the file.
Removal statements are at the bottom of the file.

`sql/archive/` holds a superseded design from before the payments work was
integrated. **Do not import anything from it** — its `payments` table has
different columns and `CREATE TABLE IF NOT EXISTS` would block the correct one.

Then seed the settings the schema cannot know:

```sql
INSERT INTO settings (setting_key, setting_value) VALUES
  ('business_name','Your Business'),
  ('app_name','ReviewAI'),
  ('site_url','https://yourdomain.com'),
  ('owner_email','you@yourdomain.com'),
  ('installed','1'),
  ('payments_mode','disabled'),
  ('razorpay_key_id',''), ('razorpay_key_secret',''), ('razorpay_webhook_secret',''),
  ('razorpay_timeout','15'),
  ('theme','purple'), ('custom_primary','#7c3aed'),
  ('custom_gradient','linear-gradient(135deg, #7c3aed 0%, #4f46e5 100%)'),
  ('enable_keywords','1'), ('groq_api_key',''), ('groq_model','llama-3.3-70b-versatile')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
```

Create the owner account through `/signup/` **first**, then set `owner_email` to
that address — the owner check is by email, and an account created without a
matching `owner_email` gets no admin rights. (`sql/schema.sql` seeds every key
with an empty/neutral default, so the INSERT above is only needed if you want
non-default values.)

Finally copy `app/config.example.php` to `app/config.php`, fill in the four
database values plus `site_url`, `app_name`, `owner_email`, and set
`'installed' => true`.

---

## 7. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| Blank page, HTTP 500 | PHP version < 8.1, or `pdo_mysql` missing. Check the host's error log. |
| "Database connection failed" | Wrong `db_host`/`db_name`/`db_user` in `config.php`; on Hostinger the DB user must be *attached* to the database. |
| Everything redirects to `install.php` | `config.php` is missing or `installed` is not `true`. |
| `/dashboard/` 404s but `/dashboard.php` works | `mod_rewrite` is off, or `.htaccess` was not uploaded (hidden files are often skipped by FTP clients — use File Manager or `scp`). |
| Logos upload but do not display | `assets/uploads/` is not writable, or the folder was uploaded with wrong ownership. |
| AI draft is generic | no GROQ key, invalid key, or the model name is not available on your GROQ plan. |
| Razorpay popup does not open | payments mode is `disabled`, or the key ID and secret are from different modes (one test, one live). |
| Razorpay says "Authentication failed" | wrong key pair. The secret must come from the same dashboard and the same mode as the key ID. |
| Payment succeeds but membership stays free | the webhook secret is wrong, so the async confirmation is rejected. The browser-side verification normally still activates the plan — check the card's **Transaction history** page for the row and its status. |
| ₹ symbol renders as a box | the page is not UTF-8. `.htaccess` sets `AddDefaultCharset UTF-8`; check that the host is not overriding it. |

---

## 8. What to delete / keep after install

| Path | After install |
|---|---|
| `app/install.php` | **delete** |
| `app/config.php` | keep, chmod 600, never commit |
| `app/config.example.php` | keep (harmless; not served — `.htaccess` denies it) |
| `sql/` | may delete from the server, keep in your archive |
| `tools/`, `tests/`, `dev/`, `docs/` | never upload |
