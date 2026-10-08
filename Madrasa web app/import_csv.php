<?php
session_start();
require 'db.php';
require_once 'finance_accounting_bridge.php';

// Ensure only admins and nazim can access
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'nazim'])) {
    die("رسائی مسترد کر دی گئی ہے۔ براہ کرم بطور ایڈمن لاگ ان ہوں۔");
}

$cur_tenant_id = function_exists('get_current_tenant_id') ? (int)get_current_tenant_id() : 1;
$cur_tenant_id = max(1, $cur_tenant_id);

$message = '';

// 1. سابقہ آمدن رسید نمبرز درست و ری سیکوینس کرنے کا ہینڈلر
if (isset($_POST['resequence_receipts'])) {
    try {
        $res = finance_resequence_all_receipt_numbers($pdo, $cur_tenant_id);
        $totalFixed = (int)($res['total_updated'] ?? 0);
        $details = [];
        if (!empty($res['users'])) {
            foreach ($res['users'] as $u) {
                $details[] = "صارف #" . $u['user_id'] . " (" . $u['system_type'] . "): " . $u['count'] . " رسیدیں (نمبر " . $u['start_receipt'] . " تا " . $u['end_receipt'] . ")";
            }
        }
        $detailStr = !empty($details) ? ("<br><small class='mt-1 d-block'>" . implode(' | ', $details) . "</small>") : "";
        $message = "<div class='alert alert-success shadow-sm alert-dismissible fade show' role='alert'>
            <i class='fas fa-check-circle me-1'></i> تمام سابقہ آمدن رسید نمبرز کامیابی کے ساتھ تاریخ کے تسلسل اور صارف کے بلاک کے مطابق درست و مرتب کر دیے گئے ہیں۔ کل درست کردہ اندراجات: <strong>$totalFixed</strong>$detailStr
            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
        </div>";
    } catch (Throwable $e) {
        $message = "<div class='alert alert-danger shadow-sm alert-dismissible fade show' role='alert'>
            <i class='fas fa-exclamation-triangle me-1'></i> رسید نمبرز درست کرنے میں خرابی پیش آئی: " . htmlspecialchars($e->getMessage()) . "
            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
        </div>";
    }
}

// 1-B. ریکوری ہینڈلر: صارف #1 یا منتخب صارف کی تمام غائب/مسجد/ٹریش انٹریز کو فوری بحال اور مدرسہ میں لانے کا ٹول
if (isset($_POST['recover_user_entries'])) {
    try {
        $targetUid = (int)($_POST['target_user_id'] ?? 1);
        $t_c = ($cur_tenant_id == 1) ? "(tenant_id = 1 OR tenant_id IS NULL OR tenant_id = 0)" : "tenant_id = $cur_tenant_id";

        // 1. اگر کوئی انٹری ڈیلیٹ شدہ ہے تو بحال کریں
        $updDel = $pdo->prepare("UPDATE finance SET is_deleted = 0, deleted_at = NULL WHERE user_id = ? AND is_deleted = 1 AND $t_c");
        $updDel->execute([$targetUid]);
        $restoredFromTrash = $updDel->rowCount();

        // 2. اگر کوئی انٹری مسجد (system_type = 'Masjid') میں چلی گئی ہے تو اسے مدرسہ میں تبدیل کریں
        $updSys = $pdo->prepare("UPDATE finance SET system_type = 'Madrasa' WHERE user_id = ? AND system_type = 'Masjid' AND $t_c");
        $updSys->execute([$targetUid]);
        $convertedToMadrasa = $updSys->rowCount();

        // 3. رسید نمبرز کو مسلسل ترتیب میں دوبارہ جاری کریں
        finance_resequence_all_receipt_numbers($pdo, $cur_tenant_id);

        $totRec = $restoredFromTrash + $convertedToMadrasa;
        $message = "<div class='alert alert-success shadow-sm alert-dismissible fade show' role='alert'>
            <i class='fas fa-check-circle me-1'></i> ریکوری کامیابی سے مکمل ہو گئی! کل <strong>$totRec</strong> انٹریز بحال ہو کر مدرسہ فنانس میں شامل ہو چکی ہیں (بحال شدہ: $restoredFromTrash، مدرسہ میں منتقل: $convertedToMadrasa)۔ رسید نمبرز بھی یکساں تسلسل کے ساتھ دوبارہ سیٹ کر دیے گئے ہیں۔
            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
        </div>";
    } catch (Throwable $e) {
        $message = "<div class='alert alert-danger shadow-sm alert-dismissible fade show' role='alert'>
            <i class='fas fa-exclamation-triangle me-1'></i> ریکوری میں خرابی پیش آئی: " . htmlspecialchars($e->getMessage()) . "
            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
        </div>";
    }
}

// 2. CSV فائل امپورٹ کا ہینڈلر
if (isset($_POST['import']) && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file']['tmp_name'];
    $type = $_POST['entry_type'] ?? 'Revenue'; // 'Revenue' or 'Expense'
    $financeType = ($type === 'Revenue') ? 'Income' : 'Expense';
    $sys = $_POST['system_type'] ?? ($_SESSION['system_type'] ?? 'Madrasa');
    if (!in_array($sys, ['Madrasa', 'Masjid'])) {
        $sys = 'Madrasa';
    }

    if (empty($file) || !is_uploaded_file($file)) {
        $message = "<div class='alert alert-danger shadow-sm alert-dismissible fade show' role='alert'>
            <i class='fas fa-exclamation-triangle me-1'></i> براہ کرم درست CSV فائل منتخب کریں!
            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
        </div>";
    } else {
        $handle = fopen($file, "r");
        if ($handle !== FALSE) {
            $header = fgetcsv($handle, 1000, ","); // skip header
            $imported = 0;
            $newHeads = 0;

            // منتخب صارف کا تعین
            $selUser = $_POST['selected_user'] ?? '';
            $selParts = explode('|', $selUser);
            $importUserId = (int)($_SESSION['user_id'] ?? 1);
            $importUserName = $_SESSION['username'] ?? 'Admin';
            if (count($selParts) == 2) {
                $importUserId = (int)$selParts[0];
                $importUserName = trim($selParts[1]);
            }

            // اگر آمدن ہے تو پہلے سے جنریٹڈ رسید نمبر کے تسلسل میں اگلا نمبر حاصل کریں
            $currentReceiptNo = null;
            if ($financeType === 'Income') {
                $currentReceiptNo = next_user_receipt_no($pdo, $importUserId, $sys, $cur_tenant_id);
            }

            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if (count($data) < 6) continue; // ensure valid row

                $date_raw = trim($data[1]);
                // Convert DD/MM/YYYY to YYYY-MM-DD
                $date_parts = explode('/', $date_raw);
                if (count($date_parts) == 3) {
                    $date = $date_parts[2] . '-' . $date_parts[1] . '-' . $date_parts[0];
                } else {
                    $date = date('Y-m-d'); // fallback
                }

                $category = trim($data[2]);
                $description = trim($data[3]);
                $amount_raw = trim($data[4]);
                $amount = floatval(str_replace([' ', ','], ['', ''], $amount_raw));
                $recipient = trim($data[5]);

                if (empty($category) || $amount <= 0) continue;

                // Check if head exists
                $headStmt = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE name = ? AND (system_type = ? OR system_type IS NULL OR system_type = '') AND (is_deleted = 0 OR is_deleted IS NULL)");
                $headStmt->execute([$category, $sys]);
                $head = $headStmt->fetch(PDO::FETCH_ASSOC);

                if ($head) {
                    $head_id = $head['id'];
                } else {
                    // Create new head
                    $code = rand(10000, 99999);
                    $insHead = $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, system_type, tenant_id, is_deleted) VALUES (?, ?, ?, ?, ?, 0)");
                    $insHead->execute([(string)$code, $category, $type, $sys, $cur_tenant_id]);
                    $head_id = $pdo->lastInsertId();
                    $newHeads++;
                }

                // رسید نمبر مختص کریں (صرف آمدن کے لیے تسلسلی نمبر)
                $compReceiptNo = null;
                $formattedReceiptNo = null;
                if ($financeType === 'Income') {
                    $compReceiptNo = $currentReceiptNo;
                    $formattedReceiptNo = ($sys === 'Masjid') ? ('M-' . $compReceiptNo) : (string)$compReceiptNo;
                    $currentReceiptNo++; // ہر انٹری کے بعد اگلا تسلسلی نمبر
                }

                // Insert into finance
                if (!isset($hasReceiptNoCol)) {
                    $hasReceiptNoCol = false;
                    try {
                        $pdo->exec("ALTER TABLE finance ADD COLUMN computer_receipt_no INT DEFAULT NULL");
                        $pdo->exec("ALTER TABLE finance ADD COLUMN receipt_no VARCHAR(100) DEFAULT NULL");
                        $chkCol = $pdo->query("SHOW COLUMNS FROM finance LIKE 'receipt_no'")->fetchAll();
                        $hasReceiptNoCol = !empty($chkCol);
                    } catch (Throwable $eCol) {}
                }

                if ($hasReceiptNoCol) {
                    $insFin = $pdo->prepare("
                        INSERT INTO finance 
                        (type, amount, description, date, head_id, donor_name, user_id, user_name, computer_receipt_no, receipt_no, system_type, tenant_id, is_deleted) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
                    ");
                    $insFin->execute([
                        $financeType,
                        $amount,
                        $description,
                        $date,
                        $head_id,
                        $recipient,
                        $importUserId,
                        $importUserName,
                        $compReceiptNo,
                        $formattedReceiptNo,
                        $sys,
                        $cur_tenant_id
                    ]);
                } else {
                    $insFin = $pdo->prepare("
                        INSERT INTO finance 
                        (type, amount, description, date, head_id, donor_name, user_id, user_name, computer_receipt_no, system_type, tenant_id, is_deleted) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
                    ");
                    $insFin->execute([
                        $financeType,
                        $amount,
                        $description,
                        $date,
                        $head_id,
                        $recipient,
                        $importUserId,
                        $importUserName,
                        $compReceiptNo,
                        $sys,
                        $cur_tenant_id
                    ]);
                }
                
                $finance_id = $pdo->lastInsertId();
                if (function_exists('finance_accounting_post')) {
                    try {
                        finance_accounting_post($pdo, $finance_id);
                    } catch (Throwable $ePost) {}
                }

                $imported++;
            }
            fclose($handle);
            $receiptMsg = "";
            if ($financeType === 'Income' && $imported > 0) {
                $startNo = $currentReceiptNo - $imported;
                $endNo = $currentReceiptNo - 1;
                $receiptMsg = "<br><small class='mt-1 d-block'>جاری کردہ رسید نمبرز: <strong>$startNo</strong> تا <strong>$endNo</strong> (مسلسل تسلسل کے ساتھ)</small>";
            }
            $message = "<div class='alert alert-success shadow-sm alert-dismissible fade show' role='alert'>
                <i class='fas fa-check-circle me-1'></i> <strong>$imported</strong> انٹریز کامیابی کے ساتھ امپورٹ کر دی گئیں! ($newHeads نئے کھاتے شامل ہوئے)$receiptMsg
                <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
            </div>";
        } else {
            $message = "<div class='alert alert-danger shadow-sm alert-dismissible fade show' role='alert'>
                <i class='fas fa-exclamation-triangle me-1'></i> فائل پڑھنے میں خرابی پیش آئی!
                <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
            </div>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فنانس CSV امپورٹ و درستگی رسید نمبرز</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Jameel Noori Nastaleeq', 'Segoe UI', Tahoma, Arial, sans-serif; background-color: #f4f6f9; }
        .card-custom { border-radius: 12px; border: none; box-shadow: 0 4px 15px rgba(0,0,0,0.08); background: #ffffff; }
        .header-title { font-weight: 700; color: #198754; }
    </style>
</head>
<body>
    <div class="container py-5" style="max-width: 650px;">
        <!-- Header -->
        <div class="card card-custom p-4 mb-4 text-center">
            <h2 class="header-title mb-2"><i class="fas fa-file-csv me-2 text-success"></i> فنانس CSV امپورٹ ٹول</h2>
            <p class="text-muted mb-0">خودکار تسلسلی رسید نمبر کے ساتھ فنانس انٹریز شامل کریں</p>
        </div>

        <?= $message ?>

        <?php
        // لائیو تشخیصی معائنہ برائے صارف #1
        $diag_u1 = [];
        try {
            $t_c = ($cur_tenant_id == 1) ? "(tenant_id = 1 OR tenant_id IS NULL OR tenant_id = 0)" : "tenant_id = $cur_tenant_id";
            
            // کل آمدن انٹریز
            $diag_u1['total_income'] = (int)$pdo->query("SELECT COUNT(*) FROM finance WHERE user_id = 1 AND type = 'Income' AND $t_c")->fetchColumn();
            // مدرسہ فعال
            $diag_u1['madrasa_active'] = (int)$pdo->query("SELECT COUNT(*) FROM finance WHERE user_id = 1 AND type = 'Income' AND (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '') AND (is_deleted = 0 OR is_deleted IS NULL) AND $t_c")->fetchColumn();
            // مسجد فعال
            $diag_u1['masjid_active'] = (int)$pdo->query("SELECT COUNT(*) FROM finance WHERE user_id = 1 AND type = 'Income' AND system_type = 'Masjid' AND (is_deleted = 0 OR is_deleted IS NULL) AND $t_c")->fetchColumn();
            // ری سائیکل بن میں
            $diag_u1['in_trash'] = (int)$pdo->query("SELECT COUNT(*) FROM finance WHERE user_id = 1 AND type = 'Income' AND is_deleted = 1 AND $t_c")->fetchColumn();
            // دیگر مہینوں میں
            $cur_m_start = date('Y-m-01');
            $cur_m_end = date('Y-m-t');
            $diag_u1['cur_month'] = (int)$pdo->query("SELECT COUNT(*) FROM finance WHERE user_id = 1 AND type = 'Income' AND (is_deleted = 0 OR is_deleted IS NULL) AND (date BETWEEN '$cur_m_start' AND '$cur_m_end') AND $t_c")->fetchColumn();
            $diag_u1['other_month'] = (int)$pdo->query("SELECT COUNT(*) FROM finance WHERE user_id = 1 AND type = 'Income' AND (is_deleted = 0 OR is_deleted IS NULL) AND (date < '$cur_m_start' OR date > '$cur_m_end') AND $t_c")->fetchColumn();
        } catch (Throwable $eD) {}
        ?>

        <!-- تشخیصی و ریکوری باکس برائے صارف #1 -->
        <div class="card card-custom p-4 mb-4 border-start border-4 border-info">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-search-plus me-1 text-info"></i> صارف #1 کی انٹریز کا لائیو اسٹیٹس
                </h5>
                <span class="badge bg-primary fs-6">ڈیٹا بیس میں کل محفوظ: <?= ($diag_u1['total_income'] ?? 0) ?></span>
            </div>

            <div class="row g-2 text-center mb-3">
                <div class="col-4">
                    <div class="p-2 border rounded bg-light">
                        <small class="text-muted d-block fw-bold">مدرسہ فعال</small>
                        <strong class="text-success fs-5"><?= ($diag_u1['madrasa_active'] ?? 0) ?></strong>
                    </div>
                </div>
                <div class="col-4">
                    <div class="p-2 border rounded bg-light">
                        <small class="text-muted d-block fw-bold">مسجد فعال</small>
                        <strong class="text-info fs-5"><?= ($diag_u1['masjid_active'] ?? 0) ?></strong>
                    </div>
                </div>
                <div class="col-4">
                    <div class="p-2 border rounded bg-light">
                        <small class="text-muted d-block fw-bold">ری سائیکل بن</small>
                        <strong class="text-danger fs-5"><?= ($diag_u1['in_trash'] ?? 0) ?></strong>
                    </div>
                </div>
            </div>

            <?php if (($diag_u1['other_month'] ?? 0) > 0): ?>
                <div class="alert alert-warning py-2 small mb-3">
                    <i class="fas fa-calendar-alt me-1"></i> <strong>نوٹ:</strong> ان میں سے <strong><?= $diag_u1['other_month'] ?></strong> انٹریز کی تاریخ پچھلے مہینوں کی ہے۔ فنانس کاؤنٹر ڈیفالٹ میں صرف "رواں ماہ" دکھاتا ہے۔ تمام 41 انٹریز دیکھنے کے لیے وہاں "تمام ریکارڈز" پر کلک کریں۔
                </div>
            <?php endif; ?>

            <?php if (($diag_u1['masjid_active'] ?? 0) > 0 || ($diag_u1['in_trash'] ?? 0) > 0): ?>
                <form method="post" onsubmit="return confirm('کیا آپ واقعی تمام انٹریز کو بحال کر کے مدرسہ میں یکجا کرنا چاہتے ہیں؟');">
                    <input type="hidden" name="target_user_id" value="1">
                    <button type="submit" name="recover_user_entries" class="btn btn-info w-100 fw-bold py-2 text-dark shadow-sm">
                        <i class="fas fa-magic me-1"></i> تمام <?= (($diag_u1['masjid_active'] ?? 0) + ($diag_u1['in_trash'] ?? 0)) ?> انٹریز کو فوری بحال اور مدرسہ میں شامل کریں
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Import Form -->
        <div class="card card-custom p-4 mb-4">
            <h5 class="fw-bold mb-3 text-dark border-bottom pb-2">
                <i class="fas fa-cloud-upload-alt me-1 text-primary"></i> نئی فائل امپورٹ کریں
            </h5>
            <form method="post" enctype="multipart/form-data">
                <?php
                try {
                    $usersStmt = $pdo->query("SELECT id, username, name FROM admins ORDER BY id ASC");
                    $allUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    $allUsers = [['id' => $_SESSION['user_id'], 'username' => $_SESSION['username'] ?? 'Admin', 'name' => 'ایڈمن']];
                }
                ?>
                <div class="mb-3 text-start">
                    <label class="form-label fw-bold">کس صارف کے نام سے انٹری شامل ہو؟</label>
                    <select name="selected_user" class="form-select" required>
                        <?php foreach ($allUsers as $u): 
                            $dispName = !empty($u['name']) ? $u['name'] : $u['username'];
                        ?>
                            <option value="<?= $u['id'] ?>|<?= htmlspecialchars($dispName) ?>" <?= ($u['id'] == $_SESSION['user_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dispName) ?> (صارف آئی ڈی: <?= $u['id'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6 text-start">
                        <label class="form-label fw-bold">سسٹم کی قسم (System)</label>
                        <select name="system_type" class="form-select" required>
                            <option value="Madrasa" <?= (($_SESSION['system_type'] ?? 'Madrasa') === 'Madrasa') ? 'selected' : '' ?>>مدرسہ (Madrasa)</option>
                            <option value="Masjid" <?= (($_SESSION['system_type'] ?? '') === 'Masjid') ? 'selected' : '' ?>>مسجد (Masjid)</option>
                        </select>
                    </div>
                    <div class="col-md-6 text-start">
                        <label class="form-label fw-bold">انٹری کی نوعیت (Type)</label>
                        <select name="entry_type" class="form-select" required>
                            <option value="Revenue">آمدن (Revenue / Income)</option>
                            <option value="Expense">خرچ (Expense)</option>
                        </select>
                    </div>
                </div>

                <div class="mb-4 text-start">
                    <label class="form-label fw-bold">CSV فائل منتخب کریں</label>
                    <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                    <div class="form-text text-muted">
                        <i class="fas fa-info-circle me-1"></i> آمدن منتخب کرنے کی صورت میں پچھلے رسید نمبر کے عین تسلسل میں نئے نمبر جاری ہوں گے۔
                    </div>
                </div>

                <button type="submit" name="import" class="btn btn-success w-100 py-2 fs-5 fw-bold shadow-sm">
                    <i class="fas fa-upload me-1"></i> فائل امپورٹ کریں
                </button>
            </form>
        </div>

        <!-- Re-sequence Previous Entries Card -->
        <div class="card card-custom p-4 mb-4 border-start border-4 border-warning">
            <h5 class="fw-bold mb-2 text-dark">
                <i class="fas fa-sync-alt me-1 text-warning"></i> سابقہ ریکارڈز کے رسید نمبرز درست کریں
            </h5>
            <p class="text-muted small mb-3">
                اگر ماضی میں امپورٹ کی گئی فائلوں کے ذریعے رسید نمبرز شروع سے (ری سیٹ) ہو گئے تھے یا ترتیب میں فرق آ گیا تھا، تو آپ یہاں سے ایک کلک کے ذریعے تمام سابقہ فعال آمدن رسیدوں کو تاریخ کے مطابق یکساں و مسلسل ترتیب میں از سر نو منظم کر سکتے ہیں۔
            </p>
            <form method="post" onsubmit="return confirm('کیا آپ واقعی تمام سابقہ آمدن ریکارڈز کے رسید نمبرز کو تاریخ اور صارف کے بلاک کے مطابق ترتیب دینا چاہتے ہیں؟');">
                <button type="submit" name="resequence_receipts" class="btn btn-warning w-100 py-2 text-dark fw-bold shadow-sm">
                    <i class="fas fa-sort-numeric-down me-1"></i> تمام سابقہ آمدن رسید نمبرز درست و منظم کریں
                </button>
            </form>
        </div>

        <!-- Navigation Links -->
        <div class="d-flex gap-2">
            <a href="simple_finance.php" class="btn btn-outline-secondary w-100 py-2 fw-bold">
                <i class="fas fa-cash-register me-1"></i> فنانس کاؤنٹر
            </a>
            <a href="bulk_delete_entries.php" class="btn btn-outline-danger w-100 py-2 fw-bold">
                <i class="fas fa-trash-alt me-1"></i> بلک ڈیلیٹ ٹول
            </a>
            <a href="finance.php" class="btn btn-outline-primary w-100 py-2 fw-bold">
                <i class="fas fa-chart-line me-1"></i> ڈیش بورڈ
            </a>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
