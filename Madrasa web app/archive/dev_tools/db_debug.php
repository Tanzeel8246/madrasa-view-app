<?php
if (php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        http_response_code(403);
        die('Forbidden: Admin access required.');
    }
}
require_once __DIR__ . '/../../db.php';
echo "Host: " . getenv('MADRASA_DB_HOST') . "\n";
echo "DB: " . getenv('MADRASA_DB_NAME') . "\n";
echo "User: " . getenv('MADRASA_DB_USER') . "\n";
echo "Pass: " . getenv('MADRASA_DB_PASS') . "\n";

echo "Actual Host: $host\n";
echo "Actual DB: $dbname\n";
echo "Actual User: $user\n";
?>
