<?php
/**
 * card_update_wheel.php — save the Rewards & Spin Wheel settings.
 *
 * Owner-only (ownership is proved through review_cards.user_id), CSRF-checked.
 * Everything is written together or not at all, and the whole save is refused
 * when the probabilities do not add up to exactly 100%.
 *
 * What it deliberately does NOT touch: spins that already happened. Each spin and
 * each coupon carries its own snapshot of the prize, so editing the campaign now
 * cannot rewrite yesterday's result.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/wheel.php';

requireLogin();
requirePost();
requireCsrf();

header('Content-Type: application/json');

$userId = (int)$_SESSION['user_id'];
$slug   = trim((string)($_POST['slug'] ?? ''));
$action = (string)($_POST['do'] ?? 'save');

$stmt = $conn->prepare('SELECT * FROM review_cards WHERE slug = ? AND user_id = ? LIMIT 1');
$stmt->execute([$slug, $userId]);
$card = $stmt->fetch();

if (!$card) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Card not found.']);
    exit;
}

/* ---------- mark a coupon as used ---------- */

if ($action === 'redeem') {
    $code = strtoupper(trim((string)($_POST['code'] ?? '')));
    if (!rg_wheel_code_looks_valid($code)) {
        echo json_encode(['success' => false, 'error' => 'That does not look like a coupon code.']);
        exit;
    }

    $stmt = $conn->prepare(
        'UPDATE wheel_coupons SET redeemed_at = NOW(), redeemed_note = ?
          WHERE code = ? AND card_id = ? AND redeemed_at IS NULL'
    );
    $stmt->execute([substr(trim((string)($_POST['note'] ?? '')), 0, 240), $code, (int)$card['id']]);

    echo json_encode([
        'success' => $stmt->rowCount() > 0,
        'error'   => $stmt->rowCount() > 0 ? null : 'No unused coupon with that code for this business.',
    ]);
    exit;
}

if ($action === 'unredeem') {
    $code = strtoupper(trim((string)($_POST['code'] ?? '')));
    $stmt = $conn->prepare('UPDATE wheel_coupons SET redeemed_at = NULL, redeemed_note = ? WHERE code = ? AND card_id = ?');
    $stmt->execute(['', $code, (int)$card['id']]);
    echo json_encode(['success' => $stmt->rowCount() > 0]);
    exit;
}

/* ---------- save the campaign ---------- */

$campaign = rg_wheel_campaign($conn, (int)$card['id'], true);
if (!$campaign) {
    echo json_encode(['success' => false, 'error' => 'Could not open the campaign.']);
    exit;
}

$enabled     = !empty($_POST['enabled']);
$startsAt    = trim((string)($_POST['starts_at'] ?? ''));
$endsAt      = trim((string)($_POST['ends_at'] ?? ''));
$expiryDays  = max(1, min(365, (int)($_POST['coupon_expiry_days'] ?? 30)));
$inventory   = max(0, min(100000, (int)($_POST['inventory_limit'] ?? 0)));
$note        = substr(trim((string)($_POST['redemption_note'] ?? '')), 0, 240);

$dateOk = static function (string $value): bool {
    if ($value === '') return true;
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false;
};

if (!$dateOk($startsAt) || !$dateOk($endsAt)) {
    echo json_encode(['success' => false, 'error' => 'Dates must look like 2026-11-04.']);
    exit;
}
if ($startsAt !== '' && $endsAt !== '' && strtotime($endsAt) < strtotime($startsAt)) {
    echo json_encode(['success' => false, 'error' => 'The campaign cannot end before it starts.']);
    exit;
}

/* Build the segment list that was posted, in display order. */
$labels    = (array)($_POST['label'] ?? []);
$weights   = (array)($_POST['weight'] ?? []);
$stocks    = (array)($_POST['stock'] ?? []);
$actives   = (array)($_POST['active'] ?? []);
$ids       = (array)($_POST['segment_id'] ?? []);

$posted = [];
foreach ($campaign['segments'] as $index => $existing) {
    $id = (int)$existing['id'];
    $label = trim((string)($labels[$id] ?? $existing['label']));
    if ($label === '') $label = (string)$existing['label'];

    $rawWeight = str_replace(',', '.', trim((string)($weights[$id] ?? '')));
    if (!is_numeric($rawWeight)) {
        echo json_encode(['success' => false, 'error' => 'Every probability must be a number.']);
        exit;
    }

    $posted[] = [
        'id'       => $id,
        'label'    => substr($label, 0, 120),
        'weight'   => (int)round((float)$rawWeight * RG_WHEEL_UNIT / 100),
        'stock'    => max(0, min(100000, (int)($stocks[$id] ?? 0))),
        'active'   => isset($actives[$id]) ? 1 : 0,
        'kind'     => (string)$existing['kind'],
    ];
}

$check = rg_wheel_validate($posted);
if (!$check['ok']) {
    echo json_encode([
        'success' => false,
        'error'   => implode(' ', $check['errors']),
        'total'   => $check['total'],
    ]);
    exit;
}

try {
    $conn->beginTransaction();

    $update = $conn->prepare(
        'UPDATE wheel_segments SET label = ?, weight = ?, inventory_limit = ?, is_active = ?, sort_order = ? WHERE id = ? AND campaign_id = ?'
    );
    foreach ($posted as $order => $segment) {
        $update->execute([
            $segment['label'],
            $segment['weight'],
            $segment['stock'],
            $segment['active'],
            $order,
            (int)$segment['id'],
            (int)$campaign['id'],
        ]);
    }

    $save = $conn->prepare(
        'UPDATE wheel_campaigns
            SET enabled = ?, starts_at = ?, ends_at = ?, coupon_expiry_days = ?,
                inventory_limit = ?, redemption_note = ?
          WHERE id = ? AND card_id = ?'
    );
    $save->execute([
        $enabled ? 1 : 0,
        $startsAt === '' ? null : $startsAt,
        $endsAt === '' ? null : $endsAt,
        $expiryDays,
        $inventory,
        $note,
        (int)$campaign['id'],
        (int)$card['id'],
    ]);

    $conn->commit();
} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => 'Could not save. Please try again.']);
    exit;
}

echo json_encode([
    'success' => true,
    'enabled' => $enabled,
    'message' => $enabled
        ? 'Saved. The wheel is live on your customer page.'
        : 'Saved. The wheel is switched off, so it does not appear for customers.',
]);
