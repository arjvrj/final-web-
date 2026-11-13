-- 2026-11-popups.sql
--
-- Adds the Popup & Promotion Manager. Safe to run more than once: the table is
-- created only if it does not exist, and existing tables are untouched.
--
--   mysql -u USER -p DATABASE < sql/migrations/2026-11-popups.sql
--
-- Fresh installs need nothing: install.php creates this table.

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
