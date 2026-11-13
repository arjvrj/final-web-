<?php
/**
 * card_reactivate_trial.php — POST: start the one free 3-day trial.
 *
 * Rules (Stage 7):
 *   - one trial per business account, recorded in users.trial_started_at
 *   - a second request never grants another trial, ever
 *   - a card that is currently paid is never overwritten by a trial
 *   - eligibility is decided here, on the server; the button is only a hint
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payment_flow.php';

requireLogin();
requirePost();
requireCsrf();

header('Content-Type: application/json');

$userId = (int)$_SESSION['user_id'];
$cardId = (int)($_POST['card_id'] ?? 0);

$stmt = $conn->prepare("SELECT id, name, slug, membership, expiry_date FROM review_cards WHERE id = ? AND user_id = ? LIMIT 1");
$stmt->execute([$cardId, $userId]);
$card = $stmt->fetch();
if (!$card) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Card not found']);
    exit;
}

/* A paid card must never be downgraded by a trial request. */
if (rg_card_is_paid($card)) {
    echo json_encode(['success' => false, 'error' => 'This card already has an active paid plan.']);
    exit;
}

if (rg_trial_used($conn, $userId)) {
    echo json_encode([
        'success'       => false,
        'error'         => 'The free trial has already been used on this account. Choose a plan to continue.',
        'trial_used'    => true,
    ]);
    exit;
}

/* Grant it once: the marker is written before the card is touched, so two
   simultaneous requests cannot both get through. */
$mark = $conn->prepare("UPDATE users SET trial_started_at = NOW() WHERE id = ? AND trial_started_at IS NULL");
$mark->execute([$userId]);
if ($mark->rowCount() !== 1) {
    echo json_encode([
        'success'    => false,
        'error'      => 'The free trial has already been used on this account. Choose a plan to continue.',
        'trial_used' => true,
    ]);
    exit;
}

$stmt = $conn->prepare("
    UPDATE review_cards
    SET membership = 'trial', expiry_date = DATE_ADD(NOW(), INTERVAL " . RG_TRIAL_DAYS . " DAY)
    WHERE id = ? AND user_id = ?
      AND (membership = 'trial' OR expiry_date IS NULL OR expiry_date < NOW())
");
$stmt->execute([$cardId, $userId]);

echo json_encode(['success' => true, 'trial_days' => RG_TRIAL_DAYS]);
