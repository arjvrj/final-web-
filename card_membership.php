<?php
/**
 * card_membership.php — Card plan/membership management
 * URL: card_membership.php?slug=<slug>
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payment_flow.php';

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

/* ---------- Plans ---------- */
$stmt = $conn->prepare("SELECT * FROM plans ORDER BY price ASC");
$stmt->execute();
$plans = $stmt->fetchAll();

/* If the plans table is empty the page says so. It does not invent plans. */

foreach ($plans as &$plan) {
    if (is_string($plan['features'])) {
        $plan['features'] = json_decode($plan['features'], true);
    }
    if (!is_array($plan['features'])) {
        $plan['features'] = [];
    }
    if (!isset($plan['duration'])) {
        $plan['duration'] = 30;
    }
}
unset($plan);

/* ---------- Payments (Stage 7) ---------- */
$payCfg = rg_payment_config([
    'payments_mode'           => getSetting('payments_mode', 'disabled'),
    'razorpay_key_id'         => getSetting('razorpay_key_id', ''),
    'razorpay_key_secret'     => getSetting('razorpay_key_secret', ''),
    'razorpay_webhook_secret' => getSetting('razorpay_webhook_secret', ''),
    'razorpay_timeout'        => getSetting('razorpay_timeout', '15'),
    'whatsapp_number'         => getSetting('whatsapp_number', ''),
]);
$payPublic   = rg_public_config($payCfg);
$payEnabled  = (bool)$payPublic['enabled'];
$trialUsed   = rg_trial_used($conn, $userId);
$trialLocked = $trialUsed || rg_card_is_paid($card);

/* ---------- Current plan state ---------- */
$currentMembership = $card['membership'] ?? 'trial';
$expiryDate        = $card['expiry_date'] ?? null;
$daysLeft          = 0;
$isExpired         = false;

if ($expiryDate) {
    $now    = new DateTime();
    $expiry = new DateTime($expiryDate);
    if ($expiry > $now) {
        $daysLeft = $now->diff($expiry)->days;
    } else {
        $isExpired = true;
        if ($currentMembership !== 'expired') {
            $currentMembership = 'expired';
            $stmt = $conn->prepare("UPDATE review_cards SET membership = 'expired' WHERE id = ?");
            $stmt->execute([$card['id']]);
        }
    }
}

/* Resolve the current plan record once — used by header + progress bar */
$currentPlan = null;
foreach ($plans as $p) {
    if (strtolower($p['name']) === strtolower($currentMembership)) {
        $currentPlan = $p;
        break;
    }
}

/* ---------- Usage stats ---------- */
$stmt = $conn->prepare("SELECT COUNT(*) FROM reviews WHERE card_id = ?");
$stmt->execute([$card['id']]);
$reviewCount = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT AVG(rating) FROM reviews WHERE card_id = ?");
$stmt->execute([$card['id']]);
$avgRating = (float)($stmt->fetchColumn() ?? 0);

/* ---------- Platform WhatsApp ---------- */
try {
    $stmt = $conn->query("SELECT whatsapp FROM platform_settings LIMIT 1");
    $platformSettings = $stmt->fetch();
    $platformWhatsapp = $platformSettings['whatsapp'] ?? getSetting('business_whatsapp', '');
} catch (Exception $e) {
    $platformWhatsapp = getSetting('business_whatsapp', '');
}

ob_start();
?>

<style>
    /* .status is a design class that only shows when it has a message. */
    .rg-modal { position: fixed; inset: 0; background: #102d2170; display: flex; align-items: center;
                justify-content: center; padding: 16px; z-index: 50; }
    .rg-modal[hidden] { display: none; }
    .rg-modal .panel { max-width: 460px; width: 100%; }
    .billing-cards article.current { border-color: var(--deep); box-shadow: 0 0 0 3px #173d2e14; }
    .billing-cards ul { padding-left: 18px; margin: 12px 0 16px; }
    .rg-plan-actions { display: flex; flex-direction: column; gap: 8px; }
    .rg-plan-actions .button { width: 100%; }
    .rg-pay-note { margin-top: 12px; }
</style>

<div class="page-heading">
    <div>
        <span class="kicker">MEMBERSHIP</span>
        <h1>Your plan</h1>
        <p>What this card is on, and what else is available.</p>
    </div>
    <a class="button button-outline" href="card_home.php?slug=<?= urlencode($card['slug']) ?>">Back to the card</a>
    <a class="button button-outline" href="card_payments.php?slug=<?= urlencode($card['slug']) ?>">Transaction history</a>
</div>

<?php if ($isExpired): ?>
<p class="status error" role="alert">
    <strong>This plan has expired.</strong>
    <?php if ($expiryDate): ?>It ended on <?= date('F j, Y', strtotime($expiryDate)) ?>.<?php endif; ?>
    The review page stays visible but new reviews cannot be collected until a plan is active.
</p>
<?php endif; ?>

<div class="stats">
    <article class="stat">
        <div class="stat-title"><span>Current plan</span><span class="stat-symbol" aria-hidden="true">◈</span></div>
        <div class="stat-value" style="font-size:22px"><?= e(ucfirst($currentMembership)) ?></div>
        <div class="stat-note"><?= $isExpired ? 'Expired' : 'Active' ?></div>
    </article>
    <article class="stat">
        <div class="stat-title"><span>Renews / ends</span><span class="stat-symbol" aria-hidden="true">◷</span></div>
        <div class="stat-value" style="font-size:22px"><?= $expiryDate ? date('M j, Y', strtotime($expiryDate)) : '—' ?></div>
        <div class="stat-note"><?= $daysLeft > 0 ? $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . ' left' : 'No end date' ?></div>
    </article>
    <article class="stat">
        <div class="stat-title"><span>What you pay</span><span class="stat-symbol" aria-hidden="true">₹</span></div>
        <?php
        $currentListPrice = $currentPlan ? (float)$currentPlan['price'] : 0.0;
        $currentSalePrice = $currentPlan ? (float)($currentPlan['sale_price'] ?? 0) : 0.0;
        $currentPayable   = ($currentSalePrice > 0 && $currentSalePrice < $currentListPrice) ? $currentSalePrice : $currentListPrice;
        ?>
        <div class="stat-value" style="font-size:22px"><?= $currentPayable > 0 ? '₹' . number_format($currentPayable) : 'Free' ?></div>
        <div class="stat-note">
            <?php if ($currentPlan): ?>
            per <?= (int)($currentPlan['duration'] ?? 30) ?> days
            <?php elseif ($currentMembership === 'trial'): ?>
            Trial card — no charge
            <?php else: ?>
            No matching plan record
            <?php endif; ?>
        </div>
    </article>
    <article class="stat">
        <div class="stat-title"><span>Reviews so far</span><span class="stat-symbol" aria-hidden="true">▤</span></div>
        <div class="stat-value"><?= number_format($reviewCount) ?></div>
        <div class="stat-note">Average <?= $reviewCount > 0 ? number_format($avgRating, 1) : '—' ?>★</div>
    </article>
</div>

<?php
if ($expiryDate && !$isExpired && $currentPlan):
    $totalDays = (int)($currentPlan['duration'] ?? 30);
    if ($totalDays > 0):
        $usedDays = max(0, $totalDays - $daysLeft);
        $progressPercent = min(100, max(0, ($usedDays / $totalDays) * 100));
?>
<section class="panel" style="margin-top:18px">
    <div class="panel-head">
        <div>
            <h2>Plan progress</h2>
            <p><?= $daysLeft ?> of <?= $totalDays ?> days left</p>
        </div>
    </div>
    <div class="meter" role="progressbar" aria-valuenow="<?= (int)$progressPercent ?>" aria-valuemin="0" aria-valuemax="100">
        <div style="height:100%;width:<?= (int)$progressPercent ?>%;background:var(--deep);border-radius:4px"></div>
    </div>
    <div class="meter-caption"><span><?= date('M j, Y', strtotime($expiryDate)) ?></span><span><?= $daysLeft ?> days left</span></div>
</section>
<?php endif; endif; ?>

<section class="panel" style="margin-top:18px">
    <div class="panel-head">
        <div>
            <h2><?= $isExpired ? 'Choose a plan' : ($currentMembership === 'trial' ? 'Upgrade' : 'Other plans') ?></h2>
            <p><?= $payEnabled ? 'Pay online — the plan starts as soon as Razorpay confirms the payment — or ask the owner on WhatsApp.' : 'Plans are set by the site owner. Choosing one sends the owner a WhatsApp message — no card is charged here.' ?></p>
        </div>
    </div>

    <?php if (!$plans): ?>
    <div class="empty-panel">
        <div>
            <div class="empty-icon" aria-hidden="true">◈</div>
            <div class="empty-title">No plans are set up yet</div>
            <div class="empty-copy">The site owner has not added any plans, so there is nothing to choose from here. Your card keeps working on its current plan.</div>
        </div>
    </div>
    <?php else: ?>
    <div class="billing-cards" style="margin-top:16px">
        <?php foreach ($plans as $plan):
            $planType = strtolower((string)$plan['name']);
            $isCurrentPlan = !$isExpired && ($planType === strtolower($currentMembership));
            $isPopular = stripos((string)$plan['name'], 'pro') !== false;
        ?>
        <article class="<?= $isCurrentPlan ? 'current' : '' ?>">
            <div class="panel-head">
                <div>
                    <h3 style="font-size:15px;margin:0"><?= e($plan['name']) ?></h3>
                    <p class="small-note" style="margin:4px 0 0"><?= e($plan['description']) ?></p>
                </div>
                <?php if ($isCurrentPlan): ?>
                <span class="pill">Current</span>
                <?php elseif ($isPopular): ?>
                <span class="pill">Popular</span>
                <?php endif; ?>
            </div>

            <?php
            $listPrice = (float)$plan['price'];
            $salePrice = (float)($plan['sale_price'] ?? 0);
            $hasSale   = $salePrice > 0 && $salePrice < $listPrice;
            $payable   = $hasSale ? $salePrice : $listPrice;
            ?>
            <p class="billing-price" style="margin:14px 0 0;font-weight:700">
                <?php if ($listPrice <= 0): ?>
                Free
                <?php else: ?>
                ₹<?= number_format($payable) ?>
                <?php if ($hasSale): ?>
                <small class="small-note" style="text-decoration:line-through">₹<?= number_format($listPrice) ?></small>
                <?php endif; ?>
                <?php endif; ?>
            </p>
            <p class="small-note" style="margin:2px 0 0">per <?= (int)($plan['duration'] ?? 30) ?> days</p>

            <ul class="small-note">
                <?php foreach (($plan['features'] ?? []) as $feature): ?>
                <li><?= e((string)$feature) ?></li>
                <?php endforeach; ?>
            </ul>

            <?php if ($isCurrentPlan): ?>
            <button class="button button-outline" type="button" disabled>Current plan</button>
            <?php elseif ((float)$plan['price'] == 0.0): ?>
                <?php if ($trialLocked): ?>
                <button class="button button-outline" type="button" disabled>Trial already used</button>
                <?php else: ?>
                <button class="button button-dark" type="button" onclick="reactivateTrial()">Start the free 3-day trial</button>
                <?php endif; ?>
            <?php else: ?>
            <div class="rg-plan-actions">
                <?php if ($payEnabled): ?>
                <button class="button button-dark" type="button"
                        onclick="payOnline(<?= (int)$plan['id'] ?>, <?= e(json_encode((string)$plan['name'], JSON_HEX_QUOT | JSON_HEX_APOS)) ?>)">
                    Pay ₹<?= number_format($payable) ?> online
                </button>
                <?php endif; ?>
                <button class="button <?= $payEnabled ? 'button-outline' : 'button-dark' ?>" type="button"
                        onclick="upgradePlan(<?= e(json_encode((string)$plan['name'], JSON_HEX_QUOT | JSON_HEX_APOS)) ?>, <?= (float)$payable ?>)">
                    <?= $payEnabled ? 'Ask on WhatsApp' : e((string)($plan['cta'] ?? 'Choose this plan')) ?>
                </button>
            </div>
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<?php if ($payEnabled): ?>
<p class="small-note rg-pay-note" id="payStatus" role="status"></p>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<?php endif; ?>

<!-- Upgrade modal -->
<div id="upgradeModal" class="rg-modal" hidden>
    <div class="panel">
        <div class="panel-head">
            <div>
                <h2>Upgrade to <span id="upgradePlanName"></span></h2>
                <p>Message the site owner on WhatsApp to switch plans.</p>
            </div>
            <button class="button button-outline button-small" type="button" onclick="closeUpgradeModal()" aria-label="Close">✕</button>
        </div>
        <div class="form-footer">
            <a id="whatsappUpgradeLink" class="button button-dark" href="#" target="_blank" rel="noopener">Contact on WhatsApp</a>
            <button class="button button-outline" type="button" onclick="closeUpgradeModal()">Cancel</button>
        </div>
    </div>
</div>

<script>
/* jQuery is no longer loaded on this page. */
var UPGRADE_CARD_NAME = <?= json_encode((string)$card['name']) ?>;
var PLATFORM_WHATSAPP = <?= json_encode(preg_replace('/[^0-9]/', '', (string)$platformWhatsapp)) ?>;

function csrfHeaderToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

function setPayStatus(message, isError) {
    var box = document.getElementById('payStatus');
    if (!box) return;
    box.textContent = message || '';
    box.style.color = isError ? '#8b2f25' : '';
}

/* Start a payment: the server creates the order and decides the amount. */
function payOnline(planId, planName) {
    var button = (typeof event !== 'undefined' && event && event.currentTarget) ? event.currentTarget : null;
    var original = button ? button.textContent : '';
    if (button) { button.disabled = true; button.textContent = 'Opening…'; }

    var body = new URLSearchParams();
    body.append('_csrf', csrfHeaderToken());
    body.append('card_id', <?= (int)$card['id'] ?>);
    body.append('plan_id', planId);

    setPayStatus('');

    fetch('pay_create_order.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (button) { button.disabled = false; button.textContent = original; }
            if (!res || !res.success) {
                setPayStatus((res && res.error) || 'Payments are unavailable right now.', true);
                return;
            }

            var options = {
                key: res.key_id,
                order_id: res.order_id,
                amount: res.amount,
                currency: res.currency,
                name: res.card_name,
                description: res.plan_name + ' plan',
                handler: function (response) { confirmPayment(response); },
                modal: {
                    ondismiss: function () {
                        setPayStatus('Payment window closed. Nothing was charged.');
                    }
                }
            };

            try {
                var rzp = new Razorpay(options);
                rzp.on('payment.failed', function (failure) {
                    setPayStatus('Payment failed: ' + ((failure.error && failure.error.description) || 'the bank declined it.'), true);
                });
                rzp.open();
            } catch (e) {
                setPayStatus('The payment window could not open. Please try again.', true);
            }
        })
        .catch(function () {
            if (button) { button.disabled = false; button.textContent = original; }
            setPayStatus('Could not reach the payment server. Please try again.', true);
        });
}

/* Razorpay says the payment finished. The server decides whether that is true. */
function confirmPayment(response) {
    setPayStatus('Confirming the payment…');

    var body = new URLSearchParams();
    body.append('_csrf', csrfHeaderToken());
    body.append('razorpay_order_id', response.razorpay_order_id || '');
    body.append('razorpay_payment_id', response.razorpay_payment_id || '');
    body.append('razorpay_signature', response.razorpay_signature || '');

    fetch('pay_verify.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res && res.success) {
                setPayStatus('Payment received. Reloading…');
                setTimeout(function () { location.reload(); }, 900);
            } else {
                setPayStatus((res && res.error) || 'We could not verify the payment. Contact us on WhatsApp.', true);
            }
        })
        .catch(function () {
            setPayStatus('We could not verify the payment. Contact us on WhatsApp.', true);
        });
}

function upgradePlan(name, price) {
    document.getElementById('upgradePlanName').textContent = name;
    var message = 'Hi, I want to upgrade my plan to ' + name + ' for my review card "' + UPGRADE_CARD_NAME + '"';
    document.getElementById('whatsappUpgradeLink').href =
        'https://wa.me/' + PLATFORM_WHATSAPP + '?text=' + encodeURIComponent(message);
    document.getElementById('upgradeModal').hidden = false;
}

function closeUpgradeModal() {
    document.getElementById('upgradeModal').hidden = true;
}

function reactivateTrial() {
    if (!confirm('Reactivate the trial? You get a new trial period on this card.')) return;

    var body = new URLSearchParams();
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    body.append('_csrf', csrfMeta ? csrfMeta.getAttribute('content') : '');
    body.append('card_id', <?= (int)$card['id'] ?>);

    fetch('card_reactivate_trial.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    })
        .then(function (r) { return r.json(); })
        .then(function (response) {
            if (response && response.success) location.reload();
            else alert('Error: ' + ((response && response.error) || 'Unknown error'));
        })
        .catch(function () { alert('Error reactivating trial. Please try again.'); });
}

document.addEventListener('click', function (event) {
    var modal = document.getElementById('upgradeModal');
    if (modal && !modal.hidden && event.target === modal) closeUpgradeModal();
});
document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeUpgradeModal();
});
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/includes/card_layout.php';
