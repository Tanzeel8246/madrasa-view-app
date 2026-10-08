<?php
require 'db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$std_id = $_GET['std_id'] ?? null;
$date = $_GET['date'] ?? null;
$type = $_GET['type'] ?? null;

if (!$std_id || !$date || !$type) {
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

$date = date('Y-m-d', strtotime($date));

if ($type === 'hifz') {
    $stmt = $pdo->prepare("SELECT * FROM daily_hifz WHERE student_id = ? AND date = ? LIMIT 1");
} else if ($type === 'nazira') {
    $stmt = $pdo->prepare("SELECT * FROM daily_nazira WHERE student_id = ? AND date = ? LIMIT 1");
} else {
    echo json_encode(['error' => 'Invalid type']);
    exit;
}

$stmt->execute([$std_id, $date]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if ($record) {
    echo json_encode(['status' => 'success', 'data' => $record]);
} else {
    echo json_encode(['status' => 'not_found']);
}
?>
