<?php
if (php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        http_response_code(403);
        die('Forbidden: Admin access required.');
    }
}
require_once __DIR__ . "/../../db.php";
$stmt = $pdo->query("SELECT DISTINCT status_1, status_2 FROM attendance LIMIT 20");
file_put_contents(__DIR__ . "/test_db_output.txt", print_r($stmt->fetchAll(PDO::FETCH_ASSOC), true));
$stmt2 = $pdo->query("SELECT DISTINCT status FROM maktab_attendance LIMIT 20");
file_put_contents(__DIR__ . "/test_db_output.txt", print_r($stmt2->fetchAll(PDO::FETCH_ASSOC), true), FILE_APPEND);
echo "Done";
