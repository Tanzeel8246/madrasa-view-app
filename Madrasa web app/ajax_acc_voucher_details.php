<?php
require_once 'db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    echo "Unauthorized";
    exit;
}

$voucher_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$voucher_id) {
    echo "Invalid Voucher ID";
    exit;
}

$cur_tenant_id = (int)get_current_tenant_id($pdo);

// Self-heal any legacy lines where tenant_id was not populated
try {
    $pdo->prepare("UPDATE acc_ledger_entries e JOIN acc_vouchers v ON e.voucher_id = v.id SET e.tenant_id = v.tenant_id WHERE e.voucher_id = ? AND (e.tenant_id IS NULL OR e.tenant_id = 0) AND v.tenant_id = ?")->execute([$voucher_id, $cur_tenant_id]);
} catch (Throwable $e) {}

$entries = $pdo->prepare("
    SELECT e.*, a.name as account_name, a.code as account_code 
    FROM acc_ledger_entries e 
    JOIN acc_chart_of_accounts a ON e.account_id = a.id 
    JOIN acc_vouchers v ON e.voucher_id = v.id
    WHERE e.voucher_id = ? AND v.tenant_id = ? AND (e.tenant_id = ? OR e.tenant_id IS NULL)
    ORDER BY e.id ASC
");
$entries->execute([$voucher_id, $cur_tenant_id, $cur_tenant_id]);
$ledger = $entries->fetchAll(PDO::FETCH_ASSOC);

if (!$ledger) {
    echo "<p class='text-center text-muted py-3'>اس واؤچر کے اندراجات دستیاب نہیں ہیں۔</p>";
    exit;
}
?>
<div class="table-responsive">
    <table class="table table-bordered text-center align-middle mb-0">
        <thead class="table-light">
            <tr>
                <th>Account Code</th>
                <th>Account Name</th>
                <th class="text-end">Debit (Dr)</th>
                <th class="text-end">Credit (Cr)</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $tot_dr = 0; $tot_cr = 0;
            foreach($ledger as $e): 
                $tot_dr += (float)$e['debit'];
                $tot_cr += (float)$e['credit'];
            ?>
            <tr>
                <td class="fw-bold"><?php echo e($e['account_code']); ?></td>
                <td class="text-start"><?php echo e($e['account_name']); ?></td>
                <td class="text-end text-success fw-bold"><?php echo (float)$e['debit'] > 0 ? number_format((float)$e['debit'], 2) : '-'; ?></td>
                <td class="text-end text-danger fw-bold"><?php echo (float)$e['credit'] > 0 ? number_format((float)$e['credit'], 2) : '-'; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light fw-bold">
            <tr>
                <td colspan="2" class="text-end">Total:</td>
                <td class="text-end text-success"><?php echo number_format($tot_dr, 2); ?></td>
                <td class="text-end text-danger"><?php echo number_format($tot_cr, 2); ?></td>
            </tr>
        </tfoot>
    </table>
</div>
