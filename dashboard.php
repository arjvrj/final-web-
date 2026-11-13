<?php
/**
 * dashboard.php — the signed-in home page.
 *
 * Every number on this page comes from the database. Where there is no data yet
 * the page says so, instead of showing an invented figure.
 *
 * This is the page that eight redirects already pointed at (auth.php, card_*.php,
 * card_update_settings.php); until now it did not exist.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/dashboard_layout.php';

requireLogin();

$userId = (int)$_SESSION['user_id'];

/* ---------- Cards, with their real review counts ---------- */
$stmt = $conn->prepare("
    SELECT rc.*, COUNT(r.id) AS review_count, AVG(r.rating) AS avg_rating
    FROM review_cards rc
    LEFT JOIN reviews r ON r.card_id = rc.id
    WHERE rc.user_id = ?
    GROUP BY rc.id
    ORDER BY rc.created_at DESC
");
$stmt->execute([$userId]);
$cards = $stmt->fetchAll();

/* ---------- Totals across every card this user owns ---------- */
$totalReviews = 0;
$ratingSum    = 0;
$ratedCount   = 0;
foreach ($cards as $c) {
    $totalReviews += (int)$c['review_count'];
    if ((int)$c['review_count'] > 0) {
        $ratingSum  += (float)$c['avg_rating'] * (int)$c['review_count'];
        $ratedCount += (int)$c['review_count'];
    }
}
$avgRating = $ratedCount > 0 ? $ratingSum / $ratedCount : null;

/* ---------- Feedback received (private, 1–3 stars) ---------- */
$feedbackCount = 0;
if ($cards) {
    $ids = array_map(static fn($c) => (int)$c['id'], $cards);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("SELECT COUNT(*) FROM feedbacks WHERE card_id IN ($in)");
    $stmt->execute($ids);
    $feedbackCount = (int)$stmt->fetchColumn();
}

/* ---------- The five newest reviews ---------- */
$recentReviews = [];
if ($cards) {
    $ids = array_map(static fn($c) => (int)$c['id'], $cards);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("
        SELECT r.customer_name, r.rating, r.body, r.created_at, rc.name AS card_name, rc.slug
        FROM reviews r
        JOIN review_cards rc ON rc.id = r.card_id
        WHERE r.card_id IN ($in)
        ORDER BY r.created_at DESC
        LIMIT 5
    ");
    $stmt->execute($ids);
    $recentReviews = $stmt->fetchAll();
}

/* ---------- The three newest private feedbacks ---------- */
$recentFeedback = [];
if ($cards) {
    $ids = array_map(static fn($c) => (int)$c['id'], $cards);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("
        SELECT f.customer_name, f.rating, f.message, f.created_at, rc.name AS card_name
        FROM feedbacks f
        JOIN review_cards rc ON rc.id = f.card_id
        WHERE f.card_id IN ($in)
        ORDER BY f.created_at DESC
        LIMIT 3
    ");
    $stmt->execute($ids);
    $recentFeedback = $stmt->fetchAll();
}

$appName = getSetting('app_name', 'Review AI');
$success = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);

/* ---------- Markup ---------- */
ob_start();
?>

<?php if ($success !== ''): ?>
<p class="alert" role="status"><?= e($success) ?></p>
<?php endif; ?>

<style>
    /* The design's tables carry a 480px minimum width, which forces a sideways
       scroll inside a half-width panel. They are allowed to wrap here instead. */
    .panel .billing-scroll table { min-width: 0; }
    .panel .billing-scroll th, .panel .billing-scroll td { vertical-align: top; }
</style>

<div class="page-heading">
    <div>
        <span class="kicker">OVERVIEW</span>
        <h1>Your review workspace</h1>
        <p>Every number below comes from your own customers.</p>
    </div>
    <a class="button button-dark" href="onboard.php">Create a review card</a>
</div>

<div class="stats">
    <article class="stat">
        <div class="stat-title"><span>Reviews collected</span><span class="stat-symbol" aria-hidden="true">▤</span></div>
        <div class="stat-value"><?= number_format($totalReviews) ?></div>
        <div class="stat-note"><?= count($cards) === 1 ? 'Across 1 card' : 'Across ' . count($cards) . ' cards' ?></div>
    </article>
    <article class="stat">
        <div class="stat-title"><span>Average rating</span><span class="stat-symbol" aria-hidden="true">★</span></div>
        <div class="stat-value"><?= $avgRating === null ? '—' : number_format($avgRating, 1) ?></div>
        <div class="stat-note"><?= $avgRating === null ? 'No ratings yet' : 'Out of 5, from real ratings' ?></div>
    </article>
    <article class="stat">
        <div class="stat-title"><span>Private feedback</span><span class="stat-symbol" aria-hidden="true">✉</span></div>
        <div class="stat-value"><?= number_format($feedbackCount) ?></div>
        <div class="stat-note">From customers who rated 1–3 stars</div>
    </article>
    <article class="stat">
        <div class="stat-title"><span>Review cards</span><span class="stat-symbol" aria-hidden="true">▦</span></div>
        <div class="stat-value"><?= count($cards) ?></div>
        <div class="stat-note"><?= count($cards) ? 'Each has its own public link' : 'Create one to get your link' ?></div>
    </article>
</div>

<div class="overview-grid">
    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Recent reviews</h2>
                <p>The five newest, from all your cards.</p>
            </div>
        </div>
        <?php if (!$recentReviews): ?>
        <div class="empty-panel">
            <div>
                <div class="empty-icon" aria-hidden="true">★</div>
                <div class="empty-title">No reviews yet</div>
                <div class="empty-copy">When a customer finishes the review page, their review shows up here.</div>
            </div>
        </div>
        <?php else: ?>
        <div class="billing-scroll">
            <table>
                <thead><tr><th>Customer</th><th>Review</th></tr></thead>
                <tbody>
                <?php foreach ($recentReviews as $r): ?>
                    <tr>
                        <td>
                            <strong><?= e($r['customer_name'] ?: 'Anonymous') ?></strong>
                            <div class="small-note"><?= str_repeat('★', max(1, (int)$r['rating'])) ?> · <?= e($r['card_name']) ?></div>
                        </td>
                        <td>
                            <?= e(excerpt((string)$r['body'], 140)) ?>
                            <div class="small-note"><?= e(timeAgo((string)$r['created_at'])) ?></div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Private feedback</h2>
                <p>Customers who rated you 1–3 stars.</p>
            </div>
        </div>
        <?php if (!$recentFeedback): ?>
        <div class="empty-panel">
            <div>
                <div class="empty-icon" aria-hidden="true">✉</div>
                <div class="empty-title">Nothing to read</div>
                <div class="empty-copy">Low-star customers can leave private feedback. It arrives here, never on Google.</div>
            </div>
        </div>
        <?php else: ?>
        <div class="billing-scroll">
            <table>
                <thead><tr><th>Customer</th><th>What happened</th></tr></thead>
                <tbody>
                <?php foreach ($recentFeedback as $f): ?>
                    <tr>
                        <td>
                            <strong><?= e($f['customer_name'] ?: 'Anonymous') ?></strong>
                            <div class="small-note"><?= str_repeat('★', max(1, (int)$f['rating'])) ?> · <?= e($f['card_name']) ?></div>
                        </td>
                        <td>
                            <?= e(excerpt((string)$f['message'], 140)) ?>
                            <div class="small-note"><?= e(timeAgo((string)$f['created_at'])) ?></div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
</div>

<div class="overview-bottom" id="cards">
    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Your review cards</h2>
                <p>Each card is one public review link.</p>
            </div>
        </div>

        <?php if (!$cards): ?>
        <div class="empty-panel">
            <div>
                <div class="empty-icon" aria-hidden="true">▦</div>
                <div class="empty-title">No cards yet</div>
                <div class="empty-copy">A card gives you a link and a QR code that customers open to leave a review.</div>
                <a class="button button-dark" href="onboard.php" style="margin-top:16px">Create your first card</a>
            </div>
        </div>
        <?php else: ?>
        <div class="billing-scroll">
            <table>
                <thead><tr><th>Card</th><th>Reviews</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($cards as $c):
                    $expired = !empty($c['expiry_date']) && strtotime((string)$c['expiry_date']) < time();
                    $days    = !empty($c['expiry_date']) ? (int)ceil((strtotime((string)$c['expiry_date']) - time()) / 86400) : null;
                    $s       = urlencode((string)$c['slug']);
                ?>
                    <tr>
                        <td>
                            <strong><?= e($c['name']) ?></strong>
                            <div class="small-note">/r/<?= e($c['slug']) ?>/</div>
                        </td>
                        <td class="small-note">
                            <?= (int)$c['review_count'] ?><?= (int)$c['review_count'] > 0 ? '<br>' . number_format((float)$c['avg_rating'], 1) . '★' : '' ?>
                        </td>
                        <td>
                            <?php if ($expired): ?>
                            <span class="pill" style="color:#9e3e37;border-color:#e7c7c0;background:#fff1ee">Expired</span>
                            <?php elseif ($days !== null && $days <= 7): ?>
                            <span class="pill"><?= $days ?>d left</span>
                            <?php else: ?>
                            <span class="pill"><?= e(ucfirst((string)$c['membership'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="form-footer" style="margin:0;gap:6px;flex-wrap:nowrap">
                                <a class="button button-outline button-small" href="review.php?slug=<?= $s ?>" target="_blank" rel="noopener">View</a>
                                <a class="button button-dark button-small" href="card_home.php?slug=<?= $s ?>">Manage</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>What happens next</h2>
                <p>How your review link works.</p>
            </div>
        </div>
        <ol class="hint" style="padding-left:18px;line-height:1.9">
            <li><strong>You share the link.</strong> Print the QR code or send the link after a visit.</li>
            <li><strong>The customer rates you.</strong> 4–5 stars gets three AI drafts from the topics they pick. 1–3 stars gets a private form.</li>
            <li><strong>Nothing is posted for them.</strong> They copy a draft and paste it on Google themselves, or leave private feedback for you.</li>
        </ol>
        <p class="small-note" style="margin-top:14px">Drafts are written by the AI from the star rating and the chosen topics only. The customer's own words are never sent to it.</p>
    </section>
</div>

<?php
$content = ob_get_clean();
echo dashboardShell($content, [
    'title' => 'Overview',
    'crumb' => 'Overview',
    'page'  => 'overview',
    'card'  => null,
]);
