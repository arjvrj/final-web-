<?php
/**
 * card_wheel.php — Rewards & Spin Wheel for one review card.
 * URL: card_wheel.php?slug=<slug>
 *
 * The customer page shows the wheel only when this campaign is on, inside its
 * dates, and its probabilities add up to exactly 100%. Everything here is
 * per-card; the odds are applied on the server when the customer spins.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/wheel.php';

requireLogin();

$page = 'wheel';
$pageStyles = ['css/dashboard-wheel.css', 'vendor/fontawesome/css/all.min.css'];

$slug   = trim((string)($_GET['slug'] ?? ''));
$userId = (int)$_SESSION['user_id'];

$stmt = $conn->prepare('SELECT * FROM review_cards WHERE slug = ? AND user_id = ? LIMIT 1');
$stmt->execute([$slug, $userId]);
$card = $stmt->fetch();

if (!$card) {
    header('Location: dashboard.php');
    exit;
}

/* Opening this page creates the campaign from the agreed defaults the first time,
   so the owner edits its own row rather than a hidden constant. */
$campaign = null;
$dbError  = '';
try {
    $campaign = rg_wheel_campaign($conn, (int)$card['id'], true);
} catch (Throwable $e) {
    $dbError = 'The wheel tables are missing. Run sql/migrations/2026-11-wheel.sql once, then reload.';
}

$segments = $campaign['segments'] ?? [];
$check    = rg_wheel_validate($segments);
$stats    = $dbError === '' ? rg_wheel_stats($conn, (int)$card['id']) : ['spins' => 0, 'coupons' => 0, 'redeemed' => 0, 'pending' => 0, 'by_segment' => [], 'recent' => []];
$isLive   = $campaign ? rg_wheel_is_open($campaign) && $check['ok'] : false;

/* Weights are stored in millionths of a percent; discount_percent is already a
   plain percentage. Two formatters, so 1% never renders as 0%. */
$percentOf = static function ($weight): string {
    return rtrim(rtrim(number_format((float)$weight / RG_WHEEL_UNIT * 100, 4, '.', ''), '0'), '.');
};
$plainPercent = static function ($value): string {
    return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
};

ob_start();
?>

<div class="page-heading">
  <div>
    <p class="kicker">REWARDS</p>
    <h1>Rewards &amp; Spin Wheel</h1>
    <p class="small-note" style="margin:6px 0 0">
      One spin per private feedback submission. No Google review is required and every rating qualifies.
    </p>
  </div>
  <div class="rgw-head-actions">
    <span class="rgw-state <?= $isLive ? 'is-live' : 'is-off' ?>">
      <span aria-hidden="true"></span><?= $isLive ? 'Live now' : 'Not live' ?>
    </span>
    <a class="button button-outline button-small" href="review.php?slug=<?= urlencode($card['slug']) ?>" target="_blank" rel="noopener">
      Open customer page ↗
    </a>
  </div>
</div>

<?php if ($dbError !== ''): ?>
<div class="rgw-alert is-error"><strong>Setup needed.</strong> <?= e($dbError) ?></div>
<?php else: ?>

<div class="rgw-stats">
  <div class="rgw-stat"><span>Total spins</span><strong><?= number_format($stats['spins']) ?></strong></div>
  <div class="rgw-stat"><span>Coupons issued</span><strong><?= number_format($stats['coupons']) ?></strong></div>
  <div class="rgw-stat"><span>Coupons redeemed</span><strong><?= number_format($stats['redeemed']) ?></strong></div>
  <div class="rgw-stat"><span>Unclaimed spins</span><strong><?= number_format($stats['pending']) ?></strong></div>
</div>

<?php if (!$check['ok']): ?>
<div class="rgw-alert is-error">
  <strong>The wheel is paused.</strong> <?= e(implode(' ', $check['errors'])) ?>
  Fix the numbers below and save — the customer page stays off until it is exactly 100%.
</div>
<?php endif; ?>

<section class="panel rgw-panel">
  <div class="panel-head">
    <div>
      <h2>Prizes and odds</h2>
      <p>Probabilities are applied on the server with a secure random number. The wedges are drawn the same size on purpose; these numbers are the real odds and are shown to customers in the campaign terms.</p>
    </div>
  </div>

  <form id="wheelForm" onsubmit="return false;" data-slug="<?= e($card['slug']) ?>">
    <div class="rgw-switch-row">
      <label class="rgw-switch">
        <input type="checkbox" name="enabled" id="wheelEnabled" <?= !empty($campaign['enabled']) ? 'checked' : '' ?>>
        <span class="rgw-switch-track" aria-hidden="true"></span>
        <span class="rgw-switch-text">Show the wheel on my customer page</span>
      </label>
    </div>

    <div class="form-grid rgw-grid">
      <div class="field">
        <label for="startsAt">Campaign starts</label>
        <input type="date" id="startsAt" name="starts_at" value="<?= e((string)($campaign['starts_at'] ?? '')) ?>">
        <p class="hint">Empty means “from now”.</p>
      </div>
      <div class="field">
        <label for="endsAt">Campaign ends</label>
        <input type="date" id="endsAt" name="ends_at" value="<?= e((string)($campaign['ends_at'] ?? '')) ?>">
        <p class="hint">Empty means “no end date”.</p>
      </div>
      <div class="field">
        <label for="expiryDays">Coupon valid for (days)</label>
        <input type="number" id="expiryDays" name="coupon_expiry_days" min="1" max="365" value="<?= (int)($campaign['coupon_expiry_days'] ?? 30) ?>">
      </div>
      <div class="field">
        <label for="inventory">Maximum coupons for this campaign</label>
        <input type="number" id="inventory" name="inventory_limit" min="0" max="100000" value="<?= (int)($campaign['inventory_limit'] ?? 0) ?>">
        <p class="hint">0 = no limit. When the cap is reached the wheel stops handing out spins.</p>
      </div>
      <div class="field full">
        <label for="redemptionNote">Redemption instructions (shown with every coupon)</label>
        <input type="text" id="redemptionNote" name="redemption_note" maxlength="240"
               value="<?= e((string)($campaign['redemption_note'] ?? '')) ?>"
               placeholder="Show this code at the counter before it expires.">
      </div>
    </div>

    <div class="billing-scroll rgw-table-wrap">
      <table class="rgw-table">
        <thead>
          <tr>
            <th>Prize</th>
            <th>Type</th>
            <th class="rgw-num">Probability %</th>
            <th class="rgw-num">Stock left</th>
            <th>On</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($segments as $segment):
            $id = (int)$segment['id'];
            $kindLabel = $segment['kind'] === 'discount' ? 'Discount' : ($segment['kind'] === 'thanks' ? 'Thank you' : 'No prize');
        ?>
          <tr>
            <td data-label="Prize">
              <input class="rgw-input" type="text" name="label[<?= $id ?>]" maxlength="120" value="<?= e((string)$segment['label']) ?>">
              <?php if ($segment['kind'] === 'discount'): ?>
              <span class="rgw-hint-small"><?= e($plainPercent($segment['discount_percent'])) ?>% off coupon</span>
              <?php endif; ?>
            </td>
            <td data-label="Type"><span class="rgw-chip is-<?= e($segment['kind']) ?>"><?= e($kindLabel) ?></span></td>
            <td data-label="Probability %" class="rgw-num">
              <input class="rgw-input rgw-input-num" type="number" step="0.0001" min="0" max="100"
                     name="weight[<?= $id ?>]" value="<?= e($percentOf($segment['weight'])) ?>"
                     oninput="refreshTotal()">
            </td>
            <td data-label="Stock left" class="rgw-num">
              <input class="rgw-input rgw-input-num" type="number" min="0" max="100000"
                     name="stock[<?= $id ?>]" value="<?= (int)($segment['inventory_limit'] ?? 0) ?>">
            </td>
            <td data-label="On">
              <input type="checkbox" name="active[<?= $id ?>]" value="1" <?= (int)$segment['is_active'] === 1 ? 'checked' : '' ?>>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="2" class="rgw-total-label">Total</td>
            <td class="rgw-num"><span id="oddsTotal" class="rgw-total"><?= e($percentOf($check['total'])) ?>%</span></td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <p class="hint" id="oddsHint">The total must be exactly 100% before the wheel can be switched on.</p>

    <div class="form-footer">
      <button type="button" class="button button-dark" onclick="saveWheel()" id="saveWheelBtn">Save rewards settings</button>
      <button type="button" class="button button-outline" onclick="resetOdds()">Restore default odds</button>
      <span class="status" id="wheelStatus"></span>
    </div>
  </form>
</section>

<div class="rgw-two-col">
  <section class="panel rgw-panel">
    <div class="panel-head"><div><h2>Prize distribution</h2><p>What customers have actually won.</p></div></div>
    <?php if (!$stats['by_segment']): ?>
    <div class="rgw-empty"><strong>No spins yet.</strong><span>Results appear here as soon as the first customer spins.</span></div>
    <?php else: ?>
    <table class="rgw-table rgw-simple">
      <thead><tr><th>Prize</th><th class="rgw-num">Won</th><th class="rgw-num">Share</th></tr></thead>
      <tbody>
      <?php foreach ($stats['by_segment'] as $row):
          $share = $stats['spins'] > 0 ? $row['count'] / $stats['spins'] * 100 : 0; ?>
        <tr>
          <td data-label="Prize"><?= e($row['label']) ?></td>
          <td data-label="Won" class="rgw-num"><?= number_format($row['count']) ?></td>
          <td data-label="Share" class="rgw-num"><?= number_format($share, 1) ?>%</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>

  <section class="panel rgw-panel">
    <div class="panel-head"><div><h2>Coupons</h2><p>The 50 most recent. Mark one as used when the customer redeems it.</p></div></div>
    <?php if (!$stats['recent']): ?>
    <div class="rgw-empty"><strong>No coupons yet.</strong><span>Discount prizes create a code automatically.</span></div>
    <?php else: ?>
    <table class="rgw-table rgw-simple">
      <thead><tr><th>Code</th><th>Discount</th><th>Expires</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($stats['recent'] as $coupon):
          $used = !empty($coupon['redeemed_at']); ?>
        <tr>
          <td data-label="Code"><code class="rgw-code"><?= e((string)$coupon['code']) ?></code></td>
          <td data-label="Discount"><?= e($plainPercent($coupon['discount_percent'])) ?>%</td>
          <td data-label="Expires"><?= e(date('M j, Y', strtotime((string)$coupon['expires_at']))) ?></td>
          <td data-label="Status">
            <?php if ($used): ?>
            <span class="rgw-chip is-used">Redeemed</span>
            <?php else: ?>
            <span class="rgw-chip is-open">Unused</span>
            <?php endif; ?>
          </td>
          <td data-label="Action">
            <button type="button" class="button button-outline button-small"
                    onclick="toggleCoupon('<?= e((string)$coupon['code']) ?>', <?= $used ? 'true' : 'false' ?>)">
              <?= $used ? 'Undo' : 'Mark used' ?>
            </button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>
</div>

<p class="rgw-footnote">
  Changing a prize or an odd never alters a spin that already happened: each result and each coupon keeps its own copy of the prize.
  Spins already completed cannot be re-drawn or deleted here.
</p>

<?php endif; ?>

<script>
const WHEEL_SAVE = 'card_update_wheel.php';
const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
const DEFAULT_PERCENTS = {d1: '10', d2: '1', d5: '0.5', d20: '0.01', d50: '0.001', better: '68.489', thanks: '20'};

function refreshTotal() {
    let total = 0;
    document.querySelectorAll('input[name^="weight["]').forEach(function (input) {
        const value = parseFloat((input.value || '0').replace(',', '.'));
        if (!isNaN(value)) total += value;
    });
    const box = document.getElementById('oddsTotal');
    const hint = document.getElementById('oddsHint');
    const ok = Math.abs(total - 100) < 0.00005;
    box.textContent = (Math.round(total * 10000) / 10000) + '%';
    box.classList.toggle('is-bad', !ok);
    hint.textContent = ok
        ? 'Exactly 100%. The wheel can run.'
        : 'The total must be exactly 100% before the wheel can be switched on.';
    hint.classList.toggle('is-bad', !ok);
}

function resetOdds() {
    document.querySelectorAll('input[name^="weight["]').forEach(function (input) {
        const id = input.name.replace(/[^0-9]/g, '');
        const slugRow = input.closest('tr');
        const chip = slugRow ? slugRow.querySelector('.rgw-chip') : null;
        const kind = chip ? chip.className.replace('rgw-chip is-', '') : '';
        const map = {1: 'd1', 2: 'd2', 3: 'd5', 4: 'd20', 5: 'd50', 6: 'better', 7: 'thanks'};
        input.value = DEFAULT_PERCENTS[map[id]] || input.value;
    });
    refreshTotal();
    showToast('Default odds filled in. Save to apply them.');
}

function saveWheel() {
    const form = document.getElementById('wheelForm');
    const data = new FormData(form);
    data.append('slug', form.dataset.slug);
    data.append('do', 'save');

    const button = document.getElementById('saveWheelBtn');
    button.disabled = true;

    fetch(WHEEL_SAVE, {
        method: 'POST',
        body: data,
        headers: {'X-CSRF-Token': csrfToken, 'X-Requested-With': 'XMLHttpRequest'}
    }).then(function (r) { return r.json(); }).then(function (res) {
        button.disabled = false;
        if (res && res.success) {
            showToast(res.message || 'Saved.');
            setTimeout(function () { location.reload(); }, 900);
        } else {
            showToast((res && res.error) || 'Could not save.');
        }
    }).catch(function () {
        button.disabled = false;
        showToast('Could not reach the server.');
    });
}

function toggleCoupon(code, wasUsed) {
    const data = new FormData();
    data.append('slug', '<?= e($card['slug']) ?>');
    data.append('do', wasUsed ? 'unredeem' : 'redeem');
    data.append('code', code);

    fetch(WHEEL_SAVE, {
        method: 'POST', body: data,
        headers: {'X-CSRF-Token': csrfToken, 'X-Requested-With': 'XMLHttpRequest'}
    }).then(function (r) { return r.json(); }).then(function (res) {
        if (res && res.success) { location.reload(); }
        else { showToast((res && res.error) || 'Could not update that coupon.'); }
    });
}

function showToast(message) {
    const toast = document.getElementById('wheelToast');
    if (!toast) { alert(message); return; }
    document.getElementById('wheelToastMessage').textContent = message;
    toast.hidden = false;
    clearTimeout(window.__wheelToast);
    window.__wheelToast = setTimeout(function () { toast.hidden = true; }, 3200);
}

refreshTotal();
</script>

<div id="wheelToast" class="rgw-toast" hidden role="status" aria-live="polite">
  <span id="wheelToastMessage"></span>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/includes/card_layout.php';
