<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once __DIR__ . '/finance_accounting_bridge.php';

global $system_type;
$system_type = $_SESSION['system_type'] ?? 'Madrasa';
$is_masjid = ($system_type === 'Masjid');
$sys_cond_t = $is_masjid 
    ? "system_type = 'Masjid'" 
    : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
$sys_cond_p = $is_masjid 
    ? "p.system_type = 'Masjid'" 
    : "(p.system_type = 'Madrasa' OR p.system_type IS NULL OR p.system_type = '')";

if (!isset($_SESSION['user_id']) || (!app_has_perm('manage_stipends') && !app_has_perm('manage_masjid_stipends') && !app_has_perm('manage_erp') && ($_SESSION['role'] ?? '') !== 'admin' && ($_SESSION['role'] ?? '') !== 'nazim')) {
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

// Ensure acc_payroll table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS acc_payroll (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT NOT NULL,
        month_year VARCHAR(20) NOT NULL,
        basic_salary DECIMAL(15,2) DEFAULT 0,
        allowances DECIMAL(15,2) DEFAULT 0,
        deductions DECIMAL(15,2) DEFAULT 0,
        net_salary DECIMAL(15,2) DEFAULT 0,
        status VARCHAR(50) DEFAULT 'Unpaid',
        payment_date DATE NULL,
        created_by INT DEFAULT 1,
        system_type VARCHAR(50) DEFAULT 'Madrasa',
        is_deleted TINYINT(1) DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    ensure_column($pdo, 'acc_payroll', 'system_type', "VARCHAR(50) DEFAULT 'Madrasa'");
    ensure_column($pdo, 'acc_payroll', 'created_by', "INT DEFAULT 1");
    ensure_column($pdo, 'acc_payroll', 'is_deleted', "TINYINT(1) DEFAULT 0");
} catch (Exception $e) {}

$uid = $_SESSION['user_id'];
$is_en = (isset($_SESSION['lang']) && $_SESSION['lang'] === 'en');
$msg = '';

// Helper: Ensure Staff Salary head exists in heads and acc_chart_of_accounts
$sal_head_id = 0;
$sal_head_name = $is_en ? 'Staff Salaries' : 'وظائف عملہ';
try {
    $h = $pdo->prepare("SELECT id FROM heads WHERE name = ? AND type = 'Expense' AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1");
    $h->execute([$sal_head_name]);
    $headRow = $h->fetchColumn();
    if (!$headRow) {
        $h2 = $pdo->prepare("SELECT id FROM heads WHERE name LIKE '%وظائف%' AND type = 'Expense' AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1");
        $h2->execute();
        $headRow = $h2->fetchColumn();
    }
    if (!$headRow) {
        $insH = $pdo->prepare("INSERT INTO heads (name, type, is_deleted) VALUES (?, 'Expense', 0)");
        $insH->execute([$sal_head_name]);
        $sal_head_id = (int)$pdo->lastInsertId();
    } else {
        $sal_head_id = (int)$headRow;
    }
} catch (Exception $e) {}

$cash_acc_id = 0;
try {
    $cash_acc_id = (int)($pdo->query("SELECT id FROM acc_chart_of_accounts WHERE code = '1001' AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1")->fetchColumn() ?: 0);
    if (!$cash_acc_id && function_exists('finance_accounting_ensure_account')) {
        $cash_acc_id = finance_accounting_ensure_account($pdo, '1001', 'Cash in Hand', 'Asset');
    }
} catch (Exception $e) {}

// 1. Generate Payroll Action
if (isset($_POST['generate_payroll'])) {
    $month_year = trim($_POST['month_year'] ?? date('Y-m'));
    if (empty($month_year)) $month_year = date('Y-m');

    $teachers = $pdo->query("SELECT * FROM teachers WHERE (status = 'Active' OR status IS NULL OR status = '') AND (is_deleted = 0 OR is_deleted IS NULL) AND $sys_cond_t")->fetchAll(PDO::FETCH_ASSOC);

    $count = 0;
    foreach ($teachers as $t) {
        $check = $pdo->prepare("SELECT id FROM acc_payroll WHERE teacher_id = ? AND month_year = ? AND is_deleted = 0");
        $check->execute([$t['id'], $month_year]);
        if (!$check->fetch()) {
            $basic = (float)($t['basic_salary'] ?? $t['salary'] ?? 0);
            $house_rent = (float)($t['house_rent'] ?? 0);
            $med = (float)($t['medical_allowance'] ?? 0);
            $travel = (float)($t['travel_allowance'] ?? 0);
            $allowances = $house_rent + $med + $travel;

            $daily_rate = (float)($t['daily_deduction_rate'] ?? 0);
            $absences_stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance_teachers WHERE teacher_id = ? AND DATE_FORMAT(date, '%Y-%m') = ? AND status = 'A'");
            $absences_stmt->execute([$t['id'], $month_year]);
            $absences = (int)$absences_stmt->fetchColumn();
            $deductions = $absences * $daily_rate;

            $net = $basic + $allowances - $deductions;
            if ($net < 0) $net = 0;

            $stmt = $pdo->prepare("INSERT INTO acc_payroll (teacher_id, month_year, basic_salary, allowances, deductions, net_salary, status, created_by, system_type, is_deleted) VALUES (?, ?, ?, ?, ?, ?, 'Unpaid', ?, '$system_type', 0)");
            $stmt->execute([$t['id'], $month_year, $basic, $allowances, $deductions, $net, $uid]);
            $count++;
        }
    }
    $msg = "<div class='alert alert-success fw-bold shadow-sm'><i class='fas fa-check-circle me-1'></i> " . ($is_en ? "Payroll generated for $month_year ($count new records added)." : "ماہ $month_year کے لیے $count اساتذہ کا وظیفہ پے رول کامیابی سے تیار کر دیا گیا۔") . "</div>";
}

// 2. Mark Paid Action
if (isset($_POST['mark_paid'])) {
    $payroll_id = (int)$_POST['payroll_id'];
    $pay_date = $_POST['payment_date'] ?? date('Y-m-d');
    $allowances = (float)($_POST['allowances'] ?? 0);
    $deductions = (float)($_POST['deductions'] ?? 0);
    $loan_deduction = (float)($_POST['loan_deduction'] ?? 0);
    
    $p_stmt = $pdo->prepare("SELECT p.*, t.name as teacher_name FROM acc_payroll p JOIN teachers t ON p.teacher_id = t.id WHERE p.id = ?");
    $p_stmt->execute([$payroll_id]);
    $p_row = $p_stmt->fetch(PDO::FETCH_ASSOC);

    if ($p_row && $p_row['status'] !== 'Paid') {
        $basic = (float)$p_row['basic_salary'];
        $t_name = $p_row['teacher_name'];
        $t_id = $p_row['teacher_id'];
        $m_year = $p_row['month_year'];
        $p_sys = !empty($p_row['system_type']) ? $p_row['system_type'] : $system_type;
        
        $total_deductions = $deductions + $loan_deduction;
        $net_sal = $basic + $allowances - $total_deductions;
        if ($net_sal < 0) $net_sal = 0;

        $pdo->prepare("UPDATE acc_payroll SET allowances = ?, deductions = ?, net_salary = ?, status = 'Paid', payment_date = ? WHERE id = ?")
            ->execute([$allowances, $total_deductions, $net_sal, $pay_date, $payroll_id]);

        // Insert into Finance Expense
        $f_desc = "مد: وظائف عملہ | تفصیل: وظیفہ/تنخواہ ادا کی گئی برائے ماہ $m_year: $t_name";
        $uname = $_SESSION['username'] ?? ($_SESSION['name'] ?? 'Admin');
        
        // Check if existing finance entry already exists for this payroll
        $chk_fin = $pdo->prepare("SELECT id FROM finance WHERE type = 'Expense' AND description LIKE ? AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1");
        $chk_fin->execute(["%ماہ $m_year: $t_name%"]);
        $existing_fid = $chk_fin->fetchColumn();
        
        $fin_id = 0;
        if ($existing_fid) {
            $fin_id = (int)$existing_fid;
            $pdo->prepare("UPDATE finance SET amount = ?, date = ?, user_id = ?, user_name = ?, head_id = ?, head_name = 'وظائف عملہ', spender_name = ?, donor_name = ?, description = ?, account_id = ?, system_type = ?, is_deleted = 0 WHERE id = ?")
                ->execute([$net_sal, $pay_date, $uid, $uname, $sal_head_id, $t_name, $t_name, $f_desc, $cash_acc_id, $p_sys, $fin_id]);
        } else {
            $pdo->prepare("INSERT INTO finance (user_id, user_name, type, head_id, head_name, amount, date, donor_name, spender_name, description, account_id, is_deleted, system_type) VALUES (?, ?, 'Expense', ?, 'وظائف عملہ', ?, ?, ?, ?, ?, ?, 0, ?)")
                ->execute([$uid, $uname, $sal_head_id, $net_sal, $pay_date, $t_name, $t_name, $f_desc, $cash_acc_id, $p_sys]);
            $fin_id = (int)$pdo->lastInsertId();
        }

        if (function_exists('finance_accounting_post')) {
            try { finance_accounting_post($pdo, $fin_id); } catch (Exception $e) {}
        }
        if (function_exists('payroll_accounting_post')) {
            try { payroll_accounting_post($pdo, $payroll_id); } catch (Exception $e) {}
        }

        if ($loan_deduction > 0) {
            // Find active loan
            $active_loan = $pdo->query("SELECT id, remaining_amount FROM staff_loans WHERE teacher_id = $t_id AND status = 'Active' AND is_deleted = 0 AND system_type = '$p_sys' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            
            // Record loan recovery in Finance
            $loan_head_id = 0;
            try {
                $lh = $pdo->prepare("SELECT id FROM heads WHERE name LIKE '%قرض%' AND type = 'Income' AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1");
                $lh->execute();
                $loan_head_id = (int)($lh->fetchColumn() ?: 0);
            } catch (Exception $e) {}
            
            $fin_desc = "مد: قرض حسنہ (اساتذہ) | تفصیل: قرض وصولی از تنخواہ کٹوتی برائے ماہ $m_year: $t_name";
            $pdo->prepare("INSERT INTO finance (user_id, user_name, type, head_id, head_name, amount, date, donor_name, spender_name, description, account_id, is_deleted, system_type) VALUES (?, ?, 'Income', ?, 'قرض حسنہ (اساتذہ)', ?, ?, ?, ?, ?, ?, 0, ?)")
                ->execute([$uid, $uname, $loan_head_id, $loan_deduction, $pay_date, $t_name, $t_name, $fin_desc, $cash_acc_id, $p_sys]);
            $loan_fin_id = (int)$pdo->lastInsertId();
            if (function_exists('finance_accounting_post')) {
                try { finance_accounting_post($pdo, $loan_fin_id); } catch (Exception $e) {}
            }

            // Update staff_loans table if active loan exists
            if ($active_loan) {
                $rem = (float)$active_loan['remaining_amount'] - $loan_deduction;
                if ($rem < 0) $rem = 0;
                $new_status = ($rem == 0) ? 'Paid' : 'Active';
                $pdo->prepare("UPDATE staff_loans SET remaining_amount = ?, repaid_amount = COALESCE(repaid_amount,0) + ?, status = ? WHERE id = ?")->execute([$rem, $loan_deduction, $new_status, $active_loan['id']]);
            }
        }

        $msg = "<div class='alert alert-success fw-bold shadow-sm'><i class='fas fa-check-circle me-1'></i> " . ($is_en ? "Stipend of $t_name marked as PAID and posted to Finance Expense." : "استاد $t_name کا وظیفہ کامیابی سے ادا شدہ مارک ہو گیا اور فنانس آمدن و خرچ میں درج ہو گیا۔") . "</div>";
    }
}

// 3. Mark Unpaid Action
if (isset($_POST['mark_unpaid'])) {
    $payroll_id = (int)$_POST['payroll_id'];
    
    $p_stmt = $pdo->prepare("SELECT p.*, t.name as teacher_name FROM acc_payroll p JOIN teachers t ON p.teacher_id = t.id WHERE p.id = ?");
    $p_stmt->execute([$payroll_id]);
    $p_row = $p_stmt->fetch(PDO::FETCH_ASSOC);
    if ($p_row) {
        $t_name = $p_row['teacher_name'];
        $m_year = $p_row['month_year'];
        $chk_fin = $pdo->prepare("SELECT id FROM finance WHERE type = 'Expense' AND description LIKE ? AND (is_deleted = 0 OR is_deleted IS NULL)");
        $chk_fin->execute(["%ماہ $m_year: $t_name%"]);
        $fids = $chk_fin->fetchAll(PDO::FETCH_COLUMN);
        foreach ($fids as $fid) {
            $pdo->prepare("UPDATE finance SET is_deleted = 1 WHERE id = ?")->execute([$fid]);
            if (function_exists('finance_accounting_delete_voucher')) {
                try { finance_accounting_delete_voucher($pdo, (int)$fid); } catch (Exception $e) {}
            }
        }
    }
    
    $pdo->prepare("UPDATE acc_payroll SET status = 'Unpaid', payment_date = NULL WHERE id = ?")->execute([$payroll_id]);
    $msg = "<div class='alert alert-info fw-bold shadow-sm'><i class='fas fa-info-circle me-1'></i> " . ($is_en ? "Stipend marked as UNPAID." : "وظیفہ غیر ادا شدہ (Unpaid) قرار دے دیا گیا اور فنانس اخراجات سے منسوخ کر دیا گیا۔") . "</div>";
}

// 4. Update Allowances/Deductions Action
if (isset($_POST['update_payroll'])) {
    $payroll_id = (int)$_POST['payroll_id'];
    $allowances = (float)$_POST['allowances'];
    $deductions = (float)$_POST['deductions'];
    
    $p_stmt = $pdo->prepare("SELECT basic_salary FROM acc_payroll WHERE id = ?");
    $p_stmt->execute([$payroll_id]);
    $basic = (float)$p_stmt->fetchColumn();
    $net = $basic + $allowances - $deductions;
    if ($net < 0) $net = 0;

    $pdo->prepare("UPDATE acc_payroll SET allowances = ?, deductions = ?, net_salary = ? WHERE id = ?")->execute([$allowances, $deductions, $net, $payroll_id]);
    $msg = "<div class='alert alert-success fw-bold shadow-sm'><i class='fas fa-check-circle me-1'></i> " . ($is_en ? "Allowances & Deductions updated." : "الاؤنس اور کٹوتی کی رقم کامیابی سے اپڈیٹ ہو گئی۔") . "</div>";
}

// 5. Delete Action
if (isset($_POST['delete_payroll'])) {
    $payroll_id = (int)$_POST['payroll_id'];
    $pdo->prepare("UPDATE acc_payroll SET is_deleted = 1 WHERE id = ? AND status = 'Unpaid'")->execute([$payroll_id]);
    $msg = "<div class='alert alert-warning fw-bold shadow-sm'><i class='fas fa-trash me-1'></i> " . ($is_en ? "Payroll entry deleted." : "غیر ادا شدہ پے رول ریکارڈ حذف کر دیا گیا۔") . "</div>";
}

// Filters
$filter_teacher_id = isset($_GET['teacher_id']) && $_GET['teacher_id'] !== '' ? (int)$_GET['teacher_id'] : 0;
$filter_month = $_GET['month'] ?? '';
$filter_status = $_GET['status'] ?? '';

// Distinct months for dropdown
$months_list = $pdo->query("SELECT DISTINCT month_year FROM acc_payroll WHERE is_deleted = 0 AND $sys_cond_p ORDER BY month_year DESC")->fetchAll(PDO::FETCH_COLUMN);

// Teachers for dropdown
$all_teachers = $pdo->query("SELECT id, name FROM teachers WHERE (is_deleted = 0 OR is_deleted IS NULL) AND $sys_cond_t ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Base Query
$active_loans = [];
try {
    $loans_q = $pdo->query("SELECT teacher_id, SUM(remaining_amount) as rem, MAX(installment_amount) as inst FROM staff_loans WHERE is_deleted = 0 AND status = 'Active' AND system_type = '$system_type' GROUP BY teacher_id")->fetchAll(PDO::FETCH_ASSOC);
    foreach($loans_q as $l) {
        $active_loans[$l['teacher_id']] = ['rem' => (float)$l['rem'], 'inst' => (float)$l['inst']];
    }
} catch(Exception $e) {}

$where = ["p.is_deleted = 0", ($is_masjid ? "p.system_type = 'Masjid' AND t.system_type = 'Masjid'" : "(p.system_type = 'Madrasa' OR p.system_type IS NULL OR p.system_type = '') AND (t.system_type = 'Madrasa' OR t.system_type IS NULL OR t.system_type = '')")];
$params = [];

if ($filter_teacher_id > 0) {
    $where[] = "p.teacher_id = ?";
    $params[] = $filter_teacher_id;
}
if (!empty($filter_month)) {
    $where[] = "p.month_year = ?";
    $params[] = $filter_month;
}
if (!empty($filter_status)) {
    $where[] = "p.status = ?";
    $params[] = $filter_status;
}

$where_sql = implode(" AND ", $where);
$sql = "SELECT p.*, t.name as teacher_name, t.phone as teacher_phone 
        FROM acc_payroll p 
        JOIN teachers t ON p.teacher_id = t.id 
        WHERE $where_sql 
        ORDER BY p.month_year DESC, p.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payroll_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Metrics calculation
$total_payable = 0;
$total_paid = 0;
$total_unpaid = 0;
$paid_count = 0;
$unpaid_count = 0;

foreach ($payroll_records as $r) {
    $net = (float)$r['net_salary'];
    $total_payable += $net;
    if ($r['status'] === 'Paid') {
        $total_paid += $net;
        $paid_count++;
    } else {
        $total_unpaid += $net;
        $unpaid_count++;
    }
}

include 'header.php';
?>

<div class="container-fluid py-3" dir="<?php echo $is_en ? 'ltr' : 'rtl'; ?>">
    <!-- Page Header & Action Bar -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div>
            <h3 class="fw-bold text-primary mb-0"><i class="fas fa-hand-holding-usd me-2"></i> <?php echo $is_en ? 'Staff Stipends & Salaries Management' : 'وظائف و تنخواہیں مینیجمنٹ'; ?></h3>
            <small class="text-muted fw-bold"><?php echo $is_en ? 'Complete record of paid & payable salaries with staff filter and finance integration.' : 'تمام اساتذہ و ملازمین کے ادا شدہ اور واجب الادا وظائف کا مکمل نظام مع فنانس انٹیگریشن۔'; ?></small>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-success fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#generateModal">
                <i class="fas fa-plus-circle me-1"></i> <?php echo $is_en ? 'Generate Payroll' : 'نیا پے رول بنائیں'; ?>
            </button>
            <button class="btn btn-outline-dark fw-bold shadow-sm" onclick="window.print()">
                <i class="fas fa-print me-1"></i> <?php echo $is_en ? 'Print Report' : 'پرنٹ رپورٹ'; ?>
            </button>
        </div>
    </div>

    <?php if($msg) echo $msg; ?>

    <!-- Summary Metrics Cards -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm bg-primary text-dark p-3 rounded-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="fw-bold text-dark"><?php echo $is_en ? 'Total Net Payable' : 'کل واجب الادا وظائف'; ?></small>
                        <h4 class="fw-bold text-dark mb-0 mt-1">Rs. <?php echo number_format($total_payable); ?></h4>
                    </div>
                    <i class="fas fa-coins fa-2x opacity-50"></i>
                </div>
                <small class="mt-2 text-dark"><?php echo count($payroll_records); ?> <?php echo $is_en ? 'Records' : 'کل ملازمین/اندراجات'; ?></small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm bg-success text-white p-3 rounded-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="fw-bold text-white"><?php echo $is_en ? 'Total Paid' : 'کل ادا شدہ رقم'; ?></small>
                        <h4 class="fw-bold text-white mb-0 mt-1">Rs. <?php echo number_format($total_paid); ?></h4>
                    </div>
                    <i class="fas fa-check-circle fa-2x opacity-50"></i>
                </div>
                <small class="mt-2 text-white"><?php echo $paid_count; ?> <?php echo $is_en ? 'Paid Staff' : 'ادا شدہ افراد'; ?></small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm bg-danger text-white p-3 rounded-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="fw-bold text-white"><?php echo $is_en ? 'Total Remaining Unpaid' : 'بقایا غیر ادا شدہ رقم'; ?></small>
                        <h4 class="fw-bold text-white mb-0 mt-1">Rs. <?php echo number_format($total_unpaid); ?></h4>
                    </div>
                    <i class="fas fa-clock fa-2x opacity-50"></i>
                </div>
                <small class="mt-2 text-white"><?php echo $unpaid_count; ?> <?php echo $is_en ? 'Pending Staff' : 'غیر ادا شدہ افراد'; ?></small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm bg-info text-white p-3 rounded-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="fw-bold text-white"><?php echo $is_en ? 'Total Staff' : 'کل رجسٹرڈ اساتذہ'; ?></small>
                        <h4 class="fw-bold text-white mb-0 mt-1"><?php echo count($all_teachers); ?></h4>
                    </div>
                    <i class="fas fa-users fa-2x opacity-50"></i>
                </div>
                <small class="mt-2 text-white"><?php echo $is_en ? 'Active Employees' : 'موجودہ فعال اساتذہ'; ?></small>
            </div>
        </div>
    </div>

    <!-- Filter Bar Card -->
    <div class="card border-0 shadow-sm mb-3 bg-light p-3 rounded-3 d-print-none">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="fw-bold small mb-1"><i class="fas fa-user-tie me-1 text-primary"></i> <?php echo $is_en ? 'Filter by Staff/Teacher:' : 'استاد / ملازم منتخب کریں (فلٹر):'; ?></label>
                <select name="teacher_id" class="form-select form-select-sm fw-bold" onchange="this.form.submit()">
                    <option value="">-- <?php echo $is_en ? 'All Staff (Default)' : 'تمام اساتذہ و عملہ (ڈیفالٹ)'; ?> --</option>
                    <?php foreach($all_teachers as $tch): ?>
                        <option value="<?php echo $tch['id']; ?>" <?php echo $filter_teacher_id == $tch['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($tch['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-md-3">
                <label class="fw-bold small mb-1"><i class="fas fa-calendar-alt me-1 text-success"></i> <?php echo $is_en ? 'Filter by Month:' : 'مہینہ منتخب کریں:'; ?></label>
                <select name="month" class="form-select form-select-sm fw-bold" onchange="this.form.submit()">
                    <option value="">-- <?php echo $is_en ? 'All Months' : 'تمام مہینے'; ?> --</option>
                    <?php foreach($months_list as $m): 
                        $m_date = strtotime($m . '-01');
                        $m_eng = date('F', $m_date);
                        $y_eng = date('Y', $m_date);
                        $ur_months = ['January'=>'جنوری', 'February'=>'فروری', 'March'=>'مارچ', 'April'=>'اپریل', 'May'=>'مئی', 'June'=>'جون', 'July'=>'جولائی', 'August'=>'اگست', 'September'=>'ستمبر', 'October'=>'اکتوبر', 'November'=>'نومبر', 'December'=>'دسمبر'];
                        $m_name = $is_en ? $m_eng : ($ur_months[$m_eng] ?? $m_eng);
                    ?>
                        <option value="<?php echo $m; ?>" <?php echo $filter_month === $m ? 'selected' : ''; ?>>
                            <?php echo $m_name . " " . $y_eng . " ($m)"; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-md-3">
                <label class="fw-bold small mb-1"><i class="fas fa-filter me-1 text-info"></i> <?php echo $is_en ? 'Filter by Status:' : 'کیفیت (اسٹیٹس):'; ?></label>
                <select name="status" class="form-select form-select-sm fw-bold" onchange="this.form.submit()">
                    <option value="">-- <?php echo $is_en ? 'All Statuses' : 'تمام اسٹیٹس'; ?> --</option>
                    <option value="Paid" <?php echo $filter_status === 'Paid' ? 'selected' : ''; ?>><?php echo $is_en ? 'Paid Only' : 'صرف ادا شدہ (Paid)'; ?></option>
                    <option value="Unpaid" <?php echo $filter_status === 'Unpaid' ? 'selected' : ''; ?>><?php echo $is_en ? 'Unpaid Only' : 'صرف واجب الادا (Unpaid)'; ?></option>
                </select>
            </div>

            <div class="col-12 col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold"><i class="fas fa-search me-1"></i> <?php echo $is_en ? 'Filter' : 'فلٹر کریں'; ?></button>
                <a href="staff_stipends.php" class="btn btn-outline-secondary btn-sm w-50 fw-bold"><i class="fas fa-redo"></i></a>
            </div>
        </form>
    </div>

    <!-- Main Table Card -->
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 text-center" style="font-size: 13px;">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th class="text-start"><?php echo $is_en ? 'Staff Name & Designation' : 'نام استاد / ملازم مع عہدہ'; ?></th>
                            <th><?php echo $is_en ? 'Month' : 'ماہ و سال'; ?></th>
                            <th><?php echo $is_en ? 'Basic' : 'بنیادی وظیفہ'; ?></th>
                            <th><?php echo $is_en ? 'Allowances' : 'الاؤنسز'; ?></th>
                            <th><?php echo $is_en ? 'Deductions' : 'کٹوتیاں'; ?></th>
                            <th class="bg-primary text-white"><?php echo $is_en ? 'Net Stipend' : 'خالص وظیفہ / رقم'; ?></th>
                            <th><?php echo $is_en ? 'Status' : 'کیفیت'; ?></th>
                            <th><?php echo $is_en ? 'Payment Date' : 'تاریخ ادائیگی'; ?></th>
                            <th class="d-print-none"><?php echo $is_en ? 'Actions' : 'اختیارات'; ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($payroll_records)): ?>
                            <tr>
                                <td colspan="10" class="py-4 text-muted fw-bold">
                                    <i class="fas fa-info-circle fa-2x d-block mb-2 text-warning"></i>
                                    <?php echo $is_en ? 'No stipend records found matching your filter.' : 'منتخب کردہ فلٹر کے مطابق کوئی وظیفہ ریکارڈ موجود نہیں ہے۔ پے رول جنریٹ کرنے کے لیے "نیا پے رول بنائیں" پر کلک کریں۔'; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($payroll_records as $idx => $r): ?>
                                <tr>
                                    <td><?php echo $idx + 1; ?></td>
                                    <td class="text-start fw-bold">
                                        <i class="fas fa-user text-muted me-1"></i> <?php echo htmlspecialchars($r['teacher_name']); ?>

                                    </td>
                                    <td>
                                        <span class="badge bg-secondary fw-bold"><?php echo $r['month_year']; ?></span>
                                    </td>
                                    <td>Rs. <?php echo number_format($r['basic_salary']); ?></td>
                                    <td class="text-success">+ Rs. <?php echo number_format($r['allowances']); ?></td>
                                    <td class="text-danger">- Rs. <?php echo number_format($r['deductions']); ?></td>
                                    <td class="fw-bold fs-6 text-primary">Rs. <?php echo number_format($r['net_salary']); ?></td>
                                    <td>
                                        <?php if($r['status'] === 'Paid'): ?>
                                            <span class="badge bg-success px-2 py-1"><i class="fas fa-check-circle me-1"></i> <?php echo $is_en ? 'Paid' : 'ادا شدہ'; ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-danger px-2 py-1"><i class="fas fa-clock me-1"></i> <?php echo $is_en ? 'Unpaid' : 'واجب الادا'; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $r['payment_date'] ? date('d-M-Y', strtotime($r['payment_date'])) : '-'; ?></td>
                                    <td class="d-print-none">
                                        <div class="d-flex justify-content-center gap-1">
                                            <?php if($r['status'] === 'Unpaid'): ?>
                                                <button class="btn btn-sm btn-success fw-bold px-2" data-bs-toggle="modal" data-bs-target="#payModal<?php echo $r['id']; ?>" title="<?php echo $is_en ? 'Pay Stipend' : 'وظیفہ ادا کریں'; ?>">
                                                    <i class="fas fa-money-bill-wave"></i> <?php echo $is_en ? 'Pay' : 'ادائیگی'; ?>
                                                </button>
                                                <button class="btn btn-sm btn-outline-primary px-2" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $r['id']; ?>" title="<?php echo $is_en ? 'Edit Allowances/Deductions' : 'ترمیم الاؤنس/کٹوتی'; ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <form method="POST" onsubmit="return confirm('<?php echo $is_en ? 'Are you sure you want to delete this unpaid entry?' : 'کیا آپ واقعی اس غیر ادا شدہ اندراج کو حذف کرنا چاہتے ہیں؟'; ?>');" style="display:inline;">
                                                    <input type="hidden" name="payroll_id" value="<?php echo $r['id']; ?>">
                                                    <button type="submit" name="delete_payroll" class="btn btn-sm btn-outline-danger px-2" title="<?php echo $is_en ? 'Delete Entry' : 'حذف کریں'; ?>">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" onsubmit="return confirm('<?php echo $is_en ? 'Mark as unpaid?' : 'کیا آپ اس وظیفہ کو دوبارہ غیر ادا شدہ کرنا چاہتے ہیں؟'; ?>');" style="display:inline;">
                                                    <input type="hidden" name="payroll_id" value="<?php echo $r['id']; ?>">
                                                    <button type="submit" name="mark_unpaid" class="btn btn-sm btn-outline-warning text-dark px-2 fw-bold" title="<?php echo $is_en ? 'Mark Unpaid' : 'غیر ادا شدہ کریں'; ?>">
                                                        <i class="fas fa-undo me-1"></i> <?php echo $is_en ? 'Unmark' : 'منسوخ ادائیگی'; ?>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <!-- Print Slip Button -->
                                            <button class="btn btn-sm btn-outline-dark px-2" onclick="printPaySlip(<?php echo htmlspecialchars(json_encode($r)); ?>)" title="<?php echo $is_en ? 'Print Pay Slip' : 'رسید / سلپ'; ?>">
                                                <i class="fas fa-file-invoice"></i>
                                            </button>
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
</div>

<!-- Generate Payroll Modal -->
<div class="modal fade" id="generateModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-calculator me-2"></i> <?php echo $is_en ? 'Generate Monthly Payroll' : 'ماہانہ پے رول جنریشن'; ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-start">
                    <label class="form-label fw-bold"><?php echo $is_en ? 'Select Month & Year:' : 'مہینہ اور سال منتخب کریں:'; ?></label>
                    <input type="month" name="month_year" id="genMonthInput" class="form-control form-control-lg fw-bold mb-3" value="<?php echo date('Y-m'); ?>" required>

                    <div class="d-flex gap-2 mb-3">
                        <button type="button" class="btn btn-outline-secondary btn-sm flex-fill fw-bold" onclick="setGenMonth(-1)"><i class="fas fa-arrow-right me-1"></i> <?php echo $is_en ? 'Previous Month' : 'پچھلا مہینہ'; ?></button>
                        <button type="button" class="btn btn-outline-primary btn-sm flex-fill fw-bold" onclick="setGenMonth(0)"><?php echo $is_en ? 'Current Month' : 'رواں مہینہ'; ?></button>
                        <button type="button" class="btn btn-outline-secondary btn-sm flex-fill fw-bold" onclick="setGenMonth(1)"><?php echo $is_en ? 'Next Month' : 'اگلا مہینہ'; ?> <i class="fas fa-arrow-left ms-1"></i></button>
                    </div>

                    <div class="alert alert-info small mb-0">
                        <i class="fas fa-info-circle me-1"></i> <?php echo $is_en ? 'Payroll will be generated for all active staff for the chosen month based on their basic salary, allowances and attendance deductions.' : 'منتخب کردہ مہینے کے لیے تمام فعال اساتذہ کا پے رول بنیادی تنخواہ، الاؤنسز اور غیر حاضری کی کٹوتیوں کا حساب لگا کر خودکار تیار ہو جائے گا۔'; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary fw-bold" data-bs-dismiss="modal"><?php echo $is_en ? 'Cancel' : 'منسوخ'; ?></button>
                    <button type="submit" name="generate_payroll" class="btn btn-success fw-bold"><i class="fas fa-cog me-1"></i> <?php echo $is_en ? 'Generate Now' : 'پے رول بنائیں'; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>


<?php foreach($payroll_records as $r): ?>
    <?php if($r['status'] === 'Unpaid'): ?>
        <?php 
            $t_id = $r['teacher_id'];
            $max_loan = 0;
            $def_loan = 0;
            if(isset($active_loans[$t_id])) {
                $max_loan = $active_loans[$t_id]['rem'];
                $def_loan = $active_loans[$t_id]['inst'];
                if ($def_loan > $max_loan) $def_loan = $max_loan;
            }
        ?>
        <!-- Combined Pay & Edit Modal -->
        <div class="modal fade" id="payModal<?php echo $r['id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <form method="POST">
                        <input type="hidden" name="payroll_id" value="<?php echo $r['id']; ?>">
                        <div class="modal-header bg-success text-white">
                            <h5 class="modal-title fw-bold"><i class="fas fa-money-bill-wave me-2"></i> <?php echo $is_en ? 'Confirm Payment & Adjustments' : 'ادائیگی کی تفصیل اور کٹوتیاں'; ?></h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body text-start">
                            <p class="mb-2"><strong><?php echo $is_en ? 'Staff:' : 'استاد:'; ?></strong> <?php echo htmlspecialchars($r['teacher_name']); ?> (<?php echo $r['month_year']; ?>)</p>
                            
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label small fw-bold text-muted"><?php echo $is_en ? 'Basic Salary:' : 'بنیادی وظیفہ:'; ?></label>
                                    <input type="number" id="basic_<?php echo $r['id']; ?>" class="form-control form-control-sm bg-light" value="<?php echo $r['basic_salary']; ?>" readonly>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-bold text-muted"><?php echo $is_en ? 'Payment Date:' : 'تاریخ ادائیگی:'; ?></label>
                                    <input type="date" name="payment_date" class="form-control form-control-sm" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                            
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label small fw-bold text-success"><?php echo $is_en ? 'Allowances:' : 'الاؤنسز (+):'; ?></label>
                                    <input type="number" step="0.01" name="allowances" id="allow_<?php echo $r['id']; ?>" class="form-control form-control-sm text-success fw-bold" value="<?php echo $r['allowances']; ?>" oninput="calcNet(<?php echo $r['id']; ?>)" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-bold text-danger"><?php echo $is_en ? 'Deductions:' : 'دیگر کٹوتیاں (-):'; ?></label>
                                    <input type="number" step="0.01" name="deductions" id="deduc_<?php echo $r['id']; ?>" class="form-control form-control-sm text-danger fw-bold" value="<?php echo $r['deductions']; ?>" oninput="calcNet(<?php echo $r['id']; ?>)" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-danger"><?php echo $is_en ? 'Loan Installment Deduction:' : 'قرض کی قسط کٹوتی (-):'; ?></label>
                                <?php if($max_loan > 0): ?>
                                    <input type="number" step="0.01" max="<?php echo $max_loan; ?>" name="loan_deduction" id="loan_<?php echo $r['id']; ?>" class="form-control form-control-sm text-danger fw-bold" value="<?php echo $def_loan; ?>" oninput="calcNet(<?php echo $r['id']; ?>)">
                                    <small class="text-muted d-block mt-1"><?php echo $is_en ? "Max remaining loan: Rs. $max_loan" : "بقایا قرض: Rs. $max_loan"; ?></small>
                                <?php else: ?>
                                    <input type="number" step="0.01" name="loan_deduction" id="loan_<?php echo $r['id']; ?>" class="form-control form-control-sm bg-light" value="0" readonly>
                                    <small class="text-muted d-block mt-1"><?php echo $is_en ? 'No active loan' : 'اس وقت کوئی قرض نہیں ہے'; ?></small>
                                <?php endif; ?>
                            </div>

                            <div class="border-top pt-2">
                                <h5 class="mb-0 fw-bold"><?php echo $is_en ? 'Net Payable:' : 'خالص ادائیگی:'; ?> <span class="text-primary float-end">Rs. <span id="net_<?php echo $r['id']; ?>"><?php echo number_format($r['basic_salary'] + $r['allowances'] - $r['deductions'] - $def_loan); ?></span></span></h5>
                            </div>
                            
                            <div class="alert alert-info py-2 small mb-0 mt-3">
                                <i class="fas fa-info-circle me-1"></i> <?php echo $is_en ? 'Payment posts to Finance. Loan deductions update Staff Loans automatically.' : 'ادائیگی فنانس میں ریکارڈ ہو جائے گی، اور قرض کی کٹوتی بھی قرض کھاتے میں خودکار درج ہو گی۔'; ?>
                            </div>
                        </div>
                        <div class="modal-footer bg-light">
                            <button type="button" class="btn btn-secondary fw-bold btn-sm px-3" data-bs-dismiss="modal"><?php echo $is_en ? 'Cancel' : 'منسوخ'; ?></button>
                            <button type="submit" name="mark_paid" class="btn btn-success fw-bold btn-sm px-3"><i class="fas fa-check me-1"></i> <?php echo $is_en ? 'Confirm Payment' : 'ادائیگی محفوظ کریں'; ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Keep Edit Modal for manual changes without paying -->
        <div class="modal fade" id="editModal<?php echo $r['id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST">
                        <input type="hidden" name="payroll_id" value="<?php echo $r['id']; ?>">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i> <?php echo $is_en ? 'Edit Allowances & Deductions' : 'الاؤنس اور کٹوتی کی ترمیم'; ?></h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body text-start">
                            <p class="mb-1"><strong><?php echo htmlspecialchars($r['teacher_name']); ?></strong> (<?php echo $r['month_year']; ?>)</p>
                            <div class="mb-3">
                                <label class="form-label fw-bold"><?php echo $is_en ? 'Allowances (Rs.):' : 'الاؤنسز رقم (اضافہ):'; ?></label>
                                <input type="number" step="0.01" name="allowances" class="form-control fw-bold text-success" value="<?php echo $r['allowances']; ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold"><?php echo $is_en ? 'Deductions (Rs.):' : 'کٹوتی رقم (منفی):'; ?></label>
                                <input type="number" step="0.01" name="deductions" class="form-control fw-bold text-danger" value="<?php echo $r['deductions']; ?>" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary fw-bold" data-bs-dismiss="modal"><?php echo $is_en ? 'Cancel' : 'منسوخ'; ?></button>
                            <button type="submit" name="update_payroll" class="btn btn-primary fw-bold"><i class="fas fa-save me-1"></i> <?php echo $is_en ? 'Save Changes' : 'محفوظ کریں'; ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<script>
function calcNet(id) {
    let basic = parseFloat(document.getElementById('basic_'+id).value) || 0;
    let allow = parseFloat(document.getElementById('allow_'+id).value) || 0;
    let deduc = parseFloat(document.getElementById('deduc_'+id).value) || 0;
    let loan = parseFloat(document.getElementById('loan_'+id).value) || 0;
    let net = basic + allow - deduc - loan;
    if(net < 0) net = 0;
    document.getElementById('net_'+id).innerText = net.toLocaleString('en-US', {maximumFractionDigits:2});
}
</script>

<script>
function setGenMonth(offset) {
    const d = new Date();
    d.setMonth(d.getMonth() + offset);
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    document.getElementById('genMonthInput').value = `${yyyy}-${mm}`;
}

function printPaySlip(item) {
    const printWin = window.open('', '', 'width=600,height=700');
    printWin.document.write(`
        <!DOCTYPE html>
        <html dir="rtl">
        <head>
            <title>تنخواہ / وظیفہ رسید</title>
            <style>
                body { font-family: 'Segoe UI', Tahoma, Arial; padding: 20px; color: #333; }
                .slip-box { border: 2px solid #333; padding: 20px; border-radius: 8px; max-width: 500px; margin: 0 auto; }
                .header { text-align: center; border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 15px; }
                .title { font-size: 20px; font-weight: bold; margin: 0; }
                .table-slip { width: 100%; border-collapse: collapse; margin-top: 15px; }
                .table-slip td { padding: 8px; border-bottom: 1px solid #ddd; }
                .total-row { font-weight: bold; font-size: 16px; background-color: #f0f0f0; }
                .footer { margin-top: 30px; display: flex; justify-content: space-between; }
            </style>
        </head>
        <body>
            <div class="slip-box">
                <div class="header">
                    <h2 class="title">مدرسہ اشرف المدارس</h2>
                    <p style="margin: 3px 0; font-weight: bold;">تنخواہ / وظیفہ کی رسید (Salary Slip)</p>
                    <small>ماہ: ${item.month_year}</small>
                </div>
                <table class="table-slip">
                    <tr><td><strong>استاد / ملازم:</strong></td><td>${item.teacher_name}</td></tr>
                    <tr><td><strong>عہدہ:</strong></td><td>${item.teacher_desig || '-'}</td></tr>
                    <tr><td><strong>بنیادی وظیفہ:</strong></td><td>Rs. ${parseFloat(item.basic_salary).toLocaleString()}</td></tr>
                    <tr><td><strong>الاؤنسز:</strong></td><td>+ Rs. ${parseFloat(item.allowances).toLocaleString()}</td></tr>
                    <tr><td><strong>کٹوتیاں:</strong></td><td>- Rs. ${parseFloat(item.deductions).toLocaleString()}</td></tr>
                    <tr class="total-row"><td><strong>خالص ادا شدہ رقم:</strong></td><td>Rs. ${parseFloat(item.net_salary).toLocaleString()}</td></tr>
                    <tr><td><strong>کیفیت (اسٹیٹس):</strong></td><td>${item.status === 'Paid' ? 'ادا شدہ (PAID)' : 'واجب الادا (UNPAID)'}</td></tr>
                    <tr><td><strong>تاریخ ادائیگی:</strong></td><td>${item.payment_date || '-'}</td></tr>
                </table>
                <div class="footer">
                    <div>دستخط وصول کنندہ: ____________</div>
                    <div>دستخط ناظم / مہتمم: ____________</div>
                </div>
            </div>
            <script>window.onload = function() { window.print(); };<\/script>
        </body>
        </html>
    `);
    printWin.document.close();
}
</script>

<?php include 'footer.php'; ?>
