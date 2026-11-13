<?php
/**
 * onboard.php — Create a review card
 * Handles its own POST. On success → redirects to list.php.
 * On error → re-renders with the error message.
 * No external POST target, no redirect loops.
 *
 * Logic unchanged from the purchased application. Only the markup was replaced
 * with the original ReviewGrow design (app/assets/css/auth.css), and the live
 * slug preview was ported from jQuery to plain JavaScript.
 *
 * The design's onboarding is a 3-step wizard collecting category, address,
 * phone and language. None of those columns exist (review_cards holds
 * id, user_id, name, slug, membership, expiry_date, details), so the wizard is
 * not reproduced — see docs/STAGE-2-PUBLIC.md.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/public_layout.php';

requireLogin();

$appName = getSetting('app_name', 'ReviewAI');
$userId  = (int)$_SESSION['user_id'];
$errors  = [];

/* ---------- Handle POST (create card) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $name   = trim($_POST['name'] ?? '');
    $slugIn = trim($_POST['slug'] ?? '');

    if ($name === '') {
        $errors[] = 'Business name is required.';
    }

    if (empty($errors)) {
        try {
            $slug = uniqueCardSlug($slugIn !== '' ? $slugIn : $name);

            /* One 3-day trial per account. A second card for the same business
               starts with no active period, so it has to be paid for. */
            require_once __DIR__ . '/includes/payment_flow.php';
            $trialAvailable = !rg_trial_used($conn, $userId);

            if ($trialAvailable) {
                $stmt = $conn->prepare("
                    INSERT INTO review_cards (user_id, name, slug, membership, expiry_date)
                    VALUES (?, ?, ?, 'trial', DATE_ADD(NOW(), INTERVAL " . RG_TRIAL_DAYS . " DAY))
                ");
                $stmt->execute([$userId, $name, $slug]);
                rg_mark_trial_started($conn, $userId);
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO review_cards (user_id, name, slug, membership, expiry_date)
                    VALUES (?, ?, ?, 'none', NULL)
                ");
                $stmt->execute([$userId, $name, $slug]);
            }

            $_SESSION['success_message'] = 'Review card created successfully!';
            header('Location: list.php');
            exit;

        } catch (Exception $e) {
            $errors[] = 'Could not create card: ' . $e->getMessage();
        }
    }
}

$postedName = $_POST['name'] ?? '';
$postedSlug = $_POST['slug'] ?? '';
$previewUrl = url('review.php?slug=');

public_head($appName . ' — Create Review Card', ['auth.css']);
auth_shell_start($appName);
auth_card_open(
    'Tell us about your business.',
    'This is the name customers see when they open your review page.',
    'BUSINESS SETUP'
);
?>
  <form id="onboardForm" method="POST">
    <?= csrfField() ?>
    <div class="form-grid">
      <div class="form-field span-two">
        <label for="name">Business name</label>
        <input id="name" name="name" type="text" required minlength="2" maxlength="120"
               autocomplete="organization" value="<?= e($postedName) ?>">
      </div>
      <div class="form-field span-two">
        <label for="slugPreviewInput">Your review page URL</label>
        <input id="slugPreviewInput" type="text" readonly value="<?= e($previewUrl . $postedSlug) ?>">
        <p class="hint">Created automatically from the business name.</p>
      </div>
    </div>
    <input type="hidden" id="slug" name="slug" value="<?= e($postedSlug) ?>">
    <p class="hint">A 3-day free trial starts now. No card required.</p>
    <?php status_block($errors); ?>
    <div class="form-actions">
      <button class="button button-dark" type="submit" id="submitBtn">Create review card →</button>
    </div>
  </form>
  <p class="form-foot"><a href="<?= e(url('list.php')) ?>">← Back to my cards</a></p>

  <script>
    /* Live slug preview — same behaviour as the original jQuery version,
       rewritten in plain JavaScript so this page needs no library. */
    (function () {
      var nameInput  = document.getElementById('name');
      var slugInput  = document.getElementById('slug');
      var preview    = document.getElementById('slugPreviewInput');
      var submitBtn  = document.getElementById('submitBtn');
      var base       = <?= json_encode($previewUrl, JSON_UNESCAPED_SLASHES) ?>;

      function slugify(value) {
        return value
          .toLowerCase()
          .replace(/[^a-z0-9\s-]/g, '')
          .replace(/\s+/g, '-')
          .replace(/-+/g, '-')
          .replace(/^-|-$/g, '');
      }

      nameInput.addEventListener('input', function () {
        var slug = slugify(nameInput.value.trim());
        slugInput.value = slug;
        preview.value = base + slug;
        submitBtn.disabled = slug.length === 0;
      });

      /* Keep the button state correct when the page loads with a value
         already filled in (e.g. after a validation error). */
      if (slugify(nameInput.value.trim()).length === 0) {
        submitBtn.disabled = true;
      }
    })();
  </script>
<?php
auth_card_close();
