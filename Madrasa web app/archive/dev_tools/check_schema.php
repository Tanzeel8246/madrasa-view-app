<?php
if (php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        http_response_code(403);
        die('Forbidden: Admin access required.');
    }
}
require_once __DIR__ . '/../../db.php';
try {
    $stmt = $pdo->query("DESCRIBE students");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($columns);
} catch (Exception $e) {
    echo $e->getMessage();
}
