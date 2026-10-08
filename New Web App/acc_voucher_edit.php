<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';

if (!isset($_SESSION['user_id']) || !app_has_perm('manage_erp')) {
    header("Location: dashboard.php");
    exit;
}
$role = $_SESSION['role'] ?? '';
$user = ['id' => $_SESSION['user_id'], 'role' => $role];

$v_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$voucher = $pdo->query("SELECT * FROM acc_vouchers WHERE id = $v_id AND (is_deleted = 0 OR is_deleted IS NULL)")->fetch(PDO::FETCH_ASSOC);

if (!$voucher) {
    die("Voucher not found or deleted.");
}

// Ensure non-admins can only edit pending vouchers (or according to business logic, here we allow admin to edit any, or others if pending)
if ($role !== 'admin' && $voucher['status'] !== 'Pending') {
    die("Only admin can edit approved vouchers.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_voucher'])) {
    $date = $_POST['date'] ?? $voucher['date'];
    $type = $_POST['type'] ?? $voucher['type'];
    $desc = $_POST['description'] ?? '';
    $ref = $_POST['reference'] ?? '';
    
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
        $stmt = $pdo->prepare("UPDATE acc_vouchers SET date=?, type=?, reference=?, description=?, department_id=? WHERE id=?");
        $stmt->execute([$date, $type, $ref, $desc, $department_id, $v_id]);
        
        // Delete old entries and insert new
        $pdo->prepare("DELETE FROM acc_ledger_entries WHERE voucher_id = ?")->execute([$v_id]);
        
        $entry_stmt = $pdo->prepare("INSERT INTO acc_ledger_entries (voucher_id, account_id, debit, credit, department_id) VALUES (?, ?, ?, ?, ?)");
        foreach ($clean_lines as $line) {
            $entry_stmt->execute([$v_id, $line[0], $line[1], $line[2], $department_id]);
        }
        $pdo->commit();
        header("Location: acc_vouchers.php?msg=" . urlencode("Voucher updated successfully!"));
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $err = "Failed: " . $e->getMessage();
    }
}

$entries = $pdo->query("SELECT * FROM acc_ledger_entries WHERE voucher_id = $v_id")->fetchAll(PDO::FETCH_ASSOC);
$accounts_list = $pdo->query("SELECT id, code, name FROM acc_chart_of_accounts WHERE is_deleted = 0 ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
$departments_list = $pdo->query("SELECT id, name FROM departments WHERE status = 'Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$title = "Edit Voucher - " . $voucher['voucher_no'];
include 'header.php';
?>

<div class="container py-4 urdu-text" style="font-family: 'Noto Nastaliq Urdu', serif;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-primary"><i class="fas fa-edit me-2"></i> واؤچر ترمیم (Edit Voucher) - <?php echo $voucher['voucher_no']; ?></h3>
        <a href="acc_vouchers.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i> واپس (Back)</a>
    </div>

    <?php if (!empty($err)): ?>
        <div class="alert alert-danger"><?php echo $err; ?></div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-body">
            <form method="POST">
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="fw-bold">تاریخ (Date)</label>
                        <input type="date" name="date" class="form-control" value="<?php echo $voucher['date']; ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="fw-bold">واؤچر کی قسم (Type)</label>
                        <select name="type" class="form-select" required>
                            <option value="Journal" <?php echo $voucher['type']=='Journal'?'selected':''; ?>>Journal Voucher (JV)</option>
                            <option value="Payment" <?php echo $voucher['type']=='Payment'?'selected':''; ?>>Payment Voucher (PV)</option>
                            <option value="Receipt" <?php echo $voucher['type']=='Receipt'?'selected':''; ?>>Receipt Voucher (RV)</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="fw-bold">شعبہ (Department)</label>
                        <select name="department_id" class="form-select">
                            <option value="">-- کوئی نہیں (None) --</option>
                            <?php foreach($departments_list as $d): ?>
                                <option value="<?php echo $d['id']; ?>" <?php echo $voucher['department_id']==$d['id']?'selected':''; ?>><?php echo e($d['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold">حوالہ نمبر (Reference #)</label>
                        <input type="text" name="reference" class="form-control" value="<?php echo htmlspecialchars($voucher['reference'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold">تفصیل (Description)</label>
                        <input type="text" name="description" class="form-control" value="<?php echo htmlspecialchars($voucher['description'] ?? ''); ?>">
                    </div>
                </div>

                <h5 class="fw-bold text-secondary border-bottom pb-2 mb-3">لیجر اندراجات (Ledger Entries)</h5>
                <div id="ledgerLines">
                    <?php foreach($entries as $entry): ?>
                    <div class="row g-2 mb-2 line-row">
                        <div class="col-md-6">
                            <select name="account_id[]" class="form-select select2" required>
                                <option value="">-- Select Account --</option>
                                <?php foreach($accounts_list as $a): ?>
                                    <option value="<?php echo $a['id']; ?>" <?php echo $entry['account_id']==$a['id']?'selected':''; ?>><?php echo e($a['code'] . ' - ' . $a['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <input type="number" step="0.01" name="debit[]" class="form-control debit-input" placeholder="Debit (Dr)" value="<?php echo $entry['debit']; ?>">
                        </div>
                        <div class="col-md-3">
                            <input type="number" step="0.01" name="credit[]" class="form-control credit-input" placeholder="Credit (Cr)" value="<?php echo $entry['credit']; ?>">
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="addLedgerLine()"><i class="fas fa-plus me-1"></i> مزید لائن شامل کریں (Add Line)</button>
                
                <hr class="my-4">
                <div class="text-end">
                    <button type="submit" name="edit_voucher" class="btn btn-primary fw-bold px-4"><i class="fas fa-save me-1"></i> محفوظ کریں (Save Changes)</button>
                </div>
            </form>
        </div>
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
</script>

<?php include 'footer.php'; ?>
