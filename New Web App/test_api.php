<?php
require 'db.php';
$stmt = $pdo->query("SELECT student_id, date, status, status_1, status_2, status_3 FROM attendance ORDER BY id DESC LIMIT 50");
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
