<?php
require 'db.php';
$stmt = $pdo->query("SELECT id, date, student_id, period_num as shift, status, 0 as delay_minutes FROM period_attendance ORDER BY date DESC, id DESC LIMIT 5");
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($res);
