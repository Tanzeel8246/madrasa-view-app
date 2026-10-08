<?php
require_once 'db.php';
echo "<h2>Fixing updated_at timestamps for sync...</h2>";
try {
    $tables = [
        'students', 'teachers', 'classes', 'class_periods', 'attendance', 'attendance_teachers',
        'teacher_period_attendance', 'daily_hifz', 'daily_nazira', 'qaida_records', 'husn_saut_record',
        'exams', 'exam_results', 'staff_loans', 'fee_submissions', 'notices', 'complaints',
        'parent_complaints', 'academic_reports', 'period_attendance', 'leave_applications',
        'finance', 'acc_chart_of_accounts', 'acc_vouchers', 'acc_ledger_entries', 'acc_payroll'
    ];
    $count = 0;
    foreach ($tables as $tbl) {
        // Check if updated_at exists
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE 'updated_at'");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            $pdo->exec("UPDATE `$tbl` SET updated_at = NOW(), system_type = 'Madrasa' WHERE system_type IS NULL OR system_type = '' OR system_type = 'Madrasa'");
            echo "<p>Updated table: $tbl</p>";
            $count++;
        }
    }
    echo "<h3>Success! Updated $count tables. You can now sync down from mobile app.</h3>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
