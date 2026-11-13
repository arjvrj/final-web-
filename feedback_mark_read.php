<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
requirePost();
requireCsrf();

header('Content-Type: application/json');

$userId = (int)$_SESSION['user_id'];
$fid    = (int)($_POST['feedback_id'] ?? 0);

$stmt = $conn->prepare("
    UPDATE feedbacks f
    JOIN review_cards rc ON f.card_id = rc.id
    SET f.status = 'read'
    WHERE f.id = ? AND rc.user_id = ? AND f.status = 'new'
");
$stmt->execute([$fid, $userId]);

echo json_encode(['success' => true]);