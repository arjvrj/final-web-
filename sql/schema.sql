-- ReviewGrow — complete database schema (fresh install)
--
-- This file mirrors the tables that app/install.php creates, so a new database
-- can be built either by running the installer in a browser or by importing this
-- file and then seeding settings by hand.
--
-- For an EXISTING installation that predates Stage 7, run
-- sql/migrations/2026-11-payments.sql instead — it is additive only.
--
-- Engine: MySQL 5.7+ / MariaDB 10.2+ (utf8mb4)

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------- users
CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(255) NOT NULL UNIQUE,
                    password VARCHAR(255) NOT NULL,
                    otp VARCHAR(255),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    trial_started_at DATETIME DEFAULT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- review_cards
CREATE TABLE IF NOT EXISTS review_cards (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- reviews
CREATE TABLE IF NOT EXISTS reviews (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- feedbacks
CREATE TABLE IF NOT EXISTS feedbacks (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- platform_settings
CREATE TABLE IF NOT EXISTS platform_settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    whatsapp VARCHAR(20) NOT NULL,
                    details JSON DEFAULT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- plans
CREATE TABLE IF NOT EXISTS plans (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    description TEXT,
                    price DECIMAL(10,2) NOT NULL,
                    sale_price DECIMAL(10,2) DEFAULT NULL,
                    features JSON,
                    cta VARCHAR(255),
                    duration INT DEFAULT 30,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- keywords
CREATE TABLE IF NOT EXISTS keywords (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    keyword VARCHAR(100) NOT NULL,
                    sort_order INT DEFAULT 0
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- settings
CREATE TABLE IF NOT EXISTS settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    setting_key VARCHAR(100) UNIQUE NOT NULL,
                    setting_value TEXT,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- payments
CREATE TABLE IF NOT EXISTS payments (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- webhook_events
CREATE TABLE IF NOT EXISTS webhook_events (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    event_id VARCHAR(128) NOT NULL,
                    event_type VARCHAR(64) NOT NULL,
                    signature_ok TINYINT(1) NOT NULL DEFAULT 0,
                    environment ENUM('test','live') NOT NULL DEFAULT 'test',
                    payload MEDIUMTEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_event (event_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Settings that must exist for the application to know itself.
-- owner_email is filled by install.php step 3; payments ship switched OFF.
-- ---------------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
    ('app_name', 'ReviewAI'),
    ('site_url', ''),
    ('owner_email', ''),
    ('installed', '1'),
    ('payments_mode', 'disabled'),
    ('razorpay_key_id', ''),
    ('razorpay_key_secret', ''),
    ('razorpay_webhook_secret', ''),
    ('razorpay_timeout', '15')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- Spin the Wheel (added 2026-11): campaigns, prizes, spins, coupons.
CREATE TABLE IF NOT EXISTS wheel_campaigns (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wheel_segments (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wheel_spins (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wheel_coupons (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wheel_participations (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Popup & Promotion Manager (added 2026-11): owner-authored campaigns that the
-- public marketing pages can show. Counters live on the row; the visitor-side
-- frequency rules live in the browser.
CREATE TABLE IF NOT EXISTS popups (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per visitor per campaign: what they saw, clicked, copied or closed,
-- and when. Frequency windows read last_shown_at from here, so a cleared
-- localStorage no longer resets "once per campaign". visitor_key is a hash of
-- the anonymous rgpv cookie; no personal data is stored.
CREATE TABLE IF NOT EXISTS popup_views (
    id INT AUTO_INCREMENT PRIMARY KEY,
    popup_id INT NOT NULL,
    visitor_key CHAR(64) NOT NULL,
    impressions INT NOT NULL DEFAULT 0,
    clicks INT NOT NULL DEFAULT 0,
    coupon_copies INT NOT NULL DEFAULT 0,
    dismissals INT NOT NULL DEFAULT 0,
    first_seen_at DATETIME NULL,
    last_shown_at DATETIME NULL,
    dismissed_at DATETIME NULL,
    UNIQUE KEY uniq_popup_visitor (popup_id, visitor_key),
    KEY idx_popup_views_popup (popup_id),
    CONSTRAINT fk_popup_views_popup FOREIGN KEY (popup_id) REFERENCES popups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
