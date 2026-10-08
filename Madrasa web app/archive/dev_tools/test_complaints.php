<?php
require __DIR__ . '/../../db.php';
$stmt = $pdo->query("SELECT * FROM complaints LIMIT 20");
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($res);
?>
