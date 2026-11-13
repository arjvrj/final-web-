<?php
/**
 * Database connection — reads config.php, exposes $conn (PDO).
 * No .env, no .htaccess required.
 */

function getDB() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $configFile = dirname(__DIR__) . '/config.php';

    if (!file_exists($configFile)) {
        header('Location: install.php');
        exit;
    }

    $config = include $configFile;

    if (empty($config['installed'])) {
        header('Location: install.php');
        exit;
    }

    try {
        $pdo = new PDO(
            "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
            $config['db_user'],
            $config['db_pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
    }

    return $pdo;
}

/**
 * Global config accessor (cached).
 */
function getConfig() {
    static $config = null;
    if ($config !== null) return $config;

    $configFile = dirname(__DIR__) . '/config.php';
    if (!file_exists($configFile)) return [];

    $config = include $configFile;
    return $config;
}

// Expose connection as $conn for legacy includes
$conn = getDB();