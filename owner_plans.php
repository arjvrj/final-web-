<?php
/**
 * owner_plans.php — Owner panel: manage subscription plans
 * URL:   owner_plans.php
 * POST:  owner_plans.php?action=save     (create or update)
 *        owner_plans.php?action=delete
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

requireOwner();

/* =========================================================
   AJAX DISPATCHER
   ========================================================= */
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'save' || $action === 'delete') {
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }
    requireCsrf();

    /* ---------- SAVE PLAN ---------- */
    if ($action === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $price       = (float)($_POST['price'] ?? 0);
        $salePrice   = $_POST['sale_price'] !== '' && isset($_POST['sale_price'])
                        ? (float)$_POST['sale_price'] : null;
        $description = trim($_POST['description'] ?? '');
        $cta         = trim($_POST['cta'] ?? '');
        $duration    = max(1, (int)($_POST['duration'] ?? 30));

        if ($name === '') {
            echo json_encode(['error' => 'Plan name is required']);
            exit;
        }

        /* Sanitize features */
        $features = $_POST['features'] ?? [];
        if (!is_array($features)) $features = [];
        $features = array_values(array_filter(array_map('trim', $features)));
        $featuresJson = json_encode($features);

        try {
            if ($id > 0) {
                /* Update */
                $stmt = $conn->prepare("
                    UPDATE plans
                    SET name = ?, description = ?, price = ?, sale_price = ?,
                        features = ?, cta = ?, duration = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $description, $price, $salePrice, $featuresJson, $cta, $duration, $id]);
            } else {
                /* Insert */
                $stmt = $conn->prepare("
                    INSERT INTO plans (name, description, price, sale_price, features, cta, duration)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$name, $description, $price, $salePrice, $featuresJson, $cta, $duration]);
            }

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['error' => 'Could not save plan']);
        }
        exit;
    }

    /* ---------- DELETE PLAN ---------- */
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['error' => 'Invalid plan']);
            exit;
        }

        try {
            $stmt = $conn->prepare("DELETE FROM plans WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['error' => 'Could not delete plan']);
        }
        exit;
    }
}

/* =========================================================
   RENDER
   ========================================================= */

$page = 'plans';

$plans = $conn->query("SELECT * FROM plans ORDER BY price ASC")->fetchAll();

foreach ($plans as &$plan) {
    $plan['features_array'] = json_decode($plan['features'] ?? '[]', true);
    if (!is_array($plan['features_array'])) $plan['features_array'] = [];
}
unset($plan);

ob_start();
?>

<style>
    .modal { position: fixed; inset: 0; background: #18362b80; display: flex; align-items: center;
             justify-content: center; padding: 1rem; z-index: 20; }
    .modal[hidden] { display: none; }
    .modal .card { max-width: 620px; width: 100%; max-height: 88vh; overflow: auto; margin: 0; }
    .modal-head { display: flex; justify-content: space-between; align-items: start; gap: 1rem; }
    .plan-card { display: flex; flex-direction: column; }
    .plan-card .plan-foot { margin-top: auto; display: flex; gap: .5rem; flex-wrap: wrap; }
    .plan-card ul { padding-left: 1.1rem; margin: .6rem 0 1rem; }
    .plan-price { font-size: 1.6rem; font-weight: 700; }
    .feature-row { display: flex; gap: .5rem; align-items: center; margin-bottom: .5rem; }
    .feature-row input { flex: 1; }
    .feature-row button { padding: .45rem .7rem; }
    /* .tag is inline; inside a flex row it would stretch to fill the line. */
    .plan-card .tag, .plan-foot .tag { flex: 0 0 auto; align-self: flex-start; width: max-content; }
</style>

<div class="row" style="justify-content:space-between;align-items:center">
    <div>
        <h1 style="margin:0">Subscription plans</h1>
        <p style="margin:.3rem 0 0">These are the plans customers see on the card membership page.</p>
    </div>
    <button type="button" onclick="openAddPlanModal()">Add a plan</button>
</div>

<?php if (empty($plans)): ?>
<div class="card">
    <p class="muted">No plans yet. Until you add one, the membership page tells customers that no plans are available — it does not invent any.</p>
</div>
<?php else: ?>
<div class="grid">
    <?php foreach ($plans as $plan):
        $listPrice = (float)$plan['price'];
        $salePrice = (float)($plan['sale_price'] ?? 0);
        $hasSale   = $salePrice > 0 && $salePrice < $listPrice;
    ?>
    <div class="card plan-card">
        <div class="row" style="justify-content:space-between;align-items:start">
            <div>
                <h2 style="margin:0"><?= e($plan['name']) ?></h2>
                <p class="muted" style="margin:.2rem 0 0"><?= (int)$plan['duration'] ?> days</p>
            </div>
            <?php if ($hasSale): ?><span class="tag">Sale</span><?php endif; ?>
        </div>

        <?php if (!empty($plan['description'])): ?>
        <p class="muted"><?= e($plan['description']) ?></p>
        <?php endif; ?>

        <p class="plan-price">
            <?php if ($listPrice <= 0): ?>
            Free
            <?php else: ?>
            ₹<?= number_format($hasSale ? $salePrice : $listPrice) ?>
            <?php if ($hasSale): ?>
            <small class="muted" style="text-decoration:line-through">₹<?= number_format($listPrice) ?></small>
            <?php endif; ?>
            <?php endif; ?>
        </p>

        <?php if (!empty($plan['features_array'])): ?>
        <ul class="muted">
            <?php foreach ($plan['features_array'] as $feature): ?>
            <li><?= e((string)$feature) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <div class="plan-foot">
            <button type="button" onclick='editPlan(<?= json_encode($plan, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>Edit</button>
            <button type="button" class="secondary" onclick="deletePlan(<?= (int)$plan['id'] ?>)">Delete</button>
            <?php if (!empty($plan['cta'])): ?>
            <span class="tag">Button: <?= e($plan['cta']) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Add / edit plan -->
<div id="planModal" class="modal" hidden>
    <div class="card">
        <div class="modal-head">
            <h2 id="modalTitle" style="margin:0">Add a plan</h2>
            <button type="button" class="secondary" onclick="closePlanModal()" aria-label="Close">✕</button>
        </div>

        <form id="planForm" onsubmit="savePlan(event)">
            <?= csrfField() ?>
            <input type="hidden" id="planId" name="id" value="">

            <div class="field">
                <label for="planName">Name</label>
                <input type="text" id="planName" name="name" required placeholder="Starter">
            </div>

            <div class="grid">
                <div class="field">
                    <label for="planPrice">Price (₹)</label>
                    <input type="number" id="planPrice" name="price" min="0" step="1" value="0">
                </div>
                <div class="field">
                    <label for="planSalePrice">Sale price (₹, optional)</label>
                    <input type="number" id="planSalePrice" name="sale_price" min="0" step="1" placeholder="Leave empty for no sale">
                </div>
                <div class="field">
                    <label for="planDuration">Duration (days)</label>
                    <input type="number" id="planDuration" name="duration" min="1" value="30">
                </div>
                <div class="field">
                    <label for="planCta">Button label</label>
                    <input type="text" id="planCta" name="cta" placeholder="Choose this plan">
                </div>
            </div>

            <div class="field">
                <label for="planDescription">Short description</label>
                <input type="text" id="planDescription" name="description" placeholder="One review card for one location">
            </div>

            <div class="field">
                <label>Features</label>
                <div id="featuresContainer"></div>
                <button type="button" class="secondary" onclick="addFeature()">Add a feature line</button>
            </div>

            <div class="stack">
                <button type="submit">Save plan</button>
                <button type="button" class="secondary" onclick="closePlanModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
/* jQuery is no longer loaded on this page. */
function escapeHtmlAttr(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

function addFeature(value) {
    var row = document.createElement('div');
    row.className = 'feature-row';
    row.innerHTML = '<input type="text" name="features[]" value="' + escapeHtmlAttr(value || '') + '" placeholder="Unlimited reviews">' +
        '<button type="button" class="secondary" aria-label="Remove">✕</button>';
    row.querySelector('button').addEventListener('click', function () { row.remove(); });
    document.getElementById('featuresContainer').appendChild(row);
}

function openAddPlanModal() {
    document.getElementById('modalTitle').textContent = 'Add a plan';
    document.getElementById('planId').value = '';
    document.getElementById('planName').value = '';
    document.getElementById('planPrice').value = '0';
    document.getElementById('planSalePrice').value = '';
    document.getElementById('planDescription').value = '';
    document.getElementById('planCta').value = '';
    document.getElementById('planDuration').value = '30';
    document.getElementById('featuresContainer').innerHTML = '';
    addFeature();
    document.getElementById('planModal').hidden = false;
}

function editPlan(plan) {
    document.getElementById('modalTitle').textContent = 'Edit plan';
    document.getElementById('planId').value = plan.id;
    document.getElementById('planName').value = plan.name;
    document.getElementById('planPrice').value = plan.price;
    document.getElementById('planSalePrice').value = plan.sale_price || '';
    document.getElementById('planDescription').value = plan.description || '';
    document.getElementById('planCta').value = plan.cta || '';
    document.getElementById('planDuration').value = plan.duration || 30;

    var container = document.getElementById('featuresContainer');
    container.innerHTML = '';

    var features = [];
    try {
        features = typeof plan.features === 'string' ? JSON.parse(plan.features || '[]') : (plan.features || []);
    } catch (e) { features = []; }

    if (features.length) features.forEach(function (f) { addFeature(f); });
    else addFeature();

    document.getElementById('planModal').hidden = false;
}

function closePlanModal() {
    document.getElementById('planModal').hidden = true;
}

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

function savePlan(event) {
    event.preventDefault();

    var form = event.target;
    var submitBtn = form.querySelector('button[type="submit"]');
    var originalLabel = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Saving…';

    fetch('owner_plans.php?action=save', { method: 'POST', body: new FormData(form) })
        .then(function (response) { return response.json(); })
        .then(function (res) {
            if (res && res.success) { location.reload(); return; }
            alert('Error: ' + ((res && res.error) || 'Unknown error'));
            submitBtn.disabled = false;
            submitBtn.textContent = originalLabel;
        })
        .catch(function () {
            alert('Error saving plan. Please try again.');
            submitBtn.disabled = false;
            submitBtn.textContent = originalLabel;
        });
}

function deletePlan(id) {
    if (!confirm('Delete this plan? This cannot be undone.')) return;

    postJson('owner_plans.php?action=delete', { id: id })
        .then(function (res) {
            if (res && res.success) location.reload();
            else alert('Error: ' + ((res && res.error) || 'Unknown error'));
        })
        .catch(function () { alert('Error deleting plan. Please try again.'); });
}

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closePlanModal();
});
document.addEventListener('click', function (event) {
    var modal = document.getElementById('planModal');
    if (modal && !modal.hidden && event.target === modal) closePlanModal();
});
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/includes/owner_layout.php';
