<?php
require_once 'db.php';
require_once __DIR__ . '/finance_accounting_bridge.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$u_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin = in_array($u_role, ['admin', 'superadmin', 'super_admin', 'administrator'], true);
if (!$is_admin && $u_role !== 'nazim' && !app_has_perm('manage_erp') && !app_has_perm('manage_stipends') && !app_has_perm('manage_masjid_stipends')) {
    header("Location: dashboard.php");
    exit;
}
$role = $_SESSION['role'] ?? '';
$user = ['id' => $_SESSION['user_id'], 'role' => $role];
$system_type = $_SESSION['system_type'] ?? 'Madrasa';
$sys = $system_type;

$cur_tenant_id = get_current_tenant_id($pdo);

$is_masjid = ($sys === 'Masjid');
$sys_cond_teachers = $is_masjid 
    ? "system_type = 'Masjid'" 
    : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
$sys_cond_p = $is_masjid 
    ? "p.system_type = 'Masjid'" 
    : "(p.system_type = 'Madrasa' OR p.system_type IS NULL OR p.system_type = '')";
$sys_cond_t = $is_masjid 
    ? "t.system_type = 'Masjid'" 
    : "(t.system_type = 'Madrasa' OR t.system_type IS NULL OR t.system_type = '')";

// Create table if not exists (cached with flag)
if (!file_exists(__DIR__ . '/.payroll_schema_done')) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS acc_payroll (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL DEFAULT 1,
            teacher_id INT NOT NULL,
            month_year VARCHAR(20),
            basic_salary DECIMAL(15,2) DEFAULT 0,
            allowances DECIMAL(15,2) DEFAULT 0,
            deductions DECIMAL(15,2) DEFAULT 0,
            net_salary DECIMAL(15,2) DEFAULT 0,
            status ENUM('Unpaid', 'Paid', 'Cancelled') DEFAULT 'Unpaid',
            payment_date DATE DEFAULT NULL,
            system_type VARCHAR(50) DEFAULT 'Madrasa',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_by INT,
            is_deleted TINYINT(1) DEFAULT 0,
            INDEX (tenant_id)
        )");

        $pdo->exec("ALTER TABLE finance ADD COLUMN head_name VARCHAR(255) DEFAULT NULL");
        $pdo->exec("ALTER TABLE finance ADD COLUMN spender_name VARCHAR(255) DEFAULT NULL");
        $pdo->exec("ALTER TABLE finance ADD COLUMN account_id INT DEFAULT NULL");
        $pdo->exec("ALTER TABLE finance ADD COLUMN payment_method VARCHAR(50) DEFAULT 'Cash'");
        $pdo->exec("ALTER TABLE finance ADD COLUMN system_type VARCHAR(50) DEFAULT 'Madrasa'");

        // Ensure system_type consistency between acc_payroll and teachers
        $pdo->exec("UPDATE acc_payroll p JOIN teachers t ON p.teacher_id = t.id SET p.system_type = 'Madrasa' WHERE (t.system_type = 'Madrasa' OR t.system_type IS NULL OR t.system_type = '') AND (p.system_type != 'Madrasa' OR p.system_type IS NULL OR p.system_type = '')");
        $pdo->exec("UPDATE acc_payroll p JOIN teachers t ON p.teacher_id = t.id SET p.system_type = 'Masjid' WHERE t.system_type = 'Masjid' AND (p.system_type != 'Masjid' OR p.system_type IS NULL OR p.system_type = '')");
        @touch(__DIR__ . '/.payroll_schema_done');
    } catch (Exception $e) {}
}

// Auto-reconcile paid payrolls into finance expenses
try {
    $paid_stmt = $pdo->prepare("SELECT p.*, t.name as teacher_name FROM acc_payroll p LEFT JOIN teachers t ON p.teacher_id = t.id WHERE p.status = 'Paid' AND (p.is_deleted = 0 OR p.is_deleted IS NULL) AND p.tenant_id = ?");
    $paid_stmt->execute([$cur_tenant_id]);
    $paid_payrolls = $paid_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($paid_payrolls as $pp) {
        $t_name = $pp['teacher_name'] ?? 'Staff';
        $m_year = $pp['month_year'] ?? '';
        $net_amt = (float)($pp['net_salary'] ?? ($pp['basic_salary'] + $pp['allowances'] - $pp['deductions']));
        if ($net_amt <= 0 || empty($m_year)) continue;
        $pay_date = !empty($pp['payment_date']) ? $pp['payment_date'] : date('Y-m-d');
        $pay_method = !empty($pp['payment_method']) ? $pp['payment_method'] : 'Cash';
        $pay_account_id = !empty($pp['payment_account_id']) ? (int)$pp['payment_account_id'] : null;
        $sys_item = $pp['system_type'] ?? 'Madrasa';
        
        $chk = $pdo->prepare("SELECT id FROM finance WHERE type = 'Expense' AND description LIKE ? AND (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = ? LIMIT 1");
        $chk->execute(["%ماہ $m_year: $t_name%", $cur_tenant_id]);
        if (!$chk->fetchColumn()) {
            $desc = "مد: وظائف عملہ | تفصیل: وظیفہ/تنخواہ ادا کی گئی بذریعہ $pay_method برائے ماہ $m_year: $t_name";
            $pdo->prepare("INSERT INTO finance (user_id, user_name, type, amount, head_id, head_name, date, donor_name, spender_name, description, system_type, account_id, payment_method, is_deleted, tenant_id) VALUES (?, 'Admin', 'Expense', ?, 0, 'وظائف عملہ', ?, ?, ?, ?, ?, ?, ?, 0, ?)")
                ->execute([$pp['created_by'] ?? 1, $net_amt, $pay_date, $t_name, $t_name, $desc, $sys_item, $pay_account_id, $pay_method, $cur_tenant_id]);
        }
    }
} catch (Throwable $e) {}

// Handle Generation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate_payroll'])) {
    $month_year = $_POST['month_year'] ?? date('Y-m');
    $uid = $_SESSION['user_id'];
    $sys = $_SESSION['system_type'] ?? 'Madrasa';
    
    $status_filter = "status NOT IN ('Inactive', 'Resigned', 'Expelled', 'Terminated', 'مستعفی', 'استعفی', 'علیحدہ', 'خارج', 'اخراج', 'برطرف', 'غیر فعال', 'معطل') 
        AND status NOT LIKE '%مستعفی%' 
        AND status NOT LIKE '%استعفی%' 
        AND status NOT LIKE '%علیحدہ%' 
        AND status NOT LIKE '%خارج%' 
        AND status NOT LIKE '%برطرف%' 
        AND status NOT LIKE '%غیر فعال%' 
        AND status NOT LIKE '%معطل%' 
        AND status NOT LIKE '%چھوڑ%'";

    $t_stmt = $pdo->prepare("SELECT id, name, salary, house_rent, medical_allowance, travel_allowance, daily_deduction_rate FROM teachers WHERE (status = 'Active' OR status = 'حاضر' OR status = 'فعال' OR status IS NULL OR status = '') AND $status_filter AND (is_deleted = 0 OR is_deleted IS NULL) AND $sys_cond_teachers AND tenant_id = ?");
    $t_stmt->execute([$cur_tenant_id]);
    $teachers = $t_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($teachers)) {
        $msg = ($sys === 'Masjid') ? "مسجد موڈ کے تحت پے رول جنریشن کے لیے کوئی فعال استاد یا عملہ موجود نہیں ہے۔" : "مدرسہ موڈ کے تحت پے رول جنریشن کے لیے کوئی فعال استاد یا عملہ موجود نہیں ہے۔";
    } else {
        $created_count = 0;
        foreach ($teachers as $t) {
            $check = $pdo->prepare("SELECT id FROM acc_payroll WHERE teacher_id = ? AND month_year = ? AND (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = ?");
            $check->execute([$t['id'], $month_year, $cur_tenant_id]);
            if ($check->rowCount() == 0) {
                $salary = (float)($t['salary'] ?? 0);
                $allowances = (float)($t['house_rent'] ?? 0) + (float)($t['medical_allowance'] ?? 0) + (float)($t['travel_allowance'] ?? 0);
                
                // Calculate Absences Deduction
                $absences_stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance_teachers WHERE teacher_id = ? AND DATE_FORMAT(date, '%Y-%m') = ? AND status = 'A' AND tenant_id = ?");
                $absences_stmt->execute([$t['id'], $month_year, $cur_tenant_id]);
                $absent_days = $absences_stmt->fetchColumn();
                
                $deduction_rate = (float)($t['daily_deduction_rate'] ?? 0);
                if ($deduction_rate == 0 && $salary > 0) {
                    $deduction_rate = round($salary / 30); // fallback deduction
                }
                $deductions = $absent_days * $deduction_rate;
                $net = max(0, $salary + $allowances - $deductions);

                $stmt = $pdo->prepare("INSERT INTO acc_payroll (teacher_id, month_year, basic_salary, allowances, deductions, net_salary, status, system_type, created_by, tenant_id) VALUES (?, ?, ?, ?, ?, ?, 'Unpaid', ?, ?, ?)");
                $stmt->execute([$t['id'], $month_year, $salary, $allowances, $deductions, $net, $sys, $uid, $cur_tenant_id]);
                payroll_accounting_post($pdo, (int)$pdo->lastInsertId());
                $created_count++;
            }
        }
        $msg = "ماہ " . format_urdu_month_year($month_year) . " کے لیے $created_count اساتذہ/عملہ کے وظائف کا پے رول کامیابی سے تیار ہو گیا!";
    }
}

// Handle Update Allowances/Deductions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_payroll'])) {
    $pid = (int)$_POST['payroll_id'];
    $allow = (float)$_POST['allowances'];
    $deduct = (float)$_POST['deductions'];
    $pdo->prepare("UPDATE acc_payroll SET allowances = ?, deductions = ? WHERE id = ? AND tenant_id = ? AND status = 'Unpaid'")->execute([$allow, $deduct, $pid, $cur_tenant_id]);
    payroll_accounting_post($pdo, $pid);
    header("Location: acc_payroll.php");
    exit;
}

// Handle Delete Entry
if (isset($_GET['delete_id']) && is_numeric($_GET['delete_id'])) {
    $del_id = (int)$_GET['delete_id'];
    
    // Soft-delete corresponding finance expense
    try {
        $p_stmt = $pdo->prepare("SELECT p.*, t.name as teacher_name FROM acc_payroll p LEFT JOIN teachers t ON p.teacher_id = t.id WHERE p.id = ? AND p.tenant_id = ?");
        $p_stmt->execute([$del_id, $cur_tenant_id]);
        $p_row = $p_stmt->fetch(PDO::FETCH_ASSOC);
        if ($p_row) {
            $t_name = $p_row['teacher_name'] ?? '';
            $m_year = $p_row['month_year'] ?? '';
            if (!empty($t_name) && !empty($m_year)) {
                $pdo->prepare("UPDATE finance SET is_deleted = 1 WHERE type = 'Expense' AND description LIKE ? AND tenant_id = ?")
                    ->execute(["%ماہ $m_year: $t_name%", $cur_tenant_id]);
            }
        }
    } catch (Exception $e) {}

    $pdo->prepare("UPDATE acc_payroll SET is_deleted = 1 WHERE id = ? AND tenant_id = ?")->execute([$del_id, $cur_tenant_id]);
    $redir_params = [];
    if (!empty($_GET['month'])) $redir_params['month'] = $_GET['month'];
    if (!empty($_GET['teacher_id'])) $redir_params['teacher_id'] = $_GET['teacher_id'];
    $qs = !empty($redir_params) ? '?' . http_build_query($redir_params) : '';
    header("Location: acc_payroll.php" . $qs);
    exit;
}

// Update Status: Mark Paid via POST (with payment account selection)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_pay_payroll'])) {
    $pid = (int)($_POST['payroll_id'] ?? 0);
    $pay_date = !empty($_POST['payment_date']) ? $_POST['payment_date'] : date('Y-m-d');
    $pay_account_id = (int)($_POST['payment_account_id'] ?? 0);
    $pay_method = $_POST['payment_method'] ?? 'Cash';
    if ($pay_account_id <= 0) {
        $pay_account_id = finance_accounting_ensure_account($pdo, '1001', 'Cash in Hand', 'Asset');
    }

    $pdo->prepare("UPDATE acc_payroll SET status = 'Paid', payment_date = ?, payment_account_id = ?, payment_method = ? WHERE id = ? AND tenant_id = ?")
        ->execute([$pay_date, $pay_account_id, $pay_method, $pid, $cur_tenant_id]);
    payroll_accounting_post($pdo, $pid);

    // Auto-record into finance expense table with payment account
    try {
        $p_stmt = $pdo->prepare("SELECT p.*, t.name as teacher_name FROM acc_payroll p LEFT JOIN teachers t ON p.teacher_id = t.id WHERE p.id = ? AND p.tenant_id = ?");
        $p_stmt->execute([$pid, $cur_tenant_id]);
        $p_row = $p_stmt->fetch(PDO::FETCH_ASSOC);
        if ($p_row) {
            $t_name = $p_row['teacher_name'] ?? 'Staff';
            $m_year = $p_row['month_year'] ?? '';
            $net_amt = (float)($p_row['net_salary'] ?? ($p_row['basic_salary'] + $p_row['allowances'] - $p_row['deductions']));
            $sys = $p_row['system_type'] ?? ($_SESSION['system_type'] ?? 'Madrasa');
            $desc = "مد: وظائف عملہ | تفصیل: وظیفہ/تنخواہ ادا کی گئی بذریعہ $pay_method برائے ماہ $m_year: $t_name";
            
            // Check if already in finance
            $chk = $pdo->prepare("SELECT id FROM finance WHERE type = 'Expense' AND description LIKE ? AND (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = ? LIMIT 1");
            $chk->execute(["%ماہ $m_year: $t_name%", $cur_tenant_id]);
            $existing_fid = $chk->fetchColumn();
            if ($existing_fid) {
                $pdo->prepare("UPDATE finance SET amount = ?, date = ?, account_id = ?, payment_method = ?, is_deleted = 0 WHERE id = ? AND tenant_id = ?")
                    ->execute([$net_amt, $pay_date, $pay_account_id, $pay_method, $existing_fid, $cur_tenant_id]);
            } else {
                $pdo->prepare("INSERT INTO finance (user_id, user_name, type, amount, head_id, head_name, date, donor_name, spender_name, description, system_type, account_id, payment_method, is_deleted, tenant_id) VALUES (?, ?, 'Expense', ?, 0, 'وظائف عملہ', ?, ?, ?, ?, ?, ?, ?, 0, ?)")
                    ->execute([$_SESSION['user_id'] ?? 1, $_SESSION['username'] ?? 'Admin', $net_amt, $pay_date, $t_name, $t_name, $desc, $sys, $pay_account_id, $pay_method, $cur_tenant_id]);
            }
        }
    } catch (Exception $e) {}

    $redir_params = [];
    if (!empty($_POST['month'])) $redir_params['month'] = $_POST['month'];
    if (!empty($_POST['teacher_id'])) $redir_params['teacher_id'] = $_POST['teacher_id'];
    $qs = !empty($redir_params) ? '?' . http_build_query($redir_params) : '';
    header("Location: acc_payroll.php" . $qs);
    exit;
}

// Update Status: Mark Paid via GET (Fallback)
if (isset($_GET['mark_paid']) && is_numeric($_GET['mark_paid'])) {
    $pid = (int)$_GET['mark_paid'];
    $cashId = finance_accounting_ensure_account($pdo, '1001', 'Cash in Hand', 'Asset');
    $pdo->prepare("UPDATE acc_payroll SET status = 'Paid', payment_date = CURRENT_DATE, payment_account_id = ?, payment_method = 'Cash' WHERE id = ? AND tenant_id = ?")->execute([$cashId, $pid, $cur_tenant_id]);
    payroll_accounting_post($pdo, $pid);

    // Auto-record into finance expense table
    try {
        $p_stmt = $pdo->prepare("SELECT p.*, t.name as teacher_name FROM acc_payroll p LEFT JOIN teachers t ON p.teacher_id = t.id WHERE p.id = ? AND p.tenant_id = ?");
        $p_stmt->execute([$pid, $cur_tenant_id]);
        $p_row = $p_stmt->fetch(PDO::FETCH_ASSOC);
        if ($p_row) {
            $t_name = $p_row['teacher_name'] ?? 'Staff';
            $m_year = $p_row['month_year'] ?? '';
            $net_amt = (float)($p_row['net_salary'] ?? ($p_row['basic_salary'] + $p_row['allowances'] - $p_row['deductions']));
            $sys = $p_row['system_type'] ?? ($_SESSION['system_type'] ?? 'Madrasa');
            $today = date('Y-m-d');
            $desc = "مد: وظائف عملہ | تفصیل: وظیفہ/تنخواہ ادا کی گئی برائے ماہ $m_year: $t_name";
            
            $chk = $pdo->prepare("SELECT id FROM finance WHERE type = 'Expense' AND description LIKE ? AND (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = ? LIMIT 1");
            $chk->execute(["%ماہ $m_year: $t_name%", $cur_tenant_id]);
            $existing_fid = $chk->fetchColumn();
            if ($existing_fid) {
                $pdo->prepare("UPDATE finance SET amount = ?, date = ?, account_id = ?, payment_method = 'Cash', is_deleted = 0 WHERE id = ? AND tenant_id = ?")
                    ->execute([$net_amt, $today, $cashId, $existing_fid, $cur_tenant_id]);
            } else {
                $pdo->prepare("INSERT INTO finance (user_id, user_name, type, amount, head_id, head_name, date, donor_name, spender_name, description, system_type, account_id, payment_method, is_deleted, tenant_id) VALUES (?, ?, 'Expense', ?, 0, 'وظائف عملہ', ?, ?, ?, ?, ?, ?, 'Cash', 0, ?)")
                    ->execute([$_SESSION['user_id'] ?? 1, $_SESSION['username'] ?? 'Admin', $net_amt, $today, $t_name, $t_name, $desc, $sys, $cashId, $cur_tenant_id]);
            }
        }
    } catch (Exception $e) {}

    $redir_params = [];
    if (!empty($_GET['month'])) $redir_params['month'] = $_GET['month'];
    if (!empty($_GET['teacher_id'])) $redir_params['teacher_id'] = $_GET['teacher_id'];
    $qs = !empty($redir_params) ? '?' . http_build_query($redir_params) : '';
    header("Location: acc_payroll.php" . $qs);
    exit;
}

// Update Status: Mark Unpaid
if (isset($_GET['mark_unpaid']) && is_numeric($_GET['mark_unpaid'])) {
    $pid = (int)$_GET['mark_unpaid'];
    
    // Soft-delete corresponding finance expense
    try {
        $p_stmt = $pdo->prepare("SELECT p.*, t.name as teacher_name FROM acc_payroll p LEFT JOIN teachers t ON p.teacher_id = t.id WHERE p.id = ? AND p.tenant_id = ?");
        $p_stmt->execute([$pid, $cur_tenant_id]);
        $p_row = $p_stmt->fetch(PDO::FETCH_ASSOC);
        if ($p_row) {
            $t_name = $p_row['teacher_name'] ?? '';
            $m_year = $p_row['month_year'] ?? '';
            if (!empty($t_name) && !empty($m_year)) {
                $pdo->prepare("UPDATE finance SET is_deleted = 1 WHERE type = 'Expense' AND description LIKE ? AND tenant_id = ?")
                    ->execute(["%ماہ $m_year: $t_name%", $cur_tenant_id]);
            }
        }
    } catch (Exception $e) {}

    $pdo->prepare("UPDATE acc_payroll SET status = 'Unpaid', payment_date = NULL WHERE id = ? AND tenant_id = ?")->execute([$pid, $cur_tenant_id]);
    payroll_accounting_post($pdo, $pid);
    $redir_params = [];
    if (!empty($_GET['month'])) $redir_params['month'] = $_GET['month'];
    if (!empty($_GET['teacher_id'])) $redir_params['teacher_id'] = $_GET['teacher_id'];
    $qs = !empty($redir_params) ? '?' . http_build_query($redir_params) : '';
    header("Location: acc_payroll.php" . $qs);
    exit;
}

// Fetch available months from database
$sys_cond_m = $is_masjid 
    ? "system_type = 'Masjid'" 
    : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
$m_stmt = $pdo->prepare("SELECT DISTINCT month_year FROM acc_payroll WHERE (is_deleted = 0 OR is_deleted IS NULL) AND $sys_cond_m AND tenant_id = ? AND month_year IS NOT NULL AND month_year != '' ORDER BY month_year DESC");
$m_stmt->execute([$cur_tenant_id]);
$available_months = $m_stmt->fetchAll(PDO::FETCH_COLUMN);
$current_m = date('Y-m');
if (!in_array($current_m, $available_months)) {
    array_unshift($available_months, $current_m);
}

$selected_month = $_GET['month'] ?? '';
$selected_teacher = $_GET['teacher_id'] ?? '';
$where_clause = "WHERE (t.is_deleted = 0 OR t.is_deleted IS NULL) AND (p.is_deleted = 0 OR p.is_deleted IS NULL) AND $sys_cond_p AND $sys_cond_t AND p.tenant_id = ? AND t.tenant_id = ?";
$params = [$cur_tenant_id, $cur_tenant_id];
if (!empty($selected_month)) {
    $where_clause .= " AND p.month_year = ?";
    $params[] = $selected_month;
}
if (!empty($selected_teacher)) {
    $where_clause .= " AND p.teacher_id = ?";
    $params[] = $selected_teacher;
}

$stmt = $pdo->prepare("
    SELECT p.*, t.name as teacher_name, a.name as payment_account_name 
    FROM acc_payroll p
    JOIN teachers t ON p.teacher_id = t.id
    LEFT JOIN acc_chart_of_accounts a ON p.payment_account_id = a.id
    $where_clause
    ORDER BY p.month_year DESC, p.id DESC
");
$stmt->execute($params);
$payrolls = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Available teachers for dropdown (Tenant scoped)
$tea_stmt = $pdo->prepare("SELECT id, name FROM teachers WHERE (is_deleted = 0 OR is_deleted IS NULL) AND $sys_cond_teachers AND tenant_id = ? ORDER BY name ASC");
$tea_stmt->execute([$cur_tenant_id]);
$available_teachers = $tea_stmt->fetchAll(PDO::FETCH_ASSOC);

// Payment accounts (Asset accounts scoped by tenant)
$payment_accounts = [];
try {
    $pacc_stmt = $pdo->prepare("SELECT id, code, name FROM acc_chart_of_accounts WHERE type = 'Asset' AND (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = ? ORDER BY code ASC");
    $pacc_stmt->execute([$cur_tenant_id]);
    $payment_accounts = $pacc_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
if (empty($payment_accounts)) {
    $payment_accounts = [
        ['id' => 1, 'code' => '1001', 'name' => 'Cash in Hand (نقد رقم)'],
        ['id' => 2, 'code' => '1002', 'name' => 'Bank Account (بینک کھاتہ)']
    ];
}

// Calculate summary stats
$total_net = 0;
$total_paid = 0;
$total_unpaid = 0;
$paid_count = 0;
$unpaid_count = 0;

foreach ($payrolls as $p) {
    $net = (float)$p['net_salary'];
    $total_net += $net;
    if ($p['status'] === 'Paid') {
        $total_paid += $net;
        $paid_count++;
    } else {
        $total_unpaid += $net;
        $unpaid_count++;
    }
}

$title = "وظائف عملہ - ERP";
include 'header.php';
?>

<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h3 class="fw-bold text-success mb-1"><i class="fas fa-money-check-alt me-2"></i> <?php echo ($lang=='ur') ? 'وظائف عملہ' : 'Staff Stipends'; ?></h3>
            <small class="text-muted fw-bold">ماہانہ وظائف جنریشن، جائزہ، پرنٹ اور ادائیگی کا انتظام</small>
        </div>
        <button class="btn btn-info fw-bold text-white shadow" data-bs-toggle="modal" data-bs-target="#generateModal">
            <i class="fas fa-magic me-1"></i> نیا پے رول بنائیں (Generate)
        </button>
    </div>

    <?php if(isset($msg)): ?>
    <div class="alert alert-success shadow-sm"><i class="fas fa-check-circle me-2"></i> <?php echo $msg; ?></div>
    <?php endif; ?>

    <!-- Month & Staff Filter & Quick Stats -->
    <div class="card shadow-sm border-0 mb-4 bg-light">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-12 col-md-4">
                    <label class="fw-bold small text-dark mb-1"><i class="fas fa-calendar-alt me-1 text-primary"></i> فلٹر برائے مہینہ (Select Month):</label>
                    <select name="month" class="form-select form-select-sm fw-bold" onchange="this.form.submit()">
                        <option value="">-- تمام مہینے (All Months) --</option>
                        <?php foreach($available_months as $m_opt): ?>
                            <option value="<?php echo htmlspecialchars($m_opt); ?>" <?php echo $selected_month === $m_opt ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(format_urdu_month_year($m_opt)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="fw-bold small text-dark mb-1"><i class="fas fa-user-tie me-1 text-success"></i> فلٹر برائے استاذ / عملہ (Select Staff):</label>
                    <select name="teacher_id" class="form-select form-select-sm fw-bold" onchange="this.form.submit()">
                        <option value="">-- تمام اساتذہ و عملہ (All Staff) --</option>
                        <?php foreach($available_teachers as $t_opt): ?>
                            <option value="<?php echo $t_opt['id']; ?>" <?php echo $selected_teacher == $t_opt['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t_opt['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4 text-md-end pt-2 pt-md-4">
                    <?php if(!empty($selected_month) || !empty($selected_teacher)): ?>
                        <a href="acc_payroll.php" class="btn btn-outline-danger btn-sm fw-bold"><i class="fas fa-times me-1"></i> تمام دکھائیں (Reset)</a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="row g-2 text-center mt-2 border-top pt-3">
                <div class="col-6 col-md-3">
                    <div class="p-2 border rounded bg-white shadow-sm">
                        <div class="small fw-bold text-muted">کل اسٹاف / اندراجات</div>
                        <div class="fw-bold fs-5 text-primary"><?php echo count($payrolls); ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="p-2 border rounded bg-white shadow-sm">
                        <div class="small fw-bold text-muted">کل واجب الادا رقم</div>
                        <div class="fw-bold fs-5 text-dark">Rs. <?php echo number_format($total_net, 0); ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="p-2 border rounded bg-white shadow-sm">
                        <div class="small fw-bold text-muted">ادا شدہ (Paid: <?php echo $paid_count; ?>)</div>
                        <div class="fw-bold fs-5 text-success">Rs. <?php echo number_format($total_paid, 0); ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="p-2 border rounded bg-white shadow-sm">
                        <div class="small fw-bold text-muted">غیر ادا شدہ (Unpaid: <?php echo $unpaid_count; ?>)</div>
                        <div class="fw-bold fs-5 text-danger">Rs. <?php echo number_format($total_unpaid, 0); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 neobox">
        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle table-sm text-center">
                    <thead class="table-dark">
                        <tr>
                            <th style="width: 20%;"><?php echo t('teacher'); ?></th>
                            <th style="width: 12%;"><?php echo t('month'); ?></th>
                            <th style="width: 14%;"><?php echo t('basic'); ?></th>
                            <th style="width: 14%;"><?php echo t('allow_deduct'); ?></th>
                            <th style="width: 15%;"><?php echo t('net_salary'); ?></th>
                            <th style="width: 10%;"><?php echo t('status'); ?></th>
                            <th style="width: 15%;"><?php echo t('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($payrolls as $p): ?>
                        <tr>
                            <td class="fw-bold text-primary text-start ps-3"><?php echo htmlspecialchars($p['teacher_name']); ?></td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars(format_urdu_month_year($p['month_year'])); ?></span></td>
                            <td>Rs. <?php echo number_format($p['basic_salary'], 0); ?></td>
                            <td class="small">
                                <span class="text-success fw-bold">+<?php echo number_format($p['allowances'], 0); ?></span> / 
                                <span class="text-danger fw-bold">-<?php echo number_format($p['deductions'], 0); ?></span>
                            </td>
                            <td class="fw-bold fs-6 text-dark">Rs. <?php echo number_format($p['net_salary'], 0); ?></td>
                            <td>
                                <?php if($p['status'] == 'Paid'): ?>
                                    <span class="badge bg-success"><i class="fas fa-check me-1"></i> <?php echo t('paid'); ?></span>
                                    <?php if(!empty($p['payment_date'])): ?>
                                        <br><small class="text-muted" style="font-size:10px;"><?php echo htmlspecialchars($p['payment_date']); ?></small>
                                    <?php endif; ?>
                                    <?php if(!empty($p['payment_account_name'])): ?>
                                        <br><span class="badge bg-light text-dark border" style="font-size:9px;"><i class="fas fa-wallet me-1 text-secondary"></i><?php echo htmlspecialchars($p['payment_account_name']); ?></span>
                                    <?php endif; ?>
                                <?php elseif($p['status'] == 'Cancelled'): ?>
                                    <span class="badge bg-danger"><?php echo t('cancelled'); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i> <?php echo t('unpaid'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <?php if($p['status'] == 'Unpaid'): ?>
                                        <button class="btn btn-primary" title="ترمیم کریں" onclick="editPayroll(<?php echo $p['id']; ?>, <?php echo $p['allowances']; ?>, <?php echo $p['deductions']; ?>)"><i class="fas fa-edit"></i></button>
                                        <button type="button" class="btn btn-success" title="ادائیگی درج کریں" onclick="openPayModal(<?php echo $p['id']; ?>, '<?php echo htmlspecialchars(addslashes($p['teacher_name'])); ?>', <?php echo (float)$p['net_salary']; ?>)"><i class="fas fa-money-check-alt"></i></button>
                                        <a href="?delete_id=<?php echo $p['id']; ?><?php echo !empty($selected_month)?'&month='.urlencode($selected_month):''; ?>" class="btn btn-outline-danger" title="حذف کریں" onclick="return confirm('کیا آپ اس اندراج کو حذف کرنا چاہتے ہیں؟')"><i class="fas fa-trash-alt"></i></a>
                                    <?php else: ?>
                                        <a href="?mark_unpaid=<?php echo $p['id']; ?><?php echo !empty($selected_month)?'&month='.urlencode($selected_month):''; ?>" class="btn btn-outline-warning" title="غیر ادا شدہ مارک کریں" onclick="return confirm('کیا آپ اسٹیٹس غیر ادا شدہ کرنا چاہتے ہیں؟')"><i class="fas fa-undo"></i></a>
                                    <?php endif; ?>
                                    <a href="print_salary_slip.php?id=<?php echo $p['id']; ?>" target="_blank" class="btn btn-dark" title="پرنٹ سلپ"><i class="fas fa-print"></i></a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($payrolls)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4 fw-bold"><?php echo t('no_payroll_records'); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Generate Modal -->
<div class="modal fade" id="generateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-magic me-2"></i> <?php echo t('generate_month_payroll'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-end">
                    <div class="mb-3">
                        <label class="fw-bold mb-1"><i class="fas fa-calendar-alt me-1 text-primary"></i> <?php echo t('payroll_month'); ?> (سال اور مہینہ منتخب کریں):</label>
                        <input type="month" name="month_year" id="genMonthInput" class="form-control fw-bold" value="<?php echo date('Y-m'); ?>" required>
                        <div class="d-flex gap-2 mt-2">
                            <button type="button" class="btn btn-outline-secondary btn-sm flex-fill fw-bold" onclick="setGenMonth(-1)"><i class="fas fa-arrow-left me-1"></i> پچھلا مہینہ</button>
                            <button type="button" class="btn btn-outline-primary btn-sm flex-fill fw-bold" onclick="setGenMonth(0)"><i class="fas fa-calendar-day me-1"></i> رواں مہینہ</button>
                            <button type="button" class="btn btn-outline-success btn-sm flex-fill fw-bold" onclick="setGenMonth(1)">اگلا مہینہ <i class="fas fa-arrow-right ms-1"></i></button>
                        </div>
                        <small class="text-muted mt-2 d-block">نوٹ: اس مہینے کے لیے تمام فعال اساتذہ کا بنیادی پے رول اور حاضری کٹوتی خودکار طریقے سے تیار ہو جائے گا۔</small>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('cancel'); ?></button>
                    <button type="submit" name="generate_payroll" class="btn btn-info fw-bold text-white"><i class="fas fa-cogs me-1"></i> <?php echo t('generate_payroll'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="payroll_id" id="edit_pid">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i> Update Salary Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="fw-bold mb-1">Allowances (Rs.)</label>
                        <input type="number" name="allowances" id="edit_allow" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold mb-1">Deductions (Rs.)</label>
                        <input type="number" name="deductions" id="edit_deduct" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('cancel'); ?></button>
                    <button type="submit" name="update_payroll" class="btn btn-primary fw-bold text-white"><i class="fas fa-save me-1"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Pay Payroll Modal -->
<div class="modal fade" id="payPayrollModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="submit_pay_payroll" value="1">
                <input type="hidden" name="payroll_id" id="modal_pay_pid">
                <input type="hidden" name="month" value="<?php echo htmlspecialchars($selected_month); ?>">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-money-check-alt me-2"></i> تنخواہ کی ادائیگی کا اندراج</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-end">
                    <div class="alert alert-light border mb-3 text-start">
                        <div class="fw-bold fs-6 text-dark" id="modal_pay_teacher"></div>
                        <div class="text-success fw-bold fs-5" id="modal_pay_amount"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">تاریخ ادائیگی</label>
                        <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">ادائیگی کا کھاتہ (کہاں سے ادا ہوئی؟)</label>
                        <select name="payment_account_id" class="form-select" required>
                            <?php foreach($payment_accounts as $pa): ?>
                                <option value="<?php echo $pa['id']; ?>" <?php echo ($pa['code'] == '1001') ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pa['name'] . ' (' . $pa['code'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">طریقہ ادائیگی</label>
                        <select name="payment_method" class="form-select">
                            <option value="Cash">نقد رقم (Cash)</option>
                            <option value="Bank Transfer">آن لائن بینک ٹرانسفر (Online Transfer)</option>
                            <option value="Cheque">چیک (Cheque)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('cancel'); ?></button>
                    <button type="submit" class="btn btn-success fw-bold"><i class="fas fa-check me-1"></i> ادائیگی مکمل کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function setGenMonth(offset) {
    let now = new Date();
    if (offset !== 0) {
        now.setMonth(now.getMonth() + offset);
    }
    let year = now.getFullYear();
    let month = String(now.getMonth() + 1).padStart(2, '0');
    document.getElementById('genMonthInput').value = `${year}-${month}`;
}

function editPayroll(id, allow, deduct) {
    document.getElementById('edit_pid').value = id;
    document.getElementById('edit_allow').value = allow;
    document.getElementById('edit_deduct').value = deduct;
    var myModal = new bootstrap.Modal(document.getElementById('editModal'));
    myModal.show();
}

function openPayModal(id, teacherName, amount) {
    document.getElementById('modal_pay_pid').value = id;
    document.getElementById('modal_pay_teacher').innerText = 'استاد / ملازم: ' + teacherName;
    document.getElementById('modal_pay_amount').innerText = 'قابل ادا رقم: Rs. ' + Number(amount).toLocaleString();
    var payModal = new bootstrap.Modal(document.getElementById('payPayrollModal'));
    payModal.show();
}
</script>

<?php include 'footer.php'; ?>
