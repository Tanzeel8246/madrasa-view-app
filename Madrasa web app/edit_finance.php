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

$cur_tenant_id = (int)get_current_tenant_id();
$stmt = $pdo->prepare("SELECT * FROM finance WHERE id = ? AND tenant_id = ? LIMIT 1");
$stmt->execute([$id, $cur_tenant_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    die('Record not found');
}

// User privacy check: non-admin can only edit their own finance records
$user_role_lower = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin = in_array($user_role_lower, ['admin', 'superadmin', 'super_admin', 'administrator']);
if (!$is_admin && (int)($row['user_id'] ?? 0) !== (int)($_SESSION['user_id'] ?? 0)) {
    die('غیر متعلقہ سیکیورٹی سیشن: آپ صرف اپنا ریکارڈ تبدیل کر سکتے ہیں۔');
}

$has_otp_permission = $is_admin || finance_has_valid_edit_permission($pdo, $id, (int)$_SESSION['user_id']);
$otp_message = '';
$otp_error = '';

if (isset($_POST['request_edit_otp'])) {
    $res = finance_request_edit_otp($pdo, $id, (int)$_SESSION['user_id'], $_SESSION['user_name'] ?? 'User', $row['system_type'] ?? 'Madrasa', $cur_tenant_id);
    if ($res['status'] === 'success') {
        $otp_message = $res['message'] . ' (ایڈمن کے ڈیش بورڈ پر کوڈ پہنچ گیا ہے)';
    } else {
        $otp_error = $res['message'] ?? 'OTP جاری نہیں ہو سکا۔';
    }
}

if (isset($_POST['verify_edit_otp'])) {
    $entered_otp = trim((string)($_POST['otp_code'] ?? ''));
    $res = finance_verify_edit_otp($pdo, $id, $entered_otp, (int)$_SESSION['user_id'], $cur_tenant_id);
    if ($res['status'] === 'success') {
        $has_otp_permission = true;
        $otp_message = $res['message'];
    } else {
        $otp_error = $res['message'] ?? 'غلط OTP کوڈ درج کیا گیا ہے۔';
    }
}

if (isset($_GET['del'])) {
    if (!$is_admin && !$has_otp_permission) {
        die('سیکیورٹی نوٹس: اس انٹری کو ڈیلیٹ کرنے کے لیے ایڈمن کی اجازت (OTP) درکار ہے۔');
    }
    try { finance_accounting_delete_voucher($pdo, $id, $cur_tenant_id); } catch (Throwable $e) {}
    
    $deleted_count = 0;
    try {
        $delStmt = $pdo->prepare("
            UPDATE finance 
            SET is_deleted = 1, 
                deleted_at = NOW(), 
                original_receipt_no = COALESCE(original_receipt_no, receipt_no, CAST(computer_receipt_no AS CHAR)),
                computer_receipt_no = NULL 
            WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)
        ");
        $delStmt->execute([$id, $cur_tenant_id]);
        $deleted_count = $delStmt->rowCount();
    } catch (Throwable $e) {}

    // Fallback: If tenant condition matched 0 rows, update by ID
    if ($deleted_count === 0) {
        try {
            $pdo->prepare("
                UPDATE finance 
                SET is_deleted = 1, 
                    deleted_at = NOW(), 
                    original_receipt_no = COALESCE(original_receipt_no, receipt_no, CAST(computer_receipt_no AS CHAR)),
                    computer_receipt_no = NULL 
                WHERE id = ?
            ")->execute([$id]);
        } catch (Throwable $e2) {}
    }

    // Also soft delete in masjid_finance if present
    try {
        $pdo->prepare("
            UPDATE masjid_finance 
            SET is_deleted = 1, 
                deleted_at = NOW(), 
                original_receipt_no = COALESCE(original_receipt_no, receipt_no, CAST(computer_receipt_no AS CHAR)),
                computer_receipt_no = NULL 
            WHERE id = ?
        ")->execute([$id]);
    } catch (Throwable $e3) {}

    echo "<script>window.location='simple_finance.php?msg=deleted';</script>";
    exit;
}

if (isset($_POST['update_transaction'])) {
    if (!$is_admin && !$has_otp_permission) {
        die('سیکیورٹی نوٹس: اس انٹری میں ترمیم کرنے کے لیے ایڈمن کی اجازت (OTP) درکار ہے۔');
    }
    $type = finance_type_label($_POST['type'] ?? 'income');
    $date = $_POST['date'] ?? date('Y-m-d');
    $headId = (int)($_POST['head_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $donorName = $type === 'Income' ? trim($_POST['donor_name'] ?? '') : '';
    $donorAddress = $type === 'Income' ? trim($_POST['donor_address'] ?? '') : '';
    $manualReceipt = $type === 'Income' ? trim($_POST['manual_receipt_no'] ?? '') : '';
    $defCashId = function_exists('finance_accounting_ensure_account') ? finance_accounting_ensure_account($pdo, '1001', 'Cash in Hand', 'Asset', $cur_tenant_id) : null;
    $accountId = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : $defCashId;
    $paymentMethod = $_POST['payment_method'] ?? 'Cash';

    $billPath = $row['bill_image'] ?? null;
    if (!empty($_FILES['bill_file']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['bill_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'])) $ext = 'jpg';
        $uploadDir = __DIR__ . '/uploads/bills/';
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);
        $fn = 'bill_' . $id . '_' . time() . '.' . $ext;
        if (move_uploaded_file($_FILES['bill_file']['tmp_name'], $uploadDir . $fn)) {
            $billPath = 'uploads/bills/' . $fn;
        }
    }

    try {
        $pdo->prepare("UPDATE finance SET date=?, type=?, head_id=?, amount=?, description=?, donor_name=?, donor_address=?, manual_receipt_no=?, account_id=?, payment_method=?, bill_image=? WHERE id=? AND tenant_id=?")
            ->execute([
                $date,
                $type,
                $headId,
                $amount,
                $description,
                $donorName !== '' ? $donorName : null,
                $donorAddress !== '' ? $donorAddress : null,
                $manualReceipt !== '' ? $manualReceipt : null,
                $accountId,
                $paymentMethod,
                $billPath,
                $id,
                $cur_tenant_id
            ]);
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE finance SET date=?, type=?, head_id=?, amount=?, description=?, donor_name=?, donor_address=?, manual_receipt_no=?, bill_image=? WHERE id=? AND tenant_id=?")
            ->execute([
                $date,
                $type,
                $headId,
                $amount,
                $description,
                $donorName !== '' ? $donorName : null,
                $donorAddress !== '' ? $donorAddress : null,
                $manualReceipt !== '' ? $manualReceipt : null,
                $billPath,
                $id,
                $cur_tenant_id
            ]);
    }
    finance_accounting_post($pdo, $id);

    echo "<script>alert('ریکارڈ اپ ڈیٹ ہو گیا۔'); window.location='simple_finance.php';</script>";
    exit;
}

if (function_exists('sync_all_heads_with_chart_and_departments')) {
    try { sync_all_heads_with_chart_and_departments($pdo); } catch (Throwable $e) {}
}
$heads = [];
try {
    $heads = $pdo->query("SELECT * FROM heads WHERE (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = $cur_tenant_id ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    try {
        $heads = $pdo->query("SELECT * FROM heads WHERE tenant_id = $cur_tenant_id ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) {
        $heads = [];
    }
}
$payment_accounts = [];
try {
    $payment_accounts = $pdo->query("SELECT id, code, name FROM acc_chart_of_accounts WHERE type = 'Asset' AND (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = $cur_tenant_id ORDER BY code ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
if (empty($payment_accounts)) {
    $payment_accounts = [
        ['id' => 1, 'code' => '1001', 'name' => 'Cash in Hand (نقد رقم)'],
        ['id' => 2, 'code' => '1002', 'name' => 'Bank Account (بینک کھاتہ)']
    ];
}
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

    <?php if ($otp_message): ?>
        <div class="alert alert-success alert-dismissible fade show fw-bold shadow-sm mb-3">
            <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($otp_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($otp_error): ?>
        <div class="alert alert-danger alert-dismissible fade show fw-bold shadow-sm mb-3">
            <i class="fas fa-exclamation-triangle me-2"></i> <?php echo htmlspecialchars($otp_error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!$has_otp_permission): ?>
        <!-- OTP اجازت نامہ ماڈل برائے غیر ایڈمن صارف -->
        <div class="card shadow-lg border-0 border-top border-5 border-warning mb-4">
            <div class="card-body p-4 text-center">
                <div class="mb-3">
                    <span class="badge bg-warning text-dark fs-5 px-3 py-2 rounded-pill shadow-sm">
                        <i class="fas fa-lock me-2"></i> ایڈمن OTP پروٹیکشن (ترمیم کی اجازت درکار ہے)
                    </span>
                </div>
                <h5 class="fw-bold text-secondary mb-2">محترم صارف! یہ انٹری پہلے سے سسٹم میں محفوظ ہے۔</h5>
                <p class="text-muted mb-4" style="max-width: 600px; margin: 0 auto;">
                    مالیاتی شفافیت اور آڈٹ ضابطے کے تحت، ایک بار انٹری محفوظ ہونے کے بعد ایڈمن کی اجازت کے بغیر اس میں تبدیلی کی اجازت نہیں ہے۔ براہِ کرم نیچے دیے گئے بٹن سے ایڈمن کو OTP درخواست بھیجیں اور ایڈمن سے 6 ہندسوں کا کوڈ حاصل کر کے درج کریں۔
                    <br><strong class="text-primary mt-1 d-inline-block">نوٹ: یہ کوڈ تصدیق کے بعد 24 گھنٹے تک کارآمد رہے گا۔</strong>
                </p>

                <div class="bg-light rounded p-3 mb-4 text-start mx-auto" style="max-width: 500px; border-right: 4px solid #ffc107;">
                    <div class="row g-2">
                        <div class="col-6"><strong>انٹری ID:</strong> #<?php echo $row['id']; ?></div>
                        <div class="col-6"><strong>قسم:</strong> <?php echo $isIncome ? 'آمدن (Income)' : 'خرچ (Expense)'; ?></div>
                        <div class="col-6"><strong>تاریخ:</strong> <?php echo htmlspecialchars($row['date']); ?></div>
                        <div class="col-6"><strong>رقم:</strong> Rs. <?php echo number_format($row['amount'], 2); ?></div>
                        <div class="col-12"><strong>تفصیل:</strong> <?php echo htmlspecialchars($row['description'] ?? '-'); ?></div>
                    </div>
                </div>

                <div class="d-flex flex-column align-items-center justify-content-center gap-3">
                    <form method="POST" class="d-inline-block">
                        <button type="submit" name="request_edit_otp" class="btn btn-warning btn-lg fw-bold px-4 shadow-sm">
                            <i class="fas fa-paper-plane me-2"></i> ایڈمن کو OTP درخواست بھیجیں
                        </button>
                    </form>

                    <form method="POST" class="mt-3" style="max-width: 350px; width: 100%;">
                        <label class="fw-bold mb-2 text-dark">ایڈمن سے حاصل شدہ 6 ہندسوں کا کوڈ درج کریں:</label>
                        <div class="input-group mb-3 shadow-sm">
                            <input type="text" name="otp_code" class="form-control form-control-lg text-center fw-bold letter-spacing-2" placeholder="------" maxlength="6" pattern="[0-9]{6}" required autofocus>
                            <button type="submit" name="verify_edit_otp" class="btn btn-success fw-bold px-3">
                                <i class="fas fa-unlock me-1"></i> انٹری کھولیں
                            </button>
                        </div>
                        <a href="simple_finance.php" class="text-secondary small text-decoration-none">
                            <i class="fas fa-times me-1"></i> منسوخ کریں اور واپس جائیں
                        </a>
                    </form>
                </div>
            </div>
        </div>
    <?php else: ?>

    <div class="card shadow border-top border-5 border-primary">
        <div class="card-body">
            <?php
            $creator_display = !empty($row['user_name']) ? $row['user_name'] : '';
            if (empty($creator_display) && !empty($row['user_id'])) {
                try {
                    $u_stmt = $pdo->prepare("SELECT name, username FROM admins WHERE id = ? LIMIT 1");
                    $u_stmt->execute([$row['user_id']]);
                    $u_info = $u_stmt->fetch(PDO::FETCH_ASSOC);
                    if ($u_info) {
                        $creator_display = !empty($u_info['username']) ? $u_info['username'] : $u_info['name'];
                    }
                } catch (Throwable $e) {}
            }
            ?>
            <div class="alert alert-info py-2 px-3 mb-3 d-flex flex-wrap align-items-center justify-content-between">
                <div>
                    <i class="fas fa-user-shield me-2 fs-5 text-primary"></i>
                    <strong>اصل اندراج کنندہ (Original Creator):</strong> 
                    <span class="badge bg-primary fs-6 ms-1"><?php echo htmlspecialchars($creator_display ?: 'صارف'); ?></span>
                    <?php if (!$is_admin): ?>
                        <span class="badge bg-success ms-2"><i class="fas fa-check me-1"></i> ایڈمن OTP تصدیق شدہ (24 گھنٹے اجازت)</span>
                    <?php endif; ?>
                </div>
                <small class="text-muted"><i class="fas fa-check-circle text-success me-1"></i> ایڈٹ کرنے کے باوجود یہ انٹری اسی صارف کے کھاتے اور واؤچر سے وابستہ رہے گی۔</small>
            </div>

            <form method="POST" enctype="multipart/form-data">
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

                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label class="fw-bold">ادائیگی / وصولی کھاتہ (Account)</label>
                        <select name="account_id" class="form-select">
                            <?php foreach ($payment_accounts as $pa): ?>
                                <option value="<?php echo $pa['id']; ?>" <?php echo ((int)($row['account_id'] ?? 0) === (int)$pa['id'] || (empty($row['account_id']) && $pa['code'] == '1001')) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pa['name'] . ' (' . $pa['code'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold">طریقہ کار (Payment Method)</label>
                        <select name="payment_method" class="form-select">
                            <option value="Cash" <?php echo ($row['payment_method'] ?? '') === 'Cash' ? 'selected' : ''; ?>>نقد رقم (Cash)</option>
                            <option value="Bank Transfer" <?php echo ($row['payment_method'] ?? '') === 'Bank Transfer' ? 'selected' : ''; ?>>آن لائن بینک ٹرانسفر (Online Transfer)</option>
                            <option value="Cheque" <?php echo ($row['payment_method'] ?? '') === 'Cheque' ? 'selected' : ''; ?>>چیک (Cheque)</option>
                        </select>
                    </div>
                </div>

                <!-- خرچ کا بل اپلوڈ سیکشن -->
                <div class="card bg-light border p-3 mt-3">
                    <div class="row align-items-center">
                        <div class="col-md-7">
                            <label class="fw-bold text-dark mb-1">
                                <i class="fas fa-file-invoice-dollar text-primary me-2"></i> خرچ کا بل / رسید منسلک کریں (Bill / Receipt Attachment):
                            </label>
                            <input type="file" name="bill_file" class="form-control" accept="image/*,.pdf">
                            <small class="text-muted">تصویر (JPG, PNG, WebP) یا PDF دستاویز اپلوڈ کی جا سکتی ہے۔</small>
                        </div>
                        <div class="col-md-5 text-md-end mt-2 mt-md-0">
                            <?php if (!empty($row['bill_image'])): ?>
                                <span class="badge bg-success fs-6 p-2">
                                    <i class="fas fa-paperclip me-1"></i> بل منسلک ہے
                                </span>
                                <a href="<?php echo htmlspecialchars($row['bill_image']); ?>" target="_blank" class="btn btn-sm btn-outline-primary fw-bold ms-2 shadow-sm">
                                    <i class="fas fa-external-link-alt me-1"></i> اصل بل دیکھیں
                                </a>
                            <?php else: ?>
                                <span class="badge bg-secondary p-2">کوئی بل منسلک نہیں ہے</span>
                            <?php endif; ?>
                        </div>
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
    <?php endif; ?>
</div>

<script>
function toggleFields() {
    var t = document.getElementById('type').value;
    document.getElementById('incomeFields').style.display = (t === 'Income') ? 'flex' : 'none';
}
toggleFields();
</script>
<?php include 'footer.php'; ?>
