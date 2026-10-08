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

$cur_tenant_id = get_current_tenant_id($pdo);
$entries = $pdo->prepare("SELECT e.*, a.name as account_name, a.code as account_code FROM acc_ledger_entries e JOIN acc_chart_of_accounts a ON e.account_id = a.id WHERE e.voucher_id = ? AND e.tenant_id = ? ORDER BY e.id ASC");
$entries->execute([$voucher_id, $cur_tenant_id]);
$ledger = $entries->fetchAll(PDO::FETCH_ASSOC);

if (!$ledger) {
    echo "<p class='text-center text-muted'>No entries found for this voucher.</p>";
    exit;
}
?>
<div class="table-responsive">
    <table class="table table-bordered text-center align-middle">
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
                $tot_dr += $e['debit'];
                $tot_cr += $e['credit'];
            ?>
            <tr>
                <td><?php echo e($e['account_code']); ?></td>
                <td><?php echo e($e['account_name']); ?></td>
                <td class="text-end text-success fw-bold"><?php echo $e['debit'] > 0 ? number_format($e['debit'], 2) : '-'; ?></td>
                <td class="text-end text-danger fw-bold"><?php echo $e['credit'] > 0 ? number_format($e['credit'], 2) : '-'; ?></td>
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
