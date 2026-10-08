<?php
if (php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        http_response_code(403);
        die('Forbidden: Admin access required.');
    }
}
$dsn = "mysql:host=localhost;dbname=mamfss_madrasa;charset=utf8mb4";
try {
    $pdo = new PDO($dsn, "mamfss_madrasa", "");
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo implode("\n", $tables);
} catch (Exception $e) {
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=mamfss_madrasa;charset=utf8mb4", "root", "");
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo implode("\n", $tables);
    } catch (Exception $e2) {
        echo "ERROR: " . $e2->getMessage();
    }
}
