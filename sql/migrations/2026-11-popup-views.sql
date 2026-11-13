-- 2026-11-popup-views.sql
--
-- Upgrade an existing database that already ran 2026-11-popups.sql.
-- Adds the campaign timezone, the coupon/dismissal counters, and the
-- per-visitor table the frequency rules read.
--
--   mysql -u USER -p DATABASE < sql/migrations/2026-11-popup-views.sql
--
-- Run once. Re-running only reports "duplicate column" for the three ALTERs,
-- which is harmless; the table itself uses IF NOT EXISTS.

ALTER TABLE popups
    ADD COLUMN timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Kolkata' AFTER ends_at,
    ADD COLUMN coupon_copies INT NOT NULL DEFAULT 0 AFTER clicks,
    ADD COLUMN dismissals INT NOT NULL DEFAULT 0 AFTER coupon_copies;

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

-- Existing campaigns default to the site's timezone; the owner can change it
-- per campaign in Marketing → Popup & Promotions.
