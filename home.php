<?php
/**
 * home.php — Public landing page
 * Loaded by index.php after install check.
 *
 * Markup generated from the original design file by tools/build-home.mjs.
 * Do not edit the HTML below by hand: edit the design, or edit the documented
 * substitutions in that script and re-run it.
 */

if (!isset($conn)) {
    require_once __DIR__ . '/includes/db.php';
}
if (!function_exists('getSetting')) {
    require_once __DIR__ . '/includes/functions.php';
}
require_once __DIR__ . '/includes/public_layout.php';
/* The homepage video section: settings, validation and markup all live here,
   so the owner panel and this page can never disagree about the rules. */
require_once __DIR__ . '/includes/home_video.php';
/* Marketing popups: this is a marketing page, so a published campaign may appear
   here. The dashboards, the owner panel and review.php never load this file. */
require_once __DIR__ . '/includes/popups.php';
define('RG_PUBLIC_PAGE', 'home');

$appName = getSetting('app_name', 'ReviewAI');

// ---------- Stats (safe fallbacks if tables are empty) ----------
try {
    $totalCards    = (int)$conn->query("SELECT COUNT(*) FROM review_cards")->fetchColumn();
    $totalReviews  = (int)$conn->query("SELECT COUNT(*) FROM reviews")->fetchColumn();
    $totalUsers    = (int)$conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $avgRating     = (float)($conn->query("SELECT AVG(rating) FROM reviews")->fetchColumn() ?? 0);

    $recentReviews = $conn->query("
        SELECT r.customer_name, r.rating, r.body, r.created_at,
               rc.name AS business_name
        FROM reviews r
        JOIN review_cards rc ON r.card_id = rc.id
        ORDER BY r.created_at DESC
        LIMIT 6
    ")->fetchAll();
} catch (Exception $e) {
    // Fail silently — landing page should never hard-crash
    $totalCards = $totalReviews = $totalUsers = 0;
    $avgRating = 0;
    $recentReviews = [];
}

// ---------- Public pricing (plans table) ----------
try {
    $plans = $conn->query("SELECT * FROM plans ORDER BY price ASC")->fetchAll();
} catch (Exception $e) {
    $plans = [];
}

$head = '<meta name="theme-color" content="#faf8f2">' . "\n"
      . '<meta name="description" content="Create a QR code and make it easier for customers to share an honest review.">' . "\n"
      . '<script defer src="' . e(asset('js/home-redesign.js')) . '"></script>' . "
"
      . '<script defer src="' . e(asset('js/home-video.js')) . '"></script>' . "\n"
      /* Promotion popup: only printed when a campaign targets this page. */
      . rg_popup_head();

public_head($appName . ' — Every experience matters', ['public.css', 'home-video.css'], $head);
?>
<a class="skip" href="#main">Skip to content</a><header class="header"><div class="nav wrap"><a class="logo" href="<?= e(url('/')) ?>"><span class="logo-mark">✧</span> <?= e($appName) ?></a><button class="menu-toggle" aria-expanded="false" aria-controls="nav-links" aria-label="Toggle navigation"><span></span><span></span></button><nav id="nav-links" aria-label="Main navigation"><a href="#product">Product</a><a href="#how-it-works">How it works</a><a href="#features">Features</a><a href="#pricing">Pricing</a><a href="#faq">FAQ</a><a class="sign-in" href="<?= e(url('login.php')) ?>">Sign in</a><a class="nav-cta" href="<?= e(url('signup.php')) ?>">Get started <span>→</span></a></nav></div></header><main id="main"><section class="hero wrap" id="product"><div class="hero-copy reveal"><div class="eyebrow"><span class="eyebrow-dot"></span> For Indian businesses</div><h1>Turn every customer<br>experience into a<br><em>better review.</em></h1><p>One QR code. Simple customer feedback.<br>Make sharing honest experiences easier.</p><div class="hero-actions"><a class="btn btn-primary" href="<?= e(url('signup.php')) ?>">Create your first QR <span>→</span></a><a class="btn btn-light" href="#how-it-works"><span class="play">▶</span> See how it works</a></div><div class="proof-points"><div><span>♧</span><div><strong>Built for</strong><small>local businesses</small></div></div><div><span class="gold">☆</span><div><strong>Honest</strong><small>customer experiences</small></div></div><div><span>▥</span><div><strong>Customer-led</strong><small>review sharing</small></div></div></div></div><div class="hero-art reveal" aria-label="Illustration of a customer review experience"><div class="orbit orbit-one"></div><div class="orbit orbit-two"></div><div class="orbit orbit-three"></div><div class="glow"></div><span class="particle p1"></span><span class="particle p2"></span><span class="particle p3"></span><span class="particle p4"></span><div class="scene" id="scene"><div class="phone-float"><div class="phone"><div class="phone-bezel"><div class="phone-top"><span>9:41</span><span class="dynamic-island"></span><span>▴ ▮</span></div><div class="phone-app"><div class="phone-kicker"><span>✳</span> Review AI</div><div class="shop-icon">✦</div><div class="phone-business">Your business</div><div class="phone-location">YOUR CUSTOMER'S EXPERIENCE</div><div class="phone-heading">How was your<br>experience?</div><div class="phone-sub">We'd love to hear from you.</div><div class="phone-stars">★★★★★</div><div class="phone-label">A few words about your visit</div><div class="phone-input">Share what actually happened...</div><div class="phone-button">Create a draft <span>→</span></div><div class="phone-direct">Or continue directly to Google ↗</div></div><div class="phone-home"></div></div></div></div><div class="qr-card tilt-card" data-tilt><div class="mini-logo"><span>✳</span> REVIEW AI</div><div class="qr-graphic" aria-hidden="true"><svg viewBox="0 0 100 100" role="presentation"><path d="M9 35V9h26M65 9h26v26M91 65v26H65M35 91H9V65" fill="none" stroke="#17182d" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 50h58" stroke="#4c24dd" stroke-width="6" stroke-linecap="round"/><circle cx="50" cy="50" r="9" fill="#dcd2ff"/></svg></div><strong>Scan to share</strong><small>Icon only · not scannable</small></div><div class="google-float"><span class="google-g">G</span><div><strong>Google Reviews</strong><div class="mini-stars">★★★★★</div></div></div><span class="float-star fs1">✦</span><span class="float-star fs2">✦</span><span class="float-star fs3">✧</span></div><div class="art-caption">Product illustration · not a live QR <span>✳</span></div></div></section><?php rg_home_video_render(); ?><section class="steps-section wrap" id="how-it-works"><div class="center-head"><span class="green-tag">How it works</span><h2>Get more reviews in 3 simple steps</h2></div><div class="steps-line"><article><div class="round-icon">⌗</div><h3>1. Share Your QR</h3><p>Create a QR code for your<br>business or campaign.</p></article><span class="step-arrow" aria-hidden="true">→</span><article><div class="round-icon">☏</div><h3>2. Let Customers Share</h3><p>Customers scan and tell us<br>about their experience.</p></article><span class="step-arrow" aria-hidden="true">→</span><article><div class="round-icon">▥</div><h3>3. Generate & Share</h3><p>Customers edit their own draft<br>and choose whether to share it.</p></article></div></section><section class="feature-cards wrap" id="features"><article><span class="square-icon">▦</span><h3>QR Campaigns</h3><p>Create QR codes for your store, menu or bills.</p></article><article><span class="square-icon">✧</span><h3>Natural Review Drafts</h3><p>Customers edit honest drafts in their own words.</p></article><article><span class="square-icon">◎</span><h3>Customer Feedback</h3><p>Unhappy customers can leave private feedback instead of a public review.</p></article><article><span class="square-icon">▥</span><h3>Built-in QR Code</h3><p>Every review card gets its own printable QR code.</p></article></section><section class="customer-stories wrap"><div class="center-head"><span class="green-tag">For real businesses</span><h2>Real businesses. Real results.</h2><?php if ($recentReviews): ?><p><?= (int)$totalReviews ?> review<?= $totalReviews === 1 ? '' : 's' ?> from <?= (int)$totalCards ?> business<?= $totalCards === 1 ? '' : 'es' ?> so far.</p><?php else: ?><p>We'll share verified customer stories here when they're available.</p><?php endif; ?></div><?php if ($recentReviews): ?>
        <div class="stories">
          <?php foreach ($recentReviews as $review): ?>
          <article><span class="placeholder-avatar">✦</span><div>
            <strong><?= e($review['business_name']) ?></strong>
            <div class="mini-stars"><?= str_repeat('★', max(0, min(5, (int)$review['rating']))) ?></div>
            <p>“<?= e(excerpt((string)$review['body'])) ?>” — <?= e($review['customer_name'] ?: 'A customer') ?></p>
          </div></article>
          <?php endforeach; ?>
        </div>
<?php else: ?>
        <div class="stories"><article><span class="placeholder-avatar">✦</span><div><strong>Customer story coming soon</strong><p>Reserved for a verified testimonial.</p></div></article><article><span class="placeholder-avatar">✦</span><div><strong>Customer story coming soon</strong><p>Reserved for a verified testimonial.</p></div></article><article><span class="placeholder-avatar">✦</span><div><strong>Customer story coming soon</strong><p>Reserved for a verified testimonial.</p></div></article></div>
<?php endif; ?>
        </section><section class="bottom-layout wrap"><div id="pricing"><div class="center-head"><span class="green-tag">Simple choices</span><h2>Choose the plan that fits your business.</h2></div><?php if (empty($plans)): ?>
        <p class="public-note">No plans have been created yet. Sign in as the administrator to add one.</p>
        <?php else: ?>
        <div class="public-grid">
          <?php foreach ($plans as $plan):
              $features = is_string($plan['features']) ? json_decode($plan['features'], true) : $plan['features'];
              if (!is_array($features)) $features = [];
              $price = ($plan['sale_price'] !== null && (float)$plan['sale_price'] > 0)
                  ? (float)$plan['sale_price'] : (float)$plan['price'];
              $days  = max(1, (int)$plan['duration']);
          ?>
          <article class="public-card">
            <small><?= $days ?>-DAY PLAN</small>
            <h3><?= e($plan['name']) ?></h3>
            <div class="public-price">₹<?= number_format($price, 0) ?> <small>/ <?= $days ?> days</small></div>
            <?php if (!empty($plan['description'])): ?><p><?= e($plan['description']) ?></p><?php endif; ?>
            <?php if ($features): ?>
            <ul><?php foreach ($features as $feature): ?><li><?= e($feature) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
          </article>
          <?php endforeach; ?>
        </div>
        <p class="public-note">Prices are set by the administrator. Sign in to choose a plan for your business.</p>
        <?php endif; ?></div><div id="faq"><div class="center-head"><span class="green-tag">FAQ</span><h2>Got questions?</h2></div><div class="compact-faq"><details><summary>How do I create a QR code?<span>+</span></summary><p>Use the QR section in the dashboard. A verified permanent origin is required before printing customer codes.</p></details><details><summary>Can customers edit a review draft?<span>+</span></summary><p>Yes. Customers control and edit their own words before sharing.</p></details><details><summary>Does it work in multiple languages?<span>+</span></summary><p>Existing sentence ideas include English, Hindi and Telugu variants. Automatic translation is not connected.</p></details><details><summary>Will reviews be published automatically?<span>+</span></summary><p>No. Customers choose whether to continue to Google and whether to post.</p></details><details><summary>How are subscriptions managed?<span>+</span></summary><p>Plans are activated by the administrator after payment by card or WhatsApp, and each card shows its own expiry date.</p></details><details><summary>Is customer data secure?<span>+</span></summary><p>Draft text stays in the browser. Production privacy and security review is still required.</p></details><details><summary>Can I customise my QR?<span>+</span></summary><p>Your QR code is generated for your own review link. Colour options are not available yet.</p></details></div></div></section></main><footer><div class="wrap ref-footer"><div><a href="<?= e(url('/')) ?>" class="logo"><span class="logo-mark">✧</span> <?= e($appName) ?></a><small>Real experiences. More connection.</small></div><nav aria-label="Footer navigation"><a href="#product">Product</a><a href="#features">Features</a><a href="#pricing">Pricing</a><a href="#faq">FAQ</a><a href="<?= e(url('login.php')) ?>">Sign in</a></nav><span class="social-note">Social links coming soon</span></div></footer><?php print_rg_popup('home'); ?>
