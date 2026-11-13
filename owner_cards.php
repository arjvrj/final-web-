<?php
/**
 * owner_cards.php — Owner panel: manage all review cards
 * URL:   owner_cards.php?search=...&membership=...&page=N
 * POST:  owner_cards.php?action=update_membership
 *        owner_cards.php?action=delete
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

requireOwner();

/* =========================================================
   AJAX DISPATCHER (runs before any HTML output)
   ========================================================= */
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'update_membership' || $action === 'delete') {
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }
    requireCsrf();

    /* ---------- UPDATE MEMBERSHIP ---------- */
    if ($action === 'update_membership') {
        $cardId     = (int)($_POST['card_id'] ?? 0);
        $membership = trim($_POST['membership'] ?? '');
        $expiry     = trim($_POST['expiry_date'] ?? '');

        if ($cardId <= 0 || $membership === '' || $expiry === '') {
            echo json_encode(['error' => 'Missing required fields']);
            exit;
        }

        /* Normalize datetime-local input (2025-01-01T12:00) → MySQL DATETIME */
        $expiry = str_replace('T', ' ', $expiry);
        if (strlen($expiry) === 16) $expiry .= ':00';

        try {
            $stmt = $conn->prepare("UPDATE review_cards SET membership = ?, expiry_date = ? WHERE id = ?");
            $stmt->execute([$membership, $expiry, $cardId]);

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['error' => 'Could not update membership']);
        }
        exit;
    }

    /* ---------- DELETE CARD ---------- */
    if ($action === 'delete') {
        $cardId = (int)($_POST['id'] ?? 0);
        if ($cardId <= 0) {
            echo json_encode(['error' => 'Invalid card']);
            exit;
        }

        try {
            // FK ON DELETE CASCADE handles reviews + feedbacks
            $stmt = $conn->prepare("DELETE FROM review_cards WHERE id = ?");
            $stmt->execute([$cardId]);

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['error' => 'Could not delete card']);
        }
        exit;
    }
}

/* =========================================================
   RENDER
   ========================================================= */

$page             = 'cards';
$search           = trim($_GET['search'] ?? '');
$membershipFilter = trim($_GET['membership'] ?? '');
$pageNum          = max(1, (int)($_GET['page'] ?? 1));
$perPage          = 20;
$offset           = ($pageNum - 1) * $perPage;

$where  = [];
$params = [];

if ($search !== '') {
    $where[]  = "(rc.name LIKE ? OR rc.slug LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($membershipFilter !== '') {
    $where[]  = "rc.membership = ?";
    $params[] = $membershipFilter;
}
$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* Total */
$stmt = $conn->prepare("SELECT COUNT(*) FROM review_cards rc JOIN users u ON rc.user_id = u.id $whereClause");
$stmt->execute($params);
$totalCards = (int)$stmt->fetchColumn();
$totalPages = (int)ceil($totalCards / $perPage);

/* Cards with stats */
$stmt = $conn->prepare("
    SELECT rc.*, u.email AS user_email, u.id AS user_id,
           COUNT(DISTINCT r.id) AS review_count,
           COUNT(DISTINCT f.id) AS feedback_count,
           AVG(r.rating)        AS avg_rating
    FROM review_cards rc
    JOIN users u ON rc.user_id = u.id
    LEFT JOIN reviews r ON rc.id = r.card_id
    LEFT JOIN feedbacks f ON rc.id = f.card_id
    $whereClause
    GROUP BY rc.id
    ORDER BY rc.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$cards = $stmt->fetchAll();

/* Plans for modal */
$plans = $conn->query("SELECT * FROM plans ORDER BY price ASC")->fetchAll();

/* Membership counts */
$membershipCounts = $conn->query("SELECT membership, COUNT(*) AS count FROM review_cards GROUP BY membership")->fetchAll();
$membershipData = [];
foreach ($membershipCounts as $mc) $membershipData[$mc['membership']] = (int)$mc['count'];

/* Membership types for filter */
$membershipTypes = $conn->query("SELECT DISTINCT membership FROM review_cards ORDER BY membership")->fetchAll();

ob_start();
?>

<style>
    /* The design's admin.css has no modal; this is the same pattern used on the
       business side, drawn with the admin palette. */
    .modal { position: fixed; inset: 0; background: #18362b80; display: flex; align-items: center;
             justify-content: center; padding: 1rem; z-index: 20; }
    .modal[hidden] { display: none; }
    .modal .card { max-width: 520px; width: 100%; max-height: 88vh; overflow: auto; margin: 0; }
    .modal-head { display: flex; justify-content: space-between; align-items: start; gap: 1rem; }
    .row-actions { display: flex; gap: .4rem; flex-wrap: nowrap; }
    .row-actions .btn, .row-actions button { padding: .4rem .6rem; font-size: .85rem; min-height: 0; }
    td .tag { white-space: nowrap; }
    .pager { display: flex; gap: .4rem; flex-wrap: wrap; align-items: center; margin-top: 1rem; }
    .pager a, .pager span { padding: .45rem .7rem; border: 1px solid #c9dccd; border-radius: 7px; font-size: .9rem; }
    .pager a[aria-current="page"] { background: #1f513b; color: #fff; border-color: #1f513b; }
    .pager .gap { border: 0; }
</style>

<h1>Review cards</h1>
<p><?= number_format($totalCards) ?> card<?= $totalCards === 1 ? '' : 's' ?> on the platform.</p>

<div class="stats">
    <div class="card">
        <p class="muted">Total</p>
        <div class="num"><?= number_format($totalCards) ?></div>
    </div>
    <?php foreach ($membershipData as $type => $count): ?>
    <div class="card">
        <p class="muted"><?= e(ucfirst((string)$type)) ?></p>
        <div class="num"><?= number_format($count) ?></div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <form method="get" class="row">
        <div class="field" style="margin:0">
            <label for="search">Search</label>
            <input id="search" type="text" name="search" value="<?= e($search) ?>" placeholder="Name, slug or email">
        </div>
        <div class="field" style="margin:0">
            <label for="membership">Plan</label>
            <select id="membership" name="membership">
                <option value="">All plans</option>
                <?php foreach ($membershipTypes as $mt): ?>
                <option value="<?= e($mt['membership']) ?>" <?= $membershipFilter === $mt['membership'] ? 'selected' : '' ?>>
                    <?= e(ucfirst((string)$mt['membership'])) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit">Filter</button>
        <?php if ($search !== '' || $membershipFilter !== ''): ?>
        <a class="btn secondary" href="owner_cards.php">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <?php if (empty($cards)): ?>
    <p class="muted">No review cards found.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Card</th><th>Owner</th><th>Plan</th><th>Expiry</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($cards as $c):
                $expired = !empty($c['expiry_date']) && strtotime((string)$c['expiry_date']) < time();
                $daysLeft = !empty($c['expiry_date']) ? (int)ceil((strtotime((string)$c['expiry_date']) - time()) / 86400) : null;
            ?>
                <tr>
                    <td>
                        <strong><?= e($c['name']) ?></strong><br>
                        <span class="muted">/r/<?= e($c['slug']) ?>/</span>
                    </td>
                    <td>
                        <?= e($c['user_email']) ?><br>
                        <span class="muted">#<?= (int)$c['user_id'] ?></span>
                    </td>
                    <td><span class="tag"><?= e(ucfirst((string)$c['membership'])) ?></span></td>
                    <td>
                        <?php if (!empty($c['expiry_date'])): ?>
                        <?= e(date('M j, Y', strtotime((string)$c['expiry_date']))) ?><br>
                        <?php if ($expired): ?>
                        <span class="muted" style="color:#8b2f25">Expired</span>
                        <?php else: ?>
                        <span class="muted"><?= $daysLeft ?> day<?= $daysLeft === 1 ? '' : 's' ?> left</span>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="muted">Not set</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="row-actions">
                            <a class="btn secondary" href="review.php?slug=<?= urlencode((string)$c['slug']) ?>" target="_blank" rel="noopener">View</a>
                            <button type="button"
                                    onclick='openMembershipModal(<?= (int)$c['id'] ?>, <?= json_encode((string)$c['name'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode((string)$c['membership'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode((string)$c['expiry_date'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Plan</button>
                            <button type="button" class="secondary"
                                    onclick='deleteCard(<?= (int)$c['id'] ?>, <?= json_encode((string)$c['name'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Delete</button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
    <div class="pager">
        <span class="muted" style="border:0">
            <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalCards) ?> of <?= $totalCards ?>
        </span>
        <?php if ($pageNum > 1): ?>
        <a href="?page=<?= $pageNum - 1 ?>&amp;search=<?= urlencode($search) ?>&amp;membership=<?= urlencode($membershipFilter) ?>">Previous</a>
        <?php endif; ?>
        <?php $start = max(1, $pageNum - 2); $end = min($totalPages, $pageNum + 2); ?>
        <?php if ($start > 1): ?>
        <a href="?page=1&amp;search=<?= urlencode($search) ?>&amp;membership=<?= urlencode($membershipFilter) ?>">1</a>
        <?php if ($start > 2): ?><span class="gap muted">…</span><?php endif; ?>
        <?php endif; ?>
        <?php for ($i = $start; $i <= $end; $i++): ?>
        <a href="?page=<?= $i ?>&amp;search=<?= urlencode($search) ?>&amp;membership=<?= urlencode($membershipFilter) ?>"
           <?= $i === $pageNum ? 'aria-current="page"' : '' ?>><?= $i ?></a>
        <?php endfor; ?>
        <?php if ($end < $totalPages): ?>
        <?php if ($end < $totalPages - 1): ?><span class="gap muted">…</span><?php endif; ?>
        <a href="?page=<?= $totalPages ?>&amp;search=<?= urlencode($search) ?>&amp;membership=<?= urlencode($membershipFilter) ?>"><?= $totalPages ?></a>
        <?php endif; ?>
        <?php if ($pageNum < $totalPages): ?>
        <a href="?page=<?= $pageNum + 1 ?>&amp;search=<?= urlencode($search) ?>&amp;membership=<?= urlencode($membershipFilter) ?>">Next</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Plan / expiry modal -->
<div id="membershipModal" class="modal" hidden>
    <div class="card">
        <div class="modal-head">
            <div>
                <h2 style="margin:0">Change plan</h2>
                <p class="muted" style="margin:.3rem 0 0">Card: <span id="membershipCardName"></span></p>
            </div>
            <button type="button" class="secondary" onclick="closeMembershipModal()" aria-label="Close">✕</button>
        </div>

        <form id="membershipForm" onsubmit="updateMembership(event)">
            <?= csrfField() ?>
            <input type="hidden" id="membershipCardId" name="card_id">

            <p class="muted">Currently: <span id="currentMembership" class="tag"></span></p>

            <div class="field">
                <label for="planSelect">Plan</label>
                <select id="planSelect" name="plan_id" required>
                    <option value="">Choose a plan…</option>
                    <?php foreach ($plans as $plan): ?>
                    <option value="<?= (int)$plan['id'] ?>"
                            data-duration="<?= (int)$plan['duration'] ?>"
                            data-name="<?= e($plan['name']) ?>">
                        <?= e($plan['name']) ?> — ₹<?= number_format((float)($plan['sale_price'] ?: $plan['price'])) ?> (<?= (int)$plan['duration'] ?> days)
                    </option>
                    <?php endforeach; ?>
                </select>
                <p class="muted">The plan name fills the type and the expiry date below.</p>
            </div>

            <div class="field">
                <label for="membershipType">Plan type</label>
                <input type="text" id="membershipType" name="membership" required placeholder="trial, pro, enterprise">
            </div>

            <div class="field">
                <label for="expiryDate">Expiry</label>
                <input type="datetime-local" id="expiryDate" name="expiry_date" required>
            </div>

            <div class="stack">
                <button type="submit">Save</button>
                <button type="button" class="secondary" onclick="closeMembershipModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
/* jQuery is no longer loaded on this page. */
function openMembershipModal(cardId, cardName, currentMembership, currentExpiry) {
    document.getElementById('membershipCardId').value = cardId;
    document.getElementById('membershipCardName').textContent = cardName;
    document.getElementById('currentMembership').textContent =
        currentMembership ? currentMembership.charAt(0).toUpperCase() + currentMembership.slice(1) : '—';

    var typeField = document.getElementById('membershipType');
    if (typeField) typeField.value = currentMembership || '';

    var expiryField = document.getElementById('expiryDate');
    if (currentExpiry && currentExpiry !== 'null' && currentExpiry !== '') {
        expiryField.value = new Date(currentExpiry.replace(' ', 'T')).toISOString().slice(0, 16);
    } else {
        var d = new Date();
        d.setDate(d.getDate() + 30);
        expiryField.value = d.toISOString().slice(0, 16);
    }

    document.getElementById('planSelect').value = '';
    document.getElementById('membershipModal').hidden = false;
}

function closeMembershipModal() {
    document.getElementById('membershipModal').hidden = true;
}

document.getElementById('planSelect').addEventListener('change', function () {
    var opt = this.options[this.selectedIndex];
    if (!opt || !opt.dataset.name) return;
    var duration = parseInt(opt.dataset.duration, 10) || 0;
    if (duration <= 0) return;

    var name = String(opt.dataset.name).toLowerCase();
    var type = name;
    if (name.indexOf('trial') !== -1) type = 'trial';
    else if (name.indexOf('enterprise') !== -1) type = 'enterprise';
    else if (name.indexOf('pro') !== -1) type = 'pro';

    document.getElementById('membershipType').value = type;

    var d = new Date();
    d.setDate(d.getDate() + duration);
    document.getElementById('expiryDate').value = d.toISOString().slice(0, 16);
});

function postJson(url, data) {
    var body = new URLSearchParams();
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    body.append('_csrf', csrfMeta ? csrfMeta.getAttribute('content') : '');
    Object.keys(data).forEach(function (key) { body.append(key, data[key]); });
    return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    }).then(function (response) { return response.json(); });
}

function updateMembership(event) {
    event.preventDefault();

    var form = event.target;
    var submitBtn = form.querySelector('button[type="submit"]');
    var originalLabel = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Saving…';

    fetch('owner_cards.php?action=update_membership', { method: 'POST', body: new FormData(form) })
        .then(function (response) { return response.json(); })
        .then(function (res) {
            if (res && res.success) { location.reload(); return; }
            alert('Error: ' + ((res && res.error) || 'Unknown error'));
            submitBtn.disabled = false;
            submitBtn.textContent = originalLabel;
        })
        .catch(function () {
            alert('Error updating membership.');
            submitBtn.disabled = false;
            submitBtn.textContent = originalLabel;
        });
}

function deleteCard(id, name) {
    if (!confirm('Delete "' + name + '"? This cannot be undone and removes all of its reviews and feedback.')) return;
    if (!confirm('Please confirm again. This permanently deletes all data for this card.')) return;

    postJson('owner_cards.php?action=delete', { id: id })
        .then(function (res) {
            if (res && res.success) location.reload();
            else alert('Error: ' + ((res && res.error) || 'Unknown error'));
        })
        .catch(function () { alert('Error deleting card.'); });
}

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeMembershipModal();
});
document.addEventListener('click', function (event) {
    var modal = document.getElementById('membershipModal');
    if (modal && !modal.hidden && event.target === modal) closeMembershipModal();
});
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/includes/owner_layout.php';
