<?php
require __DIR__ . '/../../db.php';
$stmt1 = $pdo->query("SELECT date, status_1 FROM attendance ORDER BY id DESC LIMIT 5");
$res1 = $stmt1->fetchAll(PDO::FETCH_ASSOC);
echo "ATTENDANCE:\n";
print_r($res1);

$stmt2 = $pdo->query("SELECT date, status FROM teacher_period_attendance ORDER BY id DESC LIMIT 5");
$res2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);
echo "PERIOD_ATTENDANCE:\n";
print_r($res2);
?>
