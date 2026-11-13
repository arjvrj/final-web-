<?php
/**
 * index.php — Front controller
 * - If not installed → redirect to install.php
 * - If installed → render the home page (home.php)
 */

$configFile = __DIR__ . '/config.php';

if (!file_exists($configFile)) {
    header('Location: install.php');
    exit;
}

$config = include $configFile;

if (empty($config['installed'])) {
    header('Location: install.php');
    exit;
}

// Bootstrapping
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

// Render home page
require __DIR__ . '/home.php';