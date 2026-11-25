<?php
require_once 'db.php';
require_once __DIR__ . '/finance_accounting_bridge.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id']) || (!app_has_perm('manage_erp') && !app_has_perm('manage_stipends') && !app_has_perm('manage_masjid_stipends') && ($_SESSION['role'] ?? '') !== 'admin' && ($_SESSION['role'] ?? '') !== 'nazim')) {
    header("Location: dashboard.php");
    exit;
}

$role = $_SESSION['role'] ?? '';
$system_type = $_SESSION['system_type'] ?? 'Madrasa';
$is_masjid = ($system_type === 'Masjid');
$cur_tenant_id = get_current_tenant_id($pdo);

$selected_month = $_GET['month_year'] ?? date('Y-m');
$cutoff_date = date('Y-m-01', strtotime($selected_month . '-01'));

$sys_cond_teachers = $is_masjid 
    ? "system_type = 'Masjid'" 
    : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
$sys_cond_payroll = $is_masjid 
    ? "p.system_type = 'Masjid'" 
    : "(p.system_type = 'Madrasa' OR p.system_type IS NULL OR p.system_type = '')";

// تمام اساتذہ و عملہ حاصل کریں
$t_stmt = $pdo->prepare("SELECT id, name, father_name, phone, salary, joining_date, resignation_date, status FROM teachers WHERE (is_deleted = 0 OR is_deleted IS NULL) AND $sys_cond_teachers AND tenant_id = ? ORDER BY id ASC");
$t_stmt->execute([$cur_tenant_id]);
$teachers = $t_stmt->fetchAll(PDO::FETCH_ASSOC);

// تمام پے رول حاصل کریں
$p_stmt = $pdo->prepare("SELECT p.*, t.name as teacher_name FROM acc_payroll p LEFT JOIN teachers t ON p.teacher_id = t.id WHERE (p.is_deleted = 0 OR p.is_deleted IS NULL) AND $sys_cond_payroll AND p.tenant_id = ?");
$p_stmt->execute([$cur_tenant_id]);
$all_payrolls = $p_stmt->fetchAll(PDO::FETCH_ASSOC);

$payroll_by_teacher = [];
foreach ($all_payrolls as $p) {
    $payroll_by_teacher[$p['teacher_id']][] = $p;
}

$records = [];
$grand_due = 0.0;
$grand_paid = 0.0;
$grand_unpaid = 0.0;
$grand_arrears = 0.0;
$grand_advance = 0.0;
$grand_net = 0.0;

foreach ($teachers as $t) {
    $tid = (int)$t['id'];
    $salary = (float)($t['salary'] ?? 0);
    $joining = !empty($t['joining_date']) ? $t['joining_date'] : '2026-01-01';
    $resignation = !empty($t['resignation_date']) ? $t['resignation_date'] : null;

    $d_start = new DateTime($joining);
    $d_cutoff = new DateTime($cutoff_date);
    $d_end = $d_cutoff;
    if ($resignation) {
        $d_res = new DateTime($resignation);
        if ($d_res < $d_cutoff) $d_end = $d_res;
    }

    $months_count = 0;
    if ($d_start <= $d_end) {
        $months_count = (($d_end->format('Y') - $d_start->format('Y')) * 12) + ($d_end->format('m') - $d_start->format('m')) + 1;
        if ($months_count < 0) $months_count = 0;
    }

    $t_payrolls = $payroll_by_teacher[$tid] ?? [];
    $paid_amt = 0.0;
    $unpaid_amt = 0.0;
    $advance_amt = 0.0;
    $gen_months = [];

    foreach ($t_payrolls as $p) {
        $my = $p['month_year'] ?? '';
        $net = (float)($p['net_salary'] ?? 0);
        $st = strtolower(trim($p['status'] ?? ''));

        if (!empty($my)) {
            $gen_months[$my] = true;
            $p_date = new DateTime($my . '-01');
            if ($p_date > $d_cutoff) {
                if ($st === 'paid') {
                    $advance_amt += $net;
                }
                continue;
            }
        }

        if ($st === 'paid') {
            $paid_amt += $net;
        } elseif ($st === 'unpaid') {
            $unpaid_amt += $net;
        }
    }

    $due_amt = $months_count * $salary;

    // بقایا جات (Missing Months)
    $missing_months = 0;
    $cur_p = clone $d_start;
    $cur_p->modify('first day of this month');
    $end_p = clone $d_end;
    $end_p->modify('first day of this month');

    while ($cur_p <= $end_p) {
        $m_key = $cur_p->format('Y-m');
        if (!isset($gen_months[$m_key])) {
            $missing_months++;
        }
        $cur_p->modify('+1 month');
    }
    $arrears_amt = $missing_months * $salary;

    $net_bal = $due_amt - $paid_amt - $advance_amt;

    $records[] = [
        'id' => $tid,
        'name' => $t['name'],
        'father_name' => $t['father_name'],
        'phone' => $t['phone'],
        'salary' => $salary,
        'joining_date' => $t['joining_date'],
        'resignation_date' => $t['resignation_date'],
        'months' => $months_count,
        'due' => $due_amt,
        'paid' => $paid_amt,
        'unpaid' => $unpaid_amt,
        'arrears' => $arrears_amt,
        'advance' => $advance_amt,
        'net' => $net_bal,
    ];

    $grand_due += $due_amt;
    $grand_paid += $paid_amt;
    $grand_unpaid += $unpaid_amt;
    $grand_arrears += $arrears_amt;
    $grand_advance += $advance_amt;
    $grand_net += $net_bal;
}

$page_title = ($is_masjid ? "مسجد" : "مدرسہ") . " — تنخواہ و واجبات اسٹیٹس";
include 'header.php';
?>

<div class="container-fluid py-4" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-1" style="color: #284D3D;">
                <i class="bi bi-wallet2 me-2"></i><?= htmlspecialchars($page_title) ?>
            </h3>
            <p class="text-muted mb-0">اساتذہ و عملہ کے کل واجب، ادا شدہ، ایڈوانس اور خالص باقی کا تفصیلی موازنہ</p>
        </div>
        <div class="d-flex gap-2">
            <form method="GET" class="d-flex align-items-center gap-2">
                <label class="form-label mb-0 fw-bold">انتخابِ ماہ:</label>
                <input type="month" name="month_year" class="form-control" value="<?= htmlspecialchars($selected_month) ?>" onchange="this.form.submit()">
            </form>
            <button onclick="window.print()" class="btn btn-outline-success">
                <i class="bi bi-printer me-1"></i> پرنٹ
            </button>
        </div>
    </div>

    <!-- Grand Totals Cards (Benchmark Reference) -->
    <div class="row g-3 mb-4">
        <div class="col-md-2 col-6">
            <div class="card border-0 shadow-sm text-center p-3" style="background: #284D3D; color: white; border-radius: 10px;">
                <small class="opacity-75">کل واجب</small>
                <h4 class="fw-bold mb-0 mt-1"><?= number_format($grand_due) ?></h4>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="card border-0 shadow-sm text-center p-3 text-white" style="background: #198754; border-radius: 10px;">
                <small class="opacity-75">ادا شدہ</small>
                <h4 class="fw-bold mb-0 mt-1"><?= number_format($grand_paid) ?></h4>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="card border-0 shadow-sm text-center p-3 text-dark" style="background: #ffc107; border-radius: 10px;">
                <small class="opacity-75">باقی (غیر ادا شدہ پے رول)</small>
                <h4 class="fw-bold mb-0 mt-1"><?= number_format($grand_unpaid) ?></h4>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="card border-0 shadow-sm text-center p-3 text-white" style="background: #dc3545; border-radius: 10px;">
                <small class="opacity-75">بقایا جات (غیر جنریٹ شدہ)</small>
                <h4 class="fw-bold mb-0 mt-1"><?= number_format($grand_arrears) ?></h4>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="card border-0 shadow-sm text-center p-3 text-white" style="background: #0d6efd; border-radius: 10px;">
                <small class="opacity-75">ایڈوانس</small>
                <h4 class="fw-bold mb-0 mt-1"><?= number_format($grand_advance) ?></h4>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="card border-0 shadow-sm text-center p-3 text-white" style="background: #6f42c1; border-radius: 10px;">
                <small class="opacity-75">خالص باقی</small>
                <h4 class="fw-bold mb-0 mt-1"><?= number_format($grand_net) ?></h4>
            </div>
        </div>
    </div>

    <!-- Main Table Card -->
    <div class="card border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
        <div class="card-header bg-white py-3 border-0">
            <h5 class="fw-bold mb-0" style="color: #284D3D;">مشترکہ اسٹیٹس رپورٹ (تا اختتام <?= htmlspecialchars($selected_month) ?>)</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 text-center">
                <thead style="background-color: #E8ECE9;">
                    <tr>
                        <th>#</th>
                        <th class="text-start">نام استاد / عملہ</th>
                        <th>ماہانہ مشاہرہ</th>
                        <th>مدتِ خدمت</th>
                        <th>کل واجب</th>
                        <th>ادا شدہ</th>
                        <th>باقی (پے رول)</th>
                        <th>بقایا جات</th>
                        <th>ایڈوانس</th>
                        <th>خالص باقی</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr><td colspan="10" class="py-4 text-muted">کوئی ریکارڈ نہیں ملا</td></tr>
                    <?php else: ?>
                        <?php foreach ($records as $r): ?>
                            <tr>
                                <td><?= $r['id'] ?></td>
                                <td class="text-start fw-bold">
                                    <?= htmlspecialchars($r['name']) ?>
                                    <?php if (!empty($r['father_name'])): ?>
                                        <div class="small text-muted fw-normal">ولد <?= htmlspecialchars($r['father_name']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= number_format($r['salary']) ?></td>
                                <td><?= $r['months'] ?> ماہ</td>
                                <td class="fw-bold"><?= number_format($r['due']) ?></td>
                                <td class="text-success fw-bold"><?= number_format($r['paid']) ?></td>
                                <td><?= number_format($r['unpaid']) ?></td>
                                <td><?= number_format($r['arrears']) ?></td>
                                <td class="text-primary fw-bold"><?= number_format($r['advance']) ?></td>
                                <td class="fw-bold <?= ($r['net'] < 0) ? 'text-purple' : (($r['net'] > 0) ? 'text-danger' : 'text-dark') ?>">
                                    <?= number_format($r['net']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <!-- Grand Total Footer -->
                        <tr class="table-active fw-bold fs-6">
                            <td colspan="4" class="text-center">مجموعہ (Grand Total):</td>
                            <td><?= number_format($grand_due) ?></td>
                            <td class="text-success"><?= number_format($grand_paid) ?></td>
                            <td><?= number_format($grand_unpaid) ?></td>
                            <td><?= number_format($grand_arrears) ?></td>
                            <td class="text-primary"><?= number_format($grand_advance) ?></td>
                            <td class="text-purple"><?= number_format($grand_net) ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
