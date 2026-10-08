<?php
require_once 'db.php';
require_once 'finance_accounting_bridge.php';
include 'header.php';

function finance_type_label($raw) {
    return strtolower(trim((string)$raw)) === 'expense' ? 'Expense' : 'Income';
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die('Invalid request');
}

$stmt = $pdo->prepare("SELECT * FROM finance WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    die('Record not found');
}

// User privacy check: non-admin can only edit their own finance records
if (($_SESSION['role'] ?? '') !== 'admin' && (int)($row['user_id'] ?? 0) !== (int)($_SESSION['user_id'] ?? 0)) {
    die('غیر متعلقہ سیکیورٹی سیشن: آپ صرف اپنا ریکارڈ تبدیل کر سکتے ہیں۔');
}

if (isset($_GET['del'])) {
    $pdo->prepare("DELETE FROM finance WHERE id = ?")->execute([$id]);
    finance_accounting_delete_voucher($pdo, $id);
    echo "<script>window.location='simple_finance.php';</script>";
    exit;
}

if (isset($_POST['update_transaction'])) {
    $type = finance_type_label($_POST['type'] ?? 'income');
    $date = $_POST['date'] ?? date('Y-m-d');
    $headId = (int)($_POST['head_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $donorName = $type === 'Income' ? trim($_POST['donor_name'] ?? '') : '';
    $donorAddress = $type === 'Income' ? trim($_POST['donor_address'] ?? '') : '';
    $manualReceipt = $type === 'Income' ? trim($_POST['manual_receipt_no'] ?? '') : '';

    $pdo->prepare("UPDATE finance SET date=?, type=?, head_id=?, amount=?, description=?, donor_name=?, donor_address=?, manual_receipt_no=? WHERE id=?")
        ->execute([
            $date,
            $type,
            $headId,
            $amount,
            $description,
            $donorName !== '' ? $donorName : null,
            $donorAddress !== '' ? $donorAddress : null,
            $manualReceipt !== '' ? $manualReceipt : null,
            $id,
        ]);
    finance_accounting_post($pdo, $id);

    echo "<script>alert('ریکارڈ اپ ڈیٹ ہو گیا۔'); window.location='simple_finance.php';</script>";
    exit;
}

sync_all_heads_with_chart_and_departments($pdo);
$heads = $pdo->query("SELECT * FROM heads WHERE is_deleted = 0 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$isIncome = strtolower((string)$row['type']) === 'income';
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold text-primary mb-0"><i class="fas fa-edit me-2"></i> فنانس انٹری ایڈٹ کریں</h4>
        <div>
            <?php if ($isIncome): ?>
                <a href="print_receipt.php?id=<?php echo $row['id']; ?>" target="_blank" class="btn btn-outline-success btn-sm fw-bold">رسید</a>
            <?php endif; ?>
            <a href="simple_finance.php" class="btn btn-outline-secondary btn-sm fw-bold">واپس جائیں</a>
        </div>
    </div>

    <div class="card shadow border-top border-5 border-primary">
        <div class="card-body">
            <form method="POST">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="fw-bold">قسم</label>
                        <select name="type" id="type" class="form-select fw-bold" onchange="toggleFields()" required>
                            <option value="Income" <?php echo $isIncome ? 'selected' : ''; ?>>آمدن (Income)</option>
                            <option value="Expense" <?php echo !$isIncome ? 'selected' : ''; ?>>خرچ (Expense)</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold">تاریخ</label>
                        <input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($row['date'] ?? date('Y-m-d')); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold">کھاتہ / شعبہ (Head)</label>
                        <select name="head_id" class="form-select" required>
                            <option value="">منتخب کریں</option>
                            <?php foreach ($heads as $h): ?>
                                <option value="<?php echo $h['id']; ?>" <?php echo (int)($row['head_id'] ?? 0) === (int)$h['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($h['name'] . ' (' . ($h['type'] ?? '') . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold">رقم (PKR)</label>
                        <input type="number" step="0.01" name="amount" class="form-control" value="<?php echo htmlspecialchars($row['amount'] ?? '0'); ?>" required>
                    </div>
                </div>

                <div class="row g-3 mt-1" id="incomeFields">
                    <div class="col-md-4">
                        <label class="fw-bold">دینے والے کا نام</label>
                        <input type="text" name="donor_name" class="form-control" value="<?php echo htmlspecialchars($row['donor_name'] ?? ''); ?>">
                    </div>
                    <div class="col-md-5">
                        <label class="fw-bold">پتہ</label>
                        <input type="text" name="donor_address" class="form-control" value="<?php echo htmlspecialchars($row['donor_address'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold">دستی رسید نمبر</label>
                        <input type="text" name="manual_receipt_no" class="form-control" value="<?php echo htmlspecialchars($row['manual_receipt_no'] ?? ''); ?>">
                    </div>
                </div>

                <div class="mt-3">
                    <label class="fw-bold">تفصیل / نوٹس</label>
                    <textarea name="description" class="form-control" rows="3"><?php echo htmlspecialchars($row['description'] ?? ''); ?></textarea>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-4">
                    <a href="edit_finance.php?id=<?php echo $row['id']; ?>&del=1" class="btn btn-outline-danger fw-bold" onclick="return confirm('کیا آپ واقعی یہ انٹری ختم کرنا چاہتے ہیں؟');">حذف کریں (Delete)</a>
                    <button type="submit" name="update_transaction" class="btn btn-primary px-4 fw-bold">محفوظ کریں (Update)</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleFields() {
    var t = document.getElementById('type').value;
    document.getElementById('incomeFields').style.display = (t === 'Income') ? 'flex' : 'none';
}
toggleFields();
</script>
<?php include 'footer.php'; ?>
