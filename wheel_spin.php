<?php
/**
 * wheel_spin.php — the one endpoint that runs a spin.
 *
 * Public (the customer is not signed in), so it is CSRF-checked and it trusts
 * nothing from the browser except the opaque token the server itself handed out
 * after a feedback submission. The prize is drawn here, not in JavaScript.
 *
 * POST  slug, token
 * 200   { success, spin_no, spins_left, next_token, forced,
 *         segment:{label,kind,percent}, coupon:{code,percent,expires}|null,
 *         business, redemption }
 *
 * The prize is drawn from the business's configured odds, on the server, before
 * the response is written. The browser never sends a spin number and never picks
 * a result. One customer gets one spin per campaign; a second request for the
 * same token answers `already_spun` and returns the prize that was recorded.
 * 4xx   { success:false, error:'already_spun'|'unknown_spin'|'expired'|… }
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/wheel.php';

/* The CSRF token lives in the session. Without this line the session was never
   started here, `csrfValid()` compared against an empty array and EVERY spin was
   answered 403 — the wheel was rendered correctly but could never turn. The
   customer page starts its session through auth.php; this public endpoint has to
   do it itself, before the token is checked. */
rg_start_session();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function rg_wheel_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rg_wheel_json(['success' => false, 'error' => 'method_not_allowed'], 405);
}
requireCsrf();

$slug  = trim((string)($_POST['slug'] ?? ''));
$token = trim((string)($_POST['token'] ?? ''));

if ($slug === '' || $token === '') {
    rg_wheel_json(['success' => false, 'error' => 'missing_fields'], 400);
}

$card = getCardBySlug($slug);
if (!$card) {
    rg_wheel_json(['success' => false, 'error' => 'unknown_card'], 404);
}

/* An expired membership has no wheel — the same rule the rest of the page uses. */
if (!empty($card['expiry_date']) && strtotime((string)$card['expiry_date']) < time()) {
    rg_wheel_json(['success' => false, 'error' => 'expired_membership'], 403);
}

try {
    $result = rg_wheel_spin($conn, (int)$card['id'], $token);
} catch (Throwable $e) {
    /* A database that has not had the wheel migration yet is the one failure a
       shopkeeper can fix themselves, so it gets its own message instead of a
       generic server error. */
    $message = $e->getMessage();
    $schema = stripos($message, 'Unknown column') !== false
        || stripos($message, "doesn't exist") !== false
        || stripos($message, 'no such table') !== false;
    rg_wheel_json([
        'success' => false,
        'error'   => $schema ? 'schema_outdated' : 'server_error',
        'message' => $schema
            ? 'The wheel database needs its upgrade. Please run sql/migrations/2026-11-wheel-one-spin.sql.'
            : 'Something went wrong. Please try again.',
    ], $schema ? 503 : 500);
}

if (empty($result['ok'])) {
    $error = (string)($result['error'] ?? 'unknown');

    $status = 400;
    $messages = [
        'already_spun'      => 'Your spin for this campaign has already been used.',
        'expired'           => 'This spin token has expired. Reload the page to get a fresh one.',
        'unknown_spin'      => 'This spin could not be found. Please reload the page.',
        'not_available'     => 'This spin is no longer available.',
        'bad_odds'          => 'The business is still setting up the prizes.',
        'not_configured'    => 'The wheel is not running right now.',
        'no_prizes'         => 'The wheel has no prizes right now.',
        'db_error'          => 'Something went wrong saving the result. Please try again.',
        'server_error'      => 'Something went wrong. Please try again.',
        'no_more_spins'     => 'Your spin for this campaign has already been used. Thanks for taking part!',
        'not_eligible'      => 'Complete your feedback first to unlock your free spin.',
        'schema_outdated'   => 'The wheel database needs its upgrade. Please run sql/migrations/2026-11-wheel-one-spin.sql.',
    ];
    if ($error === 'already_spun' || $error === 'expired') $status = 409;

    rg_wheel_json([
        'success' => false,
        'error'   => $error,
        'message' => $messages[$error] ?? 'This spin is not available.',
        'result'  => $result['result'] ?? null,
    ], $status);
}

/* The customer-facing shape: no internal ids, no weights, no cost data. */
$coupon = $result['coupon'] ?? null;
$segment = $result['segment'] ?? ['label' => '', 'kind' => 'thanks', 'percent' => null];

rg_wheel_json([
    'success'      => true,
    'spin_no'      => (int)($result['spin_no'] ?? 1),
    'spins_left'   => (int)($result['spins_left'] ?? 0),
    'next_token'   => isset($result['next_token']) && $result['next_token'] !== null ? (string)$result['next_token'] : null,
    'forced'       => !empty($result['forced']),
    'segment'      => [
        'index'   => isset($segment['index']) && $segment['index'] !== null ? (int)$segment['index'] : null,
        'label'   => (string)$segment['label'],
        'kind'    => (string)$segment['kind'],
        'percent' => $segment['percent'] === null ? null : (float)$segment['percent'],
    ],
    'coupon'       => $coupon ? [
        'code'    => (string)$coupon['code'],
        'percent' => (float)$coupon['percent'],
        'expires' => (string)$coupon['expires'],
    ] : null,
    'business'     => (string)$card['name'],
    'redemption'   => rg_wheel_redemption_note($conn, (int)$card['id']),
]);
