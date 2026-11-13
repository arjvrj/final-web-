<?php
/**
 * owner_homepage.php — Owner panel: Homepage Management → Demo Video
 * URL:   owner_homepage.php
 * POST:  owner_homepage.php?action=save     (JSON, multipart, CSRF-checked)
 *
 * The homepage reads this configuration on every request through
 * includes/home_video.php, so a saved change appears on the landing page at the
 * next page load: no redeploy, no file edit, no cache to clear.
 *
 * Owner only. Nothing here is reachable without a session (requireOwner), and
 * the save endpoint is POST + CSRF, like every other owner action.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/home_video.php';

requireOwner();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* =========================================================
   SAVE (AJAX)
   ========================================================= */
if ($action === 'save') {
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }
    requireCsrf();

    try {
        /* ---------- thumbnail upload, checked before anything is saved ----------
           Same rules as the business logo: the file must really be an image, the
           extension comes from the detected type, the original filename never
           reaches the disk. */
        $storedThumb = '';
        $thumbError  = '';

        if (!empty($_FILES['thumbnail']['tmp_name']) && is_uploaded_file($_FILES['thumbnail']['tmp_name'])) {
            $check = rg_home_video_validate_thumb(
                (string)$_FILES['thumbnail']['tmp_name'],
                (int)($_FILES['thumbnail']['size'] ?? 0)
            );

            if (!$check['ok']) {
                $thumbError = $check['error'];
            } else {
                $dir = __DIR__ . '/assets/uploads';
                if (!is_dir($dir)) @mkdir($dir, 0755, true);

                $fname = rg_home_video_thumb_name($check['ext']);
                if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $dir . '/' . $fname)) {
                    @chmod($dir . '/' . $fname, 0644);
                    $storedThumb = 'assets/uploads/' . $fname;
                } else {
                    $thumbError = 'The thumbnail could not be saved. Check the uploads folder permissions.';
                }
            }
        } elseif (!empty($_FILES['thumbnail']['name'])) {
            /* A file was chosen but PHP refused it — usually the 3 MB limit or
               the server's upload_max_filesize. Say which. */
            $code = (int)($_FILES['thumbnail']['error'] ?? 0);
            if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
                $thumbError = 'The thumbnail is larger than this server accepts. Use an image under 3 MB.';
            } elseif ($code !== UPLOAD_ERR_NO_FILE) {
                $thumbError = 'The thumbnail upload failed. Please try again.';
            }
        }

        /* "Remove" wins over a simultaneous upload: the new file is discarded
           instead of being left orphaned while the setting goes empty. */
        if (!empty($_POST['remove_thumbnail']) && $storedThumb !== '') {
            $discard = __DIR__ . '/' . $storedThumb;
            if (is_file($discard)) @unlink($discard);
            $storedThumb = '';
        }

        /* The current file, read before the save replaces the setting. */
        $previousVideo  = rg_home_video_settings();
        $previousThumb  = (string)($previousVideo['thumbnail'] ?? '');

        $result = rg_home_video_save(
            [
                'url'         => $_POST['video_url'] ?? '',
                'title'       => $_POST['video_title'] ?? '',
                'description' => $_POST['video_description'] ?? '',
                'channel_url' => $_POST['channel_url'] ?? '',
                'enabled'     => !empty($_POST['enabled']),
            ],
            $storedThumb,
            !empty($_POST['remove_thumbnail'])
        );

        if ($thumbError !== '') {
            $result['ok'] = false;
            $result['errors']['thumbnail'] = $thumbError;
        }

        /* Nothing is written when the thumbnail failed; the rest of the form is
           kept in the browser so the owner only has to re-pick the image. */

        if (!$result['ok']) {
            /* A failed save must not leave the fresh upload behind: nothing in
               the database points at it. */
            if ($storedThumb !== '') {
                $orphan = __DIR__ . '/' . $storedThumb;
                if (is_file($orphan)) @unlink($orphan);
            }
            echo json_encode([
                'success' => false,
                'errors'  => $result['errors'],
                'message' => reset($result['errors']) ?: 'Could not save the video settings.',
            ]);
            exit;
        }

        $v = $result['saved'];

        /* A replaced thumbnail should not leave the old file on the server. */
        if ($storedThumb !== '' && $previousThumb !== '' && $previousThumb !== $storedThumb
            && preg_match('#^assets/uploads/[A-Za-z0-9._-]+$#', $previousThumb) === 1) {
            $old = __DIR__ . '/' . $previousThumb;
            if (is_file($old)) @unlink($old);
        }

        echo json_encode([
            'success' => true,
            'message' => $v['enabled']
                ? 'Saved. The video section is live on your homepage.'
                : 'Saved. The video section is hidden from your homepage.',
            'video'   => [
                'enabled'        => $v['enabled'],
                'video_id'       => $v['video_id'],
                'embed_url'      => $v['embed_url'],
                'watch_url'      => $v['watch_url'],
                'title'          => $v['title'],
                'description'    => $v['description'],
                'thumbnail_kind' => $v['thumbnail_kind'],
                'thumbnail_url'  => $v['thumbnail_url'],
                'has_video'      => $v['has_video'],
            ],
        ]);
    } catch (Exception $e) {
        error_log('homepage video save failed: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Could not save the video settings.']);
    }
    exit;
}

/* =========================================================
   RENDER
   ========================================================= */

$page = 'homepage';

$video     = rg_home_video_settings();
$defaults  = rg_home_video_defaults();
$homeUrl   = url('/');
$previewOk = $video['has_video'] && $video['embed_url'] !== '';

ob_start();
?>

<h1>Homepage management</h1>
<p>What visitors see on your landing page. Changes go live as soon as you save — there is nothing to redeploy.</p>

<?php if (!$previewOk): ?>
<div class="alert">
    <strong>No video saved yet.</strong>
    Paste a YouTube link below and switch the section on. Until then the video section does not appear on the homepage at all — visitors never see an empty player.
</div>
<?php endif; ?>

<form id="videoForm" onsubmit="saveVideo(event)" enctype="multipart/form-data">

    <div class="card">
        <div class="stack" style="justify-content:space-between;align-items:flex-start">
            <div>
                <h2>Demo video</h2>
                <p class="muted" style="margin:.25rem 0 0">
                    Appears on the homepage directly below the hero, above “How it works”.
                </p>
            </div>
            <span class="tag" id="videoStateTag" style="background:<?= $video['enabled'] ? '#e6f4e9' : '#f1f2f4' ?>;color:<?= $video['enabled'] ? '#1f513b' : '#5b5f66' ?>">
                <?= $video['enabled'] ? 'Shown on homepage' : 'Hidden' ?>
            </span>
        </div>

        <div class="alert" id="videoNotice" role="status" hidden style="margin-top:1rem"></div>

        <div class="field" style="margin-top:1rem">
            <label for="video_url">YouTube video URL *</label>
            <input id="video_url" type="text" name="video_url" inputmode="url" autocomplete="off"
                   placeholder="https://www.youtube.com/watch?v=…"
                   value="<?= e($video['url']) ?>">
            <p class="muted" style="margin:.4rem 0 0;font-size:.85rem">
                Accepted: youtube.com/watch?v=…, youtu.be/…, youtube.com/embed/…, /shorts/…, or just the video id.
                The video id is read from the link — no HTML or embed code is ever stored.
            </p>
        </div>

        <div class="field">
            <label for="video_title">Video title</label>
            <input id="video_title" type="text" name="video_title" maxlength="120"
                   value="<?= e($video['title']) ?>" placeholder="<?= e($defaults['title']) ?>">
            <p class="muted" style="margin:.4rem 0 0;font-size:.85rem">
                Shown under the player and used as the frame's accessible name.
            </p>
        </div>

        <div class="field">
            <label for="video_description">Supporting description</label>
            <textarea id="video_description" name="video_description" rows="3" maxlength="300"><?= e($video['description']) ?></textarea>
            <p class="muted" style="margin:.4rem 0 0;font-size:.85rem">The sentence under the “See How ReviewAI Works” heading.</p>
        </div>

        <div class="field">
            <label for="channel_url">YouTube channel URL (where “Watch on YouTube” goes)</label>
            <input id="channel_url" type="text" name="channel_url" inputmode="url" autocomplete="off"
                   placeholder="https://www.youtube.com/@yourchannel"
                   value="<?= e($video['channel_url']) ?>">
            <p class="muted" style="margin:.4rem 0 0;font-size:.85rem">
                Leave it empty and the button points at the video itself. Only youtube.com links are accepted.
            </p>
        </div>

        <div class="field">
            <label for="thumbnail">Custom thumbnail (optional)</label>
            <input id="thumbnail" type="file" name="thumbnail" accept="image/jpeg,image/png,image/gif,image/webp">
            <p class="muted" style="margin:.4rem 0 0;font-size:.85rem">
                JPEG, PNG, GIF or WebP up to 3 MB. 16:9 looks best (1280×720 or larger).
                Leave empty to use YouTube's own preview, or the ReviewAI card when there is no video.
            </p>

            <div id="thumbCurrent" style="margin-top:.85rem;<?= $video['thumbnail_url'] === '' ? 'display:none' : '' ?>">
                <p class="muted" style="margin:0 0 .4rem;font-size:.85rem">
                    Current: <span id="thumbKind"><?= $video['thumbnail_kind'] === 'custom' ? 'your uploaded image' : 'YouTube preview' ?></span>
                </p>
                <img id="thumbPreview" src="<?= e($video['thumbnail_url']) ?>" alt="Current video thumbnail"
                     onerror="thumbnailMissing(this)"
                     style="width:100%;max-width:420px;border-radius:12px;border:1px solid #dfe4e0;display:block">
                <p class="muted" id="thumbMissing" hidden style="margin:.4rem 0 0;font-size:.85rem">
                    The stored thumbnail could not be loaded. The homepage falls back to its built-in card until a new image is uploaded.
                </p>
                <?php if ($video['thumbnail'] !== ''): ?>
                <label class="stack" style="gap:.45rem;margin-top:.6rem;font-size:.9rem;align-items:center">
                    <input type="checkbox" name="remove_thumbnail" value="1" style="width:auto">
                    Remove this uploaded thumbnail
                </label>
                <?php endif; ?>
            </div>
        </div>

        <div class="field">
            <label for="enabled">Section visibility</label>
            <label class="stack" style="gap:.5rem;align-items:center;font-size:.95rem">
                <input id="enabled" type="checkbox" name="enabled" value="1" style="width:auto"
                       <?= $video['enabled'] ? 'checked' : '' ?>>
                Show the demo video section on the homepage
            </label>
            <p class="muted" style="margin:.4rem 0 0;font-size:.85rem">
                Switching this off hides the whole section. The saved video and thumbnail stay, so switching it back on needs no re-entry.
            </p>
        </div>

        <div class="stack" style="gap:.6rem;margin-top:1rem;flex-wrap:wrap">
            <button class="btn" type="submit" id="videoSaveBtn">Save changes</button>
            <button class="btn" type="button" onclick="previewVideo()" id="videoPreviewBtn"
                    <?= $previewOk ? '' : 'disabled title="Save a video first"' ?>>Preview video</button>
            <a class="btn" href="<?= e($homeUrl) ?>" target="_blank" rel="noopener">Open homepage ↗</a>
        </div>
    </div>
</form>

<div class="card" style="margin-top:1rem">
    <h2>How this works</h2>
    <ul class="muted" style="margin:.5rem 0 0;padding-left:1.1rem;line-height:1.7">
        <li>The homepage reads these values from the database on every page load — nothing is baked into the code.</li>
        <li>The player loads YouTube only after a visitor presses play, so the landing page stays fast and sets no YouTube cookie up front.</li>
        <li>Only the video id reaches the embed URL. Any other link is refused, so the section can never turn into an open redirect or an HTML injection point.</li>
        <li>Uploading a new tutorial: paste the new link, press Save changes. That is the whole job.</li>
    </ul>
</div>

<!-- preview modal: the saved video, in the same embed the homepage will use -->
<div class="modal" id="videoModal" hidden>
    <div class="card">
        <div class="modal-head">
            <h2 style="margin:0">Preview</h2>
            <button class="btn" type="button" onclick="closePreview()">Close</button>
        </div>
        <p class="muted" id="videoModalTitle" style="margin:.5rem 0"></p>
        <div style="position:relative;aspect-ratio:16/9;border-radius:12px;overflow:hidden;background:#141623">
            <iframe id="videoModalFrame" title="Video preview" src=""
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                    allowfullscreen style="position:absolute;inset:0;width:100%;height:100%;border:0"></iframe>
        </div>
        <p class="muted" style="margin:.6rem 0 0;font-size:.85rem">
            This preview calls YouTube directly. The public homepage waits for the visitor to press play.
        </p>
    </div>
</div>

<script>
var RG_VIDEO_EMBED = <?= json_encode($video['embed_url']) ?>;
var RG_VIDEO_TITLE = <?= json_encode($video['title']) ?>;

function videoNotice(message, ok) {
    var box = document.getElementById('videoNotice');
    box.textContent = message;
    box.hidden = false;
    box.style.background = ok ? '#e6f4e9' : '#fdecec';
    box.style.color      = ok ? '#1f513b' : '#8a2020';
}

function saveVideo(event) {
    event.preventDefault();

    var form = document.getElementById('videoForm');
    var btn  = document.getElementById('videoSaveBtn');
    var data = new FormData(form);
    data.append('action', 'save');
    data.append('_csrf', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

    btn.disabled = true;
    btn.textContent = 'Saving…';

    fetch('owner_homepage.php?action=save', {
        method: 'POST',
        body: data,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) {
        return r.json().catch(function () {
            throw new Error('The server did not answer with JSON. Your session may have expired — reload and sign in again.');
        });
    }).then(function (reply) {
        btn.disabled = false;
        btn.textContent = 'Save changes';

        if (!reply.success) {
            var parts = [];
            if (reply.errors) {
                Object.keys(reply.errors).forEach(function (key) { parts.push(reply.errors[key]); });
            }
            videoNotice(parts.length ? parts.join(' ') : (reply.message || 'Could not save.'), false);
            return;
        }

        videoNotice(reply.message || 'Saved.', true);

        var v = reply.video || {};
        RG_VIDEO_EMBED = v.embed_url || '';
        RG_VIDEO_TITLE = v.title || '';

        var tag = document.getElementById('videoStateTag');
        if (tag) {
            tag.textContent = v.enabled ? 'Shown on homepage' : 'Hidden';
            tag.style.background = v.enabled ? '#e6f4e9' : '#f1f2f4';
            tag.style.color      = v.enabled ? '#1f513b' : '#5b5f66';
        }

        var previewBtn = document.getElementById('videoPreviewBtn');
        if (previewBtn) {
            previewBtn.disabled = !v.has_video;
            if (v.has_video) previewBtn.removeAttribute('title');
        }

        var current = document.getElementById('thumbCurrent');
        var img = document.getElementById('thumbPreview');
        if (current && img) {
            if (v.thumbnail_url) {
                img.style.display = '';
                var note = document.getElementById('thumbMissing');
                if (note) note.hidden = true;
                img.src = v.thumbnail_url;
                document.getElementById('thumbKind').textContent =
                    v.thumbnail_kind === 'custom' ? 'your uploaded image' : 'YouTube preview';
                current.style.display = '';
            } else {
                current.style.display = 'none';
            }
        }
    }).catch(function (err) {
        btn.disabled = false;
        btn.textContent = 'Save changes';
        videoNotice(err.message || 'Could not save. Check your connection and try again.', false);
    });
}

/* A preview image that cannot load (a deleted upload, YouTube changing a URL)
   must say so instead of showing a broken icon. */
function thumbnailMissing(img) {
    img.style.display = 'none';
    var note = document.getElementById('thumbMissing');
    if (note) note.hidden = false;
}

function previewVideo() {
    if (!RG_VIDEO_EMBED) {
        videoNotice('Save a YouTube link first.', false);
        return;
    }
    var modal = document.getElementById('videoModal');
    var frame = document.getElementById('videoModalFrame');
    document.getElementById('videoModalTitle').textContent = RG_VIDEO_TITLE;
    /* autoplay is fine here: the owner just asked for it. */
    frame.src = RG_VIDEO_EMBED;
    modal.hidden = false;
}

function closePreview() {
    var modal = document.getElementById('videoModal');
    var frame = document.getElementById('videoModalFrame');
    frame.src = '';
    modal.hidden = true;
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closePreview();
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/includes/owner_layout.php';
