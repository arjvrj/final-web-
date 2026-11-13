-- ReviewGrow · Stage 7 — Razorpay orders, webhook idempotency, one-trial-per-account
-- All statements are additive: nothing is dropped, renamed or narrowed.
-- Safe to run twice (IF NOT EXISTS / column check).

-- 1. Payments / transaction history -----------------------------------------
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
    KEY idx_status (status),
    CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_payments_card FOREIGN KEY (card_id) REFERENCES review_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Webhook idempotency -----------------------------------------------------
CREATE TABLE IF NOT EXISTS webhook_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(128) NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    signature_ok TINYINT(1) NOT NULL DEFAULT 0,
    environment ENUM('test','live') NOT NULL DEFAULT 'test',
    payload MEDIUMTEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_event (event_id),
    KEY idx_type (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. One 3-day trial per business account ------------------------------------
ALTER TABLE users ADD COLUMN trial_started_at DATETIME DEFAULT NULL;

-- Backfill: any account that already has a card on trial has used its trial.
UPDATE users u
SET u.trial_started_at = COALESCE(
        (SELECT MIN(rc.created_at) FROM review_cards rc WHERE rc.user_id = u.id AND rc.membership = 'trial'),
        u.created_at
    )
WHERE u.trial_started_at IS NULL
  AND EXISTS (SELECT 1 FROM review_cards rc2 WHERE rc2.user_id = u.id AND rc2.membership = 'trial');

-- 4. Payment settings, shipped switched off ----------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
    ('payments_mode', 'disabled'),
    ('razorpay_key_id', ''),
    ('razorpay_key_secret', ''),
    ('razorpay_webhook_secret', ''),
    ('razorpay_timeout', '15')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
