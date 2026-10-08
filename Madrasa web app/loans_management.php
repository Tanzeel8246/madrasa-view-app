<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once __DIR__ . '/finance_accounting_bridge.php';

global $system_type;
$system_type = $_SESSION['system_type'] ?? 'Madrasa';
$uid = $_SESSION['user_id'] ?? 1;
$is_en = (isset($_SESSION['lang']) && $_SESSION['lang'] === 'en');
$msg = '';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$u_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin = in_array($u_role, ['admin', 'superadmin', 'super_admin', 'administrator'], true);
if (!$is_admin && $u_role !== 'nazim' && !app_has_perm('manage_loans') && !app_has_perm('manage_masjid_loans') && !app_has_perm('manage_erp')) {
    header("Location: dashboard.php");
    exit;
}

if (!function_exists('ensure_column')) {
    function ensure_column(PDO $pdo, $table, $column, $definition) {
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `" . $table . "` LIKE ?");
            $stmt->execute([$column]);
            if ($stmt->fetch(PDO::FETCH_ASSOC)) return true;
            $pdo->exec("ALTER TABLE `" . $table . "` ADD COLUMN `" . $column . "` " . $definition);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

// 1. Ensure staff_loans table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_loans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT NULL,
        borrower_name VARCHAR(255) NULL,
        phone VARCHAR(50) NULL,
        loan_direction VARCHAR(20) DEFAULT 'Given',
        loan_amount DECIMAL(15,2) DEFAULT 0,
        installment_amount DECIMAL(15,2) DEFAULT 0,
        repaid_amount DECIMAL(15,2) DEFAULT 0,
        remaining_amount DECIMAL(15,2) DEFAULT 0,
        date_issued DATE NULL,
        due_date DATE NULL,
        description TEXT NULL,
        status VARCHAR(50) DEFAULT 'Active',
        system_type VARCHAR(50) DEFAULT 'Madrasa',
        is_deleted TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    try { $pdo->exec("ALTER TABLE staff_loans MODIFY teacher_id INT NULL DEFAULT NULL"); } catch (Exception $e) {}
    ensure_column($pdo, 'staff_loans', 'loan_direction', "VARCHAR(20) DEFAULT 'Given'");
    ensure_column($pdo, 'staff_loans', 'borrower_name', "VARCHAR(255) DEFAULT NULL");
    ensure_column($pdo, 'staff_loans', 'repaid_amount', "DECIMAL(15,2) DEFAULT 0");
    ensure_column($pdo, 'staff_loans', 'due_date', "DATE DEFAULT NULL");
    ensure_column($pdo, 'staff_loans', 'phone', "VARCHAR(50) DEFAULT NULL");
    ensure_column($pdo, 'staff_loans', 'remaining_amount', "DECIMAL(15,2) DEFAULT 0");
    ensure_column($pdo, 'staff_loans', 'system_type', "VARCHAR(50) DEFAULT 'Madrasa'");
    ensure_column($pdo, 'staff_loans', 'is_deleted', "TINYINT(1) DEFAULT 0");
    ensure_column($pdo, 'staff_loans', 'account_id', "INT NULL DEFAULT NULL");
} catch (Exception $e) {}

// 2. Ensure loan_transactions table exists for tracking individual repayments and recoveries
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS loan_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        loan_id INT NULL,
        type VARCHAR(20) NOT NULL, -- 'Recovery' or 'Repayment'
        borrower_lender_name VARCHAR(255) NULL,
        amount DECIMAL(15,2) DEFAULT 0,
        date DATE NOT NULL,
        payment_method VARCHAR(50) DEFAULT 'Cash',
        remarks TEXT NULL,
        system_type VARCHAR(50) DEFAULT 'Madrasa',
        created_by INT DEFAULT 1,
        is_deleted TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    ensure_column($pdo, 'loan_transactions', 'system_type', "VARCHAR(50) DEFAULT 'Madrasa'");
    ensure_column($pdo, 'loan_transactions', 'created_by', "INT DEFAULT 1");
    ensure_column($pdo, 'loan_transactions', 'is_deleted', "TINYINT(1) DEFAULT 0");
    ensure_column($pdo, 'loan_transactions', 'account_id', "INT NULL DEFAULT NULL");
} catch (Exception $e) {}

// 3. Ensure chart of accounts heads exist for loans
$head_given_id = 0;
$head_recovery_id = 0;
$head_taken_id = 0;
$head_repay_id = 0;

try {
    $h1 = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE code = 'LOAN-GIV' LIMIT 1")->fetchColumn();
    if (!$h1) {
        $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, is_deleted, system_type) VALUES ('LOAN-GIV', 'دیے گئے قرض و پیشگی', 'Asset', 0, '$system_type')")->execute();
        $head_given_id = (int)$pdo->lastInsertId();
    } else { $head_given_id = (int)$h1; }

    $h2 = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE code = 'LOAN-REC' LIMIT 1")->fetchColumn();
    if (!$h2) {
        $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, is_deleted, system_type) VALUES ('LOAN-REC', 'قرض واپسی وصولی', 'Income', 0, '$system_type')")->execute();
        $head_recovery_id = (int)$pdo->lastInsertId();
    } else { $head_recovery_id = (int)$h2; }

    $h3 = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE code = 'LOAN-TAK' LIMIT 1")->fetchColumn();
    if (!$h3) {
        $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, is_deleted, system_type) VALUES ('LOAN-TAK', 'لیے ہوئے قرض', 'Liability', 0, '$system_type')")->execute();
        $head_taken_id = (int)$pdo->lastInsertId();
    } else { $head_taken_id = (int)$h3; }

    $h4 = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE code = 'LOAN-REP' LIMIT 1")->fetchColumn();
    if (!$h4) {
        $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, is_deleted, system_type) VALUES ('LOAN-REP', 'قرض واپسی ادائیگی', 'Expense', 0, '$system_type')")->execute();
        $head_repay_id = (int)$pdo->lastInsertId();
    } else { $head_repay_id = (int)$h4; }
} catch (Exception $e) {}

try {
    ensure_column($pdo, 'finance', 'is_loan', "TINYINT(1) DEFAULT 0");
    if (!empty($head_given_id) || !empty($head_taken_id) || !empty($head_recovery_id) || !empty($head_repay_id)) {
        $head_ids_str = implode(',', array_filter([(int)$head_given_id, (int)$head_taken_id, (int)$head_recovery_id, (int)$head_repay_id]));
        if (!empty($head_ids_str)) {
            $pdo->exec("UPDATE finance SET is_loan = 1 WHERE (is_loan = 0 OR is_loan IS NULL) AND (head_id IN ($head_ids_str) OR description LIKE '%قرض%' OR description LIKE '%loan%')");
        }
    }
} catch (Exception $e) {}

// Fetch payment asset accounts (Cash in Hand, Bank, etc.)
$payment_accounts = [];
try {
    $payment_accounts = $pdo->query("SELECT id, code, name FROM acc_chart_of_accounts WHERE type = 'Asset' AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY code ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
if (empty($payment_accounts)) {
    $cid = finance_accounting_ensure_account($pdo, '1001', 'Cash in Hand', 'Asset');
    $bid = finance_accounting_ensure_account($pdo, '1002', 'Bank Account', 'Asset');
    $payment_accounts = [
        ['id' => $cid, 'code' => '1001', 'name' => 'Cash in Hand (نقد رقم / گلہ)'],
        ['id' => $bid, 'code' => '1002', 'name' => 'Bank Account (بینک کھاتہ)']
    ];
}

// --- Actions Handling ---

// 1. Issue Loan Given (قرض دیا گیا - خرچ)
if (isset($_POST['issue_loan_given'])) {
    $teacher_id = !empty($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : null;
    $borrower_name = trim($_POST['borrower_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $amount = (float)($_POST['loan_amount'] ?? 0);
    $inst = (float)($_POST['installment_amount'] ?? 0);
    $date_issued = $_POST['date_issued'] ?? date('Y-m-d');
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $desc = trim($_POST['description'] ?? '');
    $account_id = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;

    if ($teacher_id) {
        $t_stmt = $pdo->prepare("SELECT name, phone FROM teachers WHERE id = ?");
        $t_stmt->execute([$teacher_id]);
        $t_row = $t_stmt->fetch(PDO::FETCH_ASSOC);
        if ($t_row) {
            $borrower_name = $t_row['name'];
            if (empty($phone)) $phone = $t_row['phone'] ?? '';
        }
    }

    if ($amount > 0 && !empty($borrower_name)) {
        $stmt = $pdo->prepare("INSERT INTO staff_loans (teacher_id, borrower_name, phone, loan_direction, loan_amount, installment_amount, repaid_amount, remaining_amount, date_issued, due_date, description, status, system_type, is_deleted, account_id) VALUES (?, ?, ?, 'Given', ?, ?, 0, ?, ?, ?, ?, 'Active', '$system_type', 0, ?)");
        $stmt->execute([$teacher_id, $borrower_name, $phone, $amount, $inst, $amount, $date_issued, $due_date, $desc, $account_id]);
        $loan_id = (int)$pdo->lastInsertId();

        // Post Expense in Finance (Marked as is_loan = 1 so it's not counted in operating deficit)
        $f_desc = "قرض/پیشگی رقم جاری کی گئی: $borrower_name - $desc";
        $stmt_fin = $pdo->prepare("INSERT INTO finance (user_id, user_name, type, head_id, amount, date, donor_name, description, is_deleted, system_type, account_id, is_loan) VALUES (?, ?, 'Expense', ?, ?, ?, ?, ?, 0, '$system_type', ?, 1)");
        $stmt_fin->execute([$uid, $_SESSION['name'] ?? 'Admin', $head_given_id, $amount, $date_issued, $borrower_name, $f_desc, $account_id]);
        $last_fin_id = (int)$pdo->lastInsertId();
        try { finance_accounting_post($pdo, $last_fin_id); } catch (Throwable $e) {}

        $msg = "<div class='alert alert-success fw-bold shadow-sm'><i class='fas fa-check-circle me-1'></i> " . ($is_en ? "Loan issued to $borrower_name (Rs. $amount) and recorded in Finance Expense." : "استاد/فرد $borrower_name کو قرض (Rs. $amount) جاری ہوا اور فنانس میں خرچ درج ہو گیا۔") . "</div>";
    }
}

// 2. Record Loan Taken (ادارہ نے قرض لیا - آمدن)
if (isset($_POST['record_loan_taken'])) {
    $lender_name = trim($_POST['lender_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $amount = (float)($_POST['loan_amount'] ?? 0);
    $date_issued = $_POST['date_issued'] ?? date('Y-m-d');
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $desc = trim($_POST['description'] ?? '');
    $account_id = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;

    if ($amount > 0 && !empty($lender_name)) {
        $stmt = $pdo->prepare("INSERT INTO staff_loans (teacher_id, borrower_name, phone, loan_direction, loan_amount, installment_amount, repaid_amount, remaining_amount, date_issued, due_date, description, status, system_type, is_deleted, account_id) VALUES (NULL, ?, ?, 'Taken', ?, 0, 0, ?, ?, ?, ?, 'Active', '$system_type', 0, ?)");
        $stmt->execute([$lender_name, $phone, $amount, $amount, $date_issued, $due_date, $desc, $account_id]);

        // Record Cash Inflow (Loan Inflow) in Finance (Marked as is_loan = 1 so it's not counted in operating donations)
        $f_desc = "ادارہ نے قرض حسنہ حاصل کیا: $lender_name - $desc";
        $stmt_fin = $pdo->prepare("INSERT INTO finance (user_id, user_name, type, head_id, amount, date, donor_name, description, is_deleted, system_type, account_id, is_loan) VALUES (?, ?, 'Income', ?, ?, ?, ?, ?, 0, '$system_type', ?, 1)");
        $stmt_fin->execute([$uid, $_SESSION['name'] ?? 'Admin', $head_taken_id, $amount, $date_issued, $lender_name, $f_desc, $account_id]);
        $last_fin_id = (int)$pdo->lastInsertId();
        try { finance_accounting_post($pdo, $last_fin_id); } catch (Throwable $e) {}

        $msg = "<div class='alert alert-success fw-bold shadow-sm'><i class='fas fa-check-circle me-1'></i> " . ($is_en ? "Loan taken from $lender_name (Rs. $amount) recorded in Finance Inflow." : "قرض دہندہ $lender_name سے قرض حسنہ موصولی (Rs. $amount) کا اندراج فنانس میں ہو گیا۔") . "</div>";
    }
}

// 3. Receive Repayment for Given Loan (قرض وصولی - آمدن)
if (isset($_POST['receive_repayment'])) {
    $loan_id = (int)$_POST['loan_id'];
    $repay_amount = (float)$_POST['repay_amount'];
    $repay_date = $_POST['repay_date'] ?? date('Y-m-d');
    $method = trim($_POST['payment_method'] ?? 'Cash');
    $remarks = trim($_POST['remarks'] ?? '');
    $account_id = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;

    $l_stmt = $pdo->prepare("SELECT * FROM staff_loans WHERE id = ? AND is_deleted = 0");
    $l_stmt->execute([$loan_id]);
    $loan = $l_stmt->fetch(PDO::FETCH_ASSOC);

    if ($loan && $repay_amount > 0) {
        $new_repaid = (float)$loan['repaid_amount'] + $repay_amount;
        $new_remaining = (float)$loan['remaining_amount'] - $repay_amount;
        if ($new_remaining < 0) $new_remaining = 0;
        $status = ($new_remaining <= 0) ? 'Paid' : 'Active';

        $pdo->prepare("UPDATE staff_loans SET repaid_amount = ?, remaining_amount = ?, status = ? WHERE id = ?")
            ->execute([$new_repaid, $new_remaining, $status, $loan_id]);

        $b_name = $loan['borrower_name'] ?: 'Borrower';

        // Insert into loan_transactions table
        $pdo->prepare("INSERT INTO loan_transactions (loan_id, type, borrower_lender_name, amount, date, payment_method, remarks, system_type, created_by, is_deleted, account_id) VALUES (?, 'Recovery', ?, ?, ?, ?, ?, '$system_type', ?, 0, ?)")
            ->execute([$loan_id, $b_name, $repay_amount, $repay_date, $method, $remarks, $uid, $account_id]);

        // Record Income (Loan Recovery Inflow) in Finance (Marked as is_loan = 1)
        $f_desc = "قرض قسط واپسی وصول ہوئی: $b_name - $remarks ($method)";
        $stmt_fin = $pdo->prepare("INSERT INTO finance (user_id, user_name, type, head_id, amount, date, donor_name, description, is_deleted, system_type, account_id, payment_method, is_loan) VALUES (?, ?, 'Income', ?, ?, ?, ?, ?, 0, '$system_type', ?, ?, 1)");
        $stmt_fin->execute([$uid, $_SESSION['name'] ?? 'Admin', $head_recovery_id, $repay_amount, $repay_date, $b_name, $f_desc, $account_id, $method]);
        $last_fin_id = (int)$pdo->lastInsertId();
        try { finance_accounting_post($pdo, $last_fin_id); } catch (Throwable $e) {}

        $msg = "<div class='alert alert-success fw-bold shadow-sm'><i class='fas fa-check-circle me-1'></i> " . ($is_en ? "Recovery of Rs. $repay_amount from $b_name recorded and posted to Finance Income." : "قرض وصولی رقم Rs. $repay_amount از $b_name درج ہو گئی اور فنانس آمدن میں شامل کر دی گئی۔") . "</div>";
    }
}

// 4. Repay Taken Loan by Madrasa (قرض واپسی / ادائیگی - خرچ)
if (isset($_POST['repay_loan_taken'])) {
    $loan_id = (int)$_POST['loan_id'];
    $repay_amount = (float)$_POST['repay_amount'];
    $repay_date = $_POST['repay_date'] ?? date('Y-m-d');
    $method = trim($_POST['payment_method'] ?? 'Cash');
    $remarks = trim($_POST['remarks'] ?? '');
    $account_id = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;

    $l_stmt = $pdo->prepare("SELECT * FROM staff_loans WHERE id = ? AND is_deleted = 0");
    $l_stmt->execute([$loan_id]);
    $loan = $l_stmt->fetch(PDO::FETCH_ASSOC);

    if ($loan && $repay_amount > 0) {
        $new_repaid = (float)$loan['repaid_amount'] + $repay_amount;
        $new_remaining = (float)$loan['remaining_amount'] - $repay_amount;
        if ($new_remaining < 0) $new_remaining = 0;
        $status = ($new_remaining <= 0) ? 'Paid' : 'Active';

        $pdo->prepare("UPDATE staff_loans SET repaid_amount = ?, remaining_amount = ?, status = ? WHERE id = ?")
            ->execute([$new_repaid, $new_remaining, $status, $loan_id]);

        $l_name = $loan['borrower_name'] ?: 'Lender';

        // Insert into loan_transactions table
        $pdo->prepare("INSERT INTO loan_transactions (loan_id, type, borrower_lender_name, amount, date, payment_method, remarks, system_type, created_by, is_deleted, account_id) VALUES (?, 'Repayment', ?, ?, ?, ?, ?, '$system_type', ?, 0, ?)")
            ->execute([$loan_id, $l_name, $repay_amount, $repay_date, $method, $remarks, $uid, $account_id]);

        // Record Cash Outflow (Loan Repayment) in Finance (Marked as is_loan = 1)
        $f_desc = "ادارہ کی طرف سے قرض واپس ادا کیا گیا: $l_name - $remarks ($method)";
        $stmt_fin = $pdo->prepare("INSERT INTO finance (user_id, user_name, type, head_id, amount, date, donor_name, description, is_deleted, system_type, account_id, payment_method, is_loan) VALUES (?, ?, 'Expense', ?, ?, ?, ?, ?, 0, '$system_type', ?, ?, 1)");
        $stmt_fin->execute([$uid, $_SESSION['name'] ?? 'Admin', $head_repay_id, $repay_amount, $repay_date, $l_name, $f_desc, $account_id, $method]);
        $last_fin_id = (int)$pdo->lastInsertId();
        try { finance_accounting_post($pdo, $last_fin_id); } catch (Throwable $e) {}

        $msg = "<div class='alert alert-success fw-bold shadow-sm'><i class='fas fa-check-circle me-1'></i> " . ($is_en ? "Debt repayment of Rs. $repay_amount to $l_name recorded and posted to Finance Expense." : "قرض ادائیگی رقم Rs. $repay_amount بنام $l_name ادا ہو گئی اور فنانس خرچ میں درج ہو گئی۔") . "</div>";
    }
}

// 5. Delete Loan Record
if (isset($_POST['delete_loan'])) {
    $loan_id = (int)$_POST['loan_id'];
    $pdo->prepare("UPDATE staff_loans SET is_deleted = 1 WHERE id = ?")->execute([$loan_id]);
    $msg = "<div class='alert alert-warning fw-bold shadow-sm'><i class='fas fa-trash me-1'></i> " . ($is_en ? "Loan record deleted." : "قرض کا ریکارڈ حذف کر دیا گیا۔") . "</div>";
}

// 6. Delete Transaction Record
if (isset($_POST['delete_transaction'])) {
    $tx_id = (int)$_POST['tx_id'];
    $t_stmt = $pdo->prepare("SELECT * FROM loan_transactions WHERE id = ?");
    $t_stmt->execute([$tx_id]);
    $tx = $t_stmt->fetch(PDO::FETCH_ASSOC);
    if ($tx) {
        $pdo->prepare("UPDATE loan_transactions SET is_deleted = 1 WHERE id = ?")->execute([$tx_id]);
        if (!empty($tx['loan_id'])) {
            $l_stmt = $pdo->prepare("SELECT * FROM staff_loans WHERE id = ?");
            $l_stmt->execute([$tx['loan_id']]);
            $loan = $l_stmt->fetch(PDO::FETCH_ASSOC);
            if ($loan) {
                $cur_repaid = max(0, (float)$loan['repaid_amount'] - (float)$tx['amount']);
                $cur_rem = (float)$loan['remaining_amount'] + (float)$tx['amount'];
                $status = ($cur_rem <= 0) ? 'Paid' : 'Active';
                $pdo->prepare("UPDATE staff_loans SET repaid_amount = ?, remaining_amount = ?, status = ? WHERE id = ?")
                    ->execute([$cur_repaid, $cur_rem, $status, $loan['id']]);
            }
        }
        $msg = "<div class='alert alert-warning fw-bold shadow-sm'><i class='fas fa-trash me-1'></i> " . ($is_en ? "Transaction deleted and balance adjusted." : "ٹرانزیکشن حذف کر دی گئی اور قرض کا بیلنس درست کر دیا گیا۔") . "</div>";
    }
}

// --- Active Tab Selection ---
$active_tab = $_GET['tab'] ?? 'given'; // 'given', 'taken', 'recoveries', 'repayments'

// --- Data Queries ---

// 1. Loans Given Query
$loans_given = $pdo->query("SELECT l.*, t.name as teacher_name FROM staff_loans l LEFT JOIN teachers t ON l.teacher_id = t.id WHERE l.is_deleted = 0 AND (l.loan_direction = 'Given' OR l.loan_direction IS NULL OR l.loan_direction = '') AND (l.system_type = '$system_type' OR l.system_type IS NULL OR l.system_type = '') ORDER BY l.id DESC")->fetchAll(PDO::FETCH_ASSOC);

// 2. Loans Taken Query
$loans_taken = $pdo->query("SELECT * FROM staff_loans WHERE is_deleted = 0 AND loan_direction = 'Taken' AND (system_type = '$system_type' OR system_type IS NULL OR system_type = '') ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// 3. Recoveries Query (قرض وصولی)
$recoveries = $pdo->query("SELECT t.*, l.loan_amount, l.remaining_amount as current_remaining FROM loan_transactions t LEFT JOIN staff_loans l ON t.loan_id = l.id WHERE t.type = 'Recovery' AND t.is_deleted = 0 AND (t.system_type = '$system_type' OR t.system_type IS NULL OR t.system_type = '') ORDER BY t.date DESC, t.id DESC")->fetchAll(PDO::FETCH_ASSOC);

// 4. Repayments Query (قرض واپسی / ادائیگی)
$repayments = $pdo->query("SELECT t.*, l.loan_amount, l.remaining_amount as current_remaining FROM loan_transactions t LEFT JOIN staff_loans l ON t.loan_id = l.id WHERE t.type = 'Repayment' AND t.is_deleted = 0 AND (t.system_type = '$system_type' OR t.system_type IS NULL OR t.system_type = '') ORDER BY t.date DESC, t.id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Active Teachers for Dropdown
$teachers = $pdo->query("SELECT id, name, phone FROM teachers WHERE (is_deleted = 0 OR is_deleted IS NULL) AND (system_type = '$system_type' OR system_type IS NULL OR system_type = '') ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Calculate Summary Totals
$tot_given_amount = 0;
$tot_given_repaid = 0;
$tot_given_remaining = 0;
foreach ($loans_given as $lg) {
    $tot_given_amount += (float)$lg['loan_amount'];
    $tot_given_repaid += (float)$lg['repaid_amount'];
    $tot_given_remaining += (float)$lg['remaining_amount'];
}

$tot_taken_amount = 0;
$tot_taken_repaid = 0;
$tot_taken_remaining = 0;
foreach ($loans_taken as $lt) {
    $tot_taken_amount += (float)$lt['loan_amount'];
    $tot_taken_repaid += (float)$lt['repaid_amount'];
    $tot_taken_remaining += (float)$lt['remaining_amount'];
}

$tot_recoveries_count = count($recoveries);
$tot_recoveries_sum = 0;
foreach ($recoveries as $r) { $tot_recoveries_sum += (float)$r['amount']; }

$tot_repayments_count = count($repayments);
$tot_repayments_sum = 0;
foreach ($repayments as $rp) { $tot_repayments_sum += (float)$rp['amount']; }

include 'header.php';
?>

<div class="container-fluid py-3" dir="<?php echo $is_en ? 'ltr' : 'rtl'; ?>">
    <!-- Page Header & Action Buttons -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div>
            <h3 class="fw-bold text-primary mb-1"><i class="fas fa-hand-holding-usd me-2"></i> <?php echo $is_en ? 'Loans & Advances Management' : 'ایڈوانس و قرض مینیجمنٹ'; ?></h3>
            <div class="text-muted small fw-bold"><?php echo $is_en ? 'Complete ledger of given loans, taken loans, recoveries and repayments with automatic finance integration.' : 'ادارہ کے دیے گئے قرض، لیے ہوئے قرض، قرض وصولی اور قرض واپسی کا مکمل نظام مع فنانس انٹیگریشن۔'; ?></div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-primary fw-bold shadow-sm px-3 py-2" data-bs-toggle="modal" data-bs-target="#issueGivenModal">
                <i class="fas fa-plus-circle me-1"></i> <?php echo $is_en ? 'Issue Loan (Given)' : 'نیا قرض / ایڈوانس جاری کریں'; ?>
            </button>
            <button type="button" class="btn btn-warning fw-bold shadow-sm text-dark px-3 py-2" data-bs-toggle="modal" data-bs-target="#recordTakenModal">
                <i class="fas fa-hand-holding me-1"></i> <?php echo $is_en ? 'Record Loan Taken' : 'ادارہ کا لیا گیا قرض درج کریں'; ?>
            </button>
            <button type="button" class="btn btn-success fw-bold shadow-sm text-white px-3 py-2" data-bs-toggle="modal" data-bs-target="#newRecoveryModal">
                <i class="fas fa-hand-holding-usd me-1"></i> <?php echo $is_en ? 'Record Recovery' : 'نئی قرض وصولی درج کریں'; ?>
            </button>
            <button type="button" class="btn btn-info fw-bold shadow-sm text-white px-3 py-2" data-bs-toggle="modal" data-bs-target="#newRepaymentModal">
                <i class="fas fa-money-bill-wave me-1"></i> <?php echo $is_en ? 'Record Repayment' : 'نئی قرض ادائیگی درج کریں'; ?>
            </button>
            <button type="button" class="btn btn-outline-secondary fw-bold shadow-sm px-3 py-2 bg-white" onclick="window.print()">
                <i class="fas fa-print me-1"></i> <?php echo $is_en ? 'Print' : 'پرنٹ'; ?>
            </button>
        </div>
    </div>

    <?php if($msg) echo $msg; ?>

    <!-- Summary Metrics Cards -->
    <div class="row g-3 mb-3">
        <!-- 1. Loans Given Metrics -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card h-100 shadow-sm rounded-4 p-3 border-0 position-relative overflow-hidden" style="background: #ffffff !important; border-left: 5px solid #2563eb !important; border-right: 5px solid #2563eb !important;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-bold" style="color: #1e40af; font-size: 14px;"><?php echo $is_en ? '1. Total Loans Given' : '1. کل دیے گئے قرض'; ?></span>
                    <span class="rounded-circle d-flex align-items-center justify-content-center" style="width: 36px; height: 36px; background-color: #dbeafe; color: #1d4ed8;">
                        <i class="fas fa-arrow-circle-up fs-6"></i>
                    </span>
                </div>
                <h4 class="fw-bold mb-1" style="color: #0f172a !important; font-weight: 800;">Rs. <?php echo number_format($tot_given_amount); ?></h4>
                <div class="d-flex align-items-center justify-content-between pt-2 border-top mt-1" style="border-color: #f1f5f9 !important;">
                    <small class="text-muted fw-semibold"><?php echo $is_en ? 'Receivable Balance:' : 'بقایا واجب الوصول:'; ?></small>
                    <span class="badge bg-danger-subtle text-danger fw-bold fs-7">Rs. <?php echo number_format($tot_given_remaining); ?></span>
                </div>
            </div>
        </div>

        <!-- 2. Loans Taken Metrics -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card h-100 shadow-sm rounded-4 p-3 border-0 position-relative overflow-hidden" style="background: #ffffff !important; border-left: 5px solid #d97706 !important; border-right: 5px solid #d97706 !important;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-bold" style="color: #92400e; font-size: 14px;"><?php echo $is_en ? '2. Total Loans Taken' : '2. کل لیے ہوئے قرض'; ?></span>
                    <span class="rounded-circle d-flex align-items-center justify-content-center" style="width: 36px; height: 36px; background-color: #fef3c7; color: #b45309;">
                        <i class="fas fa-arrow-circle-down fs-6"></i>
                    </span>
                </div>
                <h4 class="fw-bold mb-1" style="color: #0f172a !important; font-weight: 800;">Rs. <?php echo number_format($tot_taken_amount); ?></h4>
                <div class="d-flex align-items-center justify-content-between pt-2 border-top mt-1" style="border-color: #f1f5f9 !important;">
                    <small class="text-muted fw-semibold"><?php echo $is_en ? 'Outstanding Debt:' : 'کل واجب الادا قرض:'; ?></small>
                    <span class="badge bg-warning-subtle text-warning-emphasis fw-bold fs-7">Rs. <?php echo number_format($tot_taken_remaining); ?></span>
                </div>
            </div>
        </div>

        <!-- 3. Recoveries Sum -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card h-100 shadow-sm rounded-4 p-3 border-0 position-relative overflow-hidden" style="background: #ffffff !important; border-left: 5px solid #16a34a !important; border-right: 5px solid #16a34a !important;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-bold" style="color: #15803d; font-size: 14px;"><?php echo $is_en ? '3. Total Recoveries' : '3. کل وصول شدہ قرض واپسی'; ?></span>
                    <span class="rounded-circle d-flex align-items-center justify-content-center" style="width: 36px; height: 36px; background-color: #dcfce7; color: #15803d;">
                        <i class="fas fa-hand-holding-usd fs-6"></i>
                    </span>
                </div>
                <h4 class="fw-bold mb-1" style="color: #0f172a !important; font-weight: 800;">Rs. <?php echo number_format($tot_recoveries_sum); ?></h4>
                <div class="d-flex align-items-center justify-content-between pt-2 border-top mt-1" style="border-color: #f1f5f9 !important;">
                    <small class="text-muted fw-semibold"><?php echo $is_en ? 'Total Receipts:' : 'تعداد وصولیاں:'; ?></small>
                    <span class="badge bg-success-subtle text-success fw-bold fs-7"><?php echo $tot_recoveries_count; ?> وصولیاں (آمدن)</span>
                </div>
            </div>
        </div>

        <!-- 4. Repayments Sum -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card h-100 shadow-sm rounded-4 p-3 border-0 position-relative overflow-hidden" style="background: #ffffff !important; border-left: 5px solid #6366f1 !important; border-right: 5px solid #6366f1 !important;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-bold" style="color: #4338ca; font-size: 14px;"><?php echo $is_en ? '4. Total Debt Repayments' : '4. کل ادا شدہ قرض واپسی'; ?></span>
                    <span class="rounded-circle d-flex align-items-center justify-content-center" style="width: 36px; height: 36px; background-color: #e0e7ff; color: #4338ca;">
                        <i class="fas fa-money-bill-wave fs-6"></i>
                    </span>
                </div>
                <h4 class="fw-bold mb-1" style="color: #0f172a !important; font-weight: 800;">Rs. <?php echo number_format($tot_repayments_sum); ?></h4>
                <div class="d-flex align-items-center justify-content-between pt-2 border-top mt-1" style="border-color: #f1f5f9 !important;">
                    <small class="text-muted fw-semibold"><?php echo $is_en ? 'Total Payments:' : 'تعداد ادائیگیاں:'; ?></small>
                    <span class="badge bg-info-subtle text-info-emphasis fw-bold fs-7"><?php echo $tot_repayments_count; ?> ادائیگیاں (خرچ)</span>
                </div>
            </div>
        </div>
    </div>

    <!-- 4 Navigation Tabs -->
    <ul class="nav nav-pills mb-3 gap-2 p-2 rounded-3 border d-print-none flex-wrap" style="background: #ffffff; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <li class="nav-item">
            <a class="nav-link fw-bold px-3 py-2 <?php echo $active_tab==='given'?'active bg-primary text-white shadow-sm':'text-secondary'; ?>" href="loans_management.php?tab=given">
                <i class="fas fa-arrow-circle-up me-1"></i> <?php echo $is_en ? '1. Loans Given' : '1. دیے گئے قرض و پیشگی'; ?> (<?php echo count($loans_given); ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link fw-bold px-3 py-2 <?php echo $active_tab==='taken'?'active bg-warning text-dark shadow-sm':'text-secondary'; ?>" href="loans_management.php?tab=taken">
                <i class="fas fa-arrow-circle-down me-1"></i> <?php echo $is_en ? '2. Loans Taken' : '2. لیے ہوئے قرض حسنہ'; ?> (<?php echo count($loans_taken); ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link fw-bold px-3 py-2 <?php echo $active_tab==='recoveries'?'active bg-success text-white shadow-sm':'text-secondary'; ?>" href="loans_management.php?tab=recoveries">
                <i class="fas fa-hand-holding-usd me-1"></i> <?php echo $is_en ? '3. Loan Recoveries (Inflow)' : '3. قرض وصولی (واپسی موصول)'; ?> (<?php echo $tot_recoveries_count; ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link fw-bold px-3 py-2 <?php echo $active_tab==='repayments'?'active bg-info text-white shadow-sm':'text-secondary'; ?>" href="loans_management.php?tab=repayments">
                <i class="fas fa-money-bill-wave me-1"></i> <?php echo $is_en ? '4. Loan Repayments (Outflow)' : '4. قرض واپسی / ادائیگی (ادارہ ادائیگی)'; ?> (<?php echo $tot_repayments_count; ?>)
            </a>
        </li>
    </ul>

    <!-- Tab 1: Loans Given -->
    <?php if($active_tab === 'given'): ?>
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-primary text-white py-2 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="fas fa-arrow-circle-up me-2"></i> <?php echo $is_en ? 'Loans Given to Staff & Individuals (Receivable)' : 'ادارہ کے دیے گئے قرض اور پیشگی رقم کا ریکارڈ (واجب الوصول)'; ?></h6>
                <span class="badge bg-white text-primary fw-bold"><?php echo count($loans_given); ?> ریکارڈز</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 text-center" style="font-size: 13px;">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th class="text-start"><?php echo $is_en ? 'Borrower / Staff' : 'وصول کنندہ (استاد / فرد)'; ?></th>
                                <th><?php echo $is_en ? 'Phone' : 'فون نمبر'; ?></th>
                                <th><?php echo $is_en ? 'Loan Amount' : 'اصل قرض رقم'; ?></th>
                                <th><?php echo $is_en ? 'Monthly Inst.' : 'ماہانہ قسط'; ?></th>
                                <th><?php echo $is_en ? 'Repaid' : 'واپس موصول'; ?></th>
                                <th class="bg-danger text-white"><?php echo $is_en ? 'Remaining' : 'بقایا رقم'; ?></th>
                                <th><?php echo $is_en ? 'Date Issued' : 'تاریخ اجرا'; ?></th>
                                <th><?php echo $is_en ? 'Due Date' : 'واپسی تاریخ'; ?></th>
                                <th><?php echo $is_en ? 'Status' : 'کیفیت'; ?></th>
                                <th class="d-print-none"><?php echo $is_en ? 'Actions' : 'ایکشن'; ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($loans_given)): ?>
                                <tr>
                                    <td colspan="11" class="py-4 text-muted fw-bold"><?php echo $is_en ? 'No loans given records found.' : 'دیے گئے قرض کا کوئی ریکارڈ موجود نہیں ہے۔'; ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach($loans_given as $idx => $l): ?>
                                    <tr>
                                        <td><?php echo $idx + 1; ?></td>
                                        <td class="text-start fw-bold">
                                            <i class="fas fa-user-circle text-primary me-1"></i>
                                            <?php echo htmlspecialchars($l['teacher_name'] ?: $l['borrower_name']); ?>
                                            <?php if(!empty($l['description'])): ?>
                                                <div class="small text-muted fw-normal"><?php echo htmlspecialchars($l['description']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($l['phone'] ?: '-'); ?></td>
                                        <td class="fw-bold">Rs. <?php echo number_format($l['loan_amount']); ?></td>
                                        <td>Rs. <?php echo number_format($l['installment_amount']); ?></td>
                                        <td class="text-success fw-bold">Rs. <?php echo number_format($l['repaid_amount']); ?></td>
                                        <td class="fw-bold text-danger fs-6">Rs. <?php echo number_format($l['remaining_amount']); ?></td>
                                        <td><?php echo $l['date_issued'] ? date('d-M-Y', strtotime($l['date_issued'])) : '-'; ?></td>
                                        <td><?php echo $l['due_date'] ? date('d-M-Y', strtotime($l['due_date'])) : '-'; ?></td>
                                        <td>
                                            <?php if($l['status'] === 'Paid' || (float)$l['remaining_amount'] <= 0): ?>
                                                <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i> <?php echo $is_en ? 'Paid' : 'مکمل ادا'; ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark"><i class="fas fa-hourglass-half me-1"></i> <?php echo $is_en ? 'Active' : 'جاری'; ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="d-print-none">
                                            <div class="d-flex justify-content-center gap-1">
                                                <?php if((float)$l['remaining_amount'] > 0): ?>
                                                    <button type="button" class="btn btn-sm btn-success text-white fw-bold px-2" data-bs-toggle="modal" data-bs-target="#repayGivenModal<?php echo $l['id']; ?>" title="<?php echo $is_en ? 'Receive Repayment' : 'قرض کی واپسی وصول کریں'; ?>">
                                                        <i class="fas fa-hand-holding-usd"></i> <?php echo $is_en ? 'Recover' : 'وصولی'; ?>
                                                    </button>
                                                <?php endif; ?>
                                                <form method="POST" onsubmit="return confirm('<?php echo $is_en ? 'Delete this loan record?' : 'کیا آپ واقعی یہ ریکارڈ حذف کرنا چاہتے ہیں؟'; ?>');" style="display:inline;">
                                                    <input type="hidden" name="loan_id" value="<?php echo $l['id']; ?>">
                                                    <button type="submit" name="delete_loan" class="btn btn-sm btn-outline-danger px-2"><i class="fas fa-trash"></i></button>
                                                </form>
                                            </div>

                                            <!-- Receive Repayment Modal for Given Loan -->
                                            <div class="modal fade" id="repayGivenModal<?php echo $l['id']; ?>" tabindex="-1">
                                                <div class="modal-dialog modal-dialog-centered">
                                                    <div class="modal-content">
                                                        <form method="POST">
                                                            <input type="hidden" name="loan_id" value="<?php echo $l['id']; ?>">
                                                            <div class="modal-header bg-success text-white">
                                                                <h5 class="modal-title fw-bold"><i class="fas fa-hand-holding-usd me-2"></i> <?php echo $is_en ? 'Receive Loan Repayment' : 'قرض کی واپسی / قسط وصولی'; ?></h5>
                                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <div class="modal-body text-start">
                                                                <p class="mb-1"><strong><?php echo $is_en ? 'Borrower:' : 'قرض دار:'; ?></strong> <?php echo htmlspecialchars($l['teacher_name'] ?: $l['borrower_name']); ?></p>
                                                                <p class="mb-3"><strong><?php echo $is_en ? 'Remaining Balance:' : 'بقایا واجب الوصول:'; ?></strong> <span class="text-danger fw-bold fs-5">Rs. <?php echo number_format($l['remaining_amount']); ?></span></p>

                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold"><?php echo $is_en ? 'Amount Received (Rs.):' : 'وصول شدہ رقم (روپے):'; ?></label>
                                                                    <input type="number" step="0.01" name="repay_amount" class="form-control fw-bold text-success" value="<?php echo $l['installment_amount'] > 0 && $l['installment_amount'] <= $l['remaining_amount'] ? $l['installment_amount'] : $l['remaining_amount']; ?>" max="<?php echo $l['remaining_amount']; ?>" required>
                                                                </div>
                                                                <div class="row g-2 mb-3">
                                                                    <div class="col-6">
                                                                        <label class="form-label fw-bold"><?php echo $is_en ? 'Receipt Date:' : 'تاریخ وصولی:'; ?></label>
                                                                        <input type="date" name="repay_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                                                    </div>
                                                                    <div class="col-6">
                                                                        <label class="form-label fw-bold"><?php echo $is_en ? 'Payment Method:' : 'طریقہ ادائیگی:'; ?></label>
                                                                        <select name="payment_method" class="form-select">
                                                                            <option value="Cash">کیش (Cash)</option>
                                                                            <option value="Bank">بینک ٹرانسفر (Bank)</option>
                                                                            <option value="Cheque">چیک (Cheque)</option>
                                                                            <option value="Salary Deduction">تنخواہ سے کٹوتی</option>
                                                                        </select>
                                                                    </div>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold"><?php echo $is_en ? 'Remarks / Notes:' : 'تفصیل / نوٹ:'; ?></label>
                                                                    <input type="text" name="remarks" class="form-control" placeholder="<?php echo $is_en ? 'e.g. Monthly installment received' : 'مثلاً: ماہانہ قسط موصول ہوئی'; ?>">
                                                                </div>
                                                                <div class="alert alert-info py-2 small mb-0">
                                                                    <i class="fas fa-info-circle me-1"></i> <?php echo $is_en ? 'This will update loan balance and post as Income in Finance.' : 'یہ رقم قرض کا بیلنس کم کرے گی اور خودکار طریقہ سے فنانس میں آمدن (Income) درج کرے گی۔'; ?>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary fw-bold" data-bs-dismiss="modal"><?php echo $is_en ? 'Cancel' : 'منسوخ'; ?></button>
                                                                <button type="submit" name="receive_repayment" class="btn btn-success fw-bold text-white"><i class="fas fa-check me-1"></i> <?php echo $is_en ? 'Confirm Recovery' : 'وصولی درج کریں'; ?></button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Tab 2: Loans Taken -->
    <?php if($active_tab === 'taken'): ?>
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-warning text-dark py-2 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="fas fa-arrow-circle-down me-2"></i> <?php echo $is_en ? 'Loans Taken by Madrasa from Lenders (Debt Payable)' : 'ادارہ کے لیے ہوئے قرض حسنہ کا ریکارڈ (واجب الادا قرض)'; ?></h6>
                <span class="badge bg-dark text-white fw-bold"><?php echo count($loans_taken); ?> ریکارڈز</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 text-center" style="font-size: 13px;">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th class="text-start"><?php echo $is_en ? 'Lender / Donor' : 'قرض دہندہ'; ?></th>
                                <th><?php echo $is_en ? 'Phone' : 'فون نمبر'; ?></th>
                                <th><?php echo $is_en ? 'Amount Taken' : 'لی گئی رقم'; ?></th>
                                <th><?php echo $is_en ? 'Repaid' : 'واپس ادا شدہ'; ?></th>
                                <th class="bg-danger text-white"><?php echo $is_en ? 'Remaining Debt' : 'بقایا قرض'; ?></th>
                                <th><?php echo $is_en ? 'Date Taken' : 'تاریخ وصولی'; ?></th>
                                <th><?php echo $is_en ? 'Due Date' : 'واپسی تاریخ'; ?></th>
                                <th><?php echo $is_en ? 'Status' : 'کیفیت'; ?></th>
                                <th class="d-print-none"><?php echo $is_en ? 'Actions' : 'ایکشن'; ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($loans_taken)): ?>
                                <tr>
                                    <td colspan="10" class="py-4 text-muted fw-bold"><?php echo $is_en ? 'No loans taken found.' : 'کوئی قرض ریکارڈ موجود نہیں ہے۔'; ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach($loans_taken as $idx => $lt): ?>
                                    <tr>
                                        <td><?php echo $idx + 1; ?></td>
                                        <td class="text-start fw-bold">
                                            <i class="fas fa-hand-holding text-warning me-1"></i>
                                            <?php echo htmlspecialchars($lt['borrower_name']); ?>
                                            <?php if(!empty($lt['description'])): ?>
                                                <div class="small text-muted fw-normal"><?php echo htmlspecialchars($lt['description']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($lt['phone'] ?: '-'); ?></td>
                                        <td class="fw-bold">Rs. <?php echo number_format($lt['loan_amount']); ?></td>
                                        <td class="text-info fw-bold">Rs. <?php echo number_format($lt['repaid_amount']); ?></td>
                                        <td class="fw-bold text-danger fs-6">Rs. <?php echo number_format($lt['remaining_amount']); ?></td>
                                        <td><?php echo $lt['date_issued'] ? date('d-M-Y', strtotime($lt['date_issued'])) : '-'; ?></td>
                                        <td><?php echo $lt['due_date'] ? date('d-M-Y', strtotime($lt['due_date'])) : '-'; ?></td>
                                        <td>
                                            <?php if($lt['status'] === 'Paid' || (float)$lt['remaining_amount'] <= 0): ?>
                                                <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i> <?php echo $is_en ? 'Settled' : 'ادا شدہ'; ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-danger"><i class="fas fa-clock me-1"></i> <?php echo $is_en ? 'Outstanding' : 'واجب الادا'; ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="d-print-none">
                                            <div class="d-flex justify-content-center gap-1">
                                                <?php if((float)$lt['remaining_amount'] > 0): ?>
                                                    <button type="button" class="btn btn-sm btn-info text-white fw-bold px-2" data-bs-toggle="modal" data-bs-target="#repayTakenModal<?php echo $lt['id']; ?>" title="<?php echo $is_en ? 'Repay Debt' : 'قرض واپس ادا کریں'; ?>">
                                                        <i class="fas fa-money-bill-wave"></i> <?php echo $is_en ? 'Repay' : 'ادائیگی'; ?>
                                                    </button>
                                                <?php endif; ?>
                                                <form method="POST" onsubmit="return confirm('<?php echo $is_en ? 'Delete this record?' : 'کیا آپ واقعی یہ ریکارڈ حذف کرنا چاہتے ہیں؟'; ?>');" style="display:inline;">
                                                    <input type="hidden" name="loan_id" value="<?php echo $lt['id']; ?>">
                                                    <button type="submit" name="delete_loan" class="btn btn-sm btn-outline-danger px-2"><i class="fas fa-trash"></i></button>
                                                </form>
                                            </div>

                                            <!-- Repay Taken Debt Modal -->
                                            <div class="modal fade" id="repayTakenModal<?php echo $lt['id']; ?>" tabindex="-1">
                                                <div class="modal-dialog modal-dialog-centered">
                                                    <div class="modal-content">
                                                        <form method="POST">
                                                            <input type="hidden" name="loan_id" value="<?php echo $lt['id']; ?>">
                                                            <div class="modal-header bg-info text-white">
                                                                <h5 class="modal-title fw-bold"><i class="fas fa-money-bill-wave me-2"></i> <?php echo $is_en ? 'Repay Debt (Madrasa Payment)' : 'قرض حسنہ کی واپسی ادائیگی'; ?></h5>
                                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <div class="modal-body text-start">
                                                                <p class="mb-1"><strong><?php echo $is_en ? 'Lender / Person:' : 'قرض دہندہ:'; ?></strong> <?php echo htmlspecialchars($lt['borrower_name']); ?></p>
                                                                <p class="mb-3"><strong><?php echo $is_en ? 'Outstanding Debt:' : 'بقایا واجب الادا قرض:'; ?></strong> <span class="text-danger fw-bold fs-5">Rs. <?php echo number_format($lt['remaining_amount']); ?></span></p>

                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold"><?php echo $is_en ? 'Amount to Pay (Rs.):' : 'واپس ادا کی جانے والی رقم:'; ?></label>
                                                                    <input type="number" step="0.01" name="repay_amount" class="form-control fw-bold text-info" value="<?php echo $lt['remaining_amount']; ?>" max="<?php echo $lt['remaining_amount']; ?>" required>
                                                                </div>
                                                                <div class="row g-2 mb-3">
                                                                    <div class="col-6">
                                                                        <label class="form-label fw-bold"><?php echo $is_en ? 'Payment Date:' : 'تاریخ ادائیگی:'; ?></label>
                                                                        <input type="date" name="repay_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                                                    </div>
                                                                    <div class="col-6">
                                                                        <label class="form-label fw-bold"><?php echo $is_en ? 'Payment Method:' : 'طریقہ ادائیگی:'; ?></label>
                                                                        <select name="payment_method" class="form-select">
                                                                            <option value="Cash">کیش (Cash)</option>
                                                                            <option value="Bank">بینک ٹرانسفر (Bank)</option>
                                                                            <option value="Cheque">چیک (Cheque)</option>
                                                                        </select>
                                                                    </div>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold"><?php echo $is_en ? 'Remarks / Notes:' : 'تفصیل / نوٹ:'; ?></label>
                                                                    <input type="text" name="remarks" class="form-control" placeholder="<?php echo $is_en ? 'e.g. Bank Transfer / Cash Return' : 'مثلاً بذریعہ بینک / کیش واپس کیا گیا'; ?>">
                                                                </div>
                                                                <div class="alert alert-info py-2 small mb-0">
                                                                    <i class="fas fa-info-circle me-1"></i> <?php echo $is_en ? 'This will update loan debt and post as Expense in Finance.' : 'یہ ادائیگی قرض کا واجب الادا بیلنس کم کرے گی اور خودکار طریقہ سے فنانس میں خرچ (Expense) درج کرے گی۔'; ?>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary fw-bold" data-bs-dismiss="modal"><?php echo $is_en ? 'Cancel' : 'منسوخ'; ?></button>
                                                                <button type="submit" name="repay_loan_taken" class="btn btn-info fw-bold text-white"><i class="fas fa-save me-1"></i> <?php echo $is_en ? 'Confirm Payment' : 'ادائیگی محفوظ کریں'; ?></button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Tab 3: Loan Recoveries Log (قرض وصولی) -->
    <?php if($active_tab === 'recoveries'): ?>
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-success text-white py-2 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="fas fa-donate me-2"></i> <?php echo $is_en ? 'Loan Recoveries Log (Cash Inflow / Income)' : 'قرض واپسی وصولی کا مکمل ریکارڈ (آمدن برائے دیے گئے قرض)'; ?></h6>
                <div>
                    <button type="button" class="btn btn-sm btn-light fw-bold text-success me-2" data-bs-toggle="modal" data-bs-target="#newRecoveryModal">
                        <i class="fas fa-plus-circle me-1"></i> <?php echo $is_en ? 'New Recovery' : 'نئی وصولی درج کریں'; ?>
                    </button>
                    <span class="badge bg-white text-success fw-bold"><?php echo count($recoveries); ?> ریکارڈز</span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 text-center" style="font-size: 13px;">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th><?php echo $is_en ? 'Date' : 'تاریخ وصولی'; ?></th>
                                <th class="text-start"><?php echo $is_en ? 'Borrower Name' : 'قرض دار / وصول کنندہ'; ?></th>
                                <th><?php echo $is_en ? 'Amount Recovered' : 'وصول شدہ رقم'; ?></th>
                                <th><?php echo $is_en ? 'Payment Method' : 'طریقہ ادائیگی'; ?></th>
                                <th><?php echo $is_en ? 'Original Loan' : 'اصل قرض'; ?></th>
                                <th><?php echo $is_en ? 'Current Remaining' : 'موجودہ بقایا'; ?></th>
                                <th><?php echo $is_en ? 'Remarks / Notes' : 'تفصیل / نوٹ'; ?></th>
                                <th><?php echo $is_en ? 'Finance Status' : 'فنانس انٹیگریشن'; ?></th>
                                <th class="d-print-none"><?php echo $is_en ? 'Actions' : 'ایکشن'; ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($recoveries)): ?>
                                <tr>
                                    <td colspan="10" class="py-4 text-muted fw-bold"><?php echo $is_en ? 'No recovery transactions recorded yet.' : 'ابھی تک قرض وصولی کا کوئی ریکارڈ موجود نہیں ہے۔'; ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach($recoveries as $idx => $r): ?>
                                    <tr>
                                        <td><?php echo $idx + 1; ?></td>
                                        <td class="fw-bold"><?php echo date('d-M-Y', strtotime($r['date'])); ?></td>
                                        <td class="text-start fw-bold">
                                            <i class="fas fa-user-check text-success me-1"></i>
                                            <?php echo htmlspecialchars($r['borrower_lender_name'] ?: 'Borrower'); ?>
                                        </td>
                                        <td class="text-success fw-bold fs-6">Rs. <?php echo number_format($r['amount']); ?></td>
                                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($r['payment_method'] ?: 'Cash'); ?></span></td>
                                        <td>Rs. <?php echo number_format((float)($r['loan_amount'] ?? 0)); ?></td>
                                        <td class="text-danger fw-bold">Rs. <?php echo number_format((float)($r['current_remaining'] ?? 0)); ?></td>
                                        <td class="small text-muted"><?php echo htmlspecialchars($r['remarks'] ?: '-'); ?></td>
                                        <td><span class="badge bg-success"><i class="fas fa-check-circle me-1"></i> آمدن (Income)</span></td>
                                        <td class="d-print-none">
                                            <form method="POST" onsubmit="return confirm('<?php echo $is_en ? 'Delete this recovery transaction?' : 'کیا آپ واقعی یہ وصولی ٹرانزیکشن حذف کرنا چاہتے ہیں؟'; ?>');" style="display:inline;">
                                                <input type="hidden" name="tx_id" value="<?php echo $r['id']; ?>">
                                                <button type="submit" name="delete_transaction" class="btn btn-sm btn-outline-danger px-2"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Tab 4: Loan Repayments Log (قرض واپسی / ادائیگی) -->
    <?php if($active_tab === 'repayments'): ?>
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-info text-white py-2 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="fas fa-money-bill-wave me-2"></i> <?php echo $is_en ? 'Loan Repayments Log (Cash Outflow / Expense)' : 'قرض حسنہ واپسی ادائیگی کا مکمل ریکارڈ (خرچ برائے لیے ہوئے قرض)'; ?></h6>
                <div>
                    <button type="button" class="btn btn-sm btn-light fw-bold text-info me-2" data-bs-toggle="modal" data-bs-target="#newRepaymentModal">
                        <i class="fas fa-plus-circle me-1"></i> <?php echo $is_en ? 'New Repayment' : 'نئی ادائیگی درج کریں'; ?>
                    </button>
                    <span class="badge bg-white text-info fw-bold"><?php echo count($repayments); ?> ریکارڈز</span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 text-center" style="font-size: 13px;">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th><?php echo $is_en ? 'Date' : 'تاریخ ادائیگی'; ?></th>
                                <th class="text-start"><?php echo $is_en ? 'Lender Name' : 'قرض دہندہ کا نام'; ?></th>
                                <th><?php echo $is_en ? 'Amount Paid' : 'ادا شدہ رقم'; ?></th>
                                <th><?php echo $is_en ? 'Payment Method' : 'طریقہ ادائیگی'; ?></th>
                                <th><?php echo $is_en ? 'Original Debt' : 'کل لیا گیا قرض'; ?></th>
                                <th><?php echo $is_en ? 'Remaining Debt' : 'موجودہ واجب الادا قرض'; ?></th>
                                <th><?php echo $is_en ? 'Remarks / Notes' : 'تفصیل / نوٹ'; ?></th>
                                <th><?php echo $is_en ? 'Finance Status' : 'فنانس انٹیگریشن'; ?></th>
                                <th class="d-print-none"><?php echo $is_en ? 'Actions' : 'ایکشن'; ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($repayments)): ?>
                                <tr>
                                    <td colspan="10" class="py-4 text-muted fw-bold"><?php echo $is_en ? 'No debt repayments recorded yet.' : 'ابھی تک قرض ادائیگی کا کوئی ریکارڈ موجود نہیں ہے۔'; ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach($repayments as $idx => $rp): ?>
                                    <tr>
                                        <td><?php echo $idx + 1; ?></td>
                                        <td class="fw-bold"><?php echo date('d-M-Y', strtotime($rp['date'])); ?></td>
                                        <td class="text-start fw-bold">
                                            <i class="fas fa-hand-holding-usd text-info me-1"></i>
                                            <?php echo htmlspecialchars($rp['borrower_lender_name'] ?: 'Lender'); ?>
                                        </td>
                                        <td class="text-info fw-bold fs-6">Rs. <?php echo number_format($rp['amount']); ?></td>
                                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($rp['payment_method'] ?: 'Cash'); ?></span></td>
                                        <td>Rs. <?php echo number_format((float)($rp['loan_amount'] ?? 0)); ?></td>
                                        <td class="text-danger fw-bold">Rs. <?php echo number_format((float)($rp['current_remaining'] ?? 0)); ?></td>
                                        <td class="small text-muted"><?php echo htmlspecialchars($rp['remarks'] ?: '-'); ?></td>
                                        <td><span class="badge bg-danger"><i class="fas fa-check-circle me-1"></i> خرچ (Expense)</span></td>
                                        <td class="d-print-none">
                                            <form method="POST" onsubmit="return confirm('<?php echo $is_en ? 'Delete this repayment transaction?' : 'کیا آپ واقعی یہ ادائیگی ٹرانزیکشن حذف کرنا چاہتے ہیں؟'; ?>');" style="display:inline;">
                                                <input type="hidden" name="tx_id" value="<?php echo $rp['id']; ?>">
                                                <button type="submit" name="delete_transaction" class="btn btn-sm btn-outline-danger px-2"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- ================= MODALS ================= -->

<!-- Modal 1: Issue Loan Given (نیا قرض جاری کریں) -->
<div class="modal fade" id="issueGivenModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-hand-holding-usd me-2"></i> <?php echo $is_en ? 'Issue Loan / Advance (Given)' : 'نیا قرض / پیشگی رقم جاری کریں'; ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-start">
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $is_en ? 'Select Staff Member (Optional):' : 'استاد / ملازم منتخب کریں (اختیاری):'; ?></label>
                        <select name="teacher_id" id="givenTeacherSelect" class="form-select" onchange="autoFillTeacher(this)">
                            <option value="">-- <?php echo $is_en ? 'Other / External Person' : 'دیگر / بیرونی فرد'; ?> --</option>
                            <?php foreach($teachers as $t): ?>
                                <option value="<?php echo $t['id']; ?>" data-name="<?php echo htmlspecialchars($t['name']); ?>" data-phone="<?php echo htmlspecialchars($t['phone'] ?? ''); ?>">
                                    <?php echo htmlspecialchars($t['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $is_en ? 'Borrower Name:' : 'وصول کنندہ کا نام:'; ?></label>
                        <input type="text" name="borrower_name" id="givenBorrowerName" class="form-control fw-bold" placeholder="مثلاً: قاری صاحب / فرد کا نام" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $is_en ? 'Phone Number:' : 'فون نمبر:'; ?></label>
                        <input type="text" name="phone" id="givenPhone" class="form-control" placeholder="0300-1234567">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold"><?php echo $is_en ? 'Loan Amount (Rs.):' : 'قرض کی رقم (روپے):'; ?></label>
                            <input type="number" step="0.01" name="loan_amount" class="form-control fw-bold text-primary" placeholder="50000" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold"><?php echo $is_en ? 'Monthly Inst. (Rs.):' : 'ماہانہ قسط:'; ?></label>
                            <input type="number" step="0.01" name="installment_amount" class="form-control" placeholder="5000">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $is_en ? 'Payment Account (Where money was paid from):' : 'ادائیگی کھاتہ (رقم کہاں سے ادا کی گئی؟):'; ?></label>
                        <select name="account_id" class="form-select" required>
                            <?php foreach($payment_accounts as $pa): ?>
                                <option value="<?php echo $pa['id']; ?>" <?php echo ($pa['code'] === '1001') ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pa['name'] . ' (' . $pa['code'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold"><?php echo $is_en ? 'Date Issued:' : 'تاریخ اجرا:'; ?></label>
                            <input type="date" name="date_issued" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold"><?php echo $is_en ? 'Due Date:' : 'واپسی کی تاریخ:'; ?></label>
                            <input type="date" name="due_date" class="form-control">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $is_en ? 'Description / Purpose:' : 'تفصیل / مقصد:'; ?></label>
                        <textarea name="description" class="form-control" rows="2" placeholder="<?php echo $is_en ? 'e.g. Emergency medical advance' : 'مثلاً: گھریلو ضرورت یا علاج کے لیے قرض حسنہ'; ?>"></textarea>
                    </div>
                    <div class="alert alert-info py-2 small mb-0">
                        <i class="fas fa-info-circle me-1"></i> <?php echo $is_en ? 'This will automatically record an Expense in Finance.' : 'یہ قرض خودکار طریقہ سے فنانس رجسٹر کے اخراجات (Expense) میں درج ہو جائے گا۔'; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary fw-bold" data-bs-dismiss="modal"><?php echo $is_en ? 'Cancel' : 'منسوخ'; ?></button>
                    <button type="submit" name="issue_loan_given" class="btn btn-primary fw-bold"><i class="fas fa-save me-1"></i> <?php echo $is_en ? 'Issue Loan' : 'قرض جاری کریں'; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal 2: Record Loan Taken (ادارہ کا لیا گیا قرض) -->
<div class="modal fade" id="recordTakenModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title fw-bold"><i class="fas fa-hand-holding me-2"></i> <?php echo $is_en ? 'Record Loan Taken by Madrasa' : 'ادارہ کا لیا گیا قرض حسنہ درج کریں'; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-start">
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $is_en ? 'Lender / Donor Name:' : 'قرض دینے والے کا نام:'; ?></label>
                        <input type="text" name="lender_name" class="form-control fw-bold" placeholder="مثلاً: حاجی محمد اکرم صاحب" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $is_en ? 'Phone Number:' : 'فون نمبر:'; ?></label>
                        <input type="text" name="phone" class="form-control" placeholder="0300-1234567">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold"><?php echo $is_en ? 'Amount Taken (Rs.):' : 'لی گئی رقم (روپے):'; ?></label>
                            <input type="number" step="0.01" name="loan_amount" class="form-control fw-bold text-warning" placeholder="100000" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold"><?php echo $is_en ? 'Date Taken:' : 'تاریخ وصولی:'; ?></label>
                            <input type="date" name="date_issued" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $is_en ? 'Deposit Account (Where money was deposited):' : 'وصولی کھاتہ (رقم کس کھاتے میں جمع ہوئی؟):'; ?></label>
                        <select name="account_id" class="form-select" required>
                            <?php foreach($payment_accounts as $pa): ?>
                                <option value="<?php echo $pa['id']; ?>" <?php echo ($pa['code'] === '1001') ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pa['name'] . ' (' . $pa['code'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $is_en ? 'Repayment Due Date:' : 'واپسی کی مقررہ تاریخ:'; ?></label>
                        <input type="date" name="due_date" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $is_en ? 'Description / Notes:' : 'تفصیل / شرائط:'; ?></label>
                        <textarea name="description" class="form-control" rows="2" placeholder="<?php echo $is_en ? 'e.g. Building construction temporary loan' : 'مثلاً: مدرسہ تعمیراتی کام کے لیے عارضی قرض حسنہ'; ?>"></textarea>
                    </div>
                    <div class="alert alert-info py-2 small mb-0">
                        <i class="fas fa-info-circle me-1"></i> <?php echo $is_en ? 'This will automatically record Cash Inflow (Income) in Finance.' : 'یہ قرض خودکار طریقہ سے فنانس رجسٹر کی آمدن (Income Inflow) میں درج ہو جائے گا۔'; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary fw-bold" data-bs-dismiss="modal"><?php echo $is_en ? 'Cancel' : 'منسوخ'; ?></button>
                    <button type="submit" name="record_loan_taken" class="btn btn-warning fw-bold text-dark"><i class="fas fa-save me-1"></i> <?php echo $is_en ? 'Record Loan' : 'محفوظ کریں'; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal 3: New Loan Recovery (نئی قرض وصولی درج کریں) -->
<div class="modal fade" id="newRecoveryModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-donate me-2"></i> <?php echo $is_en ? 'Record Loan Recovery' : 'نئی قرض وصولی درج کریں (آمدن)'; ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-start">
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $is_en ? 'Select Borrower / Loan Record:' : 'قرض دار / ریکارڈ منتخب کریں:'; ?></label>
                        <select name="loan_id" id="recoveryLoanSelect" class="form-select" onchange="autoFillRecoveryAmount(this)" required>
                            <option value="">-- منتخب کریں --</option>
                            <?php 
                            $active_given = array_filter($loans_given, function($l){ return (float)$l['remaining_amount'] > 0; });
                            foreach($active_given as $l): ?>
                                <option value="<?php echo $l['id']; ?>" data-rem="<?php echo $l['remaining_amount']; ?>" data-inst="<?php echo $l['installment_amount']; ?>">
                                    <?php echo htmlspecialchars($l['teacher_name'] ?: $l['borrower_name']); ?> (بقایا: Rs. <?php echo number_format($l['remaining_amount']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $is_en ? 'Amount Received (Rs.):' : 'وصول شدہ رقم (روپے):'; ?></label>
                        <input type="number" step="0.01" name="repay_amount" id="recoveryAmountInput" class="form-control fw-bold text-success fs-5" placeholder="5000" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold"><?php echo $is_en ? 'Receipt Date:' : 'تاریخ وصولی:'; ?></label>
                            <input type="date" name="repay_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold"><?php echo $is_en ? 'Payment Method:' : 'طریقہ ادائیگی:'; ?></label>
                            <select name="payment_method" class="form-select">
                                <option value="Cash">کیش (Cash)</option>
                                <option value="Bank">بینک ٹرانسفر (Bank)</option>
                                <option value="Cheque">چیک (Cheque)</option>
                                <option value="Salary Deduction">تنخواہ سے کٹوتی</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $is_en ? 'Deposit Account (Where money was received):' : 'وصولی کھاتہ (رقم کس کھاتے میں وصول ہوئی؟):'; ?></label>
                        <select name="account_id" class="form-select" required>
                            <?php foreach($payment_accounts as $pa): ?>
                                <option value="<?php echo $pa['id']; ?>" <?php echo ($pa['code'] === '1001') ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pa['name'] . ' (' . $pa['code'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $is_en ? 'Remarks / Notes:' : 'تفصیل / نوٹ:'; ?></label>
                        <input type="text" name="remarks" class="form-control" placeholder="<?php echo $is_en ? 'e.g. Installment received' : 'مثلاً: ماہانہ قسط موصول ہوئی'; ?>">
                    </div>
                    <div class="alert alert-info py-2 small mb-0">
                        <i class="fas fa-info-circle me-1"></i> <?php echo $is_en ? 'This will update loan balance and post as Income in Finance.' : 'یہ وصولی قرض کا بیلنس کم کرے گی اور خودکار طریقہ سے فنانس میں آمدن (Income) درج ہو گی۔'; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary fw-bold" data-bs-dismiss="modal"><?php echo $is_en ? 'Cancel' : 'منسوخ'; ?></button>
                    <button type="submit" name="receive_repayment" class="btn btn-success fw-bold text-white"><i class="fas fa-save me-1"></i> <?php echo $is_en ? 'Save Recovery' : 'وصولی محفوظ کریں'; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal 4: New Loan Repayment (نئی قرض ادائیگی درج کریں) -->
<div class="modal fade" id="newRepaymentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-money-bill-wave me-2"></i> <?php echo $is_en ? 'Record Debt Repayment' : 'نئی قرض واپسی ادائیگی درج کریں (خرچ)'; ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-start">
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $is_en ? 'Select Lender / Debt Record:' : 'قرض دہندہ / ریکارڈ منتخب کریں:'; ?></label>
                        <select name="loan_id" id="repayLoanSelect" class="form-select" onchange="autoFillRepayAmount(this)" required>
                            <option value="">-- منتخب کریں --</option>
                            <?php 
                            $active_taken = array_filter($loans_taken, function($l){ return (float)$l['remaining_amount'] > 0; });
                            foreach($active_taken as $lt): ?>
                                <option value="<?php echo $lt['id']; ?>" data-rem="<?php echo $lt['remaining_amount']; ?>">
                                    <?php echo htmlspecialchars($lt['borrower_name']); ?> (واجب الادا: Rs. <?php echo number_format($lt['remaining_amount']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $is_en ? 'Amount to Pay (Rs.):' : 'ادا کی جانے والی رقم:'; ?></label>
                        <input type="number" step="0.01" name="repay_amount" id="repayAmountInput" class="form-control fw-bold text-info fs-5" placeholder="50000" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold"><?php echo $is_en ? 'Payment Date:' : 'تاریخ ادائیگی:'; ?></label>
                            <input type="date" name="repay_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold"><?php echo $is_en ? 'Payment Method:' : 'طریقہ ادائیگی:'; ?></label>
                            <select name="payment_method" class="form-select">
                                <option value="Cash">کیش (Cash)</option>
                                <option value="Bank">بینک ٹرانسفر (Bank)</option>
                                <option value="Cheque">چیک (Cheque)</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $is_en ? 'Payment Account (From which money was paid):' : 'ادائیگی کھاتہ (رقم کس کھاتے سے ادا کی گئی؟):'; ?></label>
                        <select name="account_id" class="form-select" required>
                            <?php foreach($payment_accounts as $pa): ?>
                                <option value="<?php echo $pa['id']; ?>" <?php echo ($pa['code'] === '1001') ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pa['name'] . ' (' . $pa['code'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $is_en ? 'Remarks / Notes:' : 'تفصیل / نوٹ:'; ?></label>
                        <input type="text" name="remarks" class="form-control" placeholder="<?php echo $is_en ? 'e.g. Bank transfer return' : 'مثلاً: بذریعہ بینک واپس ادا کیا گیا'; ?>">
                    </div>
                    <div class="alert alert-info py-2 small mb-0">
                        <i class="fas fa-info-circle me-1"></i> <?php echo $is_en ? 'This will update loan debt and post as Expense in Finance.' : 'یہ ادائیگی قرض کا واجب الادا بیلنس کم کرے گی اور خودکار طریقہ سے فنانس میں خرچ (Expense) درج کرے گی۔'; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary fw-bold" data-bs-dismiss="modal"><?php echo $is_en ? 'Cancel' : 'منسوخ'; ?></button>
                    <button type="submit" name="repay_loan_taken" class="btn btn-info fw-bold text-white"><i class="fas fa-save me-1"></i> <?php echo $is_en ? 'Confirm Payment' : 'ادائیگی محفوظ کریں'; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function autoFillTeacher(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.value) {
        document.getElementById('givenBorrowerName').value = opt.getAttribute('data-name') || '';
        document.getElementById('givenPhone').value = opt.getAttribute('data-phone') || '';
    }
}

function autoFillRecoveryAmount(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.value) {
        const rem = parseFloat(opt.getAttribute('data-rem')) || 0;
        const inst = parseFloat(opt.getAttribute('data-inst')) || 0;
        const defaultAmt = (inst > 0 && inst <= rem) ? inst : rem;
        document.getElementById('recoveryAmountInput').value = defaultAmt;
        document.getElementById('recoveryAmountInput').max = rem;
    }
}

function autoFillRepayAmount(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.value) {
        const rem = parseFloat(opt.getAttribute('data-rem')) || 0;
        document.getElementById('repayAmountInput').value = rem;
        document.getElementById('repayAmountInput').max = rem;
    }
}
</script>

<?php include 'footer.php'; ?>
