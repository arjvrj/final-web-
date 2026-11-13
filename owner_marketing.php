<?php
/**
 * owner_marketing.php — Owner panel: Marketing → Popup & Promotions
 * URL:   owner_marketing.php                     the campaign list
 *        owner_marketing.php?action=new          the editor, empty
 *        owner_marketing.php?action=edit&id=N    the editor, filled
 *        owner_marketing.php?action=preview&id=N the popup on its own page
 * POST:  action=save | toggle | delete
 *
 * Uploads go through the same storage as the rest of the app (assets/uploads),
 * with the same rules: the file must really be an image, the extension comes from
 * the detected type, and the name on disk is random.
 *
 * Owner only, and every POST is CSRF-checked through the form token.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/popups.php';

requireOwner();

$notice = '';
$errors = [];

/* =========================================================
   ACTIONS
   ========================================================= */
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

if ($action === 'save' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrf();

    $id = (int)($_POST['id'] ?? 0);
    $storedImage = '';
    $removeImage = !empty($_POST['remove_image']);
    $imageError = '';
    /* Read this BEFORE rg_popup_save() updates the row. Otherwise the "previous"
       image is the new one and the old file is left on the server for ever. */
    $previousImage = $id > 0 ? rg_popup_image_from_db($id) : '';

    if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
        $check = rg_popup_validate_image(
            (string)$_FILES['image']['tmp_name'],
            (int)($_FILES['image']['size'] ?? 0)
        );
        if (!$check['ok']) {
            $imageError = $check['error'];
        } else {
            $dir = __DIR__ . '/assets/uploads';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $fname = rg_popup_image_name($check['ext']);
            if (move_uploaded_file($_FILES['image']['tmp_name'], $dir . '/' . $fname)) {
                @chmod($dir . '/' . $fname, 0644);
                $storedImage = 'assets/uploads/' . $fname;
            } else {
                $imageError = 'The image could not be saved. Check the uploads folder permissions.';
            }
        }
    } elseif (!empty($_FILES['image']['name'])) {
        $code = (int)($_FILES['image']['error'] ?? 0);
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            $imageError = 'That image is larger than this server accepts. Use something under 4 MB.';
        } elseif ($code !== UPLOAD_ERR_NO_FILE) {
            $imageError = 'The image upload failed. Please try again.';
        }
    }

    /* If the owner uploaded a file and also ticked "remove", removal wins: the
       freshly uploaded file must not be left orphaned on disk. */
    if ($removeImage && $storedImage !== '') {
        rg_popup_delete_file($storedImage);
        $storedImage = '';
    }

    /* The form offers checkboxes; rg_popup_save() takes one comma list, so the
       choice is folded here. "All pages" wins if both were somehow sent. */
    if (!empty($_POST['target_all'])) {
        $_POST['targets'] = 'all';
    } else {
        $picked = array_map('strval', (array)($_POST['target_page'] ?? []));
        $_POST['targets'] = implode(',', $picked);
    }

    /* An invalid upload fails the whole save: nothing is half-written, and the
       campaign does not quietly become a draft without the artwork. */
    $result = $imageError !== ''
        ? ['ok' => false, 'id' => $id, 'errors' => ['image' => $imageError]]
        : rg_popup_save($_POST, $id, $storedImage, $removeImage);

    if (!$result['ok']) {
        $errors = $result['errors'];
        $action = $id > 0 ? 'edit' : 'new';
        $_GET['id'] = (string)$id;

        /* A save that failed after the upload must not leave an orphan file. */
        if ($storedImage !== '' && $storedImage !== $previousImage) {
            rg_popup_delete_file($storedImage);
            $storedImage = '';
        }
    } else {
        /* A replaced or removed image should not leave the old file behind. */
        if ($previousImage !== '' && $previousImage !== $storedImage) {
            rg_popup_delete_file($previousImage);
        }
        $published = ($_POST['status'] ?? '') === 'active';
        $_SESSION['marketing_notice'] = $published
            ? 'Published. The campaign follows its schedule and page targeting.'
            : ($id > 0 ? 'Campaign saved.' : 'Campaign saved as a draft. Publish it when you are ready.');
        header('Location: owner_marketing.php?action=edit&id=' . (int)$result['id'] . '&saved=1');
        exit;
    }
}

if ($action === 'toggle' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrf();
    $id = (int)($_POST['id'] ?? 0);
    $to = (string)($_POST['to'] ?? '');
    if ($id > 0 && rg_popup_set_status($id, $to)) {
        $_SESSION['marketing_notice'] = $to === 'active'
            ? 'Campaign published. It follows its schedule and page targeting.'
            : 'Campaign disabled. Visitors will not see it.';
    } else {
        $_SESSION['marketing_notice'] = 'That change could not be saved.';
    }
    header('Location: owner_marketing.php');
    exit;
}

if ($action === 'delete' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrf();
    $id = (int)($_POST['id'] ?? 0);
    $_SESSION['marketing_notice'] = ($id > 0 && rg_popup_delete($id))
        ? 'Campaign deleted, and its image removed from the server.'
        : 'That campaign could not be deleted.';
    header('Location: owner_marketing.php');
    exit;
}

if (!empty($_SESSION['marketing_notice'])) {
    $notice = (string)$_SESSION['marketing_notice'];
    unset($_SESSION['marketing_notice']);
}

$page = 'marketing';
$csrffield = csrfField();

/* =========================================================
   PREVIEW — the popup on its own page, owner only
   ========================================================= */
if ($action === 'preview') {
    $popup = rg_popup_find((int)($_GET['id'] ?? 0));
    if (!$popup) {
        header('Location: owner_marketing.php');
        exit;
    }

    $appName = getSetting('app_name', 'ReviewAI');
    $targets = (string)($popup['targets'] ?? '');
    $pages = rg_popup_page_registry();
    $pageLabel = [];
    foreach (explode(',', $targets) as $key) {
        if ($key === 'all') { $pageLabel[] = 'all public marketing pages'; continue; }
        if (isset($pages[$key])) $pageLabel[] = $pages[$key]['label'];
    }
    $statusLabel = rg_popup_status_label(rg_popup_status($popup));

    /* ---------- the embedded viewport ----------------------------------
       The parent page puts this inside an iframe. That is the only honest way
       to preview a responsive dialog: the CSS media queries see the iframe's
       width, so "Mobile" really is a 390 px viewport instead of a small card
       drawn in the middle of a desktop window. */
    if (!empty($_GET['embed'])) {
        header('X-Robots-Tag: noindex, nofollow');
        ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Popup preview</title>
<link rel="stylesheet" href="<?= e(asset('css/fonts.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/popup.css')) ?>">
<style>
  html, body { margin: 0; min-height: 100%; background: transparent; }
  body { font-family: "Inter", system-ui, -apple-system, sans-serif; }
</style>
</head>
<body>
<?php rg_popup_render($popup, 'preview', false); ?>
<script src="<?= e(asset('js/popup.js')) ?>"></script>
<script>
  /* A preview shows the dialog immediately and never remembers or counts it. */
  document.addEventListener('DOMContentLoaded', function () {
    var root = document.getElementById('rgPopup');
    if (!root) return;
    root.hidden = false;
    document.body.classList.add('rg-pop-open');
  });
</script>
</body>
</html><?php
        exit;
    }

    /* ---------- the preview shell -------------------------------------- */
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex,nofollow">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Preview · <?= e($popup['name']) ?> · <?= e($appName) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/fonts.css')) ?>">
<style>
  :root {
    --pv-cream: #FBF8F2; --pv-purple: #4B20DC; --pv-navy: #141623;
    --pv-lime: #E7F3B7; --pv-lav: #F1EBFE; --pv-white: #FFFDFB;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; min-height: 100vh; color: var(--pv-navy);
    font-family: "Inter", system-ui, -apple-system, sans-serif;
    background:
      radial-gradient(70% 50% at 12% 0%, #F1EBFE 0%, rgba(241, 235, 254, 0) 62%),
      radial-gradient(60% 50% at 88% 8%, #EFF6D8 0%, rgba(239, 246, 216, 0) 58%),
      var(--pv-cream);
  }
  .pv-bar {
    position: sticky; top: 0; z-index: 20;
    display: flex; flex-wrap: wrap; align-items: center; gap: 10px;
    padding: 12px 16px; background: rgba(255, 253, 251, .94);
    border-bottom: 1px solid #E7DEFB; backdrop-filter: blur(10px);
  }
  .pv-title { margin-right: auto; min-width: 200px; }
  .pv-title strong { display: block; font-size: 14px; letter-spacing: -.01em; }
  .pv-title span { font-size: 11.5px; color: #6B6683; }
  .pv-seg { display: inline-flex; padding: 3px; border-radius: 999px; background: #F1EBFE; }
  .pv-seg button {
    border: 0; background: transparent; color: #4B20DC; cursor: pointer;
    font: 700 12px/1 Inter, system-ui, sans-serif; padding: 9px 14px; border-radius: 999px;
  }
  .pv-seg button[aria-pressed="true"] { background: #4B20DC; color: #fff; }
  .pv-btn {
    display: inline-flex; align-items: center; gap: 6px;
    min-height: 38px; padding: 0 14px; border-radius: 999px;
    border: 1px solid #DED3F8; background: #fff; color: #141623;
    font: 700 12px/1 Inter, system-ui, sans-serif; text-decoration: none; cursor: pointer;
  }
  .pv-btn:hover { background: #FAF7FF; }
  .pv-stage { padding: 26px 16px 40px; display: grid; place-items: start center; }
  .pv-frame {
    position: relative; width: min(1120px, 100%); height: min(720px, calc(100vh - 150px));
    background: var(--pv-white); border: 1px solid #E7DEFB; border-radius: 22px;
    box-shadow: 0 40px 90px -50px rgba(20, 22, 35, .55); overflow: hidden;
    transition: width .25s ease, height .25s ease, border-radius .25s ease;
  }
  .pv-frame.is-mobile {
    width: 390px; height: min(780px, calc(100vh - 150px)); border-radius: 34px;
    border: 9px solid #141623; box-shadow: 0 50px 100px -40px rgba(20, 22, 35, .6);
  }
  .pv-frame iframe { display: block; width: 100%; height: 100%; border: 0; background: transparent; }
  .pv-fake { position: absolute; inset: 0; z-index: 0; display: flex; flex-direction: column; }
  .pv-nav { display: flex; align-items: center; gap: 18px; padding: 18px 26px; border-bottom: 1px solid #F0E9FA; font-size: 12px; }
  .pv-logo { font-weight: 800; font-size: 15px; color: var(--pv-navy); }
  .pv-logo i { color: var(--pv-purple); font-style: normal; }
  .pv-nav b { margin-left: auto; padding: 8px 14px; border-radius: 999px; background: var(--pv-purple); color: #fff; font-size: 11px; }
  .pv-hero { padding: 48px 34px 20px; max-width: 640px; }
  .pv-kicker { display: inline-block; padding: 5px 11px; border-radius: 999px; background: var(--pv-lime); font-size: 10px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
  .pv-hero h1 { margin: 14px 0 10px; font-size: clamp(26px, 4.4vw, 46px); line-height: 1.06; letter-spacing: -.04em; }
  .pv-hero h1 em { color: var(--pv-purple); font-style: normal; }
  .pv-hero p { margin: 0; max-width: 46ch; font-size: 13px; line-height: 1.6; color: #5F6175; }
  .pv-phone-note { position: absolute; right: 20px; bottom: 18px; font-size: 10.5px; color: #8A85A0; }
  @media (max-width: 640px) {
    .pv-title { flex: 1 1 100%; }
    .pv-stage { padding: 16px 10px 26px; }
    .pv-frame { height: calc(100vh - 220px); border-radius: 18px; }
    .pv-frame.is-mobile { width: min(390px, 100%); border-width: 6px; border-radius: 28px; }
    .pv-hero { padding: 26px 20px 16px; }
  }
</style>
</head>
<body>
<div class="pv-bar">
  <div class="pv-title">
    <strong><?= e($popup['name']) ?></strong>
    <span><?= e($statusLabel) ?> · <?= e(implode(', ', $pageLabel) ?: 'no page selected') ?></span>
  </div>
  <div class="pv-seg" role="group" aria-label="Preview size">
    <button type="button" data-device="desktop" aria-pressed="true">Desktop</button>
    <button type="button" data-device="mobile" aria-pressed="false">Mobile</button>
  </div>
  <button type="button" class="pv-btn" id="pvReopen">Reopen popup</button>
  <a class="pv-btn" href="owner_marketing.php?action=edit&amp;id=<?= (int)$popup['id'] ?>">Edit campaign</a>
  <a class="pv-btn" href="owner_marketing.php">Back to list</a>
</div>

<div class="pv-stage">
  <div class="pv-frame" id="pvFrame">
    <div class="pv-fake" aria-hidden="true">
      <div class="pv-nav">
        <span class="pv-logo"><i>✧</i> <?= e($appName) ?></span>
        <span>Product</span><span>How it works</span><span>Pricing</span>
        <b>Get started →</b>
      </div>
      <div class="pv-hero">
        <span class="pv-kicker">For Indian businesses</span>
        <h1>Turn every customer experience into a <em>better review.</em></h1>
        <p>One QR code. Simple customer feedback. Make sharing honest experiences easier.</p>
      </div>
      <span class="pv-phone-note">Background is a mock marketing page</span>
    </div>
    <iframe id="pvFrameBody"
            title="Popup preview"
            src="owner_marketing.php?action=preview&amp;embed=1&amp;id=<?= (int)$popup['id'] ?>"></iframe>
  </div>
</div>

<script>
  (function () {
    var frame = document.getElementById('pvFrame');
    var body  = document.getElementById('pvFrameBody');
    var base  = body.getAttribute('src');

    document.querySelectorAll('[data-device]').forEach(function (button) {
      button.addEventListener('click', function () {
        document.querySelectorAll('[data-device]').forEach(function (other) {
          other.setAttribute('aria-pressed', String(other === button));
        });
        frame.classList.toggle('is-mobile', button.dataset.device === 'mobile');
      });
    });

    document.getElementById('pvReopen').addEventListener('click', function () {
      /* Reloading the embedded document restores the popup after a close. */
      body.src = base + '&r=' + Date.now();
    });
  })();
</script>
</body>
</html><?php
    exit;
}

/* =========================================================
   RENDER
   ========================================================= */
$rows  = rg_popup_all();
$stats = rg_popup_stats($rows);

$editing = null;
$editingId = (int)($_GET['id'] ?? 0);
if ($action === 'edit' && $editingId > 0) {
    $editing = rg_popup_find($editingId);
}
$showEditor = $action === 'new' || $editing !== null;

/* Values for the editor: the posted attempt if there was one, otherwise the row. */
$v = [
    'id' => 0, 'name' => '', 'status' => 'draft', 'layout' => 'image_text', 'image' => '',
    'heading' => '', 'description' => '', 'coupon_code' => '', 'cta_enabled' => 1,
    'cta_text' => 'Claim offer', 'cta_url' => '', 'cta_new_tab' => 1,
    'trigger_type' => 'delay', 'trigger_value' => 3, 'frequency' => 'session',
    'targets' => 'home', 'starts_at' => '', 'ends_at' => '',
    'timezone' => rg_popup_default_timezone(), 'start_time' => '', 'end_time' => '',
    'impressions' => 0, 'clicks' => 0, 'coupon_copies' => 0, 'dismissals' => 0,
];
if ($editing) {
    $v = array_merge($v, array_map(static function ($value) {
        return $value === null ? '' : $value;
    }, $editing));
}
if ($errors) {
    /* Keep what was typed so nothing has to be re-entered. The row still fills
       the analytics and the stored image, but every editable field comes from
       the rejected POST. */
    foreach (['name','status','layout','heading','description','coupon_code','cta_text','cta_url','trigger_type','trigger_value','frequency','targets','timezone','start_time','end_time'] as $key) {
        if (isset($_POST[$key])) $v[$key] = (string)$_POST[$key];
    }
    $v['id'] = (int)($_POST['id'] ?? ($v['id'] ?? 0));
    $v['cta_enabled'] = !empty($_POST['cta_enabled']) ? 1 : 0;
    $v['cta_new_tab'] = !empty($_POST['cta_new_tab']) ? 1 : 0;
    $v['starts_at'] = (string)($_POST['starts_at'] ?? '');
    $v['ends_at']   = (string)($_POST['ends_at'] ?? '');
}

/* The date inputs are in the campaign's timezone. Old rows (and freshly typed
   ones) only had a date, so the time falls back to 00:00 / 23:59. */
$vTz = rg_popup_timezone((string)($v['timezone'] ?? ''));
$v['start_date_input'] = $v['starts_at'] !== '' ? rg_popup_date_input($v['starts_at'], $vTz) : '';
$v['end_date_input']   = $v['ends_at'] !== '' ? rg_popup_date_input($v['ends_at'], $vTz) : '';
$v['start_time_input'] = $v['start_date_input'] !== ''
    ? ((string)($v['start_time'] ?? '') !== '' ? (string)$v['start_time'] : rg_popup_time_input($v['starts_at'], $vTz, '00:00'))
    : '00:00';
$v['end_time_input']   = $v['end_date_input'] !== ''
    ? ((string)($v['end_time'] ?? '') !== '' ? (string)$v['end_time'] : rg_popup_time_input($v['ends_at'], $vTz, '23:59'))
    : '23:59';

$imageUrl = rg_popup_image_url((string)$v['image']);
$targets  = array_filter(explode(',', (string)$v['targets']));
$previewUrl = $v['id'] > 0 ? 'owner_marketing.php?action=preview&id=' . (int)$v['id'] : '';

ob_start();
?>

<h1>Popup &amp; promotions</h1>
<p>Announce a sale, a new feature or a coupon on your marketing pages. Create it here, upload your artwork, publish — no code and no redeploy.</p>

<?php if ($notice !== ''): ?>
<div class="alert" role="status"><?= e($notice) ?></div>
<?php endif; ?>

<?php if (!rg_popup_schema_ready()): ?>
<div class="alert" role="alert" style="background:#fff5e5;border-color:#f0c987;color:#6b4e14">
    <strong>The popup database needs its upgrade.</strong>
    Run <code>sql/migrations/2026-11-popup-views.sql</code> once in phpMyAdmin, then reload this page.
    Existing campaigns keep working after that; until then saving a campaign will fail.
</div>
<?php endif; ?>

<style>
  /* The marketing screens use the palette the popups themselves use. Scoped to
     this page so the rest of the admin panel keeps its own green shell. */
  .mk-stats { display: grid; gap: 10px; margin: 1rem 0; grid-template-columns: repeat(auto-fit, minmax(132px, 1fr)); }
  .mk-stat { margin: 0; padding: 14px 15px; border: 1px solid #E7DEFB; border-radius: 16px;
             background: linear-gradient(160deg, #FFFDFB 0%, #FBF8F2 100%); }
  .mk-stat strong { display: block; font-size: 22px; line-height: 1.1; letter-spacing: -.03em; color: #141623; }
  .mk-stat span { display: block; margin-top: 4px; font-size: 11.5px; font-weight: 600; color: #6B6683; }
  .mk-stat.is-brand { background: linear-gradient(150deg, #4B20DC 0%, #7B4BF5 100%); border-color: #4B20DC; }
  .mk-stat.is-brand strong, .mk-stat.is-brand span { color: #fff; }
  .mk-schedule { display: block; font-size: 11.5px; color: #5F6175; line-height: 1.45; }
  .mk-perf { display: grid; gap: 2px; font-size: 11.5px; color: #5F6175; min-width: 116px; }
  .mk-perf b { color: #141623; font-size: 13px; }
  .mk-chip { display: inline-block; padding: 2px 7px; border-radius: 999px; font-size: 10px; font-weight: 800; letter-spacing: .02em; }
  .mk-chip.is-live { background: #E7F3B7; color: #3E5210; }
  .mk-field-error { margin: .35rem 0 0; font-size: .82rem; font-weight: 600; color: #8a2020; }
  .mk-invalid { border-color: #c46a6a !important; outline-color: #c46a6a !important; }
  .mk-analytics { display: grid; gap: 8px; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); margin-top: .8rem; }
  .mk-analytics div { padding: 10px 12px; border-radius: 12px; background: #F8F5FF; }
  .mk-analytics strong { display: block; font-size: 18px; letter-spacing: -.02em; }
  .mk-analytics span { font-size: 10.5px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: #7A6BA8; }
</style>

<div class="mk-stats">
    <div class="mk-stat"><strong><?= (int)$stats['total'] ?></strong><span>Total campaigns</span></div>
    <div class="mk-stat"><strong><?= (int)$stats['active'] ?></strong><span>Active now</span></div>
    <div class="mk-stat"><strong><?= (int)$stats['scheduled'] ?></strong><span>Scheduled</span></div>
    <div class="mk-stat"><strong><?= (int)$stats['expired'] ?></strong><span>Expired</span></div>
    <div class="mk-stat"><strong><?= (int)$stats['draft'] + (int)$stats['paused'] ?></strong><span>Draft / disabled</span></div>
    <div class="mk-stat is-brand"><strong><?= (int)$stats['impressions'] ?></strong><span>Impressions</span></div>
    <div class="mk-stat"><strong><?= (int)$stats['clicks'] ?></strong><span>CTA clicks</span></div>
    <div class="mk-stat"><strong><?= (int)$stats['coupon_copies'] ?></strong><span>Coupon copies</span></div>
    <div class="mk-stat"><strong><?= (int)$stats['dismissals'] ?></strong><span>Dismissals</span></div>
    <div class="mk-stat"><strong><?= number_format((float)$stats['ctr'], 1) ?>%</strong><span>Click-through</span></div>
</div>

<p>
    <a class="btn" href="owner_marketing.php?action=new">+ Create New Popup</a>
</p>

<?php if (!$rows): ?>
<div class="card">
    <h2>No campaigns yet</h2>
    <p class="muted">Create your first popup: upload an image, add a heading and a coupon, choose when it should appear, then publish it.</p>
</div>
<?php else: ?>
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Campaign</th><th>Status</th><th>Schedule</th><th>Performance</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row):
            $state = rg_popup_status($row);
            $thumb = rg_popup_image_url((string)($row['image'] ?? ''));
        ?>
            <tr>
                <td>
                    <div class="stack" style="gap:.6rem;align-items:center">
                        <?php if ($thumb !== ''): ?>
                        <img src="<?= e($thumb) ?>" alt="" width="44" height="44"
                             style="width:44px;height:44px;object-fit:contain;background:#FBF8F2;border-radius:10px;border:1px solid #dfe4e0">
                        <?php else: ?>
                        <span class="tag" style="width:44px;height:44px;display:grid;place-items:center;border-radius:10px">—</span>
                        <?php endif; ?>
                        <strong><?= e($row['name']) ?></strong>
                    </div>
                </td>
                <td>
                    <span class="tag" style="background:<?=
                        $state === 'active' ? '#e6f4e9' : ($state === 'scheduled' ? '#fdf3dc' : ($state === 'expired' ? '#f1f2f4' : '#eef1f7'))
                    ?>;color:<?=
                        $state === 'active' ? '#1f513b' : ($state === 'scheduled' ? '#6b4e14' : '#4a4f57')
                    ?>"><?= e(rg_popup_status_label($state)) ?></span>
                </td>
                <td>
                    <span class="mk-schedule">
                        <strong>From:</strong> <?= e($row['starts_at'] ? rg_popup_format_datetime($row['starts_at'], (string)($row['timezone'] ?? '')) : 'Immediately') ?><br>
                        <strong>To:</strong> <?= e($row['ends_at'] ? rg_popup_format_datetime($row['ends_at'], (string)($row['timezone'] ?? '')) : 'No end date') ?>
                    </span>
                </td>
                <td>
                    <div class="mk-perf">
                        <span><b><?= (int)$row['impressions'] ?></b> impressions · <b><?= (int)$row['clicks'] ?></b> clicks</span>
                        <span>CTR <b><?= number_format(rg_popup_ctr($row), 1) ?>%</b> · <?= (int)($row['coupon_copies'] ?? 0) ?> copies · <?= (int)($row['dismissals'] ?? 0) ?> closed</span>
                    </div>
                </td>
                <td>
                    <div class="stack" style="gap:.35rem;flex-wrap:wrap">
                        <a class="btn" href="owner_marketing.php?action=edit&amp;id=<?= (int)$row['id'] ?>">Edit</a>
                        <a class="btn" href="owner_marketing.php?action=preview&amp;id=<?= (int)$row['id'] ?>"
                           target="_blank" rel="noopener">Preview</a>

                        <form method="post" action="owner_marketing.php" style="display:inline">
                            <?= $csrffield ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                            <?php if (($row['status'] ?? '') === 'active'): ?>
                            <input type="hidden" name="to" value="paused">
                            <button class="btn" type="submit">Disable</button>
                            <?php else: ?>
                            <input type="hidden" name="to" value="active">
                            <button class="btn" type="submit"><?= ($row['status'] ?? '') === 'paused' ? 'Re-enable' : 'Publish' ?></button>
                            <?php endif; ?>
                        </form>

                        <form method="post" action="owner_marketing.php" style="display:inline"
                              onsubmit="return confirm('Delete this campaign and its image? This cannot be undone.');">
                            <?= $csrffield ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                            <button class="btn" type="submit" style="border-color:#b23b3b;color:#b23b3b">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if ($showEditor): ?>
<form id="popupForm" method="post" action="owner_marketing.php" enctype="multipart/form-data" style="margin-top:1.5rem">
    <?= $csrffield ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">

    <div class="card">
        <div class="stack" style="justify-content:space-between;align-items:flex-start">
            <h2 style="margin:0"><?= $v['id'] > 0 ? 'Edit campaign' : 'Create popup' ?></h2>
            <?php if ($previewUrl !== ''): ?>
            <a class="btn" href="<?= e($previewUrl) ?>" target="_blank" rel="noopener">Preview ↗</a>
            <?php endif; ?>
        </div>

        <?php if ($errors): ?>
        <div class="alert" role="alert" style="background:#fdecec;color:#8a2020;margin-top:1rem">
            <?= e(implode(' ', $errors)) ?>
        </div>
        <?php endif; ?>

        <div class="field" style="margin-top:1rem">
            <label for="name">Campaign name *</label>
            <input id="name" name="name" type="text" required maxlength="120" class="<?= isset($errors['name']) ? 'mk-invalid' : '' ?>"
                   value="<?= e($v['name']) ?>" placeholder="Diwali 2026 Special Offer">
            <?php if (isset($errors['name'])): ?><p class="mk-field-error"><?= e($errors['name']) ?></p><?php endif; ?>
            <p class="muted" style="margin:.4rem 0 0;font-size:.85rem">Only shown in this panel.</p>
        </div>

        <div class="field">
            <label for="image">Popup image *</label>
            <input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp">
            <p class="muted" style="margin:.4rem 0 0;font-size:.85rem">
                JPG, PNG or WebP, up to 4 MB, at least 320 px on each side. 4:5 or 1:1 works best.
                The artwork is shown whole — never cropped or stretched.
            </p>

            <?php if ($imageUrl !== ''): ?>
            <div style="margin-top:.8rem">
                <img id="imagePreview" src="<?= e($imageUrl) ?>" alt="Current popup image"
                     style="width:100%;max-width:260px;border-radius:14px;border:1px solid #dfe4e0;display:block">
                <label class="stack" style="gap:.45rem;margin-top:.6rem;font-size:.9rem;align-items:center">
                    <input type="checkbox" name="remove_image" value="1" style="width:auto">
                    Remove this image
                </label>
            </div>
            <?php else: ?>
            <img id="imagePreview" alt="" hidden style="width:100%;max-width:260px;border-radius:14px;border:1px solid #dfe4e0;margin-top:.8rem">
            <?php endif; ?>
            <?php if (isset($errors['image'])): ?><p class="mk-field-error"><?= e($errors['image']) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label for="layout">Popup layout</label>
            <select id="layout" name="layout">
                <?php foreach (rg_popup_layouts() as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $v['layout'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="heading">Popup heading</label>
            <input id="heading" name="heading" type="text" maxlength="160" value="<?= e($v['heading']) ?>"
                   placeholder="Diwali Sale — 30% off all plans">
        </div>

        <div class="field">
            <label for="description">Popup description</label>
            <textarea id="description" name="description" rows="2" maxlength="400"
                      placeholder="Use code REVIEW30 at checkout. Offer ends 12 November."><?= e($v['description']) ?></textarea>
        </div>

        <div class="field">
            <label for="coupon_code">Coupon code (optional)</label>
            <input id="coupon_code" name="coupon_code" type="text" maxlength="40" value="<?= e($v['coupon_code']) ?>"
                   placeholder="REVIEW30" style="text-transform:uppercase">
            <p class="muted" style="margin:.4rem 0 0;font-size:.85rem">
                Shown with a Copy button. Letters, numbers, hyphen and underscore only.
            </p>
        </div>

        <div class="field">
            <label class="stack" style="gap:.5rem;align-items:center;font-size:.95rem">
                <input type="checkbox" name="cta_enabled" value="1" style="width:auto"
                       <?= (int)$v['cta_enabled'] === 1 ? 'checked' : '' ?>>
                Show a call-to-action button
            </label>
        </div>

        <div class="field">
            <label for="cta_text">Button text</label>
            <input id="cta_text" name="cta_text" type="text" maxlength="60" value="<?= e($v['cta_text']) ?>"
                   placeholder="Claim offer">
        </div>

        <div class="field">
            <label for="cta_url">Button destination</label>
            <input id="cta_url" name="cta_url" type="text" inputmode="url" maxlength="500" class="<?= isset($errors['cta_url']) ? 'mk-invalid' : '' ?>"
                   value="<?= e($v['cta_url']) ?>" placeholder="https://yoursite.com/pricing">
            <?php if (isset($errors['cta_url'])): ?><p class="mk-field-error"><?= e($errors['cta_url']) ?></p><?php endif; ?>
            <p class="muted" style="margin:.4rem 0 0;font-size:.85rem">
                A full web address, <code>signup.php</code> for a page on this site, or <code>#pricing</code> for a section.
            </p>
        </div>

        <div class="field">
            <label class="stack" style="gap:.5rem;align-items:center;font-size:.95rem">
                <input type="checkbox" name="cta_new_tab" value="1" style="width:auto"
                       <?= (int)$v['cta_new_tab'] === 1 ? 'checked' : '' ?>>
                Open the button in a new tab
            </label>
        </div>
    </div>

    <div class="card">
        <h2>When and where</h2>

        <div class="field" style="margin-top:1rem">
            <label for="trigger_type">Trigger</label>
            <select id="trigger_type" name="trigger_type">
                <?php foreach (rg_popup_triggers() as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $v['trigger_type'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="trigger_value">Delay (seconds) or scroll depth (percent)</label>
            <input id="trigger_value" name="trigger_value" type="number" min="1" max="60"
                   value="<?= (int)$v['trigger_value'] ?>">
            <p class="muted" style="margin:.4rem 0 0;font-size:.85rem">
                Delay uses seconds (3–5 is usual); scroll uses a percentage of the page (30–60).
                Exit intent ignores this and is desktop only.
            </p>
        </div>

        <div class="field">
            <label for="frequency">How often a visitor sees it</label>
            <select id="frequency" name="frequency">
                <?php foreach (rg_popup_frequencies() as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $v['frequency'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label>Pages</label>
            <?php foreach (rg_popup_page_registry() as $key => $meta): ?>
            <label class="stack" style="gap:.5rem;align-items:center;font-size:.95rem;font-weight:400">
                <input type="checkbox" name="target_page[]" value="<?= e($key) ?>" style="width:auto"
                       <?= in_array($key, $targets, true) ? 'checked' : '' ?>>
                <?= e($meta['label']) ?>
            </label>
            <?php endforeach; ?>
            <label class="stack" style="gap:.5rem;align-items:center;font-size:.95rem;font-weight:400">
                <input type="checkbox" name="target_all" value="all" style="width:auto"
                       <?= in_array('all', $targets, true) ? 'checked' : '' ?>>
                All public marketing pages
            </label>
            <p class="muted" style="margin:.5rem 0 0;font-size:.85rem">
                Promotions never appear in a business dashboard, the owner panel, or the customer
                QR and review flow — those pages do not load the popup at all.
            </p>
        </div>

        <div class="field">
            <label>Campaign schedule</label>
            <div class="stack" style="gap:.8rem;flex-wrap:wrap;align-items:flex-start">
                <div style="flex:1 1 170px;min-width:150px">
                    <label for="starts_at" style="font-size:.85rem;font-weight:600">Start date</label>
                    <input id="starts_at" name="starts_at" type="date" class="<?= isset($errors['starts_at']) ? 'mk-invalid' : '' ?>"
                           value="<?= e($v['start_date_input']) ?>">
                    <label for="start_time" style="font-size:.85rem;font-weight:600;display:block;margin-top:.45rem">Start time</label>
                    <input id="start_time" name="start_time" type="time" value="<?= e($v['start_time_input']) ?>">
                </div>
                <div style="flex:1 1 170px;min-width:150px">
                    <label for="ends_at" style="font-size:.85rem;font-weight:600">End date</label>
                    <input id="ends_at" name="ends_at" type="date" class="<?= isset($errors['ends_at']) ? 'mk-invalid' : '' ?>"
                           value="<?= e($v['end_date_input']) ?>">
                    <label for="end_time" style="font-size:.85rem;font-weight:600;display:block;margin-top:.45rem">End time</label>
                    <input id="end_time" name="end_time" type="time" value="<?= e($v['end_time_input']) ?>">
                </div>
            </div>
            <?php if (isset($errors['starts_at'])): ?><p class="mk-field-error"><?= e($errors['starts_at']) ?></p><?php endif; ?>
            <?php if (isset($errors['ends_at'])): ?><p class="mk-field-error"><?= e($errors['ends_at']) ?></p><?php endif; ?>
            <p class="muted" style="margin:.5rem 0 0;font-size:.85rem">
                The campaign starts and stops by itself at these times. Leave both dates empty to run until you disable it.
            </p>
        </div>

        <div class="field">
            <label for="timezone">Campaign timezone</label>
            <select id="timezone" name="timezone">
                <?php foreach (rg_popup_timezone_options() as $tzKey => $tzLabel): ?>
                <option value="<?= e($tzKey) ?>" <?= $vTz === $tzKey ? 'selected' : '' ?>><?= e($tzLabel) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="muted" style="margin:.4rem 0 0;font-size:.85rem">
                The start and end times above are read in this timezone, no matter what timezone the server runs in.
            </p>
        </div>

        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="draft"  <?= $v['status'] === 'draft'  ? 'selected' : '' ?>>Draft — not shown</option>
                <option value="active" <?= $v['status'] === 'active' ? 'selected' : '' ?>>Published — follows the schedule</option>
                <option value="paused" <?= $v['status'] === 'paused' ? 'selected' : '' ?>>Disabled — kept, not shown</option>
            </select>
            <p class="muted" style="margin:.4rem 0 0;font-size:.85rem">
                A published campaign with a future start date shows as Scheduled and goes live by itself.
            </p>
        </div>

        <div class="stack" style="gap:.6rem;margin-top:1rem;flex-wrap:wrap">
            <button class="btn" type="submit" id="saveBtn"><?= $v['id'] > 0 ? 'Save changes' : 'Create popup' ?></button>
            <button class="btn" type="submit" onclick="return submitAs('active')">Publish</button>
            <button class="btn" type="button" onclick="openLivePreview()">Preview</button>
            <?php if ($v['id'] > 0): ?>
            <?php if ($previewUrl !== ''): ?>
            <a class="btn" href="<?= e($previewUrl) ?>" target="_blank" rel="noopener">Open full preview ↗</a>
            <?php endif; ?>
            <?php endif; ?>
            <a class="btn" href="owner_marketing.php">Back to list</a>
        </div>

        <?php if ($v['id'] > 0): ?>
        <div style="margin-top:1.4rem;padding-top:1rem;border-top:1px solid #e6e9e3">
            <h3 style="margin:0 0 .2rem;font-size:1rem">Campaign analytics</h3>
            <p class="muted" style="margin:0;font-size:.85rem">Counted without storing personal information.</p>
            <div class="mk-analytics">
                <div><span>Impressions</span><strong><?= (int)$v['impressions'] ?></strong></div>
                <div><span>CTA clicks</span><strong><?= (int)$v['clicks'] ?></strong></div>
                <div><span>CTR</span><strong><?= number_format(rg_popup_ctr($v), 1) ?>%</strong></div>
                <div><span>Coupon copies</span><strong><?= (int)($v['coupon_copies'] ?? 0) ?></strong></div>
                <div><span>Dismissals</span><strong><?= (int)($v['dismissals'] ?? 0) ?></strong></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</form>

<div class="mk-modal" id="livePreview" hidden>
  <div class="mk-modal-shell" role="dialog" aria-modal="true" aria-labelledby="mkPreviewTitle">
    <div class="mk-modal-bar">
      <div>
        <strong id="mkPreviewTitle">Popup preview</strong>
        <span>Exactly what a visitor sees — nothing is saved or counted.</span>
      </div>
      <div class="mk-seg" role="group" aria-label="Preview size">
        <button type="button" data-preview-device="desktop" aria-pressed="true">Desktop</button>
        <button type="button" data-preview-device="mobile" aria-pressed="false">Mobile</button>
      </div>
      <button type="button" class="mk-modal-close" id="mkPreviewClose" aria-label="Close preview">×</button>
    </div>
    <div class="mk-modal-stage">
      <div class="mk-device" id="mkDevice">
        <div class="mk-device-page" aria-hidden="true">
          <div class="mk-device-nav"><b>✧ ReviewAI</b><span>Product</span><span>Pricing</span><i>Get started →</i></div>
          <div class="mk-device-hero">
            <small>FOR INDIAN BUSINESSES</small>
            <h3>Turn every customer experience into a <em>better review.</em></h3>
            <p>One QR code. Simple customer feedback.</p>
          </div>
        </div>

        <div class="mk-device-popup" id="mkDevicePopup">
          <button type="button" class="mk-device-close" aria-label="Close this offer">×</button>
          <div class="mk-device-media" id="mkDeviceMedia">
            <img id="mkDeviceImage" alt="">
            <div class="mk-device-fallback" id="mkDeviceFallback" hidden>
              <span class="mk-device-mark">✳</span>
              <span class="mk-device-brand">ReviewAI</span>
            </div>
          </div>
          <div class="mk-device-body">
            <h4 id="mkDeviceHeading"></h4>
            <p id="mkDeviceText"></p>
            <div class="mk-device-coupon" id="mkDeviceCoupon" hidden>
              <span>Coupon code</span>
              <div><code id="mkDeviceCode"></code><button type="button" id="mkDeviceCopy">Copy code</button></div>
            </div>
            <a class="mk-device-cta" id="mkDeviceCta" href="#" target="_blank" rel="noopener noreferrer" hidden></a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<style>
  .mk-modal { position: fixed; inset: 0; z-index: 2147483000; display: grid; place-items: center;
              padding: 14px; background: rgba(20, 22, 35, .62); backdrop-filter: blur(3px); }
  .mk-modal[hidden] { display: none; }
  .mk-modal-shell { width: min(1080px, 100%); max-height: calc(100vh - 28px); overflow: hidden;
                    display: flex; flex-direction: column; border-radius: 20px; background: #FFFDFB;
                    border: 1px solid #E7DEFB; box-shadow: 0 40px 100px -30px rgba(20,22,35,.7); }
  .mk-modal-bar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding: 12px 16px;
                  border-bottom: 1px solid #EFE8FA; background: #fff; }
  .mk-modal-bar > div:first-child { margin-right: auto; }
  .mk-modal-bar strong { display: block; font-size: 14px; color: #141623; }
  .mk-modal-bar span { font-size: 11.5px; color: #6B6683; }
  .mk-seg { display: inline-flex; padding: 3px; border-radius: 999px; background: #F1EBFE; }
  .mk-seg button { border: 0; background: transparent; color: #4B20DC; cursor: pointer; padding: 8px 12px;
                   border-radius: 999px; font: 700 11.5px/1 Inter, system-ui, sans-serif; }
  .mk-seg button[aria-pressed="true"] { background: #4B20DC; color: #fff; }
  .mk-modal-close { width: 36px; height: 36px; border-radius: 50%; border: 0; background: #F1EBFE;
                    color: #141623; font-size: 21px; line-height: 1; cursor: pointer; }
  .mk-modal-stage { padding: 18px; overflow: auto; display: grid; place-items: start center;
                    background: radial-gradient(70% 60% at 20% 0%, #F1EBFE, transparent 60%), #FBF8F2; }
  .mk-device { position: relative; width: min(760px, 100%); height: min(560px, calc(100vh - 190px));
               border-radius: 18px; overflow: hidden; background: #FFFDFB; border: 1px solid #E7DEFB;
               box-shadow: 0 30px 70px -40px rgba(20,22,35,.6); transition: width .25s ease, height .25s ease; }
  .mk-device.is-mobile { width: 390px; max-width: 100%; height: min(720px, calc(100vh - 190px)); border-radius: 30px;
                         border: 8px solid #141623; }
  .mk-device-page { position: absolute; inset: 0; }
  .mk-device-nav { display: flex; align-items: center; gap: 14px; padding: 15px 20px;
                   border-bottom: 1px solid #F0E9FA; font-size: 11px; color: #5F6175; }
  .mk-device-nav b { color: #141623; font-size: 13px; margin-right: 4px; }
  .mk-device-nav i { margin-left: auto; font-style: normal; background: #4B20DC; color: #fff;
                     padding: 7px 11px; border-radius: 999px; font-size: 10px; font-weight: 700; }
  .mk-device-hero { padding: 34px 28px; max-width: 520px; }
  .mk-device-hero small { display: inline-block; padding: 4px 9px; border-radius: 999px; background: #E7F3B7;
                          font-size: 9px; font-weight: 800; letter-spacing: .07em; }
  .mk-device-hero h3 { margin: 12px 0 8px; font-size: clamp(22px, 3.7vw, 34px); line-height: 1.08;
                       letter-spacing: -.03em; color: #141623; }
  .mk-device-hero h3 em { color: #4B20DC; font-style: normal; }
  .mk-device-hero p { margin: 0; font-size: 12px; color: #5F6175; }
  .mk-device-popup { position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%);
                     width: min(330px, calc(100% - 26px)); max-height: calc(100% - 26px); overflow: auto;
                     border-radius: 20px; background: #FFFDFB; border: 1px solid #E7DEFB;
                     box-shadow: 0 30px 70px -26px rgba(20,22,35,.65); }
  .mk-device.is-mobile .mk-device-popup { top: auto; bottom: 0; transform: translateX(-50%);
                                          width: 100%; max-height: 86%; border-radius: 20px 20px 0 0; }
  .mk-device-close { position: absolute; top: 8px; right: 8px; z-index: 2; width: 30px; height: 30px;
                     border: 0; border-radius: 50%; background: rgba(255,253,251,.94); color: #141623;
                     font-size: 18px; line-height: 1; cursor: pointer; box-shadow: 0 6px 14px -8px rgba(20,22,35,.5); }
  .mk-device-media { display: grid; place-items: center; overflow: hidden; min-height: 90px;
                     border-radius: 19px 19px 0 0; background: linear-gradient(160deg, #F1EBFE, #FBF8F2); }
  .mk-device-media img { display: block; width: 100%; max-height: 230px; object-fit: contain; }
  .mk-device-fallback { display: flex; flex-direction: column; align-items: center; justify-content: center;
                        gap: 6px; width: 100%; min-height: 150px; background: linear-gradient(155deg,#F6F0FF,#FBF8F2 62%,#EEF6D6); }
  .mk-device-fallback[hidden] { display: none; }
  .mk-device-mark { display: grid; place-items: center; width: 46px; height: 46px; border-radius: 14px;
                    background: linear-gradient(135deg,#4B20DC,#7B4BF5); color: #fff; font-size: 22px; }
  .mk-device-brand { font-size: 14px; font-weight: 800; color: #141623; }
  .mk-device-body { padding: 14px 16px 18px; text-align: center; }
  .mk-device-body h4 { margin: 0 0 6px; font-size: 17px; line-height: 1.2; letter-spacing: -.02em; color: #141623; }
  .mk-device-body p { margin: 0; font-size: 12.5px; line-height: 1.55; color: #5F6175; }
  .mk-device-coupon { margin-top: 12px; padding: 10px 12px; border-radius: 13px; background: #F1EBFE;
                      border: 1px dashed #C9B8F7; text-align: left; }
  .mk-device-coupon > span { display: block; font-size: 9px; font-weight: 800; letter-spacing: .08em;
                             text-transform: uppercase; color: #4B20DC; margin-bottom: 5px; }
  .mk-device-coupon > div { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .mk-device-coupon code { flex: 1 1 auto; font: 700 14px/1.2 ui-monospace, Menlo, monospace; color: #141623; }
  .mk-device-coupon button { border: 0; border-radius: 999px; background: #fff; color: #4B20DC;
                             font-size: 11px; font-weight: 700; padding: 7px 11px; cursor: pointer; }
  .mk-device-cta { display: block; margin-top: 14px; padding: 12px 16px; border-radius: 999px;
                   background: linear-gradient(135deg,#4B20DC,#7B4BF5); color: #fff; text-decoration: none;
                   font-size: 13px; font-weight: 700; }
  .mk-device-cta[hidden] { display: none; }
  @media (max-width: 640px) {
    .mk-modal-bar > div:first-child { flex: 1 1 100%; }
    .mk-modal-stage { padding: 10px; }
    .mk-device { height: calc(100vh - 240px); }
  }
</style>

<script>
/* Show the picked image before it is uploaded, and refresh the preview src. */
(function () {
    var input = document.getElementById('image');
    var preview = document.getElementById('imagePreview');
    if (!input || !preview) return;
    input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        if (!file) return;
        preview.src = URL.createObjectURL(file);
        preview.hidden = false;
    });
})();

/* The page checkboxes and "all pages" are one choice: ticking one clears the
   other, so a campaign can never target everything by accident. */
(function () {
    var all = document.querySelector('input[name="target_all"]');
    var singles = Array.prototype.slice.call(document.querySelectorAll('input[name="target_page[]"]'));
    if (!all) return;
    all.addEventListener('change', function () {
        if (all.checked) singles.forEach(function (box) { box.checked = false; });
    });
    singles.forEach(function (box) {
        box.addEventListener('change', function () {
            if (box.checked) all.checked = false;
        });
    });
})();

/* Publish / save are the same validated form; only the status differs. */
function submitAs(status) {
    var select = document.getElementById('status');
    if (select) select.value = status;
    return true;
}

/* The live preview reads the form itself, so the owner can check a heading or
   an image before saving anything. Nothing is sent to the server. */
(function () {
    var modal   = document.getElementById('livePreview');
    var device  = document.getElementById('mkDevice');
    var devicePopup = document.getElementById('mkDevicePopup');
    var image   = document.getElementById('mkDeviceImage');
    var fallback = document.getElementById('mkDeviceFallback');
    var heading = document.getElementById('mkDeviceHeading');
    var text    = document.getElementById('mkDeviceText');
    var coupon  = document.getElementById('mkDeviceCoupon');
    var code    = document.getElementById('mkDeviceCode');
    var cta     = document.getElementById('mkDeviceCta');
    var title   = document.getElementById('mkPreviewTitle');
    if (!modal) return;

    function val(id) { var el = document.getElementById(id); return el ? el.value.trim() : ''; }
    function checked(id) { var el = document.getElementById(id); return !!(el && el.checked); }

    function render() {
        var source = document.getElementById('imagePreview');
        var src = source && !source.hidden ? source.getAttribute('src') : '';
        if (src) {
            image.src = src;
            image.hidden = false;
            fallback.hidden = true;
        } else {
            image.removeAttribute('src');
            image.hidden = true;
            fallback.hidden = false;
        }

        var name = val('name') || 'Untitled campaign';
        title.textContent = 'Preview · ' + name;

        var h = val('heading');
        var d = val('description');
        heading.textContent = h;
        heading.hidden = !h;
        text.textContent = d;
        text.hidden = !d;

        var c = val('coupon_code').toUpperCase().replace(/[^A-Z0-9_-]/g, '');
        code.textContent = c;
        coupon.hidden = !c;

        var label = val('cta_text') || 'Claim offer';
        var href = val('cta_url');
        var showCta = checked('cta_enabled') && href !== '';
        cta.textContent = label;
        cta.hidden = !showCta;
        if (showCta) cta.setAttribute('href', href);
        else cta.removeAttribute('href');

        device.classList.toggle('mk-layout-announcement', val('layout') === 'announcement');
    }

    function open() {
        render();
        if (devicePopup) devicePopup.hidden = false;
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        var close = document.getElementById('mkPreviewClose');
        if (close) close.focus();
    }

    function close() {
        modal.hidden = true;
        document.body.style.overflow = '';
    }

    window.openLivePreview = open;

    document.getElementById('mkPreviewClose').addEventListener('click', close);
    var mockClose = document.querySelector('.mk-device-close');
    if (mockClose) mockClose.addEventListener('click', function () {
        if (devicePopup) devicePopup.hidden = true;
    });
    modal.addEventListener('click', function (event) { if (event.target === modal) close(); });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && !modal.hidden) close(); });

    document.querySelectorAll('[data-preview-device]').forEach(function (button) {
        button.addEventListener('click', function () {
            document.querySelectorAll('[data-preview-device]').forEach(function (other) {
                other.setAttribute('aria-pressed', String(other === button));
            });
            device.classList.toggle('is-mobile', button.dataset.previewDevice === 'mobile');
        });
    });

    var copy = document.getElementById('mkDeviceCopy');
    if (copy) {
        copy.addEventListener('click', function () {
            var value = code.textContent || '';
            if (!value) return;
            var done = function () { copy.textContent = 'Copied'; setTimeout(function () { copy.textContent = 'Copy code'; }, 1800); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(value).then(done).catch(done);
            } else { done(); }
        });
    }

    /* A broken preview image falls back the same way the real popup does. */
    image.addEventListener('error', function () {
        image.hidden = true;
        fallback.hidden = false;
    });

    /* Keep the modal's contents current if the form is edited while it is open. */
    var form = document.getElementById('popupForm');
    if (form) form.addEventListener('input', function () { if (!modal.hidden) render(); });
})();

</script>
<?php endif; ?>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/includes/owner_layout.php';
