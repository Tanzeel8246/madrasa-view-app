<?php
require_once 'db.php';
session_start();

if (!isset($_SESSION['user_id']) || !app_has_perm('manage_erp')) {
    header("Location: dashboard.php");
    exit;
}
$role = $_SESSION['role'] ?? '';
$user = ['id' => $_SESSION['user_id'], 'role' => $role];

$account_id = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
$from_date = $_GET['from'] ?? date('Y-m-01');
$to_date = $_GET['to'] ?? date('Y-m-d');

$entries = [];
$opening_balance = 0;
$selected_account = null;

$accounts_list = $pdo->query("SELECT id, code, name FROM acc_chart_of_accounts WHERE is_deleted = 0 ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);

if ($account_id > 0) {
    // Get account info
    $stmt = $pdo->prepare("SELECT * FROM acc_chart_of_accounts WHERE id = ?");
    $stmt->execute([$account_id]);
    $selected_account = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($selected_account) {
        // Calculate opening balance (Before from_date)
        $ob_stmt = $pdo->prepare("
            SELECT SUM(e.debit) as total_dr, SUM(e.credit) as total_cr 
            FROM acc_ledger_entries e 
            JOIN acc_vouchers v ON e.voucher_id = v.id 
            WHERE e.account_id = ? AND v.date < ? AND (v.status IS NULL OR v.status != 'Rejected') AND (v.is_deleted = 0 OR v.is_deleted IS NULL)
        ");
        $ob_stmt->execute([$account_id, $from_date]);
        $ob = $ob_stmt->fetch(PDO::FETCH_ASSOC);
        
        $dr = (float)($ob['total_dr'] ?? 0);
        $cr = (float)($ob['total_cr'] ?? 0);
        
        $is_dr_normal = in_array($selected_account['type'], ['Asset', 'Expense']);
        $opening_balance = $is_dr_normal ? ($dr - $cr) : ($cr - $dr);

        // Fetch entries in date range
        $entries_stmt = $pdo->prepare("
            SELECT e.*, v.date, v.voucher_no, v.description as v_desc, v.type as v_type
            FROM acc_ledger_entries e
            JOIN acc_vouchers v ON e.voucher_id = v.id
            WHERE e.account_id = ? AND v.date BETWEEN ? AND ? AND (v.status IS NULL OR v.status != 'Rejected') AND (v.is_deleted = 0 OR v.is_deleted IS NULL)
            ORDER BY v.date ASC, v.id ASC
        ");
        $entries_stmt->execute([$account_id, $from_date, $to_date]);
        $entries = $entries_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$title = "Ledger Report - ERP";
include 'header.php';
?>

<div class="container py-4">
    <div class="d-print-none card p-3 mb-4 bg-light shadow-sm">
        <h4 class="mb-3 text-primary fw-bold"><i class="fas fa-book me-2"></i> General Ledger</h4>
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="fw-bold">Account</label>
                <select name="account_id" class="form-select select2" required>
                    <option value="">-- Select Account --</option>
                    <?php foreach($accounts_list as $a): ?>
                    <option value="<?php echo $a['id']; ?>" <?php echo $account_id == $a['id'] ? 'selected' : ''; ?>>
                        <?php echo e($a['code'] . ' - ' . $a['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="fw-bold">From Date</label>
                <input type="date" name="from" class="form-control" value="<?php echo e($from_date); ?>" required>
            </div>
            <div class="col-md-3">
                <label class="fw-bold">To Date</label>
                <input type="date" name="to" class="form-control" value="<?php echo e($to_date); ?>" required>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100 mb-1"><?php echo t('view_ledger'); ?></button>
                <button type="button" onclick="window.print()" class="btn btn-dark w-100">Print</button>
            </div>
        </form>
    </div>

    <?php if($selected_account): ?>
    <div id="printableArea" class="bg-white p-4 border rounded shadow-sm">
        <div class="text-center border-bottom border-2 border-dark mb-4 pb-2">
            <h3 class="fw-bold text-success m-0">مدرسہ اشرف المدارس</h3>
            <h5 class="mt-2 text-uppercase fw-bold">General Ledger Account</h5>
            <p class="mb-1 fw-bold fs-5 text-primary">
                <?php echo e($selected_account['code'] . ' - ' . $selected_account['name']); ?> 
                <span class="badge bg-secondary fs-6"><?php echo e($selected_account['type']); ?></span>
            </p>
            <p class="text-muted small">Period: <?php echo date('d-M-Y', strtotime($from_date)); ?> to <?php echo date('d-M-Y', strtotime($to_date)); ?></p>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle">
                <thead class="table-dark">
                    <tr>
                        <th style="width: 12%;">Date</th>
                        <th style="width: 15%;">Voucher #</th>
                        <th style="width: 10%;">Type</th>
                        <th>Description</th>
                        <th class="text-end" style="width: 12%;">Debit (Dr)</th>
                        <th class="text-end" style="width: 12%;">Credit (Cr)</th>
                        <th class="text-end" style="width: 15%;">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Opening Balance Row -->
                    <tr class="table-warning fw-bold">
                        <td colspan="4">Opening Balance (As of <?php echo date('d-M-Y', strtotime($from_date)); ?>)</td>
                        <td class="text-end">-</td>
                        <td class="text-end">-</td>
                        <td class="text-end">Rs. <?php echo number_format($opening_balance, 2); ?></td>
                    </tr>

                    <?php 
                    $current_balance = $opening_balance;
                    $total_dr = 0;
                    $total_cr = 0;
                    $is_dr_normal = in_array($selected_account['type'], ['Asset', 'Expense']);

                    foreach($entries as $e): 
                        $dr = (float)$e['debit'];
                        $cr = (float)$e['credit'];
                        $total_dr += $dr;
                        $total_cr += $cr;

                        if ($is_dr_normal) {
                            $current_balance += ($dr - $cr);
                        } else {
                            $current_balance += ($cr - $dr);
                        }
                    ?>
                    <tr>
                        <td><?php echo date('d-M-Y', strtotime($e['date'])); ?></td>
                        <td class="fw-bold">
                            <a href="javascript:void(0)" onclick="viewVoucher(<?php echo $e['voucher_id']; ?>)">
                                <?php echo e($e['voucher_no']); ?>
                            </a>
                        </td>
                        <td><span class="badge bg-info text-dark"><?php echo e($e['v_type']); ?></span></td>
                        <td><?php echo e($e['v_desc']); ?></td>
                        <td class="text-end font-monospace"><?php echo $dr > 0 ? number_format($dr, 2) : '-'; ?></td>
                        <td class="text-end font-monospace"><?php echo $cr > 0 ? number_format($cr, 2) : '-'; ?></td>
                        <td class="text-end font-monospace fw-bold">Rs. <?php echo number_format($current_balance, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-dark fw-bold">
                    <tr>
                        <td colspan="4" class="text-end">Total for Period:</td>
                        <td class="text-end font-monospace">Rs. <?php echo number_format($total_dr, 2); ?></td>
                        <td class="text-end font-monospace">Rs. <?php echo number_format($total_cr, 2); ?></td>
                        <td class="text-end font-monospace">Ending: Rs. <?php echo number_format($current_balance, 2); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Modal for Voucher Details -->
<div class="modal fade" id="voucherModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" id="voucherModalContent">
            <!-- Loaded via AJAX -->
        </div>
    </div>
</div>

<script>
function viewVoucher(id) {
    fetch('ajax_acc_voucher_details.php?id=' + id)
        .then(response => response.text())
        .then(html => {
            document.getElementById('voucherModalContent').innerHTML = html;
            new bootstrap.Modal(document.getElementById('voucherModal')).show();
        });
}
</script>

<?php include 'footer.php'; ?>
