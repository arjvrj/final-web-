<?php
/**
 * owner.php — Owner Panel dashboard
 * Loaded directly (no rewrites). Requires owner session.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (!isOwner()) {
    header('Location: login.php');
    exit;
}

$page = 'home';

/* ---------- Stats ---------- */
$totalUsers     = (int)$conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalCards     = (int)$conn->query("SELECT COUNT(*) FROM review_cards")->fetchColumn();
$totalReviews   = (int)$conn->query("SELECT COUNT(*) FROM reviews")->fetchColumn();
$totalFeedbacks = (int)$conn->query("SELECT COUNT(*) FROM feedbacks")->fetchColumn();

$avgRating   = (float)($conn->query("SELECT AVG(rating) FROM reviews")->fetchColumn() ?? 0);
$highRatings = (int)$conn->query("SELECT COUNT(*) FROM reviews WHERE rating >= 4")->fetchColumn();

$membershipStats = $conn->query("
    SELECT membership, COUNT(*) AS count
    FROM review_cards
    GROUP BY membership
")->fetchAll();

/* ---------- Recent activity ---------- */
$recentUsers = $conn->query("
    SELECT id, email, created_at
    FROM users
    ORDER BY created_at DESC
    LIMIT 5
")->fetchAll();

$recentCards = $conn->query("
    SELECT rc.*, u.email AS user_email
    FROM review_cards rc
    JOIN users u ON rc.user_id = u.id
    ORDER BY rc.created_at DESC
    LIMIT 5
")->fetchAll();

$recentReviews = $conn->query("
    SELECT r.*, rc.name AS card_name, rc.slug
    FROM reviews r
    JOIN review_cards rc ON r.card_id = rc.id
    ORDER BY r.created_at DESC
    LIMIT 5
")->fetchAll();

$recentFeedbacks = $conn->query("
    SELECT f.*, rc.name AS card_name
    FROM feedbacks f
    JOIN review_cards rc ON f.card_id = rc.id
    ORDER BY f.created_at DESC
    LIMIT 5
")->fetchAll();

$ratingDistribution = $conn->query("
    SELECT rating, COUNT(*) AS count
    FROM reviews
    GROUP BY rating
    ORDER BY rating DESC
")->fetchAll();

/* ---------- Render view ---------- */
ob_start();
?>

<h1>Platform overview</h1>
<p>Everything below is counted from the database right now.</p>

<div class="stats">
    <div class="card">
        <p class="muted">Users</p>
        <div class="num"><?= number_format($totalUsers) ?></div>
    </div>
    <div class="card">
        <p class="muted">Review cards</p>
        <div class="num"><?= number_format($totalCards) ?></div>
    </div>
    <div class="card">
        <p class="muted">Reviews collected</p>
        <div class="num"><?= number_format($totalReviews) ?></div>
    </div>
    <div class="card">
        <p class="muted">Private feedback</p>
        <div class="num"><?= number_format($totalFeedbacks) ?></div>
    </div>
</div>

<div class="card">
    <h2>Ratings</h2>
    <p class="muted">
        Average rating <?= $totalReviews > 0 ? number_format($avgRating, 2) : '—' ?> out of 5 ·
        <?= number_format($highRatings) ?> of <?= number_format($totalReviews) ?> reviews were 4★ or more.
    </p>
    <div class="stack" style="margin-top:.6rem">
        <?php if (empty($membershipStats)): ?>
        <span class="muted">No cards yet.</span>
        <?php else: foreach ($membershipStats as $m): ?>
        <span class="tag"><?= e(ucfirst((string)$m['membership'])) ?>: <?= (int)$m['count'] ?></span>
        <?php endforeach; endif; ?>
    </div>
</div>

<div class="card">
    <div class="stack" style="justify-content:space-between">
        <h2>Newest cards</h2>
        <a href="owner_cards.php">All cards →</a>
    </div>
    <?php if (empty($recentCards)): ?>
    <p class="muted">No cards yet.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Card</th><th>Owner</th><th>Plan</th><th>Created</th></tr></thead>
            <tbody>
            <?php foreach ($recentCards as $card): ?>
                <tr>
                    <td><strong><?= e($card['name']) ?></strong><br><span class="muted">/r/<?= e($card['slug']) ?>/</span></td>
                    <td class="muted"><?= e($card['user_email']) ?></td>
                    <td><span class="tag"><?= e(ucfirst((string)$card['membership'])) ?></span></td>
                    <td class="muted"><?= e(timeAgo((string)$card['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="stack" style="justify-content:space-between">
        <h2>Newest users</h2>
        <a href="owner_users.php">All users →</a>
    </div>
    <?php if (empty($recentUsers)): ?>
    <p class="muted">No users yet.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Email</th><th>Joined</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($recentUsers as $u): ?>
                <tr>
                    <td><?= e($u['email']) ?></td>
                    <td class="muted"><?= e(timeAgo((string)$u['created_at'])) ?></td>
                    <td><?php if (isOwnerEmail($u['email'])): ?><span class="tag">Owner</span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Newest reviews</h2>
    <?php if (empty($recentReviews)): ?>
    <p class="muted">No reviews yet.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Customer</th><th>Rating</th><th>Review</th><th>Card</th><th>When</th></tr></thead>
            <tbody>
            <?php foreach ($recentReviews as $r): ?>
                <tr>
                    <td><?= e($r['customer_name'] ?: 'Anonymous') ?></td>
                    <td><?= (int)$r['rating'] ?>★</td>
                    <td class="message"><?= e(excerpt((string)$r['body'], 120)) ?></td>
                    <td class="muted"><?= e($r['card_name']) ?></td>
                    <td class="muted"><?= e(timeAgo((string)$r['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Newest private feedback</h2>
    <?php if (empty($recentFeedbacks)): ?>
    <p class="muted">No feedback yet.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Customer</th><th>Rating</th><th>Message</th><th>Card</th><th>Status</th><th>When</th></tr></thead>
            <tbody>
            <?php foreach ($recentFeedbacks as $f): ?>
                <tr>
                    <td><?= e($f['customer_name'] ?: 'Anonymous') ?></td>
                    <td><?= (int)$f['rating'] ?>★</td>
                    <td class="message"><?= e(excerpt((string)$f['message'], 120)) ?></td>
                    <td class="muted"><?= e($f['card_name']) ?></td>
                    <td><span class="tag"><?= e(ucfirst((string)$f['status'])) ?></span></td>
                    <td class="muted"><?= e(timeAgo((string)$f['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/includes/owner_layout.php';
