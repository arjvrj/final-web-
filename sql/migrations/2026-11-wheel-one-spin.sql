-- 2026-11-wheel-one-spin.sql
--
-- Run this ONCE on an existing database to switch the wheel to the one-spin
-- system: one customer, one spin, per campaign.
--
-- A fresh install does not need this file — install.php and sql/schema.sql
-- already create everything in this shape.
--
-- What it adds:
--   wheel_participations — the customer's participation record for a campaign:
--                          whether they are eligible, whether they already spun,
--                          the prize they got and when.
--
-- wheel_spins keeps the spin itself (token, prize snapshot, coupon). The two are
-- linked through participation.spin_id.

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

-- A spin no longer needs a feedback row, and each spin belongs to one visitor.
-- A fresh install already has all of this. On an older database, run these once;
-- "duplicate column/key" errors on a second run are harmless.
ALTER TABLE wheel_spins MODIFY feedback_id INT NULL;
ALTER TABLE wheel_spins ADD COLUMN session_key VARCHAR(64) NULL AFTER token;
ALTER TABLE wheel_spins ADD UNIQUE KEY uniq_wheel_session (campaign_id, session_key, spin_no);

-- Existing rows from the old "one spin per feedback" design have no session_key.
-- Give each one a stable key derived from its feedback id, so the unique key
-- above has something to enforce and the old history stays visible.
UPDATE wheel_spins
   SET session_key = SHA2(CONCAT('legacy-', id, '-', card_id), 256)
 WHERE session_key IS NULL OR session_key = '';
