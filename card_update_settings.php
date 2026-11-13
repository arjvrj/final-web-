<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: dashboard.php'); exit; }
requireCsrf();

$userId = (int)$_SESSION['user_id'];
$cardId = (int)($_POST['card_id'] ?? 0);
$slug   = trim($_POST['slug'] ?? '');

$stmt = $conn->prepare("SELECT * FROM review_cards WHERE id = ? AND user_id = ? LIMIT 1");
$stmt->execute([$cardId, $userId]);
$card = $stmt->fetch();
if (!$card) { header('Location: dashboard.php'); exit; }

$details = json_decode($card['details'] ?? '{}', true);
if (!is_array($details)) $details = [];
$card_logo_old = (string)($details['logo'] ?? '');

$name = trim($_POST['name'] ?? $card['name']);
if ($name === '') $name = $card['name'];

$details['description'] = trim($_POST['description'] ?? '');

/* ---------- Outgoing links: validated, never trusted ----------
   htmlspecialchars() escapes quotes but not a scheme, so a stored
   "javascript:..." was a live href on the public page. Every link is now run
   through rg_safe_url()/rg_wa_normalise() and a rejected value is never written.
   If anything is rejected the whole form comes back with the reasons filled in,
   rather than silently saving a half-edited card. */
$linkErrors = [];

$submittedLinks = [];
foreach (array_keys(rg_link_fields()) as $linkField) {
    $raw = trim((string)($_POST[$linkField] ?? ''));
    $submittedLinks[$linkField] = $raw;

    $safe = rg_safe_url($raw, $linkField);

    if ($raw !== '' && $safe === '') {
        $fieldMeta = rg_link_fields()[$linkField];
        $linkErrors[$linkField] = $fieldMeta['label'] . ' must be a full http:// or https:// address, for example ' . $fieldMeta['expect'];
        $safe = rg_safe_url((string)($details[$linkField] ?? ''), $linkField);   // keep the previous good value
    }
    $details[$linkField] = $safe;
}

/* WhatsApp is stored as E.164 digits, so every entry format ends up as one
   usable wa.me link. */
$rawWhatsapp = trim((string)($_POST['whatsapp'] ?? ''));
$submittedLinks['whatsapp'] = $rawWhatsapp;
$waDigits = rg_wa_normalise($rawWhatsapp);
if ($rawWhatsapp !== '' && $waDigits === '') {
    $linkErrors['whatsapp'] = 'WhatsApp needs a phone number with the country code, for example +91 98765 43210.';
    $details['whatsapp'] = rg_wa_normalise((string)($details['whatsapp'] ?? ''));
} else {
    $details['whatsapp'] = $waDigits;
}

/* Language the drafts are written in by default for this business. */
$submittedLanguage = strtolower(trim((string)($_POST['language'] ?? '')));
$details['language'] = array_key_exists($submittedLanguage, rg_ai_languages()) ? $submittedLanguage : (string)($details['language'] ?? 'en');

if ($linkErrors) {
    $_SESSION['settings_errors'] = $linkErrors;
    $_SESSION['settings_old']    = $submittedLinks;
    header('Location: card_settings.php?slug=' . urlencode($card['slug']) . '#links');
    exit;
}
unset($_SESSION['settings_errors'], $_SESSION['settings_old']);
$details['theme']             = $_POST['theme'] ?? 'purple';
$details['enable_keyword_selection'] = !empty($_POST['enable_keyword_selection']);

$kw = array_filter(array_map('trim', explode(',', $_POST['business_keywords'] ?? '')));
$details['business_keywords'] = array_values($kw);

/* Logo upload — the file must really be an image, and the extension is taken from
   the detected type, never from the name the browser sent. */
if (!empty($_POST['remove_logo'])) {
    $details['logo'] = '';
} elseif (!empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
    $tmpPath = $_FILES['logo']['tmp_name'];
    $size    = (int)($_FILES['logo']['size'] ?? 0);

    /* getimagesize() reads the actual file header; a renamed .php or a text file
       with a .png extension fails here. */
    $imageInfo = @getimagesize($tmpPath);
    $allowedMime = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];
    $ext = ($imageInfo && isset($allowedMime[$imageInfo[2]])) ? $allowedMime[$imageInfo[2]] : '';

    if ($ext !== '' && $size > 0 && $size <= 2 * 1024 * 1024) {
        $dir = __DIR__ . '/assets/uploads';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        /* Random name: the uploaded filename never reaches the filesystem. */
        $fname = 'logo_' . $cardId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (move_uploaded_file($tmpPath, $dir . '/' . $fname)) {
            @chmod($dir . '/' . $fname, 0644);

            /* Remove the previous file so the folder does not grow forever. */
            if (!empty($card_logo_old) && is_string($card_logo_old)) {
                $oldPath = __DIR__ . '/' . ltrim($card_logo_old, '/');
                if (strpos(realpath($oldPath) ?: '', realpath($dir) ?: '\0') === 0 && is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }

            $details['logo'] = 'assets/uploads/' . $fname;
        }
    }
}

$stmt = $conn->prepare("UPDATE review_cards SET name = ?, details = ? WHERE id = ?");
$stmt->execute([$name, json_encode($details), $cardId]);

$_SESSION['settings_success'] = 'Settings saved successfully.';
header('Location: card_settings.php?slug=' . urlencode($card['slug']));
exit;