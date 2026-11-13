<?php
/**
 * list.php — the old "My Review Cards" page.
 *
 * Its job is now done by dashboard.php (same card list, plus the real totals),
 * and seven redirects in the app already point at dashboard.php. This file stays
 * so old links and bookmarks keep working: it sends the browser there.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

header('Location: dashboard.php', true, 302);
exit;
