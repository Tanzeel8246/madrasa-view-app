<?php 
require_once 'db.php'; 
include 'header.php'; 

// Check Permissions
$u_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin = in_array($u_role, ['admin', 'superadmin', 'super_admin', 'administrator'], true);
if (!$is_admin && $u_role !== 'nazim' && !app_has_perm('manage_finance_report')) {
    echo "<script>window.location='dashboard.php';</script>";
    exit;
}

$cur_tenant_id = (int)get_current_tenant_id();
$sys = $_SESSION['system_type'] ?? 'Madrasa';
$is_masjid = ($sys === 'Masjid');
$sys_cond_std = $is_masjid ? "system_type = 'Masjid'" : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
$sys_cond_fin = $is_masjid ? "system_type = 'Masjid'" : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

$current_year = date('Y');
$start_date = $_GET['from'] ?? "$current_year-01-01";
$end_date = $_GET['to'] ?? "$current_year-12-31";

// 1. Total Students & Teachers
$total_students = 0;
try {
    $stmt_std = $pdo->prepare("SELECT COUNT(*) FROM students WHERE (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = ? AND $sys_cond_std");
    $stmt_std->execute([$cur_tenant_id]);
    $total_students = (int)$stmt_std->fetchColumn();
} catch (Throwable $e) {}

$total_teachers = 0;
try {
    $stmt_tch = $pdo->prepare("SELECT COUNT(*) FROM teachers WHERE (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = ? AND $sys_cond_std");
    $stmt_tch->execute([$cur_tenant_id]);
    $total_teachers = (int)$stmt_tch->fetchColumn();
} catch (Throwable $e) {}

// 2. Average Attendance
$avg_attendance = 0;
try {
    $stmt_att = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN (status_1='P' OR late_1 > 0 OR status_2='P' OR late_2 > 0) THEN 1 ELSE 0 END) as presents
        FROM attendance 
        WHERE tenant_id = ? AND date BETWEEN ? AND ?
    ");
    $stmt_att->execute([$cur_tenant_id, $start_date, $end_date]);
    $att_data = $stmt_att->fetch();
    $total_att_records = (int)($att_data['total'] ?? 0);
    $present_att_records = (int)($att_data['presents'] ?? 0);
    $avg_attendance = $total_att_records > 0 ? round(($present_att_records / $total_att_records) * 100, 1) : 0;
} catch (Throwable $e) {}

// 3. Financial Breakdown
$fin_income = 0.0;
$fin_expense = 0.0;
try {
    $stmt_fin = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN LOWER(type) IN ('income', 'آمدن') THEN amount ELSE 0 END) as total_income,
            SUM(CASE WHEN LOWER(type) IN ('expense', 'خرچ', 'اخراجات') THEN amount ELSE 0 END) as total_expense
        FROM finance 
        WHERE tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL) AND $sys_cond_fin AND date BETWEEN ? AND ?
    ");
    $stmt_fin->execute([$cur_tenant_id, $start_date, $end_date]);
    $fin_data = $stmt_fin->fetch();
    $fin_income = (float)($fin_data['total_income'] ?? 0);
    $fin_expense = (float)($fin_data['total_expense'] ?? 0);
} catch (Throwable $e) {}

// Fee Submissions
$fee_income = 0.0;
try {
    $stmt_fee = $pdo->prepare("
        SELECT SUM(amount) FROM fee_submissions 
        WHERE tenant_id = ? AND (LOWER(status) IN ('approved', 'paid', 'ادا شدہ') OR status IS NULL)
        AND (
            (date IS NOT NULL AND date BETWEEN ? AND ?) 
            OR (submission_date IS NOT NULL AND submission_date BETWEEN ? AND ?)
        )
    ");
    $stmt_fee->execute([$cur_tenant_id, $start_date, $end_date, $start_date, $end_date]);
    $fee_income = (float)$stmt_fee->fetchColumn();
} catch (Throwable $e) {
    try {
        $stmt_fee = $pdo->prepare("
            SELECT SUM(amount) FROM fee_submissions 
            WHERE tenant_id = ? AND (LOWER(status) IN ('approved', 'paid', 'ادا شدہ') OR status IS NULL)
        ");
        $stmt_fee->execute([$cur_tenant_id]);
        $fee_income = (float)$stmt_fee->fetchColumn();
    } catch (Throwable $e2) {}
}

$total_income = $fin_income + $fee_income;
$total_expense = $fin_expense;
$net_savings = $total_income - $total_expense;

// 4. Hifz & Qaida Records
$total_hifz = 0;
try {
    $stmt_hifz = $pdo->prepare("SELECT COUNT(*) FROM daily_hifz WHERE tenant_id = ? AND date BETWEEN ? AND ?");
    $stmt_hifz->execute([$cur_tenant_id, $start_date, $end_date]);
    $total_hifz = (int)$stmt_hifz->fetchColumn();
} catch (Throwable $e) {}

$total_qaida = 0;
try {
    $stmt_qaida = $pdo->prepare("SELECT COUNT(*) FROM qaida_records WHERE tenant_id = ? AND date BETWEEN ? AND ?");
    $stmt_qaida->execute([$cur_tenant_id, $start_date, $end_date]);
    $total_qaida = (int)$stmt_qaida->fetchColumn();
} catch (Throwable $e) {}

// 5. Monthly Breakdown for Table & Chart
$month_labels = [];
$month_income_arr = [];
$month_expense_arr = [];
try {
    $stmt_monthly = $pdo->prepare("
        SELECT 
            DATE_FORMAT(date, '%Y-%m') as ym,
            SUM(CASE WHEN LOWER(type) IN ('income', 'آمدن') THEN amount ELSE 0 END) as inc,
            SUM(CASE WHEN LOWER(type) IN ('expense', 'خرچ', 'اخراجات') THEN amount ELSE 0 END) as exp
        FROM finance 
        WHERE tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL) AND $sys_cond_fin AND date BETWEEN ? AND ?
        GROUP BY DATE_FORMAT(date, '%Y-%m')
        ORDER BY ym ASC
    ");
    $stmt_monthly->execute([$cur_tenant_id, $start_date, $end_date]);
    $monthly_fin = $stmt_monthly->fetchAll(PDO::FETCH_ASSOC);

    foreach ($monthly_fin as $mf) {
        $month_labels[] = date('M Y', strtotime($mf['ym'] . '-01'));
        $month_income_arr[] = (float)$mf['inc'];
        $month_expense_arr[] = (float)$mf['exp'];
    }
} catch (Throwable $e) {}
?>

<div class="container-fluid py-3" dir="rtl">
    
    <!-- Filter Card (Hidden in Print) -->
    <div class="card p-3 mb-4 bg-light shadow-sm border-0 rounded-3 d-print-none">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="fw-bold small mb-1">کب سے (From Date):</label>
                <input type="date" name="from" id="annFrom" class="form-control fw-bold" value="<?php echo htmlspecialchars($start_date); ?>" onchange="if(this.value && document.getElementById('annTo').value) this.form.submit();" required>
            </div>
            <div class="col-md-4">
                <label class="fw-bold small mb-1">کب تک (To Date):</label>
                <input type="date" name="to" id="annTo" class="form-control fw-bold" value="<?php echo htmlspecialchars($end_date); ?>" onchange="if(this.value && document.getElementById('annFrom').value) this.form.submit();" required>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary fw-bold w-100"><i class="fas fa-filter me-1"></i> فلٹر کریں</button>
                <button type="button" onclick="window.print()" class="btn btn-dark fw-bold w-100"><i class="fas fa-print me-1"></i> پرنٹ / PDF</button>
            </div>
        </form>
    </div>

    <!-- Printable Report Container -->
    <div id="printableArea" class="bg-white p-3 rounded shadow-sm">
        
        <!-- Header Banner -->
        <div class="d-flex justify-content-between align-items-center border-bottom border-3 border-success pb-3 mb-4">
            <div>
                <h2 class="fw-bold text-success mb-1"><?php echo htmlspecialchars(!empty($_SESSION['tenant_name']) ? $_SESSION['tenant_name'] : t('app_name')); ?></h2>
                <h5 class="text-muted mb-0">سالانہ جامع کارکردگی و مالیاتی رپورٹ (Annual Comprehensive Report)</h5>
            </div>
            <div class="text-end">
                <span class="badge bg-dark fs-6 px-3 py-2">دورانیہ: <?php echo date('d-M-Y', strtotime($start_date)); ?> تا <?php echo date('d-M-Y', strtotime($end_date)); ?></span>
                <p class="text-muted small mb-0 mt-1">رپورٹ تاریخ: <?php echo date('d-M-Y'); ?></p>
            </div>
        </div>

        <!-- Metric KPI Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-3 col-6">
                <div class="card p-3 border-start border-4 border-primary shadow-sm bg-light text-center">
                    <h6 class="text-muted small fw-bold mb-1">کل زیر تعلیم طلبہ</h6>
                    <h3 class="fw-bold text-primary mb-0"><?php echo number_format($total_students); ?></h3>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card p-3 border-start border-4 border-indigo shadow-sm bg-light text-center">
                    <h6 class="text-muted small fw-bold mb-1">کل اساتذہ کرام</h6>
                    <h3 class="fw-bold text-indigo mb-0"><?php echo number_format($total_teachers); ?></h3>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card p-3 border-start border-4 border-info shadow-sm bg-light text-center">
                    <h6 class="text-muted small fw-bold mb-1">اوسط سالانہ حاضری</h6>
                    <h3 class="fw-bold text-info mb-0"><?php echo $avg_attendance; ?>%</h3>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card p-3 border-start border-4 <?php echo $net_savings >= 0 ? 'border-success' : 'border-danger'; ?> shadow-sm bg-light text-center">
                    <h6 class="text-muted small fw-bold mb-1"><?php echo $net_savings >= 0 ? 'خالص سالانہ بچت' : 'خالص سالانہ خسارہ'; ?></h6>
                    <h3 class="fw-bold <?php echo $net_savings >= 0 ? 'text-success' : 'text-danger'; ?> mb-0"><span dir="ltr">Rs. <?php echo number_format(abs($net_savings)); ?></span></h3>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-3 col-6">
                <div class="card p-3 border-start border-4 border-success shadow-sm bg-light text-center">
                    <h6 class="text-muted small fw-bold mb-1">کل آمدن / فیس وصولی</h6>
                    <h4 class="fw-bold text-success mb-0">Rs. <?php echo number_format($total_income); ?></h4>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card p-3 border-start border-4 border-danger shadow-sm bg-light text-center">
                    <h6 class="text-muted small fw-bold mb-1">کل اخراجات</h6>
                    <h4 class="fw-bold text-danger mb-0">Rs. <?php echo number_format($total_expense); ?></h4>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card p-3 border-start border-4 border-warning shadow-sm bg-light text-center">
                    <h6 class="text-muted small fw-bold mb-1">حفظ یومیہ ریکارڈز</h6>
                    <h4 class="fw-bold text-warning text-dark mb-0"><?php echo number_format($total_hifz); ?></h4>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card p-3 border-start border-4 border-secondary shadow-sm bg-light text-center">
                    <h6 class="text-muted small fw-bold mb-1">قاعدہ و ناظرہ ریکارڈز</h6>
                    <h4 class="fw-bold text-secondary mb-0"><?php echo number_format($total_qaida); ?></h4>
                </div>
            </div>
        </div>

        <!-- Monthly Finance Chart (Hidden in Print) -->
        <?php if (!empty($month_labels)): ?>
        <div class="card shadow-sm border-0 mb-4 d-print-none">
            <div class="card-header bg-dark text-white fw-bold py-2">
                <i class="fas fa-chart-bar me-2"></i> ماہانہ آمدن بمقابلہ اخراجات کا گراف
            </div>
            <div class="card-body p-3" style="height: 250px;">
                <canvas id="annualFinanceChart"></canvas>
            </div>
        </div>
        <?php endif; ?>

        <!-- Detailed Summary Table -->
        <h5 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fas fa-table me-2 text-success"></i> تفصیلی سالانہ گوشوارہ</h5>
        <div class="table-responsive">
            <table class="table table-bordered table-striped text-center align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>شمار</th>
                        <th>تفصیل / شعبہ</th>
                        <th>شرح / تعداد / رقم</th>
                        <th>کیفیت</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>1</td>
                        <td class="text-start ps-3 fw-bold">کل طلبہ کی تعداد (Active Students)</td>
                        <td class="fw-bold text-primary"><?php echo number_format($total_students); ?></td>
                        <td>مدرسہ و مکتب</td>
                    </tr>
                    <tr>
                        <td>2</td>
                        <td class="text-start ps-3 fw-bold">کل اساتذہ و عملہ (Staff)</td>
                        <td class="fw-bold text-indigo"><?php echo number_format($total_teachers); ?></td>
                        <td>تدریسی عملہ</td>
                    </tr>
                    <tr>
                        <td>3</td>
                        <td class="text-start ps-3 fw-bold">مجموعی اوسط حاضری شرح</td>
                        <td class="fw-bold text-info"><?php echo $avg_attendance; ?>%</td>
                        <td><?php echo $avg_attendance >= 80 ? 'بہترین' : 'مناسب'; ?></td>
                    </tr>
                    <tr>
                        <td>4</td>
                        <td class="text-start ps-3 fw-bold">کل مالیاتی آمدن و چندہ جات</td>
                        <td class="fw-bold text-success">Rs. <?php echo number_format($total_income); ?></td>
                        <td>وصول شدہ</td>
                    </tr>
                    <tr>
                        <td>5</td>
                        <td class="text-start ps-3 fw-bold">کل مالیاتی اخراجات</td>
                        <td class="fw-bold text-danger">Rs. <?php echo number_format($total_expense); ?></td>
                        <td>خرچ شدہ</td>
                    </tr>
                    <tr class="<?php echo $net_savings >= 0 ? 'table-success' : 'table-danger'; ?>">
                        <td>6</td>
                        <td class="text-start ps-3 fw-bold"><?php echo $net_savings >= 0 ? 'خالص بچت / بیلنس' : 'خالص خسارہ'; ?></td>
                        <td class="fw-bold fs-5 <?php echo $net_savings >= 0 ? 'text-success' : 'text-danger'; ?>"><span dir="ltr">Rs. <?php echo number_format(abs($net_savings)); ?></span></td>
                        <td class="fw-bold <?php echo $net_savings >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo $net_savings >= 0 ? 'مثبت بچت' : 'خسارہ (Deficit)'; ?></td>
                    </tr>
                    <tr>
                        <td>7</td>
                        <td class="text-start ps-3 fw-bold">حفظ قرآن کریم تعلیمی اندراجات</td>
                        <td class="fw-bold"><?php echo number_format($total_hifz); ?></td>
                        <td>روزانہ سبق، سبقی، منزل</td>
                    </tr>
                    <tr>
                        <td>8</td>
                        <td class="text-start ps-3 fw-bold">قاعدہ و ناظرہ تعلیمی اندراجات</td>
                        <td class="fw-bold"><?php echo number_format($total_qaida); ?></td>
                        <td>روزانہ اسباق و پیش رفت</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Signature Area for Print -->
        <div class="row mt-5 pt-4 text-center d-flex justify-content-between">
            <div class="col-4">
                <hr class="border-dark">
                <p class="fw-bold mb-0">دستخط ناظم مالیات</p>
            </div>
            <div class="col-4">
                <hr class="border-dark">
                <p class="fw-bold mb-0">دستخط ناظم تعلیمات</p>
            </div>
            <div class="col-4">
                <hr class="border-dark">
                <p class="fw-bold mb-0">دستخط مہتمم صاحب / مہر ادارہ</p>
            </div>
        </div>

    </div>

</div>

<style>
@media print {
    body * { visibility: hidden; }
    #printableArea, #printableArea * { visibility: visible; }
    #printableArea { position: absolute; left: 0; top: 0; width: 100%; }
    .d-print-none { display: none !important; }
    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const labels = <?php echo json_encode($month_labels); ?>;
    const incData = <?php echo json_encode($month_income_arr); ?>;
    const expData = <?php echo json_encode($month_expense_arr); ?>;
    const ctx = document.getElementById('annualFinanceChart');

    if (ctx && labels.length > 0) {
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'آمدن (Income)',
                        data: incData,
                        backgroundColor: '#284D3D',
                        borderRadius: 4
                    },
                    {
                        label: 'اخراجات (Expense)',
                        data: expData,
                        backgroundColor: '#d32f2f',
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        ticks: {
                            callback: function(value) { return 'Rs ' + value.toLocaleString(); }
                        }
                    }
                }
            }
        });
    }
});
</script>

<?php include 'footer.php'; ?>
