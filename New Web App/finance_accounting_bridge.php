<?php

if (!function_exists('finance_accounting_reference')) {
    function finance_accounting_reference($financeId) {
        return 'FIN-' . (int)$financeId;
    }
}

if (!function_exists('finance_accounting_ensure_account')) {
    function finance_accounting_ensure_account(PDO $pdo, $code, $name, $type) {
        $stmt = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE code = ? LIMIT 1");
        $stmt->execute([$code]);
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;

        $insert = $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, is_deleted) VALUES (?, ?, ?, 0)");
        $insert->execute([$code, $name, $type]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('finance_accounting_delete_voucher')) {
    function finance_accounting_delete_voucher(PDO $pdo, $financeId) {
        $stmt = $pdo->prepare("UPDATE acc_vouchers SET is_deleted = 1 WHERE reference = ?");
        $stmt->execute([finance_accounting_reference($financeId)]);
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

        if ((int)($finance['is_deleted'] ?? 0) === 1) {
            finance_accounting_delete_voucher($pdo, $financeId);
            return;
        }

        $amount = (float)($finance['amount'] ?? 0);
        if ($amount <= 0) {
            finance_accounting_delete_voucher($pdo, $financeId);
            return;
        }

        $type = strtolower(trim((string)($finance['type'] ?? '')));
        $isExpense = $type === 'expense';
        $cashId = finance_accounting_ensure_account($pdo, '1001', 'Cash in Hand', 'Asset');
        $offsetId = $isExpense
            ? finance_accounting_ensure_account($pdo, '5003', 'Maintenance', 'Expense')
            : finance_accounting_ensure_account($pdo, '4002', 'Donation / Sadqa', 'Revenue');

        $reference = finance_accounting_reference($financeId);
        $voucherNo = ($isExpense ? 'SF-PV-' : 'SF-RV-') . str_pad((string)$financeId, 6, '0', STR_PAD_LEFT);
        $description = trim((string)($finance['description'] ?? ''));
        if ($description === '') {
            $description = $isExpense ? 'Simple finance expense' : 'Simple finance income';
        }

        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $existing = $pdo->prepare("SELECT id FROM acc_vouchers WHERE reference = ? LIMIT 1");
            $existing->execute([$reference]);
            $voucherId = $existing->fetchColumn();

            if ($voucherId) {
                $voucherId = (int)$voucherId;
                $update = $pdo->prepare("UPDATE acc_vouchers SET voucher_no = ?, date = ?, type = ?, description = ?, status = 'Approved', created_by = ?, is_deleted = 0 WHERE id = ?");
                $update->execute([$voucherNo, $finance['date'], $isExpense ? 'Payment' : 'Receipt', $description, (int)($finance['user_id'] ?? 1), $voucherId]);
                $pdo->prepare("DELETE FROM acc_ledger_entries WHERE voucher_id = ?")->execute([$voucherId]);
            } else {
                $insert = $pdo->prepare("INSERT INTO acc_vouchers (voucher_no, date, type, reference, description, status, created_by, is_deleted) VALUES (?, ?, ?, ?, ?, 'Approved', ?, 0)");
                $insert->execute([$voucherNo, $finance['date'], $isExpense ? 'Payment' : 'Receipt', $reference, $description, (int)($finance['user_id'] ?? 1)]);
                $voucherId = (int)$pdo->lastInsertId();
            }

            $lines = $isExpense
                ? [[$offsetId, $amount, 0], [$cashId, 0, $amount]]
                : [[$cashId, $amount, 0], [$offsetId, 0, $amount]];
            $entry = $pdo->prepare("INSERT INTO acc_ledger_entries (voucher_id, account_id, debit, credit) VALUES (?, ?, ?, ?)");
            foreach ($lines as $line) {
                $entry->execute([$voucherId, $line[0], $line[1], $line[2]]);
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
    function payroll_accounting_delete_voucher(PDO $pdo, $payrollId, $kind) {
        $stmt = $pdo->prepare("UPDATE acc_vouchers SET is_deleted = 1 WHERE reference = ?");
        $stmt->execute([payroll_accounting_reference($payrollId, $kind)]);
    }
}

if (!function_exists('payroll_accounting_upsert_voucher')) {
    function payroll_accounting_upsert_voucher(PDO $pdo, $reference, $voucherNo, $date, $type, $description, $createdBy, $lines) {
        $existing = $pdo->prepare("SELECT id FROM acc_vouchers WHERE reference = ? LIMIT 1");
        $existing->execute([$reference]);
        $voucherId = $existing->fetchColumn();

        if ($voucherId) {
            $voucherId = (int)$voucherId;
            $update = $pdo->prepare("UPDATE acc_vouchers SET voucher_no = ?, date = ?, type = ?, description = ?, status = 'Approved', created_by = ?, is_deleted = 0 WHERE id = ?");
            $update->execute([$voucherNo, $date, $type, $description, $createdBy, $voucherId]);
            $pdo->prepare("DELETE FROM acc_ledger_entries WHERE voucher_id = ?")->execute([$voucherId]);
        } else {
            $insert = $pdo->prepare("INSERT INTO acc_vouchers (voucher_no, date, type, reference, description, status, created_by, is_deleted) VALUES (?, ?, ?, ?, ?, 'Approved', ?, 0)");
            $insert->execute([$voucherNo, $date, $type, $reference, $description, $createdBy]);
            $voucherId = (int)$pdo->lastInsertId();
        }

        $entry = $pdo->prepare("INSERT INTO acc_ledger_entries (voucher_id, account_id, debit, credit) VALUES (?, ?, ?, ?)");
        foreach ($lines as $line) {
            $entry->execute([$voucherId, $line[0], $line[1], $line[2]]);
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

        $net = isset($payroll['net_salary']) && $payroll['net_salary'] !== null
            ? (float)$payroll['net_salary']
            : ((float)($payroll['basic_salary'] ?? 0) + (float)($payroll['allowances'] ?? 0) - (float)($payroll['deductions'] ?? 0));
        if ($net <= 0 || (int)($payroll['is_deleted'] ?? 0) === 1 || ($payroll['status'] ?? '') === 'Cancelled') {
            payroll_accounting_delete_voucher($pdo, $payrollId, 'ACCRUAL');
            payroll_accounting_delete_voucher($pdo, $payrollId, 'PAYMENT');
            return;
        }

        $salaryExpenseId = finance_accounting_ensure_account($pdo, '5001', 'Teacher Salary', 'Expense');
        $salaryPayableId = finance_accounting_ensure_account($pdo, '2002', 'Salaries Payable', 'Liability');
        $cashId = finance_accounting_ensure_account($pdo, '1001', 'Cash in Hand', 'Asset');
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
                [[$salaryExpenseId, $net, 0], [$salaryPayableId, 0, $net]]
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
                    [[$salaryPayableId, $net, 0], [$cashId, 0, $net]]
                );
            } else {
                payroll_accounting_delete_voucher($pdo, $payrollId, 'PAYMENT');
            }

            if ($ownTransaction) $pdo->commit();
        } catch (Exception $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
