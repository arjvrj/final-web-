-- =============================================================================
-- !! SUPERSEDED — DO NOT RUN THIS FILE !!
-- =============================================================================
-- This was the design from before the payments work was integrated: Razorpay
-- SUBSCRIPTIONS (auto-debit) plus a manual WhatsApp channel, using a
-- `payment_events` table and an `environment` column.
--
-- The shipped application does NOT use subscriptions. Razorpay Subscriptions
-- cannot run on the plans this app sells (the `plans` table has no
-- `razorpay_plan_*` columns), so Stage 7 uses one-time ORDERS instead, with
-- `webhook_events` for idempotency.
--
-- The live schema is:
--     fresh install ......... sql/schema.sql
--     upgrade from Stage 6 .. sql/migrations/2026-11-payments.sql
--
-- Running this file on a fresh database would create a `payments` table with
-- different columns, and CREATE TABLE IF NOT EXISTS would then prevent the
-- correct definition from ever being applied. It is kept only as a record of
-- what was tried. Do not import it.
--
-- The original header follows, unedited.
-- =============================================================================

-- =============================================================================
-- 001_payments.sql — Razorpay subscriptions + WhatsApp manual channel
-- =============================================================================
-- STATUS: **NOT APPLIED**. Run it yourself in phpMyAdmin on Hostinger, or:
--     mysql -u USER -p DBNAME < sql/001_payments.sql
--
-- SAFETY
--   * Additive only. No table is dropped, no column is removed, no row is
--     updated or deleted. Existing data is untouched.
--   * Every new column is NULLable or has a default, so the current app keeps
--     working unchanged even before the new UI is switched on.
--   * Money is stored as INTEGER paise. Never FLOAT — 0.1 + 0.2 problems in
--     billing are silent and expensive.
--   * `environment` is a column so a test payment can never be mistaken for
--     real revenue.
--   * Rollback is at the bottom. Run it to return to exactly today's schema.
--
-- Run this BEFORE switching PAYMENTS_MODE away from 'disabled'.
-- =============================================================================

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 1. Payment records. One row per verified payment.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `card_id` INT UNSIGNED NOT NULL,
  `plan_id` INT UNSIGNED NOT NULL,

  -- Razorpay identifiers. NULL for WhatsApp/manual payments.
  `razorpay_payment_id` VARCHAR(64) DEFAULT NULL,
  `razorpay_subscription_id` VARCHAR(64) DEFAULT NULL,

  `amount_paise` BIGINT UNSIGNED NOT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'INR',

  -- captured | refunded | failed | pending (pending = WhatsApp awaiting proof)
  `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
  -- razorpay | whatsapp
  `channel` VARCHAR(16) NOT NULL DEFAULT 'razorpay',
  -- test | live | manual   <- so test money can never look like revenue
  `environment` VARCHAR(16) NOT NULL DEFAULT 'test',

  `paid_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  -- The real idempotency guard: Razorpay retries webhooks, and a retry must not
  -- credit the customer twice. A duplicate insert fails here.
  UNIQUE KEY `uniq_razorpay_payment` (`razorpay_payment_id`),
  KEY `idx_payments_user` (`user_id`),
  KEY `idx_payments_card` (`card_id`),
  KEY `idx_payments_created` (`created_at`),

  CONSTRAINT `chk_payments_amount` CHECK (`amount_paise` >= 0),
  CONSTRAINT `chk_payments_status`
    CHECK (`status` IN ('pending','captured','refunded','failed')),
  CONSTRAINT `chk_payments_channel`
    CHECK (`channel` IN ('razorpay','whatsapp')),
  CONSTRAINT `chk_payments_environment`
    CHECK (`environment` IN ('test','live','manual'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. Webhook events. Razorpay may deliver the same event many times.
--    Storing the event id makes "already handled" a database fact, not a guess.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payment_events` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `razorpay_event_id` VARCHAR(64) NOT NULL,
  `event_type` VARCHAR(64) NOT NULL,
  `payload_hash` CHAR(64) NOT NULL,
  `handled` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_event_id` (`razorpay_event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. Plans: the two Razorpay plan ids each plan can be sold as.
--    NULL means "not configured yet" and checkout refuses that plan rather than
--    guessing an amount.
-- -----------------------------------------------------------------------------
ALTER TABLE `plans`
  ADD COLUMN `razorpay_plan_monthly` VARCHAR(64) DEFAULT NULL,
  ADD COLUMN `razorpay_plan_yearly`  VARCHAR(64) DEFAULT NULL;

-- -----------------------------------------------------------------------------
-- 4. Cards: membership state.
--    The existing app already shows an expiry screen (card_membership.php) but
--    had nowhere authoritative to read it from.
-- -----------------------------------------------------------------------------
ALTER TABLE `review_cards`
  ADD COLUMN `plan_id` INT UNSIGNED DEFAULT NULL,
  ADD COLUMN `membership_period` VARCHAR(10) DEFAULT NULL,
  ADD COLUMN `membership_expires_at` DATETIME DEFAULT NULL,
  ADD COLUMN `membership_source` VARCHAR(16) DEFAULT NULL;

-- -----------------------------------------------------------------------------
-- 5. Settings the payment module reads.
--    All empty / 'disabled' on purpose: the module fails closed until you fill
--    them in. `razorpay_key_secret` and `razorpay_webhook_secret` are secrets —
--    they live here and are never sent to a browser.
-- -----------------------------------------------------------------------------
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('payments_mode',           'disabled'),
  ('razorpay_key_id',         ''),
  ('razorpay_key_secret',     ''),
  ('razorpay_webhook_secret', ''),
  ('razorpay_live_confirm',   ''),
  ('razorpay_timeout',        '15'),
  ('whatsapp_number',         '')
ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`;  -- never overwrite an existing value

COMMIT;

-- =============================================================================
-- VERIFY — expected results
-- =============================================================================
-- 1. Two new tables exist:
--    SHOW TABLES LIKE 'payment%';
--    -> payment_events, payments
--
-- 2. The columns were added (expect 4 + 2 rows):
--    SHOW COLUMNS FROM review_cards LIKE 'membership%';
--    SHOW COLUMNS FROM plans LIKE 'razorpay%';
--
-- 3. Payments still refuses bad data (should fail with a CHECK violation):
--    INSERT INTO payments (user_id,card_id,plan_id,amount_paise,environment)
--      VALUES (1,1,1,100,'production');   -- expect error, 'production' not allowed
--
-- 4. Settings are seeded and disabled (expect 7 rows, first = 'disabled'):
--    SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'payment%'
--      OR setting_key LIKE 'razorpay%' OR setting_key = 'whatsapp_number';
--
-- 5. Nothing existing changed:
--    SELECT COUNT(*) FROM users;        -- same as before
--    SELECT COUNT(*) FROM review_cards; -- same as before

-- =============================================================================
-- ROLLBACK — returns the schema to exactly today's state
-- =============================================================================
-- Safe order. Run only if you want the payment feature gone entirely.
--
-- START TRANSACTION;
-- DROP TABLE IF EXISTS `payment_events`;
-- DROP TABLE IF EXISTS `payments`;
-- ALTER TABLE `review_cards`
--   DROP COLUMN `plan_id`,
--   DROP COLUMN `membership_period`,
--   DROP COLUMN `membership_expires_at`,
--   DROP COLUMN `membership_source`;
-- ALTER TABLE `plans`
--   DROP COLUMN `razorpay_plan_monthly`,
--   DROP COLUMN `razorpay_plan_yearly`;
-- DELETE FROM `settings` WHERE `setting_key` IN (
--   'payments_mode','razorpay_key_id','razorpay_key_secret',
--   'razorpay_webhook_secret','razorpay_live_confirm','razorpay_timeout','whatsapp_number'
-- );
-- COMMIT;
--
-- WARNING: dropping `payments` destroys the payment history. Export it first:
--   SELECT * FROM payments;   -- then save the result as CSV
