<?php
/**
 * pay_create_order.php — POST: start a Razorpay payment for one plan period.
 *
 * The browser sends only a card id and a plan id. The amount is read from the
 * plans table here and never taken from the request.
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

$userId = (int)$_SESSION['user_id'];
$cardId = (int)($_POST['card_id'] ?? 0);
$planId = (int)($_POST['plan_id'] ?? 0);

/* Ownership first: another business's card must be indistinguishable from a
   card that does not exist. */
$stmt = $conn->prepare("SELECT id, name, slug FROM review_cards WHERE id = ? AND user_id = ? LIMIT 1");
$stmt->execute([$cardId, $userId]);
$card = $stmt->fetch();
if (!$card) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Card not found']);
    exit;
}

$plan = rg_find_plan($conn, $planId);
if (!$plan) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Plan not found']);
    exit;
}

$cfg    = rg_payment_config([
    'payments_mode'           => getSetting('payments_mode', 'disabled'),
    'razorpay_key_id'         => getSetting('razorpay_key_id', ''),
    'razorpay_key_secret'     => getSetting('razorpay_key_secret', ''),
    'razorpay_timeout'        => getSetting('razorpay_timeout', '15'),
]);
$status = rg_payment_status($cfg);
if (!$status['enabled']) {
    echo json_encode([
        'success' => false,
        'error'   => rg_payment_message($status['reason']),
        'reason'  => $status['reason'],
    ]);
    exit;
}

$receipt = 'rc' . $cardId . '-p' . $planId . '-' . time();
$receipt = substr($receipt, 0, 40);

$order = rg_create_order($cfg, $plan, $userId, $cardId, $receipt);
if (!$order['ok']) {
    echo json_encode([
        'success' => false,
        'error'   => rg_payment_message($order['reason']),
        'reason'  => $order['reason'],
    ]);
    exit;
}

rg_store_order($conn, $userId, $cardId, $planId, $order['order_id'], $order['amount_paise'], $order['mode']);

echo json_encode([
    'success'      => true,
    'order_id'     => $order['order_id'],
    'amount'       => $order['amount_paise'],
    'currency'     => RG_CURRENCY,
    'key_id'       => $cfg['key_id'],
    'mode'         => $order['mode'],
    'plan_name'    => (string)$plan['name'],
    'card_name'    => (string)$card['name'],
]);
