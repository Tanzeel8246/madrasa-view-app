<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require 'db.php';
require 'finance_accounting_bridge.php';

echo "Functions defined: \n";
echo (function_exists('payroll_accounting_post') ? "Yes" : "No") . "\n";

try {
    $teachers = $pdo->query("SELECT id, name, salary, house_rent, medical_allowance, travel_allowance, daily_deduction_rate FROM teachers LIMIT 1")->fetchAll(PDO::FETCH_ASSOC);
    echo "Teachers query success.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

try {
    $absences_stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance_teachers WHERE teacher_id = 1 AND DATE_FORMAT(date, '%Y-%m') = '2026-08' AND status = 'A'");
    $absences_stmt->execute();
    echo "Attendance query success.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
