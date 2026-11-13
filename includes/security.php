<?php
/**
 * security.php — CSRF tokens and session hardening.
 *
 * Every state-changing request in this application is a POST. Each one is now
 * checked against a per-session token before it is allowed to touch the database.
 * The token is read from (in order): $_POST['_csrf'], the X-CSRF-Token header, or
 * the 'csrf_token' field that the page scripts send with fetch().
 */

/** Bytes of entropy in a token. 32 bytes = 64 hex characters. */
const RG_CSRF_BYTES = 32;

/**
 * Start the session with secure cookie flags. Call this instead of session_start().
 * Secure is set only when the request really is HTTPS, so local http still works.
 */
function rg_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    /* Keep the cookie scoped to the folder the app lives in, so two installs on one
       host do not share a session. */
    $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    $dir = rtrim($dir, '/');
    $cookiePath = ($dir === '' || $dir === '.') ? '/' : $dir . '/';

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $cookiePath,
        'domain'   => '',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/** The session's CSRF token, created on first use. */
function csrfToken(): string
{
    $stored = $_SESSION['_csrf'] ?? null;
    if (!is_string($stored) || !preg_match('/^[0-9a-f]{64}$/', $stored)) {
        try {
            $_SESSION['_csrf'] = bin2hex(random_bytes(RG_CSRF_BYTES));
        } catch (Exception $e) {
            /* Extremely unlikely; a weak token is still better than no check. */
            $_SESSION['_csrf'] = hash('sha256', uniqid((string)mt_rand(), true));
        }
    }
    return $_SESSION['_csrf'];
}

/** Hidden input for a normal <form>. */
function csrfField(): string
{
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

/** <meta> tag so page scripts can send the token with fetch(). */
function csrfMeta(): string
{
    return '<meta name="csrf-token" content="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

/** True when the request carries the session's token. */
function csrfValid($token = null): bool
{
    if (empty($_SESSION['_csrf'])) return false;

    $sent = $token;
    if ($sent === null) {
        $sent = $_POST['_csrf']
            ?? $_POST['csrf_token']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? '';
    }
    if (!is_string($sent) || $sent === '') return false;

    return hash_equals((string)$_SESSION['_csrf'], $sent);
}

/**
 * Stop the request unless the POST carries a valid token.
 * JSON callers get JSON, everyone else is sent back with a plain 403.
 */
function requireCsrf(): void
{
    if (csrfValid()) return;

    http_response_code(403);

    /* jQuery/fetch POSTs from our own pages send X-Requested-With and often a
       wildcard Accept, so the content-type test alone missed them and a JSON
       endpoint answered with an HTML page. Ask for JSON when any of the three
       signals says this is a script call. */
    $acceptsJson = stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
        || stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false
        || stripos((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'xmlhttprequest') !== false;

    if ($acceptsJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Your session expired. Please reload the page and try again.']);
        exit;
    }

    echo '<!doctype html><meta charset="utf-8"><title>Request blocked</title>'
        . '<p style="font:16px/1.5 system-ui;padding:2rem">Your session expired or the form was not sent from this site. '
        . '<a href="javascript:history.back()">Go back</a>, reload the page and try again.</p>';
    exit;
}

/** Convenience: reject anything that is not a POST before doing work. */
function requirePost(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }
}
