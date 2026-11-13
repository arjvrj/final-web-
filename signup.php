<?php
/**
 * signup.php — Create an account
 *
 * Logic unchanged from the purchased application. Only the markup was replaced
 * with the original ReviewGrow design (app/assets/css/auth.css).
 *
 * Note: the design's register screen also asks for a "Full name". The users
 * table has no name column (id, email, password, otp, created_at), so that field
 * is not reproduced here — see docs/STAGE-2-PUBLIC.md.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/public_layout.php';
/* Marketing popups. These two pages are marketing pages, so a promotion may
   appear here; nothing in the dashboard, the owner panel or review.php loads it. */
require_once __DIR__ . '/includes/popups.php';
define('RG_PUBLIC_PAGE', 'signup');

/* Redirect already-logged-in users */
if (isLoggedIn()) {
    header('Location: ' . (isOwner() ? 'owner.php' : 'list.php'));
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email.';
    if (strlen($password) < 8)                      $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm)                     $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            $errors[] = 'An account with that email already exists.';
        } else {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (email, password) VALUES (?, ?)");
                $stmt->execute([$email, $hash]);
                $userId = (int)$conn->lastInsertId();

                /* Log the new user in */
                loginUser($userId, $email);

                /* Owner email? → owner panel. Otherwise → their cards list */
                if (isOwnerEmail($email)) {
                    header('Location: owner.php');
                } else {
                    header('Location: list.php');
                }
                exit;

            } catch (Exception $e) {
                /* Never show driver messages to a visitor; log them instead. */
                error_log('signup failed: ' . $e->getMessage());
                $errors[] = 'Signup failed. Please try again.';
            }
        }
    }
}

$appName = getSetting('app_name', 'ReviewAI');

public_head($appName . ' — Create your account', ['auth.css'], rg_popup_head());
auth_shell_start($appName);
auth_card_open('Create your account', 'Set up your account with an email address and a password.');
?>
  <form id="form" method="POST">
    <?= csrfField() ?>
    <?php
    form_field('email', 'Email address', 'email', 'required maxlength="254" autocomplete="email"', $_POST['email'] ?? '');
    form_field('password', 'Password', 'password', 'required minlength="6" maxlength="72" autocomplete="new-password"');
    form_field('password_confirm', 'Confirm password', 'password', 'required minlength="6" maxlength="72" autocomplete="new-password"');
    ?>
    <p class="hint">Use at least 6 characters.</p>
    <?php
    status_block($errors);
    ?>
    <div class="form-actions">
      <button class="button button-dark" type="submit">Create account →</button>
    </div>
  </form>
  <p class="form-foot">Already have an account? <a href="<?= e(url('login.php')) ?>">Log in</a></p>
<?php
auth_card_close();
print_rg_popup('signup');
