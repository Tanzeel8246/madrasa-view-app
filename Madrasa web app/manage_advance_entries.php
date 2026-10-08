<?php
require_once 'db.php';
require_once 'finance_accounting_bridge.php';

$user_role_lower = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin = in_array($user_role_lower, ['admin', 'superadmin', 'super_admin', 'administrator']);
if (!$is_admin) {
    die('رسائی ممنوع ہے: صرف ایڈمنسٹریٹر اس صفحے کو استعمال کر سکتا ہے۔');
}

$cur_tenant_id = (int)get_current_tenant_id();
$msg = '';
$msg_type = 'success';

// Ensure 1001 Cash in Hand account exists
$def_cash_id = function_exists('finance_accounting_ensure_account')
    ? finance_accounting_ensure_account($pdo, '1001', 'Cash in Hand', 'Asset', $cur_tenant_id)
    : null;

// Fetch all system users (admins / staff)
$sys_users = [];
try {
    $sys_users = $pdo->query("SELECT id, username, name FROM admins WHERE (tenant_id = $cur_tenant_id OR tenant_id IS NULL OR tenant_id = 0) ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    try {
        $sys_users = $pdo->query("SELECT id, username, name FROM admins ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) {}
}

// Fetch List of Target Accounts for Dropdowns
$available_accounts = [];
try {
    $stA = $pdo->prepare("SELECT id, code, name, type, system_type FROM acc_chart_of_accounts WHERE tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL) AND (type = 'Asset' OR type = 'Expense') ORDER BY (code = '1001') DESC, code ASC");
    $stA->execute([$cur_tenant_id]);
    $available_accounts = $stA->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// ----------------------------------------------------
// Filter parameters (GET)
// ----------------------------------------------------
$filter_user_id = $_GET['user_id'] ?? '3'; // Default to Saleem ID 3 or 'all'
$filter_system  = $_GET['system_type'] ?? 'all'; // 'all', 'Madrasa', 'Masjid'
$filter_type    = $_GET['entry_type'] ?? 'Expense'; // 'all', 'Expense', 'Income'
$filter_account = $_GET['account_filter'] ?? 'advance_only'; // 'all', 'advance_only', 'collection_only', 'cash_1001', or numeric account_id
$search_term    = trim($_GET['search'] ?? '');

// ----------------------------------------------------
// 1. Handle Bulk Reassignment
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_reassign') {
    $entry_ids = $_POST['entry_ids'] ?? [];
    $target_user_id = trim($_POST['target_user_id'] ?? 'no_change');
    $target_account_id = trim($_POST['target_account_id'] ?? 'no_change');
    $target_system = trim($_POST['target_system_type'] ?? 'no_change');

    if (!empty($entry_ids) && is_array($entry_ids)) {
        // Resolve target user name
        $new_user_name = null;
        $new_uid = null;
        if ($target_user_id !== 'no_change') {
            $new_uid = (int)$target_user_id;
            foreach ($sys_users as $su) {
                if ((int)$su['id'] === $new_uid) {
                    $new_user_name = !empty($su['name']) ? $su['name'] : $su['username'];
                    break;
                }
            }
            if (empty($new_user_name)) $new_user_name = "صارف #{$new_uid}";
        }

        // Resolve target account & payment method
        $new_aid = null;
        $new_payment_method = 'Cash';
        if ($target_account_id !== 'no_change') {
            $new_aid = (int)$target_account_id;
            try {
                $stAn = $pdo->prepare("SELECT name FROM acc_chart_of_accounts WHERE id = ?");
                $stAn->execute([$new_aid]);
                $anVal = (string)$stAn->fetchColumn();
                if (mb_stripos($anVal, 'ایڈوانس') !== false || mb_stripos($anVal, 'advance') !== false) {
                    $new_payment_method = 'Advance';
                }
            } catch (Throwable $e) {}
        }

        $updated_count = 0;
        $total_amount_shifted = 0.0;

        foreach ($entry_ids as $eid) {
            $eid = (int)$eid;
            if ($eid <= 0) continue;

            $stmtC = $pdo->prepare("SELECT amount FROM finance WHERE id = ? AND tenant_id = ?");
            $stmtC->execute([$eid, $cur_tenant_id]);
            $amt = (float)$stmtC->fetchColumn();

            // Construct dynamic update fields
            $update_fields = [];
            $update_params = [];

            if ($new_uid !== null) {
                $update_fields[] = "user_id = ?";
                $update_params[] = $new_uid;
                $update_fields[] = "user_name = ?";
                $update_params[] = $new_user_name;
            }

            if ($new_aid !== null) {
                $update_fields[] = "account_id = ?";
                $update_params[] = $new_aid;
                $update_fields[] = "payment_method = ?";
                $update_params[] = $new_payment_method;
            }

            if ($target_system !== 'no_change' && in_array($target_system, ['Madrasa', 'Masjid'])) {
                $update_fields[] = "system_type = ?";
                $update_params[] = $target_system;
            }

            if (!empty($update_fields)) {
                $sqlUpd = "UPDATE finance SET " . implode(', ', $update_fields) . " WHERE id = ? AND tenant_id = ?";
                $update_params[] = $eid;
                $update_params[] = $cur_tenant_id;
                $upd = $pdo->prepare($sqlUpd);
                $upd->execute($update_params);

                try {
                    finance_accounting_post($pdo, $eid);
                } catch (Throwable $eBridge) {}

                $updated_count++;
                $total_amount_shifted += $amt;
            }
        }

        $msg = "کامیابی! کل {$updated_count} انٹریز (کل رقم: Rs. " . number_format($total_amount_shifted) . ") کو نئی تفصیلات کے مطابق کامیابی سے منتقل کر دیا گیا۔";
        $msg_type = 'success';
    } else {
        $msg = "براہ کرم فہرست میں سے کم از کم ایک انٹری منتخب کریں۔";
        $msg_type = 'warning';
    }
}

// ----------------------------------------------------
// 2. Handle Inline Single Reassignment
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'single_reassign') {
    $entry_id = (int)($_POST['entry_id'] ?? 0);
    $single_user_id = trim($_POST['single_user_id'] ?? 'no_change');
    $single_account_id = trim($_POST['single_account_id'] ?? 'no_change');
    $single_system = trim($_POST['single_system_type'] ?? 'no_change');

    if ($entry_id > 0) {
        $update_fields = [];
        $update_params = [];

        if ($single_user_id !== 'no_change') {
            $suid = (int)$single_user_id;
            $suname = null;
            foreach ($sys_users as $su) {
                if ((int)$su['id'] === $suid) {
                    $suname = !empty($su['name']) ? $su['name'] : $su['username'];
                    break;
                }
            }
            if (empty($suname)) $suname = "صارف #{$suid}";
            $update_fields[] = "user_id = ?";
            $update_params[] = $suid;
            $update_fields[] = "user_name = ?";
            $update_params[] = $suname;
        }

        if ($single_account_id !== 'no_change') {
            $said = (int)$single_account_id;
            $spay_method = 'Cash';
            try {
                $stAn = $pdo->prepare("SELECT name FROM acc_chart_of_accounts WHERE id = ?");
                $stAn->execute([$said]);
                $anVal = (string)$stAn->fetchColumn();
                if (mb_stripos($anVal, 'ایڈوانس') !== false || mb_stripos($anVal, 'advance') !== false) {
                    $spay_method = 'Advance';
                }
            } catch (Throwable $e) {}
            $update_fields[] = "account_id = ?";
            $update_params[] = $said;
            $update_fields[] = "payment_method = ?";
            $update_params[] = $spay_method;
        }

        if ($single_system !== 'no_change' && in_array($single_system, ['Madrasa', 'Masjid'])) {
            $update_fields[] = "system_type = ?";
            $update_params[] = $single_system;
        }

        if (!empty($update_fields)) {
            $sqlUpd = "UPDATE finance SET " . implode(', ', $update_fields) . " WHERE id = ? AND tenant_id = ?";
            $update_params[] = $entry_id;
            $update_params[] = $cur_tenant_id;
            $upd = $pdo->prepare($sqlUpd);
            $upd->execute($update_params);

            try {
                finance_accounting_post($pdo, $entry_id);
            } catch (Throwable $eBridge) {}

            $msg = "انٹری #{$entry_id} کی تفصیلات کامیابی سے درست کر دی گئیں۔";
            $msg_type = 'success';
        }
    }
}

// ----------------------------------------------------
// 3. Build Dynamic Query for Filtering Entries
// ----------------------------------------------------
$where_clauses = ["f.tenant_id = ?", "(f.is_deleted = 0 OR f.is_deleted IS NULL)"];
$query_params = [$cur_tenant_id];

// User Filter
if ($filter_user_id !== 'all') {
    $uid_int = (int)$filter_user_id;
    if ($uid_int === 3) {
        $where_clauses[] = "(f.user_id = 3 OR f.user_name LIKE '%سلیم%' OR f.user_name = 'saleem')";
    } elseif ($uid_int === 1) {
        $where_clauses[] = "(f.user_id = 1 OR f.user_name LIKE '%تنزیل%' OR f.user_name = 'Tanzeel')";
    } else {
        $where_clauses[] = "f.user_id = $uid_int";
    }
}

// System Type Filter
if ($filter_system === 'Madrasa') {
    $where_clauses[] = "(f.system_type = 'Madrasa' OR f.system_type IS NULL OR f.system_type = '')";
} elseif ($filter_system === 'Masjid') {
    $where_clauses[] = "f.system_type = 'Masjid'";
}

// Entry Type Filter
if ($filter_type === 'Expense') {
    $where_clauses[] = "f.type = 'Expense'";
} elseif ($filter_type === 'Income') {
    $where_clauses[] = "f.type = 'Income'";
}

// Account Filter
if ($filter_account === 'advance_only') {
    $where_clauses[] = "(f.payment_method = 'Advance' OR f.payment_method LIKE '%ایڈوانس%' OR a.name LIKE '%ایڈوانس%' OR a.name LIKE '%advance%')";
} elseif ($filter_account === 'collection_only') {
    $where_clauses[] = "(a.name LIKE '%وصولی%' OR a.name LIKE '%Collection%')";
} elseif ($filter_account === 'cash_1001') {
    $where_clauses[] = "(a.code = '1001' OR a.name LIKE '%مرکزی نقد%' OR a.name LIKE '%Cash in Hand%')";
} elseif (is_numeric($filter_account) && (int)$filter_account > 0) {
    $where_clauses[] = "f.account_id = " . (int)$filter_account;
}

// Search keyword
if (!empty($search_term)) {
    $searchTermEsc = '%' . $search_term . '%';
    $where_clauses[] = "(f.description LIKE ? OR f.donor_name LIKE ? OR f.spender_name LIKE ? OR f.manual_receipt_no LIKE ? OR CAST(f.computer_receipt_no AS CHAR) LIKE ? OR CAST(f.amount AS CHAR) LIKE ?)";
    $query_params[] = $searchTermEsc;
    $query_params[] = $searchTermEsc;
    $query_params[] = $searchTermEsc;
    $query_params[] = $searchTermEsc;
    $query_params[] = $searchTermEsc;
    $query_params[] = $searchTermEsc;
}

$where_sql = implode(' AND ', $where_clauses);

$sql = "
    SELECT f.*, h.name AS head_name, a.name AS account_name, a.code AS account_code,
           COALESCE(u.name, u.username, f.user_name, CONCAT('صارف #', f.user_id)) AS resolved_user_name
    FROM finance f
    LEFT JOIN heads h ON f.head_id = h.id
    LEFT JOIN acc_chart_of_accounts a ON f.account_id = a.id
    LEFT JOIN admins u ON f.user_id = u.id
    WHERE $where_sql
    ORDER BY f.date DESC, f.id DESC
";
$stmtEntries = $pdo->prepare($sql);
$stmtEntries->execute($query_params);
$entries = $stmtEntries->fetchAll(PDO::FETCH_ASSOC);

$total_listed_count = count($entries);
$total_listed_amount = 0.0;
$expense_sum = 0.0;
$income_sum = 0.0;

foreach ($entries as $e) {
    $amt = (float)$e['amount'];
    $total_listed_amount += $amt;
    if ($e['type'] === 'Expense') {
        $expense_sum += $amt;
    } else {
        $income_sum += $amt;
    }
}

include 'header.php';
?>

<div class="container-fluid py-4" style="direction: rtl; text-align: right;">
    
    <!-- Breadcrumb & Navigation -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="simple_finance.php" class="text-decoration-none">سادہ فنانس</a></li>
                    <li class="breadcrumb-item active" aria-current="page">انٹریز تفریق و منتقلی ٹول</li>
                </ol>
            </nav>
            <h3 class="fw-bold mb-0 text-dark">
                <i class="fas fa-random text-primary me-2"></i>
                انٹریز تفریق، منتقلی و کھاتہ درستگی ٹول (Segregation & Reassignment)
            </h3>
            <small class="text-muted">مختلف صارفین، کھاتوں یا مدرسہ/مسجد سسٹمز کے درمیان مکس ہونے والی انٹریز کو فلٹر کر کے ایک کلک میں الگ الگ کریں</small>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="simple_finance.php" class="btn btn-outline-secondary fw-bold">
                <i class="fas fa-arrow-right me-1"></i> واپس فنانس پینل
            </a>
        </div>
    </div>

    <!-- Notifications -->
    <?php if (!empty($msg)): ?>
    <div class="alert alert-<?php echo $msg_type; ?> alert-dismissible fade show shadow-sm" role="alert">
        <i class="fas <?php echo $msg_type === 'success' ? 'fa-check-circle' : 'fa-info-circle'; ?> me-2"></i>
        <strong><?php echo htmlspecialchars($msg); ?></strong>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <!-- Comprehensive Filter Panel -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-light py-2 fw-bold text-dark border-bottom">
            <i class="fas fa-filter text-info me-1"></i> فلٹر پینل (مطلوبہ انٹریز تلاش کریں)
        </div>
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-end">
                <!-- User Filter -->
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-dark">صارف (User):</label>
                    <select name="user_id" class="form-select form-select-sm shadow-sm" onchange="this.form.submit()">
                        <option value="all" <?php echo ($filter_user_id === 'all') ? 'selected' : ''; ?>>-- تمام صارفین (All Users) --</option>
                        <?php foreach ($sys_users as $su): ?>
                            <option value="<?php echo $su['id']; ?>" <?php echo ((string)$su['id'] === (string)$filter_user_id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(!empty($su['name']) ? $su['name'] : $su['username']); ?> (ID: <?php echo $su['id']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- System Type Filter -->
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-dark">سسٹم (System):</label>
                    <select name="system_type" class="form-select form-select-sm shadow-sm" onchange="this.form.submit()">
                        <option value="all" <?php echo ($filter_system === 'all') ? 'selected' : ''; ?>>تمام سسٹمز</option>
                        <option value="Madrasa" <?php echo ($filter_system === 'Madrasa') ? 'selected' : ''; ?>>مدرسہ (Madrasa)</option>
                        <option value="Masjid" <?php echo ($filter_system === 'Masjid') ? 'selected' : ''; ?>>مسجد (Masjid)</option>
                    </select>
                </div>

                <!-- Entry Type Filter -->
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-dark">قسم (Type):</label>
                    <select name="entry_type" class="form-select form-select-sm shadow-sm" onchange="this.form.submit()">
                        <option value="all" <?php echo ($filter_type === 'all') ? 'selected' : ''; ?>>تمام (آمدن و خرچ)</option>
                        <option value="Expense" <?php echo ($filter_type === 'Expense') ? 'selected' : ''; ?>>صرف اخراجات (Expense)</option>
                        <option value="Income" <?php echo ($filter_type === 'Income') ? 'selected' : ''; ?>>صرف آمدن (Income)</option>
                    </select>
                </div>

                <!-- Account Filter -->
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-dark">کھاتہ (Account):</label>
                    <select name="account_filter" class="form-select form-select-sm shadow-sm" onchange="this.form.submit()">
                        <option value="all" <?php echo ($filter_account === 'all') ? 'selected' : ''; ?>>تمام کھاتے</option>
                        <option value="advance_only" <?php echo ($filter_account === 'advance_only') ? 'selected' : ''; ?>>صرف ایڈوانس کھاتے</option>
                        <option value="collection_only" <?php echo ($filter_account === 'collection_only') ? 'selected' : ''; ?>>صرف وصولی امانت کھاتے</option>
                        <option value="cash_1001" <?php echo ($filter_account === 'cash_1001') ? 'selected' : ''; ?>>صرف مرکزی نقد (1001)</option>
                        <optgroup label="مخصوص کھاتہ چنیں">
                            <?php foreach ($available_accounts as $acc): ?>
                                <option value="<?php echo $acc['id']; ?>" <?php echo ((string)$acc['id'] === (string)$filter_account) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($acc['name'] . ' (' . $acc['code'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>

                <!-- Search box -->
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-dark">تلاش (Search):</label>
                    <div class="input-group input-group-sm">
                        <input type="text" name="search" class="form-control" placeholder="رسید، تفصیل، رقم..." value="<?php echo htmlspecialchars($search_term); ?>">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Badges -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card shadow-sm border-0 border-start border-4 border-primary h-100">
                <div class="card-body p-3">
                    <span class="text-muted small fw-bold">زیرِ فہرست انٹریز کی تعداد</span>
                    <h3 class="fw-bold text-primary mb-0 mt-1"><?php echo number_format($total_listed_count); ?></h3>
                    <small class="text-muted">موجودہ فلٹرز کے مطابق</small>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm border-0 border-start border-4 border-danger h-100">
                <div class="card-body p-3">
                    <span class="text-muted small fw-bold">کل اخراجات کی رقم</span>
                    <h3 class="fw-bold text-danger mb-0 mt-1">Rs. <?php echo number_format($expense_sum); ?></h3>
                    <small class="text-muted">فہرست میں موجود اخراجات</small>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm border-0 border-start border-4 border-success h-100">
                <div class="card-body p-3">
                    <span class="text-muted small fw-bold">کل آمدن کی رقم</span>
                    <h3 class="fw-bold text-success mb-0 mt-1">Rs. <?php echo number_format($income_sum); ?></h3>
                    <small class="text-muted">فہرست میں موجود آمدن</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Table & Bulk Action Form -->
    <form method="POST" id="bulkForm">
        <input type="hidden" name="action" value="bulk_reassign">

        <!-- Bulk Action Multi-Bar -->
        <div class="card shadow-sm border-0 mb-3 bg-white border-top border-3 border-warning">
            <div class="card-body p-3">
                <div class="row g-2 align-items-center">
                    <div class="col-lg-3 col-md-12">
                        <div class="d-flex align-items-center gap-2">
                            <div class="form-check form-switch m-0">
                                <input class="form-check-input" type="checkbox" id="selectAllCheckbox" style="cursor: pointer; width: 2.5em; height: 1.3em;">
                                <label class="form-check-label fw-bold text-dark ms-2" for="selectAllCheckbox" style="cursor: pointer;">
                                    سب منتخب کریں
                                </label>
                            </div>
                            <span class="badge bg-primary fs-6 fw-normal" id="selectedCountBadge">0 منتخب شدہ</span>
                        </div>
                    </div>

                    <!-- Target User Selector -->
                    <div class="col-lg-3 col-md-4">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text fw-bold bg-light text-dark"><i class="fas fa-user me-1 text-primary"></i> نیا صارف:</span>
                            <select name="target_user_id" class="form-select form-select-sm fw-bold">
                                <option value="no_change">[صارف تبدیل نہ کریں]</option>
                                <?php foreach ($sys_users as $su): ?>
                                    <option value="<?php echo $su['id']; ?>">
                                        <?php echo htmlspecialchars(!empty($su['name']) ? $su['name'] : $su['username']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Target Account Selector -->
                    <div class="col-lg-3 col-md-4">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text fw-bold bg-light text-dark"><i class="fas fa-wallet me-1 text-success"></i> نیا کھاتہ:</span>
                            <select name="target_account_id" class="form-select form-select-sm fw-bold">
                                <option value="no_change">[کھاتہ تبدیل نہ کریں]</option>
                                <?php foreach ($available_accounts as $acc): ?>
                                    <option value="<?php echo $acc['id']; ?>" <?php echo ($acc['code'] === '1001') ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($acc['name'] . ' (' . $acc['code'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Target System Selector & Submit Button -->
                    <div class="col-lg-3 col-md-4">
                        <div class="d-flex gap-2">
                            <select name="target_system_type" class="form-select form-select-sm fw-bold w-auto">
                                <option value="no_change">[سسٹم وہی رہے]</option>
                                <option value="Madrasa">مدرسہ</option>
                                <option value="Masjid">مسجد</option>
                            </select>
                            <button type="submit" class="btn btn-warning btn-sm text-dark fw-bold shadow-sm flex-grow-1" id="bulkMoveBtn" onclick="return confirm('کیا آپ واقعی منتخب کردہ تمام انٹریز پر یہ تبدیلیاں نافذ کرنا چاہتے ہیں؟');" disabled>
                                <i class="fas fa-check-double me-1"></i> تبدیلیاں نافذ کریں
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Entries Table -->
        <div class="card shadow-sm border-0">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center py-2">
                <span class="fw-bold text-white">
                    <i class="fas fa-list me-2"></i> انٹریز کی فہرست (کل: <?php echo count($entries); ?>)
                </span>
                <span class="small text-white-50">ہر انٹری کو انفرادی طور پر بھی تبدیل کیا جا سکتا ہے</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 text-center table-bordered">
                        <thead class="table-light text-muted small">
                            <tr>
                                <th style="width: 35px;">#</th>
                                <th style="width: 70px;">تاریخ</th>
                                <th style="width: 60px;">قسم</th>
                                <th>رسید نمبر</th>
                                <th>صارف (User)</th>
                                <th>کھاتہ (Account)</th>
                                <th>سسٹم</th>
                                <th>مد (Head)</th>
                                <th>رقم</th>
                                <th>تفصیل</th>
                                <th style="width: 100px;">ایکشن</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($entries)): ?>
                            <tr>
                                <td colspan="11" class="text-center py-5 text-muted">
                                    <i class="fas fa-check-circle text-success fa-3x mb-3 d-block"></i>
                                    موجودہ فلٹرز کے مطابق کوئی انٹری نہیں ملی۔
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($entries as $idx => $e): 
                                    $isExp = ($e['type'] === 'Expense');
                                    $sysVal = $e['system_type'] ?? 'Madrasa';
                                    if (empty($sysVal)) $sysVal = 'Madrasa';
                                ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="entry_ids[]" value="<?php echo $e['id']; ?>" class="form-check-input row-checkbox" style="cursor: pointer;">
                                    </td>
                                    <td><small class="fw-bold"><?php echo !empty($e['date']) ? date('d-M-y', strtotime($e['date'])) : '---'; ?></small></td>
                                    <td>
                                        <span class="badge <?php echo $isExp ? 'bg-danger' : 'bg-success'; ?>">
                                            <?php echo $isExp ? 'خرچ' : 'آمدن'; ?>
                                        </span>
                                    </td>
                                    <td class="small fw-bold">
                                        <?php 
                                            if (!empty($e['computer_receipt_no'])) {
                                                echo '#' . $e['computer_receipt_no'];
                                            } elseif (!empty($e['manual_receipt_no'])) {
                                                echo 'دستی: ' . $e['manual_receipt_no'];
                                            } else {
                                                echo '---';
                                            }
                                        ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border">
                                            <i class="fas fa-user text-primary me-1"></i>
                                            <?php echo htmlspecialchars($e['resolved_user_name']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border">
                                            <i class="fas fa-wallet text-secondary me-1"></i>
                                            <?php echo htmlspecialchars($e['account_name'] ?? 'غیر واضح'); ?>
                                            <?php if (!empty($e['account_code'])): ?>
                                                (<?php echo $e['account_code']; ?>)
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo ($sysVal === 'Masjid') ? 'bg-warning text-dark' : 'bg-info text-dark'; ?>">
                                            <?php echo ($sysVal === 'Masjid') ? 'مسجد' : 'مدرسہ'; ?>
                                        </span>
                                    </td>
                                    <td class="small fw-bold text-dark"><?php echo htmlspecialchars($e['head_name'] ?? '---'); ?></td>
                                    <td class="fw-bold <?php echo $isExp ? 'text-danger' : 'text-success'; ?>">Rs. <?php echo number_format((float)$e['amount']); ?></td>
                                    <td class="small text-start text-muted" style="max-width: 220px;"><?php echo htmlspecialchars((string)($e['description'] ?? '')); ?></td>
                                    <td>
                                        <button type="button" class="btn btn-xs btn-outline-primary py-1 px-2" onclick="openSingleReassignModal(<?php echo $e['id']; ?>, '<?php echo htmlspecialchars(addslashes($e['head_name'] ?? '')); ?>', <?php echo (float)$e['amount']; ?>, <?php echo (int)($e['user_id'] ?? 1); ?>, <?php echo (int)($e['account_id'] ?? 0); ?>, '<?php echo $sysVal; ?>')">
                                            <i class="fas fa-edit me-1"></i> درست کریں
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- Single Reassign Modal -->
<div class="modal fade" id="singleReassignModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content text-end" style="direction: rtl;">
            <form method="POST">
                <input type="hidden" name="action" value="single_reassign">
                <input type="hidden" name="entry_id" id="modal_single_entry_id">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold">انفرادی انٹری کی درستگی و تفریق</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info py-2 small mb-3">
                        انٹری: <strong id="modal_single_head"></strong> | رقم: <strong class="text-danger" id="modal_single_amount"></strong>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">صارف (User) تبدیل کریں:</label>
                        <select name="single_user_id" id="modal_single_user_id" class="form-select">
                            <option value="no_change">[صارف وہی رہے]</option>
                            <?php foreach ($sys_users as $su): ?>
                                <option value="<?php echo $su['id']; ?>">
                                    <?php echo htmlspecialchars(!empty($su['name']) ? $su['name'] : $su['username']); ?> (ID: <?php echo $su['id']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">کھاتہ (Account) تبدیل کریں:</label>
                        <select name="single_account_id" id="modal_single_account_id" class="form-select">
                            <option value="no_change">[کھاتہ وہی رہے]</option>
                            <?php foreach ($available_accounts as $acc): ?>
                                <option value="<?php echo $acc['id']; ?>">
                                    <?php echo htmlspecialchars($acc['name'] . ' (' . $acc['code'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">سسٹم ٹائپ (System Type) تبدیل کریں:</label>
                        <select name="single_system_type" id="modal_single_system_type" class="form-select">
                            <option value="no_change">[سسٹم وہی رہے]</option>
                            <option value="Madrasa">مدرسہ (Madrasa)</option>
                            <option value="Masjid">مسجد (Masjid)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" class="btn btn-primary fw-bold">محفوظ کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('selectAllCheckbox');
    const rowCheckboxes = document.querySelectorAll('.row-checkbox');
    const badge = document.getElementById('selectedCountBadge');
    const bulkBtn = document.getElementById('bulkMoveBtn');

    function updateCount() {
        let count = 0;
        rowCheckboxes.forEach(cb => {
            if (cb.checked) count++;
        });
        if (badge) badge.textContent = count + ' منتخب شدہ';
        if (bulkBtn) bulkBtn.disabled = (count === 0);
    }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            rowCheckboxes.forEach(cb => {
                cb.checked = selectAll.checked;
            });
            updateCount();
        });
    }

    rowCheckboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            if (!cb.checked && selectAll) selectAll.checked = false;
            updateCount();
        });
    });
});

function openSingleReassignModal(id, head, amount, userId, accountId, sysType) {
    document.getElementById('modal_single_entry_id').value = id;
    document.getElementById('modal_single_head').textContent = head;
    document.getElementById('modal_single_amount').textContent = 'Rs. ' + amount.toLocaleString();
    
    const userSelect = document.getElementById('modal_single_user_id');
    if (userSelect) userSelect.value = userId;

    const accSelect = document.getElementById('modal_single_account_id');
    if (accSelect) accSelect.value = accountId;

    const sysSelect = document.getElementById('modal_single_system_type');
    if (sysSelect) sysSelect.value = sysType;

    var m = new bootstrap.Modal(document.getElementById('singleReassignModal'));
    m.show();
}
</script>

<?php include 'footer.php'; ?>
