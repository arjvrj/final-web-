# Customer feedback page — brand redesign

This pass restyles the page a customer opens from a QR code (`review.php`). It
does **not** rebuild the flow: the rating, private feedback, keyword picker,
AI draft, Google hand-off, one-spin wheel and every endpoint are unchanged.

## What changed

| Area | Change |
| --- | --- |
| Visual layer | New final stylesheet `assets/css/customer-experience.css`, loaded after `customer-reference.css` |
| Page canvas | Warm Cream `#FBF8F2` with soft lavender/lime radial washes and floating sparkle accents |
| Business profile | Centred logo (actual upload or up to two initials), business name and description, all loaded from the scanned card |
| Social row | Equal thumb-sized tiles for Google / Instagram / Website / WhatsApp; only configured links are rendered |
| Website icon | The business logo is used when the business uploaded one; never a generic globe in that case |
| Rating | Navy heading, Electric Purple emphasis, gold stars, full-width "Go directly to Google →" and the privacy line |
| Wheel | Purple/lavender/cream wedges, gold rim, gold pointer, ReviewAI-branded centre button, state-aware note and terms |
| Motion | Logo entrance, sparkle drift, star pop, link lift, step fade and the ready-state ring pulse |
| Mobile | Single column, no horizontal scroll, 40–48 px stars, tiles that fit four-to-a-row, wheel capped at 78 vw |

## Locked palette

| Token | Hex |
| --- | --- |
| Warm Cream | `#FBF8F2` |
| Electric Purple | `#4B20DC` |
| Deep Navy | `#141623` |
| Soft Lime | `#E7F3B7` |
| Off White | `#FFFDFB` |
| Lavender | `#F1EBFE` |
| Gold accent (wheel rim / stars) | `#E9B44C` |

No business name, logo or prize is hardcoded. `review.php` still builds the page
from `$card` and `$details`; missing logos fall back to the business's own
initials, and an empty social destination is never drawn.

## Wheel colours

The disc itself is server-rendered by `rg_wheel_svg()` in `includes/wheel.php`.
Its fills are now the same brand palette (`#4B20DC`, `#FBF8F2`, `#7B4BF5`,
`#F1EBFE`, `#3A16A8`, `#FFFDFB`, `#6E45E9`) with a `#E9B44C` rim. The colours are
presentation only — segment count, labels, odds, the server-side draw and the
one-spin rule are untouched.

## Copy the brief asks for

- `How was your experience?`
- `Your honest feedback matters — good or bad.`
- `Write your review to unlock your free spin.`
- `Your spin unlocks after you submit your review.`
- `Go directly to Google →`
- `Your words are only used to write your draft. Nothing is posted automatically.`

## Verification performed

- PHP lint: 51 files, 0 errors (including `review.php` and `includes/wheel.php`).
- CSS syntax: 22 stylesheets, 0 parse errors.
- Inline page JavaScript: syntax check passed.
- Server-rendered markup smoke test: no PHP warnings; 4 social tiles, 5 stars,
  one privacy note, 6 sparkles and the server-drawn wheel all present.
- Wheel rules and the one-spin flow were not changed.

Deployment is unchanged: upload the files, keep `assets/uploads/` writable, and
run the two migrations listed in `docs/POPUPS-VIDEO-AND-WHEEL.md` if the install
predates them. This visual pass adds no database changes.
