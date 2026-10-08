<?php
require_once 'db.php';
require_once __DIR__ . '/finance_accounting_bridge.php';
include 'header.php';

if (!isset($_SESSION['user_id'])) {
    echo "<script>window.location='login.php';</script>"; exit;
}

$uid  = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'admin';
$system_type = $_SESSION['system_type'] ?? 'Madrasa';

// ─── Languages ────────────────────────────────────────────────
$is_en = ($lang == 'en');
$txt = [
    'title' => $is_en ? 'Staff Salaries Management' : 'وظائف مینجمنٹ',
    'income_expense' => $is_en ? 'Income & Expense' : 'آمدن و اخراجات',
    'generate_payroll' => $is_en ? 'Generate Payroll' : 'پے رول بنائیں',
    'period' => $is_en ? 'Period' : 'مدت',
    'this_month' => $is_en ? 'This Month' : 'اس مہینے',
    'last_month' => $is_en ? 'Last Month' : 'پچھلے مہینے',
    'this_quarter' => $is_en ? 'This Quarter' : 'اس سہ ماہی',
    'this_year' => $is_en ? 'This Year' : 'اس سال',
    'all_time' => $is_en ? 'All Time' : 'کل ریکارڈ',
    'custom_range' => $is_en ? 'Custom Date Range' : 'کسٹم ڈیٹ رینج',
    'start_month' => $is_en ? 'Start Month' : 'آغاز (ماہ)',
    'end_month' => $is_en ? 'End Month' : 'اختتام (ماہ)',
    'status' => $is_en ? 'Status' : 'سٹیٹس',
    'all' => $is_en ? 'All' : 'سب',
    'pending' => $is_en ? 'Pending' : 'پینڈنگ',
    'paid' => $is_en ? 'Paid' : 'ادا شدہ',
    'advance' => $is_en ? 'Advance' : 'ایڈوانس',
    'type' => $is_en ? 'Type' : 'قسم',
    'all_staff' => $is_en ? 'All (Teachers + Staff)' : 'سب (اساتذہ + عملہ)',
    'only_teachers' => $is_en ? 'Teachers Only' : 'صرف اساتذہ',
    'only_staff' => $is_en ? 'Staff Only' : 'صرف دیگر عملہ',
    'filter' => $is_en ? 'Filter' : 'فلٹر',
    'total_salaries' => $is_en ? 'Total Salaries' : 'کل وظائف',
    'salary_record' => $is_en ? 'Salary Records' : 'وظائف کا ریکارڈ',
    'records' => $is_en ? 'Records' : 'ریکارڈ',
    'not_generated' => $is_en ? 'Payroll not generated for this month.' : 'اس ماہ کا پے رول ابھی نہیں بنا',
    'click_generate' => $is_en ? 'Click "Generate Payroll" above.' : 'اوپر "پے رول بنائیں" بٹن دبائیں',
    'sr' => $is_en ? 'Sr' : '#',
    'name_desig' => $is_en ? 'Name / Designation' : 'نام / عہدہ',
    'basic' => $is_en ? 'Basic' : 'بنیادی',
    'allowances' => $is_en ? 'Allowances' : 'الاؤنسز',
    'deductions' => $is_en ? 'Deductions' : 'کٹوتی',
    'net_salary' => $is_en ? 'Net Salary' : 'خالص تنخواہ',
    'pay_date' => $is_en ? 'Payment Date' : 'تاریخ ادائیگی',
    'action' => $is_en ? 'Action' : 'عمل',
    'cancelled' => $is_en ? 'Cancelled' : 'منسوخ',
    'pay_salary' => $is_en ? 'Pay Salary' : 'تنخواہ ادا کریں',
    'give_advance' => $is_en ? 'Give Advance' : 'ایڈوانس دیں',
    'print_slip' => $is_en ? 'Print Slip' : 'تنخواہ پرچی پرنٹ',
    'view_expense' => $is_en ? 'View Expense' : 'خرچ ریکارڈ دیکھیں',
    'total_sum' => $is_en ? 'Total Sum' : 'کل مجموعہ',
    'past_months' => $is_en ? 'Past Payroll Months:' : 'پچھلے پے رول ماہ:',
    'cancel' => $is_en ? 'Cancel' : 'کینسل',
    'generate_info' => $is_en ? 'Payroll will be auto-generated for all active staff. Already generated records will be skipped.' : 'تمام فعال اساتذہ اور عملہ کے لیے خودکار پے رول بنے گا۔ جن کا پہلے سے بنا ہو ان کا نہیں بنے گا۔',
    'select_month' => $is_en ? 'Select Month' : 'ماہ منتخب کریں',
    'note_optional' => $is_en ? 'Note (Optional)' : 'نوٹ (اختیاری)',
    'note_eg_pay' => $is_en ? 'e.g. Cash / Bank Transfer' : 'مثلاً: نقد ادا کیا / بینک ٹرانسفر',
    'auto_expense_pay' => $is_en ? 'This payment will auto-add to expense register under "Staff Salaries".' : 'یہ ادائیگی خودکار خرچ رجسٹر میں "وظیفہ اساتذہ و عملہ" مد میں درج ہو جائے گی۔',
    'adv_amount' => $is_en ? 'Advance Amount (Rs.)' : 'ایڈوانس رقم (Rs.)',
    'date' => $is_en ? 'Date' : 'تاریخ',
    'adv_note' => $is_en ? 'Note' : 'نوٹ',
    'note_eg_adv' => $is_en ? 'e.g. Eid Advance' : 'مثلاً: عید کا ایڈوانس',
    'auto_expense_adv' => $is_en ? 'Advance will also be added to the expense register.' : 'ایڈوانس بھی خرچ رجسٹر میں درج ہو گا۔',
    'quarter' => $is_en ? 'Quarter' : 'سہ ماہی',
    'year' => $is_en ? 'Year' : 'سال',
    'from' => $is_en ? 'From' : 'سے',
    'to' => $is_en ? 'To' : 'تک',
    'msg_generated' => $is_en ? 'Payroll generated successfully for' : 'کا پے رول بنایا گیا',
    'msg_new_records' => $is_en ? 'new records added!' : 'نئے ریکارڈ شامل ہوئے!',
    'msg_paid' => $is_en ? 'Salary paid and recorded in expense register successfully!' : 'تنخواہ ادا کر دی گئی اور خرچ کے رجسٹر میں درج ہو گئی!',
    'msg_adv' => $is_en ? 'Advance paid and recorded in expense register!' : 'ایڈوانس درج ہو گیا اور خرچ میں شامل ہو گیا!',
    'sal_head' => $is_en ? 'Staff & Teacher Salary' : 'وظیفہ اساتذہ و عملہ',
];

function format_month_year($my, $lang) {
    if (!$my) return '';
    $ts = strtotime($my . '-01');
    if ($lang == 'en') return date('F Y', $ts);
    $months = [
        '01' => 'جنوری', '02' => 'فروری', '03' => 'مارچ', '04' => 'اپریل',
        '05' => 'مئی', '06' => 'جون', '07' => 'جولائی', '08' => 'اگست',
        '09' => 'ستمبر', '10' => 'اکتوبر', '11' => 'نومبر', '12' => 'دسمبر'
    ];
    $m = date('m', $ts);
    $y = date('Y', $ts);
    return $months[$m] . ' ' . $y;
}

// ─── One-time cleanup for old PAYROLL_ID markers ───
try {
    $pdo->exec("UPDATE finance SET description = REPLACE(REPLACE(description, SUBSTRING_INDEX(description, ' [PAYROLL_ID:', -1), ''), ' [PAYROLL_ID:', '') WHERE description LIKE '%[PAYROLL_ID:%'");
} catch (Exception $e) {}

// ─── ٹیبل تیار کریں ───────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS acc_payroll (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT NOT NULL,
        month_year VARCHAR(20),
        basic_salary DECIMAL(15,2) DEFAULT 0,
        allowances DECIMAL(15,2) DEFAULT 0,
        deductions DECIMAL(15,2) DEFAULT 0,
        net_salary DECIMAL(15,2) DEFAULT 0,
        status ENUM('Unpaid','Paid','Advance','Cancelled') DEFAULT 'Unpaid',
        payment_date DATE DEFAULT NULL,
        finance_id INT DEFAULT NULL,
        payment_note TEXT DEFAULT '',
        advance_amount DECIMAL(15,2) DEFAULT 0,
        advance_date DATE DEFAULT NULL,
        loan_id INT DEFAULT NULL,
        loan_deduction DECIMAL(15,2) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_by INT,
        system_type VARCHAR(50) DEFAULT 'Madrasa'
    )");
} catch (Exception $e) {}

// تمام نئے کالم شامل کریں (پرانے ڈیٹا بیس کے لیے)
foreach ([
    "ALTER TABLE acc_payroll ADD COLUMN net_salary DECIMAL(15,2) DEFAULT 0",
    "ALTER TABLE acc_payroll ADD COLUMN finance_id INT DEFAULT NULL",
    "ALTER TABLE acc_payroll ADD COLUMN payment_note TEXT DEFAULT ''",
    "ALTER TABLE acc_payroll ADD COLUMN advance_amount DECIMAL(15,2) DEFAULT 0",
    "ALTER TABLE acc_payroll ADD COLUMN advance_date DATE DEFAULT NULL",
    "ALTER TABLE acc_payroll ADD COLUMN loan_id INT DEFAULT NULL",
    "ALTER TABLE acc_payroll ADD COLUMN loan_deduction DECIMAL(15,2) DEFAULT 0",
    "ALTER TABLE acc_payroll MODIFY COLUMN status ENUM('Unpaid','Paid','Advance','Cancelled') DEFAULT 'Unpaid'",
    "ALTER TABLE acc_payroll ADD COLUMN system_type VARCHAR(50) DEFAULT 'Madrasa'",
] as $sql) {
    try { $pdo->exec($sql); } catch (Exception $e) {}
}

try { $pdo->exec("UPDATE acc_payroll SET net_salary = (basic_salary + allowances - deductions) WHERE net_salary = 0 AND (basic_salary + allowances - deductions) > 0"); } catch (Exception $e) {}

// "وظیفہ عملہ" مد بنائیں
$sal_head_id = 0;
try {
    $sh = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE code = 'SAL-001' LIMIT 1")->fetchColumn();
    if (!$sh) {
        $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, is_deleted) VALUES ('SAL-001', ?, 'Expense', 0)")->execute([$txt['sal_head']]);
        $sal_head_id = (int)$pdo->lastInsertId();
    } else {
        $sal_head_id = (int)$sh;
    }
} catch (Exception $e) {}

$msg = '';

// ─── ماہانہ پے رول بنائیں ───────────────────────────────────────
if (isset($_POST['generate_payroll'])) {
    try {
        $my = $_POST['month_year'] ?? date('Y-m');
        $all_staff = $pdo->query("SELECT id, name, salary, house_rent, medical_allowance, travel_allowance, daily_deduction_rate, staff_type FROM teachers WHERE status = 'Active' AND (is_deleted = 0 OR is_deleted IS NULL) AND system_type = '$system_type'")->fetchAll(PDO::FETCH_ASSOC);
        $created = 0;
        foreach ($all_staff as $s) {
            $check = $pdo->prepare("SELECT id FROM acc_payroll WHERE teacher_id = ? AND month_year = ?");
            $check->execute([$s['id'], $my]);
            if ($check->rowCount() > 0) continue;

            $basic     = (float)($s['salary'] ?? 0);
            $allow     = (float)($s['house_rent'] ?? 0) + (float)($s['medical_allowance'] ?? 0) + (float)($s['travel_allowance'] ?? 0);
            $ded       = 0;
            if (($s['staff_type'] ?? 'Teacher') === 'Teacher') {
                try {
                    $abs = $pdo->prepare("SELECT COUNT(*) FROM attendance_teachers WHERE teacher_id = ? AND DATE_FORMAT(date,'%Y-%m') = ? AND status = 'A'");
                    $abs->execute([$s['id'], $my]);
                    $absent = (int)$abs->fetchColumn();
                    $rate   = (float)($s['daily_deduction_rate'] ?? 0);
                    if ($rate == 0 && $basic > 0) $rate = round($basic / 30);
                    $ded    = $absent * $rate;
                } catch (Exception $e) {}
            }
            $net = $basic + $allow - $ded;

            // چیک کریں کہ کوئی ایکٹو لون ہے یا نہیں
            $loan_id = null;
            $loan_ded = 0;
            try {
                $l = $pdo->prepare("SELECT id, installment_amount, remaining_amount FROM staff_loans WHERE teacher_id = ? AND status = 'Active' AND is_deleted = 0 LIMIT 1");
                $l->execute([$s['id']]);
                $loan = $l->fetch(PDO::FETCH_ASSOC);
                if ($loan) {
                    $inst = (float)$loan['installment_amount'];
                    $rem = (float)$loan['remaining_amount'];
                    // قسط اتنی کٹے گی جتنی بقایا ہے (اگر قسط بقایا سے زیادہ ہو تو صرف بقایا کٹے گا)
                    $loan_ded = ($inst > $rem) ? $rem : $inst;
                    // اگر نیٹ تنخواہ کم ہو تو صرف اتنی کٹے جتنی تنخواہ ہے (optional, but good practice)
                    if ($loan_ded > $net) $loan_ded = $net;
                    if ($loan_ded > 0) {
                        $loan_id = $loan['id'];
                        $net -= $loan_ded;
                    }
                }
            } catch(Exception $e) {}

            $pdo->prepare("INSERT INTO acc_payroll (teacher_id, month_year, basic_salary, allowances, deductions, loan_id, loan_deduction, net_salary, status, created_by, system_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Unpaid', ?, '$system_type')")
                ->execute([$s['id'], $my, $basic, $allow, $ded, $loan_id, $loan_ded, $net, $uid]);
            $pid = (int)$pdo->lastInsertId();
            try { payroll_accounting_post($pdo, $pid); } catch (Exception $e) {}
            $created++;
        }
        $msg_text = urlencode("✅ $my {$txt['msg_generated']} — $created {$txt['msg_new_records']}");
        echo "<script>window.location.href='staff_salaries.php?period_filter=custom&custom_start=$my&custom_end=$my&msg=$msg_text';</script>";
        exit;
    } catch (Throwable $e) {
        die("<div class='alert alert-danger m-5 p-5'><b>Fatal Error:</b> " . htmlspecialchars($e->getMessage()) . " <br><br><b>File:</b> " . $e->getFile() . " <br><b>Line:</b> " . $e->getLine() . "</div>");
    }
}

if (isset($_GET['msg'])) {
    $msg = "<div class='alert alert-success fw-bold'>" . htmlspecialchars($_GET['msg']) . "</div>";
}

// ─── تنخواہ ادا کریں ───────────────────────────────────────────
if (isset($_POST['pay_salary'])) {
    $pid  = (int)$_POST['payroll_id'];
    $pdate = $_POST['pay_date'] ?? date('Y-m-d');
    $note = trim($_POST['pay_note'] ?? '');

    $pr = $pdo->prepare("SELECT p.*, t.name AS staff_name, t.staff_type FROM acc_payroll p JOIN teachers t ON t.id = p.teacher_id WHERE p.id = ?");
    $pr->execute([$pid]);
    $pr = $pr->fetch(PDO::FETCH_ASSOC);

    if ($pr && $pr['status'] !== 'Paid') {
        $net = (float)($pr['net_salary'] ?: ($pr['basic_salary'] + $pr['allowances'] - $pr['deductions']));
        $desc = "{$txt['paid']}: " . $pr['staff_name'] . " - " . format_month_year($pr['month_year'], $lang);
        if ($note) $desc .= " | " . $note;
        $fin_stmt = $pdo->prepare("INSERT INTO finance (user_id, user_name, type, head_id, amount, date, donor_name, description, is_deleted) VALUES (?, ?, 'Expense', ?, ?, ?, ?, ?, 0)");
        $fin_stmt->execute([$uid, $_SESSION['name'] ?? 'Admin', $sal_head_id, $net, $pdate, $pr['staff_name'], $desc]);
        $fin_id = (int)$pdo->lastInsertId();
        try { finance_accounting_post($pdo, $fin_id); } catch (Exception $e) {}

        $pdo->prepare("UPDATE acc_payroll SET status='Paid', payment_date=?, finance_id=?, payment_note=?, net_salary=? WHERE id=?")
            ->execute([$pdate, $fin_id, $note, $net, $pid]);

        // اگر قرض کی کٹوتی کی گئی ہے تو لون ٹیبل اپڈیٹ کریں
        if (!empty($pr['loan_id']) && $pr['loan_deduction'] > 0) {
            $lid = (int)$pr['loan_id'];
            $lded = (float)$pr['loan_deduction'];
            $pdo->prepare("UPDATE staff_loans SET remaining_amount = remaining_amount - ? WHERE id = ?")->execute([$lded, $lid]);
            $pdo->prepare("UPDATE staff_loans SET status = 'Paid' WHERE id = ? AND remaining_amount <= 0")->execute([$lid]);
        }

        try { payroll_accounting_post($pdo, $pid); } catch (Exception $e) {}
        $msg = "<div class='alert alert-success fw-bold'>✅ {$txt['msg_paid']}</div>";
    }
}

// ─── ایڈوانس درج کریں ──────────────────────────────────────────
if (isset($_POST['pay_advance'])) {
    $pid      = (int)$_POST['adv_payroll_id'];
    $adv_amt  = (float)$_POST['adv_amount'];
    $adv_date = $_POST['adv_date'] ?? date('Y-m-d');
    $note     = trim($_POST['adv_note'] ?? '');

    $pr = $pdo->prepare("SELECT p.*, t.name AS staff_name FROM acc_payroll p JOIN teachers t ON t.id=p.teacher_id WHERE p.id=?");
    $pr->execute([$pid]);
    $pr = $pr->fetch(PDO::FETCH_ASSOC);

    if ($pr && $adv_amt > 0) {
        $desc = "{$txt['advance']}: " . $pr['staff_name'] . " - " . format_month_year($pr['month_year'], $lang);
        if ($note) $desc .= " | " . $note;
        $fin_stmt = $pdo->prepare("INSERT INTO finance (user_id, user_name, type, head_id, amount, date, donor_name, description, is_deleted) VALUES (?, ?, 'Expense', ?, ?, ?, ?, ?, 0)");
        $fin_stmt->execute([$uid, $_SESSION['name'] ?? 'Admin', $sal_head_id, $adv_amt, $adv_date, $pr['staff_name'], $desc]);
        $fin_id = (int)$pdo->lastInsertId();
        try { finance_accounting_post($pdo, $fin_id); } catch (Exception $e) {}

        $pdo->prepare("UPDATE acc_payroll SET status='Advance', advance_amount=?, advance_date=?, payment_note=? WHERE id=?")
            ->execute([$adv_amt, $adv_date, $note, $pid]);
        $msg = "<div class='alert alert-warning fw-bold'>💰 {$txt['msg_adv']}</div>";
    }
}

// (Fallback sync removed as finance_id is now reliably stored directly upon payment)

// ─── فلٹر ──────────────────────────────────────────────────────
$period_filter = $_GET['period_filter'] ?? 'this_month';
$custom_start  = $_GET['custom_start'] ?? date('Y-m');
$custom_end    = $_GET['custom_end'] ?? date('Y-m');
$filter_status = $_GET['status'] ?? 'all';
$filter_type   = $_GET['type']   ?? 'all'; // teacher / staff / numeric id

$date_where = "";
$date_params = [];
$display_period = "";

if ($period_filter == 'this_month') {
    $date_where = "month_year = ?";
    $date_params[] = date('Y-m');
    $display_period = date('Y-m');
} elseif ($period_filter == 'last_month') {
    $date_where = "month_year = ?";
    $date_params[] = date('Y-m', strtotime('-1 month'));
    $display_period = date('Y-m', strtotime('-1 month'));
} elseif ($period_filter == 'this_quarter') {
    $current_month = (int)date('m');
    $q_start = floor(($current_month - 1) / 3) * 3 + 1;
    $m1 = date('Y-') . str_pad((string)$q_start, 2, '0', STR_PAD_LEFT);
    $m2 = date('Y-') . str_pad((string)($q_start + 1), 2, '0', STR_PAD_LEFT);
    $m3 = date('Y-') . str_pad((string)($q_start + 2), 2, '0', STR_PAD_LEFT);
    $date_where = "month_year IN (?, ?, ?)";
    array_push($date_params, $m1, $m2, $m3);
    $display_period = "{$txt['quarter']} ($m1 {$txt['to']} $m3)";
} elseif ($period_filter == 'this_year') {
    $date_where = "month_year LIKE ?";
    $date_params[] = date('Y') . '-%';
    $display_period = "{$txt['year']} " . date('Y');
} elseif ($period_filter == 'custom') {
    $date_where = "month_year BETWEEN ? AND ?";
    $date_params[] = $custom_start;
    $date_params[] = $custom_end;
    $display_period = "$custom_start {$txt['to']} $custom_end";
} elseif ($period_filter == 'all') {
    $date_where = "id IS NOT NULL";
    $display_period = $txt['all_time'];
}

// ─── خلاصہ ─────────────────────────────────────────────────────
try {
    $sum = $pdo->prepare("SELECT
        COUNT(*) AS total_records,
        SUM(p.net_salary) AS total_net,
        SUM(CASE WHEN p.status='Paid' THEN p.net_salary ELSE 0 END) AS paid_net,
        SUM(CASE WHEN p.status='Unpaid' THEN p.net_salary ELSE 0 END) AS unpaid_net,
        SUM(CASE WHEN p.status='Advance' THEN p.advance_amount ELSE 0 END) AS advance_net,
        SUM(CASE WHEN p.status='Unpaid' THEN 1 ELSE 0 END) AS pending_count
    FROM acc_payroll p
    JOIN teachers t ON t.id = p.teacher_id
    WHERE p.$date_where AND (t.is_deleted=0 OR t.is_deleted IS NULL) AND p.system_type = '$system_type'");
    $sum->execute($date_params);
    $summary = $sum->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $summary = ['total_net' => 0, 'paid_net' => 0, 'unpaid_net' => 0, 'advance_net' => 0, 'pending_count' => 0];
}

// ─── پے رول ریکارڈ ─────────────────────────────────────────────
$where_parts = ["p.$date_where"];
$params      = $date_params;

if ($filter_status !== 'all') {
    $where_parts[] = "p.status = ?";
    $params[] = ucfirst($filter_status);
}
if ($filter_type === 'teacher') {
    $where_parts[] = "(t.staff_type = 'Teacher' OR t.staff_type IS NULL)";
} elseif ($filter_type === 'staff') {
    $where_parts[] = "(t.staff_type != 'Teacher' AND t.staff_type IS NOT NULL)";
} elseif (is_numeric($filter_type) && $filter_type > 0) {
    $where_parts[] = "p.teacher_id = ?";
    $params[] = $filter_type;
}
$where_parts[] = "(t.is_deleted = 0 OR t.is_deleted IS NULL)";

$where_sql = implode(' AND ', $where_parts);
try {
    $payrolls = $pdo->prepare("
        SELECT p.*, t.name AS staff_name, t.staff_type, t.phone
        FROM acc_payroll p
        JOIN teachers t ON t.id = p.teacher_id
        WHERE 1=1 AND p.system_type = '$system_type' AND $where_sql
        ORDER BY t.staff_type ASC, t.name ASC, p.month_year DESC
    ");
    $payrolls->execute($params);
    $payrolls = $payrolls->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $payrolls = [];
}

$total_unpaid = 0;
try {
    $total_unpaid = $pdo->query("SELECT SUM(net_salary) FROM acc_payroll WHERE system_type = '$system_type' AND status = 'Unpaid' AND is_deleted = 0")->fetchColumn() ?: 0;
} catch (Exception $e) {}

$all_teachers = [];
try {
    $all_teachers = $pdo->query("SELECT id, name, staff_type FROM teachers WHERE system_type = '$system_type' AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY staff_type ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$months_available = [];
try {
    $months_available = $pdo->query("SELECT DISTINCT month_year FROM acc_payroll WHERE system_type = '$system_type' ORDER BY month_year DESC LIMIT 24")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$staff_icons = [
    'Teacher' => '📖', 'باورچی' => '🍳', 'خادم' => '🧹', 'کیشیئر' => '💰',
    'ناظم' => '📋', 'چوکیدار' => '🔒', 'ڈرائیور' => '🚗',
    'دفتری' => '🗂️', 'صفائی کارکن' => '🧽', 'دیگر' => '👤',
];

function staff_icon($type, $icons) {
    return $icons[$type] ?? '👤';
}
?>

<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h3 class="fw-bold text-primary mb-0">
            <i class="fas fa-money-check-alt me-2"></i> <?php echo $txt['title']; ?>
        </h3>
        <div class="d-flex gap-2 flex-wrap">
            <a href="simple_finance.php" class="btn btn-outline-success btn-sm fw-bold">
                <i class="fas fa-coins me-1"></i> <?php echo $txt['income_expense']; ?>
            </a>
            <a href="staff_loans.php" class="btn btn-warning btn-sm fw-bold text-dark">
                <i class="fas fa-hand-holding-usd me-1"></i> <?php echo $is_en ? 'Manage Loans' : 'قرضہ جات'; ?>
            </a>
            <button class="btn btn-success btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#generateModal">
                <i class="fas fa-magic me-1"></i> <?php echo $txt['generate_payroll']; ?>
            </button>
        </div>
    </div>

    <?php echo $msg; ?>

    <!-- ═══ فلٹر بار ═══ -->
    <form method="GET" class="card shadow-sm mb-3 border-0">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="fw-bold small"><?php echo $txt['period']; ?></label>
                    <select name="period_filter" id="period_filter" class="form-select form-select-sm" onchange="toggleCustomMonth(); this.form.submit()">
                        <option value="this_month" <?php echo $period_filter=='this_month'?'selected':''; ?>><?php echo $txt['this_month']; ?></option>
                        <option value="last_month" <?php echo $period_filter=='last_month'?'selected':''; ?>><?php echo $txt['last_month']; ?></option>
                        <option value="this_quarter" <?php echo $period_filter=='this_quarter'?'selected':''; ?>><?php echo $txt['this_quarter']; ?></option>
                        <option value="this_year" <?php echo $period_filter=='this_year'?'selected':''; ?>><?php echo $txt['this_year']; ?></option>
                        <option value="all" <?php echo $period_filter=='all'?'selected':''; ?>><?php echo $txt['all_time']; ?></option>
                        <option value="custom" <?php echo $period_filter=='custom'?'selected':''; ?>><?php echo $txt['custom_range']; ?></option>
                    </select>
                </div>
                <div class="col-md-4" id="custom_month_div" style="<?php echo $period_filter=='custom' ? '' : 'display:none;'; ?>">
                    <div class="row g-1">
                        <div class="col-6">
                            <label class="fw-bold small"><?php echo $txt['start_month']; ?></label>
                            <input type="month" name="custom_start" class="form-control form-control-sm"
                                value="<?php echo htmlspecialchars($custom_start); ?>" onchange="this.form.submit()">
                        </div>
                        <div class="col-6">
                            <label class="fw-bold small"><?php echo $txt['end_month']; ?></label>
                            <input type="month" name="custom_end" class="form-control form-control-sm"
                                value="<?php echo htmlspecialchars($custom_end); ?>" onchange="this.form.submit()">
                        </div>
                    </div>
                </div>
                <script>
                function toggleCustomMonth() {
                    var pf = document.getElementById('period_filter').value;
                    document.getElementById('custom_month_div').style.display = (pf === 'custom') ? 'block' : 'none';
                }
                </script>
                <div class="col-md-3">
                    <label class="fw-bold small"><?php echo $txt['status']; ?></label>
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="all" <?php echo $filter_status=='all'?'selected':''; ?>><?php echo $txt['all']; ?></option>
                        <option value="unpaid" <?php echo $filter_status=='unpaid'?'selected':''; ?>>⏳ <?php echo $txt['pending']; ?></option>
                        <option value="paid" <?php echo $filter_status=='paid'?'selected':''; ?>>✅ <?php echo $txt['paid']; ?></option>
                        <option value="advance" <?php echo $filter_status=='advance'?'selected':''; ?>>💰 <?php echo $txt['advance']; ?></option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="fw-bold small"><?php echo $txt['type']; ?></label>
                    <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="all" <?php echo $filter_type=='all'?'selected':''; ?>><?php echo $txt['all_staff']; ?></option>
                        <option value="teacher" <?php echo $filter_type=='teacher'?'selected':''; ?>>📖 <?php echo $txt['only_teachers']; ?></option>
                        <option value="staff" <?php echo $filter_type=='staff'?'selected':''; ?>>👷 <?php echo $txt['only_staff']; ?></option>
                        <optgroup label="<?php echo $txt['staff']; ?>">
                        <?php foreach($all_teachers as $tchr): ?>
                            <option value="<?php echo $tchr['id']; ?>" <?php echo $filter_type==$tchr['id']?'selected':''; ?>><?php echo htmlspecialchars($tchr['name']) . ' (' . htmlspecialchars($tchr['staff_type'] ?? 'Teacher') . ')'; ?></option>
                        <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-primary btn-sm w-100 h-100" style="padding-top:2px;">
                        <i class="fas fa-filter"></i>
                    </button>
                </div>
            </div>
        </div>
    </form>

    <!-- ═══ خلاصہ کارڈز ═══ -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-right: 4px solid #284D3D !important;">
                <div class="card-body text-center py-3">
                    <div class="fs-4 fw-bold text-success">
                        Rs. <?php echo number_format((float)($summary['total_net'] ?? 0), 0); ?>
                    </div>
                    <small class="text-muted fw-bold"><?php echo $txt['total_salaries']; ?></small>
                    <br><small class="text-muted"><?php echo htmlspecialchars($display_period); ?></small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-right: 4px solid #198754 !important;">
                <div class="card-body text-center py-3">
                    <div class="fs-4 fw-bold text-success">
                        Rs. <?php echo number_format((float)($summary['paid_net'] ?? 0), 0); ?>
                    </div>
                    <small class="text-muted fw-bold">✅ <?php echo $txt['paid']; ?></small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-right: 4px solid #dc3545 !important;">
                <div class="card-body text-center py-3">
                    <div class="fs-4 fw-bold text-danger">
                        Rs. <?php echo number_format((float)($summary['unpaid_net'] ?? 0), 0); ?>
                    </div>
                    <small class="text-danger fw-bold">⏳ <?php echo $txt['pending']; ?> — <?php echo (int)($summary['pending_count'] ?? 0); ?></small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-right: 4px solid #fd7e14 !important;">
                <div class="card-body text-center py-3">
                    <div class="fs-4 fw-bold text-warning">
                        Rs. <?php echo number_format((float)($summary['advance_net'] ?? 0), 0); ?>
                    </div>
                    <small class="text-muted fw-bold">💰 <?php echo $txt['advance']; ?></small>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ وظائف کی فہرست ═══ -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
            <h6 class="mb-0 fw-bold text-primary">
                <i class="fas fa-list me-2"></i> <?php echo $txt['salary_record']; ?> — <?php echo htmlspecialchars($display_period); ?>
            </h6>
            <span class="badge bg-primary"><?php echo count($payrolls); ?> <?php echo $txt['records']; ?></span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($payrolls)): ?>
            <div class="text-center p-5 text-muted">
                <div style="font-size:3rem;">📋</div>
                <h5 class="mt-2"><?php echo $txt['not_generated']; ?></h5>
                <p><?php echo $txt['click_generate']; ?></p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle m-0 table-sm">
                    <thead class="table-dark">
                        <tr>
                            <th><?php echo $txt['sr']; ?></th>
                            <th><?php echo $txt['name_desig']; ?></th>
                            <th><?php echo $txt['basic']; ?></th>
                            <th><?php echo $txt['allowances']; ?></th>
                            <th><?php echo $txt['deductions']; ?></th>
                            <th class="fw-bold"><?php echo $txt['net_salary']; ?></th>
                            <th><?php echo $txt['status']; ?></th>
                            <th><?php echo $txt['pay_date']; ?></th>
                            <th><?php echo $txt['action']; ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($payrolls as $i => $p):
                        $icon = staff_icon($p['staff_type'] ?? 'Teacher', $staff_icons);
                        $net  = (float)($p['net_salary'] ?: ($p['basic_salary'] + $p['allowances'] - $p['deductions']));
                    ?>
                    <tr class="<?php echo $p['status']=='Unpaid' ? 'table-warning' : ($p['status']=='Paid' ? '' : 'table-info'); ?>">
                        <td class="text-muted small"><?php echo $i+1; ?></td>
                        <td>
                            <span class="me-1"><?php echo $icon; ?></span>
                            <strong><?php echo htmlspecialchars($p['staff_name']); ?></strong>
                            <br><small class="text-muted"><?php echo htmlspecialchars($p['staff_type'] ?? 'Teacher'); ?></small>
                        </td>
                        <td class="small">Rs.<?php echo number_format((float)$p['basic_salary'], 0); ?></td>
                        <td class="small text-success">+<?php echo number_format((float)$p['allowances'], 0); ?></td>
                        <td class="small text-danger">
                            -<?php echo number_format((float)$p['deductions'], 0); ?>
                            <?php if(!empty($p['loan_deduction']) && $p['loan_deduction'] > 0): ?>
                                <br><small class="text-warning">-<?php echo number_format((float)$p['loan_deduction'], 0); ?> (<?php echo $is_en ? 'Loan' : 'قرض'; ?>)</small>
                            <?php endif; ?>
                        </td>
                        <td class="fw-bold text-primary">Rs.<?php echo number_format($net, 0); ?></td>
                        <td>
                            <?php if ($p['status'] == 'Paid'): ?>
                                <span class="badge bg-success"><i class="fas fa-check me-1"></i><?php echo $txt['paid']; ?></span>
                            <?php elseif ($p['status'] == 'Advance'): ?>
                                <span class="badge bg-warning text-dark">
                                    <i class="fas fa-hand-holding-usd me-1"></i><?php echo $txt['advance']; ?>
                                    <br><small>Rs.<?php echo number_format((float)$p['advance_amount'], 0); ?></small>
                                </span>
                            <?php elseif ($p['status'] == 'Cancelled'): ?>
                                <span class="badge bg-secondary"><?php echo $txt['cancelled']; ?></span>
                            <?php else: ?>
                                <span class="badge bg-danger"><i class="fas fa-clock me-1"></i><?php echo $txt['pending']; ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?php echo $p['payment_date'] ? date('d-M-Y', strtotime($p['payment_date'])) : '—'; ?>
                            <?php if ($p['payment_note']): ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars($p['payment_note']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex gap-1 flex-wrap">
                            <?php if ($p['status'] !== 'Paid' && $p['status'] !== 'Cancelled'): ?>
                                <button class="btn btn-success btn-sm fw-bold"
                                    onclick="openPayModal(<?php echo $p['id']; ?>, '<?php echo htmlspecialchars(addslashes($p['staff_name'])); ?>', <?php echo $net; ?>)"
                                    title="<?php echo $txt['pay_salary']; ?>">
                                    <i class="fas fa-money-check-alt"></i> <?php echo $txt['pay_salary']; ?>
                                </button>
                                <button class="btn btn-warning btn-sm"
                                    onclick="openAdvModal(<?php echo $p['id']; ?>, '<?php echo htmlspecialchars(addslashes($p['staff_name'])); ?>')"
                                    title="<?php echo $txt['give_advance']; ?>">
                                    <i class="fas fa-hand-holding-usd"></i>
                                </button>
                            <?php endif; ?>
                                <a href="print_salary_slip.php?id=<?php echo $p['id']; ?>" target="_blank"
                                    class="btn btn-outline-primary btn-sm" title="<?php echo $txt['print_slip']; ?>">
                                    <i class="fas fa-print"></i>
                                </a>
                                <?php if ($p['finance_id']): ?>
                                <a href="simple_finance.php#fin_<?php echo $p['finance_id']; ?>"
                                    class="btn btn-outline-success btn-sm" title="<?php echo $txt['view_expense']; ?>">
                                    <i class="fas fa-link"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php
                    $total_basic = array_sum(array_column($payrolls, 'basic_salary'));
                    $total_allow = array_sum(array_column($payrolls, 'allowances'));
                    $total_deduct = array_sum(array_column($payrolls, 'deductions'));
                    $total_net   = array_sum(array_map(fn($p) => (float)($p['net_salary'] ?: $p['basic_salary'] + $p['allowances'] - $p['deductions']), $payrolls));
                    ?>
                    <tfoot class="table-dark fw-bold">
                        <tr>
                            <td colspan="2" class="text-end"><?php echo $txt['total_sum']; ?></td>
                            <td>Rs.<?php echo number_format($total_basic, 0); ?></td>
                            <td class="text-success">+<?php echo number_format($total_allow, 0); ?></td>
                            <td class="text-danger">-<?php echo number_format($total_deduct, 0); ?></td>
                            <td class="fs-6">Rs.<?php echo number_format($total_net, 0); ?></td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- تاریخی ماہ -->
    <?php if (!empty($months_available)): ?>
    <div class="card border-0 shadow-sm mt-3">
        <div class="card-body py-2">
            <small class="fw-bold text-muted"><?php echo $txt['past_months']; ?> </small>
            <?php foreach ($months_available as $m): ?>
                <a href="?period_filter=custom&custom_start=<?php echo urlencode($m); ?>&custom_end=<?php echo urlencode($m); ?>&status=<?php echo $filter_status; ?>&type=<?php echo $filter_type; ?>"
                    class="badge <?php echo ($period_filter=='custom' && $m == $custom_start) ? 'bg-primary' : 'bg-secondary'; ?> text-decoration-none me-1">
                    <?php echo htmlspecialchars($m); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="generateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-magic me-2"></i> <?php echo $txt['generate_payroll']; ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info small">
                        <i class="fas fa-info-circle me-1"></i>
                        <?php echo $txt['generate_info']; ?>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold"><?php echo $txt['select_month']; ?></label>
                        <input type="month" name="month_year" class="form-control"
                            value="<?php echo date('Y-m'); ?>" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo $txt['cancel']; ?></button>
                    <button type="submit" name="generate_payroll" class="btn btn-success fw-bold">
                        <i class="fas fa-cogs me-1"></i> <?php echo $txt['generate_payroll']; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="payModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="payroll_id" id="pay_pid">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-money-check-alt me-2"></i> <?php echo $txt['pay_salary']; ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3 p-3 bg-light rounded">
                        <strong id="pay_name" class="fs-5 text-primary"></strong>
                        <div class="fs-4 fw-bold text-success mt-1">
                            Rs. <span id="pay_net"></span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold"><?php echo $txt['pay_date']; ?></label>
                        <input type="date" name="pay_date" class="form-control"
                            value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold"><?php echo $txt['note_optional']; ?></label>
                        <input type="text" name="pay_note" class="form-control"
                            placeholder="<?php echo $txt['note_eg_pay']; ?>">
                    </div>
                    <div class="alert alert-success small mb-0">
                        <i class="fas fa-link me-1"></i>
                        <?php echo $txt['auto_expense_pay']; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo $txt['cancel']; ?></button>
                    <button type="submit" name="pay_salary" class="btn btn-success fw-bold">
                        <i class="fas fa-check me-1"></i> <?php echo $txt['pay_salary']; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="advModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="adv_payroll_id" id="adv_pid">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-hand-holding-usd me-2"></i> <?php echo $txt['give_advance']; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2 p-2 bg-warning bg-opacity-10 rounded">
                        <strong id="adv_name" class="text-warning fs-6"></strong>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold"><?php echo $txt['adv_amount']; ?></label>
                        <input type="number" name="adv_amount" class="form-control border-warning"
                            placeholder="0" min="1" required>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold"><?php echo $txt['date']; ?></label>
                        <input type="date" name="adv_date" class="form-control"
                            value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold"><?php echo $txt['adv_note']; ?></label>
                        <input type="text" name="adv_note" class="form-control"
                            placeholder="<?php echo $txt['note_eg_adv']; ?>">
                    </div>
                    <div class="alert alert-warning small mb-0">
                        <i class="fas fa-info-circle me-1"></i>
                        <?php echo $txt['auto_expense_adv']; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo $txt['cancel']; ?></button>
                    <button type="submit" name="pay_advance" class="btn btn-warning fw-bold text-dark">
                        <i class="fas fa-check me-1"></i> <?php echo $txt['give_advance']; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openPayModal(pid, name, net) {
    document.getElementById('pay_pid').value = pid;
    document.getElementById('pay_name').textContent = name;
    document.getElementById('pay_net').textContent = net.toLocaleString('ur-PK', {minimumFractionDigits: 0});
    new bootstrap.Modal(document.getElementById('payModal')).show();
}
function openAdvModal(pid, name) {
    document.getElementById('adv_pid').value = pid;
    document.getElementById('adv_name').textContent = name;
    new bootstrap.Modal(document.getElementById('advModal')).show();
}
</script>

<?php include 'footer.php'; ?>

