-- 002_core_erp_and_features.sql
-- Core ERP, Accounting, Attendance, Academic, Loans, and Feature tables

-- 1. Heads
CREATE TABLE IF NOT EXISTS `heads` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `type` VARCHAR(50) NOT NULL,
    `is_deleted` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_heads_type (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Finance
CREATE TABLE IF NOT EXISTS `finance` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `date` DATE NOT NULL,
    `type` VARCHAR(50) NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `description` TEXT DEFAULT NULL,
    `head_id` INT DEFAULT NULL,
    `account_id` INT DEFAULT NULL,
    `payment_method` VARCHAR(50) DEFAULT 'Cash',
    `manual_receipt_no` VARCHAR(100) DEFAULT NULL,
    `donor_name` VARCHAR(255) DEFAULT NULL,
    `donor_address` TEXT DEFAULT NULL,
    `computer_receipt_no` INT DEFAULT NULL,
    `system_type` VARCHAR(50) DEFAULT 'Madrasa',
    `user_id` INT DEFAULT 1,
    `user_name` VARCHAR(100) DEFAULT NULL,
    `tenant_id` INT NOT NULL DEFAULT 1,
    `is_deleted` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_finance_date (`date`),
    INDEX idx_finance_user_receipt (`user_id`, `computer_receipt_no`),
    INDEX idx_finance_tenant (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Accounting Chart of Accounts
CREATE TABLE IF NOT EXISTS `acc_chart_of_accounts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(20) UNIQUE NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `type` ENUM('Asset', 'Liability', 'Equity', 'Revenue', 'Expense') NOT NULL,
    `parent_id` INT DEFAULT NULL,
    `system_type` VARCHAR(50) DEFAULT 'Madrasa',
    `tenant_id` INT NOT NULL DEFAULT 1,
    `is_active` TINYINT(1) DEFAULT 1,
    `is_deleted` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_coa_tenant (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Accounting Vouchers
CREATE TABLE IF NOT EXISTS `acc_vouchers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `voucher_no` VARCHAR(50) UNIQUE NOT NULL,
    `date` DATE NOT NULL,
    `type` ENUM('Journal', 'Receipt', 'Payment', 'Contra') NOT NULL,
    `reference` VARCHAR(100) DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `department_id` INT DEFAULT NULL,
    `status` ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
    `system_type` VARCHAR(50) DEFAULT 'Madrasa',
    `tenant_id` INT NOT NULL DEFAULT 1,
    `created_by` INT DEFAULT NULL,
    `approved_by` INT DEFAULT NULL,
    `is_deleted` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_voucher_date (`date`),
    INDEX idx_voucher_ref (`reference`),
    INDEX idx_voucher_tenant (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Accounting Ledger Entries
CREATE TABLE IF NOT EXISTS `acc_ledger_entries` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `voucher_id` INT NOT NULL,
    `account_id` INT NOT NULL,
    `debit` DECIMAL(15,2) DEFAULT 0.00,
    `credit` DECIMAL(15,2) DEFAULT 0.00,
    `department_id` INT DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `tenant_id` INT NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_acc_ledger_voucher (`voucher_id`),
    INDEX idx_acc_ledger_account (`account_id`),
    INDEX idx_acc_ledger_tenant (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Teacher Period Attendance
CREATE TABLE IF NOT EXISTS `teacher_period_attendance` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `teacher_id` INT NOT NULL,
    `class_id` INT DEFAULT NULL,
    `date` DATE NOT NULL,
    `period_no` INT NOT NULL,
    `status` VARCHAR(20) DEFAULT 'P',
    `late_minutes` INT DEFAULT 0,
    `remarks` TEXT DEFAULT NULL,
    `system_type` VARCHAR(50) DEFAULT 'Madrasa',
    `tenant_id` INT NOT NULL DEFAULT 1,
    `is_deleted` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_teacher_class_date_period (`teacher_id`, `class_id`, `date`, `period_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Staff Loans & Transactions
CREATE TABLE IF NOT EXISTS `staff_loans` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `teacher_id` INT NULL,
    `borrower_name` VARCHAR(255) NULL,
    `phone` VARCHAR(50) NULL,
    `loan_direction` VARCHAR(20) DEFAULT 'Given',
    `loan_amount` DECIMAL(15,2) DEFAULT 0,
    `installment_amount` DECIMAL(15,2) DEFAULT 0,
    `repaid_amount` DECIMAL(15,2) DEFAULT 0,
    `remaining_amount` DECIMAL(15,2) DEFAULT 0,
    `date_issued` DATE NULL,
    `due_date` DATE NULL,
    `description` TEXT NULL,
    `status` VARCHAR(50) DEFAULT 'Active',
    `system_type` VARCHAR(50) DEFAULT 'Madrasa',
    `tenant_id` INT NOT NULL DEFAULT 1,
    `is_deleted` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `loan_transactions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `loan_id` INT NULL,
    `type` VARCHAR(20) NOT NULL,
    `borrower_lender_name` VARCHAR(255) NULL,
    `amount` DECIMAL(15,2) DEFAULT 0,
    `date` DATE NOT NULL,
    `payment_method` VARCHAR(50) DEFAULT 'Cash',
    `remarks` TEXT NULL,
    `system_type` VARCHAR(50) DEFAULT 'Madrasa',
    `tenant_id` INT NOT NULL DEFAULT 1,
    `created_by` INT DEFAULT 1,
    `is_deleted` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Staff Payroll
CREATE TABLE IF NOT EXISTS `acc_payroll` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `teacher_id` INT NOT NULL,
    `month_year` VARCHAR(20) NOT NULL,
    `basic_salary` DECIMAL(15,2) DEFAULT 0,
    `allowances` DECIMAL(15,2) DEFAULT 0,
    `deductions` DECIMAL(15,2) DEFAULT 0,
    `net_salary` DECIMAL(15,2) DEFAULT 0,
    `payment_account_id` INT DEFAULT NULL,
    `payment_method` VARCHAR(50) DEFAULT 'Cash',
    `status` VARCHAR(50) DEFAULT 'Unpaid',
    `payment_date` DATE NULL,
    `created_by` INT DEFAULT 1,
    `system_type` VARCHAR(50) DEFAULT 'Madrasa',
    `tenant_id` INT NOT NULL DEFAULT 1,
    `is_deleted` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
