<?php
/**
 * popups.php — the Popup & Promotion Manager, and every rule about it.
 *
 * Used by:
 *   - owner_marketing.php   (Marketing → Popup & Promotions)
 *   - home.php, login.php, signup.php (the public pages that may show one)
 *   - popup_event.php       (impression / click / copy / dismissal counting)
 *
 * The rules of the house:
 *   - **Marketing pages only.** A popup can never appear in a business dashboard,
 *     the owner panel, or the customer review/QR flow. Those pages simply do not
 *     call this file — the exclusion is structural, not a setting.
 *   - **Only a stored image, never pasted HTML.** The admin can upload an image
 *     and type text; the popup markup is built here.
 *   - **The browser is not trusted with the schedule.** Which campaign is live is
 *     decided from the saved dates in the campaign's own timezone, then matched
 *     against the page.
 *   - **Frequency is remembered on the server too.** A small opaque visitor
 *     cookie keys a popup_views row, so a cleared localStorage does not make a
 *     "once every 24 hours" offer appear again. The browser keeps its own copy as
 *     a fallback for cookie-less visitors.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/links.php';
require_once __DIR__ . '/security.php';

/* ------------------------------------------------------------------ limits */

const RG_POPUP_NAME_MAX   = 120;
const RG_POPUP_HEAD_MAX   = 160;
const RG_POPUP_DESC_MAX   = 400;
const RG_POPUP_CTA_MAX    = 60;
const RG_POPUP_COUPON_MAX = 40;

/** Upload limit for a promotional image. */
const RG_POPUP_IMAGE_MAX_BYTES = 4 * 1024 * 1024;

/** Dimensions a popup image may use: 4:5 (portrait) and 1:1 (square). */
const RG_POPUP_IMAGE_MIN_SIDE = 320;
const RG_POPUP_IMAGE_MAX_SIDE = 4000;

/** The opaque browser key used to remember a visitor's dismissals. */
const RG_POPUP_VISITOR_COOKIE = 'rgpv';

/** How long that cookie lives. Long enough for "once per campaign". */
const RG_POPUP_VISITOR_DAYS = 365;

/* ---------------------------------------------------------------- schema */

/**
 * Is the database new enough for this build?
 *
 * The popup manager grew a timezone column and a per-visitor table after the
 * first release. Rather than failing with a SQL error, the admin page asks this
 * once and tells the owner exactly which file to run.
 */
function rg_popup_schema_ready(): bool
{
    global $conn;
    static $ready = null;
    if ($ready !== null) return $ready;
    $ready = false;
    try {
        $col = $conn->query("SHOW COLUMNS FROM popups LIKE 'timezone'");
        $view = $conn->query("SHOW TABLES LIKE 'popup_views'");
        $ready = (bool)($col && $col->fetch()) && (bool)($view && $view->fetch());
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/* --------------------------------------------------------------- registry */

/**
 * The public marketing pages a popup may target.
 *
 * Deliberately short and explicit. Dashboards, the owner panel and review.php are
 * not in this list, so there is no setting anywhere that can put a promotion in
 * front of a customer who is writing a review.
 */
function rg_popup_page_registry(): array
{
    return [
        'home'    => ['label' => 'Homepage', 'path' => '/'],
        'pricing' => ['label' => 'Pricing section (homepage #pricing)', 'path' => '/#pricing'],
        'login'   => ['label' => 'Log in page', 'path' => '/login.php'],
        'signup'  => ['label' => 'Sign-up page', 'path' => '/signup.php'],
    ];
}

function rg_popup_layouts(): array
{
    return [
        'image'        => 'Image only — the artwork fills the popup',
        'image_text'   => 'Image + heading and description',
        'image_cta'    => 'Image + call-to-action button',
        'announcement' => 'Promotional announcement (text first)',
    ];
}

function rg_popup_triggers(): array
{
    return [
        'immediate' => 'Immediately after the page loads',
        'delay'     => 'After a delay',
        'scroll'    => 'After the visitor scrolls a set distance',
        'exit'      => 'Exit intent (desktop only)',
    ];
}

function rg_popup_frequencies(): array
{
    return [
        'always'   => 'Every visit',
        'session'  => 'Once per browser session',
        '24h'      => 'Once every 24 hours',
        '7d'       => 'Once every 7 days',
        'campaign' => 'Once per campaign, ever',
    ];
}

/** The event kinds the public page may report, and the counter each moves. */
function rg_popup_event_kinds(): array
{
    return [
        'impression' => 'impressions',
        'click'      => 'clicks',
        'coupon'     => 'coupon_copies',
        'dismiss'    => 'dismissals',
    ];
}

/* ---------------------------------------------------------------- the page */

/**
 * Which registry key is rendering right now?
 *
 * Set explicitly by each page (`print_rg_popup('home')`), so there is no guesswork
 * from the URL: a rewrite or a query string cannot make a popup appear on a page
 * that did not ask for one.
 */
function rg_popup_current_page(): string
{
    return defined('RG_PUBLIC_PAGE') ? (string)RG_PUBLIC_PAGE : '';
}

/* -------------------------------------------------------------- validation */

/** Trims, strips control characters and caps a single line of text. */
function rg_popup_line(string $value, int $max): string
{
    $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? $value);
    if (function_exists('mb_substr')) return mb_substr($value, 0, $max);
    return substr($value, 0, $max);
}

/**
 * A coupon code: letters, digits, hyphen and underscore only.
 *
 * Anything else could not be typed or copied reliably, and there is no reason a
 * code needs a `<` in it.
 */
function rg_popup_coupon(string $value): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/[^A-Z0-9_-]/', '', $value) ?? '';
    return substr($value, 0, RG_POPUP_COUPON_MAX);
}

/** A page target list: known keys only, always at least one. */
function rg_popup_targets(string $raw): string
{
    $known = array_keys(rg_popup_page_registry());
    $parts = array_filter(array_map('trim', explode(',', strtolower($raw))));
    $out = [];
    foreach ($parts as $part) {
        if ($part === 'all') return 'all';
        if (in_array($part, $known, true) && !in_array($part, $out, true)) $out[] = $part;
    }
    return $out ? implode(',', $out) : 'home';
}

function rg_popup_targets_match(string $targets, string $page): bool
{
    if ($page === '') return false;

    /* "all" means all *marketing* pages. A page that is not in the registry —
       a dashboard, the owner panel, the customer review flow — matches nothing,
       whatever the database says. The exclusion cannot be edited away. */
    if (!array_key_exists($page, rg_popup_page_registry())) return false;

    /* Pricing lives in a section of the homepage, so a campaign aimed at
       "pricing" has to be considered on the homepage view. */
    $equivalents = $page === 'home' ? ['pricing'] : [];

    foreach (array_map('trim', explode(',', $targets)) as $target) {
        if ($target === 'all' || $target === $page) return true;
        if (in_array($target, $equivalents, true)) return true;
    }
    return false;
}

/**
 * A CTA destination as the owner typed it.
 *
 * Accepts a full http(s) address, a path on this site (`signup.php`,
 * `/#pricing`) or an anchor (`#pricing`). Everything that could change
 * destination — `javascript:`, `data:`, `//host`, backslashes, control
 * characters — is refused. The result is stored as typed; rendering resolves a
 * relative path against the site URL.
 */
function rg_popup_cta_href(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') return '';
    if (strlen($raw) > RG_URL_MAX_LENGTH) return '';
    if (preg_match('/[\x00-\x20\x7F]/', $raw)) return '';
    if (strpos($raw, '\\') !== false) return '';
    if (strpos($raw, '//') === 0) return '';

    /* A full URL goes through the same strict check as every other link. */
    if (preg_match('#^[A-Za-z][A-Za-z0-9+.-]*:#', $raw)) {
        return rg_safe_url($raw, 'popup_cta');
    }

    /* A same-page anchor. */
    if ($raw[0] === '#') {
        return preg_match('/^#[A-Za-z0-9_-]+$/', $raw) ? $raw : '';
    }

    /* A relative path inside this application. No scheme, no host. */
    if (preg_match('#^[A-Za-z0-9._~!$&\'()*+,;=:@/?%\#-]+$#', $raw)) {
        return $raw;
    }

    return '';
}

/** The href actually printed: a relative path is resolved against site_url. */
function rg_popup_cta_resolve(string $stored): string
{
    $stored = rg_popup_cta_href($stored);
    if ($stored === '') return '';
    if ($stored[0] === '#') return $stored;
    if (preg_match('#^https?://#i', $stored)) return $stored;
    return url(ltrim($stored, '/'));
}

/* ------------------------------------------------------------- timezones */

/** The owner's site timezone, used when an old row has none saved. */
function rg_popup_default_timezone(): string
{
    $tz = (string)getSetting('popup_timezone', '');
    if ($tz !== '' && in_array($tz, DateTimeZone::listIdentifiers(), true)) return $tz;
    return date_default_timezone_get() ?: 'UTC';
}

/** Validates a stored/selected timezone name. */
function rg_popup_timezone(string $tz): string
{
    $tz = trim($tz);
    if ($tz === '') return rg_popup_default_timezone();
    try {
        new DateTimeZone($tz);
        return $tz;
    } catch (Throwable $e) {
        return rg_popup_default_timezone();
    }
}

/**
 * The zones offered in the editor: India first (this is an Indian product),
 * then UTC and a short practical list. Every stored value is still validated.
 */
function rg_popup_timezone_options(): array
{
    $common = [
        'Asia/Kolkata'      => 'India Standard Time (Asia/Kolkata)',
        'UTC'               => 'UTC',
        'Asia/Dubai'        => 'Dubai (Asia/Dubai)',
        'Asia/Singapore'    => 'Singapore (Asia/Singapore)',
        'Asia/Tokyo'        => 'Tokyo (Asia/Tokyo)',
        'Europe/London'     => 'London (Europe/London)',
        'Europe/Berlin'     => 'Berlin (Europe/Berlin)',
        'America/New_York'  => 'New York (America/New_York)',
        'America/Chicago'   => 'Chicago (America/Chicago)',
        'America/Los_Angeles' => 'Los Angeles (America/Los_Angeles)',
        'Australia/Sydney'  => 'Sydney (Australia/Sydney)',
    ];

    $all = [];
    foreach (DateTimeZone::listIdentifiers() as $id) {
        $all[$id] = $id;
    }
    /* The common list wins on label, but keeps its place at the top. */
    return $common + $all;
}

/** A MySQL DATETIME in the campaign timezone, or null. */
function rg_popup_row_time($value, string $tz): ?int
{
    $value = trim((string)$value);
    if ($value === '') return null;

    $zone = new DateTimeZone(rg_popup_timezone($tz));
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $zone);
    if ($dt === false) {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $value, $zone);
    }
    if ($dt !== false) return $dt->getTimestamp();

    $fallback = strtotime($value);
    return $fallback === false ? null : $fallback;
}

/** A readable date/time in the campaign timezone, e.g. "12 Nov 2026, 6:00 pm IST". */
function rg_popup_format_datetime($value, string $tz): string
{
    $ts = rg_popup_row_time($value, $tz);
    if ($ts === null) return '';
    $zone = new DateTimeZone(rg_popup_timezone($tz));
    return (new DateTimeImmutable('@' . $ts))->setTimezone($zone)->format('d M Y, g:i a T');
}

/** The `value=` for `<input type="date">` in the campaign timezone. */
function rg_popup_date_input($value, string $tz): string
{
    $ts = rg_popup_row_time($value, $tz);
    if ($ts === null) return '';
    $zone = new DateTimeZone(rg_popup_timezone($tz));
    return (new DateTimeImmutable('@' . $ts))->setTimezone($zone)->format('Y-m-d');
}

/** The `value=` for `<input type="time">` in the campaign timezone. */
function rg_popup_time_input($value, string $tz, string $fallback = '00:00'): string
{
    $ts = rg_popup_row_time($value, $tz);
    if ($ts === null) return $fallback;
    $zone = new DateTimeZone(rg_popup_timezone($tz));
    return (new DateTimeImmutable('@' . $ts))->setTimezone($zone)->format('H:i');
}

/**
 * Combines the date/time/timezone fields into the two DATETIME values stored.
 *
 * Dates and times are wall-clock values in the chosen zone, stored as such. That
 * keeps "11 November, 6:00 pm IST" exactly what the owner typed even if the
 * server itself runs in UTC — no conversion is silently applied twice.
 *
 * Returns ['starts_at','ends_at','timezone','errors'].
 */
function rg_popup_schedule(array $input): array
{
    $tz = rg_popup_timezone((string)($input['timezone'] ?? ''));
    $errors = [];

    $parse = static function ($dateInput, $timeInput, string $defaultTime, string $field) use ($tz, &$errors): ?string {
        $date = trim((string)$dateInput);
        if ($date === '') return null;

        $time = trim((string)$timeInput);
        if ($time === '') $time = $defaultTime;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $errors[$field] = 'Use a date like 2026-11-12.';
            return null;
        }
        if (!preg_match('/^(\d{2}):(\d{2})(?::\d{2})?$/', $time, $tm)) {
            $errors[$field] = 'Use a time like 18:00.';
            return null;
        }
        if ((int)$tm[1] > 23 || (int)$tm[2] > 59) {
            $errors[$field] = 'That is not a real time.';
            return null;
        }

        $zone = new DateTimeZone($tz);
        $stamp = $date . ' ' . $tm[1] . ':' . $tm[2];
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $stamp, $zone);
        $parseErrors = DateTimeImmutable::getLastErrors();
        if ($dt === false
            || ($parseErrors && (($parseErrors['warning_count'] ?? 0) > 0 || ($parseErrors['error_count'] ?? 0) > 0))
            || $dt->format('Y-m-d H:i') !== $stamp) {
            $errors[$field] = 'That date and time could not be read. Use a real date, like 2026-11-12.';
            return null;
        }
        return $dt->format('Y-m-d H:i:s');
    };

    $starts = $parse($input['starts_at'] ?? '', $input['start_time'] ?? '', '00:00', 'starts_at');
    $ends   = $parse($input['ends_at'] ?? '', $input['end_time'] ?? '', '23:59', 'ends_at');

    if ($starts !== null && $ends !== null) {
        $startTs = rg_popup_row_time($starts, $tz);
        $endTs   = rg_popup_row_time($ends, $tz);
        if ($startTs !== null && $endTs !== null && $endTs < $startTs) {
            $errors['ends_at'] = 'The campaign cannot end before it starts.';
        }
    }

    return ['starts_at' => $starts, 'ends_at' => $ends, 'timezone' => $tz, 'errors' => $errors];
}

/* ------------------------------------------------------------ status logic */

/**
 * The state a campaign is in *right now*.
 *
 * draft / paused are the owner's own switches. For an active campaign the dates
 * in its own timezone decide: before the start it is scheduled, after the end it
 * is expired, and between the two it is live.
 */
function rg_popup_status(array $row, ?int $now = null): string
{
    $now = $now ?? time();
    $status = strtolower((string)($row['status'] ?? 'draft'));
    if ($status === 'draft') return 'draft';
    if ($status === 'paused') return 'paused';

    $tz = rg_popup_timezone((string)($row['timezone'] ?? ''));
    $starts = rg_popup_row_time($row['starts_at'] ?? '', $tz);
    $ends   = rg_popup_row_time($row['ends_at'] ?? '', $tz);

    if ($starts !== null && $now < $starts) return 'scheduled';
    if ($ends !== null && $now > $ends) return 'expired';
    return 'active';
}

function rg_popup_status_label(string $status): string
{
    return [
        'active'    => 'Active',
        'scheduled' => 'Scheduled',
        'expired'   => 'Expired',
        'paused'    => 'Disabled',
        'draft'     => 'Draft',
    ][$status] ?? 'Draft';
}

/**
 * The date a `<input type="date">` sends → a MySQL DATETIME, or null.
 * Kept for compatibility; new saves go through rg_popup_schedule().
 */
function rg_popup_date(?string $value, string $time = '00:00:00'): ?string
{
    $value = trim((string)$value);
    if ($value === '') return null;
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value . ' ' . $time, new DateTimeZone('UTC'));
    if (!$parsed) {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if (!$parsed) return null;
        $parsed = $parsed->setTime(0, 0, 0);
    }
    return $parsed->format('Y-m-d H:i:s');
}

/* ------------------------------------------------------------------ images */

/**
 * Checks an uploaded popup image.
 *
 * Returns ['ok', 'ext', 'width', 'height', 'error']. The extension comes from the
 * detected type, never from the file name; the dimensions are checked because a
 * 40-pixel-wide image in a popup looks broken on every device. JPEG, PNG and
 * WebP only — exactly the formats the brief lists.
 */
function rg_popup_validate_image(string $tmpPath, int $size): array
{
    if ($tmpPath === '' || !is_file($tmpPath)) {
        return ['ok' => false, 'ext' => '', 'error' => 'No image was uploaded.', 'width' => 0, 'height' => 0];
    }
    if ($size <= 0) {
        return ['ok' => false, 'ext' => '', 'error' => 'The image is empty.', 'width' => 0, 'height' => 0];
    }
    if ($size > RG_POPUP_IMAGE_MAX_BYTES) {
        return ['ok' => false, 'ext' => '', 'error' => 'The image must be 4 MB or smaller.', 'width' => 0, 'height' => 0];
    }

    $info = @getimagesize($tmpPath);
    $allowed = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];
    $ext = ($info && isset($allowed[$info[2]])) ? $allowed[$info[2]] : '';
    if ($ext === '') {
        return ['ok' => false, 'ext' => '', 'error' => 'Use a JPG, PNG or WebP image.', 'width' => 0, 'height' => 0];
    }

    $width  = (int)$info[0];
    $height = (int)$info[1];
    if ($width < RG_POPUP_IMAGE_MIN_SIDE || $height < RG_POPUP_IMAGE_MIN_SIDE) {
        return ['ok' => false, 'ext' => '', 'error' => 'The image is too small. Use at least '
            . RG_POPUP_IMAGE_MIN_SIDE . '×' . RG_POPUP_IMAGE_MIN_SIDE . ' pixels.', 'width' => $width, 'height' => $height];
    }
    if ($width > RG_POPUP_IMAGE_MAX_SIDE || $height > RG_POPUP_IMAGE_MAX_SIDE) {
        return ['ok' => false, 'ext' => '', 'error' => 'The image is larger than '
            . RG_POPUP_IMAGE_MAX_SIDE . ' pixels on a side. Please scale it down.', 'width' => $width, 'height' => $height];
    }

    /* 4:5 → 2:1 in either orientation. Outside that the popup would either crop
       the artwork or leave a band of empty space, and the brief is explicit:
       do not crop or distort what the owner uploaded. */
    $ratio = $width / max(1, $height);
    if ($ratio < 0.5 || $ratio > 2.0) {
        return ['ok' => false, 'ext' => '', 'error' => 'That shape cannot be shown without cropping it. '
            . 'Use something between 1:2 and 2:1 — 4:5 or 1:1 is ideal.', 'width' => $width, 'height' => $height];
    }

    return ['ok' => true, 'ext' => $ext, 'error' => '', 'width' => $width, 'height' => $height];
}

function rg_popup_image_name(string $ext): string
{
    $allowed = ['jpg', 'png', 'webp'];
    if (!in_array($ext, $allowed, true)) $ext = 'jpg';
    return 'popup-' . bin2hex(random_bytes(8)) . '.' . $ext;
}

/** A stored path is used only if it is inside uploads and the file is there. */
function rg_popup_image_url(string $path): string
{
    if ($path === '') return '';
    if (preg_match('#^assets/uploads/[A-Za-z0-9._-]+$#', $path) !== 1) return '';
    if (!is_file(__DIR__ . '/../' . $path)) return '';
    return url($path);
}

/* -------------------------------------------------------------- save/delete */

/**
 * Validates and stores a campaign.
 *
 * Returns ['ok', 'id', 'errors' => [field => message]]. Nothing is written when
 * any field fails, so a campaign never half-exists.
 */
function rg_popup_save(array $input, int $id = 0, string $newImage = '', bool $removeImage = false): array
{
    $errors = [];

    $name = rg_popup_line((string)($input['name'] ?? ''), RG_POPUP_NAME_MAX);
    if ($name === '') $errors['name'] = 'Give the campaign a name. It is only used in this panel.';

    $layout = strtolower(trim((string)($input['layout'] ?? 'image_text')));
    if (!array_key_exists($layout, rg_popup_layouts())) $layout = 'image_text';

    $heading     = rg_popup_line((string)($input['heading'] ?? ''), RG_POPUP_HEAD_MAX);
    $description = rg_popup_line((string)($input['description'] ?? ''), RG_POPUP_DESC_MAX);
    $coupon      = rg_popup_coupon((string)($input['coupon_code'] ?? ''));

    $ctaEnabled = !empty($input['cta_enabled']);
    $ctaText    = rg_popup_line((string)($input['cta_text'] ?? ''), RG_POPUP_CTA_MAX);
    $ctaUrlRaw  = trim((string)($input['cta_url'] ?? ''));
    $ctaUrl     = '';
    if ($ctaUrlRaw !== '') {
        $ctaUrl = rg_popup_cta_href($ctaUrlRaw);
        if ($ctaUrl === '') {
            $errors['cta_url'] = 'That button link is not a normal web address. Use https://…, signup.php or #pricing.';
        }
    }
    if ($ctaEnabled && $ctaText === '') {
        $ctaText = 'Claim offer';
    }
    if ($ctaEnabled && $ctaUrl === '') {
        $errors['cta_url'] = $errors['cta_url'] ?? 'Add a destination for the button, or switch the button off.';
    }

    $trigger = strtolower(trim((string)($input['trigger_type'] ?? 'delay')));
    if (!array_key_exists($trigger, rg_popup_triggers())) $trigger = 'delay';
    $triggerValue = (int)($input['trigger_value'] ?? 3);
    $triggerValue = max(1, min(60, $triggerValue));

    $frequency = strtolower(trim((string)($input['frequency'] ?? 'session')));
    if (!array_key_exists($frequency, rg_popup_frequencies())) $frequency = 'session';

    $targets = rg_popup_targets((string)($input['targets'] ?? 'home'));

    $schedule = rg_popup_schedule($input);
    $starts = $schedule['starts_at'];
    $ends   = $schedule['ends_at'];
    $tz     = $schedule['timezone'];
    if ($schedule['errors']) $errors = array_merge($errors, $schedule['errors']);

    $status = strtolower(trim((string)($input['status'] ?? 'draft')));
    if (!in_array($status, ['draft', 'active', 'paused'], true)) $status = 'draft';

    /* Publishing needs an image: a promotion with no artwork is not a popup. */
    $image = $removeImage ? '' : $newImage;
    if ($image === '' && !$removeImage && $id > 0) {
        $image = rg_popup_image_from_db($id);
    }
    if ($status === 'active' && $image === '' && $layout !== 'announcement') {
        $errors['image'] = 'Upload the popup image before publishing, or use the announcement layout.';
    }

    if ($errors) {
        return ['ok' => false, 'id' => $id, 'errors' => $errors];
    }

    global $conn;

    try {
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE popups SET name = ?, status = ?, layout = ?, image = ?, heading = ?,
                description = ?, coupon_code = ?, cta_enabled = ?, cta_text = ?, cta_url = ?, cta_new_tab = ?,
                trigger_type = ?, trigger_value = ?, frequency = ?, targets = ?, starts_at = ?, ends_at = ?,
                timezone = ?
                WHERE id = ?");
            $stmt->execute([
                $name, $status, $layout, $image, $heading, $description, $coupon,
                $ctaEnabled ? 1 : 0, $ctaText, $ctaUrl, !empty($input['cta_new_tab']) ? 1 : 0,
                $trigger, $triggerValue, $frequency, $targets, $starts, $ends, $tz, $id,
            ]);
            return ['ok' => true, 'id' => $id, 'errors' => []];
        }

        $stmt = $conn->prepare("INSERT INTO popups (name, status, layout, image, heading, description,
            coupon_code, cta_enabled, cta_text, cta_url, cta_new_tab, trigger_type, trigger_value,
            frequency, targets, starts_at, ends_at, timezone)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $name, $status, $layout, $image, $heading, $description, $coupon,
            $ctaEnabled ? 1 : 0, $ctaText, $ctaUrl, !empty($input['cta_new_tab']) ? 1 : 0,
            $trigger, $triggerValue, $frequency, $targets, $starts, $ends, $tz,
        ]);
        $newId = (int)$conn->lastInsertId();
        return ['ok' => true, 'id' => $newId, 'errors' => []];
    } catch (Exception $e) {
        error_log('popup save failed: ' . $e->getMessage());
        return ['ok' => false, 'id' => $id, 'errors' => ['form' => 'Could not save the campaign.']];
    }
}

/** The image currently stored on a campaign ('' when there is none). */
function rg_popup_image_from_db(int $id): string
{
    global $conn;
    try {
        $stmt = $conn->prepare("SELECT image FROM popups WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? (string)($row['image'] ?? '') : '';
    } catch (Exception $e) {
        return '';
    }
}

/** Removes a stored image file, refusing anything outside assets/uploads. */
function rg_popup_delete_file(string $path): void
{
    if (preg_match('#^assets/uploads/[A-Za-z0-9._-]+$#', $path) !== 1) return;
    $absolute = __DIR__ . '/../' . $path;
    if (is_file($absolute)) @unlink($absolute);
}

function rg_popup_delete(int $id): bool
{
    global $conn;
    try {
        $image = rg_popup_image_from_db($id);
        $stmt = $conn->prepare("DELETE FROM popups WHERE id = ?");
        $stmt->execute([$id]);
        rg_popup_delete_file($image);
        return true;
    } catch (Exception $e) {
        error_log('popup delete failed: ' . $e->getMessage());
        return false;
    }
}

function rg_popup_set_status(int $id, string $status): bool
{
    global $conn;
    if (!in_array($status, ['draft', 'active', 'paused'], true)) return false;
    try {
        $stmt = $conn->prepare("UPDATE popups SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/* ------------------------------------------------------------------- reads */

/** Every campaign, newest first, for the admin list. */
function rg_popup_all(): array
{
    global $conn;
    try {
        $rows = $conn->query("SELECT * FROM popups ORDER BY created_at DESC, id DESC")->fetchAll();
        return is_array($rows) ? $rows : [];
    } catch (Exception $e) {
        return [];
    }
}

function rg_popup_find(int $id): ?array
{
    global $conn;
    try {
        $stmt = $conn->prepare("SELECT * FROM popups WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/** The counters on the admin dashboard. */
function rg_popup_stats(array $rows, ?int $now = null): array
{
    $stats = ['total' => 0, 'active' => 0, 'scheduled' => 0, 'expired' => 0, 'paused' => 0, 'draft' => 0,
              'impressions' => 0, 'clicks' => 0, 'coupon_copies' => 0, 'dismissals' => 0, 'ctr' => 0.0];
    foreach ($rows as $row) {
        $stats['total']++;
        $state = rg_popup_status($row, $now);
        if (isset($stats[$state])) $stats[$state]++;
        $stats['impressions']   += (int)($row['impressions'] ?? 0);
        $stats['clicks']        += (int)($row['clicks'] ?? 0);
        $stats['coupon_copies'] += (int)($row['coupon_copies'] ?? 0);
        $stats['dismissals']    += (int)($row['dismissals'] ?? 0);
    }
    $stats['ctr'] = $stats['impressions'] > 0
        ? round($stats['clicks'] / $stats['impressions'] * 100, 1)
        : 0.0;
    return $stats;
}

/** Click-through rate for one campaign row. */
function rg_popup_ctr(array $row): float
{
    $impressions = (int)($row['impressions'] ?? 0);
    $clicks = (int)($row['clicks'] ?? 0);
    return $impressions > 0 ? round($clicks / $impressions * 100, 1) : 0.0;
}

/* ------------------------------------------------ visitor & frequency memory */

/**
 * The opaque key that ties a browser to its dismissals.
 *
 * It is a random 64-hex value in a long-lived, HttpOnly, SameSite=Lax cookie.
 * Only a SHA-256 hash is written to the database, so the row cannot be traced
 * back to the cookie if the database alone is exposed. No IP address, user agent
 * or other personal data is stored.
 */
function rg_popup_visitor_key(): string
{
    static $key = null;
    if ($key !== null) return $key;

    $raw = (string)($_COOKIE[RG_POPUP_VISITOR_COOKIE] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $raw)) {
        try {
            $raw = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            $raw = hash('sha256', uniqid((string)mt_rand(), true) . '|popup');
        }

        if (!headers_sent()) {
            $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
                || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
                || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
            setcookie(RG_POPUP_VISITOR_COOKIE, $raw, [
                'expires'  => time() + RG_POPUP_VISITOR_DAYS * 86400,
                'path'     => '/',
                'secure'   => $https,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE[RG_POPUP_VISITOR_COOKIE] = $raw;
    }

    return $key = hash('sha256', $raw . '|reviewgrow-popup');
}

/** The stored view row for this visitor and campaign, or null. */
function rg_popup_view_row(int $popupId): ?array
{
    global $conn;
    try {
        $stmt = $conn->prepare('SELECT * FROM popup_views WHERE popup_id = ? AND visitor_key = ? LIMIT 1');
        $stmt->execute([$popupId, rg_popup_visitor_key()]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;   // migration not run yet: fall back to the browser's memory
    }
}

/**
 * Has this visitor already had their allowance for this campaign?
 *
 * `always` never suppresses. `session` uses the PHP session (server-side, so it
 * survives a refresh and other tabs in the same browser session). The longer
 * windows use the popup_views row, which survives a browser restart and a cleared
 * localStorage.
 */
function rg_popup_seen_recently(array $row, ?int $now = null): bool
{
    $id = (int)($row['id'] ?? 0);
    if ($id <= 0) return false;

    $frequency = (string)($row['frequency'] ?? 'session');
    if ($frequency === 'always') return false;

    if ($frequency === 'session') {
        if (session_status() !== PHP_SESSION_ACTIVE) rg_start_session();
        return !empty($_SESSION['rg_popup_seen'][$id]);
    }

    $view = rg_popup_view_row($id);
    if (!$view) return false;

    $last = rg_popup_row_time($view['last_shown_at'] ?? '', rg_popup_default_timezone());
    if ($last === null) return false;

    $now = $now ?? time();
    if ($frequency === 'campaign') return true;
    if ($frequency === '7d') return ($now - $last) < 7 * 86400;
    return ($now - $last) < 86400;
}

/**
 * Records one event, once per kind per visitor session for the aggregate.
 *
 * The aggregate counters live on popups (what the dashboard shows); popup_views
 * keeps the per-visitor timestamps frequency needs. Both are written here so the
 * two can never disagree about what happened.
 *
 * @return array{ok: bool, counted: bool}
 */
function rg_popup_record_event(array $popup, string $kind): array
{
    global $conn;

    $kinds = rg_popup_event_kinds();
    if (!isset($kinds[$kind])) return ['ok' => false, 'counted' => false];

    $id = (int)($popup['id'] ?? 0);
    if ($id <= 0) return ['ok' => false, 'counted' => false];

    if (session_status() !== PHP_SESSION_ACTIVE) rg_start_session();
    if (!isset($_SESSION['rg_popup_counted']) || !is_array($_SESSION['rg_popup_counted'])) {
        $_SESSION['rg_popup_counted'] = [];
    }
    $seen = $_SESSION['rg_popup_counted'][$id] ?? [];
    if (!is_array($seen)) $seen = [];

    /* A reload or a double-fired event must not multiply the dashboard. */
    if (!empty($seen[$kind])) {
        return ['ok' => true, 'counted' => false];
    }

    $column = $kinds[$kind];
    $now = date('Y-m-d H:i:s');
    $impressions   = $kind === 'impression' ? 1 : 0;
    $clicks        = $kind === 'click' ? 1 : 0;
    $couponCopies  = $kind === 'coupon' ? 1 : 0;
    $dismissals    = $kind === 'dismiss' ? 1 : 0;
    $lastShown     = $kind === 'impression' ? $now : null;
    $dismissedAt   = $kind === 'dismiss' ? $now : null;

    try {
        $stmt = $conn->prepare("UPDATE popups SET {$column} = {$column} + 1 WHERE id = ?");
        $stmt->execute([$id]);
    } catch (Throwable $e) {
        return ['ok' => false, 'counted' => false];
    }

    /* The per-visitor row is best-effort: an install that has not run the new
       migration still counts impressions on the campaign itself. */
    try {
        $key = rg_popup_visitor_key();
        $stmt = $conn->prepare(
            "INSERT INTO popup_views
                (popup_id, visitor_key, impressions, clicks, coupon_copies, dismissals,
                 first_seen_at, last_shown_at, dismissed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                impressions   = impressions + VALUES(impressions),
                clicks        = clicks + VALUES(clicks),
                coupon_copies = coupon_copies + VALUES(coupon_copies),
                dismissals    = dismissals + VALUES(dismissals),
                first_seen_at = COALESCE(first_seen_at, VALUES(first_seen_at)),
                last_shown_at = COALESCE(VALUES(last_shown_at), last_shown_at),
                dismissed_at  = COALESCE(VALUES(dismissed_at), dismissed_at)"
        );
        $stmt->execute([$id, $key, $impressions, $clicks, $couponCopies, $dismissals, $now, $lastShown, $dismissedAt]);
    } catch (Throwable $e) {
        /* Keep the aggregate counter; frequency falls back to the browser. */
    }

    if ($kind === 'impression') {
        $_SESSION['rg_popup_seen'][$id] = time();
    }

    $seen[$kind] = time();
    $_SESSION['rg_popup_counted'][$id] = $seen;

    return ['ok' => true, 'counted' => true];
}

/* ------------------------------------------------------------------ select */

/**
 * The one campaign to show on this page, or null.
 *
 * SQL decides what is a candidate (published + has an artwork); PHP then applies
 * the timezone-aware schedule, the page targeting and this visitor's frequency.
 * Only one campaign is ever returned, so two active campaigns can never stack.
 */
function rg_popup_active_for(string $page): ?array
{
    global $conn;
    if ($page === '') return null;

    try {
        $stmt = $conn->query("SELECT * FROM popups
            WHERE status = 'active'
            ORDER BY updated_at DESC, id DESC");
        $rows = $stmt->fetchAll();
    } catch (Exception $e) {
        return null;
    }

    foreach ((array)$rows as $row) {
        if (rg_popup_status($row) !== 'active') continue;
        if (!rg_popup_targets_match((string)($row['targets'] ?? ''), $page)) continue;
        if ((string)($row['layout'] ?? '') !== 'announcement'
            && rg_popup_image_url((string)($row['image'] ?? '')) === '') continue;
        if (rg_popup_seen_recently($row)) continue;
        return $row;
    }
    return null;
}

/* ------------------------------------------------------------------ render */

/**
 * Prints one popup: the dialog itself plus its configuration.
 *
 * `hidden` is in the markup, so an unopened popup can never flash on the page and
 * never blocks a click. The script decides whether and when to show it.
 */
function rg_popup_render(array $popup, string $page = '', bool $analytics = true): void
{
    $imageUrl = rg_popup_image_url((string)($popup['image'] ?? ''));
    $layout   = (string)($popup['layout'] ?? 'image_text');
    /* Only the announcement layout can stand on its own without artwork. */
    if ($imageUrl === '' && $layout !== 'announcement') return;

    $heading     = (string)($popup['heading'] ?? '');
    $description = (string)($popup['description'] ?? '');
    $coupon      = (string)($popup['coupon_code'] ?? '');
    $ctaEnabled  = (int)($popup['cta_enabled'] ?? 0) === 1;
    $ctaText     = (string)($popup['cta_text'] ?? '');
    $ctaHref     = rg_popup_cta_resolve((string)($popup['cta_url'] ?? ''));
    $ctaNewTab   = (int)($popup['cta_new_tab'] ?? 1) === 1;

    $hasBody  = in_array($layout, ['image_text', 'image_cta', 'announcement'], true)
                || $coupon !== '' || ($ctaEnabled && $ctaHref !== '');
    $showText = in_array($layout, ['image_text', 'announcement'], true);
    $showCta  = $ctaEnabled && $ctaHref !== '' && $ctaText !== '';

    $headingId = 'rgPopHeading';
    $descId    = 'rgPopDesc';
    $ariaLabel = $heading !== '' ? '' : ' aria-label="Promotion"';
    ?>
<div class="rg-pop" id="rgPopup" hidden
     data-popup-id="<?= (int)$popup['id'] ?>"
     data-page="<?= e($page) ?>"
     data-trigger="<?= e((string)($popup['trigger_type'] ?? 'delay')) ?>"
     data-trigger-value="<?= (int)($popup['trigger_value'] ?? 3) ?>"
     data-frequency="<?= e((string)($popup['frequency'] ?? 'session')) ?>"
     data-coupon="<?= e($coupon) ?>"
     data-analytics="<?= $analytics ? '1' : '0' ?>"
     data-event-url="<?= $analytics ? e(url('popup_event.php')) : '' ?>">
  <div class="rg-pop-backdrop" data-rg-pop-close="1"></div>

  <div class="rg-pop-card rg-pop-card--<?= e($layout) ?>"
       role="dialog" aria-modal="true"
       <?= $heading !== '' ? 'aria-labelledby="' . $headingId . '"' : $ariaLabel ?>
       <?= $description !== '' ? 'aria-describedby="' . $descId . '"' : '' ?>>

    <button class="rg-pop-close" type="button" data-rg-pop-close="1" aria-label="Close this offer">×</button>

    <?php if ($imageUrl !== ''): ?>
    <figure class="rg-pop-media">
      <img src="<?= e($imageUrl) ?>" alt="<?= e($heading !== '' ? $heading : 'Promotion') ?>"
           loading="lazy" decoding="async" data-rg-pop-image="1">
      <span class="rg-pop-media-fallback" id="rgPopMediaFallback" hidden aria-hidden="true">
        <span class="rg-pop-media-mark">✳</span>
        <span class="rg-pop-media-brand">ReviewAI</span>
        <span class="rg-pop-media-note">Offer details below</span>
      </span>
    </figure>
    <?php endif; ?>

    <?php if ($hasBody): ?>
    <div class="rg-pop-body">
      <?php if ($showText && $heading !== ''): ?>
      <h2 class="rg-pop-heading" id="<?= $headingId ?>"><?= e($heading) ?></h2>
      <?php endif; ?>
      <?php if ($showText && $description !== ''): ?>
      <p class="rg-pop-text" id="<?= $descId ?>"><?= e($description) ?></p>
      <?php endif; ?>

      <?php if ($coupon !== ''): ?>
      <div class="rg-pop-coupon">
        <span class="rg-pop-coupon-label">Coupon code</span>
        <div class="rg-pop-coupon-row">
          <code id="rgPopCoupon"><?= e($coupon) ?></code>
          <button class="rg-pop-copy" type="button" id="rgPopCopy" data-copy-target="rgPopCoupon">
            Copy code
          </button>
        </div>
        <span class="rg-pop-copied" id="rgPopCopied" role="status" aria-live="polite" hidden>Copied to your clipboard</span>
      </div>
      <?php endif; ?>

      <?php if ($showCta): ?>
      <a class="rg-pop-cta" id="rgPopCta"
         href="<?= e($ctaHref) ?>"
         <?= $ctaNewTab ? 'target="_blank" rel="noopener noreferrer"' : '' ?>
         data-rg-pop-cta="1"><?= e($ctaText) ?></a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
    <?php
}

/**
 * The campaign waiting for this page, looked up once per request.
 *
 * The head tag and the body both need the answer, and a page view must not cost
 * two queries. Cached by page name.
 */
function rg_popup_pending(string $page = ''): ?array
{
    static $cache = [];

    $page = $page !== '' ? $page : rg_popup_current_page();
    if (array_key_exists($page, $cache)) return $cache[$page];

    return $cache[$page] = rg_popup_active_for($page);
}

/**
 * The public pages call this once, after the body content.
 *
 * It prints nothing at all when no campaign targets this page — so an ordinary
 * visit to the homepage costs one indexed query and a few bytes.
 */
function print_rg_popup(string $page = ''): void
{
    $page = $page !== '' ? $page : rg_popup_current_page();
    $popup = rg_popup_pending($page);
    if (!$popup) return;

    rg_popup_render($popup, $page);
}

/**
 * The stylesheet + script tags, printed in the page head.
 *
 * Empty when nothing targets this page: a visitor who will not see a popup does
 * not download its CSS or its script either.
 */
function rg_popup_head(string $page = ''): string
{
    if (rg_popup_pending($page) === null) return '';

    return '<link rel="stylesheet" href="' . e(asset('css/popup.css')) . '">' . "\n"
         . '<script defer src="' . e(asset('js/popup.js')) . '"></script>';
}
