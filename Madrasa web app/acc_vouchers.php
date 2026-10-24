<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once 'financial_audit_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$u_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin = in_array($u_role, ['admin', 'superadmin', 'super_admin', 'administrator'], true);
if (!$is_admin && !app_has_perm('manage_erp')) {
    header("Location: dashboard.php");
    exit;
}
$role = $_SESSION['role'] ?? '';
$user = ['id' => $_SESSION['user_id'], 'role' => $role];

$cur_tenant_id = get_current_tenant_id($pdo);
$sys = $_SESSION['system_type'] ?? 'Madrasa';

// Run self-healing and orphan cleanup on vouchers page load
try {
    $pdo->exec("ALTER TABLE acc_vouchers MODIFY COLUMN status VARCHAR(50) DEFAULT 'Pending'");
} catch (Throwable $eStatus) {
    try {
        $pdo->exec("ALTER TABLE acc_vouchers MODIFY COLUMN status ENUM('Pending', 'Approved', 'Rejected', 'Reversed', 'Cancelled') DEFAULT 'Pending'");
    } catch (Throwable $eStatus2) {}
}

if (function_exists('acc_ensure_standard_chart_and_orphans_repaired')) {
    acc_ensure_standard_chart_and_orphans_repaired($pdo, $cur_tenant_id);
}

$msg = '';
$err = '';

// Handle Actions: Add Voucher
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_voucher'])) {
    $date = $_POST['date'] ?? date('Y-m-d');
    $type = $_POST['type'] ?? 'Journal';
    $desc = $_POST['description'] ?? '';
    $ref = $_POST['reference'] ?? '';
    
    // Auto-generate voucher number scoped by tenant
    $prefix = strtoupper(substr($type, 0, 1)) . 'V-';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM acc_vouchers WHERE type = ? AND tenant_id = ?");
    $stmt->execute([$type, $cur_tenant_id]);
    $count = (int)$stmt->fetchColumn() + 1;
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
            if ($dr < 0 || $cr < 0) {
                throw new Exception("Negative amount lines are not permitted.");
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

        // Voucher status: Admins post directly as Approved; other roles submit as Pending
        $initial_status = $is_admin ? 'Approved' : 'Pending';

        if (function_exists("acc_ensure_audit_log_schema")) { acc_ensure_audit_log_schema($pdo); } if (!$pdo->inTransaction()) { $pdo->beginTransaction(); }
        $stmt = $pdo->prepare("INSERT INTO acc_vouchers (voucher_no, date, type, reference, description, department_id, created_by, system_type, tenant_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$voucher_no, $date, $type, $ref, $desc, $department_id, $user['id'], $sys, $cur_tenant_id, $initial_status]);
        $voucher_id = (int)$pdo->lastInsertId();
        
        $entry_stmt = $pdo->prepare("INSERT INTO acc_ledger_entries (voucher_id, account_id, debit, credit, department_id, tenant_id) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($clean_lines as $line) {
            $entry_stmt->execute([$voucher_id, $line[0], $line[1], $line[2], $department_id, $cur_tenant_id]);
        }

        acc_log_audit($pdo, $cur_tenant_id, 'acc_vouchers', $voucher_id, 'CREATE_VOUCHER', null, [
            'voucher_no' => $voucher_no, 'date' => $date, 'type' => $type, 'status' => $initial_status, 'total' => $total_debit
        ], $user['id'], $_SESSION['name'] ?? 'User', 'Web');

        if ($pdo->inTransaction()) { $pdo->commit(); }
        $msg = "Voucher $voucher_no created successfully ($initial_status)!";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $err = "Failed: " . $e->getMessage();
    }
}

// D1 Fix: Reversal Voucher Handler (Immutable Corrections - Supporting both POST and GET)
$is_reverse_action = (isset($_POST['reverse_voucher']) || isset($_GET['reverse_voucher']) || 
                      (isset($_POST['action']) && $_POST['action'] === 'reverse') || 
                      (isset($_GET['action']) && $_GET['action'] === 'reverse'));

if ($is_reverse_action) {
    $v_id = (int)($_POST['voucher_id'] ?? ($_GET['voucher_id'] ?? ($_GET['id'] ?? 0)));
    $vStmt = $pdo->prepare("SELECT * FROM acc_vouchers WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0) AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1");
    $vStmt->execute([$v_id, $cur_tenant_id]);
    $orig = $vStmt->fetch(PDO::FETCH_ASSOC);

    if (!$orig) {
        $err = "واؤچر نہیں ملا یا حذف ہو چکا ہے۔";
    } elseif ($orig['status'] === 'Reversed') {
        $err = "یہ واؤچر پہلے ہی معکوس (Reversed) ہو چکا ہے۔";
    } else {
        // Self-heal legacy ledger lines where tenant_id might be missing
        try {
            $pdo->prepare("UPDATE acc_ledger_entries SET tenant_id = ? WHERE voucher_id = ? AND (tenant_id IS NULL OR tenant_id = 0)")->execute([$cur_tenant_id, $v_id]);
        } catch (Throwable $e) {}

        $lStmt = $pdo->prepare("SELECT * FROM acc_ledger_entries WHERE voucher_id = ? AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)");
        $lStmt->execute([$v_id, $cur_tenant_id]);
        $lines = $lStmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($lines)) {
            $err = "اس واؤچر کے لیجر اندراجات نہیں ملے۔";
        } else {
            $rev_voucher_no = 'REV-' . $orig['voucher_no'];
            $rev_date = date('Y-m-d');
            $rev_ref = 'REV-' . $orig['voucher_no'];
            $rev_desc = "عکسی واؤچر برائے تنسیخ واؤچر # " . $orig['voucher_no'] . " (" . $orig['description'] . ")";

            try {
                // Ensure column is VARCHAR to prevent MySQL ENUM truncation
                try {
                    $pdo->exec("ALTER TABLE acc_vouchers MODIFY COLUMN status VARCHAR(50) DEFAULT 'Pending'");
                } catch (Throwable $eMod) {}

                if (function_exists("acc_ensure_audit_log_schema")) { acc_ensure_audit_log_schema($pdo); }
                if (!$pdo->inTransaction()) { $pdo->beginTransaction(); }
                
                try {
                    $insV = $pdo->prepare("INSERT INTO acc_vouchers (voucher_no, date, type, reference, description, department_id, created_by, system_type, tenant_id, status, is_deleted) VALUES (?, ?, 'Journal', ?, ?, ?, ?, ?, ?, 'Approved', 0)");
                    $insV->execute([$rev_voucher_no, $rev_date, $rev_ref, $rev_desc, $orig['department_id'] ?? null, $user['id'], $orig['system_type'] ?? $sys, $cur_tenant_id]);
                } catch (Throwable $eVFallback) {
                    $insV = $pdo->prepare("INSERT INTO acc_vouchers (voucher_no, date, type, reference, description, created_by, tenant_id, status, is_deleted) VALUES (?, ?, 'Journal', ?, ?, ?, ?, 'Approved', 0)");
                    $insV->execute([$rev_voucher_no, $rev_date, $rev_ref, $rev_desc, $user['id'], $cur_tenant_id]);
                }
                $new_v_id = (int)$pdo->lastInsertId();

                try {
                    $insE = $pdo->prepare("INSERT INTO acc_ledger_entries (voucher_id, account_id, debit, credit, department_id, tenant_id) VALUES (?, ?, ?, ?, ?, ?)");
                    foreach ($lines as $line) {
                        // Invert: Dr -> Cr, Cr -> Dr
                        $insE->execute([$new_v_id, (int)$line['account_id'], (float)$line['credit'], (float)$line['debit'], $line['department_id'] ?? null, $cur_tenant_id]);
                    }
                } catch (Throwable $eEnt) {
                    $insE = $pdo->prepare("INSERT INTO acc_ledger_entries (voucher_id, account_id, debit, credit, tenant_id) VALUES (?, ?, ?, ?, ?)");
                    foreach ($lines as $line) {
                        $insE->execute([$new_v_id, (int)$line['account_id'], (float)$line['credit'], (float)$line['debit'], $cur_tenant_id]);
                    }
                }

                try {
                    $updOrig = $pdo->prepare("UPDATE acc_vouchers SET status = 'Reversed', description = CONCAT(description, ' [Reversed by ', ?, ']') WHERE id = ?");
                    $updOrig->execute([$rev_voucher_no, $v_id]);
                } catch (Throwable $eRevUpd) {
                    $updOrig = $pdo->prepare("UPDATE acc_vouchers SET status = 'Rejected', description = CONCAT(description, ' [Reversed by ', ?, ']') WHERE id = ?");
                    $updOrig->execute([$rev_voucher_no, $v_id]);
                }

                if (function_exists('acc_log_audit')) {
                    acc_log_audit($pdo, $cur_tenant_id, 'acc_vouchers', $new_v_id, 'REVERSE_VOUCHER', ['original_voucher_id' => $v_id], [
                        'reversal_voucher_id' => $new_v_id,
                        'reversal_voucher_no' => $rev_voucher_no
                    ], $user['id'], $_SESSION['name'] ?? 'Admin', 'Web');
                }

                if ($pdo->inTransaction()) { $pdo->commit(); }
                header("Location: acc_vouchers.php?msg=" . urlencode("عکسی واؤچر $rev_voucher_no کامیابی سے بن گیا اور اصل واؤچر تنسیخ (Reversed) ہو گیا۔"));
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $err = "عکسی واؤچر کی تیاری میں خرابی: " . $e->getMessage();
            }
        }
    }
}

// D8 Fix: Voucher Approval & Status Updates (with four-eyes check)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status']) && $is_admin) {
    $v_id = (int)$_POST['voucher_id'];
    $new_status = $_POST['status'];
    if (in_array($new_status, ['Pending', 'Approved', 'Rejected'])) {
        $vChk = $pdo->prepare("SELECT * FROM acc_vouchers WHERE id = ? AND tenant_id = ? LIMIT 1");
        $vChk->execute([$v_id, $cur_tenant_id]);
        $target_v = $vChk->fetch(PDO::FETCH_ASSOC);

        if ($target_v) {
            // Check total amount
            $tStmt = $pdo->prepare("SELECT SUM(debit) FROM acc_ledger_entries WHERE voucher_id = ? AND tenant_id = ?");
            $tStmt->execute([$v_id, $cur_tenant_id]);
            $v_amount = (float)$tStmt->fetchColumn();

            // Four-eyes check: Creator cannot approve their own high-value voucher (> 50,000) unless superadmin
            if ($new_status === 'Approved' && (int)$target_v['created_by'] === (int)$user['id'] && $v_amount > 50000 && !in_array($u_role, ['superadmin', 'super_admin'])) {
                $err = "چار آنکھوں کا اصول (Four-Eyes Principle): آپ اپنے تیار کردہ بھاری واؤچر (50,000 سے زائد) کو خود منظور نہیں کر سکتے۔ دوسرا ایڈمن منظور کرے۔";
            } else {
                $pdo->prepare("UPDATE acc_vouchers SET status = ? WHERE id = ? AND tenant_id = ?")->execute([$new_status, $v_id, $cur_tenant_id]);
                acc_log_audit($pdo, $cur_tenant_id, 'acc_vouchers', $v_id, 'STATUS_CHANGE', ['old_status' => $target_v['status']], ['new_status' => $new_status], $user['id'], $_SESSION['name'] ?? 'Admin', 'Web');
                $msg = "واؤچر کی حالت تبدیل ہو کر $new_status ہو گئی۔";
            }
        }
    }
}

// Delete Voucher (Only Unposted/Draft/Pending)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_voucher']) && $is_admin) {
    $v_id = (int)$_POST['voucher_id'];
    $vChk = $pdo->prepare("SELECT * FROM acc_vouchers WHERE id = ? AND tenant_id = ? LIMIT 1");
    $vChk->execute([$v_id, $cur_tenant_id]);
    $target_v = $vChk->fetch(PDO::FETCH_ASSOC);

    if ($target_v && in_array(strtolower((string)$target_v['status']), ['approved', 'posted'])) {
        $err = "پوسٹ شدہ واؤچر کو ڈیلیٹ کرنا ممنوع ہے۔ آڈٹ ٹریل کے لیے اس کا عکسی واؤچر (Reversal Voucher) بنائیں۔";
    } else {
        try {
            $pdo->prepare("UPDATE acc_vouchers SET is_deleted = 1 WHERE id = ? AND tenant_id = ?")->execute([$v_id, $cur_tenant_id]);
            acc_log_audit($pdo, $cur_tenant_id, 'acc_vouchers', $v_id, 'DELETE_PENDING_VOUCHER', $target_v, ['is_deleted' => 1], $user['id'], $_SESSION['name'] ?? 'Admin', 'Web');
            $msg = "واؤچر کو بحفاظت حذف کر دیا گیا۔";
        } catch (Exception $e) {
            $err = "Failed to delete voucher.";
        }
    }
}

// Filter tab
$status_filter = $_GET['status_filter'] ?? 'All';
$status_clause = "";
$status_args = [$cur_tenant_id];

if ($status_filter === 'Pending') {
    $status_clause = " AND v.status = 'Pending'";
} elseif ($status_filter === 'Approved') {
    $status_clause = " AND v.status = 'Approved'";
} elseif ($status_filter === 'Reversed') {
    $status_clause = " AND v.status = 'Reversed'";
}

// Fetch Vouchers with System Type and Tenant Isolation
$sys_cond = ($sys === 'Masjid') ? "v.system_type = 'Masjid'" : "(v.system_type = 'Madrasa' OR v.system_type IS NULL OR v.system_type = '')";

$sql = "SELECT v.*, u.name as creator_name, 
        (SELECT SUM(debit) FROM acc_ledger_entries WHERE voucher_id = v.id AND tenant_id = v.tenant_id) as total_amount 
        FROM acc_vouchers v 
        LEFT JOIN admins u ON v.created_by = u.id 
        WHERE (v.is_deleted = 0 OR v.is_deleted IS NULL) AND $sys_cond AND v.tenant_id = ? $status_clause 
        ORDER BY v.date DESC, v.id DESC LIMIT 200";

$v_stmt = $pdo->prepare($sql);
$v_stmt->execute($status_args);
$vouchers = $v_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Chart of Accounts for Dropdown (Tenant scoped - excluding legacy/deleted duplicate accounts)
$acc_list_stmt = $pdo->prepare("SELECT id, code, name FROM acc_chart_of_accounts WHERE (is_deleted = 0 OR is_deleted IS NULL) AND (is_active = 1 OR is_active IS NULL) AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0) AND code NOT IN ('1101', '1201', '1301', '2101', '2102', '2103', '5010', '5011') ORDER BY CAST(code AS UNSIGNED) ASC, code ASC");
$acc_list_stmt->execute([$cur_tenant_id]);
$accounts_list = $acc_list_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Departments (Tenant scoped)
$dept_stmt = $pdo->prepare("SELECT id, name FROM departments WHERE status = 'Active' AND tenant_id = ? ORDER BY name");
$dept_stmt->execute([$cur_tenant_id]);
$departments_list = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Vouchers - ERP Accounting";
include 'header.php';
?>

<div class="container py-4 urdu-text" style="font-family: 'Noto Nastaliq Urdu', serif;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-primary m-0"><i class="fas fa-receipt me-2"></i> <?php echo ($lang=='ur') ? 'مالیاتی واؤچرز (Accounting Vouchers)' : 'Accounting Vouchers'; ?></h3>
            <small class="text-muted"><?php echo ($lang=='ur') ? 'ڈبل انٹری جنرل لیجر اور آڈٹ ٹریل سسٹم' : 'Double-entry general ledger & audit trail system'; ?></small>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-success fw-bold" data-bs-toggle="modal" data-bs-target="#newVoucherModal">
                <i class="fas fa-plus me-1"></i> <?php echo ($lang=='ur') ? 'نیا واؤچر بنائیں' : 'Create New Voucher'; ?>
            </button>
        </div>
    </div>

    <!-- Status Filter Pills -->
    <div class="card p-2 mb-3 bg-light border-0 shadow-sm">
        <div class="d-flex gap-2 flex-wrap">
            <a href="acc_vouchers.php?status_filter=All" class="btn btn-sm <?php echo $status_filter=='All'?'btn-primary':'btn-outline-secondary'; ?>"><?php echo ($lang=='ur') ? 'تمام واؤچرز' : 'All Vouchers'; ?></a>
            <a href="acc_vouchers.php?status_filter=Approved" class="btn btn-sm <?php echo $status_filter=='Approved'?'btn-success':'btn-outline-success'; ?>"><i class="fas fa-check-circle me-1"></i> <?php echo ($lang=='ur') ? 'منظور شدہ (Approved/Posted)' : 'Approved / Posted'; ?></a>
            <a href="acc_vouchers.php?status_filter=Pending" class="btn btn-sm <?php echo $status_filter=='Pending'?'btn-warning text-dark':'btn-outline-warning'; ?>"><i class="fas fa-clock me-1"></i> <?php echo ($lang=='ur') ? 'زیرِ التواء (Pending Approval)' : 'Pending Approval'; ?></a>
            <a href="acc_vouchers.php?status_filter=Reversed" class="btn btn-sm <?php echo $status_filter=='Reversed'?'btn-dark':'btn-outline-dark'; ?>"><i class="fas fa-undo me-1"></i> <?php echo ($lang=='ur') ? 'تنسیخ شدہ (Reversed)' : 'Reversed'; ?></a>
        </div>
    </div>

    <?php if (!empty($msg)): ?>
        <div class="alert alert-success alert-dismissible fade show"><?php echo e($msg); ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if (!empty($err)): ?>
        <div class="alert alert-danger alert-dismissible fade show"><?php echo e($err); ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 text-center">
                <thead class="table-dark">
                    <tr>
                        <th><?php echo ($lang=='ur') ? 'تاریخ' : 'Date'; ?></th>
                        <th><?php echo ($lang=='ur') ? 'واؤچر #' : 'Voucher #'; ?></th>
                        <th><?php echo ($lang=='ur') ? 'قسم' : 'Type'; ?></th>
                        <th><?php echo ($lang=='ur') ? 'حوالہ' : 'Reference'; ?></th>
                        <th><?php echo ($lang=='ur') ? 'تفصیل' : 'Description'; ?></th>
                        <th class="text-end"><?php echo ($lang=='ur') ? 'رقم' : 'Amount'; ?></th>
                        <th><?php echo ($lang=='ur') ? 'حالت' : 'Status'; ?></th>
                        <th><?php echo ($lang=='ur') ? 'اندراج کنندہ' : 'Created By'; ?></th>
                        <th class="text-center"><?php echo ($lang=='ur') ? 'ایکشن' : 'Actions'; ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($vouchers)): ?>
                        <tr><td colspan="9" class="text-center py-4 text-muted"><?php echo ($lang=='ur') ? 'کوئی واؤچر موجود نہیں ہے۔' : 'No vouchers found.'; ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($vouchers as $v): 
                            $is_v_posted = in_array(strtolower((string)$v['status']), ['approved', 'posted']);
                            $is_v_rev = (strtolower((string)$v['status']) === 'reversed');
                        ?>
                        <tr>
                            <td><?php echo date('d-M-Y', strtotime($v['date'])); ?></td>
                            <td class="fw-bold text-primary"><?php echo e($v['voucher_no']); ?></td>
                            <td><span class="badge bg-secondary"><?php echo e($v['type']); ?></span></td>
                            <td><small class="text-muted"><?php echo e($v['reference'] ?? '---'); ?></small></td>
                            <td><small><?php echo e($v['description'] ?? '---'); ?></small></td>
                            <td class="text-end fw-bold">Rs. <?php echo number_format($v['total_amount'] ?? 0, 2); ?></td>
                            <td>
                                <?php if ($v['status'] == 'Approved'): ?>
                                    <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i> <?php echo ($lang=='ur') ? 'منظور شدہ' : 'Approved'; ?></span>
                                <?php elseif ($v['status'] == 'Reversed'): ?>
                                    <span class="badge bg-dark"><i class="fas fa-undo me-1"></i> <?php echo ($lang=='ur') ? 'تنسیخ شدہ' : 'Reversed'; ?></span>
                                <?php elseif ($v['status'] == 'Rejected'): ?>
                                    <span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i> <?php echo ($lang=='ur') ? 'مسترد' : 'Rejected'; ?></span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark"><i class="fas fa-hourglass-half me-1"></i> <?php echo ($lang=='ur') ? 'زیرِ التواء' : 'Pending'; ?></span>
                                <?php endif; ?>
                            </td>
                            <td><small><?php echo e($v['creator_name'] ?? 'System'); ?></small></td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-primary me-1" title="<?php echo ($lang=='ur') ? 'تفصیل دیکھیں' : 'View Details'; ?>" onclick="viewVoucher(<?php echo $v['id']; ?>)"><i class="fas fa-eye"></i></button>
                                
                                <?php if ($is_v_posted): ?>
                                    <!-- Posted vouchers can only be reversed (D1 Immutability) -->
                                    <a href="acc_vouchers.php?action=reverse&voucher_id=<?php echo $v['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('<?php echo ($lang=='ur') ? 'کیا آپ واقعی اس واؤچر کا عکسی واؤچر (Reversal Voucher) بنانا چاہتے ہیں؟' : 'Are you sure you want to reverse this voucher?'; ?>');" title="<?php echo ($lang=='ur') ? 'عکسی واؤچر بنائیں' : 'Reverse Voucher'; ?>"><i class="fas fa-undo me-1"></i> <?php echo ($lang=='ur') ? 'عکسی واؤچر' : 'Reverse'; ?></a>
                                <?php elseif (!$is_v_rev): ?>
                                    <!-- Pending vouchers can be edited directly -->
                                    <a href="acc_voucher_edit.php?id=<?php echo $v['id']; ?>" class="btn btn-sm btn-outline-warning me-1" title="<?php echo ($lang=='ur') ? 'ترمیم کریں' : 'Edit'; ?>"><i class="fas fa-edit"></i></a>
                                    
                                    <?php if ($is_admin): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="voucher_id" value="<?php echo $v['id']; ?>">
                                            <input type="hidden" name="status" value="Approved">
                                            <button type="submit" name="update_status" class="btn btn-sm btn-outline-success me-1" title="<?php echo ($lang=='ur') ? 'منظور کریں' : 'Approve'; ?>"><i class="fas fa-check"></i></button>
                                        </form>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('<?php echo ($lang=='ur') ? 'حذف کریں؟' : 'Delete voucher?'; ?>');">
                                            <input type="hidden" name="voucher_id" value="<?php echo $v['id']; ?>">
                                            <button type="submit" name="delete_voucher" class="btn btn-sm btn-outline-secondary" title="<?php echo ($lang=='ur') ? 'حذف کریں' : 'Delete'; ?>"><i class="fas fa-trash"></i></button>
                                        </form>
                                    <?php endif; ?>
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
        <form method="POST" class="modal-content <?php echo ($lang=='ur')?'urdu-text':''; ?>" <?php echo ($lang=='ur')?'style="font-family: \'Noto Nastaliq Urdu\', serif;"':''; ?>>
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i> <?php echo ($lang=='ur') ? 'نیا واؤچر بنائیں (New Journal/Payment/Receipt)' : 'Create New Voucher (JV / PV / RV)'; ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="fw-bold"><?php echo ($lang=='ur') ? 'تاریخ' : 'Date'; ?></label>
                        <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="fw-bold"><?php echo ($lang=='ur') ? 'واؤچر کی قسم' : 'Voucher Type'; ?></label>
                        <select name="type" class="form-select" required>
                            <option value="Journal">Journal Voucher (JV)</option>
                            <option value="Payment">Payment Voucher (PV)</option>
                            <option value="Receipt">Receipt Voucher (RV)</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="fw-bold"><?php echo ($lang=='ur') ? 'شعبہ (اختیاری)' : 'Department (Optional)'; ?></label>
                        <select name="department_id" class="form-select">
                            <option value="">-- <?php echo ($lang=='ur') ? 'کوئی نہیں' : 'None'; ?> --</option>
                            <?php foreach($departments_list as $d): ?>
                                <option value="<?php echo $d['id']; ?>"><?php echo e($d['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold"><?php echo ($lang=='ur') ? 'حوالہ نمبر / بل نمبر' : 'Reference / Bill #'; ?></label>
                        <input type="text" name="reference" class="form-control" placeholder="<?php echo ($lang=='ur') ? 'مثلاً INV-1002' : 'e.g. INV-1002'; ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold"><?php echo ($lang=='ur') ? 'تفصیل / وضاحتی نوٹ' : 'Description / Memo'; ?></label>
                        <input type="text" name="description" class="form-control" placeholder="<?php echo ($lang=='ur') ? 'لین دین کی تفصیل درج کریں' : 'Enter transaction memo'; ?>">
                    </div>
                </div>

                <h6 class="fw-bold text-secondary border-bottom pb-2 mb-3"><?php echo ($lang=='ur') ? 'لیجر اندراجات (کم از کم 2 لائنیں، ڈیبٹ اور کریڈٹ کا برابر ہونا لازمی ہے)' : 'Ledger Entries (Minimum 2 lines, Total Debit must equal Total Credit)'; ?></h6>
                <div id="ledgerLines">
                    <div class="row g-2 mb-2 line-row">
                        <div class="col-md-6">
                            <select name="account_id[]" class="form-select select2" required>
                                <option value="">-- <?php echo ($lang=='ur') ? 'کھاتہ منتخب کریں' : 'Select Account'; ?> --</option>
                                <?php foreach($accounts_list as $a): ?>
                                    <option value="<?php echo $a['id']; ?>"><?php echo e($a['code'] . ' - ' . $a['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <input type="number" step="0.01" name="debit[]" class="form-control debit-input" placeholder="<?php echo ($lang=='ur') ? 'ڈیبٹ (Dr)' : 'Debit (Dr)'; ?>" value="0">
                        </div>
                        <div class="col-md-3">
                            <input type="number" step="0.01" name="credit[]" class="form-control credit-input" placeholder="<?php echo ($lang=='ur') ? 'کریڈٹ (Cr)' : 'Credit (Cr)'; ?>" value="0">
                        </div>
                    </div>
                    <div class="row g-2 mb-2 line-row">
                        <div class="col-md-6">
                            <select name="account_id[]" class="form-select select2" required>
                                <option value="">-- <?php echo ($lang=='ur') ? 'کھاتہ منتخب کریں' : 'Select Account'; ?> --</option>
                                <?php foreach($accounts_list as $a): ?>
                                    <option value="<?php echo $a['id']; ?>"><?php echo e($a['code'] . ' - ' . $a['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <input type="number" step="0.01" name="debit[]" class="form-control debit-input" placeholder="<?php echo ($lang=='ur') ? 'ڈیبٹ (Dr)' : 'Debit (Dr)'; ?>" value="0">
                        </div>
                        <div class="col-md-3">
                            <input type="number" step="0.01" name="credit[]" class="form-control credit-input" placeholder="<?php echo ($lang=='ur') ? 'کریڈٹ (Cr)' : 'Credit (Cr)'; ?>" value="0">
                        </div>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="addLedgerLine()"><i class="fas fa-plus me-1"></i> <?php echo ($lang=='ur') ? 'مزید لائن شامل کریں' : 'Add Line'; ?></button>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo ($lang=='ur') ? 'منسوخ کریں' : 'Cancel'; ?></button>
                <button type="submit" name="add_voucher" class="btn btn-primary fw-bold"><i class="fas fa-save me-1"></i> <?php echo ($lang=='ur') ? 'واؤچر محفوظ کریں' : 'Save Voucher'; ?></button>
            </div>
        </form>
    </div>
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
                <option value="">-- کھاتہ منتخب کریں --</option>
                <?php foreach($accounts_list as $a): ?>
                    <option value="<?php echo $a['id']; ?>"><?php echo e($a['code'] . ' - ' . $a['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <input type="number" step="0.01" name="debit[]" class="form-control debit-input" placeholder="ڈیبٹ (Dr)" value="0">
        </div>
        <div class="col-md-3">
            <input type="number" step="0.01" name="credit[]" class="form-control credit-input" placeholder="کریڈٹ (Cr)" value="0">
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
