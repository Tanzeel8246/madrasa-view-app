<?php
/**
 * Master Migration & Data-Repair Script: Accounting Truth, Funds & Orphan Vouchers
 * Covers: D1, D3, D4, D5, D8, D9
 * Supports dry-run (?dry_run=1 or CLI --dry-run) and auditable execution.
 */

if (php_sapi_name() === 'cli') {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../financial_audit_helper.php';
    $is_dry_run = in_array('--dry-run', $argv ?? []);
} else {
    require_once __DIR__ . '/../db.php';
    require_once __DIR__ . '/../financial_audit_helper.php';
    $is_dry_run = isset($_GET['dry_run']) && $_GET['dry_run'] === '1';
}

function run_accounting_truth_migration(PDO $pdo, $dry_run = false) {
    acc_ensure_audit_log_schema($pdo);
    
    $report = [
        'mode' => $dry_run ? 'DRY-RUN (Simulated)' : 'LIVE EXECUTION',
        'timestamp' => date('Y-m-d H:i:s'),
        'tenants_processed' => [],
        'repaired_orphaned_lines' => 0,
        'standardized_chart_accounts' => 0,
        'reclassified_entries' => 0,
        'backfilled_invoice_vouchers' => 0,
        'messages' => []
    ];

    // 1. Get all tenants
    $tenants = [];
    try {
        $tenants = $pdo->query("SELECT id, name, code FROM tenants ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    if (empty($tenants)) {
        $tenants = [['id' => 1, 'name' => 'Main Institute', 'code' => 'MAIN01']];
    }

    foreach ($tenants as $t) {
        $tid = (int)$t['id'];
        $t_info = [
            'tenant_id' => $tid,
            'name' => $t['name'] ?? '',
            'code' => $t['code'] ?? '',
            'changes' => []
        ];

        // A. Repair Orphaned Ledger Entries (D1 root cause: tenant_id null)
        $orphan_stmt = $pdo->prepare("SELECT COUNT(*) FROM acc_ledger_entries e JOIN acc_vouchers v ON e.voucher_id = v.id WHERE (e.tenant_id IS NULL OR e.tenant_id = 0) AND v.tenant_id = ?");
        $orphan_stmt->execute([$tid]);
        $orphan_count = (int)$orphan_stmt->fetchColumn();
        if ($orphan_count > 0) {
            $t_info['changes'][] = "Found $orphan_count ledger entries missing tenant_id.";
            $report['repaired_orphaned_lines'] += $orphan_count;
            if (!$dry_run) {
                $upd = $pdo->prepare("UPDATE acc_ledger_entries e JOIN acc_vouchers v ON e.voucher_id = v.id SET e.tenant_id = v.tenant_id WHERE (e.tenant_id IS NULL OR e.tenant_id = 0) AND v.tenant_id = ?");
                $upd->execute([$tid]);
                acc_log_audit($pdo, $tid, 'acc_ledger_entries', 0, 'REPAIR_ORPHANS', ['count' => $orphan_count], ['status' => 'tenant_id_restored'], null, 'System Migration');
            }
        }

        // B. Ensure Standard Chart of Accounts Structure (D4 & D5)
        $standard_accounts = [
            // Assets (1000s)
            ['1001', 'Cash in Hand (نقد رقم)', 'Asset'],
            ['1002', 'Bank Account (بینک اکاؤنٹ)', 'Asset'],
            ['1101', 'Student Fees Receivable (طلبہ فیس واجب الوصول)', 'Asset'],
            ['1201', 'Staff Advances / Loans (اسٹاف ایڈوانس و قرضہ جات)', 'Asset'],
            ['1301', 'Inventory & Fixed Assets (انوینٹری و اثاثہ جات)', 'Asset'],

            // Liabilities & Restricted Funds (2000s)
            ['2001', 'Fees Received in Advance (پیشگی فیس)', 'Liability'],
            ['2002', 'Salaries Payable (واجب الادا تنخواہیں)', 'Liability'],
            ['2101', 'Zakat Fund (فنڈ زکوٰۃ - امانت)', 'Liability'],
            ['2102', 'Sadaqah Fund (فنڈ صدقات و خیرات)', 'Liability'],
            ['2103', 'Specific Donations Fund (مخصوص فنڈ عطیات)', 'Liability'],

            // Equity & Accumulated Funds (3000s)
            ['3001', 'General / Institution Fund (عمومی فنڈ ادارہ)', 'Equity'],
            ['3002', 'Retained Surplus / Deficit (مجموعی فاضل / خسارہ)', 'Equity'],

            // Revenue / Income (4000s)
            ['4001', 'Fee Income (آمدن تعلیمی فیس)', 'Revenue'],
            ['4002', 'General Donations (عمومی عطیات و چندہ)', 'Revenue'],
            ['4003', 'Other Income (دیگر متفرق آمدن)', 'Revenue'],

            // Expenses (5000s)
            ['5001', 'Teacher & Staff Salaries (تنخواہیں اساتذہ و ملازمین)', 'Expense'],
            ['5002', 'Electricity & Utilities (بجلی، گیس و یوٹیلیٹی بلز)', 'Expense'],
            ['5003', 'Maintenance & Repairs (مرمت و دیکھ بھال)', 'Expense'],
            ['5004', 'Food & Kitchen (طعام و باورچی خانہ)', 'Expense'],
            ['5005', 'Educational Supplies (کتب و اسٹیشنری)', 'Expense'],
            ['5006', 'Misc Expenses (متفرق اخراجات)', 'Expense']
        ];

        foreach ($standard_accounts as $sa) {
            $code = $sa[0];
            $name = $sa[1];
            $type = $sa[2];

            $chk = $pdo->prepare("SELECT id, name, type FROM acc_chart_of_accounts WHERE code = ? AND tenant_id = ? LIMIT 1");
            $chk->execute([$code, $tid]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                $t_info['changes'][] = "Add missing standard account $code: $name ($type)";
                $report['standardized_chart_accounts']++;
                if (!$dry_run) {
                    $ins = $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, tenant_id, is_active, is_deleted) VALUES (?, ?, ?, ?, 1, 0)");
                    $ins->execute([$code, $name, $type, $tid]);
                    $new_id = (int)$pdo->lastInsertId();
                    acc_log_audit($pdo, $tid, 'acc_chart_of_accounts', $new_id, 'CREATE_ACCOUNT', null, ['code' => $code, 'name' => $name, 'type' => $type], null, 'System Migration');
                }
            } else {
                // If account exists with wrong type (e.g. 5001 named Monthly Fee or type Expense when it should be salaries or fee income)
                if ($code === '5001' && (strpos($existing['name'], 'فیس') !== false || strpos(strtolower($existing['name']), 'fee') !== false)) {
                    // This was the mis-typed fee account reported in D4!
                    $t_info['changes'][] = "Found mis-typed account 5001 (" . $existing['name'] . "). Migrating entries to 4001 Fee Income and resetting 5001 to Salaries.";
                    $report['reclassified_entries']++;
                    if (!$dry_run) {
                        // Ensure 4001 exists
                        $fChk = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE code = '4001' AND tenant_id = ? LIMIT 1");
                        $fChk->execute([$tid]);
                        $fee_id = $fChk->fetchColumn();
                        if (!$fee_id) {
                            $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, tenant_id, is_active, is_deleted) VALUES ('4001', 'Fee Income (آمدن تعلیمی فیس)', 'Revenue', ?, 1, 0)")->execute([$tid]);
                            $fee_id = (int)$pdo->lastInsertId();
                        }
                        // Re-point ledger entries to 4001
                        $pdo->prepare("UPDATE acc_ledger_entries SET account_id = ? WHERE account_id = ? AND tenant_id = ?")->execute([$fee_id, $existing['id'], $tid]);
                        // Rename 5001 to Teacher Salaries
                        $pdo->prepare("UPDATE acc_chart_of_accounts SET name = 'Teacher & Staff Salaries (تنخواہیں اساتذہ و ملازمین)', type = 'Expense' WHERE id = ?")->execute([$existing['id']]);
                        acc_log_audit($pdo, $tid, 'acc_chart_of_accounts', $existing['id'], 'MIGRATE_FEE_ACCOUNT', $existing, ['new_name' => 'Teacher Salaries', 'repointed_to' => $fee_id], null, 'System Migration');
                    }
                }
                if ($code === '5002' && (strpos($existing['name'], 'زکوۃ') !== false || strpos($existing['name'], 'زکوٰۃ') !== false || strpos(strtolower($existing['name']), 'zakat') !== false)) {
                    // This was mis-typed Zakat expense reported in D4!
                    $t_info['changes'][] = "Found mis-typed account 5002 (" . $existing['name'] . "). Migrating entries to 2101 Zakat Fund and resetting 5002 to Utilities.";
                    $report['reclassified_entries']++;
                    if (!$dry_run) {
                        $zChk = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE code = '2101' AND tenant_id = ? LIMIT 1");
                        $zChk->execute([$tid]);
                        $zakat_id = $zChk->fetchColumn();
                        if (!$zakat_id) {
                            $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, tenant_id, is_active, is_deleted) VALUES ('2101', 'Zakat Fund (فنڈ زکوٰۃ - امانت)', 'Liability', ?, 1, 0)")->execute([$tid]);
                            $zakat_id = (int)$pdo->lastInsertId();
                        }
                        $pdo->prepare("UPDATE acc_ledger_entries SET account_id = ? WHERE account_id = ? AND tenant_id = ?")->execute([$zakat_id, $existing['id'], $tid]);
                        $pdo->prepare("UPDATE acc_chart_of_accounts SET name = 'Electricity & Utilities (بجلی، گیس و یوٹیلیٹی بلز)', type = 'Expense' WHERE id = ?")->execute([$existing['id']]);
                        acc_log_audit($pdo, $tid, 'acc_chart_of_accounts', $existing['id'], 'MIGRATE_ZAKAT_ACCOUNT', $existing, ['new_name' => 'Utilities', 'repointed_to' => $zakat_id], null, 'System Migration');
                    }
                }
            }
        }

        // C. Backfill Invoices to Ledger (D9)
        // Find invoices that have no corresponding voucher in acc_vouchers
        try {
            $inv_stmt = $pdo->prepare("SELECT i.*, s.name as student_name FROM acc_invoices i JOIN students s ON i.student_id = s.id WHERE i.tenant_id = ? AND i.amount > 0");
            $inv_stmt->execute([$tid]);
            $invoices = $inv_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get account IDs for 1101 and 4001
            $rec_acc_id = (int)($pdo->query("SELECT id FROM acc_chart_of_accounts WHERE code = '1101' AND tenant_id = $tid LIMIT 1")->fetchColumn() ?: 0);
            $fee_acc_id = (int)($pdo->query("SELECT id FROM acc_chart_of_accounts WHERE code = '4001' AND tenant_id = $tid LIMIT 1")->fetchColumn() ?: 0);

            if ($rec_acc_id > 0 && $fee_acc_id > 0) {
                foreach ($invoices as $inv) {
                    $ref = $inv['invoice_no'];
                    $vChk = $pdo->prepare("SELECT id FROM acc_vouchers WHERE reference = ? AND tenant_id = ? LIMIT 1");
                    $vChk->execute([$ref, $tid]);
                    if (!$vChk->fetchColumn()) {
                        $t_info['changes'][] = "Backfill invoice voucher for {$inv['invoice_no']} (Rs {$inv['amount']})";
                        $report['backfilled_invoice_vouchers']++;
                        if (!$dry_run) {
                            $inv_date = !empty($inv['created_at']) ? date('Y-m-d', strtotime($inv['created_at'])) : date('Y-m-d');
                            $v_no = "INV-V-" . str_pad($inv['id'], 5, '0', STR_PAD_LEFT);
                            $desc = "فیس انوائس # {$inv['invoice_no']} - طالب علم: {$inv['student_name']} (ماہ {$inv['month_year']})";
                            
                            $pdo->beginTransaction();
                            $vIns = $pdo->prepare("INSERT INTO acc_vouchers (voucher_no, date, type, reference, description, status, created_by, tenant_id, is_deleted) VALUES (?, ?, 'Journal', ?, ?, 'Approved', ?, ?, 0)");
                            $vIns->execute([$v_no, $inv_date, $ref, $desc, $inv['created_by'] ?: 1, $tid]);
                            $new_v_id = (int)$pdo->lastInsertId();

                            $eIns = $pdo->prepare("INSERT INTO acc_ledger_entries (voucher_id, account_id, debit, credit, tenant_id) VALUES (?, ?, ?, ?, ?)");
                            // Dr Student Fees Receivable (1101)
                            $eIns->execute([$new_v_id, $rec_acc_id, (float)$inv['amount'], 0, $tid]);
                            // Cr Fee Income (4001)
                            $eIns->execute([$new_v_id, $fee_acc_id, 0, (float)$inv['amount'], $tid]);
                            $pdo->commit();

                            acc_log_audit($pdo, $tid, 'acc_vouchers', $new_v_id, 'BACKFILL_INVOICE_VOUCHER', null, ['invoice_no' => $ref, 'amount' => $inv['amount']], null, 'System Migration');
                        }
                    }
                }
            }
        } catch (Throwable $eInv) {
            $t_info['changes'][] = "Invoice scan skipped/not applicable: " . $eInv->getMessage();
        }

        $report['tenants_processed'][] = $t_info;
    }

    return $report;
}

// Execute migration if requested
$result = run_accounting_truth_migration($pdo, $is_dry_run);

if (php_sapi_name() === 'cli') {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
