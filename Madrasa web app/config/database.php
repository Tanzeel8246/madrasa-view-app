<?php
/**
 * Database Configuration & Connection Module
 * 
 * Sets up environment variables, PDO connection with UTF8MB4,
 * error handling modes, and establishes secure session start.
 */

require_once __DIR__ . '/../env_loader.php';

// Global Production Flag
if (!defined('IS_PRODUCTION')) {
    define('IS_PRODUCTION', filter_var(getenv('MADRASA_IS_PRODUCTION') ?: 'true', FILTER_VALIDATE_BOOLEAN));
}

if (IS_PRODUCTION) {
    ini_set('display_errors', 0);
    error_reporting(0);
} else {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}

$host     = function_exists('get_env_val') ? get_env_val('MADRASA_DB_HOST', 'localhost') : (getenv('MADRASA_DB_HOST') ?: 'localhost');
$port     = function_exists('get_env_val') ? get_env_val('MADRASA_DB_PORT', '')          : (getenv('MADRASA_DB_PORT') ?: '');
$dbname   = function_exists('get_env_val') ? get_env_val('MADRASA_DB_NAME', '')          : (getenv('MADRASA_DB_NAME') ?: '');
$username = function_exists('get_env_val') ? get_env_val('MADRASA_DB_USER', '')          : (getenv('MADRASA_DB_USER') ?: '');
$password = function_exists('get_env_val') ? get_env_val('MADRASA_DB_PASSWORD', '')      : (getenv('MADRASA_DB_PASSWORD') ?: '');

// اگر .env سے credentials نہ ملیں تو واضح error دیں
if (empty($dbname) || empty($username)) {
    if (strpos($_SERVER['REQUEST_URI'] ?? '', 'api.php') !== false) {
        header('Content-Type: application/json');
        die(json_encode(['status' => 'error', 'message' => 'Server configuration error: DB credentials missing in environment.']));
    }
    die('Server configuration error: DB credentials not configured. Please contact administrator.');
}

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
    $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci";
}

try {
    $dsn = "mysql:host=$host;" . ($port !== '' ? "port=$port;" : '') . "dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, $options);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (PDOException $e) {
    // Fallback 1: Try alternate database name/user if host is localhost/cPanel
    $altDb = ($dbname === 'u349923428_madrasa') ? 'mamfss_madrasa' : 'u349923428_madrasa';
    $altUser = ($username === 'u349923428_madrasa') ? 'mamfss_madrasa' : 'u349923428_madrasa';
    try {
        $pdo = new PDO("mysql:host=$host;" . ($port !== '' ? "port=$port;" : '') . "dbname=$altDb;charset=utf8mb4", $altUser, $password, $options);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (PDOException $eAlt) {
        // Fallback 2: Local development attempt (root with no password)
        if (($host === 'localhost' || $host === '127.0.0.1') && $username !== 'root') {
            try {
                $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", 'root', '', $options);
                $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (PDOException $e2) {
                try {
                    $pdo = new PDO("mysql:host=$host;dbname=$altDb;charset=utf8mb4", 'root', '', $options);
                    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
                } catch (PDOException $e3) {
                    // Ignore local fallback error
                }
            }
        }
    }
}    

if (!isset($pdo)) {
    $err_msg = isset($e) && $e instanceof Throwable ? $e->getMessage() : 'Database connection could not be established.';
    if (strpos($_SERVER['REQUEST_URI'] ?? '', 'api.php') !== false) {
        header('Content-Type: application/json');
        die(json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . $err_msg]));
    } else {
        die("Database connection failed: " . htmlspecialchars($err_msg));
    }
}

if (session_status() === PHP_SESSION_NONE && (!defined('CLI_MODE') || !CLI_MODE)) {
    session_start();
}
