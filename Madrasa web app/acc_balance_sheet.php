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

$as_of_date = $_GET['as_of'] ?? date('Y-m-d');

finance_accounting_backfill($pdo, null, $as_of_date);

$cur_tenant_id = (int)get_current_tenant_id($pdo);

// Fetch accounts with balances
$query = "
    SELECT a.id, a.code, a.name, a.type, a.parent_id,
           SUM(CASE WHEN v.id IS NOT NULL THEN e.debit ELSE 0 END) as total_debit, 
           SUM(CASE WHEN v.id IS NOT NULL THEN e.credit ELSE 0 END) as total_credit
    FROM acc_chart_of_accounts a
    LEFT JOIN acc_ledger_entries e ON a.id = e.account_id
    LEFT JOIN acc_vouchers v ON e.voucher_id = v.id AND v.status != 'Rejected' AND (v.is_deleted = 0 OR v.is_deleted IS NULL) AND v.tenant_id = ? AND v.date <= ?
    WHERE a.is_deleted = 0 AND a.tenant_id = ?
    GROUP BY a.id, a.code, a.name, a.type, a.parent_id
    ORDER BY a.type, a.code
";

$stmt = $pdo->prepare($query);
$stmt->execute([$cur_tenant_id, $as_of_date, $cur_tenant_id]);
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$assets = [];
$liabilities = [];
$equity = [];

$total_assets = 0;
$total_liabilities = 0;
$total_equity = 0;
$retained_earnings = 0;

foreach($accounts as $acc) {
    $dr = (float)($acc['total_debit'] ?? 0);
    $cr = (float)($acc['total_credit'] ?? 0);
    
    $rawType = ucfirst(strtolower(trim((string)($acc['type'] ?? ''))));
    $isRev = ($rawType === 'Revenue' || $rawType === 'Income');
    $isExp = ($rawType === 'Expense');
    $isAsset = ($rawType === 'Asset');
    $isLiab = ($rawType === 'Liability');
    $isEq = ($rawType === 'Equity');

    // Revenue and Expenses (For Retained Earnings)
    if ($isRev) {
        $retained_earnings += ($cr - $dr);
    } else if ($isExp) {
        $retained_earnings -= ($dr - $cr);
    }
    
    // Assets, Liabilities, Equity
    if ($isAsset) {
        $bal = $dr - $cr;
        if ($bal != 0) {
            $assets[] = ['code' => $acc['code'], 'name' => $acc['name'], 'balance' => $bal];
            $total_assets += $bal;
        }
    } else if ($isLiab) {
        $bal = $cr - $dr;
        if ($bal != 0) {
            $liabilities[] = ['code' => $acc['code'], 'name' => $acc['name'], 'balance' => $bal];
            $total_liabilities += $bal;
        }
    } else if ($isEq) {
        $bal = $cr - $dr;
        if ($bal != 0) {
            $equity[] = ['code' => $acc['code'], 'name' => $acc['name'], 'balance' => $bal];
            $total_equity += $bal;
        }
    }
}

// Add Retained Earnings to Equity
if ($retained_earnings != 0) {
    $equity[] = ['code' => '3999', 'name' => 'Retained Earnings (خالص منافع/نقصان)', 'balance' => $retained_earnings];
    $total_equity += $retained_earnings;
}

$total_liab_and_equity = $total_liabilities + $total_equity;
?>

<div class="container py-4 urdu-text" style="font-family: 'Noto Nastaliq Urdu', serif;">
    <div class="d-print-none card p-3 mb-4 bg-light shadow-sm border-top border-5 border-primary">
        <h4 class="mb-3 text-primary fw-bold"><i class="fas fa-balance-scale-left me-2"></i> بیلنس شیٹ (Balance Sheet)</h4>
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="fw-bold">کس تاریخ تک (As of Date)</label>
                <input type="date" name="as_of" class="form-control" value="<?php echo e($as_of_date); ?>" onchange="this.form.submit()" required>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100 mb-1">رپورٹ دیکھیں</button>
            </div>
            <div class="col-md-3">
                <button type="button" onclick="window.print()" class="btn btn-dark w-100 mb-1"><i class="fas fa-print"></i> پرنٹ کریں</button>
            </div>
        </form>
    </div>

    <div id="printableArea" class="bg-white p-5 border rounded shadow-sm">
        <div class="text-center border-bottom border-2 border-dark mb-4 pb-3">
            <h2 class="fw-bold text-success m-0">مدرسہ اشرف المدارس</h2>
            <h4 class="mt-2 fw-bold">بیلنس شیٹ (Balance Sheet)</h4>
            <p class="mb-1 fw-bold fs-6 text-muted">As of: <?php echo date('d-M-Y', strtotime($as_of_date)); ?></p>
        </div>

        <div class="row g-5">
            <!-- Assets -->
            <div class="col-md-6 border-end">
                <h4 class="text-primary fw-bold border-bottom pb-2 mb-3"><i class="fas fa-money-bill-wave me-2"></i> اثاثہ جات (Assets)</h4>
                <table class="table table-borderless table-sm fs-5">
                    <tbody>
                        <?php foreach($assets as $a): ?>
                        <tr>
                            <td><span class="text-muted fs-6 me-2"><?php echo $a['code']; ?></span> <?php echo $a['name']; ?></td>
                            <td class="text-end fw-bold text-primary"><span dir="ltr" class="d-inline-block"><?php echo number_format($a['balance'], 2); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($assets)): ?>
                        <tr><td colspan="2" class="text-muted text-center">کوئی اثاثہ نہیں (No assets)</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="border-top border-2">
                        <tr>
                            <th class="fs-5">کل اثاثہ جات (Total Assets):</th>
                            <th class="text-end fs-4 text-primary"><span dir="ltr" class="d-inline-block"><?php echo number_format($total_assets, 2); ?></span></th>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Liabilities & Equity -->
            <div class="col-md-6">
                <!-- Liabilities -->
                <h4 class="text-danger fw-bold border-bottom pb-2 mb-3"><i class="fas fa-hand-holding-usd me-2"></i> واجبات (Liabilities)</h4>
                <table class="table table-borderless table-sm fs-5 mb-4">
                    <tbody>
                        <?php foreach($liabilities as $l): ?>
                        <tr>
                            <td><span class="text-muted fs-6 me-2"><?php echo $l['code']; ?></span> <?php echo $l['name']; ?></td>
                            <td class="text-end fw-bold text-danger"><span dir="ltr" class="d-inline-block"><?php echo number_format($l['balance'], 2); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($liabilities)): ?>
                        <tr><td colspan="2" class="text-muted text-center">کوئی واجبات نہیں (No liabilities)</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="border-top border-1">
                        <tr>
                            <th class="fs-6">کل واجبات (Total Liabilities):</th>
                            <th class="text-end fs-5 text-danger"><span dir="ltr" class="d-inline-block"><?php echo number_format($total_liabilities, 2); ?></span></th>
                        </tr>
                    </tfoot>
                </table>

                <!-- Equity -->
                <h4 class="text-info fw-bold border-bottom pb-2 mb-3"><i class="fas fa-chart-line me-2"></i> سرمایہ (Equity)</h4>
                <table class="table table-borderless table-sm fs-5">
                    <tbody>
                        <?php foreach($equity as $e): ?>
                        <tr>
                            <td><span class="text-muted fs-6 me-2"><?php echo $e['code']; ?></span> <?php echo $e['name']; ?></td>
                            <td class="text-end fw-bold text-info"><span dir="ltr" class="d-inline-block"><?php echo number_format($e['balance'], 2); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($equity)): ?>
                        <tr><td colspan="2" class="text-muted text-center">کوئی سرمایہ نہیں (No equity)</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="border-top border-1">
                        <tr>
                            <th class="fs-6">کل سرمایہ (Total Equity):</th>
                            <th class="text-end fs-5 text-info"><span dir="ltr" class="d-inline-block"><?php echo number_format($total_equity, 2); ?></span></th>
                        </tr>
                    </tfoot>
                </table>

                <table class="table table-borderless table-sm fs-5 mt-4">
                    <tfoot class="border-top border-2">
                        <tr>
                            <th class="fs-5">کل واجبات و سرمایہ (Total Liab & Eq):</th>
                            <th class="text-end fs-4 <?php echo abs($total_assets - $total_liab_and_equity) < 0.01 ? 'text-success' : 'text-danger'; ?>">
                                <span dir="ltr" class="d-inline-block"><?php echo number_format($total_liab_and_equity, 2); ?></span>
                            </th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <?php if (abs($total_assets - $total_liab_and_equity) > 0.01): ?>
        <div class="alert alert-danger mt-5 fw-bold text-center fs-5">
            <i class="fas fa-exclamation-triangle me-2"></i> بیلنس شیٹ برابر نہیں ہے! (Out of balance by <span dir="ltr"><?php echo number_format(abs($total_assets - $total_liab_and_equity), 2); ?></span>)
        </div>
        <?php else: ?>
        <div class="alert alert-success mt-5 fw-bold text-center fs-5">
            <i class="fas fa-check-circle me-2"></i> بیلنس شیٹ درست ہے! (Assets = Liabilities + Equity)
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>
