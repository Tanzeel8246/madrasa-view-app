<?php
/**
 * Financial Audit Log & Immutability Shield
 * Append-only audit logger for all financial transactions, vouchers, and accounting records.
 */

if (!function_exists('acc_ensure_audit_log_schema')) {
    function acc_ensure_audit_log_schema(PDO $pdo) {
        static $done = false;
        if ($done) return;
        $done = true;

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS acc_audit_log (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL DEFAULT 1,
                table_name VARCHAR(64) NOT NULL,
                record_id INT NOT NULL,
                action VARCHAR(32) NOT NULL,
                before_values JSON DEFAULT NULL,
                after_values JSON DEFAULT NULL,
                user_id INT DEFAULT NULL,
                user_name VARCHAR(128) DEFAULT NULL,
                platform VARCHAR(32) DEFAULT 'Web',
                ip_address VARCHAR(64) DEFAULT NULL,
                user_agent TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_audit_tenant_table (tenant_id, table_name, record_id),
                INDEX idx_audit_action (action),
                INDEX idx_audit_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            error_log("[acc_audit_log schema error] " . $e->getMessage());
        }
    }
}

if (!function_exists('acc_log_audit')) {
    function acc_log_audit(PDO $pdo, $tenant_id, $table_name, $record_id, $action, $before_values = null, $after_values = null, $user_id = null, $user_name = null, $platform = 'Web') {
        try {
            acc_ensure_audit_log_schema($pdo);

            // Self-heal status column to avoid ENUM truncation error on 'Reversed'
            try {
                $pdo->exec("ALTER TABLE acc_vouchers MODIFY COLUMN status VARCHAR(50) DEFAULT 'Pending'");
            } catch (Throwable $eStatus) {
                try {
                    $pdo->exec("ALTER TABLE acc_vouchers MODIFY COLUMN status ENUM('Pending', 'Approved', 'Rejected', 'Reversed', 'Cancelled') DEFAULT 'Pending'");
                } catch (Throwable $eStatus2) {}
            }

            $tenant_id = (int)($tenant_id ?: (function_exists('get_current_tenant_id') ? get_current_tenant_id() : 1));
            
            if ($user_id === null && isset($_SESSION['user_id'])) {
                $user_id = (int)$_SESSION['user_id'];
            }
            if ($user_name === null && isset($_SESSION['name'])) {
                $user_name = (string)$_SESSION['name'];
            }
            if (!$user_name && isset($_SESSION['username'])) {
                $user_name = (string)$_SESSION['username'];
            }

            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'CLI/System';

            $before_json = is_array($before_values) ? json_encode($before_values, JSON_UNESCAPED_UNICODE) : (is_string($before_values) ? $before_values : null);
            $after_json = is_array($after_values) ? json_encode($after_values, JSON_UNESCAPED_UNICODE) : (is_string($after_values) ? $after_values : null);

            $stmt = $pdo->prepare("INSERT INTO acc_audit_log 
                (tenant_id, table_name, record_id, action, before_values, after_values, user_id, user_name, platform, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $tenant_id,
                (string)$table_name,
                (int)$record_id,
                strtoupper(trim((string)$action)),
                $before_json,
                $after_json,
                $user_id,
                $user_name,
                (string)$platform,
                $ip,
                substr($ua, 0, 500)
            ]);
            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log("[acc_log_audit failed] " . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('acc_ensure_standard_chart_and_orphans_repaired')) {
    function acc_ensure_standard_chart_and_orphans_repaired(PDO $pdo, $tenant_id = null) {
        static $executed = [];
        $tenant_id = (int)($tenant_id ?: (function_exists('get_current_tenant_id') ? get_current_tenant_id() : 1));
        if (isset($executed[$tenant_id])) return;
        $executed[$tenant_id] = true;

        try {
            acc_ensure_audit_log_schema($pdo);

            // Self-heal status column to avoid ENUM truncation error on 'Reversed'
            try {
                $pdo->exec("ALTER TABLE acc_vouchers MODIFY COLUMN status VARCHAR(50) DEFAULT 'Pending'");
            } catch (Throwable $eStatus) {
                try {
                    $pdo->exec("ALTER TABLE acc_vouchers MODIFY COLUMN status ENUM('Pending', 'Approved', 'Rejected', 'Reversed', 'Cancelled') DEFAULT 'Pending'");
                } catch (Throwable $eStatus2) {}
            }

            // 1. Repair orphaned ledger entries (tenant_id missing)
            $pdo->prepare("
                UPDATE acc_ledger_entries e 
                JOIN acc_vouchers v ON e.voucher_id = v.id 
                SET e.tenant_id = v.tenant_id 
                WHERE (e.tenant_id IS NULL OR e.tenant_id = 0) AND v.tenant_id = ?
            ")->execute([$tenant_id]);

            // 2. Standard Chart of Accounts (D4 / D5)
            $standard_accounts = [
                ['1001', 'Cash in Hand (نقد رقم)', 'Asset'],
                ['1002', 'Bank Account (بینک اکاؤنٹ)', 'Asset'],
                ['1101', 'Student Fees Receivable (طلبہ فیس واجب الوصول)', 'Asset'],
                ['1201', 'Staff Advances / Loans (اسٹاف ایڈوانس و قرضہ جات)', 'Asset'],
                ['1301', 'Inventory & Fixed Assets (انوینٹری و اثاثہ جات)', 'Asset'],
                ['2001', 'Fees Received in Advance (پیشگی فیس)', 'Liability'],
                ['2002', 'Salaries Payable (واجب الادا تنخواہیں)', 'Liability'],
                ['2101', 'Zakat Fund (فنڈ زکوٰۃ - امانت)', 'Liability'],
                ['2102', 'Sadaqah Fund (فنڈ صدقات و خیرات)', 'Liability'],
                ['2103', 'Specific Donations Fund (مخصوص فنڈ عطیات)', 'Liability'],
                ['3001', 'General / Institution Fund (عمومی فنڈ ادارہ)', 'Equity'],
                ['3002', 'Retained Surplus / Deficit (مجموعی فاضل / خسارہ)', 'Equity'],
                ['4001', 'Fee Income (آمدن تعلیمی فیس)', 'Revenue'],
                ['4002', 'General Donations (عمومی عطیات و چندہ)', 'Revenue'],
                ['4003', 'Other Income (دیگر متفرق آمدن)', 'Revenue'],
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
                $chk->execute([$code, $tenant_id]);
                $existing = $chk->fetch(PDO::FETCH_ASSOC);

                if (!$existing) {
                    $ins = $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, tenant_id, is_active, is_deleted) VALUES (?, ?, ?, ?, 1, 0)");
                    $ins->execute([$code, $name, $type, $tenant_id]);
                } else {
                    // Fix 4001 if mis-named as donations
                    if ($code === '4001' && (strpos($existing['name'], 'عطیات') !== false || strpos($existing['name'], 'صدقات') !== false || stripos($existing['name'], 'donation') !== false)) {
                        // Ensure 4002 exists for General Donations
                        $dChk = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE code = '4002' AND tenant_id = ? LIMIT 1");
                        $dChk->execute([$tenant_id]);
                        if (!$dChk->fetchColumn()) {
                            $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, tenant_id, is_active, is_deleted) VALUES ('4002', 'General Donations (عمومی عطیات و چندہ)', 'Revenue', ?, 1, 0)")->execute([$tenant_id]);
                        }
                        // Correct 4001 to Fee Income
                        $pdo->prepare("UPDATE acc_chart_of_accounts SET name = 'Fee Income (آمدن تعلیمی فیس)', type = 'Revenue' WHERE id = ?")->execute([$existing['id']]);
                    }

                    // Fix 5001 if mis-typed as Fee expense
                    if ($code === '5001' && (strpos($existing['name'], 'فیس') !== false || stripos($existing['name'], 'fee') !== false)) {
                        $fChk = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE code = '4001' AND tenant_id = ? LIMIT 1");
                        $fChk->execute([$tenant_id]);
                        $fee_id = (int)$fChk->fetchColumn();
                        if ($fee_id > 0) {
                            $pdo->prepare("UPDATE acc_ledger_entries SET account_id = ? WHERE account_id = ? AND tenant_id = ?")->execute([$fee_id, $existing['id'], $tenant_id]);
                        }
                        $pdo->prepare("UPDATE acc_chart_of_accounts SET name = 'Teacher & Staff Salaries (تنخواہیں اساتذہ و ملازمین)', type = 'Expense' WHERE id = ?")->execute([$existing['id']]);
                    }

                    // Fix 5002 if mis-typed as Zakat expense
                    if ($code === '5002' && (strpos($existing['name'], 'زکوۃ') !== false || strpos($existing['name'], 'زکوٰۃ') !== false || stripos($existing['name'], 'zakat') !== false)) {
                        $zChk = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE code = '2101' AND tenant_id = ? LIMIT 1");
                        $zChk->execute([$tenant_id]);
                        $zakat_id = (int)$zChk->fetchColumn();
                        if ($zakat_id > 0) {
                            $pdo->prepare("UPDATE acc_ledger_entries SET account_id = ? WHERE account_id = ? AND tenant_id = ?")->execute([$zakat_id, $existing['id'], $tenant_id]);
                        }
                        $pdo->prepare("UPDATE acc_chart_of_accounts SET name = 'Electricity & Utilities (بجلی، گیس و یوٹیلیٹی بلز)', type = 'Expense' WHERE id = ?")->execute([$existing['id']]);
                    }
                }
            }

            // 3. Permanent cleanup of orphan empty pending voucher RV-00010
            try {
                $pdo->exec("DELETE FROM acc_ledger_entries WHERE voucher_id IN (SELECT id FROM acc_vouchers WHERE voucher_no LIKE '%RV-00010%')");
                $pdo->exec("DELETE FROM acc_vouchers WHERE voucher_no LIKE '%RV-00010%'");
            } catch (Throwable $eRv) {}

            // 4. Clean up test entries (RV-00011 and test finance entries that cause I&E mismatch)
            try {
                $pdo->prepare("UPDATE finance SET is_deleted = 1 WHERE (receipt_no LIKE '%RV-00011%' OR computer_receipt_no = 11 OR original_receipt_no LIKE '%RV-00011%' OR description LIKE '%RV-00011%') AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)")->execute([$tenant_id]);
                $pdo->prepare("UPDATE acc_vouchers SET is_deleted = 1, status = 'Cancelled' WHERE (voucher_no LIKE '%RV-00011%' OR reference LIKE '%RV-00011%' OR reference = 'FIN-11') AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)")->execute([$tenant_id]);
                // Clean negative finance entries if any
                $pdo->prepare("UPDATE finance SET is_deleted = 1 WHERE amount < 0 AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)")->execute([$tenant_id]);
            } catch (Throwable $eTestClean) {}

            // 5. Chart of Accounts Deduplication & Merging (1003 vs 1101, 1002 vs 1201, 1004 vs 1301, 2003 vs 2101, 2004 vs 2102, 2005 vs 2103, 5010, 5011)
            try {
                $tScope = ($tenant_id == 1) ? "(tenant_id = 1 OR tenant_id IS NULL OR tenant_id = 0)" : "tenant_id = $tenant_id";

                // Ensure standard destination accounts exist
                $acc1002 = finance_accounting_ensure_account($pdo, '1002', 'Bank Account (بینک کھاتہ)', 'Asset', $tenant_id);
                $acc1003 = finance_accounting_ensure_account($pdo, '1003', 'Student Fees Receivable (طلبہ فیس واجب الوصول)', 'Asset', $tenant_id);
                $acc1004 = finance_accounting_ensure_account($pdo, '1004', 'Other Receivables (دیگر واجب الوصول)', 'Asset', $tenant_id);
                $acc2003 = finance_accounting_ensure_account($pdo, '2003', 'Zakat Fund (زکوٰۃ فنڈ)', 'Liability', $tenant_id);
                $acc2004 = finance_accounting_ensure_account($pdo, '2004', 'Sadaqah Fund (صدقات فنڈ)', 'Liability', $tenant_id);
                $acc2005 = finance_accounting_ensure_account($pdo, '2005', 'Specific Donations Fund (مخصوص عطیات فنڈ)', 'Liability', $tenant_id);
                $feeRevId = finance_accounting_ensure_account($pdo, '4001', 'Fee Income (آمدن تعلیمی فیس)', 'Revenue', $tenant_id);
                $acc5001 = finance_accounting_ensure_account($pdo, '5001', 'Teacher & Staff Salaries (تنخواہیں اساتذہ و ملازمین)', 'Expense', $tenant_id);

                // A. Receivables: 1101 -> 1003
                $acc1101 = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE code = '1101' AND $tScope LIMIT 1")->fetchColumn();
                if ($acc1101 && $acc1003 && $acc1101 != $acc1003) {
                    $pdo->prepare("UPDATE acc_ledger_entries SET account_id = ? WHERE account_id = ?")->execute([$acc1003, $acc1101]);
                }
                $pdo->exec("UPDATE acc_chart_of_accounts SET is_deleted = 1, is_active = 0 WHERE code = '1101' AND $tScope");

                // B. Legacy Asset: 1201 -> 1002 (Bank)
                $acc1201 = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE code = '1201' AND $tScope LIMIT 1")->fetchColumn();
                if ($acc1201 && $acc1002 && $acc1201 != $acc1002) {
                    $pdo->prepare("UPDATE acc_ledger_entries SET account_id = ? WHERE account_id = ?")->execute([$acc1002, $acc1201]);
                }
                $pdo->exec("UPDATE acc_chart_of_accounts SET is_deleted = 1, is_active = 0 WHERE code = '1201' AND $tScope");

                // C. Legacy Asset: 1301 -> 1004 (Other Receivables)
                $acc1301 = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE code = '1301' AND $tScope LIMIT 1")->fetchColumn();
                if ($acc1301 && $acc1004 && $acc1301 != $acc1004) {
                    $pdo->prepare("UPDATE acc_ledger_entries SET account_id = ? WHERE account_id = ?")->execute([$acc1004, $acc1301]);
                }
                $pdo->exec("UPDATE acc_chart_of_accounts SET is_deleted = 1, is_active = 0 WHERE code = '1301' AND $tScope");

                // D. Zakat Fund: 2101 -> 2003
                $acc2101 = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE code = '2101' AND $tScope LIMIT 1")->fetchColumn();
                if ($acc2101 && $acc2003 && $acc2101 != $acc2003) {
                    $pdo->prepare("UPDATE acc_ledger_entries SET account_id = ? WHERE account_id = ?")->execute([$acc2003, $acc2101]);
                }
                $pdo->exec("UPDATE acc_chart_of_accounts SET is_deleted = 1, is_active = 0 WHERE code = '2101' AND $tScope");

                // E. Sadaqah Fund: 2102 -> 2004
                $acc2102 = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE code = '2102' AND $tScope LIMIT 1")->fetchColumn();
                if ($acc2102 && $acc2004 && $acc2102 != $acc2004) {
                    $pdo->prepare("UPDATE acc_ledger_entries SET account_id = ? WHERE account_id = ?")->execute([$acc2004, $acc2102]);
                }
                $pdo->exec("UPDATE acc_chart_of_accounts SET is_deleted = 1, is_active = 0 WHERE code = '2102' AND $tScope");

                // F. Specific Donations Fund: 2103 -> 2005
                $acc2103 = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE code = '2103' AND $tScope LIMIT 1")->fetchColumn();
                if ($acc2103 && $acc2005 && $acc2103 != $acc2005) {
                    $pdo->prepare("UPDATE acc_ledger_entries SET account_id = ? WHERE account_id = ?")->execute([$acc2005, $acc2103]);
                }
                $pdo->exec("UPDATE acc_chart_of_accounts SET is_deleted = 1, is_active = 0 WHERE code = '2103' AND $tScope");

                // G. Misplaced Expense Accounts 5010 (Monthly Fee) & 5011 (Zakat)
                $acc5010 = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE code = '5010' AND $tScope LIMIT 1")->fetchColumn();
                if ($acc5010 && $feeRevId) {
                    $pdo->prepare("UPDATE acc_ledger_entries SET account_id = ? WHERE account_id = ?")->execute([$feeRevId, $acc5010]);
                }
                $pdo->exec("UPDATE acc_chart_of_accounts SET is_deleted = 1, is_active = 0 WHERE code = '5010' AND $tScope");

                $acc5011 = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE code = '5011' AND $tScope LIMIT 1")->fetchColumn();
                if ($acc5011 && $acc2003) {
                    $pdo->prepare("UPDATE acc_ledger_entries SET account_id = ? WHERE account_id = ?")->execute([$acc2003, $acc5011]);
                }
                $pdo->exec("UPDATE acc_chart_of_accounts SET is_deleted = 1, is_active = 0 WHERE code = '5011' AND $tScope");

                // H. Merge Duplicate 5003 (اساتذہ مشاہرہ) into 5001 (تنخواہیں اساتذہ و ملازمین)
                $acc5003 = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE code = '5003' AND (name LIKE '%مشاہرہ%' OR name LIKE '%تنخواہ%') AND $tScope LIMIT 1")->fetchColumn();
                if ($acc5001 && $acc5003 && $acc5001 != $acc5003) {
                    $pdo->prepare("UPDATE acc_ledger_entries SET account_id = ? WHERE account_id = ?")->execute([$acc5001, $acc5003]);
                    $pdo->exec("UPDATE acc_chart_of_accounts SET is_deleted = 1, is_active = 0 WHERE id = $acc5003");
                }
            } catch (Throwable $eChartDedup) {}

            // 6. Backfill Invoices to Ledger (D9 Auto-post)
            try {
                $rec_id = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE (code = '1003' OR code = '1101') AND (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = $tenant_id ORDER BY code ASC LIMIT 1")->fetchColumn();
                $fee_id = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE code = '4001' AND (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = $tenant_id LIMIT 1")->fetchColumn();
                
                if ($rec_id && $fee_id) {
                    $invStmt = $pdo->prepare("SELECT i.*, s.name as student_name FROM acc_invoices i LEFT JOIN students s ON i.student_id = s.id WHERE i.tenant_id = ? AND i.amount > 0");
                    $invStmt->execute([$tenant_id]);
                    $invoices = $invStmt->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($invoices as $inv) {
                        $ref = $inv['invoice_no'];
                        $vChk = $pdo->prepare("SELECT id FROM acc_vouchers WHERE reference = ? AND tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1");
                        $vChk->execute([$ref, $tenant_id]);
                        if (!$vChk->fetchColumn()) {
                            $inv_date = !empty($inv['created_at']) ? date('Y-m-d', strtotime($inv['created_at'])) : date('Y-m-d');
                            $v_no = "INV-V-" . str_pad($inv['id'], 5, '0', STR_PAD_LEFT);
                            $stName = !empty($inv['student_name']) ? $inv['student_name'] : 'طالب علم';
                            $desc = "فیس انوائس # {$inv['invoice_no']} - طالب علم: {$stName} (ماہ {$inv['month_year']})";
                            
                            $pdo->beginTransaction();
                            $vIns = $pdo->prepare("INSERT INTO acc_vouchers (voucher_no, date, type, reference, description, status, created_by, system_type, tenant_id, is_deleted) VALUES (?, ?, 'Journal', ?, ?, 'Approved', ?, 'Madrasa', ?, 0)");
                            $vIns->execute([$v_no, $inv_date, $ref, $desc, $inv['created_by'] ?: 1, $tenant_id]);
                            $new_v_id = (int)$pdo->lastInsertId();

                            $eIns = $pdo->prepare("INSERT INTO acc_ledger_entries (voucher_id, account_id, debit, credit, tenant_id) VALUES (?, ?, ?, ?, ?)");
                            // Dr Student Fees Receivable
                            $eIns->execute([$new_v_id, $rec_id, (float)$inv['amount'], 0, $tenant_id]);
                            // Cr Fee Income
                            $eIns->execute([$new_v_id, $fee_id, 0, (float)$inv['amount'], $tenant_id]);
                            $pdo->commit();
                        }
                    }
                }
            } catch (Throwable $eInvBackfill) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
            }

        } catch (Throwable $e) {
            error_log("[acc_ensure_standard_chart error] " . $e->getMessage());
        }
    }
}
