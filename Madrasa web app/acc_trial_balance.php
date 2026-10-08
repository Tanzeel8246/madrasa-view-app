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

// Fetch Trial Balance
$query = "
    SELECT a.code, a.name, a.type, 
           SUM(CASE WHEN v.id IS NOT NULL THEN e.debit ELSE 0 END) as total_debit, 
           SUM(CASE WHEN v.id IS NOT NULL THEN e.credit ELSE 0 END) as total_credit
    FROM acc_chart_of_accounts a
    LEFT JOIN acc_ledger_entries e ON a.id = e.account_id
    LEFT JOIN acc_vouchers v ON e.voucher_id = v.id AND v.status != 'Rejected' AND (v.is_deleted = 0 OR v.is_deleted IS NULL) AND v.date <= ?
    WHERE a.is_deleted = 0
    GROUP BY a.id, a.code, a.name, a.type
    ORDER BY a.type, a.code
";

$stmt = $pdo->prepare($query);
$stmt->execute([$as_of_date]);
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_dr = 0;
$total_cr = 0;
?>

<div class="container py-4">
    <div class="d-print-none card p-3 mb-4 bg-light shadow-sm border-top border-5 border-primary">
        <h4 class="mb-3 text-primary fw-bold"><i class="fas fa-balance-scale me-2"></i> <?php echo ($lang=='ur') ? 'میزانِ پڑتال' : 'Trial Balance'; ?></h4>
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="fw-bold"><?php echo ($lang=='ur') ? 'کس تاریخ تک' : 'As of Date'; ?></label>
                <input type="date" name="as_of" class="form-control" value="<?php echo e($as_of_date); ?>" onchange="this.form.submit()" required>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100 mb-1"><?php echo ($lang=='ur') ? 'رپورٹ دیکھیں' : 'View Report'; ?></button>
            </div>
            <div class="col-md-3">
                <button type="button" onclick="window.print()" class="btn btn-dark w-100 mb-1"><i class="fas fa-print"></i> <?php echo ($lang=='ur') ? 'پرنٹ کریں' : 'Print'; ?></button>
            </div>
        </form>
    </div>

    <div id="printableArea" class="bg-white p-4 border rounded shadow-sm">
        <div class="text-center border-bottom border-2 border-dark mb-4 pb-2">
            <h3 class="fw-bold text-success m-0"><?php echo e(t('app_name')); ?></h3>
            <h5 class="mt-2 fw-bold"><?php echo ($lang=='ur') ? 'میزانِ پڑتال' : 'Trial Balance'; ?></h5>
            <p class="mb-1 fw-bold fs-6 text-muted">As of: <?php echo date('d-M-Y', strtotime($as_of_date)); ?></p>
        </div>

        <table class="table table-bordered table-sm align-middle">
            <thead class="table-dark text-center">
                <tr>
                    <th style="width: 15%">Account Code</th>
                    <th style="width: 35%">Account Title</th>
                    <th style="width: 20%">Account Type</th>
                    <th style="width: 15%">Debit (Dr)</th>
                    <th style="width: 15%">Credit (Cr)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $current_type = '';
                foreach($accounts as $acc): 
                    $dr = (float)($acc['total_debit'] ?? 0);
                    $cr = (float)($acc['total_credit'] ?? 0);
                    
                    // Net Balance Calculation
                    // Assets & Expenses normally have Debit balance
                    // Liabilities, Equity, Revenue normally have Credit balance
                    $net_dr = 0;
                    $net_cr = 0;
                    
                    if (in_array($acc['type'], ['Asset', 'Expense'])) {
                        $bal = $dr - $cr;
                        if ($bal > 0) $net_dr = $bal;
                        else if ($bal < 0) $net_cr = abs($bal);
                    } else {
                        $bal = $cr - $dr;
                        if ($bal > 0) $net_cr = $bal;
                        else if ($bal < 0) $net_dr = abs($bal);
                    }
                    
                    if ($net_dr == 0 && $net_cr == 0) continue; // Skip zero balance accounts
                    
                    $total_dr += $net_dr;
                    $total_cr += $net_cr;
                    
                    if ($current_type != $acc['type']):
                        $current_type = $acc['type'];
                ?>
                <tr class="table-light fw-bold text-primary">
                    <td colspan="5"><?php echo $current_type; ?> Accounts</td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td class="text-center"><?php echo $acc['code']; ?></td>
                    <td><?php echo $acc['name']; ?></td>
                    <td class="text-center"><span class="badge bg-secondary"><?php echo $acc['type']; ?></span></td>
                    <td class="text-end fw-bold text-success"><?php echo $net_dr > 0 ? number_format($net_dr, 2) : '-'; ?></td>
                    <td class="text-end fw-bold text-danger"><?php echo $net_cr > 0 ? number_format($net_cr, 2) : '-'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="table-dark">
                <tr>
                    <th colspan="3" class="text-end fs-5">Grand Total:</th>
                    <th class="text-end fs-5 text-success"><?php echo number_format($total_dr, 2); ?></th>
                    <th class="text-end fs-5 text-danger"><?php echo number_format($total_cr, 2); ?></th>
                </tr>
            </tfoot>
        </table>
        
        <?php if (abs($total_dr - $total_cr) > 0.01): ?>
        <div class="alert alert-danger mt-3 fw-bold text-center">
            <i class="fas fa-exclamation-triangle me-2"></i> Trial Balance is out of balance by <?php echo number_format(abs($total_dr - $total_cr), 2); ?>
        </div>
        <?php else: ?>
        <div class="alert alert-success mt-3 fw-bold text-center">
            <i class="fas fa-check-circle me-2"></i> Trial Balance matches perfectly! (Debit = Credit)
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>
