<?php 
require_once 'db.php'; 
include 'header.php';

$sys = $_SESSION['system_type'] ?? 'Madrasa';

if (!has_perm('manage_finance')) {
    echo "<script>window.location='dashboard.php';</script>";
    exit;
}

$role = $_SESSION['role'] ?? 'guest';
$current_user_id = $_SESSION['user_id'] ?? 1;

$start_date = isset($_GET['from']) ? $_GET['from'] : date('Y-m-01');
$end_date = isset($_GET['to']) ? $_GET['to'] : date('Y-m-d');
$type_filter = isset($_GET['type']) ? $_GET['type'] : 'All';

// Fetch transactions filtered by system_type
if ($role === 'admin') {
    $sql = "SELECT f.*, h.name AS head_name FROM finance f LEFT JOIN heads h ON h.id = f.head_id WHERE (f.system_type = ? OR f.system_type IS NULL OR f.system_type = '') AND f.date BETWEEN ? AND ? AND f.is_deleted = 0 ORDER BY f.user_id, f.date ASC, f.id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$sys, $start_date, $end_date]);
} else {
    $sql = "SELECT f.*, h.name AS head_name FROM finance f LEFT JOIN heads h ON h.id = f.head_id WHERE (f.system_type = ? OR f.system_type IS NULL OR f.system_type = '') AND f.date BETWEEN ? AND ? AND f.is_deleted = 0 AND f.user_id = ? ORDER BY f.date ASC, f.id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$sys, $start_date, $end_date, $current_user_id]);
}
$all_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by User
$by_user = [];
foreach ($all_rows as $row) {
    $uid = $row['user_id'] ?? 0;
    $uname = $row['user_name'] ?? 'نامعلوم';
    if (!isset($by_user[$uid])) {
        $by_user[$uid] = ['name' => $uname, 'income' => [], 'expense' => []];
    }
    if (strtolower((string)$row['type']) === 'expense') {
        $by_user[$uid]['expense'][] = $row;
    } else {
        $by_user[$uid]['income'][] = $row;
    }
}

// Grand Totals
$grand_inc = 0; $grand_exp = 0;
foreach ($by_user as $u) {
    foreach ($u['income'] as $r) $grand_inc += (float)$r['amount'];
    foreach ($u['expense'] as $r) $grand_exp += (float)$r['amount'];
}
?>

<div class="d-print-none card p-3 mb-4 bg-light shadow-sm">
    <h4 class="mb-3 text-primary fw-bold">
        <i class="fas fa-file-invoice-dollar me-2"></i> 
        <?php echo ($sys === 'Masjid') ? 'فنانس رپورٹ (مسجد)' : 'فنانس رپورٹ (مدرسہ)'; ?>
    </h4>
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="fw-bold">تاریخ (ابتداء)</label>
            <input type="date" name="from" class="form-control" value="<?php echo $start_date; ?>">
        </div>
        <div class="col-md-3">
            <label class="fw-bold">تاریخ (اختتام)</label>
            <input type="date" name="to" class="form-control" value="<?php echo $end_date; ?>">
        </div>
        <div class="col-md-3">
            <label class="fw-bold">قسم</label>
            <select name="type" class="form-select">
                <option value="All" <?php echo ($type_filter=='All')?'selected':''; ?>>مکمل (All)</option>
                <option value="Income" <?php echo ($type_filter=='Income')?'selected':''; ?>>آمدن (Income)</option>
                <option value="Expense" <?php echo ($type_filter=='Expense')?'selected':''; ?>><?php echo t('type_expense'); ?></option>
            </select>
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-primary w-100 mb-1 fw-bold">تلاش کریں</button>
            <div class="d-flex gap-1 mb-1 d-print-none">
                <button type="button" onclick="toggleAllIncome()" class="btn btn-outline-success btn-sm flex-fill fw-bold"><i class="fas fa-eye me-1"></i> آمدن</button>
                <button type="button" onclick="toggleAllExpense()" class="btn btn-outline-danger btn-sm flex-fill fw-bold"><i class="fas fa-eye me-1"></i> خرچ</button>
            </div>
            <button type="button" onclick="window.print()" class="btn btn-dark w-100 fw-bold">پرنٹ کریں</button>
        </div>
    </form>
</div>

<div id="printableArea" class="bg-white p-4 border rounded">
    <div class="text-center border-bottom border-2 border-dark mb-4 pb-2">
        <h2 class="fw-bold text-success m-0"><?php echo ($sys === 'Masjid') ? 'مسجد انتظامیہ' : 'مدرسہ اشرف المدارس'; ?></h2>
        <h4 class="mt-2 text-uppercase fw-bold">فنانس رپورٹ (یوزر وائز) - [<?php echo htmlspecialchars($sys); ?>]</h4>
        <p class="text-muted mb-1">
            مدت: <strong><?php echo date('d-m-Y', strtotime($start_date)); ?></strong> تا <strong><?php echo date('d-m-Y', strtotime($end_date)); ?></strong>
        </p>
    </div>

    <?php if (empty($by_user)): ?>
        <div class="alert alert-info text-center">کوئی مالیاتی ریکارڈ موجود نہیں ہے۔</div>
    <?php else: ?>
        <?php foreach ($by_user as $uid => $user_data): ?>
        <?php
            $u_inc = array_sum(array_column($user_data['income'], 'amount'));
            $u_exp = array_sum(array_column($user_data['expense'], 'amount'));
            $u_bal = $u_inc - $u_exp;
        ?>
        <div class="mb-4 border rounded p-3">
            <!-- یوزر ہیڈر (High Contrast Badges) -->
            <div class="finance-user-header d-flex justify-content-between align-items-center bg-dark text-white p-2 rounded mb-3">
                <h5 class="finance-user-name mb-0 text-white"><i class="fas fa-user-circle me-2"></i> <?php echo htmlspecialchars($user_data['name']); ?></h5>
                <div class="d-flex align-items-center gap-1">
                    <span class="badge badge-inc-box">آمدن: <?php echo number_format($u_inc); ?></span>
                    <span class="badge badge-exp-box">خرچ: <?php echo number_format($u_exp); ?></span>
                    <span class="badge badge-bal-box">بچت: <?php echo number_format($u_bal); ?></span>
                </div>
            </div>

            <!-- آمدن کی تفصیل (Expandable / Collapsible) -->
            <?php if (($type_filter == 'All' || $type_filter == 'Income') && !empty($user_data['income'])): ?>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="text-success fw-bold m-0"><i class="fas fa-arrow-down me-1"></i> آمدن (Income)</h6>
                <button type="button" class="btn btn-sm btn-outline-success border-0 fw-bold d-print-none" onclick="toggleFinSection('inc_sec_<?php echo $uid; ?>', this)"><i class="fas fa-chevron-up me-1"></i> بند کریں</button>
            </div>
            <div id="inc_sec_<?php echo $uid; ?>">
                <table class="table table-sm table-bordered mb-3">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>تاریخ</th>
                            <th>ہیڈ (معد)</th>
                            <th>تفصیل</th>
                            <th class="text-end">رقم</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $inc_idx = 1; foreach ($user_data['income'] as $inc): ?>
                        <tr>
                            <td><?php echo $inc_idx++; ?></td>
                            <td><?php echo date('d-m-Y', strtotime($inc['date'])); ?></td>
                            <td><?php echo htmlspecialchars($inc['head_name'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($inc['description'] ?? ''); ?></td>
                            <td class="text-end text-success fw-bold"><?php echo number_format($inc['amount']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- اخراجات کی تفصیل (Expandable / Collapsible) -->
            <?php if (($type_filter == 'All' || $type_filter == 'Expense') && !empty($user_data['expense'])): ?>
            <div class="d-flex justify-content-between align-items-center mb-2 mt-3">
                <h6 class="text-danger fw-bold m-0"><i class="fas fa-arrow-up me-1"></i> اخراجات (Expenses)</h6>
                <button type="button" class="btn btn-sm btn-outline-danger border-0 fw-bold d-print-none" onclick="toggleFinSection('exp_sec_<?php echo $uid; ?>', this)"><i class="fas fa-chevron-up me-1"></i> بند کریں</button>
            </div>
            <div id="exp_sec_<?php echo $uid; ?>">
                <table class="table table-sm table-bordered mb-3">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>تاریخ</th>
                            <th>ہیڈ (معد)</th>
                            <th>تفصیل</th>
                            <th class="text-end">رقم</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $exp_idx = 1; foreach ($user_data['expense'] as $exp): ?>
                        <tr>
                            <td><?php echo $exp_idx++; ?></td>
                            <td><?php echo date('d-m-Y', strtotime($exp['date'])); ?></td>
                            <td><?php echo htmlspecialchars($exp['head_name'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($exp['description'] ?? ''); ?></td>
                            <td class="text-end text-danger fw-bold"><?php echo number_format($exp['amount']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($role === 'admin' && !empty($by_user)): ?>
    <!-- گرینڈ ٹوٹل سمری -->
    <div class="row mt-4 avoid-break">
        <div class="col-md-6 ms-auto">
            <div class="card border-dark">
                <div class="card-header bg-dark text-white text-center fw-bold">حتمی خلاصہ - تمام یوزرز (Grand Summary)</div>
                <div class="card-body p-0">
                    <table class="table table-bordered mb-0">
                        <?php if($type_filter == 'All' || $type_filter == 'Income'): ?>
                        <tr>
                            <td class="text-success fw-bold ps-3 fs-5">کل آمدن (Total Income)</td>
                            <td class="text-end pe-3 fs-5 fw-bold"><?php echo number_format($grand_inc); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if($type_filter == 'All' || $type_filter == 'Expense'): ?>
                        <tr>
                            <td class="text-danger fw-bold ps-3 fs-5">کل خرچ (Total Expense)</td>
                            <td class="text-end pe-3 fs-5 fw-bold"><?php echo number_format($grand_exp); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if($type_filter == 'All'): ?>
                        <tr class="bg-light">
                            <th class="text-primary ps-3 fs-4">بچت / موجودہ بیلنس</th>
                            <th class="text-end pe-3 fs-4"><?php echo number_format($grand_inc - $grand_exp); ?></th>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row mt-5 pt-5 text-center avoid-break">
        <div class="col-4 border-top border-dark pt-2 fw-bold">اکاؤنٹنٹ</div>
        <div class="col-4"></div>
        <div class="col-4 border-top border-dark pt-2 fw-bold">مہتمم / ناظم</div>
    </div>
    <div class="text-center mt-4 small text-muted">
        تاریخ تیاری: <?php echo date('d-m-Y H:i A'); ?>
    </div>
</div>

<style>
#printableArea { font-family: 'Noto Nastaliq Urdu', serif !important; font-style: normal !important; }
.badge-inc-box { background-color: #d1e7dd !important; color: #0f5132 !important; font-weight: 800 !important; font-size: 0.95rem !important; border: 1px solid #0f5132 !important; }
.badge-exp-box { background-color: #f8d7da !important; color: #842029 !important; font-weight: 800 !important; font-size: 0.95rem !important; border: 1px solid #842029 !important; }
.badge-bal-box { background-color: #cff4fc !important; color: #055160 !important; font-weight: 800 !important; font-size: 0.95rem !important; border: 1px solid #055160 !important; }

@media print {
    @page { size: A4 portrait; margin: 10mm; }
    body * { visibility: hidden; }
    #printableArea, #printableArea * { visibility: visible; }
    #printableArea { position: absolute; left: 0; top: 0; width: 100%; border: none !important; }
    .d-print-none, .sidebar, .navbar { display: none !important; }
    body { -webkit-print-color-adjust: exact; }
    table { width: 100% !important; border-collapse: collapse; }
    th, td { border: 1px solid #000 !important; padding: 5px; font-style: normal !important; }
    .avoid-break { page-break-inside: avoid; }
    .border { border: 1px solid #999 !important; }
}
</style>

<script>
function toggleFinSection(id, btn) {
    var el = document.getElementById(id);
    if (!el) return;
    if (el.style.display === 'none') {
        el.style.display = 'block';
        if (btn) btn.innerHTML = '<i class="fas fa-chevron-up me-1"></i> بند کریں';
    } else {
        el.style.display = 'none';
        if (btn) btn.innerHTML = '<i class="fas fa-chevron-down me-1"></i> کھولیں';
    }
}
function toggleAllIncome() {
    var secs = document.querySelectorAll('[id^="inc_sec_"]');
    secs.forEach(function(s) {
        s.style.display = (s.style.display === 'none') ? 'block' : 'none';
    });
}
function toggleAllExpense() {
    var secs = document.querySelectorAll('[id^="exp_sec_"]');
    secs.forEach(function(s) {
        s.style.display = (s.style.display === 'none') ? 'block' : 'none';
    });
}
</script>

<?php include 'footer.php'; ?>
