<?php
/**
 * card_reviews.php — Card panel reviews & feedback manager
 * URL: card_reviews.php?slug=<slug>&filter=reviews|feedback&search=...&page=N
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

$page   = 'reviews';
$pageStyles = ['css/dashboard-reviews.css', 'vendor/fontawesome/css/all.min.css'];
$slug   = trim($_GET['slug'] ?? '');
$userId = (int)$_SESSION['user_id'];

$stmt = $conn->prepare("SELECT * FROM review_cards WHERE slug = ? AND user_id = ? LIMIT 1");
$stmt->execute([$slug, $userId]);
$card = $stmt->fetch();

if (!$card) {
    header('Location: dashboard.php');
    exit;
}

$filter = $_GET['filter'] ?? 'reviews';
$search = trim($_GET['search'] ?? '');
$pageNum = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($pageNum - 1) * $limit;

/* Safe initials — mbstring is optional on shared hosting. */
$rgInitials = static function ($name) {
    $parts = preg_split('/\s+/u', trim((string)$name));
    if (!$parts) return '?';
    $out = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        if ($part === '') continue;
        $first = function_exists('mb_substr') ? mb_substr($part, 0, 1, 'UTF-8') : substr($part, 0, 1);
        $out  .= function_exists('mb_strtoupper') ? mb_strtoupper($first, 'UTF-8') : strtoupper($first);
    }
    return $out !== '' ? $out : '?';
};

/* ---------- Stats ---------- */
$statsStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN rating >= 4 THEN 1 ELSE 0 END) AS high,
        SUM(CASE WHEN rating <= 3 THEN 1 ELSE 0 END) AS low,
        AVG(rating) AS avg
    FROM reviews WHERE card_id = ?
");
$statsStmt->execute([$card['id']]);
$statsData = $statsStmt->fetch() ?: [];

$stats = [
    'total_reviews' => (int)($statsData['total'] ?? 0),
    'high'          => (int)($statsData['high'] ?? 0),
    'low'           => (int)($statsData['low'] ?? 0),
    'avg'           => (float)($statsData['avg'] ?? 0),
];

$fbStmt = $conn->prepare("SELECT COUNT(*) FROM feedbacks WHERE card_id = ?");
$fbStmt->execute([$card['id']]);
$stats['feedbacks'] = (int)$fbStmt->fetchColumn();

/* ---------- Fetch reviews ---------- */
$reviews = [];
$totalReviews = 0;
$totalPages = 0;

if ($filter === 'all' || $filter === 'reviews') {
    $where = ['card_id = ?'];
    $params = [$card['id']];

    if ($search !== '') {
        $where[] = '(customer_name LIKE ? OR body LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    $whereClause = implode(' AND ', $where);

    $countStmt = $conn->prepare("SELECT COUNT(*) FROM reviews WHERE $whereClause");
    $countStmt->execute($params);
    $totalReviews = (int)$countStmt->fetchColumn();
    $totalPages = (int)ceil($totalReviews / $limit);

    $stmt = $conn->prepare("SELECT * FROM reviews WHERE $whereClause ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $stmt->execute($params);
    $reviews = $stmt->fetchAll();
}

/* ---------- Fetch feedback ---------- */
$feedbacks = [];
if ($filter === 'all' || $filter === 'feedback') {
    $where = ['card_id = ?'];
    $params = [$card['id']];

    if ($search !== '') {
        $where[] = '(customer_name LIKE ? OR message LIKE ? OR email LIKE ? OR phone LIKE ?)';
        $params[] = "%$search%"; $params[] = "%$search%";
        $params[] = "%$search%"; $params[] = "%$search%";
    }
    $whereClause = implode(' AND ', $where);

    $stmt = $conn->prepare("SELECT * FROM feedbacks WHERE $whereClause ORDER BY created_at DESC");
    $stmt->execute($params);
    $feedbacks = $stmt->fetchAll();
}

$slugEnc = urlencode($card['slug']);
$baseUrl = "card_reviews.php?slug={$slugEnc}";

ob_start();
?>

<style>
    /* .status is a design class that only shows when it has a message. */
    #toast { display: none; margin-top: 14px; }
    #toast.show { display: block; }
    .panel .billing-scroll table { min-width: 860px; }
    .panel .billing-scroll td { vertical-align: top; }
    .rg-actions { display: flex; gap: 6px; flex-wrap: nowrap; }
    /* Table rows need smaller controls than a page-level footer. */
    .rg-actions .button { min-height: 34px; padding: 0 10px; font-size: 11px; }
    .billing-scroll td .pill { white-space: nowrap; }
    .rg-actions form { margin: 0; }
    .rg-modal { position: fixed; inset: 0; background: #102d2170; display: flex; align-items: center;
                justify-content: center; padding: 16px; z-index: 50; }
    .rg-modal[hidden] { display: none; }
    .rg-modal .panel { max-width: 560px; width: 100%; max-height: 88vh; overflow: auto; }
    .rg-modal dl { display: grid; grid-template-columns: 110px 1fr; gap: 10px 14px; margin: 16px 0 0; }
    .rg-modal dt { color: var(--muted); font-size: 12px; font-weight: 700; }
    .rg-modal dd { margin: 0; font-size: 13px; }
</style>

<div class="page-heading">
    <div>
        <span class="kicker">REVIEWS</span>
        <h1>Reviews and private feedback</h1>
        <p>Everything customers left for <?= e($card['name']) ?>.</p>
    </div>
    <div class="rg-actions">
        <button type="button" class="button button-outline rg-copy-link" id="copyPageLink"
                data-link="<?= e(url('review.php?slug=' . urlencode($card['slug']))) ?>">
            <i class="fa-regular fa-copy" aria-hidden="true"></i> Copy link
        </button>
        <a class="button button-outline" href="review.php?slug=<?= urlencode($card['slug']) ?>" target="_blank" rel="noopener">Open review page ↗</a>
    </div>
</div>

<div class="rg-stats">
    <article class="rg-stat is-total">
        <div class="rg-stat-top"><span class="rg-stat-label">Total reviews</span>
            <span class="rg-stat-icon" aria-hidden="true"><i class="fa-solid fa-comment-dots"></i></span></div>
        <div class="rg-stat-value"><?= number_format($stats['total_reviews']) ?></div>
        <div class="rg-stat-note">All time</div>
    </article>
    <article class="rg-stat is-avg">
        <div class="rg-stat-top"><span class="rg-stat-label">Average rating</span>
            <span class="rg-stat-icon" aria-hidden="true"><i class="fa-solid fa-star"></i></span></div>
        <div class="rg-stat-value"><?= $stats['total_reviews'] > 0 ? number_format($stats['avg'], 1) : '—' ?></div>
        <div class="rg-stat-note rg-stars-inline" aria-hidden="true"><?php
            $avgRounded = (int)round($stats['avg']);
            for ($i = 1; $i <= 5; $i++) echo $i <= $avgRounded ? '★' : '☆';
        ?><span class="rg-visually-plain"> out of 5</span></div>
    </article>
    <article class="rg-stat is-good">
        <div class="rg-stat-top"><span class="rg-stat-label">Happy 4–5★</span>
            <span class="rg-stat-icon" aria-hidden="true"><i class="fa-solid fa-face-smile"></i></span></div>
        <div class="rg-stat-value"><?= number_format($stats['high']) ?></div>
        <div class="rg-stat-note">Customers who were happy</div>
    </article>
    <article class="rg-stat is-low">
        <div class="rg-stat-top"><span class="rg-stat-label">Low 1–3★</span>
            <span class="rg-stat-icon" aria-hidden="true"><i class="fa-solid fa-face-frown"></i></span></div>
        <div class="rg-stat-value"><?= number_format($stats['low']) ?></div>
        <div class="rg-stat-note">May have left private feedback</div>
    </article>
    <article class="rg-stat is-private">
        <div class="rg-stat-top"><span class="rg-stat-label">Private feedback</span>
            <span class="rg-stat-icon" aria-hidden="true"><i class="fa-solid fa-lock"></i></span></div>
        <div class="rg-stat-value"><?= number_format($stats['feedbacks']) ?></div>
        <div class="rg-stat-note">Never posted publicly</div>
    </article>
</div>

<section class="panel" style="margin-top:18px">
    <div class="panel-head">
        <div>
            <h2><?= $filter === 'feedback' ? 'Private feedback' : 'Reviews' ?></h2>
            <p><?= $filter === 'feedback'
                ? 'Sent privately by customers who rated 1–3 stars. Nothing here was posted on Google.'
                : 'Reviews customers copied to Google from your review page.' ?></p>
        </div>
        <div class="rg-tabs" role="tablist" aria-label="What to show">
            <a class="rg-tab <?= $filter === 'reviews' ? 'is-active' : '' ?>" role="tab"
               aria-selected="<?= $filter === 'reviews' ? 'true' : 'false' ?>"
               href="<?= $baseUrl ?>&amp;filter=reviews<?= $search !== '' ? '&amp;search=' . urlencode($search) : '' ?>">
                <i class="fa-regular fa-comment-dots" aria-hidden="true"></i> Reviews
                <span class="rg-tab-count"><?= number_format($stats['total_reviews']) ?></span>
            </a>
            <a class="rg-tab <?= $filter === 'feedback' ? 'is-active' : '' ?>" role="tab"
               aria-selected="<?= $filter === 'feedback' ? 'true' : 'false' ?>"
               href="<?= $baseUrl ?>&amp;filter=feedback<?= $search !== '' ? '&amp;search=' . urlencode($search) : '' ?>">
                <i class="fa-solid fa-lock" aria-hidden="true"></i> Feedback
                <span class="rg-tab-count"><?= number_format($stats['feedbacks']) ?></span>
            </a>
        </div>
    </div>

    <form method="get" class="rg-toolbar" style="margin-top:12px">
        <input type="hidden" name="slug" value="<?= e($card['slug']) ?>">
        <input type="hidden" name="filter" value="<?= e($filter) ?>">
        <div class="rg-search">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <label class="rg-visually-hidden" for="search">Search <?= $filter === 'feedback' ? 'feedback' : 'reviews' ?></label>
            <input id="search" type="text" name="search" value="<?= e($search) ?>" placeholder="Search by name or text…">
            <?php if ($search !== ''): ?>
            <a class="rg-search-clear" href="<?= $baseUrl ?>&amp;filter=<?= urlencode($filter) ?>" aria-label="Clear search">✕</a>
            <?php endif; ?>
        </div>
        <button class="button button-outline button-small" type="submit">Search</button>
    </form>

    <?php if ($filter === 'feedback'): ?>
        <?php if (count($feedbacks) > 0): ?>
        <div class="billing-scroll" style="margin-top:14px">
            <table class="rg-table">
                <thead>
                    <tr>
                        <th>Customer</th><th>Rating</th><th>Message</th>
                        <th>Status</th><th>Date</th><th style="text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($feedbacks as $fb):
                    $fbName = trim((string)($fb['customer_name'] ?: 'Anonymous'));
                    $fbInitials = $rgInitials($fbName);
                    $fbRating = (int)$fb['rating'];
                ?>
                    <tr>
                        <td data-label="Customer">
                            <div class="rg-person">
                                <span class="rg-avatar" aria-hidden="true"><?= e($fbInitials !== '' ? $fbInitials : '?') ?></span>
                                <div>
                                    <div class="rg-person-name"><?= e($fbName) ?></div>
                                    <?php if ($fb['email'] || $fb['phone']): ?>
                                    <div class="rg-person-contact">
                                        <?= $fb['email'] ? e($fb['email']) . '<br>' : '' ?>
                                        <?= $fb['phone'] ? e($fb['phone']) : '' ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td data-label="Rating">
                            <span class="rg-stars" aria-label="<?= $fbRating ?> out of 5 stars"><?php
                                for ($i = 1; $i <= 5; $i++) {
                                    echo $i <= $fbRating ? '★' : '<span class="is-off">☆</span>';
                                }
                            ?></span>
                        </td>
                        <td data-label="Message"><div class="rg-snippet"><?= e(excerpt((string)$fb['message'], 120)) ?></div></td>
                        <td data-label="Status">
                            <?php if ($fb['status'] === 'new'): ?>
                            <span class="rg-chip is-new">● New</span>
                            <?php elseif ($fb['status'] === 'read'): ?>
                            <span class="rg-chip is-read">Read</span>
                            <?php else: ?>
                            <span class="rg-chip"><?= e(ucfirst((string)$fb['status'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Date" class="small-note"><?= e(date('M j, Y', strtotime((string)$fb['created_at']))) ?></td>
                        <td data-label="Actions">
                            <div class="rg-row-actions">
                                <?php if ($fb['status'] === 'new'): ?>
                                <button class="button button-outline button-small" type="button"
                                        onclick="markFeedbackRead(<?= (int)$fb['id'] ?>)">Mark read</button>
                                <?php endif; ?>
                                <button class="button button-outline button-small" type="button"
                                        onclick='viewFeedback(<?= json_encode($fb, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>)'><i class="fa-solid fa-eye" aria-hidden="true"></i> View</button>
                                <button class="button button-danger button-small" type="button"
                                        onclick="deleteFeedback(<?= (int)$fb['id'] ?>)"><i class="fa-solid fa-trash-can" aria-hidden="true"></i> Delete</button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php else: ?>
        <div class="rg-empty">
            <div class="rg-empty-disc" aria-hidden="true"><i class="fa-regular fa-envelope-open"></i></div>
            <h3>No private feedback yet</h3>
            <p>When a customer rates you 1–3 stars they can tell you what went wrong. It lands here, never on Google.</p>
            <div class="rg-empty-actions">
                <a class="button button-outline button-small" href="review.php?slug=<?= urlencode($card['slug']) ?>" target="_blank" rel="noopener">Open your review page ↗</a>
            </div>
        </div>
        <?php endif; ?>

    <?php else: ?>
        <?php if (count($reviews) > 0): ?>
        <div class="billing-scroll" style="margin-top:14px">
            <table class="rg-table">
                <thead>
                    <tr>
                        <th>Customer</th><th>Rating</th><th>Review</th>
                        <th>Type</th><th>Date</th><th style="text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($reviews as $r):
                    $rating = (int)$r['rating'];
                    $rDetails = json_decode($r['details'] ?? '{}', true);
                    $isAI = !empty($rDetails['ai_generated']);
                    $name = trim((string)($r['customer_name'] ?: 'Anonymous'));
                    $initials = $rgInitials($name);
                ?>
                    <tr>
                        <td data-label="Customer">
                            <div class="rg-person">
                                <span class="rg-avatar" aria-hidden="true"><?= e($initials !== '' ? $initials : '?') ?></span>
                                <div>
                                    <div class="rg-person-name"><?= e($name) ?></div>
                                </div>
                            </div>
                        </td>
                        <td data-label="Rating">
                            <span class="rg-stars" aria-label="<?= $rating ?> out of 5 stars"><?php
                                for ($i = 1; $i <= 5; $i++) {
                                    echo $i <= $rating ? '★' : '<span class="is-off">☆</span>';
                                }
                            ?></span>
                        </td>
                        <td data-label="Review">
                            <div class="rg-snippet"><?= e(excerpt((string)($r['body'] ?: 'No comment'), 120)) ?></div>
                        </td>
                        <td data-label="Type">
                            <?php if ($isAI): ?>
                            <span class="rg-chip is-ai"><i class="fa-solid fa-feather" aria-hidden="true"></i> AI draft</span>
                            <?php else: ?>
                            <span class="rg-chip is-own"><i class="fa-regular fa-pen-to-square" aria-hidden="true"></i> Own words</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Date" class="small-note"><?= e(date('M j, Y', strtotime((string)$r['created_at']))) ?></td>
                        <td data-label="Actions">
                            <div class="rg-row-actions">
                                <button class="button button-outline button-small" type="button"
                                        onclick='viewReview(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>)'><i class="fa-solid fa-eye" aria-hidden="true"></i> View</button>
                                <button class="button button-danger button-small" type="button"
                                        onclick="deleteReview(<?= (int)$r['id'] ?>)"><i class="fa-solid fa-trash-can" aria-hidden="true"></i> Delete</button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="form-footer" style="justify-content:space-between">
            <p class="small-note" style="margin:0">
                Showing <?= $offset + 1 ?>–<?= min($offset + $limit, $totalReviews) ?> of <?= $totalReviews ?>
            </p>
            <div class="rg-actions">
                <?php if ($pageNum > 1): ?>
                <a class="button button-outline button-small" href="<?= $baseUrl ?>&amp;filter=<?= urlencode($filter) ?>&amp;search=<?= urlencode($search) ?>&amp;page=<?= $pageNum - 1 ?>">Previous</a>
                <?php endif; ?>
                <?php if ($pageNum < $totalPages): ?>
                <a class="button button-outline button-small" href="<?= $baseUrl ?>&amp;filter=<?= urlencode($filter) ?>&amp;search=<?= urlencode($search) ?>&amp;page=<?= $pageNum + 1 ?>">Next</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="rg-empty">
            <div class="rg-empty-disc" aria-hidden="true"><i class="fa-regular fa-comment-dots"></i></div>
            <h3><?= $search !== '' ? 'No reviews match your search' : 'No reviews yet' ?></h3>
            <p><?= $search !== ''
                ? 'Try a different name or word — or clear the search to see everything.'
                : 'Print your QR code or share the link below. The first review usually arrives the same day you ask for it.' ?></p>
            <div class="rg-empty-actions">
                <?php if ($search !== ''): ?>
                <a class="button button-outline button-small" href="<?= $baseUrl ?>&amp;filter=<?= urlencode($filter) ?>">Clear search</a>
                <?php else: ?>
                <a class="button button-dark button-small" href="review.php?slug=<?= urlencode($card['slug']) ?>" target="_blank" rel="noopener">Open your review page ↗</a>
                <button type="button" class="button button-outline button-small rg-copy-link"
                        data-link="<?= e(url('review.php?slug=' . urlencode($card['slug']))) ?>">
                    <i class="fa-regular fa-copy" aria-hidden="true"></i> Copy link
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <p class="status" id="toast" role="status"><span id="toastMessage"></span></p>
</section>

<!-- Review details -->
<div id="viewReviewModal" class="rg-modal" hidden>
    <div class="panel">
        <div class="panel-head">
            <div><h2>Review details</h2><p><?= e($card['name']) ?></p></div>
            <button class="button button-outline button-small" type="button" onclick="closeViewModal()" aria-label="Close">✕</button>
        </div>
        <div id="reviewDetails"></div>
    </div>
</div>

<!-- Feedback details -->
<div id="viewFeedbackModal" class="rg-modal" hidden>
    <div class="panel">
        <div class="panel-head">
            <div><h2>Feedback details</h2><p>Sent privately</p></div>
            <button class="button button-outline button-small" type="button" onclick="closeFeedbackModal()" aria-label="Close">✕</button>
        </div>
        <div id="feedbackDetails"></div>
    </div>
</div>

<script>
/* jQuery is no longer loaded on this page; these are plain DOM calls. */

/* Copy the public review link, so the owner can paste it anywhere. */
document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-link]');
    if (!button) return;
    var link = button.getAttribute('data-link');
    if (!link) return;
    var done = function () {
        showToast('Link copied — paste it in WhatsApp or your bio.');
        var label = button.querySelector('i');
        if (label) { label.className = 'fa-solid fa-check'; }
        setTimeout(function () { if (label) label.className = 'fa-regular fa-copy'; }, 2000);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(link).then(done).catch(function () { window.prompt('Copy this link', link); });
    } else {
        window.prompt('Copy this link', link);
    }
});

function showToast(msg) {
    var box = document.getElementById('toast');
    var text = document.getElementById('toastMessage');
    if (!box || !text) return;
    text.textContent = msg;
    box.classList.add('show');
    clearTimeout(window.__toastTimer);
    window.__toastTimer = setTimeout(function () {
        box.classList.remove('show');
        text.textContent = '';
    }, 2500);
}

function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

/* Small POST helper replacing $.post */
function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

function postForm(url, data) {
    var body = new URLSearchParams();
    body.append('_csrf', csrfToken());
    Object.keys(data).forEach(function (key) { body.append(key, data[key]); });
    return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    }).then(function (response) { return response.json(); });
}

function row(label, value) {
    return '<dt>' + escapeHtml(label) + '</dt><dd>' + value + '</dd>';
}

function viewReview(r) {
    var stars = '★'.repeat(r.rating) + '☆'.repeat(5 - r.rating);
    var details = {};
    try { details = JSON.parse(r.details || '{}'); } catch (e) { details = {}; }
    var isAI = details.ai_generated === true;

    document.getElementById('reviewDetails').innerHTML = '<dl>' +
        row('Customer', escapeHtml(r.customer_name || 'Anonymous')) +
        row('Rating', stars) +
        row('Review', escapeHtml(r.body || 'No comment')) +
        row('Type', isAI ? 'AI draft' : 'Own words') +
        row('Date', escapeHtml(new Date(r.created_at).toLocaleString())) +
        '</dl>';
    document.getElementById('viewReviewModal').hidden = false;
}

function viewFeedback(fb) {
    document.getElementById('feedbackDetails').innerHTML = '<dl>' +
        row('Customer', escapeHtml(fb.customer_name)) +
        row('Email', escapeHtml(fb.email || '—')) +
        row('Phone', escapeHtml(fb.phone || '—')) +
        row('Rating', fb.rating + '★') +
        row('Message', escapeHtml(fb.message)) +
        row('Status', escapeHtml(fb.status)) +
        row('Date', escapeHtml(new Date(fb.created_at).toLocaleString())) +
        '</dl>';
    document.getElementById('viewFeedbackModal').hidden = false;
    if (fb.status === 'new') markFeedbackRead(fb.id, true);
}

function deleteReview(id) {
    if (!confirm('Delete this review? This cannot be undone.')) return;
    postForm('review_delete.php', { review_id: id })
        .then(function (res) {
            if (res && res.success) { showToast('Review deleted.'); setTimeout(function () { location.reload(); }, 1200); }
            else { showToast('Error: ' + ((res && res.error) || 'Unknown')); }
        })
        .catch(function () { showToast('An error occurred.'); });
}

function deleteFeedback(id) {
    if (!confirm('Delete this feedback?')) return;
    postForm('feedback_delete.php', { feedback_id: id })
        .then(function (res) {
            if (res && res.success) { showToast('Feedback deleted.'); setTimeout(function () { location.reload(); }, 1200); }
            else { showToast('Error: ' + ((res && res.error) || 'Unknown')); }
        })
        .catch(function () { showToast('An error occurred.'); });
}

function markFeedbackRead(id, silent) {
    postForm('feedback_mark_read.php', { feedback_id: id })
        .then(function (res) {
            if (res && res.success && !silent) {
                showToast('Marked as read.');
                setTimeout(function () { location.reload(); }, 1200);
            }
        })
        .catch(function () {});
}

function closeViewModal() { document.getElementById('viewReviewModal').hidden = true; }
function closeFeedbackModal() { document.getElementById('viewFeedbackModal').hidden = true; }

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') { closeViewModal(); closeFeedbackModal(); }
});
document.addEventListener('click', function (event) {
    if (event.target && event.target.id === 'viewReviewModal') closeViewModal();
    if (event.target && event.target.id === 'viewFeedbackModal') closeFeedbackModal();
});
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/includes/card_layout.php';
