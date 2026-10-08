<?php
/**
 * Recycle Bin & Trash Management System
 * Madrasa Ashraf-ul-Madaris
 * 
 * Provides strict Admin-only visibility into soft-deleted transactions,
 * allowing Restoring, Permanent Deletion, and 90-Day Auto-Purge.
 */

require_once 'db.php';
require_once 'finance_accounting_bridge.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. Strict Admin Authentication Guard
$u_role_check = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin = in_array($u_role_check, ['admin', 'superadmin', 'super_admin', 'administrator']);
if (!isset($_SESSION['user_id']) || !$is_admin) {
    header("Location: dashboard.php?error=unauthorized");
    exit;
}

$cur_tenant_id = (int)get_current_tenant_id();

// Ensure deleted_at and original_receipt_no columns exist
try { $pdo->exec("ALTER TABLE finance ADD COLUMN deleted_at DATETIME DEFAULT NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE finance ADD COLUMN original_receipt_no VARCHAR(100) DEFAULT NULL"); } catch (Throwable $e) {}

// Ensure next_user_receipt_no is available
if (!function_exists('next_user_receipt_no')) {
    function next_user_receipt_no(PDO $pdo, $userId, $sys = 'Madrasa', $tenant_id = null) {
        if ($tenant_id === null) $tenant_id = (int)get_current_tenant_id();
        $userId = max(1, (int)$userId);
        if ($userId === 1) {
            $minNo = 300000;
            $maxNo = 400000;
        } elseif ($userId === 3) {
            $minNo = 700000;
            $maxNo = 800000;
        } else {
            $minNo = $userId * 100000;
            $maxNo = ($userId + 1) * 100000;
        }
        $sysCond = ($sys === 'Masjid') ? "system_type = 'Masjid'" : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
        $stmt = $pdo->prepare("SELECT MAX(computer_receipt_no) FROM finance WHERE computer_receipt_no > ? AND computer_receipt_no < ? AND $sysCond AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0) AND (is_deleted = 0 OR is_deleted IS NULL)");
        $stmt->execute([$minNo, $maxNo, $tenant_id]);
        $currentMax = (int)$stmt->fetchColumn();
        return ($currentMax > $minNo) ? ($currentMax + 1) : ($minNo + 1);
    }
}

// -------------------------------------------------------------
// 2. Process Actions (Restore, Permanent Delete, Purge 90 Days, Bulk)
// -------------------------------------------------------------

// Single Restore
if (isset($_GET['action']) && $_GET['action'] === 'restore' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $st = $pdo->prepare("SELECT * FROM finance WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)");
        $st->execute([$id, $cur_tenant_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $type = $row['type'] ?? 'Expense';
            $sys = $row['system_type'] ?? 'Madrasa';
            $uid = (int)($row['user_id'] ?? 1);
            $origReceipt = $row['original_receipt_no'] ?? $row['receipt_no'];

            $compNo = null;
            $recNo = null;
            if ($type === 'Income') {
                // If original receipt number is purely numeric and not currently taken by an active record, we can reclaim it
                $canReclaim = false;
                if (!empty($origReceipt) && is_numeric($origReceipt)) {
                    $chk = $pdo->prepare("SELECT id FROM finance WHERE computer_receipt_no = ? AND (is_deleted = 0 OR is_deleted IS NULL) AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0) LIMIT 1");
                    $chk->execute([(int)$origReceipt, $cur_tenant_id]);
                    if (!$chk->fetchColumn()) {
                        $compNo = (int)$origReceipt;
                        $recNo = ($sys === 'Masjid') ? "M-$compNo" : "$compNo";
                        $canReclaim = true;
                    }
                }
                if (!$canReclaim) {
                    // Assign fresh next receipt number in sequential order
                    $compNo = next_user_receipt_no($pdo, $uid, $sys, $cur_tenant_id);
                    $recNo = ($sys === 'Masjid') ? "M-$compNo" : "$compNo";
                }
            }

            $upStmt = $pdo->prepare("
                UPDATE finance 
                SET is_deleted = 0, 
                    deleted_at = NULL, 
                    computer_receipt_no = ?, 
                    receipt_no = ? 
                WHERE id = ?
            ");
            $upStmt->execute([$compNo, $recNo, $id]);

            // Re-post accounting voucher
            finance_accounting_post($pdo, $id);

            header("Location: recycle_bin.php?msg=restored&id=$id" . ($compNo ? "&receipt=$compNo" : ""));
            exit;
        }
    } catch (Throwable $e) {
        header("Location: recycle_bin.php?error=" . urlencode($e->getMessage()));
        exit;
    }
}

// Single Permanent Delete
if (isset($_GET['action']) && $_GET['action'] === 'permanent_delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        finance_accounting_delete_voucher($pdo, $id, $cur_tenant_id);
        $pdo->prepare("DELETE FROM finance WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)")->execute([$id, $cur_tenant_id]);
        header("Location: recycle_bin.php?msg=permanent_deleted");
        exit;
    } catch (Throwable $e) {
        header("Location: recycle_bin.php?error=" . urlencode($e->getMessage()));
        exit;
    }
}

// Purge 90+ Days Old
if (isset($_POST['purge_90_days']) || (isset($_GET['action']) && $_GET['action'] === 'purge_90_days')) {
    try {
        $oldStmt = $pdo->prepare("
            SELECT id FROM finance 
            WHERE is_deleted = 1 
              AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)
              AND (
                  (deleted_at IS NOT NULL AND deleted_at < DATE_SUB(NOW(), INTERVAL 90 DAY))
                  OR (deleted_at IS NULL AND (updated_at < DATE_SUB(NOW(), INTERVAL 90 DAY) OR date < DATE_SUB(NOW(), INTERVAL 90 DAY)))
              )
        ");
        $oldStmt->execute([$cur_tenant_id]);
        $oldIds = $oldStmt->fetchAll(PDO::FETCH_COLUMN);

        $purgedCount = 0;
        foreach ($oldIds as $oid) {
            finance_accounting_delete_voucher($pdo, (int)$oid, $cur_tenant_id);
            $pdo->prepare("DELETE FROM finance WHERE id = ?")->execute([(int)$oid]);
            $purgedCount++;
        }

        header("Location: recycle_bin.php?msg=purged&count=$purgedCount");
        exit;
    } catch (Throwable $e) {
        header("Location: recycle_bin.php?error=" . urlencode($e->getMessage()));
        exit;
    }
}

// Bulk Actions (Bulk Restore or Bulk Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && !empty($_POST['selected_ids'])) {
    $action = $_POST['bulk_action'];
    $selected_ids = array_map('intval', (array)$_POST['selected_ids']);
    $count = 0;

    if ($action === 'bulk_restore') {
        foreach ($selected_ids as $sid) {
            try {
                $st = $pdo->prepare("SELECT * FROM finance WHERE id = ?");
                $st->execute([$sid]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                if ($r && (int)$r['is_deleted'] === 1) {
                    $compNo = null;
                    $recNo = null;
                    if ($r['type'] === 'Income') {
                        $compNo = next_user_receipt_no($pdo, (int)($r['user_id'] ?? 1), $r['system_type'] ?? 'Madrasa', $cur_tenant_id);
                        $recNo = ($r['system_type'] === 'Masjid') ? "M-$compNo" : "$compNo";
                    }
                    $pdo->prepare("UPDATE finance SET is_deleted = 0, deleted_at = NULL, computer_receipt_no = ?, receipt_no = ? WHERE id = ?")
                        ->execute([$compNo, $recNo, $sid]);
                    finance_accounting_post($pdo, $sid);
                    $count++;
                }
            } catch (Throwable $e) {}
        }
        header("Location: recycle_bin.php?msg=bulk_restored&count=$count");
        exit;
    } elseif ($action === 'bulk_delete') {
        foreach ($selected_ids as $sid) {
            try {
                finance_accounting_delete_voucher($pdo, $sid, $cur_tenant_id);
                $pdo->prepare("DELETE FROM finance WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)")->execute([$sid, $cur_tenant_id]);
                $count++;
            } catch (Throwable $e) {}
        }
        header("Location: recycle_bin.php?msg=bulk_deleted&count=$count");
        exit;
    }
}

// -------------------------------------------------------------
// 3. Query Statistics & Filtered Trash Records
// -------------------------------------------------------------

$sys_filter = $_GET['sys'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';
$search_q = trim($_GET['search'] ?? '');

$t_cond = "(f.tenant_id = $cur_tenant_id OR f.tenant_id IS NULL OR f.tenant_id = 0)";
$where = ["f.is_deleted = 1", $t_cond];
$params = [];

if ($sys_filter !== 'all') {
    if ($sys_filter === 'Masjid') {
        $where[] = "f.system_type = 'Masjid'";
    } else {
        $where[] = "(f.system_type = 'Madrasa' OR f.system_type IS NULL OR f.system_type = '')";
    }
}

if ($type_filter !== 'all') {
    $where[] = "f.type = ?";
    $params[] = $type_filter;
}

if (!empty($search_q)) {
    $where[] = "(f.description LIKE ? OR f.donor_name LIKE ? OR f.original_receipt_no LIKE ? OR f.receipt_no LIKE ? OR f.amount LIKE ?)";
    $like = "%$search_q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$where_sql = implode(' AND ', $where);

// Summary Stats
$stats = [
    'total_count' => 0,
    'total_income' => 0.0,
    'total_expense' => 0.0,
    'older_90_days' => 0
];

try {
    $statRow = $pdo->query("
        SELECT 
            COUNT(*) as total_count,
            SUM(CASE WHEN type = 'Income' THEN amount ELSE 0 END) as total_income,
            SUM(CASE WHEN type = 'Expense' THEN amount ELSE 0 END) as total_expense,
            SUM(CASE WHEN (deleted_at IS NOT NULL AND deleted_at < DATE_SUB(NOW(), INTERVAL 90 DAY)) OR (deleted_at IS NULL AND (updated_at < DATE_SUB(NOW(), INTERVAL 90 DAY) OR date < DATE_SUB(NOW(), INTERVAL 90 DAY))) THEN 1 ELSE 0 END) as older_90_days
        FROM finance f 
        WHERE f.is_deleted = 1 AND $t_cond
    ")->fetch(PDO::FETCH_ASSOC);

    if ($statRow) {
        $stats['total_count'] = (int)($statRow['total_count'] ?? 0);
        $stats['total_income'] = (float)($statRow['total_income'] ?? 0);
        $stats['total_expense'] = (float)($statRow['total_expense'] ?? 0);
        $stats['older_90_days'] = (int)($statRow['older_90_days'] ?? 0);
    }
} catch (Throwable $e) {}

// Fetch Deleted Records
$stmt = $pdo->prepare("
    SELECT 
        f.*,
        h.name as head_name,
        coa.name as account_name,
        DATEDIFF(NOW(), COALESCE(f.deleted_at, f.updated_at, f.date)) as days_in_trash
    FROM finance f
    LEFT JOIN heads h ON f.head_id = h.id
    LEFT JOIN acc_chart_of_accounts coa ON f.account_id = coa.id
    WHERE $where_sql
    ORDER BY COALESCE(f.deleted_at, f.updated_at, f.date) DESC, f.id DESC
    LIMIT 500
");
$stmt->execute($params);
$deleted_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'header.php';
?>

<div class="container-fluid py-4" dir="rtl">
    <!-- Breadcrumb & Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="dashboard.php" class="text-decoration-none">ڈیش بورڈ</a></li>
                    <li class="breadcrumb-item"><a href="simple_finance.php" class="text-decoration-none">سادہ آمدن و اخراجات</a></li>
                    <li class="breadcrumb-item active" aria-current="page">ری سائیکل بن (ٹریش)</li>
                </ol>
            </nav>
            <h3 class="fw-bold text-danger mb-0">
                <i class="fas fa-trash-alt me-2"></i> ری سائیکل بن / ٹریش لاگ (Recycle Bin)
            </h3>
            <small class="text-muted">ڈیلیٹ شدہ تمام مالیاتی انٹریز کا محفوظ ریکارڈ مع بحالی (Restore)، مستقل ڈیلیٹ، اور 90 روزہ خودکار صفائی</small>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="simple_finance.php" class="btn btn-outline-secondary fw-bold">
                <i class="fas fa-arrow-right me-1"></i> واپس فنانس صفحہ
            </a>
            <?php if ($stats['older_90_days'] > 0): ?>
            <button type="button" class="btn btn-danger fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#purgeConfirmModal">
                <i class="fas fa-broom me-1"></i> 90 دن پرانا ڈیٹا صاف کریں (<?php echo $stats['older_90_days']; ?>)
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Feedback Alerts -->
    <?php if (isset($_GET['msg'])): ?>
        <?php if ($_GET['msg'] === 'restored'): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm border-0" role="alert">
                <i class="fas fa-check-circle me-2 fs-5"></i> <strong>کامیابی:</strong> انٹری کامیابی سے بحال کر دی گئی ہے اور جنرل لیجر میں ڈبل اینٹری شامل ہو گئی ہے۔
                <?php if (!empty($_GET['receipt'])): ?>
                    <br><span class="badge bg-primary fs-6 mt-1">جاری شدہ رسید نمبر: #<?php echo htmlspecialchars($_GET['receipt']); ?></span>
                <?php endif; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php elseif ($_GET['msg'] === 'permanent_deleted'): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0" role="alert">
                <i class="fas fa-trash me-2 fs-5"></i> ریکارڈ اور اس کا واؤچر ڈیٹا بیس سے مستقل حذف کر دیا گیا ہے۔
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php elseif ($_GET['msg'] === 'bulk_restored'): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm border-0" role="alert">
                <i class="fas fa-check-double me-2 fs-5"></i> <strong>کامیابی:</strong> کل <b><?php echo (int)($_GET['count'] ?? 0); ?></b> منتخب شدہ انٹریز کامیابی سے بحال کر دی گئیں۔
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php elseif ($_GET['msg'] === 'bulk_deleted'): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0" role="alert">
                <i class="fas fa-trash-alt me-2 fs-5"></i> کل <b><?php echo (int)($_GET['count'] ?? 0); ?></b> منتخب شدہ انٹریز مستقل حذف کر دی گئیں۔
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php elseif ($_GET['msg'] === 'purged'): ?>
            <div class="alert alert-info alert-dismissible fade show shadow-sm border-0" role="alert">
                <i class="fas fa-broom me-2 fs-5"></i> 90 دن سے پرانی کل <b><?php echo (int)($_GET['count'] ?? 0); ?></b> انٹریز کامیابی سے صاف (Purge) کر دی گئیں۔
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0" role="alert">
            <i class="fas fa-exclamation-triangle me-2 fs-5"></i> <strong>غلطی:</strong> <?php echo htmlspecialchars($_GET['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Summary Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm rounded-3 bg-white p-3 border-start border-danger border-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-bold">کل ڈیلیٹ شدہ انٹریز</div>
                        <div class="fs-4 fw-bold text-danger"><?php echo number_format($stats['total_count']); ?></div>
                    </div>
                    <div class="bg-danger bg-opacity-10 p-3 rounded-circle text-danger fs-4">
                        <i class="fas fa-trash"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm rounded-3 bg-white p-3 border-start border-success border-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-bold">ڈیلیٹ شدہ آمدن</div>
                        <div class="fs-4 fw-bold text-success">Rs. <?php echo number_format($stats['total_income']); ?></div>
                    </div>
                    <div class="bg-success bg-opacity-10 p-3 rounded-circle text-success fs-4">
                        <i class="fas fa-hand-holding-usd"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm rounded-3 bg-white p-3 border-start border-warning border-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-bold">ڈیلیٹ شدہ اخراجات</div>
                        <div class="fs-4 fw-bold text-warning">Rs. <?php echo number_format($stats['total_expense']); ?></div>
                    </div>
                    <div class="bg-warning bg-opacity-10 p-3 rounded-circle text-warning fs-4">
                        <i class="fas fa-receipt"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm rounded-3 bg-white p-3 border-start border-dark border-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-bold">90 دن سے پرانا ڈیٹا</div>
                        <div class="fs-4 fw-bold <?php echo ($stats['older_90_days'] > 0) ? 'text-danger' : 'text-muted'; ?>">
                            <?php echo number_format($stats['older_90_days']); ?>
                        </div>
                    </div>
                    <div class="bg-dark bg-opacity-10 p-3 rounded-circle text-dark fs-4">
                        <i class="fas fa-history"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Bar Card -->
    <div class="card border-0 shadow-sm mb-4 rounded-3">
        <div class="card-body p-3">
            <form method="GET" action="recycle_bin.php" class="row g-2 align-items-center">
                <div class="col-md-3 col-sm-6">
                    <label class="form-label small fw-bold mb-1"><i class="fas fa-layer-group me-1"></i> سسٹم (ادارہ)</label>
                    <select name="sys" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="all" <?php echo $sys_filter === 'all' ? 'selected' : ''; ?>>تمام سسٹمز</option>
                        <option value="Madrasa" <?php echo $sys_filter === 'Madrasa' ? 'selected' : ''; ?>>صرف مدرسہ</option>
                        <option value="Masjid" <?php echo $sys_filter === 'Masjid' ? 'selected' : ''; ?>>صرف مسجد</option>
                    </select>
                </div>
                <div class="col-md-3 col-sm-6">
                    <label class="form-label small fw-bold mb-1"><i class="fas fa-tag me-1"></i> نوعیت (Type)</label>
                    <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>آمدن اور خرچ (تمام)</option>
                        <option value="Income" <?php echo $type_filter === 'Income' ? 'selected' : ''; ?>>صرف آمدن (Income)</option>
                        <option value="Expense" <?php echo $type_filter === 'Expense' ? 'selected' : ''; ?>>صرف اخراجات (Expense)</option>
                    </select>
                </div>
                <div class="col-md-4 col-sm-8">
                    <label class="form-label small fw-bold mb-1"><i class="fas fa-search me-1"></i> تلاش (تفصیل / نام / رسید / رقم)</label>
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="تلاش کریں..." value="<?php echo htmlspecialchars($search_q); ?>">
                </div>
                <div class="col-md-2 col-sm-4 d-flex align-items-end gap-1">
                    <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold">
                        <i class="fas fa-search me-1"></i> فلٹر کریں
                    </button>
                    <a href="recycle_bin.php" class="btn btn-outline-secondary btn-sm" title="ری سیٹ">
                        <i class="fas fa-redo"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Main Table Card -->
    <form method="POST" action="recycle_bin.php" id="bulkForm">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="fas fa-list me-1 text-secondary"></i> ٹریش ریکارڈز کی فہرست
                    </h5>
                    <span class="badge bg-secondary rounded-pill"><?php echo count($deleted_records); ?> ریکارڈز</span>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" name="bulk_action" value="bulk_restore" class="btn btn-sm btn-outline-success fw-bold" onclick="return confirm('کیا آپ واقعی تمام منتخب شدہ ریکارڈز بحال کرنا چاہتے ہیں؟')">
                        <i class="fas fa-undo me-1"></i> منتخب شدہ بحال کریں
                    </button>
                    <button type="submit" name="bulk_action" value="bulk_delete" class="btn btn-sm btn-outline-danger fw-bold" onclick="return confirm('⚠️ انتباہ: مستقل ڈیلیٹ کرنے کے بعد ڈیٹا واپس نہیں لایا جا سکے گا۔ کیا آپ واقعی حذف کرنا چاہتے ہیں؟')">
                        <i class="fas fa-trash-alt me-1"></i> منتخب شدہ مستقل حذف کریں
                    </button>
                </div>
            </div>

            <div class="card-body p-0">
                <?php if (empty($deleted_records)): ?>
                    <div class="text-center py-5">
                        <div class="text-muted mb-3 fs-1">
                            <i class="fas fa-trash-restore"></i>
                        </div>
                        <h5 class="fw-bold text-secondary">ری سائیکل بن بالکل خالی ہے!</h5>
                        <p class="text-muted small">کوئی ڈیلیٹ شدہ مالیاتی ریکارڈ موجود نہیں ہے۔</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 text-center">
                            <thead class="table-light">
                                <tr>
                                    <th width="40">
                                        <input type="checkbox" id="selectAll" class="form-check-input" onclick="toggleSelectAll(this)">
                                    </th>
                                    <th>ID</th>
                                    <th>تاریخ</th>
                                    <th>سسٹم</th>
                                    <th>نوعیت</th>
                                    <th>مد / کھاتہ</th>
                                    <th>رقم</th>
                                    <th>سابقہ رسید #</th>
                                    <th>تفصیل / دہندہ</th>
                                    <th>صارف</th>
                                    <th>حذف ہونے کا وقت</th>
                                    <th>ایکشن</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($deleted_records as $r): ?>
                                <?php 
                                    $is_inc = ($r['type'] === 'Income');
                                    $days = (int)($r['days_in_trash'] ?? 0);
                                    $is_expired = ($days >= 90);
                                    $disp_receipt = !empty($r['original_receipt_no']) ? $r['original_receipt_no'] : (!empty($r['receipt_no']) ? $r['receipt_no'] : '---');
                                ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="selected_ids[]" value="<?php echo $r['id']; ?>" class="form-check-input row-checkbox">
                                    </td>
                                    <td class="text-muted small">#<?php echo $r['id']; ?></td>
                                    <td class="fw-bold small"><?php echo !empty($r['date']) ? date('d-M-Y', strtotime($r['date'])) : '---'; ?></td>
                                    <td>
                                        <?php if (($r['system_type'] ?? 'Madrasa') === 'Masjid'): ?>
                                            <span class="badge bg-info text-dark">مسجد</span>
                                        <?php else: ?>
                                            <span class="badge bg-primary">مدرسہ</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($is_inc): ?>
                                            <span class="badge bg-success bg-opacity-10 text-success border border-success px-2 py-1"><i class="fas fa-arrow-down me-1"></i> آمدن</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger px-2 py-1"><i class="fas fa-arrow-up me-1"></i> خرچ</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-start">
                                        <div class="fw-bold <?php echo $is_inc ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo htmlspecialchars($r['head_name'] ?? 'عام مد'); ?>
                                        </div>
                                        <?php if (!empty($r['account_name'])): ?>
                                            <small class="text-muted"><i class="fas fa-wallet me-1"></i><?php echo htmlspecialchars($r['account_name']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-bold fs-6 <?php echo $is_inc ? 'text-success' : 'text-danger'; ?>">
                                        Rs. <?php echo number_format((float)$r['amount']); ?>
                                    </td>
                                    <td>
                                        <?php if ($disp_receipt !== '---'): ?>
                                            <span class="badge bg-light text-dark border">#<?php echo htmlspecialchars($disp_receipt); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">---</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-start small" style="max-width: 250px;">
                                        <?php if (!empty($r['donor_name'])): ?>
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($r['donor_name']); ?></div>
                                        <?php endif; ?>
                                        <span class="text-muted text-truncate d-inline-block" style="max-width: 240px;">
                                            <?php echo htmlspecialchars($r['description'] ?? ''); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary bg-opacity-25 text-dark">
                                            <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($r['user_name'] ?? 'Admin'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="small fw-bold text-muted">
                                            <?php echo !empty($r['deleted_at']) ? date('d-M-Y h:i A', strtotime($r['deleted_at'])) : date('d-M-Y', strtotime($r['updated_at'] ?? $r['date'])); ?>
                                        </div>
                                        <?php if ($is_expired): ?>
                                            <span class="badge bg-danger mt-1"><?php echo $days; ?> دن قبل (زائد المیعاد)</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border mt-1"><?php echo $days; ?> دن قبل</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="recycle_bin.php?action=restore&id=<?php echo $r['id']; ?>" 
                                               class="btn btn-outline-success" 
                                               onclick="return confirm('کیا واقعی یہ ریکارڈ بحال کرنا چاہتے ہیں؟')" 
                                               title="بحال کریں (Restore)">
                                                <i class="fas fa-undo"></i> بحال
                                            </a>
                                            <a href="recycle_bin.php?action=permanent_delete&id=<?php echo $r['id']; ?>" 
                                               class="btn btn-outline-danger" 
                                               onclick="return confirm('⚠️ انتباہ: مستقل ڈیلیٹ کے بعد یہ ریکارڈ کبھی واپس نہیں لایا جا سکے گا۔ کیا واقعی مستقل ڈیلیٹ کرنا چاہتے ہیں؟')" 
                                               title="مستقل حذف کریں (Permanent Delete)">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="card-footer bg-light p-3 border-0">
                <div class="d-flex align-items-center text-muted small">
                    <i class="fas fa-info-circle me-2 text-primary fs-5"></i>
                    <div>
                        <b>سسٹم پالیسی:</b> ڈیلیٹ شدہ تمام فائلز اور انٹریز 90 دن تک ری سائیکل بن میں محفوظ رہتی ہیں۔ ایڈمن جب چاہے انہیں دوبارہ فعال کر سکتا ہے۔ 90 دن گزرنے کے بعد ایڈمن ایک کلک سے پرانا ڈیٹا ضائع کر سکتا ہے۔
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- Modal: Purge 90+ Days Old Confirmation -->
<div class="modal fade" id="purgeConfirmModal" tabindex="-1" aria-hidden="true" dir="rtl">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-exclamation-triangle me-2"></i> 90 دن پرانا ڈیٹا صاف کریں</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="recycle_bin.php">
                <div class="modal-body text-center p-4">
                    <div class="text-danger mb-3 fs-1">
                        <i class="fas fa-trash-alt"></i>
                    </div>
                    <h5 class="fw-bold mb-3">کیا آپ واقعی 90 دن سے پرانا ڈیٹا مستقل صاف کرنا چاہتے ہیں؟</h5>
                    <p class="text-muted mb-0">
                        کل <b><?php echo $stats['older_90_days']; ?></b> انٹریز مستقل طور پر حذف ہو جائیں گی اور یہ عمل ناقابل واپسی ہوگا۔
                    </p>
                </div>
                <div class="modal-footer justify-content-center border-0 pb-4">
                    <button type="button" class="btn btn-secondary px-4 fw-bold" data-bs-dismiss="modal">منسوخ کریں</button>
                    <button type="submit" name="purge_90_days" value="1" class="btn btn-danger px-4 fw-bold">
                        <i class="fas fa-trash-alt me-1"></i> ہاں، ابھی صاف کریں
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleSelectAll(master) {
    const checkboxes = document.querySelectorAll('.row-checkbox');
    checkboxes.forEach(cb => cb.checked = master.checked);
}
</script>

<?php include 'footer.php'; ?>
