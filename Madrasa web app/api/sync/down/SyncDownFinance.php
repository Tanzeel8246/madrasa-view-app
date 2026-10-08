<?php
/**
 * Sync Down Finance & Accounting Module
 * Madrasa Ashraf-ul-Madaris
 */

class SyncDownFinance {
    public static function process(SyncContext $ctx, &$data) {
        $pdo = $ctx->pdo;
        $user = $ctx->user;
        $userRole = $ctx->userRole;
        $since_date = $ctx->since_date;
        $since_dt = $ctx->since_dt;
        $requested_sys = $ctx->requestedSys;
        $tId = (int)$ctx->tenantId;
        $tCond = ($tId === 1) ? "(tenant_id = 1 OR tenant_id IS NULL OR tenant_id = 0)" : "tenant_id = $tId";

        if ($userRole === 'student' || $userRole === 'parent') {
            $studentIds = [];
            if ($userRole === 'student') {
                $studentIds[] = (int)$user['id'];
            } else {
                $pid = (int)$user['id'];
                $stStmt = $pdo->prepare("SELECT student_id FROM student_parents WHERE parent_id = ?");
                $stStmt->execute([$pid]);
                $studentIds = $stStmt->fetchAll(PDO::FETCH_COLUMN);
            }

            if (empty($studentIds)) {
                $data['finance_history'] = [];
            } else {
                $inClause = implode(',', array_map('intval', $studentIds));
                $data['finance_history'] = $ctx->fetchAll("
                    SELECT f.*, COALESCE(MONTH(f.date), 0) AS month, COALESCE(YEAR(f.date), 0) AS year 
                    FROM fee_submissions f 
                    WHERE f.student_id IN ($inClause) AND (f.tenant_id = $tId OR f.tenant_id IS NULL)" . ($since_date ? " AND f.date >= '$since_date'" : "") . "
                    ORDER BY f.date DESC, f.id DESC
                    LIMIT 500
                ");
            }
            return;
        }

        $isAdminRole = in_array(strtolower((string)$userRole), ['admin', 'superadmin', 'super_admin', 'administrator'], true);

        $can_access_finance = (
            $isAdminRole ||
            strtolower((string)$userRole) === 'nazim' ||
            has_permission($userRole, $user['permissions'] ?? [], 'manage_finance', $requested_sys) ||
            has_permission($userRole, $user['permissions'] ?? [], 'manage_masjid_finance', $requested_sys) ||
            has_permission($userRole, $user['permissions'] ?? [], 'manage_masjid_finance_entry', $requested_sys) ||
            has_permission($userRole, $user['permissions'] ?? [], 'manage_masjid_finance_report', $requested_sys) ||
            in_array('manage_finance', (array)($user['permissions'] ?? [])) ||
            in_array('manage_masjid_finance', (array)($user['permissions'] ?? []))
        );

        // Auto-ensure required columns on finance & payroll tables
        try { $pdo->exec("ALTER TABLE finance ADD COLUMN tenant_id INT NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE finance ADD COLUMN system_type VARCHAR(50) DEFAULT 'Madrasa'"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE finance ADD COLUMN is_deleted TINYINT(1) DEFAULT 0"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE finance ADD COLUMN account_id INT DEFAULT NULL"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE finance ADD COLUMN payment_method VARCHAR(50) DEFAULT 'Cash'"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE masjid_finance ADD COLUMN tenant_id INT NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE masjid_finance ADD COLUMN account_id INT DEFAULT NULL"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE masjid_finance ADD COLUMN payment_method VARCHAR(50) DEFAULT 'Cash'"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE acc_payroll ADD COLUMN tenant_id INT NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE acc_payroll ADD COLUMN payment_account_id INT DEFAULT NULL"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE acc_payroll ADD COLUMN payment_method VARCHAR(50) DEFAULT 'Cash'"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE heads ADD COLUMN tenant_id INT NOT NULL DEFAULT 1"); } catch (Throwable $e) {}

        try { $pdo->exec("ALTER TABLE finance ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE finance ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE masjid_finance ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE masjid_finance ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); } catch (Throwable $e) {}

        if ($can_access_finance) {
            $financeDateCond = "";
            if ($since_dt) {
                $financeDateCond = " AND (updated_at >= '$since_dt' OR created_at >= '$since_dt')";
            } elseif ($since_date) {
                $financeDateCond = " AND date >= '$since_date'";
            } else {
                $financeDateCond = " AND (is_deleted = 0 OR is_deleted IS NULL)";
            }

            if ($isAdminRole) {
                $fRows = $ctx->fetchAll("SELECT * FROM finance WHERE $tCond AND (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '') $financeDateCond ORDER BY date DESC, id DESC LIMIT 5000");
                if (empty($fRows) && !$since_dt && !$since_date) {
                    $fRows = $ctx->fetchAll("SELECT * FROM finance WHERE (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '') AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY date DESC, id DESC LIMIT 5000");
                }
                $data['finance_history'] = $fRows;

                $masjidFinanceRows = $ctx->fetchAll("SELECT * FROM finance WHERE $tCond AND system_type = 'Masjid' $financeDateCond ORDER BY date DESC, id DESC LIMIT 5000");
                if (empty($masjidFinanceRows) && !$since_dt && !$since_date) {
                    $masjidFinanceRows = $ctx->fetchAll("SELECT * FROM finance WHERE system_type = 'Masjid' AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY date DESC, id DESC LIMIT 5000");
                }
                try {
                    $hasMasjidFinTable = !empty($pdo->query("SHOW TABLES LIKE 'masjid_finance'")->fetchAll());
                    if ($hasMasjidFinTable) {
                        $mRows = $ctx->fetchAll("SELECT * FROM masjid_finance WHERE $tCond $financeDateCond ORDER BY date DESC, id DESC LIMIT 5000");
                        if (empty($mRows) && !$since_dt && !$since_date) {
                            $mRows = $ctx->fetchAll("SELECT * FROM masjid_finance WHERE (is_deleted = 0 OR is_deleted IS NULL) ORDER BY date DESC, id DESC LIMIT 5000");
                        }
                        if (!empty($mRows)) {
                            $masjidFinanceRows = array_merge($masjidFinanceRows, $mRows);
                        }
                    }
                } catch (Exception $e) {}
                $data['masjid_finance_history'] = $masjidFinanceRows;
            } else {
                $uid = intval($user['id'] ?? 0);
                $uName = trim((string)($user['name'] ?? ''));
                $uUsername = trim((string)($user['username'] ?? ''));
                
                // Construct robust user condition to capture all records owned by user ID or user name
                $uCondParts = ["user_id = $uid"];
                if ($uName !== '') {
                    $uCondParts[] = "user_name = " . $pdo->quote($uName);
                }
                if ($uUsername !== '') {
                    $uCondParts[] = "user_name = " . $pdo->quote($uUsername);
                }
                // Special alias for Saleem (ID 3)
                if ($uid === 3 || mb_stripos($uUsername, 'saleem') !== false || mb_stripos($uName, 'سلیم') !== false) {
                    $uCondParts[] = "user_name LIKE '%سلیم%'";
                    $uCondParts[] = "user_name LIKE '%saleem%'";
                }
                $userCond = "(" . implode(' OR ', array_unique($uCondParts)) . ")";

                $data['finance_history'] = $ctx->fetchAll("SELECT * FROM finance WHERE $userCond AND $tCond AND (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '') $financeDateCond ORDER BY date DESC, id DESC LIMIT 5000");
                $masjidFinanceRows = $ctx->fetchAll("SELECT * FROM finance WHERE $userCond AND $tCond AND system_type = 'Masjid' $financeDateCond ORDER BY date DESC, id DESC LIMIT 5000");
                try {
                    $hasMasjidFinTable = !empty($pdo->query("SHOW TABLES LIKE 'masjid_finance'")->fetchAll());
                    if ($hasMasjidFinTable) {
                        $mRows = $ctx->fetchAll("SELECT * FROM masjid_finance WHERE $userCond AND $tCond $financeDateCond ORDER BY date DESC, id DESC LIMIT 5000");
                        if (!empty($mRows)) {
                            $masjidFinanceRows = array_merge($masjidFinanceRows, $mRows);
                        }
                    }
                } catch (Exception $e) {}
                $data['masjid_finance_history'] = $masjidFinanceRows;
            }
            // Send deleted finance IDs so mobile client frees receipt numbers and updates local cache
            try {
                $delCond = $since_dt
                    ? " AND (updated_at >= '$since_dt' OR created_at >= '$since_dt')"
                    : "";
                $delRows = $ctx->fetchAll("SELECT id FROM finance WHERE $tCond AND is_deleted = 1 $delCond LIMIT 5000");
                $data['deleted_finance_ids'] = array_map(function($r) { return (int)$r['id']; }, $delRows);
            } catch (Throwable $eDelSync) {
                $data['deleted_finance_ids'] = [];
            }
            sync_all_heads_with_chart_and_departments($pdo);
            try { $pdo->exec("ALTER TABLE heads ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE heads ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
            $headDateCond = $since_dt ? " AND (updated_at >= '$since_dt' OR created_at >= '$since_dt')" : " AND (is_deleted = 0 OR is_deleted IS NULL)";
            $data['heads'] = $ctx->fetchAll("SELECT * FROM heads WHERE $tCond $headDateCond ORDER BY type ASC, name ASC LIMIT 1000");
            $data['fee_submissions'] = $ctx->fetchAll("SELECT * FROM fee_submissions WHERE $tCond " . ($since_dt ? "AND (updated_at >= '$since_dt' OR created_at >= '$since_dt')" : ($since_date ? "AND date >= '$since_date'" : "")) . " ORDER BY id DESC LIMIT 1000");
        }

        // ERP / Accounting / Payroll / Loans
        if ($isAdminRole || strtolower((string)$userRole) === 'nazim' || strtolower((string)$userRole) === 'teacher' || has_permission($userRole, $user['permissions'] ?? [], 'manage_erp', $requested_sys) || has_permission($userRole, $user['permissions'] ?? [], 'manage_finance', $requested_sys) || has_permission($userRole, $user['permissions'] ?? [], 'manage_finance_entry', $requested_sys) || has_permission($userRole, $user['permissions'] ?? [], 'manage_simple_finance', $requested_sys) || has_permission($userRole, $user['permissions'] ?? [], 'manage_teachers', $requested_sys) || has_permission($userRole, $user['permissions'] ?? [], 'manage_loans', $requested_sys) || has_permission($userRole, $user['permissions'] ?? [], 'manage_stipends', $requested_sys) || has_permission($userRole, $user['permissions'] ?? [], 'manage_masjid_stipends', $requested_sys)) {
            finance_accounting_backfill($pdo);
            try { $pdo->exec("ALTER TABLE departments ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE departments ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE acc_chart_of_accounts ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE acc_chart_of_accounts ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE acc_vouchers ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE acc_ledger_entries ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE acc_payroll ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE staff_loans ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE loan_transactions ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); } catch (Throwable $e) {}

            $deptDateCond = $since_dt ? " AND (updated_at >= '$since_dt' OR created_at >= '$since_dt')" : "";
            $data['departments'] = $ctx->fetchAll("SELECT * FROM departments WHERE $tCond $deptDateCond ORDER BY name ASC LIMIT 1000");

            $accDateCond = $since_dt ? " AND (updated_at >= '$since_dt' OR created_at >= '$since_dt')" : "";
            $data['acc_chart_of_accounts'] = $ctx->fetchAll("SELECT * FROM acc_chart_of_accounts WHERE $tCond $accDateCond ORDER BY code ASC LIMIT 1000");

            $voucherDateCond = $since_dt 
                ? " AND (updated_at >= '$since_dt' OR created_at >= '$since_dt')"
                : ($since_date ? " AND (date >= '$since_date' OR created_at >= '$since_date' OR type = 'Contra' OR reference LIKE 'TRANSFER-%')" : "");
            $data['acc_vouchers'] = $ctx->fetchAll("SELECT * FROM acc_vouchers WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL)$voucherDateCond ORDER BY date DESC, id DESC LIMIT 1000");

            $ledgerDateCond = $since_dt 
                ? " AND (e.created_at >= '$since_dt' OR v.updated_at >= '$since_dt' OR v.created_at >= '$since_dt')"
                : ($since_date ? " AND (v.date >= '$since_date' OR v.created_at >= '$since_date' OR v.type = 'Contra' OR v.reference LIKE 'TRANSFER-%')" : "");
            $data['acc_ledger_entries'] = $ctx->fetchAll("SELECT e.*, v.voucher_no FROM acc_ledger_entries e JOIN acc_vouchers v ON e.voucher_id = v.id WHERE (v.tenant_id = $tId OR v.tenant_id IS NULL) AND (v.is_deleted = 0 OR v.is_deleted IS NULL)$ledgerDateCond ORDER BY e.id DESC LIMIT 5000");

            $payrollDateCond = $since_dt
                ? " AND (updated_at >= '$since_dt' OR created_at >= '$since_dt')"
                : ($since_date ? " AND month_year >= '" . date('Y-m', strtotime($since_date)) . "'" : "");
            $data['acc_payroll'] = $ctx->fetchAll("SELECT * FROM acc_payroll WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL)$payrollDateCond ORDER BY month_year DESC, id DESC LIMIT 5000");

            $invoiceDateCond = $since_dt
                ? " AND (updated_at >= '$since_dt' OR created_at >= '$since_dt')"
                : ($since_date ? " AND (date >= '$since_date' OR due_date >= '$since_date')" : "");
            $data['acc_invoices'] = $ctx->fetchAll("SELECT * FROM acc_invoices WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL)$invoiceDateCond ORDER BY id DESC LIMIT 1000");
        }

        // Additional fee permissions
        if ($userRole === 'admin' || $userRole === 'nazim' || has_permission($userRole, $user['permissions'] ?? [], 'manage_finance', $requested_sys)) {
            if (!isset($data['fee_submissions'])) {
                $data['fee_submissions'] = $ctx->fetchAll("SELECT * FROM fee_submissions WHERE $tCond " . ($since_dt ? "AND (updated_at >= '$since_dt' OR created_at >= '$since_dt')" : ($since_date ? "AND date >= '$since_date'" : "")) . " ORDER BY id DESC LIMIT 500");
            }
        }

        // In-Kind Donations & Loans
        $hasInKind = ($isAdminRole || has_permission($userRole, $user['permissions'] ?? [], 'manage_in_kind_donations', $requested_sys) || has_permission($userRole, $user['permissions'] ?? [], 'manage_masjid_in_kind_donations', $requested_sys));
        if ($hasInKind) {
            $inKindCond = $since_dt ? " AND (updated_at >= '$since_dt' OR created_at >= '$since_dt')" : ($since_date ? " AND date >= '$since_date'" : "");
            $data['in_kind_donations'] = $ctx->fetchAll("SELECT * FROM in_kind_donations WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL)$inKindCond ORDER BY date DESC, id DESC LIMIT 3000");
        } else {
            $data['in_kind_donations'] = [];
        }
        $loanDateCond = $since_dt ? " AND (updated_at >= '$since_dt' OR created_at >= '$since_dt')" : "";
        $data['staff_loans'] = $ctx->fetchAll("SELECT * FROM staff_loans WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL)$loanDateCond ORDER BY id DESC LIMIT 5000");
        $loanTxDateCond = $since_dt ? " AND created_at >= '$since_dt'" : ($since_date ? " AND date >= '$since_date'" : "");
        $data['loan_transactions'] = $ctx->fetchAll("SELECT * FROM loan_transactions WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL)$loanTxDateCond ORDER BY date DESC, id DESC LIMIT 5000");
    }
}
