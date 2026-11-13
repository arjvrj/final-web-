<?php
/**
 * public_layout.php — shared presentation for the public + account pages.
 *
 * Presentation only. No database access, no session writes, no business logic,
 * no redirects. Every page keeps its own PHP block above and calls into here
 * purely for markup.
 *
 * The class names below are the ORIGINAL ReviewGrow design's own classes
 * (.card, .kicker, .intro, .form-field, .status, .button-dark, .back-link,
 * .app-header …). They are defined in app/assets/css/auth.css and must not be
 * renamed: app/assets/css is a verbatim copy of the design's stylesheets.
 */

/* public_head() emits the CSRF <meta> tag, so security.php must be loaded here
   rather than left to the page. home.php is a public page that does not load
   auth.php (which was the only thing pulling security.php in), so csrfMeta()
   was undefined there — a fatal 500 on the landing page on a real server, while
   the test harness masked it by stubbing the CSRF functions. */
require_once __DIR__ . '/security.php';

/**
 * Opens the document and loads the design's stylesheets.
 *
 * @param string $title       Page title (escaped here)
 * @param array  $stylesheets File names inside app/assets/css/
 * @param string $extraHead   Raw extra head markup (meta tags, page scripts)
 */
function public_head(string $title, array $stylesheets, string $extraHead = ''): void
{
    /* csrfToken() writes to the session, so the session has to exist before any
       output is sent. auth.php already starts one for the logged-in pages; this
       is a no-op there and covers the public pages. */
    rg_start_session();

    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<?= csrfMeta() ?>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/fonts.css')) ?>">
<?php foreach ($stylesheets as $sheet): ?>
<link rel="stylesheet" href="<?= e(asset('css/' . $sheet)) ?>">
<?php endforeach; ?>
<?= $extraHead ?>
</head>
<?php
}

/**
 * The account-page header, exactly as the design's login/register pages have it:
 * brand on the left, "Back to website" on the right, then the narrow card shell.
 */
function auth_shell_start(string $appName): void
{
    ?>
<body>
<header class="app-header">
  <div class="app-header-inner">
    <a class="app-brand" href="<?= e(url('/')) ?>"><span class="app-brand-mark">✳</span><span><?= e($appName) ?></span></a>
    <a class="header-link" href="<?= e(url('/')) ?>">Back to website</a>
  </div>
</header>
<main class="shell" id="app">
<a class="back-link" href="<?= e(url('/')) ?>">← Back to website</a>
<?php
}

/**
 * The design's card(): kicker, heading, intro paragraph, then the page content.
 */
function auth_card_open(string $title, string $intro = '', string $kicker = 'YOUR BUSINESS ACCOUNT'): void
{
    ?>
<section class="card">
  <span class="kicker"><?= e($kicker) ?></span>
  <h1><?= e($title) ?></h1>
  <?php if ($intro !== ''): ?><p class="intro"><?= e($intro) ?></p><?php endif; ?>
<?php
}

function auth_card_close(): void
{
    ?>
</section>
</main>
</body>
</html>
<?php
}

/**
 * Server-side messages in the design's own .status box.
 * The design hides it when empty (.status:empty), so this always renders.
 *
 * @param array $errors  Validation / authentication errors
 * @param string $notice A neutral informational message
 */
function status_block(array $errors = [], string $notice = ''): void
{
    if ($errors) {
        echo '<div id="status" class="status error" role="status" aria-live="polite">'
            . e(implode(' ', $errors)) . '</div>';
        return;
    }
    echo '<div id="status" class="status" role="status" aria-live="polite">' . e($notice) . '</div>';
}

/** One form field in the design's markup: label above input inside .form-field. */
function form_field(string $name, string $label, string $type = 'text', string $extra = '', string $value = ''): void
{
    ?>
<div class="form-field">
  <label for="<?= e($name) ?>"><?= e($label) ?></label>
  <input id="<?= e($name) ?>" name="<?= e($name) ?>" type="<?= e($type) ?>" value="<?= e($value) ?>" <?= $extra ?>>
</div>
<?php
}

