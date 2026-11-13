<?php
/**
 * config.example.php — the only file this application needs that you create by hand.
 *
 * The installer writes app/config.php for you (step 2 of install.php). This
 * example exists for two cases: you prefer to create the file manually, or you
 * need to see which keys are expected.
 *
 * Rules:
 *   - copy this file to app/config.php
 *   - never commit the real config.php (it is in .gitignore)
 *   - there are no environment variables and no .env file: this file and the
 *     `settings` table are the entire configuration surface
 *   - Razorpay keys are NOT here. They live in the settings table and are set in
 *     Owner panel → Settings → Card payments, so they can be rotated without
 *     touching a file.
 */

return [
    // --- database (Hostinger: hPanel → Databases → MySQL) -----------------
    'db_host' => 'localhost',          // Hostinger MySQL host
    'db_name' => 'u000000000_reviewgrow',
    'db_user' => 'u000000000_owner',
    'db_pass' => '',                   // never commit this

    // --- site -------------------------------------------------------------
    'site_url' => 'https://example.com',
    'app_name' => 'ReviewAI',

    // --- owner ------------------------------------------------------------
    // The first account created by the installer becomes the owner. This value
    // is written by install.php step 3 and is also stored in the settings table.
    'owner_email' => 'owner@example.com',

    // --- state ------------------------------------------------------------
    // The application refuses to boot until this is true.
    'installed' => false,
];
