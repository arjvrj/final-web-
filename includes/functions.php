<?php
/**
 * Core helper functions — settings, URL, keywords, AI review generation.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/links.php';

/* =========================================================
   SETTINGS (key/value in `settings` table, with in-memory cache)
   ========================================================= */

/**
 * The per-request settings cache.
 *
 * It is a function-static so every caller shares one copy, and it is reachable
 * through rg_forget_setting() so a write can clear it. Without that, a page that
 * saves a setting and then reads it back in the same request (the owner panel
 * does exactly that to show the new preview) would show the old value.
 */
function &rg_settings_cache() {
    static $cache = [];
    return $cache;
}

function rg_forget_setting($key) {
    $cache = &rg_settings_cache();
    unset($cache[$key]);
}

function getSetting($key, $default = null) {
    $cache = &rg_settings_cache();

    if (array_key_exists($key, $cache)) {
        return $cache[$key] === null ? $default : $cache[$key];
    }

    global $conn;
    try {
        $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        $cache[$key] = ($val === false) ? null : $val;
    } catch (Exception $e) {
        $cache[$key] = null;
    }

    return $cache[$key] === null ? $default : $cache[$key];
}

function setSetting($key, $value) {
    global $conn;
    $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([$key, (string)$value]);
    /* The write must be visible to the rest of this request too. */
    rg_forget_setting($key);
    return true;
}

function getAllSettings() {
    global $conn;
    $rows = $conn->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
    $out = [];
    foreach ($rows as $r) $out[$r['setting_key']] = $r['setting_value'];
    return $out;
}

/* =========================================================
   OWNER / ADMIN HELPERS
   ========================================================= */

function isOwnerEmail($email) {
    $owner = getSetting('owner_email', '');
    if ($owner === '' || $email === '') return false;
    return strcasecmp(trim($email), trim($owner)) === 0;
}

/* =========================================================
   URL / ASSET HELPERS
   ========================================================= */

function baseUrl() {
    $url = getSetting('site_url', '');
    if ($url === '') {
        $cfg = getConfig();
        $url = $cfg['site_url'] ?? '';
    }
    return rtrim($url, '/');
}

/**
 * url('login.php')   → https://site.com/login.php
 * url('/')           → https://site.com
 * url('assets/x.png')→ https://site.com/assets/x.png
 */
function url($path = '') {
    $base = baseUrl();
    $path = (string)$path;

    if (preg_match('#^https?://#i', $path)) return $path;

    if ($path === '' || $path === '/') return $base;

    return $base . '/' . ltrim($path, '/');
}

function asset($path) {
    return url('assets/' . ltrim($path, '/'));
}

function redirect($path) {
    header('Location: ' . (preg_match('#^https?://#i', $path) ? $path : url($path)));
    exit;
}

/* =========================================================
   STRING / SLUG HELPERS
   ========================================================= */

function slugify($text) {
    $text = strtolower(trim((string)$text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim($text, '-');
    return $text === '' ? 'card' : $text;
}

function uniqueCardSlug($name, $excludeId = null) {
    global $conn;
    $base = slugify($name);
    $slug = $base;
    $i = 2;

    while (true) {
        if ($excludeId) {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM review_cards WHERE slug = ? AND id != ?");
            $stmt->execute([$slug, $excludeId]);
        } else {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM review_cards WHERE slug = ?");
            $stmt->execute([$slug]);
        }
        if ((int)$stmt->fetchColumn() === 0) return $slug;
        $slug = $base . '-' . $i++;
        if ($i > 1000) return $base . '-' . time();
    }
}

function e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* =========================================================
   KEYWORDS
   ========================================================= */

function getKeywords() {
    global $conn;
    try {
        $rows = $conn->query("SELECT keyword FROM keywords ORDER BY sort_order ASC, id ASC")->fetchAll();
        return array_column($rows, 'keyword');
    } catch (Exception $e) {
        return [];
    }
}

function saveKeywords(array $keywords) {
    global $conn;
    $conn->exec("DELETE FROM keywords");
    $stmt = $conn->prepare("INSERT INTO keywords (keyword, sort_order) VALUES (?, ?)");
    $i = 0;
    foreach ($keywords as $kw) {
        $kw = trim((string)$kw);
        if ($kw === '') continue;
        $stmt->execute([$kw, $i++]);
    }
}

/* =========================================================
   AI REVIEW GENERATION (GROQ)
   ========================================================= */

/**
 * Calls Groq's chat completion endpoint to generate review texts.
 * Returns an array of strings. On failure returns [] and logs last error.
 */
function callGroq($prompt, $maxTokens = 800, $temperature = 0.8) {
    /* Thin wrapper kept for compatibility. All AI traffic now goes through
       includes/ai.php, so the provider, endpoint, model, timeouts and error
       codes are decided in exactly one place. */
    $cfg = rg_ai_config(rg_ai_settings_from_db());

    $chat = rg_ai_chat($cfg, [
        ['role' => 'system', 'content' => 'You are a helpful assistant that writes authentic-sounding customer reviews. Return ONLY valid JSON, no markdown fences, no extra text.'],
        ['role' => 'user',   'content' => (string)$prompt],
    ], ['max_tokens' => (int)$maxTokens, 'temperature' => (float)$temperature]);

    if (!$chat['ok']) {
        $GLOBALS['groq_last_error'] = $chat['message'];
        return null;
    }

    $decoded = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', trim($chat['content']))), true);
    if (!is_array($decoded)) {
        $GLOBALS['groq_last_error'] = 'Could not parse the AI JSON output.';
        return null;
    }

    return $decoded;
}

/**
 * Generate review strings for a business given keywords.
 * Returns array of plain-text review bodies.
 */
function generateAIReviews($businessName, array $keywords = [], $count = 5) {
    $businessName = trim((string)$businessName) ?: 'this business';
    $keywords = array_values(array_filter(array_map('trim', $keywords)));

    $kwText = $keywords ? implode(', ', $keywords) : 'quality, service, experience';

    $prompt = "Write {$count} unique, natural-sounding, positive customer reviews for a business called \"{$businessName}\".\n"
            . "Each review must:\n"
            . "- be 1-3 sentences (25-60 words)\n"
            . "- sound like a real customer (vary tone, add small specifics)\n"
            . "- naturally include 1-2 of these keywords: {$kwText}\n"
            . "- be written in first person (\"I\", \"we\")\n"
            . "- avoid repeating sentence openers across reviews\n"
            . "- NOT include star ratings or emojis\n\n"
            . "Return strict JSON in this exact shape: {\"reviews\":[\"…\",\"…\"]}";

    $result = callGroq($prompt, 900, 0.85);

    if (!is_array($result) || empty($result['reviews']) || !is_array($result['reviews'])) {
        return [];
    }

    $out = [];
    foreach ($result['reviews'] as $r) {
        if (is_string($r) && trim($r) !== '') {
            $out[] = trim($r);
        }
    }
    return $out;
}

/* =========================================================
   REVIEW CARDS
   ========================================================= */

function getCardBySlug($slug) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM review_cards WHERE slug = ? LIMIT 1");
    $stmt->execute([$slug]);
    return $stmt->fetch() ?: null;
}

function getCardById($id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM review_cards WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$id]);
    return $stmt->fetch() ?: null;
}

function getCardsByUser($userId) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM review_cards WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([(int)$userId]);
    return $stmt->fetchAll();
}

function decodeDetails($card) {
    if (empty($card['details'])) return [];
    $d = json_decode($card['details'], true);
    return is_array($d) ? $d : [];
}

/* =========================================================
   ANALYTICS HELPERS
   ========================================================= */

function getCardStats($cardId) {
    global $conn;
    $stmt = $conn->prepare("SELECT COUNT(*) AS total, AVG(rating) AS avg_rating
        FROM reviews WHERE card_id = ?");
    $stmt->execute([(int)$cardId]);
    $row = $stmt->fetch() ?: ['total' => 0, 'avg_rating' => 0];

    $stmt = $conn->prepare("SELECT COUNT(*) FROM feedbacks WHERE card_id = ? AND status = 'new'");
    $stmt->execute([(int)$cardId]);
    $newFeedback = (int)$stmt->fetchColumn();

    return [
        'total_reviews'   => (int)$row['total'],
        'avg_rating'      => round((float)$row['avg_rating'], 2),
        'new_feedbacks'   => $newFeedback,
    ];
}

function getRatingBreakdown($cardId) {
    global $conn;
    $stmt = $conn->prepare("SELECT rating, COUNT(*) AS c FROM reviews WHERE card_id = ? GROUP BY rating");
    $stmt->execute([(int)$cardId]);
    $out = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int)$row['rating']] = (int)$row['c'];
    }
    return $out;
}

/* =========================================================
   MISC
   ========================================================= */

/**
 * Shortens text for a preview without splitting a multi-byte character.
 * mbstring is common but not guaranteed, so this falls back to a UTF-8 aware
 * regex. Both paths return whole characters only.
 */
function excerpt(string $text, int $limit = 140): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if ($text === '') return '';

    if (function_exists('mb_substr')) {
        if (mb_strlen($text, 'UTF-8') <= $limit) return $text;
        return rtrim(mb_substr($text, 0, $limit, 'UTF-8')) . '…';
    }

    if (preg_match('/^.{0,' . $limit . '}/us', $text, $m) && $m[0] !== $text) {
        return rtrim($m[0]) . '…';
    }
    return $text;
}

function timeAgo($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', strtotime($datetime));
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

