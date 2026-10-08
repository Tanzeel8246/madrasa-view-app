<?php
require __DIR__ . '/../../db.php';
$stmt = $pdo->prepare("SELECT id, date, student_id, period_num as shift, status, 0 as delay_minutes FROM period_attendance ORDER BY date DESC, id DESC LIMIT 5");
$stmt->execute();
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($res, JSON_PRETTY_PRINT);
