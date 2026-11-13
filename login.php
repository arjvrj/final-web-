<?php
/**
 * login.php — Sign in
 * Owners → owner.php
 * Regular users → list.php (or ?redirect= target)
 *
 * Logic unchanged from the purchased application. Only the markup was replaced
 * with the original ReviewGrow design (app/assets/css/auth.css).
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/public_layout.php';
/* Marketing popups. These two pages are marketing pages, so a promotion may
   appear here; nothing in the dashboard, the owner panel or review.php loads it. */
require_once __DIR__ . '/includes/popups.php';
define('RG_PUBLIC_PAGE', 'login');

/* Already logged in? Send them to the right place */
if (isLoggedIn()) {
    header('Location: ' . (isOwner() ? 'owner.php' : 'list.php'));
    exit;
}

/**
 * Same-site path check. The rule now lives in includes/links.php as
 * rg_safe_local_redirect() so it has one definition and can be tested; this
 * wrapper keeps every call site in this file unchanged.
 */
function is_safe_local_redirect(string $target): bool
{
    return rg_safe_local_redirect($target);
}

$errors   = [];
$redirect = $_GET['redirect'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    /* Throttle: five wrong guesses in a row from this browser and it has to wait.
       This slows down guessing from one browser; it is not a defence against a
       distributed attack (that needs a firewall or a login table — see the report). */
    $attempts = (int)($_SESSION['login_attempts'] ?? 0);
    $lastTry  = (int)($_SESSION['login_last_try'] ?? 0);

    if ($attempts >= 5 && (time() - $lastTry) < 60) {
        $errors[] = 'Too many attempts. Please wait a minute and try again.';
    } else {
        if ($attempts >= 5) {
            $_SESSION['login_attempts'] = 0;   // the minute has passed
            $attempts = 0;
        }

        $email    = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $errors[] = 'Please enter your email and password.';
        } else {
            $stmt = $conn->prepare("SELECT id, email, password FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                loginUser((int)$user['id'], $user['email']);
                unset($_SESSION['login_attempts'], $_SESSION['login_last_try']);

                /* Owner → owner panel */
                if (isOwnerEmail($user['email'])) {
                    header('Location: owner.php');
                    exit;
                }

                /* Honor safe ?redirect= — same-site paths only.
                   Hardened: "//evil.com" and "/\evil.com" are protocol-relative
                   URLs that a browser would follow off-site. */
                if (is_safe_local_redirect($redirect)) {
                    header('Location: ' . $redirect);
                    exit;
                }

                /* Regular users land on their cards list */
                header('Location: list.php');
                exit;
            }

            $errors[] = 'Invalid email or password.';
            $_SESSION['login_attempts'] = $attempts + 1;
            $_SESSION['login_last_try'] = time();
        }
    }
}

$appName = getSetting('app_name', 'ReviewAI');

public_head($appName . ' — Log in', ['auth.css'], rg_popup_head());
auth_shell_start($appName);
auth_card_open('Welcome back', 'Log in to manage your business.');
?>
  <form id="form" method="POST">
    <?= csrfField() ?>
    <?php
    form_field('email', 'Email address', 'email', 'required maxlength="254" autocomplete="email"', $_POST['email'] ?? '');
    form_field('password', 'Password', 'password', 'required autocomplete="current-password"');
    status_block($errors);
    ?>
    <div class="form-actions">
      <button class="button button-dark" type="submit">Log in →</button>
    </div>
  </form>
  <p class="form-foot">New to <?= e($appName) ?>? <a href="<?= e(url('signup.php')) ?>">Create an account</a></p>
  <p class="form-foot">Need help signing in? Contact support.</p>
<?php
auth_card_close();
print_rg_popup('login');
