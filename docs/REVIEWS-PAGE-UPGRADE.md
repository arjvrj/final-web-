# Reviews & Feedback page — UI/UX upgrade

Post-delivery improvement, requested after the first deployment:

1. *"now we also need to change the ui ux of it right bro"* — the Reviews page was
   still the old flat layout (five plain numbers, a blue button pair, a bare
   "No reviews found" line).
2. *"the review are just normal like atleast use 2 emojis bro"* — AI drafts were
   correct but plain.

Nothing was removed: same URL, same filters, same search, same modals, same
delete / mark-read endpoints, same colours and typography as the rest of the
dashboard.

## 1. What the page looks like now

Added (`app/assets/css/dashboard-reviews.css`, hand-written, no CDN, no `@import`):

| Block | What it does |
| --- | --- |
| `.rg-stats` / `.rg-stat` | Five stat tiles with a coloured edge, an icon disc and one line of plain English: Total reviews, Average rating (with a ★ row), Happy 4–5★, Low 1–3★, Private feedback. |
| `.rg-tabs` / `.rg-tab` / `.rg-tab-count` | The two filters are now a segmented control that shows the counts, and the active one is filled. |
| `.rg-search` / `.rg-search-clear` | Icon search field plus a clear button that only appears when a search is active. |
| `.rg-table` | Both tables: initials avatar, name, 5-star glyph row, a two-line review snippet, an `AI draft` / `Own words` chip and right-aligned View / Delete (and Mark read). |
| `.rg-empty` | Real empty states with a disc icon, a sentence of explanation and two actions: **Copy link** and **Open review page**. |
| `.rg-chip.is-ai` `.is-own` `.is-new` `.is-read` | Small status pills. |
| `.rg-visually-hidden`, `.rg-person-contact` | Accessibility label + the email/phone lines under the customer name. |

Below 760px every table row becomes a stacked card (`data-label` on each cell),
so the page reads top-to-bottom on a phone without horizontal scrolling.

**Font Awesome** (already inside the package at `assets/vendor/fontawesome/`) is
now linked on this page only, for the icons. `check-links` confirms the path
resolves; `audit-dashboard` counts those classes as vendored, not unknown.

## 2. How a page asks for an extra stylesheet

`includes/dashboard_layout.php` gained one opt-in option:

```php
echo dashboardShell($content, [
    'title'  => 'Reviews',
    'crumb'  => 'Reviews',
    'page'   => 'reviews',
    'card'   => $card,
    'styles' => $pageStyles,   // path relative to /assets/
]);
```

`card_reviews.php` sets `$pageStyles = ['css/dashboard-reviews.css', 'vendor/fontawesome/css/all.min.css']`
and `includes/card_layout.php` passes it through. No other page loads extra CSS,
so the other dashboard pages are byte-for-byte what they were.

## 3. AI drafts now use emojis — asked for, and guaranteed

`rg_ai_build_prompt()` in `includes/ai.php`, system rule 4:

> EVERY draft must contain at least two emojis, and never more than three. Pick
> ones that match what the customer mentioned, and place them where a real person
> would — not bunched together, and never as the first character. No star ratings,
> no hashtags, no quotation marks around the draft, no headings.

That rule alone was not enough. With a softer wording ("use 2 or 3 emojis") the
model returned one; with the hard wording it complied on some runs
(2·2·3, 3·2·3) and still returned a single emoji on another. A prompt is a
request, not a guarantee, so the rule is also enforced in code:

```php
// rg_ai_generate_reviews(), after rg_ai_parse_reviews()
$rating = (int)($input['rating'] ?? 5);
foreach ($reviews as $i => $draft) {
    $reviews[$i] = rg_ai_ensure_emojis($draft, 2, 3, $rating);
}
```

`rg_ai_ensure_emojis()`:

- counts emojis as whole clusters (a ZWJ family emoji counts once);
- does nothing when the draft already has 2 or 3;
- adds emojis **only at the end** when the draft has 0 or 1 — the customer's own
  words are never re-worded, re-ordered or padded;
- never repeats an emoji already in the draft;
- drops extras past the third when a model overdoes it;
- uses a calm set (🙂 🙏 👌 😕) for 1–3 stars and a warm set (😊 👍 ✨ 🙌 💚) for 4–5,
  so a complaint never ends in party emojis.

Counted, added and trimmed by `rg_ai_count_emojis()` / `rg_ai_emoji_pattern()`.
Ten unit checks cover it (see §4).

## 4. Proof

Live calls to Groq (`openai/gpt-oss-20b`, the app's own prompt builder, the key in
`includes/ai_key.php`):

| Run | Model gave | After `rg_ai_ensure_emojis()` |
| --- | --- | --- |
| English, 5★ | 2 · 2 · 3 | 2 · 2 · 3 (untouched) |
| English, 5★ (repeat) | 3 · 2 · 3 | 3 · 2 · 3 (untouched) |
| English, 5★ (third run) | 1 · 1 · 1 | **2 · 2 · 2** — topped up, wording unchanged |
| English, 2★ with details | 2 · 2 · 2 (😓🚿 😔🚿 😟🚿) | 2 · 2 · 2 (untouched) |
| Hindi | 3 drafts, native script | 200 OK |
| Telugu | 3 drafts, native script | 200 OK |

The fourth row is the interesting one: the model picked the emojis itself and they
fit the complaint. The third row is why the code fallback exists.

The last two rows of the table below were run again on the helper's own suite:

```
test-ai-provider      169/0   (was 159 before the emoji helper)
```

Test suites after the change (all run on this tree):

```
php-lint               43/43 files parse cleanly
check-csrf-includes    24/24
check-links            every local asset reference resolves
check-install-css      PASS
audit-dashboard        0 undocumented classes, 0 remote assets
verify-assets          0 diffs (homepage, dashboard)
test-dashboard-flow    54/54   (View modal, Escape, Delete → review_delete.php)
test-admin-flow        51/51
test-ai-provider       169/0
test-review-endpoint   16/0
test-review-flow       108/108
test-security          119/119
test-install-guard     37/37
test-social-links      27/0
test-public-csrf       12/12
test-qr                11/0
```

Screenshots (desktop 1280px and phone 390px) are regenerated by `node tools/render-page.mjs
card-reviews card-reviews-feedback` into `docs/screenshots/` (that folder is a build
artefact, so it is not inside the ZIP):

```
reviews-card-reviews-desktop.png     reviews-card-reviews-mobile.png
reviews-card-reviews-feedback-desktop.png   reviews-card-reviews-feedback-mobile.png
```

## 5. Two fixes found while doing this

- **Initials helper.** The avatar used `mb_substr` / `mb_strtoupper` directly, so
  the page died with *Call to undefined function* on any host without the mbstring
  extension. It is now a small guarded helper (`$rgInitials`) that falls back to
  `substr` / `strtoupper`, and returns `?` for an empty name.
- **Table width on phones.** `dashboard-green.css` caps panel tables at
  `min-width: 860px` (`.panel .billing-scroll table`). That kept the "cards" 860px
  wide on a 390px phone and clipped the review text. The reviews stylesheet now
  resets it inside the small-screen media query, at the same specificity.
