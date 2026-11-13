# Popups, the homepage demo video, and the one-spin wheel

This release adds three features to the existing ReviewAI app. Nothing here is a
separate service: every campaign, video setting and spin is stored in the same
MySQL database and managed from the same owner panel.

## 1. Popup & Promotion Manager

**Owner panel:** Marketing → Popup & Promotions (`owner_marketing.php`).

| What the owner can do | Where it lives |
|---|---|
| Create, edit, publish, disable, delete a campaign | `owner_marketing.php` |
| Upload JPG/PNG/WebP artwork (max 4 MB, 1:2–2:1) | `assets/uploads/popup-*.{jpg,png,webp}` |
| Layouts: image, image + text, image + CTA, announcement | `popups.layout` |
| Heading, description, coupon code, CTA text/URL/target | `popups` |
| Trigger: immediate, delay, scroll, desktop exit intent | `popups.trigger_type` / `trigger_value` |
| Frequency: every visit, session, 24 h, 7 days, once per campaign | `popups.frequency` + `popup_views` |
| Page targeting: homepage, pricing, login, signup, all | `popups.targets` |
| Start/end date and time in a campaign timezone | `popups.starts_at`, `ends_at`, `timezone` |
| Status: draft, scheduled, active, expired, disabled | stored `status` + dates |
| Impressions, CTA clicks, coupon copies, dismissals, CTR | `popups.*`, `popup_views.*` |
| Desktop + mobile live preview | editor modal + `?action=preview` (iframe viewport) |

**Public pages:** `home.php`, `login.php` and `signup.php` are the only pages that
load the popup CSS, script or markup. Dashboards, the owner panel and the
customer QR/review flow (`review.php`) never include `includes/popups.php`, so a
promotion cannot appear there, whatever the database says.

**Rendering:** `print_rg_popup('home')` is called at the end of the body. The
server decides which single campaign is live for this page; the browser only
decides the trigger and keeps a local copy of the frequency rule.

**Analytics:** `popup_event.php` is POST-only, CSRF-checked and same-origin.
It records at most one of each event kind per campaign per PHP session:
`impression`, `click`, `coupon`, `dismiss`.

### Database upgrade

Fresh installs create everything in `install.php` / `sql/schema.sql`.

An existing install that already ran `2026-11-popups.sql` must run:

```
mysql -u USER -p DATABASE < sql/migrations/2026-11-popup-views.sql
```

It adds `popups.timezone`, `popups.coupon_copies`, `popups.dismissals` and the
`popup_views` table. Until it runs, the Marketing page shows a clear banner and
saves are refused rather than half-written.

## 2. Homepage demo video

**Owner panel:** Homepage Management → Demo Video (`owner_homepage.php`).

- The only input that reaches the embed is an 11-character YouTube id. The
  parser accepts `watch?v=`, `youtu.be/`, `/embed/`, `/shorts/`, `/live/`,
  `m.`/`music.` hosts and a bare id. Arbitrary iframe HTML is never accepted.
- The public section is printed after the hero and before “How it works”
  (`rg_home_video_render()` in `home.php`).
- The page ships a poster, not an iframe. `assets/js/home-video.js` creates the
  `youtube-nocookie.com` iframe only when the visitor presses play.
- Poster order: owner’s uploaded 16:9 thumbnail → YouTube preview → built-in
  ReviewAI card. A broken poster falls back to the card.
- “Watch on YouTube ↗” opens a new tab; “Quick 2-Minute Tutorial” is the note
  below it. The section honours `prefers-reduced-motion` and is responsive.
- Settings are stored as `home_video_*` rows in `settings`, so a save appears on
  the homepage without a redeploy.

## 3. Spin the Wheel

**What was wrong:** `wheel_spin.php` and `popup_event.php` checked the CSRF token
before starting the PHP session, so `csrfValid()` compared against nothing and
every spin was answered 403. Both endpoints now call `rg_start_session()` first.

**One spin per customer per campaign:**

- `wheel_participations` has `UNIQUE (card_id, participant_key)`; `wheel_spins`
  has `UNIQUE (campaign_id, session_key, spin_no)`.
- The participant key is a long-lived HttpOnly cookie (`rgp_<cardId>`), not
  localStorage or sessionStorage. Only its SHA-256 hash is stored.
- `rg_wheel_unlock_state()` is called by the server after a saved private review
  or private feedback. It is the only way `eligible` becomes 1.
- The prize is drawn on the server before the response is written; the browser
  animates to the returned segment index, so the animation always lands on the
  recorded prize.
- Concurrent requests are settled by
  `UPDATE wheel_spins SET status = 'spinning' WHERE id = ? AND status = 'pending'`
  inside a transaction. The loser gets `already_spun` with the recorded result.
- Refresh, reopen or re-scan returns `stage = done` and the same prize. The spin
  button stays disabled.

**Explicit completion:** on the review page, copying a draft or opening Google
saves the draft but does **not** unlock the wheel. “Send feedback privately”
(or the private feedback form for low ratings) is the explicit action that
unlocks it. Posting on Google is never required.

**Existing installs:** run `sql/migrations/2026-11-wheel-one-spin.sql` once. It
creates `wheel_participations` and adds `session_key` + the unique spin key to an
older `wheel_spins`.

**Limit:** a cookie identifies a browser, not a person. The only way to enforce
one spin across devices is a verified phone/OTP or login; the app does not claim
otherwise.
