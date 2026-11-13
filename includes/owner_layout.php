<?php
/**
 * owner_layout.php — the shell for the super-admin (owner) pages.
 *
 * Markup comes from the design's own admin panel stylesheet
 * (`admin.css`: header / nav tabs / main / .card / .stats / .table-wrap).
 * This is the app's green area, which is what the design uses for admin.
 *
 * Expects: $content (buffered HTML), optional $page. Echoes the page directly,
 * because the owner pages include it at the end of their own output.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

if (!isOwner()) {
    header('Location: login.php');
    exit;
}

$currentPage = $page ?? 'home';
$appName     = getSetting('app_name', 'App');
$email       = (string)($_SESSION['user_email'] ?? '');

$nav = [
    ['home',     'Dashboard',    'owner.php'],
    ['cards',    'Review cards', 'owner_cards.php'],
    ['users',    'Users',        'owner_users.php'],
    ['plans',    'Plans',        'owner_plans.php'],
    ['homepage', 'Homepage',     'owner_homepage.php'],
    ['marketing', 'Marketing',   'owner_marketing.php'],
    ['settings', 'Settings',     'owner_settings.php'],
];

$navHtml = '';
foreach ($nav as $item) {
    $current = $item[0] === $currentPage ? ' aria-current="page"' : '';
    $navHtml .= '<a href="' . e($item[2]) . '"' . $current . '>' . e($item[1]) . '</a>';
}

$css       = e(asset('css/admin.css'));

/* The shell interpolates {$csrfMeta}; without this line the owner panel shipped
   no token at all and every JSON call from these pages was answered 403. */
$csrfMeta = csrfMeta();
$appSafe   = e($appName);
$emailSafe = e($email);
$contentSafe = $content ?? '';

echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    {$csrfMeta}
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Owner panel · {$appSafe}</title>
    <link rel="stylesheet" href="{$css}">
    <style>
        /* The design styles the section tabs as buttons; these are links, so they
           get exactly the same treatment. */
        nav a { border: 0; color: #28513f; background: transparent; padding: .7rem .9rem;
                border-radius: 8px; text-decoration: none; font-weight: 500; }
        nav a:hover { background: #dfeade; }
        nav a[aria-current="page"] { background: #fff; color: #123b2a; font-weight: 700; }
        header .btn { border-color: #2f6b4f; background: #1f513b; color: #fff; }
        header .btn:hover { background: #27654a; }
        header .tag { background: #e6f4e9; }
        .modal { position: fixed; inset: 0; background: #18362b80; display: flex; align-items: center;
                 justify-content: center; padding: 1rem; z-index: 20; }
        .modal[hidden] { display: none; }
        .modal .card { max-width: 560px; width: 100%; max-height: 88vh; overflow: auto; margin: 0; }
        .modal-head { display: flex; justify-content: space-between; align-items: start; gap: 1rem; }
    </style>
</head>
<body>
<a class="skip" href="#main">Skip to content</a>

<header>
    <div class="stack" style="align-items:center">
        <strong>{$appSafe}</strong>
        <span class="tag">Owner panel</span>
    </div>
    <div class="stack" style="align-items:center">
        <span class="muted" style="color:#cfe3d5">{$emailSafe}</span>
        <a class="btn" href="dashboard.php">My dashboard</a>
        <a class="btn" href="logout.php">Log out</a>
    </div>
</header>

<nav aria-label="Owner sections">{$navHtml}</nav>

<main id="main">
{$contentSafe}
</main>
</body>
</html>
HTML;
