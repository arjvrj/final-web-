<?php
/**
 * card_payments.php — transaction history for one review card.
 * URL: card_payments.php?slug=<slug>
 *
 * Read-only. It lists what the payments table recorded: what was charged, in
 * which environment, and whether it was paid, still open, or failed.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

$page   = 'membership';
$slug   = trim($_GET['slug'] ?? '');
$userId = (int)$_SESSION['user_id'];

$stmt = $conn->prepare("SELECT * FROM review_cards WHERE slug = ? AND user_id = ? LIMIT 1");
$stmt->execute([$slug, $userId]);
$card = $stmt->fetch();

if (!$card) {
    header('Location: dashboard.php');
    exit;
}

$stmt = $conn->prepare("
    SELECT p.*, pl.name AS plan_name, pl.duration AS plan_duration
    FROM payments p
    LEFT JOIN plans pl ON pl.id = p.plan_id
    WHERE p.card_id = ? AND p.user_id = ?
    ORDER BY p.id DESC
    LIMIT 100
");
$stmt->execute([(int)$card['id'], $userId]);
$payments = $stmt->fetchAll();

$paidTotal = 0;
foreach ($payments as $row) {
    if ($row['status'] === 'paid') $paidTotal += (int)$row['amount_paise'];
}

$statusLabel = static function (string $status): string {
    switch ($status) {
        case 'paid':      return 'Paid';
        case 'created':   return 'Not completed';
        case 'failed':    return 'Failed';
        case 'cancelled': return 'Cancelled';
        default:          return ucfirst($status);
    }
};

ob_start();
?>

<div class="page-heading">
    <div>
        <p class="kicker">Payments</p>
        <h1>Transaction history</h1>
        <p class="small-note">Everything charged for <?= e($card['name']) ?>.</p>
    </div>
    <a class="button button-outline" href="card_membership.php?slug=<?= urlencode($card['slug']) ?>">Back to the plan</a>
</div>

<div class="stats">
    <div class="stat">
        <div class="stat-title">Payments</div>
        <div class="stat-value"><?= number_format(count($payments)) ?></div>
        <div class="stat-note">Last 100 shown</div>
    </div>
    <div class="stat">
        <div class="stat-title">Paid in total</div>
        <div class="stat-value">₹<?= number_format($paidTotal / 100) ?></div>
        <div class="stat-note">Successful payments only</div>
    </div>
    <div class="stat">
        <div class="stat-title">Current plan</div>
        <div class="stat-value" style="font-size:22px"><?= e(ucfirst((string)$card['membership'])) ?></div>
        <div class="stat-note"><?= $card['expiry_date'] ? 'Until ' . e(date('M j, Y', strtotime((string)$card['expiry_date']))) : 'No expiry set' ?></div>
    </div>
</div>

<section class="panel">
    <div class="panel-head">
        <div>
            <h2>Payments</h2>
            <p class="small-note">Test payments are marked, so they can never be mistaken for real money.</p>
        </div>
    </div>

    <?php if (!$payments): ?>
    <div class="empty-panel">
        <div>
            <div class="empty-icon" aria-hidden="true">₹</div>
            <div class="empty-title">No payments yet</div>
            <div class="empty-copy">When you pay for a plan, the payment appears here with its reference.</div>
        </div>
    </div>
    <?php else: ?>
    <div class="billing-scroll">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Plan</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Mode</th>
                    <th>Reference</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($payments as $row): ?>
                <tr>
                    <td>
                        <?php
                        $stamp = $row['paid_at'] ?: ($row['created_at'] ?? null);
                        $ts    = $stamp ? strtotime((string)$stamp) : false;
                        echo e($ts !== false ? date('M j, Y', $ts) : '—');
                        ?>
                    </td>
                    <td>
                        <?= e($row['plan_name'] ?? '—') ?>
                        <?php if (!empty($row['plan_duration'])): ?>
                        <br><span class="small-note"><?= (int)$row['plan_duration'] ?> days</span>
                        <?php endif; ?>
                    </td>
                    <td>₹<?= number_format(((int)$row['amount_paise']) / 100) ?></td>
                    <td>
                        <span class="pill"><?= e($statusLabel((string)$row['status'])) ?></span>
                    </td>
                    <td>
                        <?= $row['environment'] === 'live' ? 'Live' : e(ucfirst((string)$row['environment'])) ?>
                        <br><span class="small-note"><?= e($row['channel']) ?></span>
                    </td>
                    <td>
                        <span class="small-note" style="word-break:break-all">
                            <?= e((string)($row['razorpay_payment_id'] ?: ($row['razorpay_order_id'] ?: '—'))) ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<?php
$content = ob_get_clean();
require __DIR__ . '/includes/card_layout.php';
