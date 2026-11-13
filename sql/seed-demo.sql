-- =============================================================================
-- seed-demo.sql — DEMO DATA ONLY. NEVER IMPORT THIS ON A LIVE SITE.
-- =============================================================================
--
-- What it is
--   A two-business demo dataset so the interface can be clicked through on a
--   local or staging database: one paid business with a review card, reviews and
--   feedback, one trial business, three plans, keywords, a platform WhatsApp
--   number and one paid test-mode payment row (so the transaction history is not
--   empty).
--
-- What it is NOT
--   * not part of the install path — install.php never reads it
--   * not real customer data: every name, phone number and email below is
--     invented for the demo
--   * not a migration: it inserts rows, it does not change the schema
--
-- ORDER OF USE
--   1. build the schema first:   mysql -u USER -p DB < sql/schema.sql
--      (or run install.php)
--   2. then:                     mysql -u USER -p DB < sql/seed-demo.sql
--   3. sign in as the demo owner (below), look around
--   4. before any real use, either delete the demo rows (bottom of this file)
--      or start from a clean database
--
-- DEMO LOGINS (password for both accounts: Demo12345!)
--   owner / paid business : demo@reviewgrow.test
--   trial business        : salon@reviewgrow.test
--
-- PAYMENTS STAY OFF. This file does not touch payments_mode, and the one payment
-- row it inserts is marked environment='test' — it can never look like revenue.
--
-- Re-running is safe: every row has an explicit id and an ON DUPLICATE KEY
-- UPDATE, so a second import refreshes the same rows instead of duplicating them.
-- =============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------- users
INSERT INTO users (id, email, password, otp, created_at, trial_started_at) VALUES
  (1, 'demo@reviewgrow.test',
      '$2y$12$mMO/yy4h12rZJEGEHLoTAOqP2xitW8BQLq/kPniGFDxTEp.7LUk26',
      NULL, NOW() - INTERVAL 40 DAY, NOW() - INTERVAL 40 DAY),
  (2, 'salon@reviewgrow.test',
      '$2y$12$mMO/yy4h12rZJEGEHLoTAOqP2xitW8BQLq/kPniGFDxTEp.7LUk26',
      NULL, NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY)
ON DUPLICATE KEY UPDATE password = VALUES(password), created_at = VALUES(created_at);

-- ---------------------------------------------------------------- review_cards
-- Card 1 has a paid plan so the paid state, the expiry line and the transaction
-- history all render. Card 2 is on trial with the trial already used, so the
-- upgrade buttons render.
INSERT INTO review_cards (id, user_id, name, slug, membership, expiry_date, details, created_at) VALUES
  (1, 1, 'Tribe Fitness', 'tribe-fitness', 'growth', NOW() + INTERVAL 24 DAY,
      '{"description":"Neighbourhood gym with small-group strength classes and a sauna.","whatsapp":"+91 98765 43210","facebook":"","instagram":"","website":"https://example.com/tribe-fitness","google_review_url":"https://www.google.com/search?q=Tribe+Fitness+reviews","theme":"purple","enable_keyword_selection":true,"business_keywords":["friendly trainers","clean equipment","good value","never crowded"],"business_type":"gym","logo":""}',
      NOW() - INTERVAL 30 DAY),
  (2, 2, 'Green Leaf Salon', 'green-leaf-salon', 'trial', NOW() + INTERVAL 1 DAY,
      '{"description":"Hair and skin studio. Walk-ins welcome before noon.","whatsapp":"+91 90000 11111","facebook":"","instagram":"","website":"","google_review_url":"https://www.google.com/search?q=Green+Leaf+Salon+reviews","theme":"green","enable_keyword_selection":true,"business_keywords":["patient stylists","fair pricing","relaxing"],"business_type":"salon","logo":""}',
      NOW() - INTERVAL 2 DAY)
ON DUPLICATE KEY UPDATE name = VALUES(name), membership = VALUES(membership), expiry_date = VALUES(expiry_date), details = VALUES(details);

-- ---------------------------------------------------------------- reviews
INSERT INTO reviews (id, card_id, customer_name, rating, body, details, created_at) VALUES
  (1, 1, 'Priya S.', 5,
      'Clean equipment and friendly trainers. The whole visit felt effortless and I left genuinely impressed. Would happily recommend the early morning classes.',
      '{"source":"demo","keywords":["clean equipment","friendly trainers"]}', NOW() - INTERVAL 6 DAY),
  (2, 1, 'Arun K.', 4,
      'Good value for the price. Staff answered my questions without rushing me, and the trial class was easy to book.',
      '{"source":"demo","keywords":["good value"]}', NOW() - INTERVAL 4 DAY),
  (3, 1, 'Meera R.', 5,
      'Booked in the morning, got seen quickly and everything was explained clearly. The sauna afterwards was the best part.',
      '{"source":"demo","keywords":["never crowded"]}', NOW() - INTERVAL 2 DAY),
  (4, 2, 'Sneha P.', 5,
      'The stylist took the time to understand what I wanted instead of talking me into an expensive treatment.',
      '{"source":"demo","keywords":["patient stylists"]}', NOW() - INTERVAL 1 DAY)
ON DUPLICATE KEY UPDATE customer_name = VALUES(customer_name), rating = VALUES(rating), body = VALUES(body), created_at = VALUES(created_at);

-- ---------------------------------------------------------------- feedbacks
INSERT INTO feedbacks (id, card_id, customer_name, phone, email, message, rating, status, created_at) VALUES
  (1, 1, 'Ravi M.', '9876543211', 'ravi.demo@example.test',
      'The 7pm class was overbooked and there was nowhere to put my bag. Otherwise the staff were helpful.',
      2, 'new', NOW() - INTERVAL 3 DAY),
  (2, 1, 'Lakshmi V.', '9876543212', 'lakshmi.demo@example.test',
      'Waited twenty minutes past my slot for the sauna. Please fix the booking slots.',
      3, 'read', NOW() - INTERVAL 8 DAY)
ON DUPLICATE KEY UPDATE message = VALUES(message), rating = VALUES(rating), status = VALUES(status), created_at = VALUES(created_at);

-- ---------------------------------------------------------------- plans
INSERT INTO plans (id, name, description, price, sale_price, features, cta, duration, created_at) VALUES
  (1, 'Starter', 'One review card for a single location.', 499.00, NULL,
      '["Unlimited reviews","QR code","Email support"]', 'Get started', 30, NOW() - INTERVAL 60 DAY),
  (2, 'Growth', 'For businesses that want more feedback.', 999.00, 799.00,
      '["Everything in Starter","Customer feedback inbox","Priority support"]', 'Choose Growth', 30, NOW() - INTERVAL 60 DAY),
  (3, 'Pro', 'Multi-location and seasonal campaigns.', 1499.00, NULL,
      '["Everything in Growth","Multiple cards"]', 'Choose Pro', 90, NOW() - INTERVAL 60 DAY)
ON DUPLICATE KEY UPDATE name = VALUES(name), price = VALUES(price), sale_price = VALUES(sale_price), features = VALUES(features), duration = VALUES(duration);

-- ---------------------------------------------------------------- keywords
-- The eight words install.php seeds, restated so a schema.sql-only database has
-- them too. Explicit ids keep this idempotent.
INSERT INTO keywords (id, keyword, sort_order) VALUES
  (1, 'professional', 0), (2, 'quality', 1), (3, 'excellent service', 2),
  (4, 'friendly staff', 3), (5, 'clean', 4), (6, 'affordable', 5),
  (7, 'great value', 6), (8, 'highly recommend', 7)
ON DUPLICATE KEY UPDATE keyword = VALUES(keyword), sort_order = VALUES(sort_order);

-- ---------------------------------------------------------------- platform_settings
INSERT INTO platform_settings (id, whatsapp, details) VALUES
  (1, '919876500000', '{"support_hours":"Mon-Sat, 10:00-19:00 IST"}')
ON DUPLICATE KEY UPDATE whatsapp = VALUES(whatsapp);

-- ---------------------------------------------------------------- settings
-- Only the keys the demo needs. payments_mode is deliberately NOT set here: it
-- stays whatever the install left it as (disabled).
INSERT INTO settings (setting_key, setting_value) VALUES
  ('business_name', 'Tribe Fitness'),
  ('business_description', 'Neighbourhood gym with small-group strength classes and a sauna.'),
  ('business_whatsapp', '919876543210'),
  ('business_phone', '+91 98765 43210'),
  ('business_address', '12 MG Road, Nizamabad, Telangana 503001'),
  ('google_review_url', 'https://www.google.com/search?q=Tribe+Fitness+reviews'),
  ('theme', 'purple'),
  ('custom_primary', '#7c3aed'),
  ('custom_gradient', 'linear-gradient(135deg, #7c3aed 0%, #4f46e5 100%)'),
  ('enable_keywords', '1'),
  ('owner_email', 'demo@reviewgrow.test'),
  ('site_url', 'http://localhost:8080')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- ---------------------------------------------------------------- payments
-- One settled test-mode order for the Growth plan (₹799 sale price = 79900 paise).
-- environment='test' and channel='razorpay'; the ids are obviously synthetic so
-- nobody mistakes this for a real Razorpay payment.
INSERT INTO payments (id, user_id, card_id, plan_id, razorpay_order_id, razorpay_payment_id, razorpay_signature,
                      amount_paise, currency, status, environment, channel, paid_at, created_at)
VALUES
  (1, 1, 1, 2, 'order_DEMO00000000000001', 'pay_DEMO00000000000001', NULL,
      79900, 'INR', 'paid', 'test', 'razorpay', NOW() - INTERVAL 24 DAY, NOW() - INTERVAL 24 DAY)
ON DUPLICATE KEY UPDATE status = VALUES(status), amount_paise = VALUES(amount_paise), paid_at = VALUES(paid_at);

-- =============================================================================
-- REMOVING THE DEMO DATA (run these in this order — children first)
-- =============================================================================
-- DELETE FROM payments        WHERE id IN (1);
-- DELETE FROM feedbacks       WHERE id IN (1, 2);
-- DELETE FROM reviews         WHERE id IN (1, 2, 3, 4);
-- DELETE FROM review_cards    WHERE id IN (1, 2);
-- DELETE FROM users           WHERE email IN ('demo@reviewgrow.test', 'salon@reviewgrow.test');
-- DELETE FROM plans           WHERE id IN (1, 2, 3);
-- -- then re-run install.php's seeds if you also want the default keywords back
-- =============================================================================
