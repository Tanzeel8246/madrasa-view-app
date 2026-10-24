<?php
/**
 * Safe Audit Cleanup Script
 * Cleans up temporary audit records: JV-00001, JV-00002, JV-00003, JV-00004, and student 'TEST-MASJID طالب'
 */
if (php_sapi_name() === 'cli') {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../financial_audit_helper.php';
} else {
    require_once __DIR__ . '/../db.php';
    require_once __DIR__ . '/../financial_audit_helper.php';
}

$report = [
    'status' => 'success',
    'timestamp' => date('Y-m-d H:i:s'),
    'vouchers_cleaned' => [],
    'students_cleaned' => []
];

try {
    $test_vouchers = ['JV-00001', 'JV-00002', 'JV-00003', 'JV-00004'];
    foreach ($test_vouchers as $v_no) {
        $stmt = $pdo->prepare("SELECT id, tenant_id FROM acc_vouchers WHERE voucher_no = ?");
        $stmt->execute([$v_no]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $v_id = (int)$r['id'];
            $tid = (int)$r['tenant_id'];
            $pdo->prepare("DELETE FROM acc_ledger_entries WHERE voucher_id = ?")->execute([$v_id]);
            $pdo->prepare("DELETE FROM acc_vouchers WHERE id = ?")->execute([$v_id]);
            acc_log_audit($pdo, $tid, 'acc_vouchers', $v_id, 'AUDIT_CLEANUP_DELETE', ['voucher_no' => $v_no], null, null, 'Audit Cleanup');
            $report['vouchers_cleaned'][] = "$v_no (ID: $v_id, Tenant: $tid)";
        }
    }

    // Clean test student
    $sStmt = $pdo->prepare("SELECT id, tenant_id, name FROM students WHERE name LIKE '%TEST-MASJID%'");
    $sStmt->execute();
    $sRows = $sStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sRows as $sr) {
        $s_id = (int)$sr['id'];
        $tid = (int)$sr['tenant_id'];
        $pdo->prepare("DELETE FROM student_classes WHERE student_id = ?")->execute([$s_id]);
        $pdo->prepare("DELETE FROM students WHERE id = ?")->execute([$s_id]);
        $report['students_cleaned'][] = "{$sr['name']} (ID: $s_id, Tenant: $tid)";
    }

    // Handle empty pending RV-00010
    $rv = $pdo->query("SELECT id, tenant_id FROM acc_vouchers WHERE voucher_no = 'RV-00010' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($rv) {
        $v_id = (int)$rv['id'];
        $tid = (int)$rv['tenant_id'];
        $eCount = (int)$pdo->query("SELECT COUNT(*) FROM acc_ledger_entries WHERE voucher_id = $v_id")->fetchColumn();
        if ($eCount === 0) {
            $pdo->prepare("DELETE FROM acc_vouchers WHERE id = ?")->execute([$v_id]);
            $report['vouchers_cleaned'][] = "RV-00010 (Empty Pending removed, ID: $v_id)";
        }
    }
} catch (Throwable $e) {
    $report['status'] = 'error';
    $report['error'] = $e->getMessage();
}

if (php_sapi_name() === 'cli') {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
