<?php

if (!function_exists('finance_accounting_reference')) {
    function finance_accounting_reference($financeId) {
        return 'FIN-' . (int)$financeId;
    }
}

if (!function_exists('finance_accounting_ensure_account')) {
    function finance_accounting_ensure_account(PDO $pdo, $code, $name, $type, $tenant_id = null) {
        if ($tenant_id === null) $tenant_id = (int)get_current_tenant_id();
        $stmt = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE code = ? AND tenant_id = ? LIMIT 1");
        $stmt->execute([$code, $tenant_id]);
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;

        $insert = $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, tenant_id, is_deleted) VALUES (?, ?, ?, ?, 0)");
        $insert->execute([$code, $name, $type, $tenant_id]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('finance_accounting_delete_voucher')) {
    function finance_accounting_delete_voucher(PDO $pdo, $financeId, $tenant_id = null) {
        if ($tenant_id === null) $tenant_id = (int)get_current_tenant_id();
        $stmt = $pdo->prepare("UPDATE acc_vouchers SET is_deleted = 1 WHERE reference = ? AND tenant_id = ?");
        $stmt->execute([finance_accounting_reference($financeId), $tenant_id]);
    }
}

if (!function_exists('finance_accounting_post')) {
    function finance_accounting_post(PDO $pdo, $financeId) {
        $financeId = (int)$financeId;
        if ($financeId <= 0) return;

        $stmt = $pdo->prepare("SELECT * FROM finance WHERE id = ? LIMIT 1");
        $stmt->execute([$financeId]);
        $finance = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$finance) return;

        $tenant_id = (int)($finance['tenant_id'] ?? get_current_tenant_id());

        if ((int)($finance['is_deleted'] ?? 0) === 1) {
            finance_accounting_delete_voucher($pdo, $financeId, $tenant_id);
            return;
        }

        $amount = (float)($finance['amount'] ?? 0);
        if ($amount <= 0) {
            finance_accounting_delete_voucher($pdo, $financeId, $tenant_id);
            return;
        }

        $type = strtolower(trim((string)($finance['type'] ?? '')));
        $isExpense = $type === 'expense';

        // 1. Resolve payment / receipt asset account (Cash / Bank)
        $paymentAccountId = 0;
        if (!empty($finance['account_id'])) {
            try {
                $accChk = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE id = ? AND tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1");
                $accChk->execute([(int)$finance['account_id'], $tenant_id]);
                $paymentAccountId = (int)$accChk->fetchColumn();
            } catch (Throwable $e) {}
        }
        if ($paymentAccountId <= 0) {
            $paymentAccountId = finance_accounting_ensure_account($pdo, '1001', 'Cash in Hand', 'Asset', $tenant_id);
        }

        // 2. Resolve head / category account (Revenue / Expense)
        $headName = trim((string)($finance['head_name'] ?? ''));
        if ($headName === '' && !empty($finance['head_id'])) {
            try {
                $hStmt = $pdo->prepare("SELECT name FROM heads WHERE id = ? AND tenant_id = ? LIMIT 1");
                $hStmt->execute([(int)$finance['head_id'], $tenant_id]);
                $headName = trim((string)$hStmt->fetchColumn());
            } catch (Throwable $e) {}
        }
        $targetType = $isExpense ? 'Expense' : 'Revenue';
        $offsetId = 0;
        if ($headName !== '') {
            try {
                $findAcc = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) AND tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1");
                $findAcc->execute([$headName, $tenant_id]);
                $offsetId = (int)$findAcc->fetchColumn();
                if ($offsetId <= 0) {
                    $codePrefix = $isExpense ? '5' : '4';
                    $maxCodeStmt = $pdo->query("SELECT MAX(CAST(code AS UNSIGNED)) FROM acc_chart_of_accounts WHERE code LIKE '{$codePrefix}%' AND LENGTH(code) = 4 AND tenant_id = $tenant_id");
                    $maxCode = (int)($maxCodeStmt ? $maxCodeStmt->fetchColumn() : 0);
                    $nextCode = $maxCode > 0 ? (string)($maxCode + 1) : ($isExpense ? '5010' : '4010');
                    $insAcc = $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, tenant_id, is_deleted) VALUES (?, ?, ?, ?, 0)");
                    $insAcc->execute([$nextCode, $headName, $targetType, $tenant_id]);
                    $offsetId = (int)$pdo->lastInsertId();
                }
            } catch (Throwable $e) {}
        }
        if ($offsetId <= 0 && !empty($finance['head_id'])) {
            try {
                $coaChk = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE id = ? AND tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1");
                $coaChk->execute([(int)$finance['head_id'], $tenant_id]);
                $offsetId = (int)$coaChk->fetchColumn();
            } catch (Throwable $e) {}
        }
        if ($offsetId <= 0) {
            $offsetId = $isExpense
                ? finance_accounting_ensure_account($pdo, '5003', 'Maintenance', 'Expense', $tenant_id)
                : finance_accounting_ensure_account($pdo, '4002', 'Donation / Sadqa', 'Revenue', $tenant_id);
        }

        $reference = finance_accounting_reference($financeId);
        $voucherNo = ($isExpense ? 'SF-PV-' : 'SF-RV-') . str_pad((string)$financeId, 6, '0', STR_PAD_LEFT);
        $description = trim((string)($finance['description'] ?? ''));
        if ($description === '') {
            $description = $isExpense ? 'Simple finance expense' : 'Simple finance income';
        }

        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $existing = $pdo->prepare("SELECT id FROM acc_vouchers WHERE reference = ? AND tenant_id = ? LIMIT 1");
            $existing->execute([$reference, $tenant_id]);
            $voucherId = $existing->fetchColumn();

            if ($voucherId) {
                $voucherId = (int)$voucherId;
                $update = $pdo->prepare("UPDATE acc_vouchers SET voucher_no = ?, date = ?, type = ?, description = ?, status = 'Approved', created_by = ?, tenant_id = ?, is_deleted = 0 WHERE id = ? AND tenant_id = ?");
                $update->execute([$voucherNo, $finance['date'], $isExpense ? 'Payment' : 'Receipt', $description, (int)($finance['user_id'] ?? 1), $tenant_id, $voucherId, $tenant_id]);
                $pdo->prepare("DELETE FROM acc_ledger_entries WHERE voucher_id = ? AND tenant_id = ?")->execute([$voucherId, $tenant_id]);
            } else {
                $insert = $pdo->prepare("INSERT INTO acc_vouchers (voucher_no, date, type, reference, description, status, created_by, tenant_id, is_deleted) VALUES (?, ?, ?, ?, ?, 'Approved', ?, ?, 0)");
                $insert->execute([$voucherNo, $finance['date'], $isExpense ? 'Payment' : 'Receipt', $reference, $description, (int)($finance['user_id'] ?? 1), $tenant_id]);
                $voucherId = (int)$pdo->lastInsertId();
            }

            $lines = $isExpense
                ? [[$offsetId, $amount, 0], [$paymentAccountId, 0, $amount]]
                : [[$paymentAccountId, $amount, 0], [$offsetId, 0, $amount]];
            $entry = $pdo->prepare("INSERT INTO acc_ledger_entries (voucher_id, account_id, debit, credit, tenant_id) VALUES (?, ?, ?, ?, ?)");
            foreach ($lines as $line) {
                $entry->execute([$voucherId, $line[0], $line[1], $line[2], $tenant_id]);
            }

            if ($ownTransaction) $pdo->commit();
        } catch (Exception $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('finance_accounting_backfill')) {
    function finance_accounting_backfill(PDO $pdo, $fromDate = null, $toDate = null) {
        // 1. Sync deleted vouchers with deleted finance entries (Cross-DB safe)
        try {
            $pdo->exec("UPDATE acc_vouchers SET is_deleted = 1 WHERE reference IN (SELECT CONCAT('FIN-', id) FROM finance WHERE is_deleted = 1)");
        } catch (Throwable $e) {
            try {
                $pdo->exec("UPDATE acc_vouchers SET is_deleted = 1 WHERE reference LIKE 'FIN-%' AND CAST(SUBSTR(reference, 5) AS INTEGER) IN (SELECT id FROM finance WHERE is_deleted = 1)");
            } catch (Throwable $e2) {}
        }

        // 2. Clean orphan/unlinked SF- and FIN- vouchers whose finance record is deleted or missing
        try {
            $pdo->exec("UPDATE acc_vouchers SET is_deleted = 1 WHERE reference LIKE 'FIN-%' AND CAST(SUBSTR(reference, 5) AS UNSIGNED) NOT IN (SELECT id FROM finance WHERE (is_deleted = 0 OR is_deleted IS NULL))");
        } catch (Throwable $e) {}
        
        // 3. Sync deleted vouchers with deleted/cancelled payroll entries (Cross-DB safe)
        try {
            $pdo->exec("UPDATE acc_vouchers SET is_deleted = 1 WHERE reference IN (SELECT CONCAT('PAYROLL-ACCRUAL-', id) FROM acc_payroll WHERE is_deleted = 1 OR status = 'Cancelled') OR reference IN (SELECT CONCAT('PAYROLL-PAYMENT-', id) FROM acc_payroll WHERE is_deleted = 1 OR status = 'Cancelled')");
        } catch (Throwable $e) {}

        // 4. Clean orphan ledger entries pointing to non-existent vouchers
        try {
            $pdo->exec("DELETE FROM acc_ledger_entries WHERE voucher_id NOT IN (SELECT id FROM acc_vouchers)");
        } catch (Throwable $e) {}

        // 5. Repair any ledger entries pointing to soft-deleted accounts that have an active counterpart
        try {
            $pdo->exec("
                UPDATE acc_ledger_entries e
                JOIN acc_chart_of_accounts d ON e.account_id = d.id AND d.is_deleted = 1
                JOIN acc_chart_of_accounts a ON LOWER(TRIM(d.name)) = LOWER(TRIM(a.name)) AND (a.is_deleted = 0 OR a.is_deleted IS NULL)
                SET e.account_id = a.id
            ");
        } catch (Throwable $e) {}

        $where = ["(is_deleted = 0 OR is_deleted IS NULL)", "amount > 0"];
        $args = [];

        if ($fromDate !== null && $fromDate !== '') {
            $where[] = "date >= ?";
            $args[] = $fromDate;
        }
        if ($toDate !== null && $toDate !== '') {
            $where[] = "date <= ?";
            $args[] = $toDate;
        }

        $sql = "SELECT id FROM finance WHERE " . implode(" AND ", $where) . " ORDER BY id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        while ($id = $stmt->fetchColumn()) {
            finance_accounting_post($pdo, (int)$id);
        }
    }
}

if (!function_exists('payroll_accounting_reference')) {
    function payroll_accounting_reference($payrollId, $kind) {
        return 'PAYROLL-' . strtoupper($kind) . '-' . (int)$payrollId;
    }
}

if (!function_exists('payroll_accounting_delete_voucher')) {
    function payroll_accounting_delete_voucher(PDO $pdo, $payrollId, $kind, $tenant_id = null) {
        if ($tenant_id === null) $tenant_id = (int)get_current_tenant_id();
        $stmt = $pdo->prepare("UPDATE acc_vouchers SET is_deleted = 1 WHERE reference = ? AND tenant_id = ?");
        $stmt->execute([payroll_accounting_reference($payrollId, $kind), $tenant_id]);
    }
}

if (!function_exists('payroll_accounting_upsert_voucher')) {
    function payroll_accounting_upsert_voucher(PDO $pdo, $reference, $voucherNo, $date, $type, $description, $createdBy, $lines, $tenant_id = null) {
        if ($tenant_id === null) $tenant_id = (int)get_current_tenant_id();
        $existing = $pdo->prepare("SELECT id FROM acc_vouchers WHERE reference = ? AND tenant_id = ? LIMIT 1");
        $existing->execute([$reference, $tenant_id]);
        $voucherId = $existing->fetchColumn();

        if ($voucherId) {
            $voucherId = (int)$voucherId;
            $update = $pdo->prepare("UPDATE acc_vouchers SET voucher_no = ?, date = ?, type = ?, description = ?, status = 'Approved', created_by = ?, tenant_id = ?, is_deleted = 0 WHERE id = ? AND tenant_id = ?");
            $update->execute([$voucherNo, $date, $type, $description, $createdBy, $tenant_id, $voucherId, $tenant_id]);
            $pdo->prepare("DELETE FROM acc_ledger_entries WHERE voucher_id = ? AND tenant_id = ?")->execute([$voucherId, $tenant_id]);
        } else {
            $insert = $pdo->prepare("INSERT INTO acc_vouchers (voucher_no, date, type, reference, description, status, created_by, tenant_id, is_deleted) VALUES (?, ?, ?, ?, ?, 'Approved', ?, ?, 0)");
            $insert->execute([$voucherNo, $date, $type, $reference, $description, $createdBy, $tenant_id]);
            $voucherId = (int)$pdo->lastInsertId();
        }

        $entry = $pdo->prepare("INSERT INTO acc_ledger_entries (voucher_id, account_id, debit, credit, tenant_id) VALUES (?, ?, ?, ?, ?)");
        foreach ($lines as $line) {
            $entry->execute([$voucherId, $line[0], $line[1], $line[2], $tenant_id]);
        }
    }
}

if (!function_exists('payroll_accounting_post')) {
    function payroll_accounting_post(PDO $pdo, $payrollId) {
        $payrollId = (int)$payrollId;
        if ($payrollId <= 0) return;

        $stmt = $pdo->prepare("SELECT p.*, t.name AS teacher_name FROM acc_payroll p LEFT JOIN teachers t ON t.id = p.teacher_id WHERE p.id = ? LIMIT 1");
        $stmt->execute([$payrollId]);
        $payroll = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payroll) return;

        $tenant_id = (int)($payroll['tenant_id'] ?? get_current_tenant_id());

        $net = isset($payroll['net_salary']) && $payroll['net_salary'] !== null
            ? (float)$payroll['net_salary']
            : ((float)($payroll['basic_salary'] ?? 0) + (float)($payroll['allowances'] ?? 0) - (float)($payroll['deductions'] ?? 0));
        if ($net <= 0 || (int)($payroll['is_deleted'] ?? 0) === 1 || ($payroll['status'] ?? '') === 'Cancelled') {
            payroll_accounting_delete_voucher($pdo, $payrollId, 'ACCRUAL', $tenant_id);
            payroll_accounting_delete_voucher($pdo, $payrollId, 'PAYMENT', $tenant_id);
            return;
        }

        $salaryExpenseId = finance_accounting_ensure_account($pdo, '5001', 'Teacher Salary', 'Expense', $tenant_id);
        $salaryPayableId = finance_accounting_ensure_account($pdo, '2002', 'Salaries Payable', 'Liability', $tenant_id);
        $paymentAccountId = 0;
        if (!empty($payroll['payment_account_id'])) {
            try {
                $accChk = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE id = ? AND tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1");
                $accChk->execute([(int)$payroll['payment_account_id'], $tenant_id]);
                $paymentAccountId = (int)$accChk->fetchColumn();
            } catch (Throwable $e) {}
        }
        if ($paymentAccountId <= 0) {
            $paymentAccountId = finance_accounting_ensure_account($pdo, '1001', 'Cash in Hand', 'Asset', $tenant_id);
        }
        $date = date('Y-m-t', strtotime(($payroll['month_year'] ?? date('Y-m')) . '-01'));
        $createdBy = (int)($payroll['created_by'] ?? 1);
        $teacherName = trim((string)($payroll['teacher_name'] ?? ''));
        $descSuffix = trim(($teacherName !== '' ? $teacherName . ' - ' : '') . ($payroll['month_year'] ?? ''));

        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            payroll_accounting_upsert_voucher(
                $pdo,
                payroll_accounting_reference($payrollId, 'ACCRUAL'),
                'PR-AC-' . str_pad((string)$payrollId, 6, '0', STR_PAD_LEFT),
                $date,
                'Journal',
                'Payroll accrual ' . $descSuffix,
                $createdBy,
                [[$salaryExpenseId, $net, 0], [$salaryPayableId, 0, $net]],
                $tenant_id
            );

            if (($payroll['status'] ?? '') === 'Paid') {
                $paymentDate = !empty($payroll['payment_date']) ? $payroll['payment_date'] : date('Y-m-d');
                payroll_accounting_upsert_voucher(
                    $pdo,
                    payroll_accounting_reference($payrollId, 'PAYMENT'),
                    'PR-PAY-' . str_pad((string)$payrollId, 6, '0', STR_PAD_LEFT),
                    $paymentDate,
                    'Payment',
                    'Payroll payment ' . $descSuffix,
                    $createdBy,
                    [[$salaryPayableId, $net, 0], [$paymentAccountId, 0, $net]],
                    $tenant_id
                );
            } else {
                payroll_accounting_delete_voucher($pdo, $payrollId, 'PAYMENT', $tenant_id);
            }

            if ($ownTransaction) $pdo->commit();
        } catch (Exception $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('finance_accounting_transfer_fund')) {
    function finance_accounting_transfer_fund(PDO $pdo, $fromAccountId, $toAccountId, $amount, $date = null, $description = '', $userId = 1, $systemType = 'Madrasa', $tenant_id = null) {
        if ($tenant_id === null) $tenant_id = (int)get_current_tenant_id();
        $fromAccountId = (int)$fromAccountId;
        $toAccountId = (int)$toAccountId;
        $amount = (float)$amount;
        if ($fromAccountId <= 0 || $toAccountId <= 0 || $fromAccountId === $toAccountId || $amount <= 0) {
            throw new InvalidArgumentException("Invalid transfer parameters: From and To accounts must be different valid accounts and amount must be positive.");
        }
        $date = !empty($date) ? $date : date('Y-m-d');
        $userId = (int)$userId ?: 1;

        // Ensure acc_vouchers schema is ready
        try { $pdo->exec("ALTER TABLE acc_vouchers ADD COLUMN is_deleted TINYINT(1) DEFAULT 0"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE acc_vouchers ADD COLUMN created_by INT DEFAULT NULL"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE acc_vouchers ADD COLUMN system_type VARCHAR(50) DEFAULT 'Madrasa'"); } catch (Throwable $e) {}

        $fromName = '';
        $toName = '';
        try {
            $fromName = (string)$pdo->query("SELECT name FROM acc_chart_of_accounts WHERE id = $fromAccountId AND tenant_id = $tenant_id")->fetchColumn();
            $toName = (string)$pdo->query("SELECT name FROM acc_chart_of_accounts WHERE id = $toAccountId AND tenant_id = $tenant_id")->fetchColumn();
        } catch (Throwable $e) {}
        if ($fromName === '') $fromName = 'Account #' . $fromAccountId;
        if ($toName === '') $toName = 'Account #' . $toAccountId;

        $voucherNo = 'CV-' . date('ymd') . '-' . rand(1000, 9999);
        for ($i = 0; $i < 5; $i++) {
            try {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM acc_vouchers WHERE voucher_no = ? AND tenant_id = ?");
                $chk->execute([$voucherNo, $tenant_id]);
                if ($chk->fetchColumn() == 0) break;
            } catch (Throwable $e) { break; }
            $voucherNo = 'CV-' . date('ymd') . '-' . rand(10000, 99999);
        }

        $reference = 'TRANSFER-' . uniqid();
        $desc = trim($description);
        if ($desc === '') {
            $desc = "رقم منتقلی از $fromName تا $toName (Fund Transfer)";
        }

        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare("INSERT INTO acc_vouchers (voucher_no, date, type, reference, description, status, created_by, system_type, tenant_id, is_deleted) VALUES (?, ?, 'Contra', ?, ?, 'Approved', ?, ?, ?, 0)");
            $insert->execute([$voucherNo, $date, $reference, $desc, $userId, $systemType, $tenant_id]);
            $voucherId = (int)$pdo->lastInsertId();

            // Receiving account debited (Asset increased)
            // Sending account credited (Asset decreased)
            $entry = $pdo->prepare("INSERT INTO acc_ledger_entries (voucher_id, account_id, debit, credit, tenant_id) VALUES (?, ?, ?, ?, ?)");
            $entry->execute([$voucherId, $toAccountId, $amount, 0, $tenant_id]);
            $entry->execute([$voucherId, $fromAccountId, 0, $amount, $tenant_id]);

            if ($ownTransaction) $pdo->commit();
            return $voucherId;
        } catch (Exception $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('finance_ensure_otp_and_bill_schema')) {
    function finance_ensure_otp_and_bill_schema(PDO $pdo) {
        static $done = false;
        if ($done) return;
        try {
            $pdo->exec("ALTER TABLE finance ADD COLUMN bill_image VARCHAR(255) NULL DEFAULT NULL");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE masjid_finance ADD COLUMN bill_image VARCHAR(255) NULL DEFAULT NULL");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS finance_edit_otps (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    tenant_id INT NOT NULL DEFAULT 1,
                    finance_id INT NOT NULL,
                    system_type VARCHAR(50) DEFAULT 'Madrasa',
                    user_id INT NOT NULL,
                    user_name VARCHAR(100) DEFAULT NULL,
                    amount DECIMAL(12,2) DEFAULT 0,
                    type VARCHAR(20) DEFAULT 'Expense',
                    head_name VARCHAR(255) DEFAULT NULL,
                    otp_code VARCHAR(10) NOT NULL,
                    status ENUM('pending', 'verified', 'expired') DEFAULT 'pending',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    expires_at DATETIME NOT NULL,
                    verified_at DATETIME DEFAULT NULL,
                    verified_by INT DEFAULT NULL,
                    INDEX idx_f_user (finance_id, user_id),
                    INDEX idx_otp (otp_code, status, expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
        } catch (Throwable $e) {}

        $uploadDir = __DIR__ . '/uploads/bills';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }
        $done = true;
    }
}

if (!function_exists('finance_request_edit_otp')) {
    function finance_request_edit_otp(PDO $pdo, $financeId, $userId, $userName = '', $systemType = 'Madrasa', $tenantId = 1) {
        finance_ensure_otp_and_bill_schema($pdo);
        $financeId = (int)$financeId;
        $userId = (int)$userId;
        $tenantId = (int)$tenantId;

        // چیک کریں کہ کیا اس ٹرانزیکشن کے لیے پچھلے 24 گھنٹوں میں کوئی زیر التواء OTP موجود ہے
        $stmt = $pdo->prepare("
            SELECT * FROM finance_edit_otps 
            WHERE finance_id = ? AND user_id = ? AND status = 'pending' AND expires_at >= NOW()
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$financeId, $userId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        // ٹرانزیکشن کی معلومات لائیں
        $table = ($systemType === 'Masjid') ? 'masjid_finance' : 'finance';
        $finStmt = $pdo->prepare("SELECT * FROM $table WHERE id = ? LIMIT 1");
        $finStmt->execute([$financeId]);
        $finRow = $finStmt->fetch(PDO::FETCH_ASSOC);
        if (!$finRow && $table === 'masjid_finance') {
            $finStmt2 = $pdo->prepare("SELECT * FROM finance WHERE id = ? LIMIT 1");
            $finStmt2->execute([$financeId]);
            $finRow = $finStmt2->fetch(PDO::FETCH_ASSOC);
        }

        $amount = (float)($finRow['amount'] ?? 0);
        $type = $finRow['type'] ?? 'Expense';
        $headName = $finRow['head_name'] ?? '';

        if ($existing) {
            return [
                'status' => 'success',
                'otp_code' => $existing['otp_code'],
                'expires_at' => $existing['expires_at'],
                'message' => 'پہلے سے جاری شدہ OTP کوڈ 24 گھنٹے تک کارآمد ہے۔'
            ];
        }

        // نیا 6 ہندسوں کا OTP بنائیں
        $otp = sprintf('%06d', random_int(100000, 999999));
        $ins = $pdo->prepare("
            INSERT INTO finance_edit_otps 
            (tenant_id, finance_id, system_type, user_id, user_name, amount, type, head_name, otp_code, status, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL 24 HOUR))
        ");
        $ins->execute([$tenantId, $financeId, $systemType, $userId, $userName, $amount, $type, $headName, $otp]);

        // ایڈمن کو فوری پش نوٹیفکیشن بھیجیں
        if (file_exists(__DIR__ . '/notifications.php')) {
            require_once __DIR__ . '/notifications.php';
        }
        $notifTitle = "فنانس انٹری تبدیلی کی درخواست (OTP)";
        $notifBody = "صارف " . ($userName ?: 'اسٹاف') . " نے فنانس انٹری #$financeId (مبلغ Rs. " . number_format($amount) . ") میں ترمیم کے لیے OTP کی درخواست کی ہے۔ کوڈ: $otp";
        if (function_exists('sendPushNotificationFCM')) {
            sendPushNotificationFCM($pdo, $notifTitle, $notifBody, 'finance_otp', null, 'admin');
        }

        return [
            'status' => 'success',
            'otp_code' => $otp,
            'message' => 'ایڈمن کے اکاؤنٹ میں OTP درخواست بھیج دی گئی ہے (میعاد: 24 گھنٹے)۔'
        ];
    }
}

if (!function_exists('finance_consolidate_advance_accounts')) {
    function finance_consolidate_advance_accounts(PDO $pdo, $cur_tenant_id = 1) {
        static $already_ran = false;
        if ($already_ran) return;
        $already_ran = true;
        try {
            // Helper closure to merge a source code into a target code safely
            $mergeAccounts = function($srcCode, $tgtCode) use ($pdo) {
                $tgtId = (int)$pdo->query("SELECT id FROM acc_chart_of_accounts WHERE TRIM(code) = '$tgtCode' AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1")->fetchColumn();
                if (!$tgtId) {
                    $tgtId = (int)$pdo->query("SELECT id FROM acc_chart_of_accounts WHERE TRIM(code) = '$tgtCode' LIMIT 1")->fetchColumn();
                }
                if ($tgtId > 0) {
                    $srcRows = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE TRIM(code) = '$srcCode'")->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($srcRows as $srcId) {
                        $srcId = (int)$srcId;
                        if ($srcId > 0 && $srcId !== $tgtId) {
                            $pdo->exec("UPDATE acc_ledger_entries SET account_id = $tgtId WHERE account_id = $srcId");
                            $pdo->exec("UPDATE finance SET account_id = $tgtId WHERE account_id = $srcId");
                            $pdo->exec("UPDATE acc_chart_of_accounts SET is_deleted = 1, is_active = 0 WHERE id = $srcId");
                        }
                    }
                }
            };

            // 1. Merge duplicates for Tanzeel: 1632 and 1636 -> canonical 1502
            $mergeAccounts('1632', '1502');
            $mergeAccounts('1636', '1502');

            // 2. Merge duplicates for Hammad: 1637 -> canonical 1633
            $mergeAccounts('1637', '1633');

            // 3. Merge duplicates for Usman: 1635 and 1639 -> canonical 1501
            $mergeAccounts('1635', '1501');
            $mergeAccounts('1639', '1501');

            // 4. Soft-delete redundant test accounts and duplicate codes
            $pdo->exec("UPDATE acc_chart_of_accounts SET is_deleted = 1, is_active = 0 WHERE TRIM(code) IN ('1504', '1505', '1632', '1635', '1636', '1637', '1639') OR name LIKE '%ٹیسٹ%'");

            // 5. Standardize 4 canonical names strictly to 'خرچ کھاتہ' (removing any 'ایڈوانس' and ensuring correct Urdu)
            $pdo->exec("UPDATE acc_chart_of_accounts SET name = 'خرچ کھاتہ - محمد عثمان معاویہ', is_deleted = 0, is_active = 1 WHERE TRIM(code) = '1501' OR name LIKE '%عثمان%' AND (name LIKE '%خرچ%' OR name LIKE '%ایڈوانس%')");
            $pdo->exec("UPDATE acc_chart_of_accounts SET name = 'خرچ کھاتہ - تنزیل الرحمن', is_deleted = 0, is_active = 1 WHERE TRIM(code) = '1502' OR name LIKE '%تنزیل%' AND (name LIKE '%خرچ%' OR name LIKE '%ایڈوانس%')");
            $pdo->exec("UPDATE acc_chart_of_accounts SET name = 'خرچ کھاتہ - قاری سلیم اللہ صاحب', is_deleted = 0, is_active = 1 WHERE TRIM(code) = '1503' OR name LIKE '%سلیم%' AND (name LIKE '%خرچ%' OR name LIKE '%ایڈوانس%')");
            $pdo->exec("UPDATE acc_chart_of_accounts SET name = 'خرچ کھاتہ - حماد اللہ ساجد', is_deleted = 0, is_active = 1 WHERE TRIM(code) = '1633' OR name LIKE '%حماد%' AND (name LIKE '%خرچ%' OR name LIKE '%ایڈوانس%')");
        } catch (Throwable $eCoa) {}
    }
}

/**
 * Safe user provisioning: Ensure every active user has exactly ONE 'خرچ کھاتہ' and ONE 'نقد وصولی کھاتہ'.
 * Idempotent, safe, and prevents duplicates.
 */
if (!function_exists('finance_ensure_user_canonical_accounts')) {
    function finance_ensure_user_canonical_accounts(PDO $pdo, $userId, $fullName, $username, $tenantId = 1) {
        $userId = (int)$userId;
        $tenantId = (int)$tenantId;
        $fullName = trim((string)$fullName);
        $username = trim((string)$username);
        $displayName = !empty($fullName) ? $fullName : $username;
        if (empty($displayName)) return;

        try {
            // Check if user matches one of the canonical 4
            $uLower = strtolower($username . ' ' . $fullName);
            if (strpos($uLower, 'usman') !== false || mb_strpos($displayName, 'عثمان') !== false) {
                $pdo->exec("UPDATE acc_chart_of_accounts SET name = 'خرچ کھاتہ - محمد عثمان معاویہ', is_deleted = 0, is_active = 1 WHERE TRIM(code) = '1501'");
                return;
            }
            if (strpos($uLower, 'tanzeel') !== false || mb_strpos($displayName, 'تنزیل') !== false) {
                $pdo->exec("UPDATE acc_chart_of_accounts SET name = 'خرچ کھاتہ - تنزیل الرحمن', is_deleted = 0, is_active = 1 WHERE TRIM(code) = '1502'");
                return;
            }
            if (strpos($uLower, 'saleem') !== false || mb_strpos($displayName, 'سلیم') !== false) {
                $pdo->exec("UPDATE acc_chart_of_accounts SET name = 'خرچ کھاتہ - قاری سلیم اللہ صاحب', is_deleted = 0, is_active = 1 WHERE TRIM(code) = '1503'");
                return;
            }
            if (strpos($uLower, 'hammad') !== false || mb_strpos($displayName, 'حماد') !== false) {
                $pdo->exec("UPDATE acc_chart_of_accounts SET name = 'خرچ کھاتہ - حماد اللہ ساجد', is_deleted = 0, is_active = 1 WHERE TRIM(code) = '1633'");
                return;
            }

            // For any genuinely new user:
            // 1. Check if Expense Account already exists
            $expName = "خرچ کھاتہ - $displayName";
            $stmtExp = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE type = 'Asset' AND (name = ? OR name LIKE ? OR name LIKE ?) AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1");
            $stmtExp->execute([$expName, "%$displayName%", "%$username%"]);
            $expId = (int)$stmtExp->fetchColumn();

            if ($expId <= 0) {
                // Find next sequential code starting from 1506+
                $maxExpCode = (int)$pdo->query("SELECT MAX(CAST(code AS UNSIGNED)) FROM acc_chart_of_accounts WHERE type = 'Asset' AND CAST(code AS UNSIGNED) >= 1500 AND CAST(code AS UNSIGNED) < 1600")->fetchColumn();
                $newExpCode = $maxExpCode >= 1505 ? ($maxExpCode + 1) : 1506;

                $insExp = $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, parent_id, is_active, is_deleted, tenant_id) VALUES (?, ?, 'Asset', NULL, 1, 0, ?)");
                $insExp->execute([(string)$newExpCode, $expName, $tenantId]);
            }

            // 2. Check if Collection Account already exists
            $colName = "نقد وصولی - $displayName";
            $stmtCol = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE type = 'Asset' AND (name = ? OR (name LIKE ? AND name LIKE '%وصولی%')) AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1");
            $stmtCol->execute([$colName, "%$displayName%"]);
            $colId = (int)$stmtCol->fetchColumn();

            if ($colId <= 0) {
                $maxColCode = (int)$pdo->query("SELECT MAX(CAST(code AS UNSIGNED)) FROM acc_chart_of_accounts WHERE type = 'Asset' AND CAST(code AS UNSIGNED) >= 1100 AND CAST(code AS UNSIGNED) < 1200")->fetchColumn();
                $newColCode = $maxColCode >= 1102 ? ($maxColCode + 1) : 1103;

                $insCol = $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, parent_id, is_active, is_deleted, tenant_id) VALUES (?, ?, 'Asset', NULL, 1, 0, ?)");
                $insCol->execute([(string)$newColCode, $colName, $tenantId]);
            }
        } catch (Throwable $eIns) {}
    }
}

if (!function_exists('finance_verify_edit_otp')) {
    function finance_verify_edit_otp(PDO $pdo, $financeId, $otpCode, $userId, $tenantId = 1) {
        finance_ensure_otp_and_bill_schema($pdo);
        $financeId = (int)$financeId;
        $userId = (int)$userId;
        $otpCode = trim((string)$otpCode);

        $stmt = $pdo->prepare("
            SELECT * FROM finance_edit_otps 
            WHERE finance_id = ? AND otp_code = ? AND status = 'pending' AND expires_at >= NOW()
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$financeId, $otpCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'status' => 'error',
                'message' => 'درج کردہ OTP غلط ہے یا اس کی 24 گھنٹے کی میعاد ختم ہو چکی ہے۔'
            ];
        }

        $upd = $pdo->prepare("
            UPDATE finance_edit_otps 
            SET status = 'verified', verified_at = NOW(), verified_by = ?
            WHERE id = ?
        ");
        $upd->execute([$userId, $row['id']]);

        return [
            'status' => 'success',
            'message' => 'OTP تصدیق ہو گیا۔ اب آپ اگلے 24 گھنٹے تک یہ انٹری تبدیل کر سکتے ہیں۔'
        ];
    }
}

if (!function_exists('finance_has_valid_edit_permission')) {
    function finance_has_valid_edit_permission(PDO $pdo, $financeId, $userId) {
        finance_ensure_otp_and_bill_schema($pdo);
        $financeId = (int)$financeId;
        $userId = (int)$userId;

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM finance_edit_otps 
            WHERE finance_id = ? AND user_id = ? AND status = 'verified' AND expires_at >= NOW()
        ");
        $stmt->execute([$financeId, $userId]);
        return ((int)$stmt->fetchColumn()) > 0;
    }
}

if (!function_exists('finance_get_pending_otps')) {
    function finance_get_pending_otps(PDO $pdo, $tenantId = 1) {
        finance_ensure_otp_and_bill_schema($pdo);
        $stmt = $pdo->prepare("
            SELECT o.*, 
                   TIMESTAMPDIFF(HOUR, NOW(), o.expires_at) as remaining_hours,
                   TIMESTAMPDIFF(MINUTE, NOW(), o.expires_at) as remaining_minutes
            FROM finance_edit_otps o
            WHERE (o.tenant_id = ? OR o.tenant_id IS NULL OR o.tenant_id = 0)
              AND o.status = 'pending' 
              AND o.expires_at >= NOW()
            ORDER BY o.id DESC LIMIT 50
        ");
        $stmt->execute([(int)$tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('next_user_receipt_no')) {
    function next_user_receipt_no(PDO $pdo, $userId, $sys = 'Madrasa', $tenant_id = null) {
        if ($tenant_id === null && function_exists('get_current_tenant_id')) {
            $tenant_id = (int)get_current_tenant_id();
        }
        $tenant_id = (int)($tenant_id ?: 1);
        $userId = max(1, (int)$userId);

        if ($userId === 1) {
            // ایڈمن ID 1 کا رسید تسلسل سابقہ 300000 سیریز (300041 آگے) سے جاری رہے
            $minNo = 300000;
            $maxNo = 400000;
        } elseif ($userId === 3) {
            // قاری سلیم اللہ صاحب (ID 3) کے لیے 700000 بلاک تاکہ تصادم نہ ہو
            $minNo = 700000;
            $maxNo = 800000;
        } else {
            $minNo = $userId * 100000;
            $maxNo = ($userId + 1) * 100000;
        }
        $currentMax = $minNo;

        $t_clause = ($tenant_id == 1) ? "(tenant_id = 1 OR tenant_id IS NULL OR tenant_id = 0)" : "tenant_id = $tenant_id";

        try {
            if ($sys === 'Masjid') {
                $stmt = $pdo->prepare("SELECT MAX(computer_receipt_no) FROM finance WHERE computer_receipt_no > ? AND computer_receipt_no < ? AND system_type = 'Masjid' AND $t_clause AND (is_deleted = 0 OR is_deleted IS NULL)");
                $stmt->execute([$minNo, $maxNo]);
                $currentMax = (int)$stmt->fetchColumn();

                try {
                    $hasMasjidFinTable = !empty($pdo->query("SHOW TABLES LIKE 'masjid_finance'")->fetchAll());
                    if ($hasMasjidFinTable) {
                        $mStmt = $pdo->prepare("SELECT MAX(computer_receipt_no) FROM masjid_finance WHERE computer_receipt_no > ? AND computer_receipt_no < ? AND $t_clause AND (is_deleted = 0 OR is_deleted IS NULL)");
                        $mStmt->execute([$minNo, $maxNo]);
                        $mMax = (int)$mStmt->fetchColumn();
                        if ($mMax > $currentMax) {
                            $currentMax = $mMax;
                        }
                    }
                } catch (Throwable $e) {}
            } else {
                $stmt = $pdo->prepare("SELECT MAX(computer_receipt_no) FROM finance WHERE computer_receipt_no > ? AND computer_receipt_no < ? AND (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '') AND $t_clause AND (is_deleted = 0 OR is_deleted IS NULL)");
                $stmt->execute([$minNo, $maxNo]);
                $currentMax = (int)$stmt->fetchColumn();
            }
        } catch (Throwable $e) {
            try {
                $stmt = $pdo->prepare("SELECT MAX(computer_receipt_no) FROM finance WHERE computer_receipt_no > ? AND computer_receipt_no < ? AND $t_clause AND (is_deleted = 0 OR is_deleted IS NULL)");
                $stmt->execute([$minNo, $maxNo]);
                $currentMax = (int)$stmt->fetchColumn();
            } catch (Throwable $e2) {
                $currentMax = $minNo;
            }
        }

        $nextNo = ($currentMax > $minNo) ? ($currentMax + 1) : ($minNo + 1);
        return $nextNo;
    }
}

if (!function_exists('finance_resequence_all_receipt_numbers')) {
    function finance_resequence_all_receipt_numbers(PDO $pdo, $tenant_id = null) {
        if ($tenant_id === null && function_exists('get_current_tenant_id')) {
            $tenant_id = (int)get_current_tenant_id();
        }
        $tenant_id = (int)($tenant_id ?: 1);
        $t_clause = ($tenant_id == 1) ? "(tenant_id = 1 OR tenant_id IS NULL OR tenant_id = 0)" : "tenant_id = $tenant_id";

        $results = [
            'total_updated' => 0,
            'users' => []
        ];

        // کالمز کی موجودگی یقینی بنائیں
        try { $pdo->exec("ALTER TABLE finance ADD COLUMN computer_receipt_no INT DEFAULT NULL"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE finance ADD COLUMN receipt_no VARCHAR(100) DEFAULT NULL"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE finance ADD COLUMN system_type VARCHAR(50) DEFAULT 'Madrasa'"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE finance ADD COLUMN tenant_id INT DEFAULT 1"); } catch (Throwable $e) {}

        // کالم receipt_no کی جانچ
        $hasReceiptNoCol = false;
        try {
            $chkCol = $pdo->query("SHOW COLUMNS FROM finance LIKE 'receipt_no'")->fetchAll();
            $hasReceiptNoCol = !empty($chkCol);
        } catch (Throwable $e) {}

        // پہلے بغیر یوزر آئی ڈی والے ریکارڈز کو بطور ایڈمن 1 سیٹ کریں
        try {
            $pdo->exec("UPDATE finance SET user_id = 1 WHERE (user_id IS NULL OR user_id = 0) AND $t_clause");
        } catch (Throwable $e) {}

        // 1. فنانس ٹیبل میں موجود تمام یوزرز اور سسٹمز کی لسٹ لائیں
        $groups = $pdo->query("
            SELECT DISTINCT user_id, COALESCE(system_type, 'Madrasa') as system_type 
            FROM finance 
            WHERE type = 'Income' AND (is_deleted = 0 OR is_deleted IS NULL) AND $t_clause
            ORDER BY user_id ASC, system_type ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($groups as $g) {
            $uid = max(1, (int)$g['user_id']);
            $sys = !empty($g['system_type']) ? $g['system_type'] : 'Madrasa';

            if ($uid === 1) {
                $baseNo = 300000;
            } elseif ($uid === 3) {
                $baseNo = 700000;
            } else {
                $baseNo = $uid * 100000;
            }

            // تاریخ اور ID کی ترتیب سے تمام فعال آمدن انٹریز حاصل کریں (بغیر receipt_no کے SELECT تاکہ کسی قسم کا ایرر نہ آئے)
            $sysCond = ($sys === 'Masjid') ? "system_type = 'Masjid'" : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
            $stmt = $pdo->prepare("
                SELECT id, date, computer_receipt_no 
                FROM finance 
                WHERE user_id = ? AND type = 'Income' AND $sysCond AND (is_deleted = 0 OR is_deleted IS NULL) AND $t_clause
                ORDER BY date ASC, id ASC
            ");
            $stmt->execute([$uid]);
            $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $seq = $baseNo;
            $userUpdated = 0;
            if ($hasReceiptNoCol) {
                $updStmt = $pdo->prepare("UPDATE finance SET computer_receipt_no = ?, receipt_no = ? WHERE id = ?");
            } else {
                $updStmt = $pdo->prepare("UPDATE finance SET computer_receipt_no = ? WHERE id = ?");
            }

            foreach ($entries as $row) {
                $seq++;
                $formattedNo = ($sys === 'Masjid') ? ('M-' . $seq) : (string)$seq;
                if ($hasReceiptNoCol) {
                    $updStmt->execute([$seq, $formattedNo, (int)$row['id']]);
                } else {
                    $updStmt->execute([$seq, (int)$row['id']]);
                }
                $userUpdated++;
            }

            $results['total_updated'] += $userUpdated;
            $results['users'][] = [
                'user_id' => $uid,
                'system_type' => $sys,
                'count' => $userUpdated,
                'start_receipt' => $baseNo + 1,
                'end_receipt' => $seq
            ];
        }

        // 2. مسجد فنانس ٹیبل کے لیے بھی (اگر موجود ہو)
        try {
            $hasMasjidFinTable = !empty($pdo->query("SHOW TABLES LIKE 'masjid_finance'")->fetchAll());
            if ($hasMasjidFinTable) {
                try { $pdo->exec("ALTER TABLE masjid_finance ADD COLUMN computer_receipt_no INT DEFAULT NULL"); } catch (Throwable $e) {}
                try { $pdo->exec("ALTER TABLE masjid_finance ADD COLUMN receipt_no VARCHAR(100) DEFAULT NULL"); } catch (Throwable $e) {}
                $hasMReceiptNoCol = false;
                try {
                    $chkMCol = $pdo->query("SHOW COLUMNS FROM masjid_finance LIKE 'receipt_no'")->fetchAll();
                    $hasMReceiptNoCol = !empty($chkMCol);
                } catch (Throwable $e) {}

                $mGroups = $pdo->query("
                    SELECT DISTINCT user_id 
                    FROM masjid_finance 
                    WHERE type = 'Income' AND (is_deleted = 0 OR is_deleted IS NULL) AND $t_clause
                    ORDER BY user_id ASC
                ")->fetchAll(PDO::FETCH_ASSOC);

                foreach ($mGroups as $g) {
                    $uid = max(1, (int)$g['user_id']);
                    $baseNo = ($uid === 1) ? 300000 : (($uid === 3) ? 700000 : ($uid * 100000));

                    $stmt = $pdo->prepare("
                        SELECT id, date, computer_receipt_no 
                        FROM masjid_finance 
                        WHERE user_id = ? AND type = 'Income' AND (is_deleted = 0 OR is_deleted IS NULL) AND $t_clause
                        ORDER BY date ASC, id ASC
                    ");
                    $stmt->execute([$uid]);
                    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    $seq = $baseNo;
                    if ($hasMReceiptNoCol) {
                        $mUpd = $pdo->prepare("UPDATE masjid_finance SET computer_receipt_no = ?, receipt_no = ? WHERE id = ?");
                    } else {
                        $mUpd = $pdo->prepare("UPDATE masjid_finance SET computer_receipt_no = ? WHERE id = ?");
                    }

                    foreach ($entries as $row) {
                        $seq++;
                        if ($hasMReceiptNoCol) {
                            $mUpd->execute([$seq, 'M-' . $seq, (int)$row['id']]);
                        } else {
                            $mUpd->execute([$seq, (int)$row['id']]);
                        }
                        $results['total_updated']++;
                    }
                }
            }
        } catch (Throwable $e) {}

        return $results;
    }
}


