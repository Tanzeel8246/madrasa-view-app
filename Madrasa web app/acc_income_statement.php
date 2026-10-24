<?php 
require_once 'db.php'; 
require_once 'finance_accounting_bridge.php';
include 'header.php'; 

// سیکیورٹی چیک
if (!isset($_SESSION['user_id'])) { 
    echo "<script>window.location='login.php';</script>"; 
    exit; 
}
$u_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin = in_array($u_role, ['admin', 'superadmin', 'super_admin', 'administrator'], true);
if (!$is_admin && !app_has_perm('manage_erp')) { 
    echo "<script>window.location='dashboard.php';</script>"; 
    exit; 
}

$from_date = $_GET['from'] ?? date('Y-m-01');
$to_date = $_GET['to'] ?? date('Y-m-t');
$dept_id = !empty($_GET['department_id']) ? (int)$_GET['department_id'] : null;

$cur_tenant_id = (int)get_current_tenant_id($pdo);
$sys = $_SESSION['system_type'] ?? 'Madrasa';
$sys_cond = ($sys === 'Masjid') ? "v.system_type = 'Masjid'" : "(v.system_type = 'Madrasa' OR v.system_type IS NULL OR v.system_type = '')";
finance_accounting_backfill($pdo, $from_date, $to_date, $cur_tenant_id);

$cur_tenant_id = (int)get_current_tenant_id($pdo);

// Fetch Departments
$departments = $pdo->query("SELECT id, name FROM departments WHERE status = 'Active'")->fetchAll(PDO::FETCH_ASSOC);

// Prepare Query
$params = [$cur_tenant_id, $cur_tenant_id, $from_date, $to_date, $cur_tenant_id];
$dept_sql = "";
if ($dept_id) {
    $dept_sql = " AND e.department_id = ? ";
    $params[] = $dept_id;
}

$query = "
    SELECT a.id, a.code, a.name, a.type, 
           SUM(e.debit) as total_debit, 
           SUM(e.credit) as total_credit
    FROM acc_chart_of_accounts a
    JOIN acc_ledger_entries e ON a.id = e.account_id AND e.tenant_id = ?
    JOIN acc_vouchers v ON e.voucher_id = v.id AND v.status IN ('Approved', 'Reversed') AND (v.is_deleted = 0 OR v.is_deleted IS NULL) AND v.tenant_id = ? AND $sys_cond
    WHERE a.type IN ('Revenue', 'Expense') AND v.date BETWEEN ? AND ? AND (a.is_deleted = 0 OR a.is_deleted IS NULL) AND a.tenant_id = ?
    $dept_sql
    GROUP BY a.id, a.code, a.name, a.type
    ORDER BY a.type DESC, a.code
"; // Revenue first, then Expense

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_revenue = 0;
$total_expense = 0;

$revenues = [];
$expenses = [];

foreach($accounts as $acc) {
    // Normal balance for Revenue is Credit
    // Normal balance for Expense is Debit
    if ($acc['type'] == 'Revenue') {
        $bal = $acc['total_credit'] - $acc['total_debit'];
        if ($bal != 0) {
            $revenues[] = ['name' => $acc['name'], 'balance' => $bal];
            $total_revenue += $bal;
        }
    } else if ($acc['type'] == 'Expense') {
        $bal = $acc['total_debit'] - $acc['total_credit'];
        if ($bal != 0) {
            $expenses[] = ['name' => $acc['name'], 'balance' => $bal];
            $total_expense += $bal;
        }
    }
}

$net_income = $total_revenue - $total_expense;
?>

<div class="container py-4">
    <div class="d-print-none card p-3 mb-4 bg-light shadow-sm border-top border-5 border-success">
        <h4 class="mb-3 text-success fw-bold"><i class="fas fa-chart-pie me-2"></i> Income Statement (آمدن و اخراجات)</h4>
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="fw-bold"><?php echo ($lang=='ur') ? 'تاریخ سے' : 'From Date'; ?></label>
                <input type="date" name="from" id="incFrom" class="form-control" value="<?php echo e($from_date); ?>" onchange="if(this.value && document.getElementById('incTo').value) this.form.submit();" required>
            </div>
            <div class="col-md-3">
                <label class="fw-bold"><?php echo ($lang=='ur') ? 'تاریخ تک' : 'To Date'; ?></label>
                <input type="date" name="to" id="incTo" class="form-control" value="<?php echo e($to_date); ?>" onchange="if(this.value && document.getElementById('incFrom').value) this.form.submit();" required>
            </div>
            <div class="col-md-3">
                <label class="fw-bold"><?php echo ($lang=='ur') ? 'شعبہ' : 'Department'; ?></label>
                <select name="department_id" class="form-select" onchange="this.form.submit()">
                    <option value=""><?php echo ($lang=='ur') ? '-- تمام شعبہ جات --' : '-- All Departments --'; ?></option>
                    <?php foreach($departments as $d): ?>
                        <option value="<?php echo $d['id']; ?>" <?php echo $dept_id == $d['id'] ? 'selected' : ''; ?>><?php echo $d['name']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-success flex-grow-1"><i class="fas fa-search"></i></button>
                <button type="button" onclick="window.print()" class="btn btn-dark flex-grow-1"><i class="fas fa-print"></i></button>
            </div>
        </form>
    </div>

    <div id="printableArea" class="bg-white p-5 border rounded shadow-sm">
        <div class="text-center border-bottom border-2 border-dark mb-4 pb-3">
            <?php $inst_brand = !empty($app_settings['madrasa_name']) ? $app_settings['madrasa_name'] : (!empty($_SESSION['tenant_name']) ? $_SESSION['tenant_name'] : 'ادارہ'); ?>
            <h2 class="fw-bold text-success m-0"><?php echo htmlspecialchars($inst_brand); ?></h2>
            <h4 class="mt-2 fw-bold">Income Statement / گوشوارہ آمدن و اخراجات</h4>
            <p class="mb-1 fw-bold fs-6 text-muted">
                Period: <?php echo date('d-M-Y', strtotime($from_date)); ?> to <?php echo date('d-M-Y', strtotime($to_date)); ?>
                <?php 
                if($dept_id) {
                    $dname = array_filter($departments, fn($d) => $d['id'] == $dept_id);
                    echo "<br><span class='text-primary mt-1 d-block'>Department: " . reset($dname)['name'] . "</span>";
                }
                ?>
            </p>
        </div>

        <div class="row g-5">
            <!-- Revenues -->
            <div class="col-md-6 border-end">
                <h4 class="text-success fw-bold border-bottom pb-2 mb-3"><i class="fas fa-arrow-down me-2"></i> آمدن (Revenues)</h4>
                <table class="table table-borderless table-sm fs-5">
                    <tbody>
                        <?php foreach($revenues as $r): ?>
                        <tr>
                            <td><?php echo $r['name']; ?></td>
                            <td class="text-end fw-bold text-success"><?php echo number_format($r['balance'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($revenues)): ?>
                        <tr><td colspan="2" class="text-muted text-center">No revenue entries</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="border-top border-2">
                        <tr>
                            <th class="fs-5">Total Revenue:</th>
                            <th class="text-end fs-4 text-success"><?php echo number_format($total_revenue, 2); ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Expenses -->
            <div class="col-md-6">
                <h4 class="text-danger fw-bold border-bottom pb-2 mb-3"><i class="fas fa-arrow-up me-2"></i> اخراجات (Expenses)</h4>
                <table class="table table-borderless table-sm fs-5">
                    <tbody>
                        <?php foreach($expenses as $e): ?>
                        <tr>
                            <td><?php echo $e['name']; ?></td>
                            <td class="text-end fw-bold text-danger"><?php echo number_format($e['balance'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($expenses)): ?>
                        <tr><td colspan="2" class="text-muted text-center">No expense entries</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="border-top border-2">
                        <tr>
                            <th class="fs-5">Total Expenses:</th>
                            <th class="text-end fs-4 text-danger"><?php echo number_format($total_expense, 2); ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Net Income -->
        <div class="mt-5 p-4 rounded text-center <?php echo $net_income >= 0 ? 'bg-success text-white' : 'bg-danger text-white'; ?>">
            <h3 class="m-0 fw-bold">
                <?php echo $net_income >= 0 ? 'خالص منافع / بچت (Net Surplus)' : 'خالص نقصان (Net Deficit)'; ?>: 
                Rs. <?php echo number_format(abs($net_income), 2); ?>
            </h3>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
