<?php
/**
 * Installation Wizard — Owner-first
 * Step 1: Welcome
 * Step 2: Database + App info (+ writes config.php with owner_email placeholder)
 * Step 3: Owner account (email + password) — also creates the first user row
 * Step 4: Done
 *
 * No .env, no .htaccess, no separate admin account.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/includes/security.php';
rg_start_session();

$configFile = __DIR__ . '/config.php';
$step   = (int)($_GET['step'] ?? 1);
$errors = [];
$emailTaken = false;   /* the typed email already belongs to a user */

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function detectSiteUrl() {
    $https = false;
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') $https = true;
    elseif (($_SERVER['SERVER_PORT'] ?? '') == 443) $https = true;
    elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') $https = true;

    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $dir    = rtrim($dir, '/');
    if ($dir === '.' || $dir === '/') $dir = '';

    return $scheme . '://' . $host . $dir;
}

/**
 * Where the wizard goes when config.php already says "installed".
 * Returns a Location target, or null to let the current step render.
 *
 * Extracted so the rules can be tested. The old inline guard returned
 * 'install.php?step=3' even when the request was FOR step 3, so a config.php
 * with 'installed' => true and no matching owner row redirected step 3 to
 * itself forever (ERR_TOO_MANY_REDIRECTS).
 */
function rg_install_redirect_target(int $step, bool $ownerExists, string $ownerEmail = ''): ?string

{

    if ($step === 4) return null;          /* the "done" page is always allowed */

    if ($ownerExists) return 'login.php';  /* installed + owner row: wizard is dead */

    if ($step !== 3) return 'install.php?step=3';

    /* Step 3. Render the owner form only when the install never recorded an

       owner at all. If an owner_email IS on file but its row is missing, showing

       the form would let whoever opens it rewrite owner_email -- the takeover the

       original guard existed to stop. Send that case to login, never to itself. */

    return $ownerEmail === '' ? null : 'login.php';

}


$autoSiteUrl = detectSiteUrl();

/* ---------- Redirect if already installed ---------- */
if (file_exists($configFile)) {
    $existing = include $configFile;
    /* SECURITY: after a completed install the wizard is dead. Previously only
       steps 1 and 2 were redirected, so a visitor could POST to ?step=3 and
       rewrite owner_email — a full takeover. Now every step except the "done"
       page is refused as soon as the owner account exists. */
    if (!empty($existing['installed']) && $step !== 4) {
        $ownerExists = false;
        try {
            $pdo = new PDO(
                "mysql:host={$existing['db_host']};dbname={$existing['db_name']};charset=utf8mb4",
                $existing['db_user'], $existing['db_pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
            $stmt->execute([$existing['owner_email'] ?? '']);
            $ownerExists = (int)$stmt->fetchColumn() > 0;
        } catch (Exception $e) { $ownerExists = false; }

        $target = rg_install_redirect_target($step, $ownerExists, (string)($existing['owner_email'] ?? ''));
        if ($target !== null) {
            header('Location: ' . $target);
            exit;
        }
        /* $step === 3 with no owner row: fall through and render the owner
           form, or the account could never be created. */
    }
}

/* ---------- STEP 2: DB + App info ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    requireCsrf();
    $dbHost  = trim($_POST['db_host'] ?? 'localhost');
    $dbName  = trim($_POST['db_name'] ?? '');
    $dbUser  = trim($_POST['db_user'] ?? '');
    $dbPass  = $_POST['db_pass'] ?? '';
    $appName = trim($_POST['app_name'] ?? 'ReviewAI');
    $siteUrl = rtrim(trim($_POST['site_url'] ?? $autoSiteUrl), '/');

    if ($dbName === '' || $dbUser === '') $errors[] = 'Database name and user are required.';
    if ($appName === '') $appName = 'ReviewAI';
    if (!preg_match('#^https?://#i', $siteUrl)) $errors[] = 'Site URL must start with http:// or https://';

    if (empty($errors)) {
        try {
            $pdo = new PDO(
                "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
                $dbUser, $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            $tables = [

                "users" => "CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(255) NOT NULL UNIQUE,
                    password VARCHAR(255) NOT NULL,
                    otp VARCHAR(255),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    trial_started_at DATETIME DEFAULT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                "review_cards" => "CREATE TABLE IF NOT EXISTS review_cards (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    slug VARCHAR(255) NOT NULL UNIQUE,
                    membership VARCHAR(50) DEFAULT 'trial',
                    expiry_date DATETIME DEFAULT NULL,
                    details JSON DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    INDEX idx_slug (slug)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                "reviews" => "CREATE TABLE IF NOT EXISTS reviews (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    card_id INT NOT NULL,
                    customer_name VARCHAR(255) DEFAULT NULL,
                    rating INT NOT NULL,
                    body TEXT DEFAULT NULL,
                    details JSON DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_card_id (card_id),
                    INDEX idx_rating (rating),
                    FOREIGN KEY (card_id) REFERENCES review_cards(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                "feedbacks" => "CREATE TABLE IF NOT EXISTS feedbacks (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    card_id INT NOT NULL,
                    customer_name VARCHAR(255) NOT NULL,
                    phone VARCHAR(20) NOT NULL,
                    email VARCHAR(255) NOT NULL,
                    message TEXT NOT NULL,
                    rating INT NOT NULL,
                    status ENUM('new','read','resolved') DEFAULT 'new',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_card_id (card_id),
                    INDEX idx_status (status),
                    FOREIGN KEY (card_id) REFERENCES review_cards(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                "platform_settings" => "CREATE TABLE IF NOT EXISTS platform_settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    whatsapp VARCHAR(20) NOT NULL,
                    details JSON DEFAULT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                "plans" => "CREATE TABLE IF NOT EXISTS plans (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    description TEXT,
                    price DECIMAL(10,2) NOT NULL,
                    sale_price DECIMAL(10,2) DEFAULT NULL,
                    features JSON,
                    cta VARCHAR(255),
                    duration INT DEFAULT 30,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                "keywords" => "CREATE TABLE IF NOT EXISTS keywords (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    keyword VARCHAR(100) NOT NULL,
                    sort_order INT DEFAULT 0
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                "settings" => "CREATE TABLE IF NOT EXISTS settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    setting_key VARCHAR(100) UNIQUE NOT NULL,
                    setting_value TEXT,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "payments" => "CREATE TABLE IF NOT EXISTS payments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    card_id INT NOT NULL,
                    plan_id INT DEFAULT NULL,
                    razorpay_order_id VARCHAR(64) DEFAULT NULL,
                    razorpay_payment_id VARCHAR(64) DEFAULT NULL,
                    razorpay_signature VARCHAR(128) DEFAULT NULL,
                    amount_paise INT NOT NULL,
                    currency CHAR(3) NOT NULL DEFAULT 'INR',
                    status ENUM('created','paid','failed','cancelled') NOT NULL DEFAULT 'created',
                    environment ENUM('test','live','manual') NOT NULL DEFAULT 'test',
                    channel ENUM('razorpay','whatsapp') NOT NULL DEFAULT 'razorpay',
                    paid_at DATETIME DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_payment (razorpay_payment_id),
                    UNIQUE KEY uniq_order (razorpay_order_id),
                    KEY idx_user (user_id),
                    KEY idx_card (card_id),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY (card_id) REFERENCES review_cards(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                "wheel_campaigns" => "CREATE TABLE IF NOT EXISTS wheel_campaigns (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    card_id INT NOT NULL,
                    enabled TINYINT(1) NOT NULL DEFAULT 0,
                    starts_at DATE DEFAULT NULL,
                    ends_at DATE DEFAULT NULL,
                    coupon_expiry_days INT NOT NULL DEFAULT 30,
                    inventory_limit INT NOT NULL DEFAULT 0,
                    redemption_note VARCHAR(255) NOT NULL DEFAULT '',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_wheel_card (card_id),
                    FOREIGN KEY (card_id) REFERENCES review_cards(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                "wheel_segments" => "CREATE TABLE IF NOT EXISTS wheel_segments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    campaign_id INT NOT NULL,
                    slug VARCHAR(40) NOT NULL,
                    label VARCHAR(120) NOT NULL,
                    kind ENUM('discount','better_luck','thanks') NOT NULL DEFAULT 'discount',
                    discount_percent DECIMAL(5,2) DEFAULT NULL,
                    weight BIGINT NOT NULL DEFAULT 0,
                    inventory_limit INT NOT NULL DEFAULT 0,
                    sort_order INT NOT NULL DEFAULT 0,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    UNIQUE KEY uniq_wheel_segment (campaign_id, slug),
                    KEY idx_wheel_segment_campaign (campaign_id),
                    FOREIGN KEY (campaign_id) REFERENCES wheel_campaigns(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                "wheel_spins" => "CREATE TABLE IF NOT EXISTS wheel_spins (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    card_id INT NOT NULL,
                    campaign_id INT NOT NULL,
                    feedback_id INT NULL,
                    token CHAR(64) NOT NULL,
    session_key VARCHAR(64) NULL,
    spin_no TINYINT NOT NULL DEFAULT 1,
    forced TINYINT NOT NULL DEFAULT 0,
                    status ENUM('pending','spinning','spun','void') NOT NULL DEFAULT 'pending',
                    segment_id INT DEFAULT NULL,
                    segment_label VARCHAR(120) DEFAULT NULL,
                    segment_kind ENUM('discount','better_luck','thanks') DEFAULT NULL,
                    discount_percent DECIMAL(5,2) DEFAULT NULL,
                    coupon_id INT DEFAULT NULL,
                    ip_hash CHAR(64) NOT NULL DEFAULT '',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    spun_at DATETIME DEFAULT NULL,
                    UNIQUE KEY uniq_wheel_feedback (feedback_id),
    UNIQUE KEY uniq_wheel_session (campaign_id, session_key, spin_no),
                    UNIQUE KEY uniq_wheel_token (token),
                    KEY idx_wheel_spin_card (card_id),
                    FOREIGN KEY (card_id) REFERENCES review_cards(id) ON DELETE CASCADE,
                    FOREIGN KEY (feedback_id) REFERENCES feedbacks(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                "wheel_coupons" => "CREATE TABLE IF NOT EXISTS wheel_coupons (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    spin_id INT NOT NULL,
                    card_id INT NOT NULL,
                    code VARCHAR(24) NOT NULL,
                    discount_percent DECIMAL(5,2) NOT NULL,
                    expires_at DATE NOT NULL,
                    redeemed_at DATETIME DEFAULT NULL,
                    redeemed_note VARCHAR(255) NOT NULL DEFAULT '',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_wheel_code (code),
                    UNIQUE KEY uniq_wheel_spin (spin_id),
                    KEY idx_wheel_coupon_card (card_id),
                    FOREIGN KEY (card_id) REFERENCES review_cards(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "wheel_participations" => "CREATE TABLE IF NOT EXISTS wheel_participations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    card_id INT NOT NULL,
    campaign_id INT NOT NULL,
    participant_key CHAR(64) NOT NULL,
    eligible TINYINT NOT NULL DEFAULT 0,
    eligible_at DATETIME NULL,
    eligible_source VARCHAR(32) NULL,
    feedback_id INT NULL,
    spin_id INT NULL,
    spun_at DATETIME NULL,
    prize_label VARCHAR(80) NULL,
    discount_percent DECIMAL(5,2) NULL,
    coupon_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uniq_participation (card_id, participant_key),
    KEY idx_participation_campaign (campaign_id),
    CONSTRAINT fk_participation_card FOREIGN KEY (card_id) REFERENCES review_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                "popups" => "CREATE TABLE IF NOT EXISTS popups (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(120) NOT NULL,
                    status ENUM('draft','active','paused') NOT NULL DEFAULT 'draft',
                    layout ENUM('image','image_text','image_cta','announcement') NOT NULL DEFAULT 'image_text',
                    image VARCHAR(255) NOT NULL DEFAULT '',
                    heading VARCHAR(160) NOT NULL DEFAULT '',
                    description VARCHAR(400) NOT NULL DEFAULT '',
                    coupon_code VARCHAR(40) NOT NULL DEFAULT '',
                    cta_enabled TINYINT(1) NOT NULL DEFAULT 1,
                    cta_text VARCHAR(60) NOT NULL DEFAULT '',
                    cta_url VARCHAR(500) NOT NULL DEFAULT '',
                    cta_new_tab TINYINT(1) NOT NULL DEFAULT 1,
                    trigger_type ENUM('immediate','delay','scroll','exit') NOT NULL DEFAULT 'delay',
                    trigger_value INT NOT NULL DEFAULT 3,
                    frequency ENUM('always','session','24h','7d','campaign') NOT NULL DEFAULT 'session',
                    targets VARCHAR(255) NOT NULL DEFAULT 'home',
                    starts_at DATETIME NULL,
                    ends_at DATETIME NULL,
                    timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Kolkata',
                    impressions INT NOT NULL DEFAULT 0,
                    clicks INT NOT NULL DEFAULT 0,
                    coupon_copies INT NOT NULL DEFAULT 0,
                    dismissals INT NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    KEY idx_popup_status (status),
                    KEY idx_popup_starts (starts_at),
                    KEY idx_popup_ends (ends_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "popup_views" => "CREATE TABLE IF NOT EXISTS popup_views (\r\n
                    id INT AUTO_INCREMENT PRIMARY KEY,\r\n
                    popup_id INT NOT NULL,\r\n
                    visitor_key CHAR(64) NOT NULL,\r\n
                    impressions INT NOT NULL DEFAULT 0,\r\n
                    clicks INT NOT NULL DEFAULT 0,\r\n
                    coupon_copies INT NOT NULL DEFAULT 0,\r\n
                    dismissals INT NOT NULL DEFAULT 0,\r\n
                    first_seen_at DATETIME NULL,\r\n
                    last_shown_at DATETIME NULL,\r\n
                    dismissed_at DATETIME NULL,\r\n
                    UNIQUE KEY uniq_popup_visitor (popup_id, visitor_key),\r\n
                    KEY idx_popup_views_popup (popup_id),\r\n
                    CONSTRAINT fk_popup_views_popup FOREIGN KEY (popup_id) REFERENCES popups(id) ON DELETE CASCADE\r\n
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "webhook_events" => "CREATE TABLE IF NOT EXISTS webhook_events (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    event_id VARCHAR(128) NOT NULL,
                    event_type VARCHAR(64) NOT NULL,
                    signature_ok TINYINT(1) NOT NULL DEFAULT 0,
                    environment ENUM('test','live') NOT NULL DEFAULT 'test',
                    payload MEDIUMTEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_event (event_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            ];

            foreach ($tables as $name => $sql) {
                $pdo->exec($sql);
                $check = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($name));
                if (!$check->fetch()) throw new Exception("Failed to create table: {$name}");
            }

            // Seed default settings — owner_email will be filled in step 3
            $defaults = [
                'business_name'        => $appName,
                'business_description' => 'We provide excellent service!',
                'business_logo'        => '',
                'business_banner'      => '',
                'business_address'     => '',
                'business_phone'       => '',
                'business_whatsapp'    => '',
                'business_facebook'    => '',
                'business_instagram'   => '',
                'business_website'     => '',
                /* Empty on purpose: a generic Google search is not this business.
                   The owner sets the real review link per card in card settings. */
                'google_review_url'    => '',
                'theme'                => 'purple',
                'custom_primary'       => '#7c3aed',
                'custom_gradient'      => 'linear-gradient(135deg, #7c3aed 0%, #4f46e5 100%)',
                'enable_keywords'      => '1',
                'groq_api_key'         => '',
                'groq_model'           => 'llama-3.3-70b-versatile',
                'app_name'             => $appName,
                'site_url'             => $siteUrl,
                'owner_email'          => '',
                'installed'            => '1',
                'payments_mode'        => 'disabled',
                'razorpay_key_id'      => '',
                'razorpay_key_secret'  => '',
                'razorpay_webhook_secret' => '',
                'razorpay_timeout'     => '15',
            ];
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            foreach ($defaults as $k => $v) {
                if ($k === 'owner_email') {
                    $chk = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'owner_email'");
                    $chk->execute();
                    if (trim((string)$chk->fetchColumn()) !== '') continue;
                }
                $stmt->execute([$k, $v]);
            }

            // Seed default keywords (only if none exist)
            $existingKw = (int)$pdo->query("SELECT COUNT(*) FROM keywords")->fetchColumn();
            if ($existingKw === 0) {
                $kwStmt = $pdo->prepare("INSERT INTO keywords (keyword, sort_order) VALUES (?, ?)");
                $kws = ['professional','quality','excellent service','friendly staff','clean','affordable','great value','highly recommend'];
                foreach ($kws as $i => $kw) $kwStmt->execute([$kw, $i]);
            }

            // Write config.php (owner_email filled in step 3 — placeholder for now)
            $configContent = "<?php\nreturn " . var_export([
                'db_host'     => $dbHost,
                'db_name'     => $dbName,
                'db_user'     => $dbUser,
                'db_pass'     => $dbPass,
                'site_url'    => $siteUrl,
                'app_name'    => $appName,
                'owner_email' => '',
                'installed'   => true,
            ], true) . ";\n";

            if (file_put_contents($configFile, $configContent) === false) {
                throw new Exception('Could not write config.php — check folder permissions.');
            }

            @mkdir(__DIR__ . '/assets/uploads', 0755, true);

            header('Location: install.php?step=3');
            exit;

        } catch (PDOException $e) {
            /* The driver message names the host and user; keep it in the log only. */
            error_log('install step 2 db error: ' . $e->getMessage());
            $errors[] = 'Database error (code ' . $e->getCode() . '). Check the host, database name, user and password.';
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
}

/* ---------- STEP 3: Owner account (email + password) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 3) {
    requireCsrf();
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        if (!file_exists($configFile)) {
            $errors[] = 'config.php missing. Restart from step 2.';
        } else {
            $cfg = include $configFile;
            try {
                $pdo = new PDO(
                    "mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4",
                    $cfg['db_user'], $cfg['db_pass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );

                // If a user with this email already exists, refuse (prevents accidental overwrite)
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $emailTaken = true;
                    $errors[] = 'An account with that email already exists. Sign in with it, or use a different email for the owner account.';
                } else {
                    // 1) Insert the owner as a regular user
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $ins = $pdo->prepare("INSERT INTO users (email, password) VALUES (?, ?)");
                    $ins->execute([$email, $hash]);

                    // 2) Store owner_email in settings
                    $upsert = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                    $upsert->execute(['owner_email', $email]);

                    // 3) Rewrite config.php with owner_email filled in
                    $configContent = "<?php\nreturn " . var_export([
                        'db_host'     => $cfg['db_host'],
                        'db_name'     => $cfg['db_name'],
                        'db_user'     => $cfg['db_user'],
                        'db_pass'     => $cfg['db_pass'],
                        'site_url'    => $cfg['site_url'],
                        'app_name'    => $cfg['app_name'] ?? 'ReviewAI',
                        'owner_email' => $email,
                        'installed'   => true,
                    ], true) . ";\n";
                    @file_put_contents($configFile, $configContent);

                    header('Location: install.php?step=4');
                    exit;
                }
            } catch (Exception $e) {
                error_log('install step 3 failed: ' . $e->getMessage());
                $errors[] = 'Database error. Check config.php and try again.';
            }
        }
    }
}

/* ---------- Step 3 pre-check: already have an owner? ---------- */
$ownerExists = false;
if ($step === 3 && file_exists($configFile)) {
    $cfg = include $configFile;
    if (!empty($cfg['installed'])) {
        try {
            $pdo = new PDO(
                "mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4",
                $cfg['db_user'], $cfg['db_pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $email = $cfg['owner_email'] ?? '';
            if ($email !== '') {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                $ownerExists = (bool)$stmt->fetch();
            }
        } catch (Exception $e) {}
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Install — <?= h($appName ?? 'ReviewAI') ?></title>
<link rel="stylesheet" href="assets/css/install.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
</head>
<body class="min-h-screen bg-gradient-to-br from-purple-600 to-indigo-700 flex items-center justify-center p-4">
<div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg p-8">
    <div class="text-center mb-8">
        <div class="w-16 h-16 bg-purple-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
            <i class="fas fa-star text-3xl text-purple-600"></i>
        </div>
        <h1 class="text-2xl font-bold text-gray-900">Installation Wizard</h1>
        <p class="text-gray-500 text-sm mt-1">AI Google Review — Owner Edition</p>
    </div>

    <div class="flex items-center justify-center gap-2 mb-8">
        <?php for ($i = 1; $i <= 4; $i++): ?>
            <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold
                <?= $i <= $step ? 'bg-purple-600 text-white' : 'bg-gray-200 text-gray-500' ?>"><?= $i ?></div>
            <?php if ($i < 4): ?><div class="w-8 h-1 <?= $i < $step ? 'bg-purple-600' : 'bg-gray-200' ?>"></div><?php endif; ?>
        <?php endfor; ?>
    </div>

    <?php if ($errors): ?>
        <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6">
            <?php foreach ($errors as $e): ?>
                <p class="text-red-700 text-sm"><i class="fas fa-exclamation-circle mr-1"></i> <?= h($e) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($step === 1): ?>
        <div class="space-y-4">
            <h2 class="text-lg font-bold text-gray-900">Welcome!</h2>
            <p class="text-gray-600 text-sm">This wizard installs the AI Google Review system. You'll need:</p>
            <ul class="text-sm text-gray-600 space-y-2">
                <li><i class="fas fa-check text-green-500 mr-2"></i> MySQL database credentials</li>
                <li><i class="fas fa-check text-green-500 mr-2"></i> An app name</li>
                <li><i class="fas fa-check text-green-500 mr-2"></i> An owner email & password</li>
            </ul>
            <p class="text-xs text-gray-500 bg-blue-50 border border-blue-100 rounded-lg p-3">
                <i class="fas fa-info-circle text-blue-500 mr-1"></i>
                The owner email you provide grants access to <strong>owner.php</strong>. Other users sign up normally
                and land on their dashboard.
            </p>
            <a href="?step=2" class="block w-full bg-purple-600 hover:bg-purple-700 text-white font-semibold py-3 rounded-xl text-center transition">
                Get Started <i class="fas fa-arrow-right ml-2"></i>
            </a>
        </div>

    <?php elseif ($step === 2): ?>
        <h2 class="text-lg font-bold text-gray-900 mb-4">Database & App Settings</h2>
        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <div class="grid grid-cols-2 gap-3">
                <div class="col-span-2">
                    <label class="text-xs font-medium text-gray-700 block mb-1">Database Host</label>
                    <input type="text" name="db_host" value="<?= h($_POST['db_host'] ?? 'localhost') ?>" required
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 outline-none">
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-700 block mb-1">Database Name</label>
                    <input type="text" name="db_name" value="<?= h($_POST['db_name'] ?? '') ?>" required
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 outline-none">
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-700 block mb-1">Database User</label>
                    <input type="text" name="db_user" value="<?= h($_POST['db_user'] ?? '') ?>" required
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 outline-none">
                </div>
                <div class="col-span-2">
                    <label class="text-xs font-medium text-gray-700 block mb-1">Database Password</label>
                    <input type="password" name="db_pass" value="<?= h($_POST['db_pass'] ?? '') ?>"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 outline-none">
                </div>
                <div class="col-span-2">
                    <label class="text-xs font-medium text-gray-700 block mb-1">App Name</label>
                    <input type="text" name="app_name" value="<?= h($_POST['app_name'] ?? 'ReviewAI') ?>" required
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 outline-none">
                </div>
                <div class="col-span-2">
                    <label class="text-xs font-medium text-gray-700 block mb-1">Site URL</label>
                    <input type="text" name="site_url" value="<?= h($_POST['site_url'] ?? $autoSiteUrl) ?>" required
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 outline-none font-mono text-xs">
                    <p class="text-[11px] text-gray-500 mt-1">Auto-detected. Edit if behind a proxy/CDN.</p>
                </div>
            </div>

            <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-semibold py-3 rounded-xl transition">
                Install <i class="fas fa-database ml-2"></i>
            </button>
        </form>

    <?php elseif ($step === 3): ?>
        <?php if ($ownerExists): ?>
            <div class="text-center space-y-4">
                <h2 class="text-lg font-bold">Owner already exists</h2>
                <a href="login.php" class="block w-full bg-purple-600 text-white font-semibold py-3 rounded-xl text-center">Go to Login</a>
            </div>
        <?php else: ?>
            <h2 class="text-lg font-bold text-gray-900 mb-1">Create Owner Account</h2>
            <p class="text-xs text-gray-500 mb-4">
                This email will have full access to the Owner Panel. Other users sign up normally.
            </p>
            <form method="POST" class="space-y-4">
            <?= csrfField() ?>
                <div>
                    <label class="text-xs font-medium text-gray-700 block mb-1">Email</label>
                    <input type="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 outline-none">
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-700 block mb-1">Password (min 6)</label>
                    <input type="password" name="password" required minlength="6"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 outline-none">
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-700 block mb-1">Confirm Password</label>
                    <input type="password" name="password_confirm" required minlength="6"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 outline-none">
                </div>
                <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-semibold py-3 rounded-xl transition">
                    Create Owner Account <i class="fas fa-crown ml-2"></i>
                </button>
            </form>

            <?php if ($emailTaken): ?>
            <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6 text-xs text-gray-700">
                <p class="font-semibold text-red-700 mb-1">That email already belongs to an account on this site.</p>
                <p class="mb-1">Two safe ways forward &mdash; this attempt changed nothing on your site:</p>
                <p class="mb-1">1. <strong>It is your account:</strong> sign in with it. If it should also be the owner,
                    put the same address in <code>owner_email</code> inside <code>config.php</code> and it becomes the
                    owner-panel account.</p>
                <p class="mb-1">2. <strong>Use a different email:</strong> type another address above and continue.</p>
                <a href="login.php" class="font-semibold text-purple-600">Go to the sign-in page &rarr;</a>
            </div>
            <?php endif; ?>
        <?php endif; ?>

    <?php elseif ($step === 4): ?>
        <div class="text-center space-y-4">
            <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto">
                <i class="fas fa-check text-4xl text-green-600"></i>
            </div>
            <h2 class="text-xl font-bold text-gray-900">Installation Complete!</h2>
            <p class="text-gray-600 text-sm">Your AI Google Review system is ready.</p>
            <div class="bg-blue-50 border border-blue-100 rounded-xl p-3 text-xs text-blue-800 text-left">
                <i class="fas fa-lightbulb mr-1"></i>
                <strong>Next:</strong> Log in with your owner email. Add your <strong>GROQ API key</strong> later in
                <em>Owner Panel → Settings</em>.
            </div>
            <div class="bg-red-50 border border-red-200 rounded-xl p-3 text-xs text-red-800 text-left">
                <i class="fas fa-shield-alt mr-1"></i>
                <strong>Do this now:</strong> delete <code>install.php</code> from your hosting file manager.
                The wizard is already locked (it refuses to run once an owner account exists), but deleting the
                file removes the page entirely.
            </div>
            <a href="index.php" class="block w-full bg-purple-600 hover:bg-purple-700 text-white font-semibold py-3 rounded-xl transition">
                Go to Homepage <i class="fas fa-arrow-right ml-2"></i>
            </a>
        </div>
    <?php endif; ?>
</div>
</body>
</html>