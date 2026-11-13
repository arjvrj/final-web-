<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
requirePost();
requireCsrf();

header('Content-Type: application/json');

$userId = (int)$_SESSION['user_id'];
$rid    = (int)($_POST['review_id'] ?? 0);

/* Verify ownership via join */
$stmt = $conn->prepare("
    DELETE r FROM reviews r
    JOIN review_cards rc ON r.card_id = rc.id
    WHERE r.id = ? AND rc.user_id = ?
");
$stmt->execute([$rid, $userId]);

echo json_encode(['success' => $stmt->rowCount() > 0]);