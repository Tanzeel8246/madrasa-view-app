<?php
if (php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        http_response_code(403);
        die('Forbidden: Admin access required.');
    }
}
$host = 'localhost';
$dbname = 'u349923428_madrasa';
$user = 'u349923428_madrasa';
$pass = 'Abdulrahman*8246';

header('Content-Type: application/json; charset=utf-8');
$result = [];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $result['connection'] = 'OK';

    // period_attendance table
    try {
        $result['period_attendance_columns'] = $pdo->query("SHOW COLUMNS FROM period_attendance")->fetchAll(PDO::FETCH_COLUMN);
        $result['period_attendance_count'] = $pdo->query("SELECT COUNT(*) as cnt FROM period_attendance")->fetchColumn();
        $result['period_attendance_sample'] = $pdo->query("SELECT * FROM period_attendance ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        $result['period_attendance_absent'] = $pdo->query("SELECT * FROM period_attendance WHERE status IN ('A','L') ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        $result['period_attendance_dates'] = $pdo->query("SELECT DISTINCT date FROM period_attendance ORDER BY date DESC LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $result['period_attendance_error'] = $e->getMessage();
    }

    // attendance table
    try {
        $result['attendance_count'] = $pdo->query("SELECT COUNT(*) FROM attendance")->fetchColumn();
        $result['attendance_sample'] = $pdo->query("SELECT student_id, date, status, status_1, status_2, status_3 FROM attendance ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        $result['attendance_dates'] = $pdo->query("SELECT DISTINCT date FROM attendance ORDER BY date DESC LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $result['attendance_error'] = $e->getMessage();
    }

    // daily_hifz sample date format
    try {
        $result['hifz_dates'] = $pdo->query("SELECT DISTINCT date FROM daily_hifz ORDER BY date DESC LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $result['hifz_dates_error'] = $e->getMessage();
    }

} catch (Exception $e) {
    $result['connection_error'] = $e->getMessage();
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
