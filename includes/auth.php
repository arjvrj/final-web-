<?php
/**
 * Authentication — Regular users + owner detection.
 * All accounts (including the owner) live in the `users` table.
 * The owner is identified by matching `users.email` against the
 * `owner_email` setting (also mirrored in config.php).
 */

require_once __DIR__ . '/security.php';

/* Session cookie: HttpOnly always, Secure on HTTPS, SameSite=Lax, scoped to the
   app folder. See includes/security.php. */
rg_start_session();

/* CSRF tokens are available anywhere auth is loaded. */

/* ---------- Login state ---------- */

function isLoggedIn() {
    return !empty($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        $current = $_SERVER['REQUEST_URI'] ?? '/dashboard.php';
        $current = ltrim(preg_replace('#^https?://[^/]+#', '', $current), '/');
        header('Location: login.php?redirect=' . urlencode($current));
        exit;
    }
}

/* ---------- Current user ---------- */

function currentUser() {
    static $cached = null;
    if ($cached !== null) return $cached;

    if (!isLoggedIn()) return null;

    global $conn;
    $stmt = $conn->prepare("SELECT id, email FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $u = $stmt->fetch();
    if (!$u) return null;

    $cached = [
        'id'    => (int)$u['id'],
        'email' => $u['email'],
    ];
    return $cached;
}

/* ---------- Owner detection ---------- */

function isOwner() {
    $u = currentUser();
    return $u && isOwnerEmail($u['email']);
}

function requireOwner() {
    requireLogin();
    if (!isOwner()) {
        header('Location: dashboard.php');
        exit;
    }
}

/* ---------- Login / logout ---------- */

function loginUser($userId, $email) {
    session_regenerate_id(true);
    $_SESSION['user_id']    = (int)$userId;
    $_SESSION['user_email'] = $email;
    $_SESSION['login_time'] = time();
}

function logout() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}