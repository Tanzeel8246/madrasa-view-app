<?php 
require_once 'db.php'; 
include 'header.php';

$sys = $_SESSION['system_type'] ?? 'Madrasa';

$role = $_SESSION['role'] ?? 'guest';
$is_admin = in_array(strtolower(trim((string)$role)), ['admin', 'superadmin', 'super_admin', 'administrator']);

if (!$is_admin && $role !== 'nazim' && function_exists('has_perm') && !has_perm('manage_finance') && !has_perm('manage_finance_report') && !has_perm('manage_financial_reports')) {
    echo "<script>window.location='dashboard.php';</script>";
    exit;
}

$current_user_id = $_SESSION['user_id'] ?? 1;

$start_date = isset($_GET['from']) ? $_GET['from'] : date('Y-m-01');
$end_date = isset($_GET['to']) ? $_GET['to'] : date('Y-m-d');
$type_filter = isset($_GET['type']) ? $_GET['type'] : 'All';

$cur_tenant_id = (int)get_current_tenant_id();

// Dynamic column checking to prevent SQL errors on unmigrated schemas
$fin_cols = [];
try {
    $fin_cols = $pdo->query("SHOW COLUMNS FROM finance")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

$has_f_tenant  = in_array('tenant_id', $fin_cols);
$has_f_sys     = in_array('system_type', $fin_cols);
$has_f_del     = in_array('is_deleted', $fin_cols);
$has_f_user_id = in_array('user_id', $fin_cols);

$t_cond_f = ($has_f_tenant)
    ? (($cur_tenant_id == 1) ? "(f.tenant_id = 1 OR f.tenant_id IS NULL OR f.tenant_id = 0)" : "f.tenant_id = $cur_tenant_id")
    : "1=1";

$sys_filter = ($has_f_sys)
    ? (($sys === 'Masjid') ? "f.system_type = 'Masjid'" : "(f.system_type = 'Madrasa' OR f.system_type IS NULL OR f.system_type = '')")
    : "1=1";

$del_cond_f = ($has_f_del) ? "(f.is_deleted = 0 OR f.is_deleted IS NULL)" : "1=1";

$type_clause = "";
if ($type_filter === 'Income') {
    $type_clause = " AND f.type = 'Income'";
} elseif ($type_filter === 'Expense') {
    $type_clause = " AND f.type = 'Expense'";
}

$selected_user_id = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : -1;

// Fetch unique users for filtering
$users_list = [];
if ($has_f_user_id) {
    try {
        $u_stmt = $pdo->prepare("SELECT DISTINCT f.user_id, COALESCE(NULLIF(f.user_name, ''), CONCAT('صارف #', f.user_id)) AS user_name 
                                  FROM finance f 
                                  WHERE $sys_filter AND $t_cond_f AND $del_cond_f AND f.user_id IS NOT NULL 
                                  ORDER BY user_name ASC");
        $u_stmt->execute();
        $users_list = $u_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $eU) {}
}

$order_by = ($has_f_user_id) ? "ORDER BY f.user_id, f.date ASC, f.id ASC" : "ORDER BY f.date ASC, f.id ASC";

$all_rows = [];
try {
    if ($is_admin) {
        $user_clause = ($selected_user_id >= 0 && $has_f_user_id) ? " AND f.user_id = ?" : "";
        $params = ($selected_user_id >= 0 && $has_f_user_id) ? [$start_date, $end_date, $selected_user_id] : [$start_date, $end_date];
        $sql = "SELECT f.*, h.name AS head_name FROM finance f LEFT JOIN heads h ON h.id = f.head_id WHERE $sys_filter AND f.date BETWEEN ? AND ? AND $t_cond_f AND $del_cond_f $user_clause $type_clause $order_by";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } else {
        $user_clause = $has_f_user_id ? " AND f.user_id = ?" : "";
        $params = $has_f_user_id ? [$start_date, $end_date, $current_user_id] : [$start_date, $end_date];
        $sql = "SELECT f.*, h.name AS head_name FROM finance f LEFT JOIN heads h ON h.id = f.head_id WHERE $sys_filter AND f.date BETWEEN ? AND ? AND $t_cond_f AND $del_cond_f $user_clause $type_clause $order_by";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }
    $all_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e1) {
    try {
        // Fallback without heads join
        if ($is_admin) {
            $user_clause = ($selected_user_id >= 0 && $has_f_user_id) ? " AND user_id = ?" : "";
            $params = ($selected_user_id >= 0 && $has_f_user_id) ? [$start_date, $end_date, $selected_user_id] : [$start_date, $end_date];
        } else {
            $user_clause = $has_f_user_id ? " AND user_id = ?" : "";
            $params = $has_f_user_id ? [$start_date, $end_date, $current_user_id] : [$start_date, $end_date];
        }
        $stmt = $pdo->prepare("SELECT * FROM finance WHERE date BETWEEN ? AND ? $user_clause ORDER BY date ASC, id ASC");
        $stmt->execute($params);
        $all_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) {
        try {
            // Absolute bare fallback
            $stmt = $pdo->prepare("SELECT * FROM finance WHERE date BETWEEN ? AND ?");
            $stmt->execute([$start_date, $end_date]);
            $all_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e3) {
            $all_rows = [];
        }
    }
}

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
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="text-primary fw-bold mb-0">
            <i class="fas fa-file-invoice-dollar me-2"></i> 
            <?php echo ($sys === 'Masjid') ? 'فنانس رپورٹ (مسجد)' : 'فنانس رپورٹ (مدرسہ)'; ?>
        </h4>
    </div>

    <!-- Responsive Report Type Tabs (Single Row Scrollable) -->
    <ul class="nav nav-pills mb-3 d-print-none flex-nowrap overflow-x-auto text-nowrap border-bottom pb-2" id="reportTypeTabs" style="overflow-x: auto; white-space: nowrap; -webkit-overflow-scrolling: touch; scrollbar-width: thin;">
        <li class="nav-item me-2" style="flex: 0 0 auto;">
            <a class="nav-link fw-bold px-3 py-2 <?php echo ($type_filter=='All')?'active bg-primary text-white shadow-sm':'text-dark bg-white border'; ?>" href="?from=<?php echo $start_date; ?>&to=<?php echo $end_date; ?>&type=All<?php echo ($selected_user_id >= 0) ? '&user_id='.$selected_user_id : ''; ?>" style="white-space: nowrap;">
                <i class="fas fa-list me-1"></i> مکمل رپورٹ (All)
            </a>
        </li>
        <li class="nav-item me-2" style="flex: 0 0 auto;">
            <a class="nav-link fw-bold px-3 py-2 <?php echo ($type_filter=='Income')?'active bg-success text-white shadow-sm':'text-dark bg-white border'; ?>" href="?from=<?php echo $start_date; ?>&to=<?php echo $end_date; ?>&type=Income<?php echo ($selected_user_id >= 0) ? '&user_id='.$selected_user_id : ''; ?>" style="white-space: nowrap;">
                <i class="fas fa-arrow-down me-1 text-success"></i> آمدن رپورٹ (Income)
            </a>
        </li>
        <li class="nav-item" style="flex: 0 0 auto;">
            <a class="nav-link fw-bold px-3 py-2 <?php echo ($type_filter=='Expense')?'active bg-danger text-white shadow-sm':'text-dark bg-white border'; ?>" href="?from=<?php echo $start_date; ?>&to=<?php echo $end_date; ?>&type=Expense<?php echo ($selected_user_id >= 0) ? '&user_id='.$selected_user_id : ''; ?>" style="white-space: nowrap;">
                <i class="fas fa-arrow-up me-1 text-danger"></i> اخراجات رپورٹ (Expense)
            </a>
        </li>
    </ul>

    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-2 col-6">
            <label class="fw-bold">تاریخ (ابتداء)</label>
            <input type="date" name="from" id="finFromDate" class="form-control" value="<?php echo $start_date; ?>" onchange="if(this.value && document.getElementById('finToDate').value) this.form.submit();">
        </div>
        <div class="col-md-2 col-6">
            <label class="fw-bold">تاریخ (اختتام)</label>
            <input type="date" name="to" id="finToDate" class="form-control" value="<?php echo $end_date; ?>" onchange="if(this.value && document.getElementById('finFromDate').value) this.form.submit();">
        </div>
        <div class="col-md-2 col-6">
            <label class="fw-bold">قسم</label>
            <select name="type" class="form-select" onchange="this.form.submit()">
                <option value="All" <?php echo ($type_filter=='All')?'selected':''; ?>>مکمل (All)</option>
                <option value="Income" <?php echo ($type_filter=='Income')?'selected':''; ?>>آمدن (Income)</option>
                <option value="Expense" <?php echo ($type_filter=='Expense')?'selected':''; ?>><?php echo t('type_expense'); ?></option>
            </select>
        </div>
        <div class="col-md-3 col-6">
            <label class="fw-bold">صارف / کیشیئر (User)</label>
            <select name="user_id" class="form-select" onchange="this.form.submit()">
                <option value="" <?php echo ($selected_user_id < 0)?'selected':''; ?>>تمام صارفین (All Users)</option>
                <?php foreach ($users_list as $u_item): ?>
                    <option value="<?php echo $u_item['user_id']; ?>" <?php echo ($selected_user_id == $u_item['user_id'])?'selected':''; ?>>
                        <?php echo htmlspecialchars($u_item['user_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 col-12">
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
            <?php 
            if ($selected_user_id >= 0) {
                $sel_name = 'نامعلوم';
                foreach ($users_list as $ul) {
                    if ($ul['user_id'] == $selected_user_id) { $sel_name = $ul['user_name']; break; }
                }
                echo " | صارف / کیشیئر: <span class='badge bg-dark text-white px-2 py-1'>" . htmlspecialchars($sel_name) . "</span>";
            }
            ?>
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
                    <?php if ($u_bal >= 0): ?>
                        <span class="badge badge-bal-box">بچت: Rs. <?php echo number_format($u_bal); ?></span>
                    <?php else: ?>
                        <span class="badge badge-bal-box" style="background-color: #dc3545 !important; color: #ffffff !important; border: 1px solid #dc3545 !important;">خسارہ: Rs. <?php echo number_format(abs($u_bal)); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- آمدن کی تفصیل (Expandable / Collapsible) -->
            <?php if (($type_filter == 'All' || $type_filter == 'Income') && !empty($user_data['income'])): ?>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="text-success fw-bold m-0"><i class="fas fa-arrow-down me-1"></i> آمدن (Income)</h6>
                <button type="button" class="btn btn-sm btn-outline-success border-0 fw-bold d-print-none" onclick="toggleFinSection('inc_sec_<?php echo $uid; ?>', this)"><i class="fas fa-chevron-up me-1"></i> بند کریں</button>
            </div>
            <div id="inc_sec_<?php echo $uid; ?>">
                <div class="table-responsive">
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
            </div>
            <?php endif; ?>

            <!-- اخراجات کی تفصیل (Expandable / Collapsible) -->
            <?php if (($type_filter == 'All' || $type_filter == 'Expense') && !empty($user_data['expense'])): ?>
            <div class="d-flex justify-content-between align-items-center mb-2 mt-3">
                <h6 class="text-danger fw-bold m-0"><i class="fas fa-arrow-up me-1"></i> اخراجات (Expenses)</h6>
                <button type="button" class="btn btn-sm btn-outline-danger border-0 fw-bold d-print-none" onclick="toggleFinSection('exp_sec_<?php echo $uid; ?>', this)"><i class="fas fa-chevron-up me-1"></i> بند کریں</button>
            </div>
            <div id="exp_sec_<?php echo $uid; ?>">
                <div class="table-responsive">
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
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($is_admin && !empty($by_user)): ?>
    <!-- گرینڈ ٹوٹل سمری -->
    <div class="row mt-4 avoid-break">
        <div class="col-md-6 ms-auto">
            <div class="card border-dark">
                <div class="card-header bg-dark text-white text-center fw-bold">حتمی خلاصہ - تمام یوزرز (Grand Summary)</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
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
                            <?php $grand_diff = $grand_inc - $grand_exp; ?>
                            <tr class="bg-light">
                                <?php if($grand_diff >= 0): ?>
                                    <th class="text-success ps-3 fs-4">بچت / موجودہ بیلنس</th>
                                    <th class="text-end pe-3 fs-4 text-success"><span dir="ltr">Rs. <?php echo number_format($grand_diff); ?></span></th>
                                <?php else: ?>
                                    <th class="text-danger ps-3 fs-4">خسارہ (Deficit)</th>
                                    <th class="text-end pe-3 fs-4 text-danger"><span dir="ltr">Rs. <?php echo number_format(abs($grand_diff)); ?></span></th>
                                <?php endif; ?>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
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
