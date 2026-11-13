<?php
/**
 * review.php — Public customer-facing review page + inline AJAX handlers
 *
 * GET  review.php?slug=<slug>            → renders the page
 * POST review.php?action=generate        → returns JSON { reviews[], google_review_url }
 * POST review.php?action=submit          → saves chosen AI review
 * POST review.php?action=feedback        → saves low-rating feedback
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/wheel.php';

/* The review form posts with fetch(), so the visitor gets a session cookie and a
   CSRF token even though they are not logged in. */
rg_start_session();

/* =========================================================
   AJAX DISPATCHER (runs before any HTML is emitted)
   ========================================================= */
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action !== '' && $action !== 'render') {
    header('Content-Type: application/json');

    $slug = trim($_POST['slug'] ?? $_GET['slug'] ?? '');
    $card = $slug ? getCardBySlug($slug) : null;

    if (!$card) {
        echo json_encode(['error' => 'Card not found']);
        exit;
    }

    /* Expired card → block all actions */
    if (!empty($card['expiry_date']) && strtotime($card['expiry_date']) < time()) {
        echo json_encode(['error' => 'This review page is no longer active.']);
        exit;
    }

    $details = json_decode($card['details'] ?? '{}', true);
    if (!is_array($details)) $details = [];

    /* ---------- ACTION: generate AI reviews (direct Groq call) ---------- */
    if ($action === 'generate') {
        requireCsrf();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['error' => 'Method not allowed']);
            exit;
        }

        /* AI generation goes through includes/ai.php: one provider path, one set
           of timeouts, one set of error codes. This block used to hold its own
           cURL call with a hardcoded model. */
        $cfg = rg_ai_config(rg_ai_settings_from_db());

        if (!$cfg['configured']) {
            rg_ai_log('customer generate: no key configured for provider ' . $cfg['provider']);
            echo json_encode([
                'success' => false,
                'code'    => 'not_configured',
                'error'   => rg_ai_customer_message('not_configured'),
            ]);
            exit;
        }

        $keywords = $_POST['keywords'] ?? [];
        if (is_string($keywords)) $keywords = json_decode($keywords, true);
        if (!is_array($keywords)) $keywords = [];
        $keywords = array_values(array_filter(array_map('trim', $keywords)));

        /* No keywords from the customer means no keywords in the prompt. We used
           to fall back to three invented ones, which told the model to claim
           things the customer never mentioned. */
        if (empty($keywords)) {
            $keywords = $details['business_keywords'] ?? [];
            if (!is_array($keywords)) $keywords = [];
            $keywords = array_values(array_filter(array_map('trim', $keywords)));
        }

        /* The customer's own words: the only factual input the model gets. */
        $customerDetails = trim((string)($_POST['details'] ?? ''));
        if (function_exists('mb_substr')) {
            $customerDetails = mb_substr($customerDetails, 0, 800, 'UTF-8');
        } else {
            $customerDetails = substr($customerDetails, 0, 800);
        }

        /* Keywords are the normal path, so a request is refused only when the
           customer gave nothing at all: no topics and no words. */
        $detailsLength = function_exists('mb_strlen')
            ? mb_strlen($customerDetails, 'UTF-8')
            : strlen($customerDetails);
        if (empty($keywords) && $detailsLength < 15) {
            echo json_encode([
                'success' => false,
                'code'    => 'bad_request',
                'error'   => 'Pick at least one topic, or write a few words about your visit.',
            ]);
            exit;
        }

        $language = strtolower(trim((string)($_POST['language'] ?? ($details['language'] ?? 'en'))));
        if (!array_key_exists($language, rg_ai_languages())) $language = 'en';

        $result = rg_ai_generate_reviews($cfg, [
            'business_name' => $card['name'],
            'business_type' => $details['business_type'] ?? '',
            'rating'        => max(1, min(5, (int)($_POST['rating'] ?? 5))),
            'keywords'      => $keywords,
            'details'       => $customerDetails,
            'language'      => $language,
            'count'         => 3,
        ]);

        if (!$result['ok']) {
            echo json_encode([
                'success' => false,
                'code'    => $result['error'],
                'error'   => rg_ai_customer_message((string)$result['error']),
            ]);
            exit;
        }

        /* A generated draft is NOT a completed feedback round, so it must not
           unlock the wheel. rg_wheel_state() only reports what the server has
           already recorded; if this customer was unlocked earlier, they keep
           that state. */
        $wheelAnswer = rg_wheel_state($conn, (int)$card['id']);

        echo json_encode([
            'success'           => true,
            'reviews'           => $result['reviews'],
            'google_review_url' => rg_safe_url((string)($details['google_review_url'] ?? ''), 'google_review_url'),
            'wheel'             => $wheelAnswer,
        ]);
        exit;
    }

    /* ---------- ACTION: submit chosen review ---------- */
    if ($action === 'submit') {
        requireCsrf();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['error' => 'Method not allowed']);
            exit;
        }

        $rating = max(1, min(5, (int)($_POST['rating'] ?? 5)));
        $name   = trim($_POST['customer_name'] ?? 'Anonymous');
        $body   = trim($_POST['review_body'] ?? '');

        if ($body === '') {
            echo json_encode(['error' => 'Review body required']);
            exit;
        }

        try {
            $stmt = $conn->prepare("
                INSERT INTO reviews (card_id, customer_name, rating, body, details)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $card['id'],
                $name !== '' ? $name : 'Anonymous',
                $rating,
                $body,
                json_encode(['ai_generated' => true])
            ]);

            $reviewId = (int)$conn->lastInsertId();

            /* Only the explicit "send feedback privately" action unlocks. A
               draft copied on the way to Google is still saved (so nothing is
               lost), but it is not a completed feedback round. */
            $wheelAnswer = !empty($_POST['unlock'])
                ? rg_wheel_unlock_state($conn, (int)$card['id'], 'review')
                : rg_wheel_state($conn, (int)$card['id']);

            echo json_encode([
                'success' => true,
                'id'      => $reviewId,
                'wheel'   => $wheelAnswer,
            ]);
        } catch (Exception $e) {
            echo json_encode(['error' => 'Could not save review.']);
        }
        exit;
    }

    /* ---------- ACTION: submit low-rating feedback ---------- */
    if ($action === 'feedback') {
        requireCsrf();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['error' => 'Method not allowed']);
            exit;
        }

        $rating  = max(1, min(5, (int)($_POST['rating'] ?? 3)));
        $name    = trim($_POST['customer_name'] ?? 'Anonymous');
        $phone   = trim($_POST['phone'] ?? '');
        $message = trim($_POST['message'] ?? '');

        if ($message === '') {
            echo json_encode(['error' => 'Message required']);
            exit;
        }

        try {
            $stmt = $conn->prepare("
                INSERT INTO feedbacks (card_id, customer_name, phone, email, message, rating, status)
                VALUES (?, ?, ?, '', ?, ?, 'new')
            ");
            $stmt->execute([
                $card['id'],
                $name !== '' ? $name : 'Anonymous',
                $phone,
                $message,
                $rating
            ]);

            $feedbackId = (int)$conn->lastInsertId();

            /* A saved private feedback is a completed feedback round, so it
               unlocks the one spin. The server records this; the browser cannot. */
            $wheelAnswer = rg_wheel_unlock_state($conn, (int)$card['id'], 'feedback', $feedbackId);

            /* Keep the feedback and the prize on the same row of history. */
            try {
                $part = rg_wheel_participation($conn, (int)$card['id']);
                if ($part && empty($part['feedback_id'])) {
                    $link = $conn->prepare('UPDATE wheel_participations SET feedback_id = ?, updated_at = NOW() WHERE id = ?');
                    $link->execute([$feedbackId, (int)$part['id']]);
                }
            } catch (Throwable $e) {
                /* The feedback is saved either way; this is only a cross-link. */
            }

            echo json_encode(['success' => true, 'id' => $feedbackId, 'wheel' => $wheelAnswer]);
        } catch (Exception $e) {
            echo json_encode(['error' => 'Could not save feedback.']);
        }
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}

/* =========================================================
   RENDER THE PAGE
   ========================================================= */

$slug = trim($_GET['slug'] ?? '');
$card = $slug ? getCardBySlug($slug) : null;

if (!$card) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
<?= csrfMeta() ?>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Page Not Found</title>
        <link rel="stylesheet" href="<?= e(asset('css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/customer.css')) ?>">
    </head>
    <body>
        <main class="customer-shell">
            <div class="brand-box"><div class="logo-disc"><span aria-hidden="true">✦</span></div>
                <h1>Review page not found</h1>
                <p>This review link is not valid. Please check the link you were given.</p>
            </div>
            <a class="direct" href="<?= e(url('/')) ?>">Go to the website ↗</a>
        </main>
    </body>
    </html>
    <?php
    exit;
}

$isExpired  = false;
$expiryDate = null;
if (!empty($card['expiry_date'])) {
    $expiryDate = new DateTime($card['expiry_date']);
    $now        = new DateTime();
    $isExpired  = $expiryDate < $now;
}

$details = json_decode($card['details'] ?? '{}', true);
if (!is_array($details)) $details = [];

/* Spin the Wheel: only shown when the business has it on, the dates are open and
   the odds add up. Nothing about the prizes is drawn client-side. */
$wheelOpen = false;
$wheelToken  = '';         // token of the one spin, once it is unlocked
$wheelStage  = 'locked';   // locked | ready | done
$wheelResult = null;       // the prize, once it has been spun
$wheelSource = '';         // what made this customer eligible
$wheelSegments = [];
$wheelSvg = '';
$wheelOddsText = '';
try {
    $wheelCampaign = rg_wheel_campaign($conn, (int)$card['id'], false);
    /* An expired card cannot collect feedback, so it cannot hand out spins
       either: no point showing a wheel nobody can unlock. */
    if ($wheelCampaign && !$isExpired && rg_wheel_is_open($wheelCampaign)) {
        $wheelCheck = rg_wheel_validate($wheelCampaign['segments']);
        if ($wheelCheck['ok']) {
            $wheelSegments   = rg_wheel_public_view($wheelCampaign['segments']);
            $wheelOpen       = count($wheelSegments) >= 2;
            $wheelOddsText   = rg_wheel_odds_line($wheelCampaign['segments']);
            $wheelSvg        = $wheelOpen ? rg_wheel_svg($wheelSegments) : '';

            /* Where is this customer in the journey? rg_wheel_state() reads the
               participation record and the spin row from the database, so a
               refresh, a second tab, a reopened link or a restarted browser all
               resume the same state. A stale pending token is rotated there. */
            if ($wheelOpen) {
                $wheelState  = rg_wheel_state($conn, (int)$card['id']);
                $wheelStage  = (string)$wheelState['stage'];
                $wheelToken  = (string)($wheelState['token'] ?? '');
                $wheelResult = $wheelState['result'] ?? null;
            }
        }
    }
} catch (Throwable $e) {
    $wheelOpen = false;   // no campaigns table yet, or a database hiccup: no wheel
}

$business = [
    'name'                     => $card['name'],
    'logo'                     => $details['logo'] ?? '',
    'description'              => $details['description'] ?? '',
    'whatsapp'                 => $details['whatsapp'] ?? '',
    'facebook'                 => $details['facebook'] ?? '',
    'instagram'                => $details['instagram'] ?? '',
    'website'                  => $details['website'] ?? '',
    /* No fallback: an unconfigured link stays empty so the button is not drawn.
       The old default sent customers to a generic Google search, which is not
       this business. */
    'google_review_url'        => rg_safe_url((string)($details['google_review_url'] ?? ''), 'google_review_url'),
    'business_keywords'        => $details['business_keywords'] ?? ['professional', 'quality', 'excellent service', 'friendly staff', 'clean', 'affordable', 'great value'],
    'enable_keyword_selection' => $details['enable_keyword_selection'] ?? true,
];

$themes = [
    'purple' => ['name' => 'Purple', 'primary' => '#7c3aed', 'primary_light' => '#8b5cf6', 'gradient_style' => 'background: linear-gradient(135deg, #7c3aed 0%, #4f46e5 100%);'],
    'blue'   => ['name' => 'Blue',   'primary' => '#2563eb', 'primary_light' => '#3b82f6', 'gradient_style' => 'background: linear-gradient(135deg, #2563eb 0%, #0891b2 100%);'],
    'pink'   => ['name' => 'Pink',   'primary' => '#db2777', 'primary_light' => '#ec4899', 'gradient_style' => 'background: linear-gradient(135deg, #db2777 0%, #e11d48 100%);'],
    'orange' => ['name' => 'Orange', 'primary' => '#ea580c', 'primary_light' => '#f97316', 'gradient_style' => 'background: linear-gradient(135deg, #ea580c 0%, #d97706 100%);'],
    'green'  => ['name' => 'Green',  'primary' => '#059669', 'primary_light' => '#10b981', 'gradient_style' => 'background: linear-gradient(135deg, #059669 0%, #047857 100%);'],
    'red'    => ['name' => 'Red',    'primary' => '#dc2626', 'primary_light' => '#ef4444', 'gradient_style' => 'background: linear-gradient(135deg, #dc2626 0%, #e11d48 100%);'],
    'indigo' => ['name' => 'Indigo', 'primary' => '#4f46e5', 'primary_light' => '#6366f1', 'gradient_style' => 'background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);'],
    'teal'   => ['name' => 'Teal',   'primary' => '#0d9488', 'primary_light' => '#14b8a6', 'gradient_style' => 'background: linear-gradient(135deg, #0d9488 0%, #0891b2 100%);'],
    'rose'   => ['name' => 'Rose',   'primary' => '#e11d48', 'primary_light' => '#f43f5e', 'gradient_style' => 'background: linear-gradient(135deg, #e11d48 0%, #dc2626 100%);'],
    'amber'  => ['name' => 'Amber',  'primary' => '#d97706', 'primary_light' => '#f59e0b', 'gradient_style' => 'background: linear-gradient(135deg, #d97706 0%, #ca8a04 100%);'],
];

$selectedTheme = $details['theme'] ?? 'purple';
$theme = $themes[$selectedTheme] ?? $themes['purple'];

$keywords               = $business['business_keywords'];
$maxKeywords            = 3;
$enableKeywordSelection = $business['enable_keyword_selection'];

/* ---------- Links and language ----------
   rg_business_links() returns only validated destinations, so a button is drawn
   only when it actually goes somewhere. No fallback URLs are invented. */
$links   = rg_business_links($details);
$hasLink = (bool)array_filter($links);

$langOptions = rg_ai_languages();
$langCode    = array_key_exists((string)($details['language'] ?? ''), $langOptions)
    ? (string)$details['language']
    : 'en';

/* Label, Font Awesome icon and brand class for each destination, in the order
   the customer page shows them. */
$socialButtons = [
    'google_review_url' => ['label' => 'Google',    'icon' => 'fa-brands fa-google',      'class' => 'is-google'],
    'instagram'         => ['label' => 'Instagram', 'icon' => 'fa-brands fa-instagram',   'class' => 'is-instagram'],
    'website'           => ['label' => 'Website',   'icon' => 'fa-solid fa-globe',        'class' => 'is-website'],
    'whatsapp'          => ['label' => 'WhatsApp',  'icon' => 'fa-brands fa-whatsapp',    'class' => 'is-whatsapp'],
    /* Facebook is not in the reference row, but a business that configured it must
       not lose the link: it is shown last, only when it exists. */
    'facebook'          => ['label' => 'Facebook',  'icon' => 'fa-brands fa-facebook-f',  'class' => 'is-facebook'],
];
?>
<!DOCTYPE html>
<html lang="<?= e($langCode) ?>">
<head>
    <meta charset="UTF-8">
    <?= csrfMeta() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#4B20DC">
    <title><?= e($business['name']) ?> — Share your experience</title>
    <link rel="stylesheet" href="<?= e(asset('css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/customer.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/customer-premium.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/fontawesome/css/all.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/customer-refresh.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/customer-wheel.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/customer-cream.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/customer-reference.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/customer-experience.css')) ?>">
    <style>
        /* Only the two toggles the JavaScript needs. All presentation lives in
           customer-premium.css so this page keeps one stylesheet of its own. */
        .hidden { display: none !important; }
        .fade-in { animation: rg-fade .25s ease both; }
        @keyframes rg-fade { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
    </style>
</head>
<body>
<main id="review-app">
  <div class="rg-page-sparkles" aria-hidden="true">
    <span class="rg-spark s1">✦</span>
    <span class="rg-spark s2">✧</span>
    <span class="rg-spark s3">✦</span>
    <span class="rg-spark s4">✧</span>
    <span class="rg-spark s5">✦</span>
    <span class="rg-spark s6">✧</span>
  </div>
  <div class="customer-shell">
    <header class="customer-bar">
      <a class="back" href="<?= e(url('/')) ?>" aria-label="Back to website">←</a>
      <span class="bar-brand"><span class="bar-spark" aria-hidden="true">✦</span> <strong>ReviewAI</strong></span>
      <div class="bar-actions">
        <button class="bar-icon" type="button" onclick="focusLanguage()" aria-label="Change the draft language">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
            <circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.4 2.3 3.6 5.2 3.6 8.5S14.4 18.2 12 20.5c-2.4-2.3-3.6-5.2-3.6-8.5S9.6 5.8 12 3.5z"/>
          </svg>
        </button>
        <button class="bar-share" type="button" onclick="sharePage()" aria-label="Share this page">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true">
            <circle cx="18" cy="5.5" r="2.6"/><circle cx="6" cy="12" r="2.6"/><circle cx="18" cy="18.5" r="2.6"/>
            <path d="M8.3 10.8 15.7 6.8M8.3 13.2l7.4 4"/>
          </svg>
          <span>Share QR</span>
        </button>
      </div>
    </header>

    <?php if ($isExpired): ?>
    <p class="environment">THIS REVIEW LINK HAS EXPIRED</p>
    <?php endif; ?>

    <?php
    /* The stored logo is a path inside the app (assets/uploads/…). Two things
       went wrong in the field: the path was echoed raw (breaks in a subfolder
       install) and a missing file drew the alt text in a grey slab. Now the file
       is checked, and a business without a usable one gets its own initial. */
    $logoUrl = '';
    if (!empty($business['logo'])) {
        $logoFile = __DIR__ . '/' . ltrim((string)$business['logo'], '/');
        if (is_file($logoFile)) $logoUrl = url((string)$business['logo']);
    }
    /* Up to two initials, never a hardcoded name. "Two Words" → TW,
       "One" → O, a name with no latin characters falls back to the spark. */
    $monogram = '';
    $nameWords = preg_split('/\s+/', trim((string)$business['name'])) ?: [];
    foreach ($nameWords as $nameWord) {
        if (preg_match('/[A-Za-z0-9]/', $nameWord, $m)) {
            $monogram .= strtoupper($m[0]);
            if (strlen($monogram) >= 2) break;
        }
    }
    ?>
    <div class="brand-box">
      <span class="rg-halo" aria-hidden="true"></span>
      <span class="rg-arc" aria-hidden="true"></span>
      <p class="rg-profile-eyebrow"><span aria-hidden="true">✦</span> Customer feedback</p>
      <div class="logo-disc">
        <?php if ($logoUrl !== ''): ?>
        <img src="<?= e($logoUrl) ?>" alt="<?= e($business['name']) ?> logo" referrerpolicy="no-referrer">
        <?php elseif ($monogram !== ''): ?>
        <span class="rg-monogram" aria-hidden="true"><?= e($monogram) ?></span>
        <?php else: ?>
        <span aria-hidden="true">✦</span>
        <?php endif; ?>
      </div>
      <h1 class="rg-profile-pill"><?= e($business['name']) ?></h1>
      <?php if (!empty($business['description'])): ?>
      <p class="rg-profile-note"><?= e($business['description']) ?></p>
      <?php endif; ?>
    </div>

    <?php if ($hasLink): ?>
    <!-- Destinations, above the stars. Only the ones this business configured. -->
    <nav class="rg-social" id="rgSocial" aria-label="Find <?= e($business['name']) ?> elsewhere">
      <?php foreach ($socialButtons as $key => $meta): ?>
        <?php if (!empty($links[$key])): ?>
        <a class="rg-social-btn <?= e($meta['class']) ?>" data-platform="<?= e($key) ?>"
           href="<?= e($links[$key]) ?>" target="_blank" rel="noopener noreferrer"
           aria-label="<?= e($meta['label'] . ' — opens in a new tab') ?>">
            <span class="rg-social-icon" aria-hidden="true">
              <?php if ($key === 'website' && $logoUrl !== ''): ?>
              <img class="rg-social-logo" src="<?= e($logoUrl) ?>" alt="" referrerpolicy="no-referrer">
              <?php else: ?>
              <i class="<?= e($meta['icon']) ?>"></i>
              <?php endif; ?>
            </span>
            <span class="rg-social-label"><?= e($meta['label']) ?></span>
        </a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <?php endif; ?>

    <!-- Where the customer is in the flow. JavaScript moves it; without
         JavaScript step 1 is highlighted, which is where everyone starts. -->
    <ol class="rg-progress" id="rgProgress" aria-label="Three steps: rate, pick topics, choose a draft">
      <li class="is-current" data-step="1"><b aria-hidden="true">1</b><span>Rate</span></li>
      <li data-step="2"><b aria-hidden="true">2</b><span>Topics</span></li>
      <li data-step="3"><b aria-hidden="true">3</b><span>Draft</span></li>
    </ol>

    <!-- STEP 1 — rating. Always visible so the customer can change their mind. -->
    <section class="intro rg-step" id="ratingBlock">
      <h2>How was your<br><em>experience?</em></h2>
      <p>Your honest feedback matters — good or bad.</p>
      <div class="stars" role="radiogroup" aria-label="Choose your rating">
        <?php for ($star = 1; $star <= 5; $star++): ?>
        <button class="star" type="button" data-rating="<?= $star ?>"
                role="radio" aria-checked="false"
                aria-label="<?= $star ?> <?= $star === 1 ? 'star' : 'stars' ?>"
                onclick="selectRating(<?= $star ?>)">☆</button>
        <?php endfor; ?>
      </div>
      <p class="rating-note" id="ratingLabel" aria-live="polite">Tap a star to rate</p>

      <div class="rg-direct">
        <?php if ($business['google_review_url'] !== ''): ?>
        <a class="rg-direct-link" href="<?= e($business['google_review_url']) ?>" target="_blank" rel="noopener noreferrer">
          <span class="rg-direct-g" aria-hidden="true"><i class="fa-brands fa-google"></i></span>
          <span>Go directly to Google</span>
          <span class="rg-direct-arrow" aria-hidden="true">→</span>
        </a>
        <p class="rg-or"><span>or</span></p>
        <?php endif; ?>
        <p class="rg-privacy">Your words are only used to write your draft. Nothing is posted automatically.</p>
      </div>
    </section>

    <div class="customer-body">

      <!-- Private feedback. Available at every rating; opened automatically at 1-3. -->
      <section id="feedbackForm" class="hidden rg-step">
        <div class="rg-step-head">
          <span class="rg-step-badge" aria-hidden="true">!</span>
          <h3>Sorry to hear that. What went wrong?</h3>
        </div>
        <div class="field">
          <label for="feedbackName">Your name</label>
          <div class="input-wrap"><input id="feedbackName" type="text" maxlength="60" placeholder="Optional"></div>
        </div>
        <div class="field">
          <label for="feedbackPhone">Phone <span class="optional-tag">(optional)</span></label>
          <div class="input-wrap"><input id="feedbackPhone" type="tel" maxlength="20" placeholder="So the business can reply"></div>
        </div>
        <div class="field">
          <label for="feedbackMessage">What happened?</label>
          <div class="input-wrap">
            <textarea id="feedbackMessage" maxlength="1000" placeholder="Tell the business what went wrong."
                      oninput="validateFeedbackForm()"></textarea>
          </div>
        </div>
        <button type="button" class="main-button" id="submitFeedbackBtn" onclick="submitFeedback()" disabled>Send feedback privately →</button>
        <p class="small-note">This goes only to the business. You can still leave a public review below — nothing is decided for you.</p>
      </section>

      <!-- STEP 2 — topics, the customer's own words, language -->
      <section id="keywordSection" class="hidden rg-step">
        <div class="rg-step-head">
          <span class="rg-step-badge" aria-hidden="true">2</span>
          <h3>What stood out?<span>(pick up to <?= (int)$maxKeywords ?>)</span></h3>
          <span class="rg-pick-count" id="pickCount" aria-live="polite"><span id="selectedCount">0</span> / <?= (int)$maxKeywords ?></span>
        </div>
        <div class="chips" id="keywordsContainer">
          <?php foreach ($keywords as $keyword): ?>
          <button type="button" class="chip keyword-btn" data-keyword="<?= e($keyword) ?>"
                  aria-pressed="false"
                  onclick="toggleKeyword(this, <?= e(json_encode($keyword)) ?>)"><?= e($keyword) ?></button>
          <?php endforeach; ?>
        </div>
        <p class="rg-hint"><span id="hintCount">0 of <?= (int)$maxKeywords ?> selected.</span> Pick at least one, or write a line below — one of the two is enough.</p>

        <div class="field">
          <label for="experienceDetails">A few words about your visit <span class="optional-tag">(optional)</span></label>
          <div class="input-wrap">
            <textarea id="experienceDetails" maxlength="600" oninput="onDetailsInput()"
                      placeholder="Optional. Anything you actually experienced — add it and the draft sounds more like you."></textarea>
            <small id="detailsCount">0 / 600</small>
          </div>
          <p class="rg-hint">The draft is written from the topics you pick, plus these words if you add any. Nothing is invented, and you can edit it after.</p>
        </div>

        <div class="language-row">
          <label for="reviewLanguage">Draft language</label>
          <select id="reviewLanguage" onchange="onLanguageChange()">
            <?php foreach ($langOptions as $code => $label): ?>
            <option value="<?= e($code) ?>" <?= $code === $langCode ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <button type="button" class="main-button" id="generateBtn" onclick="generateAIReviews()" disabled>Write my review →</button>
        <p class="rg-cta-hint" id="ctaHint">Pick a topic or write a line to continue.</p>
        <button type="button" class="rg-private-toggle hidden" id="privateToggle" onclick="showPrivateFeedback()">
          Prefer to tell the business privately instead?
        </button>
      </section>

      <!-- STEP 3 — drafts, loading, and the failure state -->
      <section id="aiReviewsSection" class="hidden">

        <div id="aiLoading" class="hidden rg-skeleton-wrap" role="status" aria-live="polite">
          <p class="ai-badge"><span id="aiStatusText">Writing your draft…</span></p>
          <div class="rg-skeleton" aria-hidden="true">
            <div class="rg-skeleton-line"></div><div class="rg-skeleton-line"></div><div class="rg-skeleton-line"></div>
          </div>
          <div class="rg-skeleton" aria-hidden="true">
            <div class="rg-skeleton-line"></div><div class="rg-skeleton-line"></div><div class="rg-skeleton-line"></div>
          </div>
          <div class="rg-skeleton" aria-hidden="true">
            <div class="rg-skeleton-line"></div><div class="rg-skeleton-line"></div><div class="rg-skeleton-line"></div>
          </div>
        </div>

        <div id="aiError" class="hidden rg-error" role="alert">
          <div class="error-mark" aria-hidden="true">!</div>
          <h2>That did not work</h2>
          <p id="errorMessage">The writing assistant is unavailable right now.</p>
          <button type="button" class="outline-button" id="retryBtn" onclick="generateAIReviews()">Try again</button>
          <p class="small-note">You can also write your own review, or use the Google button below — those always work.</p>
        </div>

        <div class="result-body hidden" id="aiResult">
          <div class="success-mark" aria-hidden="true">✓</div>
          <p class="ai-badge"><span id="aiReadyText">AI-powered draft ready</span></p>
          <h2>Pick one and make it yours</h2>
          <p class="result-intro">Written from your words, your stars and your topics. Edit it however you like — it is yours.</p>
          <div id="selectedKeywordsDisplay" class="hidden">
            <div class="chips" id="selectedKeywordsTags"></div>
          </div>
          <div class="review-cards" id="reviewsList"></div>

          <div class="field">
            <label for="reviewerName">Your name <span class="optional-tag">(optional)</span></label>
            <div class="input-wrap">
              <input id="reviewerName" type="text" maxlength="60" placeholder="Shown with your review">
            </div>
          </div>

          <button type="button" class="main-button" id="googleReviewBtn" onclick="reviewOnGoogle()" disabled>Review us on Google →</button>
          <button type="button" class="outline-button" id="privateSubmitBtn" onclick="submitPrivateReview()" disabled
                  style="margin-top:10px">Send feedback privately</button>
          <p class="small-note centered">Nothing is posted for you. Send it privately, or copy a draft and paste it on Google — the free spin is for your feedback either way.</p>
        </div>
      </section>

      <!-- feedback sent -->
      <section id="feedbackSuccess" class="hidden rg-step">
        <div class="success-mark" aria-hidden="true">✓</div>
        <h2>Thank you — we have passed this on.</h2>
        <p class="result-intro">The business can see your feedback privately.</p>
      </section>


      <?php if ($business['google_review_url'] === ''): ?>
      <div class="rg-info-box">
        <span class="rg-info-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v6c0 4.3-2.9 7.6-7 9-4.1-1.4-7-4.7-7-9V6l7-3z"/></svg>
        </span>
        <div class="rg-info-copy">
          <p class="direct unavailable">The Google review link is not available. Please ask the business to check this link.</p>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($wheelOpen): ?>
      <?php
        /* One markup path for every state. Which one is visible comes from the
           database, so a refresh shows the truth and never a fresh spin. */
        $wheelResultJson = $wheelResult ? json_encode($wheelResult, JSON_UNESCAPED_UNICODE) : '';
        $wheelSubtitle = [
            'locked' => 'Write your review to unlock your free spin.',
            'ready'  => 'Your free spin is ready!',
            'done'   => 'This spin has been used — here is what you got.',
        ][$wheelStage] ?? 'Write your review to unlock your free spin.';
        $wheelNote = [
            'locked' => '<span aria-hidden="true">🔒</span> Your spin unlocks after you submit your review.',
            'ready'  => '<span aria-hidden="true">🎉</span> Your free spin is ready — tap the centre of the wheel.',
            'done'   => '<span aria-hidden="true">✅</span> Thanks for sharing your feedback!',
        ][$wheelStage] ?? '';
      ?>
      <section id="wheelSection" class="rg-wheel-section is-<?= e($wheelStage) ?>"
               data-stage="<?= e($wheelStage) ?>"
               data-token="<?= e($wheelToken) ?>"
               data-result="<?= e($wheelResultJson) ?>"
               data-business="<?= e($business['name']) ?>"
               data-redemption="<?= e(rg_wheel_redemption_note($conn, (int)$card['id'])) ?>"
               data-segments="<?= e(json_encode(array_column($wheelSegments, 'label'), JSON_UNESCAPED_UNICODE)) ?>">
        <p class="rg-wheel-kicker"><span aria-hidden="true">🎁</span> A thank-you from us</p>
        <h2 class="rg-wheel-title">Spin the <em>Wheel!</em></h2>
        <p class="rg-wheel-sub" id="wheelSubtitle"><?= e($wheelSubtitle) ?></p>

        <?php if ($wheelStage === 'locked'): ?>
        <button type="button" class="rg-wheel-unlock" onclick="unlockWheelHelp()">
          Write your review to unlock your spin <span aria-hidden="true">→</span>
        </button>
        <?php endif; ?>

        <div class="rg-wheel-stage">
          <div class="rg-wheel-pointer" aria-hidden="true"></div>
          <div class="rg-wheel-disc" id="wheelDisc" onclick="spinWheel()" role="presentation">
            <?= $wheelSvg ?>
          </div>
          <button type="button" class="rg-wheel-hub main-button rg-wheel-spin" id="spinBtn"
                  onclick="spinWheel()" aria-label="Spin the wheel"
                  <?= $wheelStage === 'ready' ? '' : 'disabled' ?>>
            <span class="rg-hub-brand" aria-hidden="true">ReviewAI</span>
            <span class="rg-hub-spark" aria-hidden="true">✦</span>
            <span class="rg-hub-label" id="spinBtnLabel">Spin</span>
          </button>
        </div>

        <div id="wheelResult" class="rg-wheel-result <?= $wheelStage === 'done' ? '' : 'hidden' ?>" role="status" aria-live="polite"></div>

        <p class="rg-wheel-note" id="wheelNote"><?= $wheelNote ?></p>

        <details class="rg-wheel-terms">
          <summary>Prize rules and campaign terms <span aria-hidden="true">→</span></summary>
          <p><strong>Odds of each segment:</strong> <?= e($wheelOddsText) ?></p>
          <p>The wedges are drawn the same size; the odds above are the real ones and are applied on the server.</p>
          <p>One spin per customer for this campaign. Sending your feedback privately unlocks it; posting on Google is never required, and any rating (1–5 stars) is welcome. Prizes come from this business’s own configured campaign — nothing is invented. Codes expire <?= (int)($wheelCampaign['coupon_expiry_days'] ?? 30) ?> days after the spin and are redeemed with <?= e($business['name']) ?>. No cash value.</p>
        </details>
      </section>
      <?php endif; ?>
    </div>
  </div>
</main>

<div id="toast" class="rg-toast hidden" role="status" aria-live="polite"><span id="toastMessage"></span></div>

<script src="<?= e(asset('js/vendor/jquery-3.7.1.min.js')) ?>"></script>
<script>
        /* CSRF: this page posts with $.ajax, so the token is attached once here. */
        $.ajaxPrefilter(function (options) {
            if ((options.type || 'GET').toUpperCase() !== 'POST') return;
            var meta = document.querySelector('meta[name="csrf-token"]');
            if (!meta) return;
            var t = meta.getAttribute('content');
            if (options.data instanceof FormData) options.data.append('_csrf', t);
            else if (typeof options.data === 'string') options.data += '&_csrf=' + encodeURIComponent(t);
            else if (options.data && typeof options.data === 'object') options.data._csrf = t;
            else options.data = { _csrf: t };
        });

        /* QR cards are served from /r/<slug>/, where a relative 'review.php'
           resolves to /r/<slug>/review.php and matches no rewrite. Use the
           absolute path of this script instead. */
        const ENDPOINT = '<?php
            $rgScript = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/review.php'));
            if (!preg_match('#/review\.php$#', $rgScript)) $rgScript = '/review.php';
            echo e($rgScript);
        ?>';
        const WHEEL_ENDPOINT = ENDPOINT.replace(/review\.php$/, 'wheel_spin.php');
        const themeColors = { primary: '<?= $theme['primary'] ?>', primaryLight: '<?= $theme['primary_light'] ?>' };

        /* Minimum characters of the customer's own words before a draft is asked
           for. Below this the model would have nothing to work from and would
           start inventing, which is exactly what the prompt forbids. */
        const MIN_DETAILS = 15;

        let selectedRating      = 0;
        let selectedKeywords    = [];
        let maxKeywords         = <?= (int)$maxKeywords ?>;
        let aiReviews           = [];
        let selectedReviewIndex = -1;
        let selectedReviewText  = '';
        let privateReviewSent   = false;
        let isGenerating        = false;
        let isExpired           = <?= $isExpired ? 'true' : 'false' ?>;
        let cardSlug            = <?= json_encode($card['slug']) ?>;
        let googleReviewUrl     = <?= json_encode($business['google_review_url']) ?>;
        let enableKeywordSelection = <?= $enableKeywordSelection ? 'true' : 'false' ?>;

        /* ---------- helpers ---------- */

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        function showToast(message) {
            $('#toastMessage').text(message);
            $('#toast').removeClass('hidden').fadeIn(250);
            setTimeout(() => $('#toast').fadeOut(250), 3200);
        }

        /* The rail above the flow. Purely visual: it never gates anything, and
           without JavaScript step 1 simply stays highlighted. */
        function rgSetStep(step) {
            $('#rgProgress li').each(function () {
                const n = parseInt($(this).attr('data-step'), 10);
                $(this).toggleClass('is-current', n === step)
                       .toggleClass('is-done', n < step);
            });
        }

        function detailsValue() {
            return ($('#experienceDetails').val() || '').trim();
        }

        /* Keywords alone are enough — that is how the flow was asked for. The
           words box is an optional bonus for a draft that sounds more personal. */
        function canGenerate() {
            return selectedKeywords.length > 0 || detailsValue().length >= MIN_DETAILS;
        }

        function refreshGenerateState() {
            const ready = canGenerate();
            $('#generateBtn').prop('disabled', !ready || isGenerating);
            $('#ctaHint').toggleClass('is-hidden', ready);
        }

        function onDetailsInput() {
            const value = detailsValue();
            const counter = $('#detailsCount');
            counter.text(value.length + ' / 600');
            counter.toggleClass('is-good', value.length >= MIN_DETAILS);
            refreshGenerateState();
        }

        function onLanguageChange() { /* the value is read at generate time */ }

        /* ---------- STEP 1: rating ---------- */

        function selectRating(rating) {
            if (isExpired) { showToast('⚠️ Membership expired.'); return; }

            selectedRating = rating;

            $('.star').each(function (index) {
                const on = index < rating;
                $(this).toggleClass('active', on).text(on ? '★' : '☆')
                       .attr('aria-checked', index === rating - 1 ? 'true' : 'false');
            });

            const labels = {
                1: 'We\'re sorry. What went wrong?',
                2: 'What could we improve?',
                3: 'Thanks! What could be better?',
                4: 'Good! What did you like?',
                5: 'Excellent! What did you love?'
            };
            $('#ratingLabel').text(labels[rating] || 'Tap a star to rate').toggleClass('is-set', true);
            rgSetStep(2);

            /* Equal access: every rating gets the same writer, and the private
               form is offered as well (auto-opened at 1-3, a link at 4-5). */
            $('#keywordSection').removeClass('hidden').addClass('fade-in');
            $('#privateToggle').toggleClass('hidden', rating <= 3);
            if (rating <= 3) {
                $('#feedbackForm').removeClass('hidden').addClass('fade-in');
            }

            refreshGenerateState();
            setTimeout(() => {
                const target = rating <= 3 ? $('#feedbackForm')[0] : $('#keywordSection')[0];
                target?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 220);
        }

        function showPrivateFeedback() {
            if (isExpired) return;
            rgSetStep(2);
            $('#feedbackForm').removeClass('hidden').addClass('fade-in');
            $('#privateToggle').addClass('hidden');
            setTimeout(() => $('#feedbackForm')[0]?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 150);
        }

        /* Arrow keys move between stars: a radiogroup, not five buttons. */
        $(document).on('keydown', '.stars', function (event) {
            const keys = { ArrowRight: 1, ArrowUp: 1, ArrowLeft: -1, ArrowDown: -1 };
            if (!keys[event.key]) return;
            event.preventDefault();
            const next = Math.min(5, Math.max(1, (selectedRating || 0) + keys[event.key]));
            selectRating(next);
            $('.star[data-rating="' + next + '"]').focus();
        });

        /* 1-5 shortcuts, ignored while typing or with modifiers. */
        $(document).on('keydown', function (event) {
            if (isExpired) return;
            const target = event.target;
            const tag = (target && target.tagName) ? target.tagName.toUpperCase() : '';
            const typing = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (target && target.isContentEditable);
            if (typing || event.ctrlKey || event.altKey || event.metaKey) return;
            if (event.key < '1' || event.key > '5') return;
            event.preventDefault();
            selectRating(parseInt(event.key, 10));
        });

        /* ---------- STEP 2: keywords ---------- */

        function toggleKeyword(element, keyword) {
            if (isExpired) return;
            const index = selectedKeywords.indexOf(keyword);

            if (index >= 0) {
                selectedKeywords.splice(index, 1);
                $(element).removeClass('selected').attr('aria-pressed', 'false');
            } else {
                if (selectedKeywords.length >= maxKeywords) {
                    showToast('You can pick up to ' + maxKeywords + ' topics.');
                    return;
                }
                selectedKeywords.push(keyword);
                $(element).addClass('selected').attr('aria-pressed', 'true');
            }
            $('#selectedCount').text(selectedKeywords.length);
            $('#hintCount').text(selectedKeywords.length + ' of ' + maxKeywords + ' selected.');
            $('#pickCount').toggleClass('is-set', selectedKeywords.length > 0);
            refreshGenerateState();
        }

        /* ---------- STEP 3: generation ---------- */

        function generateAIReviews() {
            if (isGenerating || isExpired) return;

            if (!canGenerate()) {
                showToast('Pick at least one topic, or write a few words about your visit.');
                $('#experienceDetails').focus();
                return;
            }

            isGenerating = true;
            $('#generateBtn').prop('disabled', true);
            $('#aiReviewsSection').removeClass('hidden');
            $('#aiError, #aiResult').addClass('hidden');
            $('#aiLoading').removeClass('hidden');
            $('#aiStatusText').text('Writing your draft…');
            $('#aiReviewsSection')[0]?.scrollIntoView({ behavior: 'smooth', block: 'start' });

            const language = $('#reviewLanguage').val() || '<?= e($langCode) ?>';

            $.ajax({
                url: ENDPOINT + '?action=generate',
                method: 'POST',
                data: {
                    slug: cardSlug,
                    keywords: selectedKeywords,
                    details: detailsValue(),
                    language: language,
                    rating: selectedRating
                },
                dataType: 'json',
                success: function (response) {
                    if (response && response.success && Array.isArray(response.reviews) && response.reviews.length) {
                        aiReviews = response.reviews;
                        if (response.google_review_url) googleReviewUrl = response.google_review_url;
                        $('#aiLoading').addClass('hidden');
                        $('#aiResult').removeClass('hidden').addClass('fade-in');
                        rgSetStep(3);
                        renderDrafts();
                        showToast('✨ Your draft is ready.');
                        /* The draft exists, so the server has recorded this
                           customer as eligible. Show the wheel without a reload. */
                        if (response.wheel) openWheelFromServer(response.wheel);
                    } else {
                        failGeneration((response && response.error) || 'The writing assistant did not answer. Please try again.');
                    }
                },
                error: function (xhr) {
                    /* A 403 here is an expired session/token rather than an AI
                       problem, and saying so saves the customer a reload. */
                    if (xhr && xhr.status === 403) {
                        failGeneration('Your page was open for a while. Reload it and try again.');
                    } else {
                        failGeneration('We could not reach the writing assistant. Check your connection and try again.');
                    }
                },
                complete: function () {
                    isGenerating = false;
                    refreshGenerateState();
                }
            });
        }

        /* Kept for the keyword-less card setting: same behaviour, one path. */
        function generateAIReviewsDirect() { generateAIReviews(); }

        function failGeneration(message) {
            isGenerating = false;
            $('#aiLoading').addClass('hidden');
            $('#aiResult').addClass('hidden');
            $('#errorMessage').text(message);
            $('#aiError').removeClass('hidden').addClass('fade-in');
            refreshGenerateState();
        }

        function renderDrafts() {
            const container = $('#reviewsList').empty();
            selectedReviewIndex = -1;
            selectedReviewText = '';
            privateReviewSent = false;
            $('#googleReviewBtn').prop('disabled', true);
            $('#privateSubmitBtn').prop('disabled', true).text('Send feedback privately');

            if (selectedKeywords.length) {
                const tags = $('#selectedKeywordsTags').empty();
                selectedKeywords.forEach((keyword) => {
                    tags.append('<span class="chip selected">' + escapeHtml(keyword) + '</span>');
                });
                $('#selectedKeywordsDisplay').removeClass('hidden');
            } else {
                $('#selectedKeywordsDisplay').addClass('hidden');
            }

            aiReviews.forEach((review, index) => {
                const words = escapeHtml(review).split(/\s+/).filter(Boolean).length;
                container.append(
                    '<label class="review-card" id="reviewBtn' + index + '" data-card="' + index + '" onclick="selectReview(' + index + ')">' +
                        '<span class="card-pick">' +
                            '<input type="radio" name="review-choice" value="' + index + '" aria-label="Choose draft ' + (index + 1) + '">' +
                            '<span class="card-title">Draft ' + (index + 1) + '</span>' +
                            '<span class="card-count">' + words + ' words</span>' +
                        '</span>' +
                        '<textarea aria-label="Review draft ' + (index + 1) + '" oninput="onDraftEdit(' + index + ', this)">' + escapeHtml(review) + '</textarea>' +
                        '<button type="button" class="link-button" onclick="event.stopPropagation(); copyReviewText(' + index + ')" id="copyIcon' + index + '">▣ Copy</button>' +
                    '</label>'
                );
            });

            $('#keywordSection').addClass('hidden');
        }

        /* The customer owns the words. Editing a draft updates the copy that
           Copy and "Review us on Google" will use — nothing is posted from the
           generated version behind their back. */
        function onDraftEdit(index, element) {
            aiReviews[index] = element.value;
            if (index === selectedReviewIndex) selectedReviewText = element.value;
            const card = element.closest('.review-card');
            if (card) {
                const counter = card.querySelector('.card-count');
                if (counter) counter.textContent = element.value.trim().split(/\s+/).filter(Boolean).length + ' words';
            }
        }

        function selectReview(index) {
            if (isExpired) return;
            selectedReviewIndex = index;
            selectedReviewText = aiReviews[index] || '';
            $('#googleReviewBtn').prop('disabled', !selectedReviewText);
            $('#privateSubmitBtn').prop('disabled', !selectedReviewText || privateReviewSent);
            $('[id^="reviewBtn"]').each(function (i) {
                $(this).toggleClass('selected', i === index);
                $(this).find('input[name="review-choice"]').prop('checked', i === index);
            });
        }

        function copyReviewText(index) {
            if (isExpired) { showToast('⚠️ Membership expired.'); return; }
            const text = aiReviews[index];
            if (!text) return;
            const done = () => {
                showToast('✅ Draft copied. Paste it wherever you like.');
                $('#copyIcon' + index).text('✓ Copied');
                selectReview(index);
                saveReview(text, { unlock: false });
            };
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(done).catch(() => fallbackCopy(text, done));
            } else {
                fallbackCopy(text, done);
            }
        }

        function fallbackCopy(text, done) {
            const area = document.createElement('textarea');
            area.value = text;
            document.body.appendChild(area);
            area.select();
            try { document.execCommand('copy'); } catch (error) {}
            document.body.removeChild(area);
            if (done) done();
        }

        function saveReview(reviewText, options) {
            const name = $('#reviewerName').val().trim() || 'Anonymous';
            const scroll = !options || options.scroll !== false;
            const unlock = !!(options && options.unlock);
            $.ajax({
                url: ENDPOINT + '?action=submit',
                method: 'POST',
                data: {
                    slug: cardSlug,
                    rating: selectedRating || 5,
                    customer_name: name,
                    review_body: reviewText,
                    unlock: unlock ? 1 : 0
                },
                dataType: 'json'
            }).done(function (response) {
                /* The wheel state is decided on the server. Only the explicit
                   private-submit path comes back unlocked. */
                if (response && response.wheel) openWheelFromServer(response.wheel, scroll);
                if (unlock && response && !response.error) {
                    privateReviewSent = true;
                    const button = $('#privateSubmitBtn');
                    button.prop('disabled', true).text('Feedback sent privately ✓');
                    showToast('✅ Feedback saved privately.');
                }
            }).fail(function () {
                const button = $('#privateSubmitBtn');
                button.prop('disabled', false).text('Send feedback privately');
                showToast('We could not save that. Please try again.');
            });
        }

        /* The one action that counts as a completed feedback round. */
        function submitPrivateReview() {
            if (isExpired) { showToast('⚠️ Membership expired.'); return; }
            if (privateReviewSent) { showToast('Your feedback is already saved.'); return; }
            if (!selectedReviewText) {
                showToast('⚠️ Please choose a draft first.');
                return;
            }
            const button = $('#privateSubmitBtn');
            button.prop('disabled', true).html('<span class="spinner" aria-hidden="true"></span> Sending…');
            saveReview(selectedReviewText, { unlock: true });
        }

        function reviewOnGoogle() {
            if (isExpired || !selectedReviewText) {
                showToast('⚠️ Please choose a draft first.');
                return;
            }
            if (!googleReviewUrl) {
                showToast('The business has not added a Google link yet.');
                return;
            }

            const button = $('#googleReviewBtn');
            button.html('<span class="spinner" aria-hidden="true"></span> Opening Google…').prop('disabled', true);

            const go = () => {
                showToast('✅ Draft copied. Opening Google…');
                /* Save the draft privately as well: the spin is a thank-you for
                   completing feedback, never a condition of posting on Google.
                   Saving first also means a blocked pop-up costs nothing. */
                saveReview(selectedReviewText, { scroll: false, unlock: false });
                setTimeout(() => {
                    window.open(googleReviewUrl, '_blank');
                    button.html('Review us on Google →').prop('disabled', false);
                }, 900);
            };

            if (navigator.clipboard) {
                navigator.clipboard.writeText(selectedReviewText).then(go).catch(() => fallbackCopy(selectedReviewText, go));
            } else {
                fallbackCopy(selectedReviewText, go);
            }
        }

        /* ---------- private feedback ---------- */

        function validateFeedbackForm() {
            $('#submitFeedbackBtn').prop('disabled', ($('#feedbackMessage').val() || '').trim().length === 0);
        }

        $(document).on('input', '#feedbackMessage', validateFeedbackForm);

        function submitFeedback() {
            if (isExpired) { showToast('⚠️ Membership expired.'); return; }
            const message = ($('#feedbackMessage').val() || '').trim();
            if (!message) { showToast('Please tell us what happened.'); $('#feedbackMessage').focus(); return; }

            const button = $('#submitFeedbackBtn');
            button.html('<span class="spinner" aria-hidden="true"></span> Sending…').prop('disabled', true);

            $.ajax({
                url: ENDPOINT + '?action=feedback',
                method: 'POST',
                data: {
                    slug: cardSlug,
                    rating: selectedRating || 5,
                    customer_name: $('#feedbackName').val().trim() || 'Anonymous',
                    phone: $('#feedbackPhone').val().trim(),
                    message: message
                },
                dataType: 'json',
                success: function (response) {
                    if (response && response.success) {
                        $('#feedbackForm').addClass('hidden');
                        $('#feedbackSuccess').removeClass('hidden').addClass('fade-in');
                        rgSetStep(3);
                        showToast('✅ Feedback sent privately.');
                        /* Saved private feedback is a completed feedback round, so
                           the server has unlocked the one spin. */
                        if (response.wheel) openWheelFromServer(response.wheel);
                    } else {
                        showToast('Error: ' + ((response && response.error) || 'Please try again.'));
                        button.html('Send feedback privately →').prop('disabled', false);
                    }
                },
                error: function () {
                    showToast('We could not send that. Please try again.');
                    button.html('Send feedback privately →').prop('disabled', false);
                }
            });
        }

        /* ---------- SPIN THE WHEEL ---------- */

        /* ------------------------------------------------------------------
           Spin the Wheel — one spin per customer.

           Nothing here decides anything. The server says whether this customer
           may spin, and which wedge the draw landed on; this code reveals it:
           turn the disc, then show the result the server already recorded.
           ------------------------------------------------------------------ */
        let wheelToken  = null;      // the one token, once the wheel is unlocked
        let wheelStage  = 'locked';  // locked | ready | done
        let isSpinning  = false;
        let wheelAngle  = 0;         // cumulative, so the disc never jumps back
        let wheelBusiness = '';
        let wheelRedemption = '';

        const WHEEL_TURN_MS = 4600;
        const WHEEL_TURNS   = 5;

        function wheelSectionEl() { return document.getElementById('wheelSection'); }

        function wheelLabels() {
            const section = wheelSectionEl();
            if (!section) return [];
            try { return JSON.parse(section.dataset.segments || '[]'); } catch (e) { return []; }
        }

        function markStage(stage) {
            const section = wheelSectionEl();
            if (!section) return;
            section.classList.remove('is-locked', 'is-ready', 'is-done', 'is-first', 'is-second');
            section.classList.add('is-' + stage);
            section.dataset.stage = stage;
        }

        function setBusy(busy) {
            const button = document.getElementById('spinBtn');
            const label  = document.getElementById('spinBtnLabel');
            if (!button) return;
            button.disabled = busy;
            button.classList.toggle('is-busy', busy);
            if (label) label.textContent = busy ? '' : 'Spin';
        }

        /* ---- the animation -------------------------------------------------
           A wedge's centre sits at -90 + (i + .5) * step in SVG space and the
           pointer is fixed at the top (-90), so the rotation that parks wedge i
           under the pointer is -(i + .5) * step. One running angle means every
           spin goes forward: no backwards jump, no double animation. */
        function rotateWheelTo(label, done, wantedIndex) {
            const disc = document.getElementById('wheelDisc');
            const labels = wheelLabels();
            if (!disc || !labels.length) { done(); return; }

            /* The server sends the index of the prize it actually drew. It is
               authoritative; the label is only a fallback for an older payload. */
            const index = Number.isInteger(wantedIndex) && wantedIndex >= 0 && wantedIndex < labels.length
                ? wantedIndex
                : Math.max(0, labels.indexOf(label));
            const step  = 360 / labels.length;
            const want  = ((-(index + 0.5) * step) % 360 + 360) % 360;
            const now   = ((wheelAngle % 360) + 360) % 360;

            let delta = want - now;
            if (delta < 0) delta += 360;

            wheelAngle += WHEEL_TURNS * 360 + delta;

            disc.style.transition = 'transform ' + WHEEL_TURN_MS + 'ms cubic-bezier(.15,.72,.06,1)';
            disc.style.transform  = 'rotate(' + wheelAngle + 'deg)';

            let finished = false;
            const finish = function () {
                if (finished) return;
                finished = true;
                disc.removeEventListener('transitionend', finish);
                done();
            };
            disc.addEventListener('transitionend', finish);
            setTimeout(finish, WHEEL_TURN_MS + 400);
        }

        /* ---- the one spin -------------------------------------------------- */
        function spinWheel() {
            if (isSpinning) return;                        // rapid taps: one spin
            if (wheelStage !== 'ready') {
                spinProblem(wheelStage === 'done'
                    ? 'Your spin for this visit has already been used.'
                    : 'Finish your feedback first — then the wheel unlocks.');
                return;
            }
            if (!wheelToken) {
                spinProblem('Your spin is not ready. Please reload the page.');
                return;
            }

            isSpinning = true;
            const token = wheelToken;
            wheelToken = null;                             // cannot be reused

            setBusy(true);
            const subtitle = document.getElementById('wheelSubtitle');
            if (subtitle) subtitle.textContent = 'Spinning…';
            const note = document.getElementById('wheelNote');
            if (note) note.textContent = 'Good luck!';

            $.ajax({
                url: WHEEL_ENDPOINT,
                method: 'POST',
                data: { slug: cardSlug, token: token },
                dataType: 'json'
            }).done(function (response) {
                if (response && response.success) {
                    wheelBusiness   = response.business || wheelBusiness;
                    wheelRedemption = response.redemption || wheelRedemption;
                    const segment   = response.segment || {};
                    const drawnIndex = (segment.index === null || segment.index === undefined)
                        ? null : Number(segment.index);
                    rotateWheelTo(segment.label || '', function () {
                        isSpinning = false;
                        setBusy(false);
                        wheelStage = 'done';
                        wheelToken = null;
                        showResult(response);
                    }, drawnIndex);
                } else {
                    wheelToken = token;                    // let them try again
                    isSpinning = false;
                    setBusy(false);
                    spinProblem((response && response.message) || 'This spin is not available.');
                }
            }).fail(function (xhr) {
                const body = (xhr && xhr.responseJSON) || {};
                isSpinning = false;
                setBusy(false);

                /* The server may have committed the spin before the connection
                   dropped. It returns the recorded prize with "already_spun":
                   show exactly that instead of inviting a retry that can never
                   succeed. */
                if (body && body.result && body.error === 'already_spun') {
                    wheelStage = 'done';
                    wheelToken = null;
                    showResult({
                        segment: body.result.segment,
                        coupon: body.result.coupon || null
                    });
                    return;
                }

                wheelToken = token;
                spinProblem(body.message || 'We could not reach the wheel. Please try again.');
            });
        }

        /* The locked wheel explains itself and takes the customer to the step
           that actually unlocks it. Nothing here sets eligibility. */
        function unlockWheelHelp() {
            /* The nearest action that actually completes the feedback round:
               the explicit private-submit button when a draft is selected, then
               the feedback form, then the drafts themselves. */
            const privateBtn = document.getElementById('privateSubmitBtn');
            if (privateBtn && !privateBtn.disabled) {
                privateBtn.scrollIntoView({ behavior: 'smooth', block: 'center' });
                setTimeout(function () { privateBtn.focus({ preventScroll: true }); }, 320);
                return;
            }

            const form = document.getElementById('feedbackForm');
            const drafts = document.getElementById('reviewsList');
            const seemHappy = selectedRating >= 4;

            if (!form || seemHappy) {
                if (drafts) drafts.scrollIntoView({ behavior: 'smooth', block: 'center' });
                showToast('Choose a draft, then tap “Send feedback privately” to unlock your spin.');
                return;
            }
            form.classList.remove('hidden');
            form.classList.add('fade-in');

            const message = document.getElementById('feedbackMessage');
            if (message && !message.value.trim()) {
                const details = detailsValue();
                if (details) {
                    message.value = details;
                    if (typeof validateFeedbackForm === 'function') validateFeedbackForm();
                }
            }

            const heading = form.querySelector('h3');
            if (heading && selectedRating >= 4) {
                heading.textContent = 'One quick note for the business?';
            }

            setTimeout(function () {
                form.scrollIntoView({ behavior: 'smooth', block: 'center' });
                if (message) { try { message.focus({ preventScroll: true }); } catch (e) {} }
            }, 120);
        }

        function spinProblem(message) {
            isSpinning = false;
            setBusy(false);
            markStage(wheelStage);
            const subtitle = document.getElementById('wheelSubtitle');
            if (subtitle) subtitle.textContent = message;
            if (typeof showToast === 'function') showToast(message);
        }

        /* ---- the result card ----------------------------------------------- */
        function showResult(data) {
            const box = document.getElementById('wheelResult');
            if (!box) return;

            const segment  = (data && data.segment) || {};
            const kind     = segment.kind || 'better_luck';
            const coupon   = data && data.coupon;
            const business = (data && data.business) || wheelBusiness;
            let html = '';

            if (kind === 'discount' && coupon) {
                html =
                    '<div class="rg-result-mark" aria-hidden="true">🎉</div>' +
                    '<h3>Congratulations!</h3>' +
                    '<p class="rg-result-lead">You won <strong>' + segment.percent + '% off</strong> at ' +
                        escapeHtml(business) + '.</p>' +
                    '<div class="rg-coupon">' +
                        '<span class="rg-coupon-label">Your coupon code</span>' +
                        '<code id="couponCode">' + escapeHtml(coupon.code) + '</code>' +
                        '<button type="button" class="rg-coupon-copy" onclick="copyCoupon()">Copy coupon</button>' +
                    '</div>' +
                    '<dl class="rg-coupon-meta">' +
                        '<div><dt>Discount</dt><dd>' + coupon.percent + '%</dd></div>' +
                        '<div><dt>Valid until</dt><dd>' + escapeHtml(coupon.expires) + '</dd></div>' +
                        '<div><dt>Business</dt><dd>' + escapeHtml(business) + '</dd></div>' +
                    '</dl>' +
                    '<p class="rg-result-note">' + escapeHtml((data && data.redemption) || wheelRedemption || '') + '</p>';
            } else if (kind === 'thanks') {
                html =
                    '<div class="rg-result-mark" aria-hidden="true">💜</div>' +
                    '<h3>Thank You for Your Feedback!</h3>' +
                    '<p class="rg-result-lead">Your words help ' + escapeHtml(business) + ' get better.</p>';
            } else {
                html =
                    '<div class="rg-result-mark" aria-hidden="true">🍀</div>' +
                    '<h3>Better Luck Next Time!</h3>' +
                    '<p class="rg-result-lead">Your feedback still means a lot to us ❤️</p>';
            }

            box.innerHTML = html;
            box.classList.remove('hidden');
            box.classList.add('fade-in');

            markStage('done');
            setBusy(false);
            const button = document.getElementById('spinBtn');
            if (button) button.disabled = true;

            const subtitle = document.getElementById('wheelSubtitle');
            if (subtitle) subtitle.textContent = 'This spin has been used — here is what you got.';
            const note = document.getElementById('wheelNote');
            if (note) note.innerHTML = '<span aria-hidden="true">✅</span> Thanks for sharing your feedback!';

            box.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        /* ---- what the server says about this customer ---------------------- */
        function readJson(value) {
            if (!value) return null;
            try { return JSON.parse(value); } catch (e) { return null; }
        }

        /* Called when the draft or the private feedback has just been accepted:
           the server has already recorded eligibility, so the wheel opens here. */
        function openWheelFromServer(info, scroll) {
            const section = wheelSectionEl();
            if (!section || !info) return;
            const shouldScroll = scroll !== false;

            if (info.stage === 'done') {
                wheelStage = 'done';
                wheelToken = null;
                markStage('done');
                if (info.result) showResult(info.result);
                return;
            }

            wheelStage = info.stage === 'ready' ? 'ready' : 'locked';
            wheelToken = info.token || null;
            markStage(wheelStage);

            const subtitle = document.getElementById('wheelSubtitle');
            const note = document.getElementById('wheelNote');
            const button = document.getElementById('spinBtn');

            if (wheelStage === 'ready' && wheelToken) {
                if (subtitle) subtitle.textContent = 'Your free spin is ready!';
                if (note) note.innerHTML = '<span aria-hidden="true">🎉</span> Your free spin is ready — tap the centre of the wheel.';
                if (button) button.disabled = false;
            }

            if (shouldScroll) {
                setTimeout(function () {
                    section.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 320);
            }
        }

        (function bootWheel() {
            const section = wheelSectionEl();
            if (!section) return;

            wheelStage = section.dataset.stage || 'locked';
            wheelToken = section.dataset.token || null;
            wheelBusiness = section.dataset.business || '';
            wheelRedemption = section.dataset.redemption || '';

            const result = readJson(section.dataset.result);

            if (wheelStage === 'done') {
                /* Refreshed after spinning: the recorded prize, nothing to spin. */
                showResult(result || {});
                const button = document.getElementById('spinBtn');
                if (button) button.disabled = true;
            } else if (wheelStage === 'ready') {
                const button = document.getElementById('spinBtn');
                if (button) button.disabled = !wheelToken;
            }
        })();

        /* ---- the two header buttons ---------------------------------------- */
        function focusLanguage() {
            const select = document.getElementById('reviewLanguage');
            if (!select) return;
            select.scrollIntoView({ behavior: 'smooth', block: 'center' });
            setTimeout(function () { select.focus({ preventScroll: true }); }, 320);
        }

        function sharePage() {
            const url = window.location.href;
            if (navigator.share) {
                navigator.share({ title: document.title, url: url }).catch(function () {});
                return;
            }
            const done = function () { if (typeof showToast === 'function') showToast('Link copied.'); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(done).catch(done);
                return;
            }
            window.prompt('Copy this link:', url);
        }

        function copyCoupon() {
            const code = document.getElementById('couponCode');
            if (!code) return;
            const text = code.textContent;
            const done = function () { showToast('📋 Coupon code copied.'); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done).catch(function () { showToast(text); });
            } else {
                showToast(text);
            }
        }


        /* Character counter is correct on a reload with restored values. */
        $(document).ready(function () { onDetailsInput(); });
    </script>
</body>
</html>
