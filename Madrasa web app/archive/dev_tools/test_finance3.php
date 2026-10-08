<?php
if (php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        http_response_code(403);
        die('Forbidden: Admin access required.');
    }
}
require __DIR__ . '/../../db.php';
$stmt = $pdo->query('SELECT * FROM finance');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
