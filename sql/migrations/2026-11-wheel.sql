-- ReviewGrow — Spin the Wheel migration
--
-- Run this once on an existing install (phpMyAdmin → your database → SQL → paste
-- → Go). A fresh install already has these tables: install.php creates them.
--
-- Safe to run twice: every statement is CREATE TABLE IF NOT EXISTS.
--
-- Four tables:
--   wheel_campaigns  one per review card: on/off, dates, coupon expiry, cap
--   wheel_segments   the seven prizes, their kind and their integer odds
--   wheel_spins      one row per feedback submission (UNIQUE feedback_id = one spin)
--   wheel_coupons    a real coupon per discount win, with an expiry date

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

-- The seven prizes, in the order the wheel draws them, with the specified odds.
-- 100000000 = 100%, so 10% = 10000000 and 0.001% = 1000. Edit them in the
-- dashboard, not here: this block only seeds a campaign that has no segments yet.
--
-- After you enable the wheel once in Dashboard → Rewards, the row already exists,
-- so you normally do not need to run the INSERT below by hand.
