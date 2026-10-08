<?php
if (php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        http_response_code(403);
        die('Forbidden: Admin access required.');
    }
}
require_once __DIR__ . '/../../db.php';
$stmt = $pdo->query("SELECT id, name, system_type FROM teachers WHERE system_type = 'Masjid' AND (is_deleted = 0 OR is_deleted IS NULL)");
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Masjid Teachers Count: " . count($res) . "\n";
print_r($res);

$stmt2 = $pdo->query("SELECT DISTINCT system_type FROM teachers");
$res2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);
echo "All system_types in teachers table:\n";
print_r($res2);
?>
