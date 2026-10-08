<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';

if (!isset($_SESSION['user_id']) || !app_has_perm('manage_erp')) {
    header("Location: dashboard.php");
    exit;
}
$role = $_SESSION['role'] ?? '';
$user = ['id' => $_SESSION['user_id'], 'role' => $role];

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_voucher'])) {
    $date = $_POST['date'] ?? date('Y-m-d');
    $type = $_POST['type'] ?? 'Journal';
    $desc = $_POST['description'] ?? '';
    $ref = $_POST['reference'] ?? '';
    
    // Auto-generate voucher number
    $prefix = strtoupper(substr($type, 0, 1)) . 'V-';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM acc_vouchers WHERE type = ?");
    $stmt->execute([$type]);
    $count = $stmt->fetchColumn() + 1;
    $voucher_no = $prefix . str_pad($count, 5, '0', STR_PAD_LEFT);
    
    $accounts = $_POST['account_id'] ?? [];
    $debits = $_POST['debit'] ?? [];
    $credits = $_POST['credit'] ?? [];
    $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
    
    try {
        $clean_lines = [];
        $total_debit = 0;
        $total_credit = 0;
        for ($i = 0; $i < count($accounts); $i++) {
            $acc_id = (int)($accounts[$i] ?? 0);
            $dr = (float)($debits[$i] ?? 0);
            $cr = (float)($credits[$i] ?? 0);
            if ($acc_id <= 0 && ($dr > 0 || $cr > 0)) {
                throw new Exception("Ledger line has amount but no account selected.");
            }
            if ($dr > 0 && $cr > 0) {
                throw new Exception("One ledger line cannot have both debit and credit.");
            }
            if ($acc_id > 0 && ($dr > 0 || $cr > 0)) {
                $clean_lines[] = [$acc_id, $dr, $cr];
                $total_debit += $dr;
                $total_credit += $cr;
            }
        }
        if (count($clean_lines) < 2) {
            throw new Exception("At least two ledger lines are required.");
        }
        if ($total_debit <= 0 || abs($total_debit - $total_credit) > 0.01) {
            throw new Exception("Debits and Credits must balance. Dr: $total_debit, Cr: $total_credit");
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO acc_vouchers (voucher_no, date, type, reference, description, department_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$voucher_no, $date, $type, $ref, $desc, $department_id, $user['id']]);
        $voucher_id = $pdo->lastInsertId();
        $entry_stmt = $pdo->prepare("INSERT INTO acc_ledger_entries (voucher_id, account_id, debit, credit, department_id) VALUES (?, ?, ?, ?, ?)");
        foreach ($clean_lines as $line) {
            $entry_stmt->execute([$voucher_id, $line[0], $line[1], $line[2], $department_id]);
        }
        $pdo->commit();
        $msg = "Voucher $voucher_no created successfully!";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $err = "Failed: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status']) && $role === 'admin') {
    $v_id = (int)$_POST['voucher_id'];
    $new_status = $_POST['status'];
    if (in_array($new_status, ['Pending', 'Approved', 'Rejected'])) {
        $pdo->prepare("UPDATE acc_vouchers SET status = ? WHERE id = ?")->execute([$new_status, $v_id]);
        $msg = "Voucher status updated to $new_status.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_voucher']) && $role === 'admin') {
    $v_id = (int)$_POST['voucher_id'];
    try {
        try { $pdo->prepare("UPDATE acc_vouchers SET is_deleted = 1 WHERE id = ?")->execute([$v_id]); } catch (Exception $e) { $pdo->prepare("DELETE FROM acc_vouchers WHERE id = ?")->execute([$v_id]); }
        $msg = "Voucher deleted successfully.";
    } catch (Exception $e) {
        $err = "Failed to delete voucher.";
    }
}

// Fetch Vouchers
$vouchers = $pdo->query("SELECT v.*, u.name as creator_name, (SELECT SUM(debit) FROM acc_ledger_entries WHERE voucher_id = v.id) as total_amount FROM acc_vouchers v LEFT JOIN admins u ON v.created_by = u.id WHERE (v.is_deleted = 0 OR v.is_deleted IS NULL) ORDER BY v.date DESC, v.id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Chart of Accounts for Dropdown
$accounts_list = $pdo->query("SELECT id, code, name FROM acc_chart_of_accounts WHERE is_deleted = 0 ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Departments
$departments_list = $pdo->query("SELECT id, name FROM departments WHERE status = 'Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$title = "Vouchers - ERP Accounting";
include 'header.php';
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-primary"><i class="fas fa-receipt me-2"></i> Accounting Vouchers</h3>
        <button class="btn btn-success fw-bold" data-bs-toggle="modal" data-bs-target="#newVoucherModal">
            <i class="fas fa-plus me-1"></i> New Voucher
        </button>
    </div>

    <?php if (!empty($msg)): ?>
        <div class="alert alert-success alert-dismissible fade show"><?php echo e($msg); ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if (!empty($err)): ?>
        <div class="alert alert-danger alert-dismissible fade show"><?php echo e($err); ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <!-- Vouchers List -->
    <div class="card shadow-sm border-0 neobox">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Date</th>
                        <th>Voucher #</th>
                        <th>Type</th>
                        <th>Reference</th>
                        <th>Description</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                        <th>Creator</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($vouchers)): ?>
                        <tr><td colspan="9" class="text-center py-4 text-muted">No vouchers found. Click "New Voucher" to create one.</td></tr>
                    <?php else: ?>
                        <?php foreach ($vouchers as $v): ?>
                        <tr>
                            <td><?php echo date('d-M-Y', strtotime($v['date'])); ?></td>
                            <td class="fw-bold text-primary"><?php echo e($v['voucher_no']); ?></td>
                            <td><span class="badge bg-secondary"><?php echo e($v['type']); ?></span></td>
                            <td><small class="text-muted"><?php echo e($v['reference'] ?? '---'); ?></small></td>
                            <td><small><?php echo e($v['description'] ?? '---'); ?></small></td>
                            <td class="text-end fw-bold">Rs. <?php echo number_format($v['total_amount'] ?? 0, 2); ?></td>
                            <td>
                                <span class="badge bg-<?php echo $v['status']=='Approved'?'success':($v['status']=='Rejected'?'danger':'warning'); ?>">
                                    <?php echo e($v['status']); ?>
                                </span>
                            </td>
                            <td><small><?php echo e($v['creator_name'] ?? 'System'); ?></small></td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-primary me-1" onclick="viewVoucher(<?php echo $v['id']; ?>)"><i class="fas fa-eye"></i></button>
                                <?php if ($role === 'admin' || $v['status'] === 'Pending'): ?>
                                    <a href="acc_voucher_edit.php?id=<?php echo $v['id']; ?>" class="btn btn-sm btn-outline-warning me-1"><i class="fas fa-edit"></i></a>
                                <?php endif; ?>
                                <?php if ($role === 'admin'): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this voucher?')">
                                        <input type="hidden" name="voucher_id" value="<?php echo $v['id']; ?>">
                                        <button type="submit" name="delete_voucher" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal for New Voucher -->
<div class="modal fade" id="newVoucherModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i> Create New Voucher</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="fw-bold">Date</label>
                        <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="fw-bold">Voucher Type</label>
                        <select name="type" class="form-select" required>
                            <option value="Journal">Journal Voucher (JV)</option>
                            <option value="Payment">Payment Voucher (PV)</option>
                            <option value="Receipt">Receipt Voucher (RV)</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="fw-bold">Department (Optional)</label>
                        <select name="department_id" class="form-select">
                            <option value="">-- General / None --</option>
                            <?php foreach($departments_list as $d): ?>
                                <option value="<?php echo $d['id']; ?>"><?php echo e($d['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold">Reference # / Bill #</label>
                        <input type="text" name="reference" class="form-control" placeholder="e.g. INV-1002">
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold">Description / Narration</label>
                        <input type="text" name="description" class="form-control" placeholder="Narration for this transaction">
                    </div>
                </div>

                <h6 class="fw-bold text-secondary border-bottom pb-2 mb-3">Ledger Entries (Minimum 2 Lines, Debits must equal Credits)</h6>
                <div id="ledgerLines">
                    <div class="row g-2 mb-2 line-row">
                        <div class="col-md-6">
                            <select name="account_id[]" class="form-select select2" required>
                                <option value="">-- Select Account --</option>
                                <?php foreach($accounts_list as $a): ?>
                                    <option value="<?php echo $a['id']; ?>"><?php echo e($a['code'] . ' - ' . $a['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <input type="number" step="0.01" name="debit[]" class="form-control debit-input" placeholder="Debit (Dr)" value="0">
                        </div>
                        <div class="col-md-3">
                            <input type="number" step="0.01" name="credit[]" class="form-control credit-input" placeholder="Credit (Cr)" value="0">
                        </div>
                    </div>
                    <div class="row g-2 mb-2 line-row">
                        <div class="col-md-6">
                            <select name="account_id[]" class="form-select select2" required>
                                <option value="">-- Select Account --</option>
                                <?php foreach($accounts_list as $a): ?>
                                    <option value="<?php echo $a['id']; ?>"><?php echo e($a['code'] . ' - ' . $a['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <input type="number" step="0.01" name="debit[]" class="form-control debit-input" placeholder="Debit (Dr)" value="0">
                        </div>
                        <div class="col-md-3">
                            <input type="number" step="0.01" name="credit[]" class="form-control credit-input" placeholder="Credit (Cr)" value="0">
                        </div>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="addLedgerLine()"><i class="fas fa-plus me-1"></i> Add Another Line</button>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="add_voucher" class="btn btn-primary fw-bold"><i class="fas fa-save me-1"></i> Save Voucher</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal for View Voucher -->
<div class="modal fade" id="voucherModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" id="voucherModalContent"></div>
    </div>
</div>

<script>
function addLedgerLine() {
    var div = document.createElement('div');
    div.className = 'row g-2 mb-2 line-row';
    div.innerHTML = `
        <div class="col-md-6">
            <select name="account_id[]" class="form-select" required>
                <option value="">-- Select Account --</option>
                <?php foreach($accounts_list as $a): ?>
                    <option value="<?php echo $a['id']; ?>"><?php echo e($a['code'] . ' - ' . $a['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <input type="number" step="0.01" name="debit[]" class="form-control debit-input" placeholder="Debit (Dr)" value="0">
        </div>
        <div class="col-md-3">
            <input type="number" step="0.01" name="credit[]" class="form-control credit-input" placeholder="Credit (Cr)" value="0">
        </div>
    `;
    document.getElementById('ledgerLines').appendChild(div);
}

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
