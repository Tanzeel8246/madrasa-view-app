<?php
require_once 'db.php';
$stmt = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='qaida_reports'");
echo $stmt->fetchColumn();
?>
