<?php
/**
 * payment_webhook.php — Razorpay calls this, not a browser.
 *
 * Rules:
 *   - the body is read raw, because the signature covers the exact bytes
 *   - the signature is checked before the JSON is even parsed
 *   - the event id is recorded first; a delivery we have seen is answered 200
 *     and does nothing (Razorpay retries, we must not activate twice)
 *   - answers quickly and never converts an internal error into a retry storm
 *     it cannot survive: a handled event always returns 200
 *
 * No session and no CSRF: the HMAC signature is the authentication here.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/payment_flow.php';

header('Content-Type: application/json');

$rawBody   = file_get_contents('php://input');
$signature = (string)($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '');

if (($rawBody === false || $rawBody === '') || $signature === '') {
    http_response_code(400);
    echo json_encode(['status' => 'ignored', 'reason' => 'missing_body_or_signature']);
    exit;
}

$cfg = rg_payment_config([
    'payments_mode'           => getSetting('payments_mode', 'disabled'),
    'razorpay_key_id'         => getSetting('razorpay_key_id', ''),
    'razorpay_key_secret'     => getSetting('razorpay_key_secret', ''),
    'razorpay_webhook_secret' => getSetting('razorpay_webhook_secret', ''),
    'razorpay_timeout'        => getSetting('razorpay_timeout', '15'),
]);

/* The webhook secret is separate from the API secret on purpose. If it is not
   configured, nothing is accepted — no fallback to the API secret. */
if ($cfg['webhook_secret'] === '' || !rg_verify_webhook_signature($rawBody, $signature, $cfg['webhook_secret'])) {
    http_response_code(400);
    echo json_encode(['status' => 'rejected', 'reason' => 'bad_signature']);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['status' => 'rejected', 'reason' => 'bad_json']);
    exit;
}

$eventType = (string)($payload['event'] ?? '');
$eventId   = (string)($payload['id'] ?? '');
if ($eventId === '') {
    /* No id means we cannot spot a retry; refuse rather than risk a double activation. */
    http_response_code(400);
    echo json_encode(['status' => 'rejected', 'reason' => 'missing_event_id']);
    exit;
}

$environment = (strpos($cfg['key_id'], 'rzp_live_') === 0) ? 'live' : 'test';

/* Record first: a duplicate delivery is a no-op. */
$isNew = rg_record_webhook_event($conn, $eventId, $eventType, true, $environment, $rawBody);
if (!$isNew) {
    echo json_encode(['status' => 'duplicate', 'event' => $eventType]);
    exit;
}

try {
    if ($eventType === 'payment.captured' || $eventType === 'order.paid') {
        $entity    = $payload['payload']['payment']['entity'] ?? [];
        $orderId   = (string)($entity['order_id'] ?? '');
        $paymentId = (string)($entity['id'] ?? '');

        if ($orderId === '' || $paymentId === '') {
            echo json_encode(['status' => 'ignored', 'reason' => 'no_order_in_payload']);
            exit;
        }

        $row = rg_find_payment_by_order($conn, $orderId);
        if (!$row) {
            /* An order we never created. Nothing to activate. */
            echo json_encode(['status' => 'ignored', 'reason' => 'unknown_order']);
            exit;
        }

        /* The amount in the payload must match what we stored. */
        if ((int)($entity['amount'] ?? -1) !== (int)$row['amount_paise']) {
            echo json_encode(['status' => 'ignored', 'reason' => 'amount_mismatch']);
            exit;
        }
        if ((string)($entity['currency'] ?? '') !== (string)$row['currency']) {
            echo json_encode(['status' => 'ignored', 'reason' => 'currency_mismatch']);
            exit;
        }

        $plan       = rg_find_plan($conn, (int)$row['plan_id']);
        $activation = rg_activate_order($conn, (int)$row['id'], $paymentId, '', $plan);

        echo json_encode([
            'status'    => $activation['ok'] ? 'ok' : 'error',
            'activated' => $activation['activated'] ?? false,
            'already'   => $activation['already'] ?? false,
        ]);
        exit;
    }

    if ($eventType === 'payment.failed') {
        $entity  = $payload['payload']['payment']['entity'] ?? [];
        $orderId = (string)($entity['order_id'] ?? '');
        $paymentId = (string)($entity['id'] ?? '');
        if ($orderId !== '') rg_mark_failed($conn, $orderId, $paymentId);
        echo json_encode(['status' => 'ok', 'recorded' => 'failed']);
        exit;
    }

    /* Anything else is acknowledged and ignored: we do not have handlers for it. */
    echo json_encode(['status' => 'ignored', 'event' => $eventType]);

} catch (Throwable $e) {
    /* Logged only; the visitor sees nothing. A 500 tells Razorpay to retry, and a
       retry is harmless because the event id is already stored. */
    error_log('razorpay webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error']);
}
