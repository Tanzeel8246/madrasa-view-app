<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db.php';

try {
    $selected_class = 'حفظ';
    $start_date = '2026-06-01';
    $end_date = '2026-06-25';
    
    echo "Testing hifz_report queries...\n";

    $stmt_lazy = $pdo->prepare("
        SELECT s.id, s.name, s.father_name, s.admission_number, s.roll_number FROM students s
        WHERE (LOWER(s.status) = 'active' OR s.status = 'حاضر' OR s.status = 'Present') 
          AND (s.is_deleted = 0 OR s.is_deleted IS NULL)
          AND s.class = ?
    ");
    $stmt_lazy->execute([$selected_class]);
    echo "Query 1 OK\n";

    $sql = "SELECT d.* FROM daily_hifz d 
            JOIN students s ON d.student_id = s.id 
            WHERE d.date BETWEEN ? AND ? AND (s.is_deleted = 0 OR s.is_deleted IS NULL) AND s.class = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$start_date, $end_date, $selected_class]);
    echo "Query 2 OK\n";

    $c_sql = "SELECT c.student_id, c.date, c.complaint as description, 'admin' as type FROM complaints c WHERE c.date BETWEEN ? AND ?";
    $pc_sql = "SELECT p.student_id, p.date, CONCAT('[', p.category, '] ', p.description) as description, 'parent' as type FROM parent_complaints p WHERE p.date BETWEEN ? AND ?";
    
    $all_c_sql = "$c_sql UNION ALL $pc_sql";
    $c_stmt = $pdo->prepare($all_c_sql);
    $c_stmt->execute([$start_date, $end_date, $start_date, $end_date]);
    echo "Query 3 OK\n";

    echo "All queries passed!";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
