<?php
require_once 'db.php';

$tables = [
    'classes', 'students', 'teachers', 'attendance', 'attendance_teachers',
    'finance', 'acc_chart_of_accounts', 'staff_loans', 'acc_payroll',
    'acc_vouchers', 'acc_ledger_entries', 'notices', 'complaints',
    'parent_complaints', 'fee_submissions', 'academic_reports', 'in_kind_donations'
];

echo "<h3>Database Migration: Adding system_type to tables</h3>";

foreach ($tables as $table) {
    try {
        // Check if table exists first
        $tableExists = $pdo->query("SHOW TABLES LIKE '$table'")->rowCount();
        if ($tableExists > 0) {
            // Check if column exists
            $columnExists = false;
            $columns = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($columns as $col) {
                if ($col['Field'] === 'system_type') {
                    $columnExists = true;
                    break;
                }
            }

            if (!$columnExists) {
                // Add the column
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `system_type` VARCHAR(50) DEFAULT 'Madrasa'");
                
                // Update existing rows to 'Madrasa' to ensure backwards compatibility
                $pdo->exec("UPDATE `$table` SET `system_type` = 'Madrasa' WHERE `system_type` IS NULL OR `system_type` = ''");
                
                echo "<p style='color:green;'>SUCCESS: Added `system_type` to `$table`.</p>";
            } else {
                // Ensure existing blank fields are updated to Madrasa
                $pdo->exec("UPDATE `$table` SET `system_type` = 'Madrasa' WHERE `system_type` IS NULL OR `system_type` = ''");
                echo "<p style='color:blue;'>INFO: `system_type` already exists in `$table`.</p>";
            }

            if ($table === 'staff_loans') {
                $hasRemaining = false;
                foreach ($columns as $col) {
                    if ($col['Field'] === 'remaining_amount') {
                        $hasRemaining = true;
                        break;
                    }
                }
                if (!$hasRemaining) {
                    $pdo->exec("ALTER TABLE staff_loans ADD COLUMN remaining_amount DECIMAL(15,2) DEFAULT 0");
                    $pdo->exec("UPDATE staff_loans SET remaining_amount = loan_amount");
                    echo "<p style='color:green;'>SUCCESS: Added `remaining_amount` to `staff_loans`.</p>";
                }
            }
        } else {
            echo "<p style='color:orange;'>WARNING: Table `$table` does not exist.</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color:red;'>ERROR processing `$table`: " . $e->getMessage() . "</p>";
    }
}
echo "<h3>Migration Completed!</h3>";
?>
