<?php
/**
 * card_layout.php — the shell for the four per-card pages.
 *
 * It used to hold its own Tailwind sidebar. It now delegates to the one
 * dashboard shell (dashboard_layout.php), so the navigation is identical on
 * every signed-in page and only has one place to change.
 *
 * Expects: $content (buffered HTML), $card (the review_cards row), optional $page
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/dashboard_layout.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$slug   = trim($_GET['slug'] ?? '');

$stmt = $conn->prepare("SELECT * FROM review_cards WHERE slug = ? AND user_id = ? LIMIT 1");
$stmt->execute([$slug, $userId]);
$card = $stmt->fetch();

if (!$card) {
    header('Location: dashboard.php');
    exit;
}

$currentPage = $page ?? 'home';

$labels = [
    'home'       => ['Card overview', 'Card overview'],
    'reviews'    => ['Reviews', 'Reviews'],
    'settings'   => ['Settings', 'Settings'],
    'wheel'      => ['Rewards', 'Rewards'],
    'membership' => ['Membership', 'Membership'],
];
[$title, $crumb] = $labels[$currentPage] ?? ['Card', 'Card'];

echo dashboardShell($content ?? '', [
    'title' => $title,
    'crumb' => $crumb,
    'page'  => $currentPage,
    'card'  => $card,
    'styles'=> $pageStyles ?? [],
]);
