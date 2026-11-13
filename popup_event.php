<?php
/**
 * popup_event.php — analytics for the promotion popups.
 *
 * POST only, JSON only, called by assets/js/popup.js from a marketing page.
 *
 * Why it exists separately: the popup itself is rendered with the page, but a
 * page view is not an impression — a visitor who never saw the popup (closed the
 * tab, or the trigger never fired) must not be counted. The browser reports what
 * actually happened.
 *
 * What keeps the numbers honest:
 *   - the session is started before the CSRF check (the token lives in it);
 *   - a same-origin check, so another site cannot inflate a campaign;
 *   - `id` must be an integer and `kind` one of the four known events;
 *   - one event of each kind per campaign per PHP session, so a reload or a
 *     double-fire cannot multiply the count;
 *   - the campaign must exist, and an impression must belong to a live campaign.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/popups.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function rg_popup_event_reply(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    rg_popup_event_reply(['ok' => false, 'error' => 'Method not allowed'], 405);
}

/* The CSRF token lives in the session and auth.php is not loaded here, so this
   endpoint has to start it itself before checking the token. */
rg_start_session();

/* Same origin only. A missing Origin and Referer is allowed (some privacy
   browsers strip both), but a header that names another site is refused. */
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $header) {
    $value = (string)($_SERVER[$header] ?? '');
    if ($value === '') continue;
    $valueHost = strtolower((string)parse_url($value, PHP_URL_HOST));
    if ($valueHost !== '' && $host !== '' && $valueHost !== $host) {
        rg_popup_event_reply(['ok' => false, 'error' => 'Cross-site request refused'], 403);
    }
}

requireCsrf();

$id   = (int)($_POST['id'] ?? 0);
$kind = (string)($_POST['kind'] ?? '');

if ($id <= 0 || !array_key_exists($kind, rg_popup_event_kinds())) {
    rg_popup_event_reply(['ok' => false, 'error' => 'Unknown event'], 422);
}

$popup = rg_popup_find($id);
if (!$popup) {
    rg_popup_event_reply(['ok' => false, 'error' => 'Unknown campaign'], 404);
}

/* An impression on a campaign that is not live now would be a fabricated view.
   Clicks, copies and dismissals are accepted slightly later: a visitor can close
   the dialog a second after the end time, and that is still a real dismissal. */
if ($kind === 'impression' && rg_popup_status($popup) !== 'active') {
    rg_popup_event_reply(['ok' => false, 'error' => 'Campaign is not live'], 409);
}

$result = rg_popup_record_event($popup, $kind);
if (empty($result['ok'])) {
    rg_popup_event_reply(['ok' => false, 'error' => 'Could not record the event'], 500);
}

rg_popup_event_reply(['ok' => true, 'counted' => !empty($result['counted'])]);
