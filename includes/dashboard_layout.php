<?php
/**
 * dashboard_layout.php — the shell every signed-in business page sits in.
 *
 * Markup and class names come from the original design's dashboard shell
 * (`.layout .sidebar .brand .workspace .nav .side-bottom .topbar .content`),
 * with the design's own stylesheet, app/assets/css/dashboard.css.
 *
 * Usage:
 *   echo dashboardShell($content, [
 *       'title' => 'Reviews',            // <title>
 *       'crumb' => 'Reviews',            // breadcrumb tail
 *       'page'  => 'reviews',            // which nav item is active
 *       'card'  => $card,                // review_cards row, or null for the overview
 *       'styles'=> ['css/x.css'],        // optional extra stylesheets, additive
 *   ]);
 *
 * Both the overview (dashboard.php) and the per-card pages use this one shell,
 * so the navigation can never drift apart.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

/** First letters of a name, for the avatar disc. */
function dashboardInitials($text)
{
    $text = trim((string)$text);
    if ($text === '') return '·';
    $parts = preg_split('/[\s@._-]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $first = $parts[0] ?? '·';
    $second = $parts[1] ?? '';
    $one = function_exists('mb_substr')
        ? mb_substr($first, 0, 1) . mb_substr($second, 0, 1)
        : substr($first, 0, 1) . substr($second, 0, 1);
    return strtoupper($one);
}

function dashboardShell($content, array $opts = [])
{
    $appName = getSetting('app_name', 'Review AI');
    $email   = (string)($_SESSION['user_email'] ?? '');
    $card    = $opts['card'] ?? null;
    $page    = (string)($opts['page'] ?? 'overview');
    $title   = (string)($opts['title'] ?? 'Dashboard');
    $crumb   = (string)($opts['crumb'] ?? 'Overview');

    $slug = $card ? (string)$card['slug'] : '';

    /* Nav: the overview always, then the card's four pages once a card is open. */
    $nav = [
        ['overview', 'Overview', '◫', 'dashboard.php'],
    ];
    if ($card) {
        $s = urlencode($slug);
        $nav[] = ['home',       'Card overview', '▣', 'card_home.php?slug=' . $s];
        $nav[] = ['reviews',    'Reviews',       '▤', 'card_reviews.php?slug=' . $s];
        $nav[] = ['settings',   'Settings',      '⚙', 'card_settings.php?slug=' . $s];
        $nav[] = ['wheel',      'Rewards',       '✦', 'card_wheel.php?slug=' . $s];
        $nav[] = ['membership', 'Membership',    '◈', 'card_membership.php?slug=' . $s];
    } else {
        $nav[] = ['cards', 'My cards', '▦', 'dashboard.php#cards'];
    }

    $navHtml = '';
    foreach ($nav as $item) {
        $key   = $item[0];
        $label = $item[1];
        $icon  = $item[2];
        $href  = $item[3];
        $active = $key === $page ? ' class="active" aria-current="page"' : '';
        $navHtml .= '<a href="' . e($href) . '"' . $active . '>'
            . '<span class="nav-icon" aria-hidden="true">' . $icon . '</span>' . e($label) . '</a>';
    }

    $workspaceName = e($card ? (string)$card['name'] : $appName);
    $workspaceNote = e($card ? 'Review card' : 'Owner workspace');
    $workspaceIcon = e(dashboardInitials($card ? (string)$card['name'] : $appName));
    $userInitials  = e(dashboardInitials($email));
    $sideName      = e($card ? (string)$card['name'] : 'Signed in');
    $sideEmail     = e($email);
    $crumbSafe     = e($crumb);
    $appSafe       = e($appName);
    $titleSafe     = e($title);
    $contentSafe   = $content;

    $viewPage = '';
    if ($card) {
        $viewPage = '<a class="button button-outline" href="review.php?slug=' . urlencode($slug)
            . '" target="_blank" rel="noopener">View page ↗</a>';
    }

    $trialNote = '';
    if ($card && !empty($card['expiry_date'])) {
        $days = (int)ceil((strtotime((string)$card['expiry_date']) - time()) / 86400);
        if ($days < 0) {
            $trialNote = '<span class="pill" style="color:#9e3e37;border-color:#e7c7c0;background:#fff1ee">Expired</span>';
        } elseif ($days <= 7) {
            $trialNote = '<span class="pill">' . $days . ' day' . ($days === 1 ? '' : 's') . ' left</span>';
        }
    }

    $fonts = e(asset('css/fonts.css'));
    $css   = e(asset('css/dashboard-green.css'));

    /* A page can ask for its own additive stylesheets by passing them in
       $opts['styles'] (path relative to /assets/). Nothing loads unless asked. */
    $extraLinks = '';
    foreach ((array)($opts['styles'] ?? []) as $extraSheet) {
        $extraLinks .= '    <link rel="stylesheet" href="' . e(asset((string)$extraSheet)) . '">' . "\n";
    }
    $csrfMeta = csrfMeta();

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    {$csrfMeta}
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#173d2e">
    <title>{$titleSafe} · {$appSafe}</title>
    <link rel="stylesheet" href="{$fonts}">
    <link rel="stylesheet" href="{$css}">
{$extraLinks}</head>
<body>
<div class="layout">

    <aside class="sidebar" id="sidebar" aria-label="Dashboard sidebar">
        <a class="brand" href="dashboard.php"><span class="mark" aria-hidden="true">✳</span><span>{$appSafe}</span></a>

        <span class="workspace-label">YOUR BUSINESS</span>
        <div class="workspace">
            <span class="workspace-icon" aria-hidden="true">{$workspaceIcon}</span>
            <div>
                <strong>{$workspaceName}</strong>
                <small>{$workspaceNote}</small>
            </div>
        </div>

        <span class="nav-label">WORKSPACE</span>
        <nav class="nav" aria-label="Dashboard navigation">{$navHtml}</nav>

        <div class="side-bottom">
            <div class="side-user">
                <span class="avatar" aria-hidden="true">{$userInitials}</span>
                <div>
                    <strong>{$sideName}</strong>
                    <small>{$sideEmail}</small>
                </div>
            </div>
        </div>
    </aside>

    <div class="veil" id="veil"></div>

    <div class="main-column">
        <header class="topbar">
            <div class="top-left">
                <button class="menu-button" id="menu" type="button"
                        aria-label="Open navigation" aria-expanded="false" aria-controls="sidebar">☰</button>
                <div class="crumb">Workspace &nbsp;/&nbsp; <strong>{$crumbSafe}</strong></div>
            </div>
            <div class="top-right">
                {$trialNote}
                <span class="top-email">{$sideEmail}</span>
                {$viewPage}
                <a class="button button-outline" href="logout.php">Log out</a>
            </div>
        </header>

        <main class="content" id="main-content" tabindex="-1">
{$contentSafe}
        </main>
    </div>
</div>

<script>
/* Mobile navigation — the same behaviour as the design's shell. */
(function () {
    var sidebar = document.getElementById('sidebar');
    var veil = document.getElementById('veil');
    var menu = document.getElementById('menu');
    if (!sidebar || !veil || !menu) return;

    function isMobile() { return window.matchMedia('(max-width: 850px)').matches; }

    function toggle(open) {
        sidebar.classList.toggle('open', open);
        veil.classList.toggle('open', open && isMobile());
        menu.setAttribute('aria-expanded', String(open && isMobile()));
        menu.setAttribute('aria-label', open && isMobile() ? 'Close navigation' : 'Open navigation');
        if (isMobile()) {
            sidebar.inert = !open;
            if (open) sidebar.removeAttribute('aria-hidden');
            else sidebar.setAttribute('aria-hidden', 'true');
        } else {
            sidebar.inert = false;
            sidebar.removeAttribute('aria-hidden');
        }
    }

    menu.addEventListener('click', function () { toggle(!sidebar.classList.contains('open')); });
    veil.addEventListener('click', function () { toggle(false); });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && sidebar.classList.contains('open')) { toggle(false); menu.focus(); }
    });
    window.addEventListener('resize', function () { toggle(false); });
    toggle(false);
})();
</script>
</body>
</html>
HTML;
}
