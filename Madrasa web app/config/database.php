<?php
/**
 * Database Configuration & Connection Module
 * 
 * Sets up environment variables, PDO connection with UTF8MB4,
 * error handling modes, and establishes secure session start.
 */

require_once __DIR__ . '/../env_loader.php';
require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../csrf.php';

// Global Production Flag
if (!defined('IS_PRODUCTION')) {
    define('IS_PRODUCTION', filter_var(getenv('MADRASA_IS_PRODUCTION') ?: 'true', FILTER_VALIDATE_BOOLEAN));
}

// Global Security Hardened Error & Exception Shield (Production Safe, Zero Information Disclosure)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
    @file_put_contents($logDir . '/.htaccess', "Order Deny,Allow\nDeny from all\n");
}
ini_set('error_log', $logDir . '/php_errors.log');

register_shutdown_function(function() use ($logDir) {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        $logMsg = date('[Y-m-d H:i:s] ') . "Fatal Error [{$err['type']}]: {$err['message']} in {$err['file']} on line {$err['line']}" . PHP_EOL;
        @file_put_contents($logDir . '/php_errors.log', $logMsg, FILE_APPEND);

        while (ob_get_level() > 0) { @ob_end_clean(); }
        $isApi = (strpos($_SERVER['REQUEST_URI'] ?? '', 'api.php') !== false) || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
        if ($isApi) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'سرور پر ایک اندرونی تکنیکی خرابی پیش آئی ہے۔ برائے مہربانی ایڈمن سے رابطہ کریں۔']);
            exit;
        }

        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo "<!DOCTYPE html><html dir='rtl' lang='ur'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width, initial-scale=1'><title>عارضی تکنیکی معذرت</title><link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css' rel='stylesheet'></head><body class='bg-light d-flex align-items-center justify-content-center min-vh-100 p-3'><div class='card shadow-sm border-0 text-center p-4' style='max-width:500px;border-radius:16px;'><div class='mb-3'><span style='font-size:3.5rem;'>⚙️</span></div><h4 class='fw-bold text-dark mb-2'>عارضی تکنیکی معذرت</h4><p class='text-muted mb-4'>سسٹم میں ایک عارضی مسئلہ پیش آیا ہے جس کی تفصیلات سیکیورٹی لاگ میں محفوظ کر لی گئی ہیں۔ برائے مہربانی چند لمحوں بعد صفحہ ریفریش کریں یا ایڈمن سے رابطہ کریں۔</p><div class='d-flex gap-2 justify-content-center'><a href='index.php' class='btn btn-success px-4 py-2 fw-bold'>ہوم پیج پر جائیں</a><button onclick='window.location.reload()' class='btn btn-outline-secondary px-3 py-2'>ریفریش کریں</button></div></div></body></html>";
        exit;
    }
});

set_exception_handler(function($e) use ($logDir) {
    $logMsg = date('[Y-m-d H:i:s] ') . "Uncaught Exception: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . PHP_EOL . $e->getTraceAsString() . PHP_EOL;
    @file_put_contents($logDir . '/php_errors.log', $logMsg, FILE_APPEND);

    while (ob_get_level() > 0) { @ob_end_clean(); }
    $isApi = (strpos($_SERVER['REQUEST_URI'] ?? '', 'api.php') !== false) || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
    if ($isApi) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'سرور پر ایک اندرونی تکنیکی خرابی پیش آئی ہے۔ برائے مہربانی ایڈمن سے رابطہ کریں۔']);
        exit;
    }

    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html dir='rtl' lang='ur'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width, initial-scale=1'><title>عارضی تکنیکی معذرت</title><link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css' rel='stylesheet'></head><body class='bg-light d-flex align-items-center justify-content-center min-vh-100 p-3'><div class='card shadow-sm border-0 text-center p-4' style='max-width:500px;border-radius:16px;'><div class='mb-3'><span style='font-size:3.5rem;'>⚙️</span></div><h4 class='fw-bold text-dark mb-2'>عارضی تکنیکی معذرت</h4><p class='text-muted mb-4'>سسٹم میں ایک عارضی مسئلہ پیش آیا ہے جس کی تفصیلات سیکیورٹی لاگ میں محفوظ کر لی گئی ہیں۔ برائے مہربانی چند لمحوں بعد صفحہ ریفریش کریں یا ایڈمن سے رابطہ کریں۔</p><div class='d-flex gap-2 justify-content-center'><a href='index.php' class='btn btn-success px-4 py-2 fw-bold'>ہوم پیج پر جائیں</a><button onclick='window.location.reload()' class='btn btn-outline-secondary px-3 py-2'>ریفریش کریں</button></div></div></body></html>";
    exit;
});

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
    @file_put_contents($logDir . '/php_errors.log', date('[Y-m-d H:i:s] ') . "Database Connection Error: " . $err_msg . PHP_EOL, FILE_APPEND);
    if (strpos($_SERVER['REQUEST_URI'] ?? '', 'api.php') !== false) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode(['status' => 'error', 'message' => 'ڈیٹا بیس سے رابطہ قائم نہیں ہو سکا۔ برائے مہربانی ایڈمن سے رابطہ کریں۔']));
    } else {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        die("<!DOCTYPE html><html dir='rtl' lang='ur'><head><meta charset='utf-8'><title>ڈیٹا بیس معذرت</title><link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css' rel='stylesheet'></head><body class='bg-light d-flex align-items-center justify-content-center min-vh-100 p-3'><div class='card shadow-sm border-0 text-center p-4' style='max-width:480px;border-radius:16px;'><div class='mb-3'><span style='font-size:3rem;'>🔌</span></div><h4 class='fw-bold text-dark mb-2'>ڈیٹا بیس سروس عارضی طور پر دستیاب نہیں</h4><p class='text-muted mb-4'>سرور سے رابطہ قائم نہیں ہو سکا۔ متعلقہ تکنیکی ٹیم کو مطلع کر دیا گیا ہے۔ برائے مہربانی چند لمحوں بعد دوبارہ کوشش کریں۔</p><button onclick='window.location.reload()' class='btn btn-primary px-4 py-2 fw-bold'>دوبارہ کوشش کریں</button></div></body></html>");
    }
}

if (session_status() === PHP_SESSION_NONE && (!defined('CLI_MODE') || !CLI_MODE)) {
    if (function_exists('madrasa_safe_session_start')) {
        madrasa_safe_session_start();
    } else {
        @session_start();
    }
}
