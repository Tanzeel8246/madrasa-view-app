<?php
/**
 * بلک ڈیلیٹ انٹریز ٹول (Bulk Delete Finance Entries Tool)
 * مدرسہ اشرف المدارس
 * 
 * یہ ٹول ایڈمن کو مخصوص صارف کی سلیکٹڈ فنانس انٹریز کو ایک ساتھ 
 * محفوظ طریقے سے ڈیلیٹ کرنے کی سہولت فراہم کرتا ہے۔
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once 'finance_accounting_bridge.php';

// سیکیورٹی چیک: صرف ایڈمن اور ناظم کو رسائی حاصل ہے
$user_role_lower = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin = in_array($user_role_lower, ['admin', 'superadmin', 'super_admin', 'administrator', 'nazim']);
if (!isset($_SESSION['user_id']) || !$is_admin) {
    die("رسائی مسترد کر دی گئی ہے۔ صرف ایڈمنسٹریٹر اس ٹول کو استعمال کر سکتا ہے۔");
}

$cur_tenant_id = function_exists('get_current_tenant_id') ? (int)get_current_tenant_id() : 1;
$cur_tenant_id = max(1, $cur_tenant_id);
$t_clause = ($cur_tenant_id == 1) ? "(f.tenant_id = 1 OR f.tenant_id IS NULL OR f.tenant_id = 0)" : "f.tenant_id = $cur_tenant_id";

$msg = '';
$msg_type = 'success';

// تمام سسٹم یوزرز حاصل کریں
$sys_users = [];
try {
    $admin_t = ($cur_tenant_id == 1) ? "(tenant_id = 1 OR tenant_id IS NULL OR tenant_id = 0)" : "tenant_id = $cur_tenant_id";
    $sys_users = $pdo->query("SELECT id, username, name FROM admins WHERE $admin_t ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    try {
        $sys_users = $pdo->query("SELECT id, username, name FROM admins ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) {}
}

// -------------------------------------------------------------
// 1. بلک ڈیلیشن پروسیسنگ (Handle Bulk Delete POST)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_delete') {
    $entry_ids = $_POST['entry_ids'] ?? [];
    $del_mode = $_POST['delete_mode'] ?? 'soft'; // 'soft' (ری سائیکل بن) یا 'permanent'

    if (!empty($entry_ids) && is_array($entry_ids)) {
        $clean_ids = array_filter(array_map('intval', $entry_ids), function($id) { return $id > 0; });
        if (!empty($clean_ids)) {
            $in_clause = implode(',', $clean_ids);
            
            // ڈیلیٹ کرنے سے پہلے ریکارڈز کا ڈیٹا اور رقم حاصل کریں
            $stmtSum = $pdo->query("SELECT COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total_amt FROM finance WHERE id IN ($in_clause)");
            $sumRow = $stmtSum->fetch(PDO::FETCH_ASSOC);
            $delCount = (int)($sumRow['cnt'] ?? 0);
            $delAmount = (float)($sumRow['total_amt'] ?? 0);

            if ($del_mode === 'permanent') {
                // مستقل ڈیلیٹ: واؤچرز اور لیجر ختم کریں
                foreach ($clean_ids as $eid) {
                    try {
                        $ref = 'FINANCE-' . (int)$eid;
                        $pdo->prepare("DELETE FROM acc_ledger_entries WHERE voucher_id IN (SELECT id FROM acc_vouchers WHERE reference = ?)")->execute([$ref]);
                        $pdo->prepare("DELETE FROM acc_vouchers WHERE reference = ?")->execute([$ref]);
                    } catch (Throwable $eV) {}
                }
                // فنانس ٹیبل سے مستقل ڈیلیٹ
                $pdo->exec("DELETE FROM finance WHERE id IN ($in_clause)");
                try {
                    $pdo->exec("DELETE FROM masjid_finance WHERE id IN ($in_clause)");
                } catch (Throwable $eM) {}
                $msg = "منتخب کردہ <strong>$delCount</strong> انٹریز (کل رقم: Rs. " . number_format($delAmount) . ") کو مستقل طور پر سسٹم سے ڈیلیٹ کر دیا گیا ہے۔";
                $msg_type = 'danger';
            } else {
                // محفوظ ڈیلیٹ (ری سائیکل بن میں منتقل کریں)
                // رسید نمبر آزاد کریں تاکہ نیا تسلسل متاثر نہ ہو
                $pdo->exec("
                    UPDATE finance 
                    SET is_deleted = 1, 
                        deleted_at = NOW(), 
                        original_receipt_no = COALESCE(original_receipt_no, CAST(computer_receipt_no AS CHAR)),
                        computer_receipt_no = NULL 
                    WHERE id IN ($in_clause)
                ");
                try {
                    $pdo->exec("
                        UPDATE masjid_finance 
                        SET is_deleted = 1, 
                            deleted_at = NOW(), 
                            original_receipt_no = COALESCE(original_receipt_no, CAST(computer_receipt_no AS CHAR)),
                            computer_receipt_no = NULL 
                        WHERE id IN ($in_clause)
                    ");
                } catch (Throwable $eM) {}

                // اکاؤنٹنگ واؤچرز سنک کریں
                foreach ($clean_ids as $eid) {
                    try {
                        if (function_exists('finance_accounting_post')) {
                            finance_accounting_post($pdo, (int)$eid);
                        }
                    } catch (Throwable $ePost) {}
                }
                $msg = "منتخب کردہ <strong>$delCount</strong> انٹریز (کل رقم: Rs. " . number_format($delAmount) . ") کو کامیابی کے ساتھ ڈیلیٹ کر کے ری سائیکل بن میں منتقل کر دیا گیا ہے۔ اگر ضرورت ہو تو آپ انہیں ری سائیکل بن سے بحال کر سکتے ہیں۔";
                $msg_type = 'success';
            }
        } else {
            $msg = "کوئی درست انٹری منتخب نہیں کی گئی تھی۔";
            $msg_type = 'warning';
        }
    } else {
        $msg = "براہ کرم ڈیلیٹ کرنے کے لیے کم از کم ایک انٹری کا انتخاب کریں!";
        $msg_type = 'warning';
    }
}

// -------------------------------------------------------------
// 2. فلٹرز کی تفصیلات (Filter parameters GET)
// -------------------------------------------------------------
$filter_user_id   = $_GET['user_id'] ?? 'all';
$filter_system    = $_GET['system_type'] ?? ($_SESSION['system_type'] ?? 'all');
$filter_type      = $_GET['entry_type'] ?? 'all';
$filter_from_date = $_GET['from_date'] ?? '';
$filter_to_date   = $_GET['to_date'] ?? '';
$search_term      = trim($_GET['search'] ?? '');
$limit            = isset($_GET['limit']) ? max(50, min(1000, (int)$_GET['limit'])) : 200;

// کیوری کنڈیشنز بنانا
$where = ["(f.is_deleted = 0 OR f.is_deleted IS NULL)", $t_clause];
$params = [];

if ($filter_user_id !== 'all' && is_numeric($filter_user_id)) {
    $where[] = "f.user_id = ?";
    $params[] = (int)$filter_user_id;
}

if ($filter_system !== 'all') {
    if ($filter_system === 'Masjid') {
        $where[] = "f.system_type = 'Masjid'";
    } else {
        $where[] = "(f.system_type = 'Madrasa' OR f.system_type IS NULL OR f.system_type = '')";
    }
}

if ($filter_type !== 'all' && in_array($filter_type, ['Income', 'Expense'])) {
    $where[] = "f.type = ?";
    $params[] = $filter_type;
}

if (!empty($filter_from_date)) {
    $where[] = "f.date >= ?";
    $params[] = $filter_from_date;
}

if (!empty($filter_to_date)) {
    $where[] = "f.date <= ?";
    $params[] = $filter_to_date;
}

if (!empty($search_term)) {
    $where[] = "(f.description LIKE ? OR f.donor_name LIKE ? OR CAST(f.computer_receipt_no AS CHAR) LIKE ?)";
    $st = "%$search_term%";
    $params[] = $st;
    $params[] = $st;
    $params[] = $st;
}

$whereSql = implode(' AND ', $where);

// انٹریز لائیں
$entries = [];
try {
    $sql = "
        SELECT f.*, COALESCE(h.name, f.head_name, '---') as head_display
        FROM finance f
        LEFT JOIN heads h ON f.head_id = h.id
        WHERE $whereSql
        ORDER BY f.date DESC, f.id DESC
        LIMIT $limit
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    try {
        $sql = "SELECT f.*, COALESCE(f.head_name, '---') as head_display FROM finance f WHERE $whereSql ORDER BY f.date DESC, f.id DESC LIMIT $limit";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) {
        $entries = [];
    }
}

// کل تعداد اور مجموعی رقم کا خلاصہ
$totalFilteredCount = count($entries);
$totalFilteredAmount = 0.0;
foreach ($entries as $en) {
    $totalFilteredAmount += (float)($en['amount'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بلک ڈیلیٹ انٹریز ٹول - فنانس مینجمنٹ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Jameel Noori Nastaleeq', 'Segoe UI', Tahoma, Arial, sans-serif; background-color: #f8fafc; }
        .card-custom { border-radius: 12px; border: none; box-shadow: 0 4px 15px rgba(0,0,0,0.06); background: #ffffff; }
        .table-responsive { max-height: 65vh; overflow-y: auto; }
        .table thead th { position: sticky; top: 0; background: #f1f5f9; z-index: 10; border-bottom: 2px solid #cbd5e1; }
        .sticky-action-bar {
            position: sticky;
            bottom: 15px;
            z-index: 100;
            background: rgba(30, 41, 59, 0.95);
            backdrop-filter: blur(8px);
            border-radius: 14px;
            color: #ffffff;
            box-shadow: 0 8px 25px rgba(0,0,0,0.25);
        }
        .badge-type-income { background-color: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .badge-type-expense { background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
    </style>
</head>
<body>
    <div class="container-fluid px-4 py-4">
        <!-- Header -->
        <div class="card card-custom p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h3 class="fw-bold text-danger mb-1">
                        <i class="fas fa-trash-alt me-2"></i> بلک ڈیلیٹ انٹریز ٹول (Bulk Delete Finance Entries)
                    </h3>
                    <p class="text-muted mb-0">
                        مخصوص صارف، تاریخ یا مد کے تحت انٹریز تلاش کر کے ایک ہی کلک میں سلیکٹڈ انٹریز کو باحفاظت ڈیلیٹ کریں۔
                    </p>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="simple_finance.php" class="btn btn-outline-secondary fw-bold">
                        <i class="fas fa-cash-register me-1"></i> فنانس کاؤنٹر
                    </a>
                    <a href="recycle_bin.php" class="btn btn-outline-info fw-bold">
                        <i class="fas fa-recycle me-1"></i> ری سائیکل بن
                    </a>
                    <a href="import_csv.php" class="btn btn-outline-success fw-bold">
                        <i class="fas fa-file-import me-1"></i> امپورٹ CSV ٹول
                    </a>
                </div>
            </div>
        </div>

        <?php if (!empty($msg)): ?>
            <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show shadow-sm" role="alert">
                <i class="fas <?= ($msg_type === 'success' ? 'fa-check-circle' : ($msg_type === 'danger' ? 'fa-trash' : 'fa-exclamation-triangle')) ?> me-2"></i>
                <?= $msg ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Filter Card -->
        <div class="card card-custom p-3 mb-4">
            <form method="GET" action="bulk_delete_entries.php" class="row g-2 align-items-end">
                <!-- User Filter -->
                <div class="col-md-3 col-sm-6">
                    <label class="form-label small fw-bold text-dark mb-1">
                        <i class="fas fa-user me-1 text-primary"></i> مخصوص صارف منتخب کریں:
                    </label>
                    <select name="user_id" class="form-select form-select-sm fw-bold">
                        <option value="all" <?= ($filter_user_id === 'all') ? 'selected' : '' ?>>تمام صارفین (All Users)</option>
                        <?php foreach ($sys_users as $u): 
                            $dispName = !empty($u['name']) ? $u['name'] : $u['username'];
                        ?>
                            <option value="<?= $u['id'] ?>" <?= ($filter_user_id == $u['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dispName) ?> (ID: <?= $u['id'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- System Type -->
                <div class="col-md-2 col-sm-6">
                    <label class="form-label small fw-bold text-dark mb-1">سسٹم (System):</label>
                    <select name="system_type" class="form-select form-select-sm fw-bold">
                        <option value="all" <?= ($filter_system === 'all') ? 'selected' : '' ?>>مدرسہ + مسجد (دونوں)</option>
                        <option value="Madrasa" <?= ($filter_system === 'Madrasa') ? 'selected' : '' ?>>صرف مدرسہ (Madrasa)</option>
                        <option value="Masjid" <?= ($filter_system === 'Masjid') ? 'selected' : '' ?>>صرف مسجد (Masjid)</option>
                    </select>
                </div>

                <!-- Entry Type -->
                <div class="col-md-2 col-sm-6">
                    <label class="form-label small fw-bold text-dark mb-1">انٹری کی قسم:</label>
                    <select name="entry_type" class="form-select form-select-sm fw-bold">
                        <option value="all" <?= ($filter_type === 'all') ? 'selected' : '' ?>>تمام (آمدن + خرچ)</option>
                        <option value="Income" <?= ($filter_type === 'Income') ? 'selected' : '' ?>>صرف آمدن (Income)</option>
                        <option value="Expense" <?= ($filter_type === 'Expense') ? 'selected' : '' ?>>صرف خرچ (Expense)</option>
                    </select>
                </div>

                <!-- Date Range -->
                <div class="col-md-3 col-sm-6">
                    <label class="form-label small fw-bold text-dark mb-1">تاریخ کا دورانیہ:</label>
                    <div class="input-group input-group-sm">
                        <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($filter_from_date) ?>" placeholder="شروع">
                        <span class="input-group-text">تا</span>
                        <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($filter_to_date) ?>" placeholder="اختتام">
                    </div>
                </div>

                <!-- Search & Action -->
                <div class="col-md-2 col-sm-12 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary w-100 fw-bold">
                        <i class="fas fa-filter me-1"></i> فلٹر لگائیں
                    </button>
                    <a href="bulk_delete_entries.php" class="btn btn-sm btn-outline-secondary" title="ری سیٹ">
                        <i class="fas fa-undo"></i>
                    </a>
                </div>

                <div class="col-12 mt-2">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="تفصیل، شخص کا نام یا رسید نمبر سے تلاش کریں..." value="<?= htmlspecialchars($search_term) ?>">
                    </div>
                </div>
            </form>
        </div>

        <!-- Summary Banner -->
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div>
                <span class="text-dark fw-bold">کل دستیاب ریکارڈز: </span>
                <span class="badge bg-secondary fs-6"><?= number_format($totalFilteredCount) ?></span>
                <span class="text-dark fw-bold ms-3">مجموعی رقم: </span>
                <span class="badge bg-dark fs-6" style="direction:ltr;">Rs. <?= number_format($totalFilteredAmount) ?></span>
            </div>
            <div class="small text-muted">
                <i class="fas fa-info-circle me-1"></i> جن انٹریز کو ڈیلیٹ کرنا ہو ان کے چیک باکس کو منتخب کریں اور نیچے دیے گئے ڈیلیٹ بٹن پر کلک کریں۔
            </div>
        </div>

        <!-- Main Form with Table -->
        <form method="POST" id="bulkDeleteForm" onsubmit="return confirmBulkDelete();">
            <input type="hidden" name="action" value="bulk_delete">

            <div class="card card-custom p-0 mb-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 text-center">
                        <thead>
                            <tr>
                                <th style="width: 50px;">
                                    <input type="checkbox" id="selectAllCheckbox" class="form-check-input" title="سب منتخب کریں / منسوخ کریں">
                                </th>
                                <th>تاریخ</th>
                                <th>صارف (ریکارڈ اونر)</th>
                                <th>سسٹم</th>
                                <th>قسم</th>
                                <th>مد (Head)</th>
                                <th>رقم</th>
                                <th>رسید نمبر</th>
                                <th>شخص / تفصیل</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($entries)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5 text-muted">
                                        <i class="fas fa-inbox fa-3x mb-3 text-secondary d-block"></i>
                                        کوئی ریکارڈ نہیں ملا۔ فلٹرز تبدیل کر کے دوبارہ کوشش کریں۔
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($entries as $r): 
                                    $isIncome = ($r['type'] === 'Income');
                                    $recNo = !empty($r['computer_receipt_no']) ? $r['computer_receipt_no'] : (!empty($r['receipt_no']) ? $r['receipt_no'] : '---');
                                    $amt = (float)($r['amount'] ?? 0);
                                ?>
                                    <tr id="row_<?= $r['id'] ?>">
                                        <td>
                                            <input type="checkbox" name="entry_ids[]" value="<?= $r['id'] ?>" class="form-check-input entry-checkbox" data-amount="<?= $amt ?>" onchange="updateSelectedSummary()">
                                        </td>
                                        <td>
                                            <small class="fw-bold"><?= !empty($r['date']) ? date('d-M-Y', strtotime($r['date'])) : '---' ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border">
                                                <i class="fas fa-user-circle me-1 text-secondary"></i>
                                                <?= htmlspecialchars($r['user_name'] ?? ('صارف #' . ($r['user_id'] ?? ''))) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?= ($r['system_type'] === 'Masjid') ? 'bg-info text-dark' : 'bg-primary text-white' ?>">
                                                <?= ($r['system_type'] === 'Masjid') ? 'مسجد' : 'مدرسہ' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?= $isIncome ? 'badge-type-income' : 'badge-type-expense' ?> fw-bold px-2 py-1">
                                                <?= $isIncome ? '<i class="fas fa-arrow-down me-1"></i>آمدن' : '<i class="fas fa-arrow-up me-1"></i>خرچ' ?>
                                            </span>
                                        </td>
                                        <td class="fw-bold text-dark">
                                            <?= htmlspecialchars($r['head_display']) ?>
                                        </td>
                                        <td class="fw-bold <?= $isIncome ? 'text-success' : 'text-danger' ?>" style="direction:ltr;">
                                            Rs. <?= number_format($amt) ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary">#<?= htmlspecialchars($recNo) ?></span>
                                        </td>
                                        <td class="text-start small" style="max-width: 250px;">
                                            <?php if (!empty($r['donor_name'])): ?>
                                                <span class="text-primary fw-bold"><?= htmlspecialchars($r['donor_name']) ?></span><br>
                                            <?php endif; ?>
                                            <span class="text-muted"><?= htmlspecialchars($r['description'] ?? '') ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Sticky Bottom Action Bar -->
            <div class="sticky-action-bar p-3 d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div>
                        <span class="small text-light">منتخب شدہ انٹریز:</span>
                        <h4 class="mb-0 fw-bold text-warning d-inline-block ms-2" id="selectedCountDisplay">0</h4>
                    </div>
                    <div class="border-start ps-3 border-secondary">
                        <span class="small text-light">منتخب کردہ مجموعی رقم:</span>
                        <h4 class="mb-0 fw-bold text-success d-inline-block ms-2" id="selectedAmountDisplay" style="direction:ltr;">Rs. 0</h4>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <div class="form-check form-check-inline text-light m-0">
                        <input class="form-check-input" type="radio" name="delete_mode" id="modeSoft" value="soft" checked>
                        <label class="form-check-label small" for="modeSoft" title="ریکوری ممکن ہے">
                            محفوظ ڈیلیٹ (ری سائیکل بن)
                        </label>
                    </div>
                    <div class="form-check form-check-inline text-light m-0">
                        <input class="form-check-input" type="radio" name="delete_mode" id="modePermanent" value="permanent">
                        <label class="form-check-label small text-danger fw-bold" for="modePermanent" title="مکمل خاتمہ">
                            مستقل ڈیلیٹ (Permanent)
                        </label>
                    </div>

                    <button type="submit" id="btnDeleteSelected" class="btn btn-danger fw-bold px-4 py-2 shadow" disabled>
                        <i class="fas fa-trash-alt me-1"></i> منتخب انٹریز ڈیلیٹ کریں
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const selectAllCheckbox = document.getElementById('selectAllCheckbox');
        const entryCheckboxes = document.querySelectorAll('.entry-checkbox');
        const countDisplay = document.getElementById('selectedCountDisplay');
        const amountDisplay = document.getElementById('selectedAmountDisplay');
        const btnDeleteSelected = document.getElementById('btnDeleteSelected');

        // Select All Handler
        selectAllCheckbox.addEventListener('change', function() {
            entryCheckboxes.forEach(cb => {
                cb.checked = selectAllCheckbox.checked;
            });
            updateSelectedSummary();
        });

        // Recalculate Count & Amount
        function updateSelectedSummary() {
            let count = 0;
            let totalAmount = 0.0;

            entryCheckboxes.forEach(cb => {
                if (cb.checked) {
                    count++;
                    totalAmount += parseFloat(cb.dataset.amount || 0);
                    cb.closest('tr').classList.add('table-warning');
                } else {
                    cb.closest('tr').classList.remove('table-warning');
                }
            });

            countDisplay.innerText = count;
            amountDisplay.innerText = 'Rs. ' + totalAmount.toLocaleString();

            if (count > 0) {
                btnDeleteSelected.removeAttribute('disabled');
            } else {
                btnDeleteSelected.setAttribute('disabled', 'true');
            }

            // Sync select all state
            if (entryCheckboxes.length > 0 && count === entryCheckboxes.length) {
                selectAllCheckbox.checked = true;
                selectAllCheckbox.indeterminate = false;
            } else if (count > 0) {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = true;
            } else {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = false;
            }
        }

        // Confirmation Dialog
        function confirmBulkDelete() {
            let count = parseInt(countDisplay.innerText) || 0;
            if (count <= 0) {
                alert('براہ کرم ڈیلیٹ کرنے کے لیے پہلے کم از کم ایک انٹری کا انتخاب کریں!');
                return false;
            }

            let isPermanent = document.getElementById('modePermanent').checked;
            let modeText = isPermanent 
                ? '⚠️ تنبیہ: آپ نے مستقل ڈیلیٹ (Permanent) کا انتخاب کیا ہے! یہ انٹریز ہمیشہ کے لیے ختم ہو جائیں گی۔' 
                : 'یہ انٹریز ری سائیکل بن میں منتقل ہو جائیں گی۔';

            return confirm('کیا آپ واقعی منتخب کردہ ' + count + ' انٹریز کو ڈیلیٹ کرنا چاہتے ہیں؟\n\n' + modeText);
        }
    </script>
</body>
</html>
