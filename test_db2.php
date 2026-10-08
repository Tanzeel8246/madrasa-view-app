<?php
require_once "Madrasa web app/db.php";
$stmt = $pdo->query("SELECT DISTINCT status_1, status_2 FROM attendance LIMIT 20");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt2 = $pdo->query("SELECT DISTINCT status FROM period_attendance LIMIT 20");
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));

$stmt3 = $pdo->query("SELECT DISTINCT status FROM maktab_attendance LIMIT 20");
print_r($stmt3->fetchAll(PDO::FETCH_ASSOC));
