<?php
require __DIR__ . '/../../db.php';
try {
    $date_where = "month_year = ?";
    $date_params = ['2026-07'];
    
    $sum = $pdo->prepare("SELECT
        COUNT(*) AS total_records,
        SUM(net_salary) AS total_net,
        SUM(CASE WHEN status='Paid' THEN net_salary ELSE 0 END) AS paid_net,
        SUM(CASE WHEN status='Unpaid' THEN net_salary ELSE 0 END) AS unpaid_net,
        SUM(CASE WHEN status='Advance' THEN advance_amount ELSE 0 END) AS advance_net,
        SUM(CASE WHEN status='Unpaid' THEN 1 ELSE 0 END) AS pending_count
    FROM acc_payroll p
    JOIN teachers t ON t.id = p.teacher_id
    WHERE p.$date_where AND (t.is_deleted=0 OR t.is_deleted IS NULL)");
    $sum->execute($date_params);
    $summary = $sum->fetch(PDO::FETCH_ASSOC);
    print_r($summary);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
