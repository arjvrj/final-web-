<?php
/**
 * owner_settings.php — Owner panel: platform-wide settings
 * URL:   owner_settings.php
 * POST:  owner_settings.php?action=save
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

requireOwner();

/* =========================================================
   AJAX DISPATCHER
   ========================================================= */
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'save') {
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }
    requireCsrf();

    $whatsapp = trim($_POST['whatsapp'] ?? '');

    if ($whatsapp === '') {
        echo json_encode(['error' => 'WhatsApp number is required']);
        exit;
    }

    try {
        /* ---------- Platform WhatsApp (single-row table) ---------- */
        $stmt = $conn->query("SELECT COUNT(*) FROM platform_settings");
        $exists = (int)$stmt->fetchColumn() > 0;

        if ($exists) {
            $stmt = $conn->prepare("UPDATE platform_settings SET whatsapp = ? LIMIT 1");
            $stmt->execute([$whatsapp]);
        } else {
            $stmt = $conn->prepare("INSERT INTO platform_settings (whatsapp) VALUES (?)");
            $stmt->execute([$whatsapp]);
        }

        /* ---------- Optional: App Name + GROQ key (settings table) ---------- */
        if (isset($_POST['app_name'])) {
            $appName = trim((string)$_POST['app_name']);
            if ($appName !== '') setSetting('app_name', $appName);
        }

        /* An empty field means "keep the key that is already saved": the value is
           never printed back into the page, so an empty field is the normal case. */
        if (isset($_POST['groq_api_key'])) {
            $groqKey = trim((string)$_POST['groq_api_key']);
            if ($groqKey !== '') setSetting('groq_api_key', $groqKey);
        }
        if (isset($_POST['clear_groq_key']) && $_POST['clear_groq_key'] === '1') {
            setSetting('groq_api_key', '');
        }

        /* ---------- AI provider and model ----------
           Kept separate from the key so an owner can switch model without
           re-entering a key. Unknown providers are refused rather than stored. */
        if (isset($_POST['ai_provider'])) {
            $provider = strtolower(trim((string)$_POST['ai_provider']));
            if (!array_key_exists($provider, rg_ai_providers())) {
                throw new Exception('AI provider not recognised.');
            }
            setSetting('ai_provider', $provider);
        }

        if (isset($_POST['ai_model'])) {
            $model = trim((string)$_POST['ai_model']);
            if ($model !== '' && !preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/-]{0,79}$#', $model)) {
                throw new Exception('AI model name looks invalid. Use the model id from the provider, e.g. openai/gpt-oss-20b.');
            }
            setSetting('ai_model', $model);
        }

        /* xAI/Grok key: only used when the provider is xAI. Empty means keep. */
        if (isset($_POST['grok_api_key']) && trim((string)$_POST['grok_api_key']) !== '') {
            setSetting('grok_api_key', trim((string)$_POST['grok_api_key']));
        }
        if (isset($_POST['clear_grok_key']) && $_POST['clear_grok_key'] === '1') {
            setSetting('grok_api_key', '');
        }

        /* ---------- Payment settings ---------- */
        if (isset($_POST['payments_mode'])) {
            $mode = (string)$_POST['payments_mode'];
            if (!in_array($mode, ['disabled', 'test', 'live'], true)) $mode = 'disabled';

            /* Live mode requires the operator to type the confirmation phrase. */
            if ($mode === 'live' && trim((string)($_POST['razorpay_live_confirm'] ?? '')) !== 'I_AUTHORIZE_REAL_CHARGES') {
                throw new Exception('To switch to live mode, type I_AUTHORIZE_REAL_CHARGES exactly.');
            }

            setSetting('payments_mode', $mode);
        }

        if (isset($_POST['razorpay_key_id'])) {
            $keyId = trim((string)$_POST['razorpay_key_id']);
            if ($keyId === '' || preg_match('/^rzp_(test|live)_[A-Za-z0-9]{6,64}$/', $keyId)) {
                setSetting('razorpay_key_id', $keyId);
            } else {
                throw new Exception('That does not look like a Razorpay key id. It starts with rzp_test_ or rzp_live_.');
            }
        }

        /* Empty means "keep the stored secret"; clearing is explicit. */
        if (isset($_POST['razorpay_key_secret']) && trim((string)$_POST['razorpay_key_secret']) !== '') {
            setSetting('razorpay_key_secret', trim((string)$_POST['razorpay_key_secret']));
        }
        if (isset($_POST['razorpay_webhook_secret']) && trim((string)$_POST['razorpay_webhook_secret']) !== '') {
            setSetting('razorpay_webhook_secret', trim((string)$_POST['razorpay_webhook_secret']));
        }
        if (isset($_POST['clear_razorpay_secrets']) && $_POST['clear_razorpay_secrets'] === '1') {
            setSetting('razorpay_key_secret', '');
            setSetting('razorpay_webhook_secret', '');
            setSetting('payments_mode', 'disabled');
        }

        if (isset($_POST['razorpay_timeout'])) {
            $timeout = max(2, min(30, (int)$_POST['razorpay_timeout']));
            setSetting('razorpay_timeout', (string)$timeout);
        }

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        /* Our own validation messages (mode, key shape) are safe to show; anything
           else is logged and replaced. */
        error_log('owner settings save failed: ' . $e->getMessage());
        $own = $e->getMessage();
        $safe = (strpos($own, 'I_AUTHORIZE_REAL_CHARGES') !== false
              || strpos($own, 'Razorpay key id') !== false
              || strpos($own, 'AI provider') !== false
              || strpos($own, 'AI model') !== false);
        echo json_encode(['error' => $safe ? $own : 'Could not save settings']);
    }
    exit;
}

/* =========================================================
   TEST THE AI CONNECTION (AJAX, owner only)
   =========================================================
   Tests exactly what is in the form, not only what is saved, so a wrong key is
   caught before it reaches a customer. Nothing is written to the database here.
   The response never contains the key. */
if ($action === 'test_ai') {
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false, 'code' => 'method', 'message' => 'Method not allowed']);
        exit;
    }
    requireCsrf();

    $settings = rg_ai_settings_from_db();

    $postedModel = trim((string)($_POST['ai_model'] ?? ''));
    if ($postedModel !== '') $settings['ai_model'] = $postedModel;

    $postedGroq = trim((string)($_POST['groq_api_key'] ?? ''));
    if ($postedGroq !== '') $settings['groq_api_key'] = $postedGroq;

    $postedXai = trim((string)($_POST['grok_api_key'] ?? ''));
    if ($postedXai !== '') $settings['grok_api_key'] = $postedXai;

    $cfg = rg_ai_config($settings, trim((string)($_POST['ai_provider'] ?? '')));
    $result = rg_ai_test_connection($cfg);

    /* Anything the owner needs for debugging also goes to the log, redacted. */
    rg_ai_log(sprintf(
        'test_ai provider=%s model=%s ok=%s code=%s',
        $cfg['provider'], $cfg['model'], $result['ok'] ? 'yes' : 'no', (string)($result['code'] ?? '')
    ), $cfg['api_key']);

    echo json_encode($result);
    exit;
}

/* =========================================================
   RENDER
   ========================================================= */

$page = 'settings';

$settings = $conn->query("SELECT * FROM platform_settings LIMIT 1")->fetch();
if (!$settings) {
    $settings = ['whatsapp' => '', 'details' => '{}'];
}
$platformWhatsapp = $settings['whatsapp'] ?? '';

$appName        = getSetting('app_name', 'ReviewAI');
$currentGroqKey = getSetting('groq_api_key', '');

/* AI settings (Stage 1 of the upgrade). api_key is never copied into a page
   variable: only the public shape is read here. */
$aiCfg          = rg_ai_public_config(rg_ai_config(rg_ai_settings_from_db()));
$aiProvider     = $aiCfg['provider'];
$aiModel        = $aiCfg['model'];
$aiModelChoices = rg_ai_model_choices($aiProvider);
$aiProviders    = rg_ai_providers();
$aiHasXaiKey    = getSetting('grok_api_key', '') !== '';
$curlAvailable  = function_exists('curl_init');

/* Payment settings (Stage 7). Secrets are write-only: the page shows whether one
   is stored, never the value. */
$payMode        = strtolower((string)getSetting('payments_mode', 'disabled'));
$payKeyId       = (string)getSetting('razorpay_key_id', '');
$payHasSecret   = (string)getSetting('razorpay_key_secret', '') !== '';
$payHasWebhook  = (string)getSetting('razorpay_webhook_secret', '') !== '';
$payTimeout     = (string)getSetting('razorpay_timeout', '15');
$payKeyIsLive   = strpos($payKeyId, 'rzp_live_') === 0;
$payKeyIsTest   = strpos($payKeyId, 'rzp_test_') === 0;

ob_start();
?>

<h1>Platform settings</h1>
<p>These values are used across the whole application.</p>

<form id="settingsForm" onsubmit="saveSettings(event)">

    <div class="card">
        <h2>WhatsApp payments</h2>
        <p class="muted">Every upgrade request and payment notification goes to this number.</p>
        <div class="alert">
            Keep this number active on WhatsApp — customers and card owners reach you here when they choose a plan.
        </div>
        <div class="field">
            <label for="whatsapp">WhatsApp number (with country code) *</label>
            <input id="whatsapp" type="text" name="whatsapp" required
                   value="<?= e($platformWhatsapp) ?>" placeholder="+91 98765 43210">
        </div>
    </div>

    <div class="card">
        <h2>App name</h2>
        <p class="muted">Shown in page titles, the sidebar and on the public pages.</p>
        <div class="field">
            <label for="app_name">App name</label>
            <input id="app_name" type="text" name="app_name" value="<?= e($appName) ?>" placeholder="ReviewAI">
        </div>
    </div>

    <div class="card">
        <h2>AI review writing</h2>
        <p class="muted">Powers the drafts on every customer page. The key is stored on the server and is never printed back into this page.</p>

        <?php if (!$curlAvailable): ?>
        <p><span class="tag" style="background:#fdecec;color:#8a2020">cURL is not enabled</span></p>
        <p class="muted">PHP's cURL extension is off on this server, so no AI call can succeed. Ask the host to enable <code>curl</code>.</p>
        <?php endif; ?>

        <?php if (!empty($aiCfg['key_mismatch'])): ?>
        <p><span class="tag" style="background:#fff7e5;color:#604b23">Key and provider do not match</span></p>
        <p class="muted">
            The saved key looks like a <?= e(rg_ai_provider_meta($aiCfg['key_provider'])['label']) ?> key
            (it starts with <code><?= e(rg_ai_provider_meta($aiCfg['key_provider'])['key_hint']) ?></code>),
            but the provider is set to <?= e($aiCfg['label']) ?>. Change the provider below, or save the
            key that belongs to <?= e($aiCfg['label']) ?>.
        </p>
        <?php endif; ?>

        <div class="field">
            <label for="ai_provider">Provider</label>
            <select id="ai_provider" name="ai_provider">
                <?php foreach ($aiProviders as $providerId => $providerMeta): ?>
                <option value="<?= e($providerId) ?>" <?= $aiProvider === $providerId ? 'selected' : '' ?>><?= e($providerMeta['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="muted">
                Groq stays the default. Groq (keys start <code>gsk_</code>) and xAI Grok
                (keys start <code>xai-</code>) are different companies and the keys are not
                interchangeable — a key from one sent to the other is rejected with "invalid API key".
            </p>
        </div>

        <div class="field">
            <label for="ai_model">Model</label>
            <input id="ai_model" name="ai_model" type="text" value="<?= e($aiModel) ?>"
                   list="aiModelChoices" spellcheck="false" autocomplete="off">
            <datalist id="aiModelChoices">
                <?php foreach ($aiModelChoices as $modelChoice): ?>
                <option value="<?= e($modelChoice) ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <p class="muted">
                Default is <code>openai/gpt-oss-20b</code> — quick and cheap;
                <code>openai/gpt-oss-120b</code> writes better but is slower. Any model id the
                provider offers can be typed here.
            </p>
        </div>

        <?php if ($currentGroqKey !== ''): ?>
        <p><span class="tag">A Groq key is saved</span></p>
        <?php else: ?>
        <p><span class="tag" style="background:#fff7e5;color:#604b23">No Groq key saved</span></p>
        <p class="muted">AI review generation is switched off until a key is saved.</p>
        <?php endif; ?>

        <div class="field">
            <label for="groq_api_key"><?= $currentGroqKey !== '' ? 'Replace the Groq key' : 'Add a Groq key' ?></label>
            <div class="feature-row" style="align-items:center">
                <input id="groq_api_key" type="password" name="groq_api_key" value=""
                       autocomplete="off" spellcheck="false"
                       placeholder="<?= $currentGroqKey !== '' ? '•••••••• saved — leave empty to keep it' : 'gsk_…' ?>">
                <button type="button" class="secondary" onclick="toggleGroqVisibility()" id="groqEyeIcon" aria-label="Show or hide the key">Show</button>
            </div>
            <p class="muted">
                Get a key from <a href="https://console.groq.com/keys" target="_blank" rel="noopener">console.groq.com/keys</a>.
            </p>
        </div>

        <?php if ($currentGroqKey !== ''): ?>
        <label class="stack" style="align-items:center">
            <input type="checkbox" name="clear_groq_key" value="1">
            <span class="muted">Remove the saved Groq key (this switches AI generation off)</span>
        </label>
        <?php endif; ?>

        <div class="field">
            <label for="grok_api_key">xAI Grok key <span class="muted">(only used when the provider above is xAI Grok)</span></label>
            <div class="feature-row" style="align-items:center">
                <input id="grok_api_key" type="password" name="grok_api_key" value=""
                       autocomplete="off" spellcheck="false"
                       placeholder="<?= $aiHasXaiKey ? '•••••••• saved — leave empty to keep it' : 'xai-… (optional)' ?>">
            </div>
            <p class="muted">
                From <a href="https://console.x.ai/team/default/api-keys" target="_blank" rel="noopener">console.x.ai</a>.
                Leave empty if you are using Groq.
            </p>
            <?php if ($aiHasXaiKey): ?>
            <label class="stack" style="align-items:center">
                <input type="checkbox" name="clear_grok_key" value="1">
                <span class="muted">Remove the saved xAI key</span>
            </label>
            <?php endif; ?>
        </div>

        <div class="field">
            <button type="button" class="secondary" id="testAiBtn" onclick="testAiConnection()">Test connection</button>
            <p class="muted" style="margin-top:6px">
                Tests the provider selected above — Groq or xAI Grok — using the values in this form,
                saved or not. Nothing is stored and the key is never returned.
            </p>
            <div id="aiTestResult" role="status" aria-live="polite" class="muted" style="margin-top:6px"></div>
        </div>

    </div>


    <div class="card">
        <h2>Card payments (Razorpay)</h2>
        <p class="muted">
            Payments are <strong><?= $payMode === 'disabled' ? 'switched off' : e($payMode === 'live' ? 'LIVE — real money' : 'in test mode') ?></strong>.
            The WhatsApp upgrade path keeps working either way.
        </p>

        <?php if ($payMode === 'live'): ?>
        <div class="alert" style="background:#fff0ee;border-color:#e6b8b0;color:#8b2f25">
            Live mode is on. Every successful payment takes real money.
        </div>
        <?php endif; ?>

        <div class="field">
            <label for="payments_mode">Mode</label>
            <select id="payments_mode" name="payments_mode">
                <option value="disabled" <?= $payMode === 'disabled' ? 'selected' : '' ?>>Off — hide the pay button</option>
                <option value="test" <?= $payMode === 'test' ? 'selected' : '' ?>>Test — rzp_test_ keys, no money</option>
                <option value="live" <?= $payMode === 'live' ? 'selected' : '' ?>>Live — real payments</option>
            </select>
        </div>

        <div class="field">
            <label for="razorpay_key_id">Key id</label>
            <input id="razorpay_key_id" type="text" name="razorpay_key_id" value="<?= e($payKeyId) ?>"
                   placeholder="rzp_test_…" autocomplete="off" spellcheck="false">
            <p class="muted">
                <?php if ($payKeyId === ''): ?>
                No key stored.
                <?php elseif ($payKeyIsLive): ?>
                A <strong>live</strong> key is stored.
                <?php elseif ($payKeyIsTest): ?>
                A <strong>test</strong> key is stored.
                <?php else: ?>
                The stored key id does not look like a Razorpay key.
                <?php endif; ?>
                The key id is public: it is sent to the browser during checkout, which is normal.
            </p>
        </div>

        <div class="field">
            <label for="razorpay_key_secret">Key secret</label>
            <input id="razorpay_key_secret" type="password" name="razorpay_key_secret" value=""
                   placeholder="<?= $payHasSecret ? '•••••••• saved — leave empty to keep it' : 'not set' ?>"
                   autocomplete="off" spellcheck="false">
            <p class="muted">
                <?= $payHasSecret ? 'A secret is stored.' : 'No secret stored.' ?>
                It is never printed back into this page and never reaches the browser.
            </p>
        </div>

        <div class="field">
            <label for="razorpay_webhook_secret">Webhook secret</label>
            <input id="razorpay_webhook_secret" type="password" name="razorpay_webhook_secret" value=""
                   placeholder="<?= $payHasWebhook ? '•••••••• saved — leave empty to keep it' : 'not set' ?>"
                   autocomplete="off" spellcheck="false">
            <p class="muted">
                <?= $payHasWebhook ? 'A webhook secret is stored.' : 'No webhook secret stored — webhooks will be refused.' ?>
                Set the same value in Razorpay → Settings → Webhooks, at
                <code><?= e(rtrim((string)getSetting('site_url', ''), '/')) ?>/payment_webhook.php</code>.
            </p>
        </div>

        <div class="field">
            <label for="razorpay_live_confirm">Live confirmation</label>
            <input id="razorpay_live_confirm" type="text" name="razorpay_live_confirm" value=""
                   placeholder="type I_AUTHORIZE_REAL_CHARGES to switch to live" autocomplete="off">
            <p class="muted">Only needed when changing the mode to Live. Never stored.</p>
        </div>

        <div class="field">
            <label for="razorpay_timeout">Provider timeout (seconds)</label>
            <input id="razorpay_timeout" type="number" name="razorpay_timeout" min="2" max="30" value="<?= e($payTimeout) ?>">
        </div>

        <?php if ($payHasSecret || $payHasWebhook): ?>
        <label class="stack" style="align-items:center">
            <input type="checkbox" name="clear_razorpay_secrets" value="1">
            <span class="muted">Remove both secrets and switch payments off</span>
        </label>
        <?php endif; ?>

        <p class="muted">
            Test it with Test Mode keys first: a test payment goes through the same code
            path as a live one but moves no money. The webhook endpoint must be reachable
            over HTTPS from Razorpay.
        </p>
    </div>

    <div class="stack">
        <button type="submit">Save settings</button>
        <a class="btn secondary" href="owner.php">Cancel</a>
    </div>
</form>

<p class="status" id="toast" role="status"><span id="toastMessage"></span></p>

<script>
/* jQuery is no longer loaded on this page. */
function showToast(message) {
    var box = document.getElementById('toast');
    var text = document.getElementById('toastMessage');
    if (!box || !text) return;
    text.textContent = message;
    box.style.color = '#1f513b';
    clearTimeout(window.__toastTimer);
    window.__toastTimer = setTimeout(function () { text.textContent = ''; }, 3000);
}

function saveSettings(event) {
    event.preventDefault();

    var whatsappField = document.querySelector('input[name="whatsapp"]');
    var whatsapp = whatsappField.value.trim();
    if (!whatsapp) { alert('Please enter a WhatsApp number.'); whatsappField.focus(); return; }

    var form = event.target;
    var submitBtn = form.querySelector('button[type="submit"]');
    var originalLabel = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Saving…';

    var body = new URLSearchParams();
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    body.append('_csrf', csrfMeta ? csrfMeta.getAttribute('content') : '');
    body.append('whatsapp', whatsapp);
    body.append('app_name', document.querySelector('input[name="app_name"]').value.trim());

    var keyField = document.getElementById('groq_api_key');
    if (keyField && keyField.value.trim() !== '') body.append('groq_api_key', keyField.value.trim());

    var clearBox = document.querySelector('input[name="clear_groq_key"]');
    if (clearBox && clearBox.checked) body.append('clear_groq_key', '1');

    /* AI provider and model travel with every save so switching model does not
       require re-typing a key. */
    var aiProviderField = document.getElementById('ai_provider');
    if (aiProviderField) body.append('ai_provider', aiProviderField.value);

    var aiModelField = document.getElementById('ai_model');
    if (aiModelField && aiModelField.value.trim() !== '') body.append('ai_model', aiModelField.value.trim());

    var xaiField = document.getElementById('grok_api_key');
    if (xaiField && xaiField.value.trim() !== '') body.append('grok_api_key', xaiField.value.trim());

    var clearXai = document.querySelector('input[name="clear_grok_key"]');
    if (clearXai && clearXai.checked) body.append('clear_grok_key', '1');

    /* Payment settings. Empty secret fields are simply not sent, so a stored
       secret survives a save that only changes the app name. */
    var payMode = document.getElementById('payments_mode');
    if (payMode) body.append('payments_mode', payMode.value);

    var keyId = document.getElementById('razorpay_key_id');
    if (keyId) body.append('razorpay_key_id', keyId.value.trim());

    var keySecret = document.getElementById('razorpay_key_secret');
    if (keySecret && keySecret.value.trim() !== '') body.append('razorpay_key_secret', keySecret.value.trim());

    var webhookSecret = document.getElementById('razorpay_webhook_secret');
    if (webhookSecret && webhookSecret.value.trim() !== '') body.append('razorpay_webhook_secret', webhookSecret.value.trim());

    var liveConfirm = document.getElementById('razorpay_live_confirm');
    if (liveConfirm && liveConfirm.value.trim() !== '') body.append('razorpay_live_confirm', liveConfirm.value.trim());

    var timeoutField = document.getElementById('razorpay_timeout');
    if (timeoutField) body.append('razorpay_timeout', timeoutField.value.trim());

    var clearSecrets = document.querySelector('input[name="clear_razorpay_secrets"]');
    if (clearSecrets && clearSecrets.checked) body.append('clear_razorpay_secrets', '1');

    fetch('owner_settings.php?action=save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    })
        .then(function (response) { return response.json(); })
        .then(function (res) {
            submitBtn.disabled = false;
            submitBtn.textContent = originalLabel;
            if (res && res.success) {
                if (keyField) keyField.value = '';
                ['razorpay_key_secret', 'razorpay_webhook_secret', 'razorpay_live_confirm'].forEach(function (id) {
                    var field = document.getElementById(id);
                    if (field) field.value = '';
                });
                showToast('Settings saved.');
            } else {
                alert('Error: ' + ((res && res.error) || 'Unknown error'));
            }
        })
        .catch(function () {
            submitBtn.disabled = false;
            submitBtn.textContent = originalLabel;
            alert('Error saving settings. Please try again.');
        });
}

/* Test the AI provider with whatever is in the form right now.
   Uses X-Requested-With so a CSRF failure comes back as JSON, not an HTML page. */
function testAiConnection() {
    var btn = document.getElementById('testAiBtn');
    var out = document.getElementById('aiTestResult');
    if (!btn || !out) return;

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var body = new URLSearchParams();
    body.append('_csrf', csrfMeta ? csrfMeta.getAttribute('content') : '');
    body.append('action', 'test_ai');

    var provider = document.getElementById('ai_provider');
    if (provider) body.append('ai_provider', provider.value);

    var model = document.getElementById('ai_model');
    if (model && model.value.trim() !== '') body.append('ai_model', model.value.trim());

    var groqKey = document.getElementById('groq_api_key');
    if (groqKey && groqKey.value.trim() !== '') body.append('groq_api_key', groqKey.value.trim());

    var xaiKey = document.getElementById('grok_api_key');
    if (xaiKey && xaiKey.value.trim() !== '') body.append('grok_api_key', xaiKey.value.trim());

    var originalLabel = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Testing…';
    out.style.color = '';
    out.style.fontWeight = '';
    out.textContent = 'Contacting the provider…';

    fetch('owner_settings.php?action=test_ai', {
        method: 'POST',
        body: body,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
    })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            out.textContent = (res && res.message) ? res.message : 'No answer from the server.';
            out.style.color = (res && res.ok) ? '#1f7a3d' : '#a1251b';
            out.style.fontWeight = '600';
        })
        .catch(function () {
            out.textContent = 'The test request could not be completed. Reload the page and try again.';
            out.style.color = '#a1251b';
            out.style.fontWeight = '600';
        })
        .then(function () {
            btn.disabled = false;
            btn.textContent = originalLabel;
        });
}

function toggleGroqVisibility() {
    var input = document.getElementById('groq_api_key');
    var button = document.getElementById('groqEyeIcon');
    if (!input) return;
    if (input.type === 'password') {
        input.type = 'text';
        if (button) button.textContent = 'Hide';
    } else {
        input.type = 'password';
        if (button) button.textContent = 'Show';
    }
}
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/includes/owner_layout.php';
