<?php
/**
 * owner_users.php — Owner panel: everyone with an account.
 *
 * Read-only on purpose. The app has no backend for suspending, banning or
 * editing a user, so this page does not pretend to offer it.
 *
 * URL: owner_users.php?search=...
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

requireOwner();

$page   = 'users';
$search = trim((string)($_GET['search'] ?? ''));

$sql = "
    SELECT u.id, u.email, u.created_at,
           COUNT(DISTINCT rc.id) AS card_count,
           COUNT(r.id)           AS review_count
    FROM users u
    LEFT JOIN review_cards rc ON rc.user_id = u.id
    LEFT JOIN reviews r       ON r.card_id = rc.id
";
$params = [];
if ($search !== '') {
    $sql .= " WHERE u.email LIKE ? ";
    $params[] = '%' . $search . '%';
}
$sql .= " GROUP BY u.id, u.email, u.created_at ORDER BY u.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

ob_start();
?>

<h1>Users</h1>
<p><?= number_format(count($users)) ?> account<?= count($users) === 1 ? '' : 's' ?><?= $search !== '' ? ' matching your search' : '' ?>.</p>

<div class="card">
    <form method="get" class="row">
        <div class="field" style="margin:0">
            <label for="search">Search by email</label>
            <input id="search" type="text" name="search" value="<?= e($search) ?>" placeholder="someone@example.com">
        </div>
        <button type="submit">Search</button>
        <?php if ($search !== ''): ?>
        <a class="btn secondary" href="owner_users.php">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <?php if (!$users): ?>
    <p class="muted"><?= $search !== '' ? 'No accounts match that search.' : 'No accounts yet.' ?></p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Email</th><th>Joined</th><th>Cards</th><th>Reviews</th><th>Role</th></tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= e($u['email']) ?></td>
                    <td class="muted"><?= e(date('M j, Y', strtotime((string)$u['created_at']))) ?></td>
                    <td><?= (int)$u['card_count'] ?></td>
                    <td><?= (int)$u['review_count'] ?></td>
                    <td>
                        <?php if (isOwnerEmail($u['email'])): ?>
                        <span class="tag">Owner</span>
                        <?php else: ?>
                        <span class="muted">Business</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<p class="muted">
    Accounts cannot be edited or suspended from here: the application has no backend for it,
    so no button is shown. Passwords are stored as hashes and are never displayed.
</p>

<?php
$content = ob_get_clean();
require __DIR__ . '/includes/owner_layout.php';
