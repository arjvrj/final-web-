<?php
/**
 * card_home.php — Card panel dashboard
 * URL: card_home.php?slug=<slug>
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

$page   = 'home';
$slug   = trim($_GET['slug'] ?? '');
$userId = (int)$_SESSION['user_id'];

/* ---------- Fetch + verify ownership ---------- */
$stmt = $conn->prepare("SELECT * FROM review_cards WHERE slug = ? AND user_id = ? LIMIT 1");
$stmt->execute([$slug, $userId]);
$card = $stmt->fetch();

if (!$card) {
    header('Location: dashboard.php');
    exit;
}

/* ---------- Stats ---------- */
$stmt = $conn->prepare("SELECT COUNT(*) FROM reviews WHERE card_id = ?");
$stmt->execute([$card['id']]);
$reviewCount = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT AVG(rating) FROM reviews WHERE card_id = ?");
$stmt->execute([$card['id']]);
$avgRating = (float)($stmt->fetchColumn() ?? 0);

$stmt = $conn->prepare("SELECT COUNT(*) FROM reviews WHERE card_id = ? AND rating >= 4");
$stmt->execute([$card['id']]);
$highCount = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM feedbacks WHERE card_id = ?");
$stmt->execute([$card['id']]);
$feedbackCount = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM reviews WHERE card_id = ? AND details LIKE '%ai_generated%'");
$stmt->execute([$card['id']]);
$aiReviewCount = (int)$stmt->fetchColumn();

$reviewPath = 'review.php?slug=' . urlencode($card['slug']);
$reviewUrl  = url($reviewPath);

/* A QR code is scanned by a phone, so it has to carry an absolute URL. If
   site_url has not been configured yet, derive one from the request rather than
   printing a code that no scanner can open. */
if (!preg_match('#^https?://#i', $reviewUrl)) {
    $rgHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') == 443)
            || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    $rgHost = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $rgDir  = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
    if ($rgDir === '/' || $rgDir === '.' || $rgDir === '\\') $rgDir = '';
    $reviewUrl = ($rgHttps ? 'https' : 'http') . '://' . $rgHost . $rgDir . '/' . $reviewPath;
}

/* The QR is drawn in the browser by the bundled qrcode-generator library. It used
   to be an <img> pointing at api.qrserver.com, which sent every business's review
   URL to a third party and left the code blank when that service was blocked. */
$qrScript = asset('js/vendor/qrcode-generator.js');

/* ---------- Trial days ---------- */
$trialDaysLeft = null;
if (!empty($card['expiry_date'])) {
    $trialDaysLeft = (int)ceil((strtotime($card['expiry_date']) - time()) / 86400);
}

ob_start();
?>

<style>
    /* .status is a design class. The design only shows it when it has a message,
       so it stays collapsed until an action fills it in. */
    #toast { display: none; margin-top: 14px; }
    #toast.show { display: block; }
</style>

<div class="page-heading">
    <div>
        <span class="kicker">CARD OVERVIEW</span>
        <h1><?= e($card['name']) ?></h1>
        <p>Here is what your review card is doing.</p>
    </div>
    <a class="button button-dark" href="<?= e($reviewUrl) ?>" target="_blank" rel="noopener">Open review page ↗</a>
</div>

<?php if ($card['membership'] === 'trial' && !empty($card['expiry_date'])): ?>
<p class="alert">
    <strong>Trial period.</strong> It ends on <?= date('M j, Y', strtotime($card['expiry_date'])) ?>
    (<?= max(0, (int)$trialDaysLeft) ?> day<?= max(0, (int)$trialDaysLeft) === 1 ? '' : 's' ?> left).
    <a href="card_membership.php?slug=<?= urlencode($card['slug']) ?>">Choose a plan →</a>
</p>
<?php endif; ?>

<div class="stats">
    <article class="stat">
        <div class="stat-title"><span>Total reviews</span><span class="stat-symbol" aria-hidden="true">▤</span></div>
        <div class="stat-value"><?= number_format($reviewCount) ?></div>
        <div class="stat-note"><?= $highCount ?> were 4 stars or more</div>
    </article>
    <article class="stat">
        <div class="stat-title"><span>Average rating</span><span class="stat-symbol" aria-hidden="true">★</span></div>
        <div class="stat-value"><?= $reviewCount > 0 ? number_format($avgRating, 1) : '—' ?></div>
        <div class="stat-note"><?= $reviewCount > 0 ? 'Out of 5' : 'No ratings yet' ?></div>
    </article>
    <article class="stat">
        <div class="stat-title"><span>AI drafts used</span><span class="stat-symbol" aria-hidden="true">✳</span></div>
        <div class="stat-value"><?= number_format($aiReviewCount) ?></div>
        <div class="stat-note">Reviews started from an AI draft</div>
    </article>
    <article class="stat">
        <div class="stat-title"><span>Private feedback</span><span class="stat-symbol" aria-hidden="true">✉</span></div>
        <div class="stat-value"><?= number_format($feedbackCount) ?></div>
        <div class="stat-note"><a href="card_reviews.php?slug=<?= urlencode($card['slug']) ?>&amp;filter=feedback">Read it →</a></div>
    </article>
</div>

<div class="overview-grid">
    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Your review page</h2>
                <p>Give this link or the QR code to a customer.</p>
            </div>
        </div>

        <div class="field" style="margin-top:16px">
            <label for="reviewUrl">Review page link</label>
            <input id="reviewUrl" type="text" readonly value="<?= e($reviewUrl) ?>">
        </div>

        <div class="form-footer">
            <button class="button button-dark button-small" type="button" onclick="copyReviewUrl()">Copy link</button>
            <a class="button button-outline button-small" href="<?= e($reviewUrl) ?>" target="_blank" rel="noopener">Open page ↗</a>
            <button class="button button-outline button-small" type="button" onclick="shareReviewPage()">Share</button>
            <button class="button button-outline button-small" type="button" onclick="downloadQRCode()">Download QR</button>
        </div>

        <p class="status" id="toast" role="status"><span id="toastMessage"></span></p>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>QR code</h2>
                <p>Customers scan this to open the page.</p>
            </div>
        </div>
        <div style="text-align:center;margin-top:14px">
            <canvas id="qrCodeImage" width="200" height="200" role="img"
                    aria-label="QR code for <?= e($card['name']) ?>"
                    style="cursor:zoom-in;background:#fff;border:1px solid var(--line);border-radius:8px;padding:8px"
                    onclick="openQRModal()"></canvas>
            <noscript><p class="hint">The QR code is drawn in your browser. Enable JavaScript, or use the link below with any QR tool.</p></noscript>
            <p class="hint">Click the code to enlarge it.</p>
        </div>
    </section>
</div>

<div class="overview-bottom">
    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Quick actions</h2>
                <p>Where to go next.</p>
            </div>
        </div>
        <div class="form-footer" style="gap:10px">
            <a class="button button-outline button-small" href="card_reviews.php?slug=<?= urlencode($card['slug']) ?>&amp;filter=reviews">Manage reviews</a>
            <a class="button button-outline button-small" href="card_reviews.php?slug=<?= urlencode($card['slug']) ?>&amp;filter=feedback">Feedback<?= $feedbackCount > 0 ? ' (' . (int)$feedbackCount . ')' : '' ?></a>
            <a class="button button-outline button-small" href="card_settings.php?slug=<?= urlencode($card['slug']) ?>">Card settings</a>
            <a class="button button-outline button-small" href="card_membership.php?slug=<?= urlencode($card['slug']) ?>">Membership</a>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>How customers use it</h2>
                <p>Nothing is posted for them.</p>
            </div>
        </div>
        <ol class="hint" style="padding-left:18px;line-height:1.9">
            <li>They scan the QR code or open the link.</li>
            <li>They pick a star rating and the topics that stood out.</li>
            <li>They get three drafts and copy the one they like onto Google.</li>
            <li>1–3 stars goes to the private feedback form instead.</li>
        </ol>
    </section>
</div>

<!-- QR modal -->
<div id="qrModal" hidden style="position:fixed;inset:0;background:#102d2170;display:flex;align-items:center;justify-content:center;padding:16px;z-index:50">
    <div class="panel" style="max-width:420px;width:100%">
        <div class="panel-head">
            <div><h2>Your QR code</h2><p><?= e($card['name']) ?></p></div>
            <button class="button button-outline button-small" type="button" onclick="closeQRModal()" aria-label="Close">✕</button>
        </div>
        <div style="text-align:center;margin:14px 0">
            <canvas id="qrModalImage" width="320" height="320" role="img" aria-label="QR code"
                    style="background:#fff;border:1px solid var(--line);border-radius:8px;padding:8px"></canvas>
        </div>
        <p class="hint" style="text-align:center;word-break:break-all"><?= e($reviewUrl) ?></p>
        <div class="form-footer">
            <button class="button button-dark button-small" type="button" onclick="downloadQRCode()">Download</button>
            <button class="button button-outline button-small" type="button" onclick="copyReviewUrl()">Copy link</button>
        </div>
    </div>
</div>

<script src="<?= e($qrScript) ?>"></script>
<script>
/* The page no longer loads jQuery, so these are plain DOM calls. */
function toast(message) {
    var box = document.getElementById('toast');
    var text = document.getElementById('toastMessage');
    if (!box || !text) return;
    text.textContent = message;
    box.classList.add('show');
    clearTimeout(window.__toastTimer);
    window.__toastTimer = setTimeout(function () {
        box.classList.remove('show');
        text.textContent = '';
    }, 2200);
}

function copyReviewUrl() {
    var input = document.getElementById('reviewUrl');
    if (!input) return;
    input.select();
    input.setSelectionRange(0, 99999);
    try { document.execCommand('copy'); } catch (e) { /* ignored */ }
    if (navigator.clipboard) { navigator.clipboard.writeText(input.value).catch(function () {}); }
    toast('Link copied.');
}

function openQRModal() {
    var modal = document.getElementById('qrModal');
    if (modal) modal.hidden = false;
}

function closeQRModal() {
    var modal = document.getElementById('qrModal');
    if (modal) modal.hidden = true;
}

/* Draws the QR locally. No network, no third-party service: qrcode-generator is
   bundled in assets/js/vendor/. */
function drawQRCode(canvas, text) {
    if (!canvas || typeof qrcode !== 'function') return false;
    var qr = qrcode(0, 'M');
    qr.addData(text);
    qr.make();

    var count = qr.getModuleCount();
    var quiet = 4;                                  /* the quiet zone scanners need */
    var total = count + quiet * 2;
    var px    = canvas.width / total;
    var ctx   = canvas.getContext('2d');

    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = '#000000';
    for (var row = 0; row < count; row++) {
        for (var col = 0; col < count; col++) {
            if (qr.isDark(row, col)) {
                ctx.fillRect(Math.round((col + quiet) * px), Math.round((row + quiet) * px),
                             Math.ceil(px), Math.ceil(px));
            }
        }
    }
    return true;
}

function downloadQRCode() {
    var slug = <?= json_encode((string)$card['slug']) ?>;
    var source = document.getElementById('qrModalImage') || document.getElementById('qrCodeImage');
    if (!source) return;
    try {
        var link = document.createElement('a');
        link.href = source.toDataURL('image/png');
        link.download = slug + '-qr-code.png';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        toast('QR code downloaded.');
    } catch (error) {
        toast('Could not save it. Right-click the QR code and choose Save image.');
    }
}

function shareReviewPage() {
    var reviewUrl = <?= json_encode($reviewUrl) ?>;
    var cardName = <?= json_encode((string)$card['name']) ?>;
    if (navigator.share) {
        navigator.share({
            title: cardName + ' - leave a review',
            text: 'I would love your feedback. It takes a minute.',
            url: reviewUrl
        }).catch(function () {});
    } else {
        copyReviewUrl();
    }
}

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeQRModal();
});
document.addEventListener('click', function (event) {
    var modal = document.getElementById('qrModal');
    if (modal && !modal.hidden && event.target === modal) closeQRModal();
});

(function () {
    var text = <?= json_encode($reviewUrl) ?>;
    var small = document.getElementById('qrCodeImage');
    var large = document.getElementById('qrModalImage');
    var drawn = drawQRCode(small, text);
    drawQRCode(large, text);
    if (!drawn) {
        /* the bundled library is missing — say so rather than showing an empty box */
        var notice = document.createElement('p');
        notice.className = 'hint';
        notice.textContent = 'The QR library did not load. Use the link below with any QR tool.';
        if (small && small.parentNode) small.parentNode.insertBefore(notice, small.nextSibling);
    }
})();
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/includes/card_layout.php';
