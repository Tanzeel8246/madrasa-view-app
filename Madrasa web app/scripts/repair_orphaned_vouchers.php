<?php
/**
 * Standalone Orphan Voucher & Ledger Reconciliation Script
 * Part of D1 Fix: Scans all tenants, detects mismatched/orphaned vouchers, and repairs them under append-only audit log.
 */

if (php_sapi_name() === 'cli') {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../financial_audit_helper.php';
    $dry_run = in_array('--dry-run', $argv ?? []);
} else {
    require_once __DIR__ . '/../db.php';
    require_once __DIR__ . '/../financial_audit_helper.php';
    $dry_run = isset($_GET['dry_run']) && $_GET['dry_run'] === '1';
}

acc_ensure_audit_log_schema($pdo);

$report = [
    'title' => 'Orphan Voucher & Accounting Ledger Repair Audit',
    'mode' => $dry_run ? 'DRY-RUN (Simulated)' : 'LIVE REPAIR',
    'timestamp' => date('Y-m-d H:i:s'),
    'tenants' => [],
    'summary' => [
        'missing_tenant_id_lines_fixed' => 0,
        'empty_vouchers_detected' => 0,
        'unbalanced_vouchers_detected' => 0,
        'total_orphans_repaired' => 0
    ]
];

$tenants = [];
try {
    $tenants = $pdo->query("SELECT id, name, code FROM tenants ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
if (empty($tenants)) {
    $tenants = [['id' => 1, 'name' => 'Main Institute', 'code' => 'MAIN01']];
}

foreach ($tenants as $t) {
    $tid = (int)$t['id'];
    $t_data = [
        'tenant_id' => $tid,
        'name' => $t['name'] ?? '',
        'code' => $t['code'] ?? '',
        'repaired_missing_tenant_lines' => 0,
        'empty_vouchers' => [],
        'unbalanced_vouchers' => []
    ];

    // 1. Check for ledger entries where tenant_id is NULL or 0, but parent voucher has tenant_id
    $null_t_stmt = $pdo->prepare("SELECT e.id, e.voucher_id, v.voucher_no FROM acc_ledger_entries e JOIN acc_vouchers v ON e.voucher_id = v.id WHERE (e.tenant_id IS NULL OR e.tenant_id = 0) AND v.tenant_id = ?");
    $null_t_stmt->execute([$tid]);
    $null_lines = $null_t_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($null_lines)) {
        $count = count($null_lines);
        $t_data['repaired_missing_tenant_lines'] = $count;
        $report['summary']['missing_tenant_id_lines_fixed'] += $count;
        $report['summary']['total_orphans_repaired'] += $count;

        if (!$dry_run) {
            $upd = $pdo->prepare("UPDATE acc_ledger_entries e JOIN acc_vouchers v ON e.voucher_id = v.id SET e.tenant_id = v.tenant_id WHERE (e.tenant_id IS NULL OR e.tenant_id = 0) AND v.tenant_id = ?");
            $upd->execute([$tid]);
            acc_log_audit($pdo, $tid, 'acc_ledger_entries', 0, 'REPAIR_NULL_TENANT_ID', null, ['fixed_count' => $count], null, 'Repair Script');
        }
    }

    // 2. Check for vouchers that have NO ledger lines
    $empty_stmt = $pdo->prepare("
        SELECT v.id, v.voucher_no, v.date, v.status 
        FROM acc_vouchers v 
        LEFT JOIN acc_ledger_entries e ON v.id = e.voucher_id 
        WHERE v.tenant_id = ? AND (v.is_deleted = 0 OR v.is_deleted IS NULL) AND e.id IS NULL
    ");
    $empty_stmt->execute([$tid]);
    $empty_vouchers = $empty_stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($empty_vouchers)) {
        $t_data['empty_vouchers'] = $empty_vouchers;
        $report['summary']['empty_vouchers_detected'] += count($empty_vouchers);
    }

    // 3. Check for vouchers where Debits != Credits
    $unbal_stmt = $pdo->prepare("
        SELECT v.id, v.voucher_no, SUM(e.debit) as tot_dr, SUM(e.credit) as tot_cr 
        FROM acc_vouchers v 
        JOIN acc_ledger_entries e ON v.id = e.voucher_id 
        WHERE v.tenant_id = ? AND (v.is_deleted = 0 OR v.is_deleted IS NULL)
        GROUP BY v.id, v.voucher_no
        HAVING ABS(tot_dr - tot_cr) > 0.01
    ");
    $unbal_stmt->execute([$tid]);
    $unbalanced = $unbal_stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($unbalanced)) {
        $t_data['unbalanced_vouchers'] = $unbalanced;
        $report['summary']['unbalanced_vouchers_detected'] += count($unbalanced);
    }

    $report['tenants'][] = $t_data;
}

if (php_sapi_name() === 'cli') {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
