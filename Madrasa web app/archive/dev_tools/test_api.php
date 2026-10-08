<?php
if (php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        http_response_code(403);
        die('Forbidden: Admin access required.');
    }
}
require 'db.php';
$stmt = $pdo->query("SELECT student_id, date, status, status_1, status_2, status_3 FROM attendance ORDER BY id DESC LIMIT 50");
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
