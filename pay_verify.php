<?php
/**
 * pay_verify.php — POST: the browser says "Razorpay finished". Prove it.
 *
 * Steps, in this order:
 *   1. the order belongs to the signed-in business
 *   2. the checkout signature is valid for THIS order and THIS payment
 *   3. Razorpay says the order is paid and the payment is captured, for the
 *      stored amount, in INR, attached to this order
 *   4. the row moves created → paid exactly once, and only then is the card
 *      activated
 *
 * Step 1 of those four is the only one that involves the browser at all.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payment_flow.php';

requireLogin();

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}
requireCsrf();

$userId        = (int)$_SESSION['user_id'];
$orderId       = trim((string)($_POST['razorpay_order_id'] ?? ''));
$paymentId     = trim((string)($_POST['razorpay_payment_id'] ?? ''));
$signature     = trim((string)($_POST['razorpay_signature'] ?? ''));

if (!preg_match('/^order_[A-Za-z0-9]+$/', $orderId) || !preg_match('/^pay_[A-Za-z0-9]+$/', $paymentId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid payment reference.']);
    exit;
}

/* 1. ownership */
$row = rg_find_order_row($conn, $orderId, $userId);
if (!$row) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Order not found.']);
    exit;
}

/* Already paid? Say so plainly instead of activating twice. */
if ($row['status'] === 'paid') {
    echo json_encode(['success' => true, 'already' => true, 'message' => 'This payment was already recorded.']);
    exit;
}

$cfg = rg_payment_config([
    'payments_mode'           => getSetting('payments_mode', 'disabled'),
    'razorpay_key_id'         => getSetting('razorpay_key_id', ''),
    'razorpay_key_secret'     => getSetting('razorpay_key_secret', ''),
    'razorpay_timeout'        => getSetting('razorpay_timeout', '15'),
]);

/* 2. signature */
if (!rg_verify_order_signature($orderId, $paymentId, $signature, $cfg['key_secret'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'The payment could not be verified.']);
    exit;
}

/* 3. ask Razorpay */
$confirm = rg_confirm_order_payment($cfg, $orderId, $paymentId, (int)$row['amount_paise']);
if (!$confirm['ok']) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => rg_payment_message($confirm['reason']),
        'reason'  => $confirm['reason'],
    ]);
    exit;
}

/* 4. activate, once */
$plan      = rg_find_plan($conn, (int)$row['plan_id']);
$activation = rg_activate_order($conn, (int)$row['id'], $paymentId, $signature, $plan);

if (!$activation['ok']) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => rg_payment_message($activation['reason']), 'reason' => $activation['reason']]);
    exit;
}

echo json_encode([
    'success'   => true,
    'activated' => $activation['activated'],
    'already'   => $activation['already'],
    'message'   => 'Payment received. Your plan is active.',
]);
