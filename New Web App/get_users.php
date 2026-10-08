<?php
require 'db.php';
$stmt = $pdo->query('SELECT id, name, username, password, role FROM admins');
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($users, JSON_PRETTY_PRINT);
?>
