<?php
// erp_migration.php
require_once 'db.php';

try {
    // 1. Chart of Accounts
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS acc_chart_of_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(20) UNIQUE NOT NULL,
            name VARCHAR(100) NOT NULL,
            type ENUM('Asset', 'Liability', 'Equity', 'Revenue', 'Expense') NOT NULL,
            parent_id INT DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            is_deleted TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (parent_id) REFERENCES acc_chart_of_accounts(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Seed default Chart of Accounts
    $stmt = $pdo->query("SELECT COUNT(*) FROM acc_chart_of_accounts");
    if ($stmt->fetchColumn() == 0) {
        $accounts = [
            // Assets
            ['1001', 'Cash in Hand', 'Asset'],
            ['1002', 'Bank Account', 'Asset'],
            ['1101', 'Accounts Receivable (Students)', 'Asset'],
            // Liabilities
            ['2001', 'Accounts Payable', 'Liability'],
            // Equity
            ['3001', 'General Fund', 'Equity'],
            // Revenue
            ['4001', 'Fee Income', 'Revenue'],
            ['4002', 'Donation / Sadqa', 'Revenue'],
            ['4003', 'Zakat', 'Revenue'],
            // Expense
            ['5001', 'Teacher Salary', 'Expense'],
            ['5002', 'Utility Bills', 'Expense'],
            ['5003', 'Maintenance', 'Expense']
        ];
        $insert = $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type) VALUES (?, ?, ?)");
        foreach ($accounts as $acc) {
            $insert->execute($acc);
        }
    }

    // 2. Vouchers
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS acc_vouchers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            voucher_no VARCHAR(50) UNIQUE NOT NULL,
            date DATE NOT NULL,
            type ENUM('Journal', 'Receipt', 'Payment', 'Contra') NOT NULL,
            reference VARCHAR(100),
            description TEXT,
            status ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
            created_by INT NOT NULL,
            approved_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 3. Ledger Entries
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS acc_ledger_entries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            voucher_id INT NOT NULL,
            account_id INT NOT NULL,
            debit DECIMAL(15, 2) DEFAULT 0.00,
            credit DECIMAL(15, 2) DEFAULT 0.00,
            description TEXT,
            FOREIGN KEY (voucher_id) REFERENCES acc_vouchers(id) ON DELETE CASCADE,
            FOREIGN KEY (account_id) REFERENCES acc_chart_of_accounts(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 4. Fee Invoices
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS acc_fee_invoices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            invoice_no VARCHAR(50) UNIQUE NOT NULL,
            date DATE NOT NULL,
            due_date DATE NOT NULL,
            total_amount DECIMAL(15, 2) NOT NULL,
            discount DECIMAL(15, 2) DEFAULT 0.00,
            net_amount DECIMAL(15, 2) NOT NULL,
            status ENUM('Unpaid', 'Partial', 'Paid', 'Cancelled') DEFAULT 'Unpaid',
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 5. Fee Invoice Items
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS acc_fee_invoice_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT NOT NULL,
            account_id INT NOT NULL,
            description VARCHAR(255),
            amount DECIMAL(15, 2) NOT NULL,
            FOREIGN KEY (invoice_id) REFERENCES acc_fee_invoices(id) ON DELETE CASCADE,
            FOREIGN KEY (account_id) REFERENCES acc_chart_of_accounts(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 6. Receipts
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS acc_receipts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            receipt_no VARCHAR(50) UNIQUE NOT NULL,
            student_id INT DEFAULT NULL,
            voucher_id INT DEFAULT NULL,
            date DATE NOT NULL,
            amount DECIMAL(15, 2) NOT NULL,
            payment_method VARCHAR(50) DEFAULT 'Cash',
            reference VARCHAR(100),
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (voucher_id) REFERENCES acc_vouchers(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 7. Payment Allocations
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS acc_payment_allocations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            receipt_id INT NOT NULL,
            invoice_id INT NOT NULL,
            amount_allocated DECIMAL(15, 2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (receipt_id) REFERENCES acc_receipts(id) ON DELETE CASCADE,
            FOREIGN KEY (invoice_id) REFERENCES acc_fee_invoices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 8. Payroll Periods
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS acc_payroll_periods (
            id INT AUTO_INCREMENT PRIMARY KEY,
            year INT NOT NULL,
            month INT NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            status ENUM('Open', 'Processed', 'Paid') DEFAULT 'Open',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(year, month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 9. Payroll Slips
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS acc_payroll_slips (
            id INT AUTO_INCREMENT PRIMARY KEY,
            period_id INT NOT NULL,
            teacher_id INT NOT NULL,
            basic_salary DECIMAL(15, 2) NOT NULL,
            total_allowances DECIMAL(15, 2) DEFAULT 0.00,
            total_deductions DECIMAL(15, 2) DEFAULT 0.00,
            net_salary DECIMAL(15, 2) NOT NULL,
            voucher_id INT DEFAULT NULL,
            status ENUM('Draft', 'Final', 'Paid') DEFAULT 'Draft',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (period_id) REFERENCES acc_payroll_periods(id) ON DELETE CASCADE,
            FOREIGN KEY (voucher_id) REFERENCES acc_vouchers(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 10. Payroll Components
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS acc_payroll_components (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slip_id INT NOT NULL,
            type ENUM('Allowance', 'Deduction') NOT NULL,
            name VARCHAR(100) NOT NULL,
            amount DECIMAL(15, 2) NOT NULL,
            FOREIGN KEY (slip_id) REFERENCES acc_payroll_slips(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 11. Audit Trail
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS acc_audit_trail (
            id INT AUTO_INCREMENT PRIMARY KEY,
            table_name VARCHAR(50) NOT NULL,
            record_id INT NOT NULL,
            action ENUM('INSERT', 'UPDATE', 'DELETE') NOT NULL,
            old_values JSON DEFAULT NULL,
            new_values JSON DEFAULT NULL,
            user_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Optional: Drop old tables if requested
    // $pdo->exec("DROP TABLE IF EXISTS finance;");
    // $pdo->exec("DROP TABLE IF EXISTS heads;");
    // $pdo->exec("DROP TABLE IF EXISTS fee_submissions;");

    echo "ERP Migration completed successfully!";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage();
}
?>
