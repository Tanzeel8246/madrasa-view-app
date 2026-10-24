<?php
require_once 'db.php';
require_once 'financial_audit_helper.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$u_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin = in_array($u_role, ['admin', 'superadmin', 'super_admin', 'administrator'], true);
if (!$is_admin && !app_has_perm('manage_erp')) {
    header("Location: dashboard.php");
    exit;
}
$role = $_SESSION['role'] ?? '';
$user = ['id' => $_SESSION['user_id'], 'role' => $role];

$cur_tenant_id = get_current_tenant_id($pdo);
$tenant_settings = function_exists('get_tenant_settings') ? get_tenant_settings($pdo, $cur_tenant_id) : [];
$inst_name = !empty($tenant_settings['madrasa_name']) ? $tenant_settings['madrasa_name'] : (!empty($_SESSION['madrasa_name']) ? $_SESSION['madrasa_name'] : 'مدرسہ پورٹل');

if (function_exists('acc_ensure_standard_chart_and_orphans_repaired')) {
    acc_ensure_standard_chart_and_orphans_repaired($pdo, $cur_tenant_id);
}

$account_id = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
$from_date = $_GET['from'] ?? date('Y-m-01');
$to_date = $_GET['to'] ?? date('Y-m-d');

$entries = [];
$opening_balance = 0;
$selected_account = null;

$acc_list_stmt = $pdo->prepare("SELECT id, code, name FROM acc_chart_of_accounts WHERE (is_deleted = 0 OR is_deleted IS NULL) AND (is_active = 1 OR is_active IS NULL) AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0) AND code NOT IN ('1101', '1201', '1301', '2101', '2102', '2103', '5010', '5011') ORDER BY CAST(code AS UNSIGNED) ASC, code ASC");
$acc_list_stmt->execute([$cur_tenant_id]);
$accounts_list = $acc_list_stmt->fetchAll(PDO::FETCH_ASSOC);

if ($account_id > 0) {
    // Get account info scoped by tenant
    $stmt = $pdo->prepare("SELECT * FROM acc_chart_of_accounts WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$account_id, $cur_tenant_id]);
    $selected_account = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($selected_account) {
        $sys = $_SESSION['system_type'] ?? 'Madrasa';
        $sys_cond = ($sys === 'Masjid') ? "v.system_type = 'Masjid'" : "(v.system_type = 'Madrasa' OR v.system_type IS NULL OR v.system_type = '')";

        // D8 Fix: Only Approved / Posted vouchers affect the general ledger
        $ob_stmt = $pdo->prepare("
            SELECT SUM(e.debit) as total_dr, SUM(e.credit) as total_cr 
            FROM acc_ledger_entries e 
            JOIN acc_vouchers v ON e.voucher_id = v.id 
            WHERE e.account_id = ? AND e.tenant_id = ? AND v.tenant_id = ? AND v.date < ? AND v.status IN ('Approved', 'Reversed') AND (v.is_deleted = 0 OR v.is_deleted IS NULL) AND $sys_cond
        ");
        $ob_stmt->execute([$account_id, $cur_tenant_id, $cur_tenant_id, $from_date]);
        $ob = $ob_stmt->fetch(PDO::FETCH_ASSOC);
        
        $dr = (float)($ob['total_dr'] ?? 0);
        $cr = (float)($ob['total_cr'] ?? 0);
        
        $is_dr_normal = in_array($selected_account['type'], ['Asset', 'Expense']);
        $opening_balance = $is_dr_normal ? ($dr - $cr) : ($cr - $dr);

        // Fetch entries in date range scoped by tenant (Approved vouchers only)
        $entries_stmt = $pdo->prepare("
            SELECT e.*, v.date, v.voucher_no, v.description as v_desc, v.type as v_type
            FROM acc_ledger_entries e
            JOIN acc_vouchers v ON e.voucher_id = v.id
            WHERE e.account_id = ? AND e.tenant_id = ? AND v.tenant_id = ? AND v.date BETWEEN ? AND ? AND v.status IN ('Approved', 'Reversed') AND (v.is_deleted = 0 OR v.is_deleted IS NULL) AND $sys_cond
            ORDER BY v.date ASC, v.id ASC
        ");
        $entries_stmt->execute([$account_id, $cur_tenant_id, $cur_tenant_id, $from_date, $to_date]);
        $entries = $entries_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$title = "Ledger Report - ERP";
include 'header.php';
?>

<div class="container py-4">
    <div class="d-print-none card p-3 mb-4 bg-light shadow-sm">
        <h4 class="mb-3 text-primary fw-bold"><i class="fas fa-book me-2"></i> <?php echo ($lang=='ur') ? 'جنرل لیجر (کھاتہ)' : 'General Ledger'; ?></h4>
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="fw-bold"><?php echo ($lang=='ur') ? 'کھاتہ' : 'Account'; ?></label>
                <select name="account_id" class="form-select select2" onchange="if(this.value) this.form.submit();" required>
                    <option value=""><?php echo ($lang=='ur') ? '-- کھاتہ منتخب کریں --' : '-- Select Account --'; ?></option>
                    <?php foreach($accounts_list as $a): ?>
                    <option value="<?php echo $a['id']; ?>" <?php echo $account_id == $a['id'] ? 'selected' : ''; ?>>
                        <?php echo e($a['code'] . ' - ' . $a['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="fw-bold"><?php echo ($lang=='ur') ? 'تاریخ سے' : 'From Date'; ?></label>
                <input type="date" name="from" id="accLedgerFrom" class="form-control" value="<?php echo e($from_date); ?>" onchange="if(this.value && document.getElementById('accLedgerTo').value && document.querySelector('[name=account_id]').value) this.form.submit();" required>
            </div>
            <div class="col-md-3">
                <label class="fw-bold"><?php echo ($lang=='ur') ? 'تاریخ تک' : 'To Date'; ?></label>
                <input type="date" name="to" id="accLedgerTo" class="form-control" value="<?php echo e($to_date); ?>" onchange="if(this.value && document.getElementById('accLedgerFrom').value && document.querySelector('[name=account_id]').value) this.form.submit();" required>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100 mb-1"><?php echo ($lang=='ur') ? 'لیجر دیکھیں' : 'View Ledger'; ?></button>
                <button type="button" onclick="window.print()" class="btn btn-dark w-100"><i class="fas fa-print me-1"></i> <?php echo ($lang=='ur') ? 'پرنٹ کریں' : 'Print'; ?></button>
            </div>
        </form>
    </div>

    <?php if($selected_account): ?>
    <div id="printableArea" class="bg-white p-4 border rounded shadow-sm">
        <div class="text-center border-bottom border-2 border-dark mb-4 pb-2">
            <!-- D10 Fix: Print current tenant's own name -->
            <h3 class="fw-bold text-success m-0"><?php echo htmlspecialchars($inst_name); ?></h3>
            <h5 class="mt-2 text-uppercase fw-bold"><?php echo ($lang=='ur') ? 'جنرل لیجر کھاتہ' : 'General Ledger Account'; ?></h5>
            <p class="mb-1 fw-bold fs-5 text-primary">
                <?php echo e($selected_account['code'] . ' - ' . $selected_account['name']); ?> 
                <span class="badge bg-secondary fs-6"><?php echo e($selected_account['type']); ?></span>
            </p>
            <p class="text-muted small"><?php echo ($lang=='ur') ? 'مدت:' : 'Period:'; ?> <?php echo date('d-M-Y', strtotime($from_date)); ?> <?php echo ($lang=='ur') ? 'تا' : 'to'; ?> <?php echo date('d-M-Y', strtotime($to_date)); ?></p>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle">
                <thead class="table-dark">
                    <tr>
                        <th style="width: 12%;"><?php echo ($lang=='ur') ? 'تاریخ' : 'Date'; ?></th>
                        <th style="width: 15%;"><?php echo ($lang=='ur') ? 'واؤچر نمبر' : 'Voucher #'; ?></th>
                        <th style="width: 10%;"><?php echo ($lang=='ur') ? 'نوعیت' : 'Type'; ?></th>
                        <th><?php echo ($lang=='ur') ? 'تفصیل' : 'Description'; ?></th>
                        <th class="text-end" style="width: 12%;"><?php echo ($lang=='ur') ? 'ڈیبٹ (Dr)' : 'Debit (Dr)'; ?></th>
                        <th class="text-end" style="width: 12%;"><?php echo ($lang=='ur') ? 'کریڈٹ (Cr)' : 'Credit (Cr)'; ?></th>
                        <th class="text-end" style="width: 15%;"><?php echo ($lang=='ur') ? 'میزان (Balance)' : 'Balance'; ?></th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Opening Balance Row -->
                    <tr class="table-warning fw-bold">
                        <td colspan="4"><?php echo ($lang=='ur') ? 'ابتدائی بقایا (بتاریخ ' . date('d-M-Y', strtotime($from_date)) . ')' : 'Opening Balance (As of ' . date('d-M-Y', strtotime($from_date)) . ')'; ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end">-</td>
                        <td class="text-end">Rs. <?php echo number_format($opening_balance, 2); ?></td>
                    </tr>
                    <?php 
                    $current_balance = $opening_balance;
                    $tot_dr = 0;
                    $tot_cr = 0;
                    if(empty($entries)): 
                    ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-3"><?php echo ($lang=='ur') ? 'اس مدت کے دوران کوئی لین دین نہیں ملا۔' : 'No transactions found in this date range.'; ?></td>
                    </tr>
                    <?php 
                    else:
                        foreach($entries as $e): 
                            $dr = (float)$e['debit'];
                            $cr = (float)$e['credit'];
                            $tot_dr += $dr;
                            $tot_cr += $cr;
                            
                            if ($is_dr_normal) {
                                $current_balance += ($dr - $cr);
                            } else {
                                $current_balance += ($cr - $dr);
                            }
                    ?>
                    <tr>
                        <td><?php echo date('d-M-Y', strtotime($e['date'])); ?></td>
                        <td class="fw-bold text-primary"><?php echo e($e['voucher_no']); ?></td>
                        <td><span class="badge bg-secondary"><?php echo e($e['v_type']); ?></span></td>
                        <td><?php echo e($e['v_desc']); ?></td>
                        <td class="text-end text-success"><?php echo $dr > 0 ? number_format($dr, 2) : '-'; ?></td>
                        <td class="text-end text-danger"><?php echo $cr > 0 ? number_format($cr, 2) : '-'; ?></td>
                        <td class="text-end fw-bold">Rs. <?php echo number_format($current_balance, 2); ?></td>
                    </tr>
                    <?php 
                        endforeach; 
                    endif;
                    ?>
                </tbody>
                <tfoot class="table-dark fw-bold">
                    <tr>
                        <td colspan="4" class="text-end"><?php echo ($lang=='ur') ? 'کل میزان برائے مدت:' : 'Total for Period:'; ?></td>
                        <td class="text-end text-success"><?php echo number_format($tot_dr, 2); ?></td>
                        <td class="text-end text-danger"><?php echo number_format($tot_cr, 2); ?></td>
                        <td class="text-end"><?php echo ($lang=='ur') ? 'اختتامی بقایا:' : 'Closing:'; ?> Rs. <?php echo number_format($current_balance, 2); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
