<?php
/**
 * card_settings.php — Card settings (basic info, contacts, theme, AI keywords)
 * URL: card_settings.php?slug=<slug>
 * POSTs to card_update_settings.php (multipart, handles logo upload)
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

$page   = 'settings';
$slug   = trim($_GET['slug'] ?? '');
$userId = (int)$_SESSION['user_id'];

$stmt = $conn->prepare("SELECT * FROM review_cards WHERE slug = ? AND user_id = ? LIMIT 1");
$stmt->execute([$slug, $userId]);
$card = $stmt->fetch();

if (!$card) {
    header('Location: dashboard.php');
    exit;
}

/* ---------- Themes ---------- */
$themes = [
    'purple' => ['name' => 'Purple',  'preview' => 'linear-gradient(135deg, #7c3aed 0%, #4f46e5 100%)'],
    'blue'   => ['name' => 'Blue',    'preview' => 'linear-gradient(135deg, #2563eb 0%, #0891b2 100%)'],
    'pink'   => ['name' => 'Pink',    'preview' => 'linear-gradient(135deg, #db2777 0%, #e11d48 100%)'],
    'orange' => ['name' => 'Orange',  'preview' => 'linear-gradient(135deg, #ea580c 0%, #d97706 100%)'],
    'green'  => ['name' => 'Green',   'preview' => 'linear-gradient(135deg, #059669 0%, #047857 100%)'],
    'red'    => ['name' => 'Red',     'preview' => 'linear-gradient(135deg, #dc2626 0%, #e11d48 100%)'],
    'indigo' => ['name' => 'Indigo',  'preview' => 'linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%)'],
    'teal'   => ['name' => 'Teal',    'preview' => 'linear-gradient(135deg, #0d9488 0%, #0891b2 100%)'],
    'rose'   => ['name' => 'Rose',    'preview' => 'linear-gradient(135deg, #e11d48 0%, #dc2626 100%)'],
    'amber'  => ['name' => 'Amber',   'preview' => 'linear-gradient(135deg, #d97706 0%, #ca8a04 100%)'],
];

$details = json_decode($card['details'] ?? '{}', true);
if (!is_array($details)) $details = [];

$business = [
    'name'               => $card['name'],
    'logo'               => $details['logo'] ?? '',
    'description'        => $details['description'] ?? '',
    'whatsapp'           => $details['whatsapp'] ?? '',
    'facebook'           => $details['facebook'] ?? '',
    'instagram'          => $details['instagram'] ?? '',
    'website'            => $details['website'] ?? '',
    'google_review_url'  => $details['google_review_url'] ?? '',
    'business_keywords'  => $details['business_keywords'] ?? ['professional', 'quality', 'excellent service'],
    'theme'              => $details['theme'] ?? 'purple',
    'enable_keyword_selection' => $details['enable_keyword_selection'] ?? true,
    'language'                 => $details['language'] ?? 'en',
];

/* A rejected save comes back here: the reasons plus the text that was typed, so
   one bad link does not cost the owner the rest of the form. */
$settingsErrors = $_SESSION['settings_errors'] ?? [];
$oldLinks       = $_SESSION['settings_old'] ?? [];
unset($_SESSION['settings_errors'], $_SESSION['settings_old']);

foreach (array_keys(rg_link_fields()) as $linkField) {
    if (array_key_exists($linkField, $oldLinks)) {
        $business[$linkField] = (string)$oldLinks[$linkField];
    }
}
if (array_key_exists('whatsapp', $oldLinks)) {
    $business['whatsapp'] = (string)$oldLinks['whatsapp'];
}

ob_start();
?>

<style>
    /* Theme swatches: a radio the customer never sees, drawn as the design's
       bordered field card. */
    .rg-themes { display: grid; grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); gap: 10px; }
    .rg-theme { display: block; cursor: pointer; border: 2px solid #e0eae0; border-radius: 8px;
                padding: 8px; background: #fff; position: relative; }
    .rg-theme input { position: absolute; opacity: 0; pointer-events: none; }
    .rg-theme .swatch { height: 40px; border-radius: 6px; margin-bottom: 6px; }
    .rg-theme .name { font-size: 12px; font-weight: 700; text-align: center; }
    .rg-theme:has(input:checked) { border-color: var(--deep); box-shadow: 0 0 0 3px #173d2e1a; }
    .rg-theme:has(input:focus-visible) { outline: 3px solid #8aae7f; outline-offset: 2px; }
    .logo-box { width: 96px; height: 96px; border: 2px dashed #d5e1d3; border-radius: 10px;
                display: grid; place-items: center; overflow: hidden; background: #fff; color: #8a9b8c; }
    .logo-box img { width: 100%; height: 100%; object-fit: cover; }
    .logo-row { display: flex; gap: 16px; align-items: flex-start; flex-wrap: wrap; }
</style>

<div class="page-heading">
    <div>
        <span class="kicker">SETTINGS</span>
        <h1>Card settings</h1>
        <p>The business details your customers see, and the topics the AI writes from.</p>
    </div>
</div>

<?php if (!empty($_SESSION['settings_success'])): ?>
<p class="alert" role="status"><?= e($_SESSION['settings_success']) ?></p>
<?php unset($_SESSION['settings_success']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['settings_error'])): ?>
<p class="status error" role="alert"><?= e($_SESSION['settings_error']) ?></p>
<?php unset($_SESSION['settings_error']); ?>
<?php endif; ?>

<?php if ($settingsErrors): ?>
<div class="status error" role="alert" style="margin-bottom:18px">
    <p style="margin:0 0 6px"><strong>Nothing was saved.</strong> Please fix this first:</p>
    <ul style="margin:0;padding-left:18px">
        <?php foreach ($settingsErrors as $fieldError): ?>
        <li><?= e($fieldError) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form action="card_update_settings.php" method="post" enctype="multipart/form-data" class="form-panel">
<?= csrfField() ?>
    <input type="hidden" name="card_id" value="<?= (int)$card['id'] ?>">
    <input type="hidden" name="slug" value="<?= e($card['slug']) ?>">

    <section class="panel form-section">
        <h2>Basic information</h2>
        <p>This is what customers see at the top of your review page.</p>

        <div class="form-grid">
            <div class="field full">
                <label for="name">Business name</label>
                <input id="name" type="text" name="name" value="<?= e($business['name']) ?>" required>
            </div>

            <div class="field full">
                <label for="logo">Logo</label>
                <div class="logo-row">
                    <div class="logo-box" id="logoPreviewContainer">
                        <?php if (!empty($business['logo'])): ?>
                        <img src="<?= e(url($business['logo'])) ?>" alt="Current logo" id="logoPreview">
                        <?php else: ?>
                        <span id="logoPlaceholder" aria-hidden="true">▣</span>
                        <?php endif; ?>
                    </div>
                    <div style="flex:1;min-width:220px">
                        <input type="file" id="logo" name="logo" accept="image/*">
                        <p class="hint">A square image works best. Maximum 2 MB.</p>
                        <?php if (!empty($business['logo'])): ?>
                        <label class="check" style="margin-top:8px">
                            <input type="checkbox" name="remove_logo" value="1">
                            <span>Remove the current logo</span>
                        </label>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="field full">
                <label for="description">Business description</label>
                <textarea id="description" name="description" rows="3"
                          placeholder="Tell customers about your business."><?= e($business['description']) ?></textarea>
            </div>
        </div>
    </section>

    <section class="panel form-section" id="links">
        <h2>Contact and social links</h2>
        <p>Optional. Each one appears as a button at the top of your customer review page — but only if it is filled in and valid. Nothing is ever guessed.</p>

        <div class="form-grid">
            <div class="field">
                <label for="whatsapp">WhatsApp number</label>
                <input id="whatsapp" type="tel" name="whatsapp" value="<?= e($business['whatsapp']) ?>"
                       placeholder="+91 98765 00000" inputmode="tel">
                <p class="hint">Include the country code. Spaces and dashes are fine — it is stored as digits and opened as a wa.me chat.</p>
                <?php if (!empty($settingsErrors['whatsapp'])): ?>
                <p class="hint" style="color:#a1251b"><?= e($settingsErrors['whatsapp']) ?></p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label for="instagram">Instagram URL</label>
                <input id="instagram" type="url" name="instagram" value="<?= e($business['instagram']) ?>"
                       placeholder="https://instagram.com/yourpage" inputmode="url">
                <p class="hint">Full address, starting with https://</p>
                <?php if (!empty($settingsErrors['instagram'])): ?>
                <p class="hint" style="color:#a1251b"><?= e($settingsErrors['instagram']) ?></p>
                <?php endif; ?>
                <?php $igHint = rg_url_platform_hint('instagram', (string)$business['instagram']); ?>
                <?php if ($igHint !== ''): ?><p class="hint"><?= e($igHint) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label for="facebook">Facebook URL</label>
                <input id="facebook" type="url" name="facebook" value="<?= e($business['facebook']) ?>"
                       placeholder="https://facebook.com/yourpage" inputmode="url">
                <p class="hint">Full address, starting with https://</p>
                <?php if (!empty($settingsErrors['facebook'])): ?>
                <p class="hint" style="color:#a1251b"><?= e($settingsErrors['facebook']) ?></p>
                <?php endif; ?>
                <?php $fbHint = rg_url_platform_hint('facebook', (string)$business['facebook']); ?>
                <?php if ($fbHint !== ''): ?><p class="hint"><?= e($fbHint) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label for="website">Website URL</label>
                <input id="website" type="url" name="website" value="<?= e($business['website']) ?>"
                       placeholder="https://yourwebsite.com" inputmode="url">
                <p class="hint">Your own site — the address customers should land on.</p>
                <?php if (!empty($settingsErrors['website'])): ?>
                <p class="hint" style="color:#a1251b"><?= e($settingsErrors['website']) ?></p>
                <?php endif; ?>
            </div>

            <div class="field full">
                <label for="google_review_url">Google review URL</label>
                <input id="google_review_url" type="url" name="google_review_url"
                       value="<?= e($business['google_review_url']) ?>"
                       placeholder="https://search.google.com/local/writereview?placeid=..." inputmode="url">
                <p class="hint">Where the customer posts after copying a draft. Leave it empty and your page tells them the link is not available, instead of sending them to a generic Google search.</p>
                <?php if (!empty($settingsErrors['google_review_url'])): ?>
                <p class="hint" style="color:#a1251b"><?= e($settingsErrors['google_review_url']) ?></p>
                <?php endif; ?>
                <?php $grHint = rg_url_platform_hint('google_review_url', (string)$business['google_review_url']); ?>
                <?php if ($grHint !== ''): ?><p class="hint"><?= e($grHint) ?></p><?php endif; ?>
            </div>
        </div>

        <p class="hint" style="margin-top:12px">
            Links must be full <code>http://</code> or <code>https://</code> addresses. Anything else is refused rather than saved.
        </p>
    </section>

    <section class="panel form-section">
        <h2>Theme</h2>
        <p>The colour of your public review page.</p>

        <div class="rg-themes">
            <?php foreach ($themes as $key => $theme): ?>
            <label class="rg-theme">
                <input type="radio" name="theme" value="<?= e($key) ?>" <?= $business['theme'] === $key ? 'checked' : '' ?>>
                <div class="swatch" style="background: <?= e($theme['preview']) ?>"></div>
                <div class="name"><?= e($theme['name']) ?></div>
            </label>
            <?php endforeach; ?>
        </div>
        <p class="hint">The review page follows the design's violet identity; the theme you pick is stored and used wherever the app reads it.</p>
    </section>

    <section class="panel form-section">
        <h2>AI review settings</h2>
        <p>Topics are what the drafts are written from. The customer's own words are never sent to the AI.</p>

        <div class="field full">
            <label class="check" for="enable_keyword_selection">
                <input id="enable_keyword_selection" type="checkbox" name="enable_keyword_selection" value="1"
                       <?= $business['enable_keyword_selection'] ? 'checked' : '' ?>>
                <span>Let customers pick topics before the AI writes their drafts</span>
            </label>
            <p class="hint">When this is off, the review page asks for a star rating only.</p>
        </div>

        <div class="form-grid" style="margin-top:14px">
            <div class="field full">
                <label for="business_keywords">Topics</label>
                <input id="business_keywords" type="text" name="business_keywords"
                       value="<?= e(implode(', ', $business['business_keywords'] ?? [])) ?>"
                       placeholder="clean equipment, friendly trainers, fair price">
                <p class="hint">Separate them with commas. Customers can pick up to three.</p>
            </div>

            <div class="field">
                <label for="language">Default draft language</label>
                <select id="language" name="language">
                    <?php foreach (rg_ai_languages() as $langCode => $langLabel): ?>
                    <option value="<?= e($langCode) ?>" <?= ($business['language'] === $langCode) ? 'selected' : '' ?>><?= e($langLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="hint">The customer page opens in this language. Customers can switch it before generating.</p>
            </div>
        </div>
    </section>

    <div class="form-footer">
        <button type="submit" class="button button-dark">Save settings</button>
        <a class="button button-outline" href="card_home.php?slug=<?= urlencode($card['slug']) ?>">Cancel</a>
    </div>
</form>

<script>
/* Logo preview, no jQuery. */
(function () {
    var input = document.getElementById('logo');
    if (!input) return;
    input.addEventListener('change', function (event) {
        var file = event.target.files && event.target.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function (e) {
            var box = document.getElementById('logoPreviewContainer');
            var placeholder = document.getElementById('logoPlaceholder');
            if (placeholder) placeholder.remove();
            var img = document.getElementById('logoPreview');
            if (!img) {
                img = document.createElement('img');
                img.id = 'logoPreview';
                img.alt = 'New logo';
                box.appendChild(img);
            }
            img.src = e.target.result;
            box.style.borderStyle = 'solid';
        };
        reader.readAsDataURL(file);
    });
})();
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/includes/card_layout.php';
