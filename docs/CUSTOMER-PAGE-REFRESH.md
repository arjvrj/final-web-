# Customer review page — UI/UX refresh

> The current brand pass is documented in `docs/CUSTOMER-PAGE-REDESIGN.md`.
> It supersedes the palette notes below; the functional fixes described here
> are still in place.

Third pass on `review.php`, the page a stranger opens from a QR code. It was
reviewed on a phone (the screenshot the business sent from the live site) and
three things were wrong.

## 1. The business logo was a grey slab

The stored logo is a path inside the app (`assets/uploads/…`). The page echoed it
raw, and when the file was not there the browser drew the **alt text** in a grey
box — which on a public page looks like a broken site.

Now, before the page is drawn:

```php
$logoUrl = '';
if (!empty($business['logo'])) {
    $logoFile = __DIR__ . '/' . ltrim((string)$business['logo'], '/');
    if (is_file($logoFile)) $logoUrl = url((string)$business['logo']);
}
```

- the file is checked, so a deleted upload cannot break the header;
- the URL goes through `url()`, so an install in a subfolder works;
- with no usable logo the header shows the business's **own initials** (up to two)
  on a violet disc instead of an empty frame.

## 2. Nothing said where the customer was

The "2" badge appeared on the topics block with no step 1 anywhere. There is now
a three-step rail above the flow — **Rate → Topics → Draft** — that JavaScript
moves as the customer advances (`rgSetStep`, called from `selectRating`,
`showPrivateFeedback`, the draft result and the feedback success). It is purely
visual: it gates nothing, and with JavaScript off step 1 stays highlighted, which
is where everyone starts.

## 3. The call to action was hard to read

Pale lavender with white text is about **2.2:1** contrast. The primary button is
now the same palette's deep violet — about **8:1** — and the disabled state is a
soft lilac pill with readable text plus one line of help underneath that says
what is missing ("Pick a topic or write a line to continue"). That line hides
itself the moment the button becomes usable.

## Everything else in the pass

| Before | Now |
| --- | --- |
| Business name in a 245px plate on a grey block | A full-width header card, logo disc overlapping the top edge |
| Topic chips were grey pills; a pick was hard to see | Selected chips fill violet, turn white and gain a ✓; a `0 / 3` counter chip fills as topics are picked |
| Three lines of grey help text under the chips | One sentence |
| Details box: long placeholder, floating grey counter | Short placeholder, counter as a chip, violet focus ring |
| Language was a bare label + select | A bordered row that matches the inputs |
| "Prefer to tell the business privately?" was an underlined link | A full-width outlined button |
| Name / phone fields were 40% wide | Full width |
| "Copy" under each draft was a small underlined link | A 34px outlined button |
| Missing Google link was a bare grey sentence | A soft amber notice with a marker |
| — | Draft cards get a violet ring when picked; radio uses the page's violet |

New file: `app/assets/css/customer-refresh.css`, loaded last, after `customer.css`
(generated, never edited) and `customer-premium.css` (the earlier premium layer).
Additive only, no CDN, no `@import`, same fonts and palette. `review.php` changed
in the head, the header, the progress rail, the topics heading, the CTA and five
small JavaScript hooks.

## Nothing functional moved

Same flow, same endpoints, same CSRF token, same "never posted automatically",
same text the customer can edit, same private-feedback behaviour at every rating.

## Proof

```
php-lint               43/43 files parse cleanly
check-links            every local asset reference resolves
test-review-flow       108/108   (stars, keyboard, chips, drafts, copy, private, Google)
test-social-links      27/0
test-public-csrf       12/12
test-review-endpoint   16/0
test-security          119/119
test-qr                11/0
verify-assets          homepage 0 diffs, dashboard 0 diffs
audit-dashboard        0 undocumented classes, 0 remote assets
```

Screenshots (regenerate with `node tools/render-page.mjs review` and the driver in
`docs/screenshots/`, which is a build artefact and not in the ZIP):

```
reviews-before-mobile.png / review-after-mobile.png
review-after-drafts-mobile.png      the drafts step
review-after-private-mobile.png     1–3 star private form
review-after-desktop.png
```

## One thing only the business can fix

The live page said **"The Google review link is not available"**. That is not a
bug in the page: the card has no Google review URL saved. Dashboard → Settings →
Google review link, save, and the button and the direct link appear. The notice
stays until then, by design — the page never sends a customer to a generic Google
search for someone else's business.

## Cream finish (matching the approved design)

A fourth stylesheet, `app/assets/css/customer-cream.css`, is loaded last on
`review.php` only and moves the page onto the approved palette:

| Token | Value | Used for |
|---|---|---|
| primary | `#5425E8` | links, chips text, step badge, buttons |
| deep | `#4820D8` | button gradient end |
| navy | `#151832` | headings, business name, field labels |
| cream | `#FBF8F2` | page background |
| lavender | `#F0E9FF` | chips, name pill, current step |
| gold | `#FFB51B` | stars |
| line | `#EAE3F8` | borders |

What it changes: the cream page with soft violet glows, one white rounded card
with a soft lavender shadow, the round gradient logo above the business-name
pill, lavender topic chips with violet text, cream text fields, the violet
gradient main button, gold stars, and the private-feedback link as an underlined
link instead of a second button.

What it does **not** change: any markup, any behaviour, any dashboard page. The
QR slug, five stars, ≤3 topics, the 600-character box, the language picker, the
AI drafts, the private feedback form, the Google link and the wheel are all the
same code. No new fonts, no CDN, no image files — the glows are CSS gradients.

The dashboard keeps its own palette; this file is never loaded there
(`verify-assets` still reports 0 diffs on the homepage and dashboard).

## Reference-matched finish (`customer-reference.css`)

The last visual layer, loaded only by `review.php`, retunes the page to the
approved reference screenshot:

| Token | Value |
|---|---|
| page background | `#FFF9F0` |
| card background | `#FFFCF7` (92% opaque, blurred) |
| primary purple | `#6529ED` |
| secondary purple | `#9B5CFF` |
| lavender | `#F2E8FF` |
| border | `#E9D8FA` |
| main text | `#17164F` |
| secondary text | `#645A99` |
| stars | `#FFAC21` |

- **Background:** cream page, purple circle in the bottom-left corner, lavender
  circle in the top-right corner, faint diagonal wash, ambient glow behind the
  card and a slowly drifting sparkle layer. All CSS + one inline SVG, no images,
  no CDN, `pointer-events: none`.
- **Card:** 460 px wide, 35 px radius, thin lavender border, soft purple glow,
  fade-and-rise entrance. On phones it fills the width instead.
- **Header:** back arrow, purple sparkle + **ReviewAI** wordmark centred; the
  business name stays in its own lavender pill below, straight from the database.
- **Type:** 34 px navy heading on desktop, 28 px on phones.
- **Stars:** 44 px, gold `#FFAC21` when chosen, lavender outline when not, hover
  lift and a small pop on selection.
- **Chips:** soft white/lavender, selected chips take the purple gradient with a
  check mark; the count and the helper sentence update live.
- **Button:** 48 px pill, `#6529ED → #9B5CFF`, lift on hover, press on click,
  sparkle prefix, disabled state stays quiet.
- **Wheel:** violet/cream wedges, gold rim, purple centre badge, "Spin the
  **Wheel!**" with the second word in gradient — matching the second reference
  image. A locked wheel stays in colour; only the button says it is waiting.
- **Motion:** honour `prefers-reduced-motion` — the card, logo, sparkle drift and
  all transitions switch off.

Copy now matches the brief: rating messages ("We're sorry. What went wrong?" …
"Excellent! What did you love?"), "What stood out? (pick up to 3)", the
"0 of 3 selected…" helper, and "The draft is written from the topics you pick…".
