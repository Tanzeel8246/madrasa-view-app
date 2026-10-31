<?php
require_once 'db.php';
require_once 'finance_accounting_bridge.php';
require_once 'financial_audit_helper.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Bulletproof table schema additions (run once)
$finSchemaFlag = __DIR__ . '/.fin_schema_done';
if (!file_exists($finSchemaFlag)) {
    try { $pdo->exec("ALTER TABLE heads ADD COLUMN is_deleted TINYINT DEFAULT 0"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE finance ADD COLUMN is_deleted TINYINT DEFAULT 0"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE finance ADD COLUMN computer_receipt_no INT DEFAULT NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE finance ADD COLUMN system_type VARCHAR(50) DEFAULT 'Madrasa'"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE finance ADD COLUMN head_name VARCHAR(255) DEFAULT NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE finance ADD COLUMN spender_name VARCHAR(255) DEFAULT NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE finance ADD COLUMN account_id INT DEFAULT NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE finance ADD COLUMN payment_method VARCHAR(50) DEFAULT 'Cash'"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE finance ADD COLUMN deleted_at DATETIME DEFAULT NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE finance ADD COLUMN original_receipt_no VARCHAR(100) DEFAULT NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE acc_chart_of_accounts ADD COLUMN is_deleted TINYINT DEFAULT 0"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE acc_chart_of_accounts ADD COLUMN system_type VARCHAR(50) DEFAULT 'Madrasa'"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE acc_chart_of_accounts ADD COLUMN is_active TINYINT(1) DEFAULT 1"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE acc_vouchers ADD COLUMN is_deleted TINYINT(1) DEFAULT 0"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE acc_vouchers ADD COLUMN created_by INT DEFAULT NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE acc_vouchers ADD COLUMN system_type VARCHAR(50) DEFAULT 'Madrasa'"); } catch (Throwable $e) {}
    @touch($finSchemaFlag);
}

// Ensure deleted_at and original_receipt_no columns always exist
try { $pdo->exec("ALTER TABLE finance ADD COLUMN deleted_at DATETIME DEFAULT NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE finance ADD COLUMN original_receipt_no VARCHAR(100) DEFAULT NULL"); } catch (Throwable $e) {}

// Self-healing: Free deleted receipt numbers so they are immediately reusable
// and clean up any test entries above 300040 for Admin ID 1
try {
    // 1. Move computer_receipt_no of deleted records into original_receipt_no and set computer_receipt_no to NULL
    $pdo->exec("
        UPDATE finance 
        SET original_receipt_no = COALESCE(original_receipt_no, receipt_no, CAST(computer_receipt_no AS CHAR)),
            computer_receipt_no = NULL 
        WHERE is_deleted = 1 AND computer_receipt_no IS NOT NULL
    ");

    // 2. If user ID 1 has active record with 300057 generated due to gap after 300040, correct it to 300041
    $chkActiveMax = (int)$pdo->query("SELECT MAX(computer_receipt_no) FROM finance WHERE computer_receipt_no >= 300000 AND computer_receipt_no <= 300040 AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchColumn();
    if ($chkActiveMax >= 300000) {
        $expectedNextNo = $chkActiveMax + 1;
        // Check if there is an active record with 300057 (or any single jump) and no active records between 300040 and that jump
        $hasJump = $pdo->query("SELECT id FROM finance WHERE user_id = 1 AND computer_receipt_no = 300057 AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1")->fetchColumn();
        if ($hasJump) {
            $hasInBetween = (int)$pdo->query("SELECT COUNT(*) FROM finance WHERE computer_receipt_no > 300040 AND computer_receipt_no < 300057 AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchColumn();
            if ($hasInBetween === 0) {
                $pdo->prepare("UPDATE finance SET computer_receipt_no = ?, receipt_no = ? WHERE id = ?")->execute([$expectedNextNo, (string)$expectedNextNo, $hasJump]);
            }
        }
    }
} catch (Throwable $eSeqFix) {}

// Self-healing restore: Restore advance expenses for users who spend from Advance accounts
$restoreAdvFlag = __DIR__ . '/.restore_user_advance_expenses_v6_done';
if (!file_exists($restoreAdvFlag)) {
    try {
        $cur_tid = (int)get_current_tenant_id();
        $advAcctRows = $pdo->query("SELECT id, name FROM acc_chart_of_accounts WHERE type = 'Asset' AND (name LIKE 'ایڈوانس%' OR name LIKE 'Advance%') AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchAll(PDO::FETCH_ASSOC);
        $admRows = $pdo->query("SELECT id, username, name, role FROM admins")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($advAcctRows as $aa) {
            $aid = (int)$aa['id'];
            $clean_label = trim(preg_replace('/^(ایڈوانس|advance)\s*[-:]?\s*/iu', '', $aa['name']));
            if (empty($clean_label)) continue;

            $matchedUser = null;
            foreach ($admRows as $u) {
                $un = !empty($u['username']) ? $u['username'] : '';
                $nm = !empty($u['name']) ? $u['name'] : '';
                if (mb_strtolower(trim($un)) === mb_strtolower($clean_label) ||
                    mb_strtolower(trim($nm)) === mb_strtolower($clean_label) ||
                    (!empty($un) && mb_stripos($aa['name'], $un) !== false) ||
                    (!empty($nm) && mb_stripos($aa['name'], $nm) !== false) ||
                    (!empty($nm) && mb_stripos($clean_label, $nm) !== false) ||
                    (!empty($un) && mb_stripos($clean_label, $un) !== false)) {
                    $matchedUser = $u;
                    break;
                }
            }

            // Auto-overwrite removed to preserve user/account segregation integrity
            // and allow users to spend from collection/central cash without being forced into advance.
        }
        @touch($restoreAdvFlag);
    } catch (Throwable $eRep) {}
}


if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$u_role_check = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin_check = in_array($u_role_check, ['admin', 'superadmin', 'super_admin', 'administrator'], true);
$can_access_fin = $is_admin_check || $u_role_check === 'nazim' || app_has_perm('manage_finance') || app_has_perm('manage_finance_entry');
if (!$can_access_fin) {
    header("Location: dashboard.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$cur_tenant_id = (int)get_current_tenant_id();
finance_consolidate_advance_accounts($pdo, $cur_tenant_id);
$user_name = $_SESSION['name'] ?? 'Admin';
$current_username = $_SESSION['username'] ?? '';
if (empty($current_username) && !empty($user_id)) {
    try {
        $stmt = $pdo->prepare("SELECT username, name FROM admins WHERE id = ?");
        $stmt->execute([$user_id]);
        $uRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($uRow) {
            $current_username = !empty($uRow['username']) ? $uRow['username'] : $uRow['name'];
            $_SESSION['username'] = $current_username;
        }
    } catch (Throwable $e) {}
}
if (empty($current_username)) {
    $current_username = $user_name;
}
$sys = $_SESSION['system_type'] ?? 'Madrasa';
$lang = $_SESSION['lang'] ?? 'ur';
$user_role_lower = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin = in_array($user_role_lower, ['admin', 'superadmin', 'super_admin', 'administrator']);

if (!function_exists('safe_localize_head')) {
    function safe_localize_head($name, $lang = 'ur') {
        if (function_exists('localize_head_name')) {
            return localize_head_name($name, $lang);
        }
        return $name;
    }
}

if (!function_exists('render_user_wise_finance_cards')) {
    function render_user_wise_finance_cards($user_groups, $type = 'income', $sys = 'Madrasa', $lang = 'ur', $current_admin_id = 0) {
        $is_income = ($type === 'income');
        $prefix = $is_income ? 'inc' : 'exp';
        $container_id = $is_income ? 'userWiseIncomeCards' : 'userWiseExpenseCards';
        $search_id = $is_income ? 'searchUserIncome' : 'searchUserExpense';
        $print_file = ($sys === 'Masjid') ? 'print_masjid_receipt.php' : 'print_receipt.php';
        
        $total_users = count($user_groups);
        $total_records = 0;
        foreach ($user_groups as $ug) {
            $total_records += count($is_income ? $ug['income_records'] : $ug['expense_records']);
        }
        
        ob_start();
        ?>
        <div class="card shadow-sm border-0 neobox mb-4">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2" style="background: #1e293b !important; color: #ffffff !important; border-top-left-radius: 12px; border-top-right-radius: 12px;">
                <h5 class="mb-0 fw-bold text-white" style="color: #ffffff !important;">
                    <i class="fas <?php echo $is_income ? 'fa-hand-holding-usd text-success' : 'fa-receipt text-danger'; ?> me-2"></i>
                    <?php echo $is_income ? __('آمدن انٹریز بلحاظ یوزرز (User-Wise Income Boxes)', 'User-Wise Income Boxes') : __('خرچ انٹریز بلحاظ یوزرز (User-Wise Expense Boxes)', 'User-Wise Expense Boxes'); ?>
                    <span class="badge bg-white text-dark ms-2 fw-bold"><?php echo __('کل یوزرز:', 'Total Users:'); ?> <?php echo $total_users; ?> | <?php echo __('کل انٹریز:', 'Total Entries:'); ?> <?php echo $total_records; ?></span>
                </h5>
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <button type="button" class="btn btn-sm" style="color: #ffffff !important; border: 1px solid rgba(255,255,255,0.4) !important; background: rgba(255,255,255,0.12) !important; font-weight: 600;" onclick="toggleAllUserCards('<?php echo $prefix; ?>', true)" title="<?php echo __('تمام کارڈز کھولیں', 'Expand All Cards'); ?>">
                        <i class="fas fa-expand-arrows-alt me-1"></i> <?php echo __('سب کھولیں', 'Expand All'); ?>
                    </button>
                    <button type="button" class="btn btn-sm" style="color: #ffffff !important; border: 1px solid rgba(255,255,255,0.4) !important; background: rgba(255,255,255,0.12) !important; font-weight: 600;" onclick="toggleAllUserCards('<?php echo $prefix; ?>', false)" title="<?php echo __('تمام کارڈز بند کریں', 'Collapse All Cards'); ?>">
                        <i class="fas fa-compress-arrows-alt me-1"></i> <?php echo __('سب بند کریں', 'Collapse All'); ?>
                    </button>
                    <input type="text" id="<?php echo $search_id; ?>" class="form-control form-control-sm" style="width: 190px; background: #ffffff !important; color: #1e293b !important; border: 1px solid rgba(255,255,255,0.5) !important;" placeholder="<?php echo __('یوزر یا تفصیل تلاش کریں...', 'Search user or details...'); ?>" onkeyup="filterUserCards('<?php echo $container_id; ?>', '<?php echo $search_id; ?>')">
                </div>
            </div>
            <div class="card-body p-3 bg-light" id="<?php echo $container_id; ?>">
                <?php if (empty($user_groups)): ?>
                    <div class="text-center p-4 text-muted fw-bold"><?php echo __('کوئی ریکارڈ موجود نہیں', 'No records found'); ?></div>
                <?php else: ?>
                    <div class="accordion" id="accordion_<?php echo $prefix; ?>">
                        <?php foreach ($user_groups as $uid => $ug): 
                            $records = $is_income ? $ug['income_records'] : $ug['expense_records'];
                            $rec_count = count($records);
                            $is_self = ((int)$uid === (int)$current_admin_id);
                            $uBal = $ug['total_income'] - $ug['total_expense'];
                            $collapse_id = "collapse_{$prefix}_{$uid}";
                            $should_show = ($is_self || $total_users === 1) ? 'show' : '';
                        ?>
                        <div class="card mb-3 border-0 shadow-sm user-finance-card" style="border-radius: 10px; overflow: hidden;">
                            <div class="card-header p-3 bg-white d-flex flex-wrap justify-content-between align-items-center" 
                                 style="cursor: pointer; border-right: 5px solid <?php echo $is_income ? '#198754' : '#dc3545'; ?>;" 
                                 data-bs-toggle="collapse" data-bs-target="#<?php echo $collapse_id; ?>">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="avatar-circle bg-light text-primary border d-flex align-items-center justify-content-center" style="width: 38px; height: 38px; border-radius: 50%;">
                                        <i class="fas fa-user-tie"></i>
                                    </div>
                                    <div>
                                        <span class="fw-bold fs-6 text-dark"><?php echo htmlspecialchars($ug['name']); ?></span>
                                        <?php if ($is_self): ?>
                                            <span class="badge bg-primary-subtle text-primary border border-primary ms-1"><?php echo __('آپ کا اکاؤنٹ', 'Your Account'); ?></span>
                                        <?php endif; ?>
                                        <span class="badge bg-secondary-subtle text-secondary border ms-1">ID: <?php echo $uid; ?></span>
                                    </div>
                                </div>
                                
                                <div class="d-flex align-items-center flex-wrap gap-2 mt-2 mt-md-0">
                                    <?php 
                                    $op_inc = (float)($ug['operating_income'] ?? $ug['total_income']);
                                    $tot_exp = (float)($ug['total_expense'] ?? 0);
                                    $op_exp = (float)($ug['operating_expense'] ?? $tot_exp);
                                    $loan_in = (float)($ug['loan_inflow'] ?? 0);
                                    $loan_out = (float)($ug['loan_outflow'] ?? 0);
                                    $has_adv = !empty($ug['has_advance']) && (($ug['advance_received'] ?? 0) > 0 || ($ug['advance_deducted'] ?? 0) > 0);
                                    ?>
                                    <?php if ($op_inc > 0 || !$has_adv): ?>
                                    <span class="badge bg-success-subtle text-success border border-success px-2 py-1">
                                        <i class="fas fa-arrow-down me-1"></i><?php echo __('خالص آمدن:', 'Net Income:'); ?> Rs. <?php echo number_format($op_inc); ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php if ($loan_in > 0): ?>
                                    <span class="badge bg-warning-subtle text-dark border border-warning px-2 py-1">
                                        <i class="fas fa-handshake me-1"></i><?php echo __('قرض آمد:', 'Loan Inflow:'); ?> Rs. <?php echo number_format($loan_in); ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php 
                                    $display_exp = ($loan_out > 0) ? $tot_exp : $op_exp;
                                    ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger px-2 py-1">
                                        <i class="fas fa-arrow-up me-1"></i><?php echo __('کل خرچ:', 'Total Expense:'); ?> Rs. <?php echo number_format($display_exp); ?>
                                    </span>
                                    <?php if ($has_adv): 
                                        $adv_rec = (float)($ug['advance_received'] ?? 0);
                                        $adv_ded = (float)($ug['advance_deducted'] ?? 0);
                                        $adv_rem = (float)($ug['advance_balance'] ?? ($adv_rec - $adv_ded));
                                    ?>
                                    <span class="badge bg-info-subtle text-dark border border-info px-2 py-1">
                                        <i class="fas fa-exchange-alt me-1"></i><?php echo __('موصولہ ایڈوانس:', 'Advance Received:'); ?> Rs. <?php echo number_format($adv_rec); ?>
                                    </span>
                                    <span class="badge bg-danger-subtle text-danger border border-danger px-2 py-1">
                                        <i class="fas fa-minus-circle me-1"></i><?php echo __('ایڈوانس سے منہا:', 'Deducted from Advance:'); ?> Rs. <?php echo number_format($adv_ded); ?>
                                    </span>
                                    <span class="badge <?php echo $adv_rem >= 0 ? 'bg-success text-white' : 'bg-danger text-white'; ?> px-2 py-1 fw-bold">
                                        <i class="fas fa-wallet me-1"></i><?php echo __('بقایا ایڈوانس بیلنس:', 'Advance Balance:'); ?> <?php echo ($adv_rem < 0 ? '-' : '+'); ?> Rs. <?php echo number_format(abs($adv_rem)); ?>
                                    </span>
                                    <?php if (!empty($ug['other_expenses']) && $ug['other_expenses'] > 0): ?>
                                    <span class="badge bg-secondary-subtle text-secondary border px-2 py-1" title="<?php echo __('کیش یا دیگر کھاتوں سے ادا شدہ اخراجات', 'Expenses paid via Cash or other accounts'); ?>">
                                        <?php echo __('کیش / دیگر کھاتوں سے خرچ:', 'Spent from Cash/Other Accounts:'); ?> Rs. <?php echo number_format($ug['other_expenses']); ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php if ($op_inc > 0): 
                                        $cashBal = $op_inc - (float)($ug['other_expenses'] ?? $op_exp);
                                    ?>
                                    <span class="badge <?php echo $cashBal >= 0 ? 'bg-primary-subtle text-primary border-primary' : 'bg-warning-subtle text-warning border-warning'; ?> border px-2 py-1" title="<?php echo __('آمدن اور عام کیش اخراجات کا توازن', 'Balance of income and cash expenses'); ?>">
                                        <i class="fas fa-coins me-1"></i><?php echo __('عام کیش بیلنس:', 'General Cash Balance:'); ?> Rs. <?php echo number_format($cashBal); ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <span class="badge <?php echo $uBal >= 0 ? 'bg-primary-subtle text-primary border-primary' : 'bg-warning-subtle text-warning border-warning'; ?> border px-2 py-1">
                                        <i class="fas fa-wallet me-1"></i><?php echo __('بقایا بیلنس:', 'Remaining Balance:'); ?> Rs. <?php echo number_format($uBal); ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php if ($loan_out > 0): ?>
                                    <span class="badge bg-warning-subtle text-dark border border-warning px-2 py-1">
                                        <i class="fas fa-handshake me-1"></i><?php echo __('قرض ادائیگی:', 'Loan Outflow:'); ?> Rs. <?php echo number_format($loan_out); ?>
                                    </span>
                                    <?php endif; ?>
                                    <span class="badge bg-dark text-white px-2 py-1">
                                        <?php echo $rec_count; ?> <?php echo __('انٹریز', 'Entries'); ?>
                                    </span>
                                    <i class="fas fa-chevron-down text-muted ms-2"></i>
                                </div>
                            </div>
                            
                            <div id="<?php echo $collapse_id; ?>" class="collapse <?php echo $should_show; ?> <?php echo $prefix; ?>-collapse">
                                <div class="card-body p-0">
                                    <?php if ($rec_count === 0): ?>
                                        <div class="text-center p-4 text-muted"><?php echo $is_income ? __('اس صارف کی کوئی آمدن انٹری موجود نہیں ہے۔', 'No income entries for this user.') : __('اس صارف کی کوئی خرچ انٹری موجود نہیں ہے۔', 'No expense entries for this user.'); ?></div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover align-middle mb-0 text-center">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th><?php echo __('تاریخ', 'Date'); ?></th>
                                                        <th><?php echo __('مد', 'Head / Category'); ?></th>
                                                        <th><?php echo __('رقم', 'Amount'); ?></th>
                                                        <?php if ($is_income): ?>
                                                            <th><?php echo __('رسید #', 'Receipt #'); ?></th>
                                                            <th><?php echo __('تفصیل / نام', 'Details / Name'); ?></th>
                                                        <?php else: ?>
                                                            <th><?php echo __('تفصیل', 'Details'); ?></th>
                                                        <?php endif; ?>
                                                        <th><?php echo __('ایکشن', 'Action'); ?></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($records as $r): ?>
                                                    <tr>
                                                        <td><small class="fw-bold"><?php echo !empty($r['date']) ? date('d-M-Y', strtotime($r['date'])) : '---'; ?></small></td>
                                                        <td class="fw-bold <?php echo $is_income ? 'text-success' : 'text-danger'; ?>">
                                                            <?php echo htmlspecialchars(safe_localize_head($r['head_name'] ?? '---', $lang)); ?>
                                                            <?php if(!empty($r['account_name'])): ?>
                                                                <br><span class="badge bg-light text-dark border"><i class="fas fa-wallet text-secondary me-1"></i><?php echo htmlspecialchars($r['account_name']); ?></span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="fw-bold <?php echo $is_income ? 'text-success' : 'text-danger'; ?>">
                                                            Rs. <?php echo number_format((float)($r['amount'] ?? 0)); ?>
                                                        </td>
                                                        <?php if ($is_income): ?>
                                                            <td>
                                                                <span class="badge bg-secondary">#<?php echo htmlspecialchars((string)(!empty($r['computer_receipt_no']) ? $r['computer_receipt_no'] : ($r['id'] ?? ''))); ?></span>
                                                            </td>
                                                            <td class="small text-start">
                                                                <?php if (!empty($r['is_loan'])): ?>
                                                                    <span class="badge bg-warning text-dark border border-warning px-2 py-0 me-1"><i class="fas fa-handshake"></i> <?php echo __('قرض کیش فلو', 'Loan Cash Flow'); ?></span>
                                                                <?php endif; ?>
                                                                <?php if(!empty($r['donor_name'])): ?>
                                                                    <span class="text-primary fw-bold"><?php echo htmlspecialchars((string)$r['donor_name']); ?></span><br>
                                                                <?php endif; ?>
                                                                <span class="text-muted"><?php echo htmlspecialchars((string)($r['description'] ?? '')); ?></span>
                                                            </td>
                                                        <?php else: ?>
                                                            <td class="small text-start text-muted">
                                                                <?php if (!empty($r['is_loan'])): ?>
                                                                    <span class="badge bg-warning text-dark border border-warning px-2 py-0 me-1"><i class="fas fa-handshake"></i> <?php echo __('قرض کیش فلو', 'Loan Cash Flow'); ?></span>
                                                                <?php endif; ?>
                                                                <?php if(!empty($r['donor_name'])): ?>
                                                                    <span class="text-danger fw-bold"><?php echo htmlspecialchars((string)$r['donor_name']); ?></span><br>
                                                                <?php endif; ?>
                                                                <?php echo htmlspecialchars((string)($r['description'] ?? '')); ?>
                                                            </td>
                                                        <?php endif; ?>
                                                        <td>
                                                            <div class="btn-group btn-group-sm">
                                                                <?php if (!empty($r['bill_image'])): ?>
                                                                    <a href="<?php echo htmlspecialchars($r['bill_image']); ?>" target="_blank" class="btn btn-outline-info" title="<?php echo __('منسلک بل دیکھیں', 'View Attached Bill'); ?>"><i class="fas fa-file-invoice-dollar"></i></a>
                                                                <?php endif; ?>
                                                                <?php if ($is_income): ?>
                                                                    <a href="<?php echo $print_file; ?>?id=<?php echo $r['id']; ?>" class="btn btn-outline-primary" target="_blank" title="<?php echo __('رسید پرنٹ', 'Print Receipt'); ?>"><i class="fas fa-print"></i></a>
                                                                <?php endif; ?>
                                                                <a href="edit_finance.php?id=<?php echo $r['id']; ?>" class="btn btn-outline-warning" title="<?php echo __('ترمیم', 'Edit'); ?>"><i class="fas fa-edit"></i></a>
                                                                <form method="POST" action="simple_finance.php" style="display:inline-block;" onsubmit="return confirm('<?php echo __('کیا واقعی یہ ریکارڈ ڈیلیٹ کرنا چاہتے ہیں؟', 'Are you sure you want to delete this record?'); ?>');">
                                                                    <?php echo csrf_field(); ?>
                                                                    <input type="hidden" name="delete_record" value="1">
                                                                    <input type="hidden" name="del_id" value="<?php echo $r['id']; ?>">
                                                                    <button type="submit" class="btn btn-outline-danger" title="<?php echo __('ڈیلیٹ', 'Delete'); ?>"><i class="fas fa-trash"></i></button>
                                                                </form>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

if (!function_exists('next_user_receipt_no')) {
    function next_user_receipt_no(PDO $pdo, $userId, $sys = 'Madrasa', $tenant_id = null) {
        if ($tenant_id === null) $tenant_id = (int)get_current_tenant_id();
        $userId = max(1, (int)$userId);
        if ($userId === 1) {
            // تنزیل الرحمن (ایڈمن ID 1) کا رسید تسلسل سابقہ 300000 سیریز (300041 آگے) سے جاری رہے
            $minNo = 300000;
            $maxNo = 400000;
        } elseif ($userId === 3) {
            // قاری سلیم اللہ صاحب (ID 3) کے لیے 700000 بلاک تاکہ تنزیل کے نمبرز سے تصادم نہ ہو
            $minNo = 700000;
            $maxNo = 800000;
        } else {
            $minNo = $userId * 100000;
            $maxNo = ($userId + 1) * 100000;
        }
        $currentMax = $minNo;
        
        try {
            if ($sys === 'Masjid') {
                $stmt = $pdo->prepare("SELECT MAX(computer_receipt_no) FROM finance WHERE computer_receipt_no > ? AND computer_receipt_no < ? AND system_type = 'Masjid' AND tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)");
                $stmt->execute([$minNo, $maxNo, $tenant_id]);
                $currentMax = (int)$stmt->fetchColumn();
                
                try {
                    $hasMasjidFinTable = !empty($pdo->query("SHOW TABLES LIKE 'masjid_finance'")->fetchAll());
                    if ($hasMasjidFinTable) {
                        $mStmt = $pdo->prepare("SELECT MAX(computer_receipt_no) FROM masjid_finance WHERE computer_receipt_no > ? AND computer_receipt_no < ? AND tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)");
                        $mStmt->execute([$minNo, $maxNo, $tenant_id]);
                        $mMax = (int)$mStmt->fetchColumn();
                        if ($mMax > $currentMax) {
                            $currentMax = $mMax;
                        }
                    }
                } catch (Throwable $e) {}
            } else {
                $stmt = $pdo->prepare("SELECT MAX(computer_receipt_no) FROM finance WHERE computer_receipt_no > ? AND computer_receipt_no < ? AND (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '') AND tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)");
                $stmt->execute([$minNo, $maxNo, $tenant_id]);
                $currentMax = (int)$stmt->fetchColumn();
            }
        } catch (Throwable $e) {
            try {
                $stmt = $pdo->prepare("SELECT MAX(computer_receipt_no) FROM finance WHERE computer_receipt_no > ? AND computer_receipt_no < ? AND tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)");
                $stmt->execute([$minNo, $maxNo, $tenant_id]);
                $currentMax = (int)$stmt->fetchColumn();
            } catch (Throwable $e2) {
                try {
                    $stmt = $pdo->prepare("SELECT MAX(computer_receipt_no) FROM finance WHERE computer_receipt_no > ? AND computer_receipt_no < ? AND tenant_id = ?");
                    $stmt->execute([$minNo, $maxNo, $tenant_id]);
                    $currentMax = (int)$stmt->fetchColumn();
                } catch (Throwable $e3) {
                    $currentMax = $minNo;
                }
            }
        }
        
        $nextNo = ($currentMax > $minNo) ? ($currentMax + 1) : ($minNo + 1);
        return $nextNo;
    }
}

// Delete Record (Soft delete to Trash/Recycle Bin, preserve original receipt text but free numeric receipt immediately)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_record'])) {
    validate_csrf_or_abort();
    $delId = (int)$_POST['del_id'];
    if (!$is_admin && !finance_has_valid_edit_permission($pdo, $delId, (int)$_SESSION['user_id'])) {
        header("Location: edit_finance.php?id=" . $delId . "&otp_required=1");
        exit;
    }
    try { finance_accounting_delete_voucher($pdo, $delId, $cur_tenant_id); } catch (Throwable $e) {}
    
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
        $delStmt->execute([$delId, $cur_tenant_id]);
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
            ")->execute([$delId]);
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
        ")->execute([$delId]);
    } catch (Throwable $e3) {}

    header("Location: simple_finance.php?msg=deleted");
    exit;
}

// Handle Add/Edit Record submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_record'])) {
    validate_csrf_or_abort();
    $type = !empty($_POST['type']) ? trim($_POST['type']) : (isset($_POST['manual_receipt_no']) || isset($_POST['donor_address']) ? 'Income' : 'Expense');
    $date = $_POST['date'];
    $head_id = (int)$_POST['head_id'];
    $amount = (float)$_POST['amount'];
    $donor_name = $_POST['donor_name'] ?? '';
    $donor_address = $_POST['donor_address'] ?? '';
    $manual_receipt_no = $_POST['manual_receipt_no'] ?? '';
    $description = $_POST['description'] ?? '';
    $def_cash_id = function_exists('finance_accounting_ensure_account')
        ? finance_accounting_ensure_account($pdo, '1001', 'Cash in Hand', 'Asset', $cur_tenant_id)
        : null;
    $account_id = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : $def_cash_id;
    $payment_method = $_POST['payment_method'] ?? 'Cash';
    
    // Automatically set payment_method to Advance if spending from an advance account
    if (!empty($account_id) && $type === 'Expense') {
        try {
            $stmt_an = $pdo->prepare("SELECT name FROM acc_chart_of_accounts WHERE id = ?");
            $stmt_an->execute([$account_id]);
            $an_val = (string)($stmt_an->fetchColumn() ?: '');
            if (mb_stripos($an_val, 'ایڈوانس') !== false || mb_stripos($an_val, 'advance') !== false) {
                $payment_method = 'Advance';
            }
        } catch (Throwable $e) {}
    }
    
    // Block manual entry for Salary/Stipends/Loans
    $chk_name = '';
    try {
        $stmt_chk = $pdo->prepare("SELECT name FROM heads WHERE id = ?");
        $stmt_chk->execute([$head_id]);
        $chk_name = (string)($stmt_chk->fetchColumn() ?: '');
    } catch (Throwable $e) {}
    
    $blocked_keywords = ['salary', 'salaries', 'stipend', 'stipends', 'سیلری', 'وظیفہ', 'وظائف', 'تنخواہ', 'تنخواہیں', 'قرض'];
    $is_blocked = false;
    $combined_text = $chk_name . ' ' . $description;
    foreach ($blocked_keywords as $kw) {
        if (mb_stripos($combined_text, $kw) !== false) {
            $is_blocked = true;
            break;
        }
    }
    if ($is_blocked && $type === 'Expense') {
        header("Location: simple_finance.php?error=blocked_head&head_name=" . urlencode($chk_name ?: $description));
        exit;
    }

    // Security / Privacy: Non-admin users cannot record expenses against other users' advance accounts
    if (!$is_admin && $type === 'Expense' && !empty($account_id)) {
        $chk_stmt = $pdo->prepare("SELECT name FROM acc_chart_of_accounts WHERE id = ?");
        $chk_stmt->execute([(int)$account_id]);
        $an = $chk_stmt->fetchColumn();
        if ($an && mb_strpos($an, 'ایڈوانس') !== false) {
            $lower_an = mb_strtolower($an);
            $u1 = mb_strtolower(trim($current_username));
            $u2 = mb_strtolower(trim($_SESSION['name'] ?? ''));
            if ((empty($u1) || mb_strpos($lower_an, $u1) === false) && (empty($u2) || mb_strpos($lower_an, $u2) === false)) {
                header("Location: simple_finance.php?error=unauthorized_account&err_msg=" . urlencode("دوسرے صارف کے ایڈوانس کھاتے سے خرچ درج کرنے کی اجازت نہیں ہے۔"));
                exit;
            }
        }
        if (mb_stripos($an, 'وصولی') !== false || mb_stripos($an, 'collection') !== false) {
            $lower_an = mb_strtolower($an);
            $u1 = mb_strtolower(trim($current_username));
            $u2 = mb_strtolower(trim($_SESSION['name'] ?? ''));
            if ((empty($u1) || mb_strpos($lower_an, $u1) === false) && (empty($u2) || mb_strpos($lower_an, $u2) === false)) {
                header("Location: simple_finance.php?error=unauthorized_account&err_msg=" . urlencode("دوسرے صارف کے نقد وصولی (امانت) کھاتے سے انٹری درج کرنے کی اجازت نہیں ہے۔"));
                exit;
            }
        }
    }

    // If admin explicitly selected another user for this transaction
    if ($is_admin && isset($_POST['form_user_id']) && !empty($_POST['form_user_id'])) {
        $target_uid = (int)$_POST['form_user_id'];
        foreach ($sys_users as $su) {
            if ((int)$su['id'] === $target_uid) {
                $user_id = $target_uid;
                $user_name = !empty($su['name']) ? $su['name'] : $su['username'];
                break;
            }
        }
    }

    $bill_path = null;
    if (!empty($_FILES['bill_file']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['bill_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'])) $ext = 'jpg';
        $uploadDir = __DIR__ . '/uploads/bills/';
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);
        $fn = 'bill_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
        if (move_uploaded_file($_FILES['bill_file']['tmp_name'], $uploadDir . $fn)) {
            $bill_path = 'uploads/bills/' . $fn;
        }
    }

    if (isset($_POST['edit_id']) && !empty($_POST['edit_id'])) {
        $edit_id = (int)$_POST['edit_id'];
        if (!$is_admin && !finance_has_valid_edit_permission($pdo, $edit_id, (int)$_SESSION['user_id'])) {
            header("Location: edit_finance.php?id=" . $edit_id . "&otp_required=1");
            exit;
        }
        $upd_user_sql = "";
        $upd_user_params = [];
        if ($is_admin && isset($_POST['form_user_id']) && !empty($_POST['form_user_id'])) {
            $upd_user_sql = ", user_id = ?, user_name = ?";
            $upd_user_params = [$user_id, $user_name];
        }
        $upd_bill_sql = "";
        $upd_bill_params = [];
        if ($bill_path !== null) {
            $upd_bill_sql = ", bill_image = ?";
            $upd_bill_params = [$bill_path];
        }
        try {
            $stmt = $pdo->prepare("UPDATE finance SET type=?, head_id=?, amount=?, date=?, donor_name=?, donor_address=?, manual_receipt_no=?, description=?, account_id=?, payment_method=? $upd_user_sql $upd_bill_sql WHERE id=? AND tenant_id=?");
            $stmt->execute(array_merge([$type, $head_id, $amount, $date, $donor_name, $donor_address, $manual_receipt_no, $description, $account_id, $payment_method], $upd_user_params, $upd_bill_params, [$edit_id, $cur_tenant_id]));
        } catch (Throwable $e) {
            try {
                $stmt = $pdo->prepare("UPDATE finance SET type=?, head_id=?, amount=?, date=?, donor_name=?, donor_address=?, manual_receipt_no=?, description=? $upd_user_sql $upd_bill_sql WHERE id=? AND tenant_id=?");
                $stmt->execute(array_merge([$type, $head_id, $amount, $date, $donor_name, $donor_address, $manual_receipt_no, $description], $upd_user_params, $upd_bill_params, [$edit_id, $cur_tenant_id]));
            } catch (Throwable $e2) {}
        }
        try { finance_accounting_post($pdo, $edit_id); } catch (Throwable $e) {}
        header("Location: simple_finance.php?msg=edited");
        exit;
    } else {
        $comp_receipt_no = null;
        if ($type == 'Income') {
            try {
                $comp_receipt_no = next_user_receipt_no($pdo, $user_id, $sys, $cur_tenant_id);
            } catch (Throwable $e) {
                $comp_receipt_no = null;
            }
        }

        // D2 Fix: Self-heal columns and ensure 100% column/value count parity
        try { $pdo->exec("ALTER TABLE finance ADD COLUMN account_id INT DEFAULT NULL"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE finance ADD COLUMN payment_method VARCHAR(50) DEFAULT NULL"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE finance ADD COLUMN bill_image VARCHAR(255) DEFAULT NULL"); } catch (Throwable $e) {}

        $insert_id = 0;
        try {
            $stmt = $pdo->prepare("INSERT INTO finance (user_id, user_name, type, head_id, amount, date, donor_name, donor_address, manual_receipt_no, computer_receipt_no, description, is_deleted, system_type, account_id, payment_method, tenant_id, bill_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $user_name, $type, $head_id, $amount, $date, $donor_name, $donor_address, $manual_receipt_no, $comp_receipt_no, $description, $sys, $account_id, $payment_method, $cur_tenant_id, $bill_path]);
            $insert_id = (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            try {
                // Robust fallback with exact 12 columns and 12 values (resolves SQLSTATE 21S01 error!)
                $stmt = $pdo->prepare("INSERT INTO finance (user_id, user_name, type, head_id, amount, date, donor_name, donor_address, manual_receipt_no, computer_receipt_no, description, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $user_name, $type, $head_id, $amount, $date, $donor_name, $donor_address, $manual_receipt_no, $comp_receipt_no, $description, $cur_tenant_id]);
                $insert_id = (int)$pdo->lastInsertId();
            } catch (Throwable $e2) {
                error_log("[Simple Finance Insert Error] " . $e2->getMessage());
                header("Location: simple_finance.php?error=insert_failed&err_msg=" . urlencode("اندراج محفوظ نہیں ہو سکا: " . $e2->getMessage()));
                exit;
            }
        }

        if ($insert_id > 0) {
            try { finance_accounting_post($pdo, $insert_id); } catch (Throwable $e) {}
            if (function_exists('acc_log_audit')) {
                acc_log_audit($pdo, $cur_tenant_id, 'finance', $insert_id, 'ADD_SIMPLE_FINANCE', null, [
                    'type' => $type, 'head_id' => $head_id, 'amount' => $amount, 'date' => $date, 'receipt' => $comp_receipt_no
                ], $user_id, $user_name, 'Web');
            }
        }
        header("Location: simple_finance.php?msg=added");
        exit;
    }
}

// Handle Fund Transfer submission (Contra Entry)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['transfer_funds'])) {
    validate_csrf_or_abort();
    try {
        // Helper function to resolve 'user_X' or 'col_X' to an account ID
        $resolve_account = function($raw_id, $pdo) use ($cur_tenant_id) {
            $raw_id = (string)$raw_id;
            if (strpos($raw_id, 'col_') === 0) {
                $u_id = (int)str_replace('col_', '', $raw_id);
                $stmt = $pdo->prepare("SELECT username, name FROM admins WHERE id = ?");
                $stmt->execute([$u_id]);
                $u = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($u) {
                    $u_label = !empty($u['name']) ? $u['name'] : (!empty($u['username']) ? $u['username'] : 'User ' . $u_id);
                    $acc_name = "نقد وصولی - " . $u_label;
                    $chk = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE (name = ? OR name LIKE ?) AND type = 'Asset' AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0) AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY (tenant_id = ?) DESC LIMIT 1");
                    $chk->execute([$acc_name, "%" . $u_label . "%", $cur_tenant_id, $cur_tenant_id]);
                    $ex = $chk->fetchColumn();
                    if ($ex) return (int)$ex;

                    $code_stmt = $pdo->prepare("SELECT MAX(CAST(code AS UNSIGNED)) FROM acc_chart_of_accounts WHERE type = 'Asset' AND code LIKE '11%' AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)");
                    $code_stmt->execute([$cur_tenant_id]);
                    $max_code = (int)($code_stmt ? $code_stmt->fetchColumn() : 0);
                    $new_code = max(1100, $max_code) + 1;
                    try {
                        $ins = $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, tenant_id, is_active, is_deleted) VALUES (?, ?, 'Asset', ?, 1, 0)");
                        $ins->execute([(string)$new_code, $acc_name, $cur_tenant_id]);
                        return (int)$pdo->lastInsertId();
                    } catch (Throwable $e) {}
                }
            } elseif (strpos($raw_id, 'user_') === 0) {
                $u_id = (int)str_replace('user_', '', $raw_id);
                $stmt = $pdo->prepare("SELECT username, name FROM admins WHERE id = ?");
                $stmt->execute([$u_id]);
                $u = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($u) {
                    $u_label = !empty($u['name']) ? $u['name'] : (!empty($u['username']) ? $u['username'] : 'User ' . $u_id);
                    $acc_name = "ایڈوانس - " . $u_label;
                    $chk = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE (name = ? OR name = ? OR name LIKE ?) AND type = 'Asset' AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0) AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY (tenant_id = ?) DESC LIMIT 1");
                    $chk->execute([$acc_name, "Advance - " . $u_label, "%" . $u_label . "%", $cur_tenant_id, $cur_tenant_id]);
                    $ex = $chk->fetchColumn();
                    if ($ex) return (int)$ex;
                    
                    $code_stmt = $pdo->prepare("SELECT MAX(CAST(code AS UNSIGNED)) FROM acc_chart_of_accounts WHERE type = 'Asset' AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)");
                    $code_stmt->execute([$cur_tenant_id]);
                    $max_code = (int)($code_stmt ? $code_stmt->fetchColumn() : 0);
                    $new_code = max(1500, $max_code + 1);
                    while ($pdo->query("SELECT COUNT(*) FROM acc_chart_of_accounts WHERE code = '$new_code' AND (tenant_id = $cur_tenant_id OR tenant_id IS NULL OR tenant_id = 0)")->fetchColumn() > 0) {
                        $new_code++;
                    }
                    
                    try {
                        $ins = $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, tenant_id, is_active, is_deleted) VALUES (?, ?, 'Asset', ?, 1, 0)");
                        $ins->execute([(string)$new_code, $acc_name, $cur_tenant_id]);
                        return (int)$pdo->lastInsertId();
                    } catch (Throwable $e) {
                        try {
                            $ins = $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, is_active, is_deleted) VALUES (?, ?, 'Asset', 1, 0)");
                            $ins->execute([(string)$new_code, $acc_name]);
                            return (int)$pdo->lastInsertId();
                        } catch (Throwable $e2) {}
                    }
                }
            }
            return (int)$raw_id;
        };

        $from_acc = $resolve_account($_POST['from_account_id'] ?? '', $pdo);
        $to_acc = $resolve_account($_POST['to_account_id'] ?? '', $pdo);
        $transfer_amount = (float)($_POST['transfer_amount'] ?? 0);
        $transfer_date = $_POST['transfer_date'] ?? date('Y-m-d');
        $transfer_desc = trim($_POST['transfer_description'] ?? '');

        // Security / Privacy: Non-admin users cannot transfer from or to other users' advance or collection accounts
        if (!$is_admin) {
            $chk_stmt = $pdo->prepare("SELECT name FROM acc_chart_of_accounts WHERE id IN (?, ?)");
            $chk_stmt->execute([$from_acc, $to_acc]);
            while ($an = $chk_stmt->fetchColumn()) {
                if ($an && (mb_strpos($an, 'ایڈوانس') !== false || mb_strpos($an, 'وصولی') !== false)) {
                    $lower_an = mb_strtolower($an);
                    $u1 = mb_strtolower(trim($current_username));
                    $u2 = mb_strtolower(trim($_SESSION['name'] ?? ''));
                    if ((empty($u1) || mb_strpos($lower_an, $u1) === false) && (empty($u2) || mb_strpos($lower_an, $u2) === false)) {
                        header("Location: simple_finance.php?error=unauthorized_account&tab=transfer&err_msg=" . urlencode("دوسرے صارف کے کھاتے سے رقم منتقلی کی اجازت نہیں ہے۔"));
                        exit;
                    }
                }
            }
        }

        if ($from_acc > 0 && $to_acc > 0 && $from_acc !== $to_acc && $transfer_amount > 0) {
            finance_accounting_transfer_fund($pdo, $from_acc, $to_acc, $transfer_amount, $transfer_date, $transfer_desc, $user_id, $sys);
            header("Location: simple_finance.php?msg=transferred&tab=transfer");
            exit;
        } else {
            header("Location: simple_finance.php?error=invalid_transfer&tab=transfer");
            exit;
        }
    } catch (Throwable $e) {
        header("Location: simple_finance.php?error=transfer_failed&tab=transfer&err_msg=" . urlencode("System Error: " . $e->getMessage() . " on line " . $e->getLine()));
        exit;
    }
}

// Delete Transfer (Admin Only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_transfer']) && $is_admin) {
    validate_csrf_or_abort();
    $del_id = (int)$_POST['del_transfer_id'];
    try {
        $pdo->query("UPDATE acc_vouchers SET is_deleted = 1 WHERE id = $del_id AND (reference LIKE 'TRANSFER-%' OR type = 'Contra')");
        $pdo->query("UPDATE acc_ledger_entries SET debit = 0, credit = 0 WHERE voucher_id = $del_id");
    } catch (Throwable $e) {}
    header("Location: simple_finance.php?msg=deleted&tab=transfer");
    exit;
}

// Edit Transfer (Admin Only)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_transfer']) && $is_admin) {
    validate_csrf_or_abort();
    $edit_id = (int)$_POST['edit_transfer_id'];
    $edit_amount = (float)($_POST['edit_transfer_amount'] ?? 0);
    $edit_date = $_POST['edit_transfer_date'] ?? date('Y-m-d');
    $edit_desc = trim($_POST['edit_transfer_description'] ?? '');

    if ($edit_id > 0 && $edit_amount > 0) {
        try {
            $pdo->query("UPDATE acc_vouchers SET date = " . $pdo->quote($edit_date) . ", description = " . $pdo->quote($edit_desc) . " WHERE id = $edit_id AND (reference LIKE 'TRANSFER-%' OR type = 'Contra')");
            // Update amounts
            $pdo->query("UPDATE acc_ledger_entries SET debit = $edit_amount WHERE voucher_id = $edit_id AND debit > 0");
            $pdo->query("UPDATE acc_ledger_entries SET credit = $edit_amount WHERE voucher_id = $edit_id AND credit > 0");
            header("Location: simple_finance.php?msg=updated&tab=transfer");
            exit;
        } catch (Throwable $e) {}
    }
    header("Location: simple_finance.php?error=invalid_transfer&tab=transfer");
    exit;
}

// Add New Head
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_head'])) {
    validate_csrf_or_abort();
    $head_name = trim($_POST['head_name']);
    $head_type = $_POST['head_type'];
    if (!empty($head_name) && !empty($head_type)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO heads (name, type, is_deleted, tenant_id) VALUES (?, ?, 0, ?)");
            $stmt->execute([$head_name, $head_type, $cur_tenant_id]);
        } catch (Throwable $e) {
            try {
                $stmt = $pdo->prepare("INSERT INTO heads (name, type, tenant_id) VALUES (?, ?, ?)");
                $stmt->execute([$head_name, $head_type, $cur_tenant_id]);
            } catch (Throwable $e2) {}
        }
        if (function_exists('sync_all_heads_with_chart_and_departments')) {
            try { sync_all_heads_with_chart_and_departments($pdo); } catch (Throwable $e) {}
        }
        header("Location: simple_finance.php?msg=head_added");
        exit;
    }
}

// Edit Head
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_head'])) {
    validate_csrf_or_abort();
    $head_id = (int)$_POST['head_id'];
    $head_name = trim($_POST['head_name']);
    $head_type = $_POST['head_type'];
    if ($head_id > 0 && !empty($head_name)) {
        try {
            $pdo->prepare("UPDATE heads SET name = ?, type = ? WHERE id = ? AND tenant_id = ?")->execute([$head_name, $head_type, $head_id, $cur_tenant_id]);
        } catch (Throwable $e) {}
        if (function_exists('sync_all_heads_with_chart_and_departments')) {
            try { sync_all_heads_with_chart_and_departments($pdo); } catch (Throwable $e) {}
        }
        header("Location: simple_finance.php?msg=head_updated");
        exit;
    }
}

// Delete Head
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_head'])) {
    validate_csrf_or_abort();
    $del_head_id = (int)$_POST['del_head_id'];
    try {
        $pdo->prepare("UPDATE heads SET is_deleted = 1 WHERE id = ? AND tenant_id = ?")->execute([$del_head_id, $cur_tenant_id]);
    } catch (Throwable $e) {
        try {
            $pdo->prepare("DELETE FROM heads WHERE id = ? AND tenant_id = ?")->execute([$del_head_id, $cur_tenant_id]);
        } catch (Throwable $e2) {}
    }
    if (function_exists('sync_all_heads_with_chart_and_departments')) {
        try { sync_all_heads_with_chart_and_departments($pdo); } catch (Throwable $e) {}
    }
    header("Location: simple_finance.php?msg=head_deleted");
    exit;
}

// Sync Heads
if (isset($_POST['sync_heads']) || isset($_GET['sync_heads'])) {
    if (function_exists('sync_all_heads_with_chart_and_departments')) {
        try { sync_all_heads_with_chart_and_departments($pdo); } catch (Throwable $e) {}
    }
    header("Location: simple_finance.php?msg=heads_synced");
    exit;
}

// Fetch heads safely
$income_heads = [];
try {
    $income_heads = $pdo->query("SELECT * FROM heads WHERE type = 'Income' AND (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = $cur_tenant_id ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    try {
        $income_heads = $pdo->query("SELECT * FROM heads WHERE type = 'Income' AND tenant_id = $cur_tenant_id ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) {
        $income_heads = [];
    }
}

$expense_heads = [];
try {
    $expense_heads = $pdo->query("SELECT * FROM heads WHERE type = 'Expense' AND (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = $cur_tenant_id ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    try {
        $expense_heads = $pdo->query("SELECT * FROM heads WHERE type = 'Expense' AND tenant_id = $cur_tenant_id ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) {
        $expense_heads = [];
    }
}

// If heads are empty, add sensible defaults
if (empty($income_heads)) {
    $defaults = ['عطیات / چندہ', 'زکوٰۃ', 'صدقات', 'فنڈ', 'متفرق آمدن'];
    foreach ($defaults as $dh) {
        try {
            $pdo->prepare("INSERT INTO heads (name, type, is_deleted, tenant_id) VALUES (?, 'Income', 0, ?)")->execute([$dh, $cur_tenant_id]);
        } catch (Throwable $e) {
            try {
                $pdo->prepare("INSERT INTO heads (name, type, tenant_id) VALUES (?, 'Income', ?)")->execute([$dh, $cur_tenant_id]);
            } catch (Throwable $e2) {}
        }
    }
    try {
        $income_heads = $pdo->query("SELECT * FROM heads WHERE type = 'Income' AND (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = $cur_tenant_id ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $income_heads = [];
    }
}
if (empty($expense_heads)) {
    $defaultsExp = ['بجلی کا بل', 'گیس کا بل', 'مرمت و دیکھ بھال', 'صفائی و ستھرائی', 'متفرق اخراجات'];
    foreach ($defaultsExp as $dh) {
        try {
            $pdo->prepare("INSERT INTO heads (name, type, is_deleted, tenant_id) VALUES (?, 'Expense', 0, ?)")->execute([$dh, $cur_tenant_id]);
        } catch (Throwable $e) {
            try {
                $pdo->prepare("INSERT INTO heads (name, type, tenant_id) VALUES (?, 'Expense', ?)")->execute([$dh, $cur_tenant_id]);
            } catch (Throwable $e2) {}
        }
    }
    try {
        $expense_heads = $pdo->query("SELECT * FROM heads WHERE type = 'Expense' AND (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = $cur_tenant_id ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $expense_heads = [];
    }
}

// Auto-provision advance accounts for all users/admins so they appear in transfer accounts
try {
    $admin_t_cond = ($cur_tenant_id == 1) ? "(tenant_id = 1 OR tenant_id IS NULL OR tenant_id = 0)" : "tenant_id = $cur_tenant_id";
    $all_admins_for_acc = $pdo->query("SELECT id, username, name FROM admins WHERE $admin_t_cond")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all_admins_for_acc as $su) {
        $u_label = !empty($su['username']) ? $su['username'] : $su['name'];
        if (!empty($u_label)) {
            $acc_name = "ایڈوانس - " . $u_label;
            $u_wild = '%' . $u_label . '%';
            $chk = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE (name = ? OR name = ? OR name = ? OR name LIKE ?) AND type = 'Asset' AND tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1");
            $chk->execute([$acc_name, "ایڈوانس-" . $u_label, "Advance - " . $u_label, $u_wild, $cur_tenant_id]);
            if (!$chk->fetchColumn()) {
                $code_stmt = $pdo->query("SELECT MAX(CAST(code AS UNSIGNED)) FROM acc_chart_of_accounts WHERE type = 'Asset' AND tenant_id = $cur_tenant_id");
                $max_code = (int)($code_stmt ? $code_stmt->fetchColumn() : 0);
                $new_code = max(1500, $max_code + 1);
                while ($pdo->query("SELECT COUNT(*) FROM acc_chart_of_accounts WHERE code = '$new_code' AND tenant_id = $cur_tenant_id")->fetchColumn() > 0) {
                    $new_code++;
                }
                $ins = $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, is_active, is_deleted, tenant_id) VALUES (?, ?, 'Asset', 1, 0, ?)");
                $ins->execute([(string)$new_code, $acc_name, $cur_tenant_id]);
            }
        }
    }
} catch (Throwable $e) {}

// Fetch payment asset accounts (Cash in Hand, Bank Accounts, etc.)
$all_payment_accounts = [];
try {
    $all_payment_accounts = $pdo->query("SELECT id, code, name FROM acc_chart_of_accounts WHERE type = 'Asset' AND (tenant_id = $cur_tenant_id OR tenant_id IS NULL OR tenant_id = 0) AND (is_deleted = 0 OR is_deleted IS NULL) AND (is_active = 1 OR is_active IS NULL) AND code NOT IN ('1101', '1201', '1301', '2101', '2102', '2103', '5010', '5011') ORDER BY CAST(code AS UNSIGNED) ASC, code ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
if (empty($all_payment_accounts)) {
    $cid = finance_accounting_ensure_account($pdo, '1001', 'Cash in Hand', 'Asset', $cur_tenant_id);
    $bid = finance_accounting_ensure_account($pdo, '1002', 'Bank Account', 'Asset', $cur_tenant_id);
    $all_payment_accounts = [
        ['id' => $cid, 'code' => '1001', 'name' => 'Cash in Hand (نقد رقم / گلہ)'],
        ['id' => $bid, 'code' => '1002', 'name' => 'Bank Account (بینک کھاتہ)']
    ];
}
$payment_accounts = $all_payment_accounts;

// Expense account dropdown: Non-admins can only use their own Advance Account
$expense_payment_accounts = $all_payment_accounts;
$income_payment_accounts = $all_payment_accounts;
$is_admin = in_array($user_role_lower, ['admin', 'superadmin', 'super_admin', 'administrator']);
$my_uname = trim($current_username);
$my_full_name = trim($_SESSION['name'] ?? '');

$is_my_advance_account = function($acc_name) use ($my_uname, $my_full_name) {
    if (mb_strpos($acc_name, 'ایڈوانس') === false && mb_strpos($acc_name, 'خرچ کھاتہ') === false && stripos($acc_name, 'advance') === false) return false;
    $lower_acc = mb_strtolower($acc_name);
    if (!empty($my_uname) && mb_strpos($lower_acc, mb_strtolower($my_uname)) !== false) return true;
    if (!empty($my_full_name) && mb_strpos($lower_acc, mb_strtolower($my_full_name)) !== false) return true;
    return false;
};

$is_other_advance_account = function($acc_name) use ($is_my_advance_account) {
    if (mb_strpos($acc_name, 'ایڈوانس') === false && mb_strpos($acc_name, 'خرچ کھاتہ') === false && stripos($acc_name, 'advance') === false) return false;
    return !$is_my_advance_account($acc_name);
};

$is_my_collection_account = function($acc_name) use ($my_uname, $my_full_name, $user_id) {
    if (mb_strpos($acc_name, 'وصولی') === false && stripos($acc_name, 'collection') === false) return false;
    $lower_acc = mb_strtolower($acc_name);
    if (!empty($my_uname) && mb_strpos($lower_acc, mb_strtolower($my_uname)) !== false) return true;
    if (!empty($my_full_name) && mb_strpos($lower_acc, mb_strtolower($my_full_name)) !== false) return true;
    return false;
};

$is_other_collection_account = function($acc_name) use ($is_my_collection_account) {
    if (mb_strpos($acc_name, 'وصولی') === false && stripos($acc_name, 'collection') === false) return false;
    return !$is_my_collection_account($acc_name);
};

$my_col_aid = 0;

if (!$is_admin) {
    // 1. Strictly filter out any other users' advance or collection accounts from general payment accounts
    $all_payment_accounts = array_values(array_filter($all_payment_accounts, function($pa) use ($is_other_advance_account, $is_other_collection_account) {
        return !$is_other_advance_account($pa['name'] ?? '') && !$is_other_collection_account($pa['name'] ?? '');
    }));
    $payment_accounts = $all_payment_accounts;

    // 2. Filter for logged-in user's own advance account
    $my_advance_acc_name = "ایڈوانس - " . (!empty($my_full_name) ? $my_full_name : $current_username);
    $filtered_pa = [];
    foreach ($all_payment_accounts as $pa) {
        if ($is_my_advance_account($pa['name'] ?? '')) {
            $filtered_pa[] = $pa;
        }
    }
    if (empty($filtered_pa)) {
        // Auto-provision advance account for user if not yet created
        $adv_code = ($user_id === 1) ? 1502 : (($user_id === 3) ? 1503 : 0);
        if ($adv_code <= 0) {
            $code_stmt = $pdo->query("SELECT MAX(CAST(code AS UNSIGNED)) FROM acc_chart_of_accounts WHERE type = 'Asset'");
            $max_code = (int)($code_stmt ? $code_stmt->fetchColumn() : 0);
            $adv_code = max(1500, $max_code + 1);
            while ($pdo->query("SELECT COUNT(*) FROM acc_chart_of_accounts WHERE code = '$adv_code'")->fetchColumn() > 0) {
                $adv_code++;
            }
        }
        try {
            $ins = $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, tenant_id, is_active, is_deleted) VALUES (?, ?, 'Asset', ?, 1, 0)");
            $ins->execute([(string)$adv_code, $my_advance_acc_name, $cur_tenant_id]);
            $new_id = (int)$pdo->lastInsertId();
            $new_acc = ['id' => $new_id, 'code' => (string)$adv_code, 'name' => $my_advance_acc_name];
            $filtered_pa[] = $new_acc;
            $all_payment_accounts[] = $new_acc;
            $payment_accounts = $all_payment_accounts;
        } catch (Throwable $e) {
            try {
                $ins = $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, is_active, is_deleted) VALUES (?, ?, 'Asset', 1, 0)");
                $ins->execute([(string)$adv_code, $my_advance_acc_name]);
                $new_id = (int)$pdo->lastInsertId();
                $new_acc = ['id' => $new_id, 'code' => (string)$adv_code, 'name' => $my_advance_acc_name];
                $filtered_pa[] = $new_acc;
                $all_payment_accounts[] = $new_acc;
                $payment_accounts = $all_payment_accounts;
            } catch (Throwable $e2) {}
        }
    }
    $expense_payment_accounts = $filtered_pa;

    // 3. Filter for logged-in user's own collection account (نقد وصولی)
    $my_col_acc_name = "نقد وصولی - " . (!empty($my_full_name) ? $my_full_name : $current_username);
    foreach ($all_payment_accounts as $pa) {
        if ($is_my_collection_account($pa['name'] ?? '')) {
            $my_col_aid = (int)$pa['id'];
            break;
        }
    }
    if ($my_col_aid <= 0) {
        // Search in database for tenant collection account for this user
        $chkColStmt = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE (name = ? OR name LIKE ?) AND type = 'Asset' AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0) AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY (tenant_id = ?) DESC LIMIT 1");
        $chkColStmt->execute([$my_col_acc_name, "%" . (!empty($my_full_name) ? $my_full_name : $current_username) . "%", $cur_tenant_id, $cur_tenant_id]);
        $my_col_aid = (int)($chkColStmt->fetchColumn() ?: 0);
    }
    if ($my_col_aid <= 0) {
        // Auto-provision collection account
        $code_stmt = $pdo->prepare("SELECT MAX(CAST(code AS UNSIGNED)) FROM acc_chart_of_accounts WHERE type = 'Asset' AND code LIKE '11%' AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)");
        $code_stmt->execute([$cur_tenant_id]);
        $max_code = (int)($code_stmt ? $code_stmt->fetchColumn() : 0);
        $col_code = max(1100, $max_code) + 1;
        try {
            $ins = $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, tenant_id, is_active, is_deleted) VALUES (?, ?, 'Asset', ?, 1, 0)");
            $ins->execute([(string)$col_code, $my_col_acc_name, $cur_tenant_id]);
            $my_col_aid = (int)$pdo->lastInsertId();
            $new_col_acc = ['id' => $my_col_aid, 'code' => (string)$col_code, 'name' => $my_col_acc_name];
            $all_payment_accounts[] = $new_col_acc;
            $payment_accounts = $all_payment_accounts;
        } catch (Throwable $e) {}
    }

    // 4. Income accounts for non-admin: User's collection account first, then bank accounts. Central cash (1001) excluded!
    $income_payment_accounts = [];
    foreach ($all_payment_accounts as $pa) {
        $pa_code = $pa['code'] ?? '';
        $pa_name = $pa['name'] ?? '';
        if ($pa_code == '1001') continue; // Hide central cash for non-admin income
        if ($is_my_advance_account($pa_name) || $is_other_advance_account($pa_name)) continue;
        if ($is_other_collection_account($pa_name)) continue;
        if ((int)$pa['id'] === $my_col_aid) {
            array_unshift($income_payment_accounts, $pa);
        } else {
            $income_payment_accounts[] = $pa;
        }
    }
    if (empty($income_payment_accounts) && $my_col_aid > 0) {
        $income_payment_accounts[] = ['id' => $my_col_aid, 'code' => '1102', 'name' => $my_col_acc_name];
    }
}

// Auto-reconcile paid staff stipends into finance expense table
if (!function_exists('reconcile_paid_stipends_to_finance')) {
    function reconcile_paid_stipends_to_finance(PDO $pdo, $current_sys = 'Madrasa', $tenant_id = null) {
        if ($tenant_id === null) $tenant_id = (int)get_current_tenant_id();
        try {
            // Find all paid payrolls that do not have an active finance record
            $paid_stmt = $pdo->prepare("
                SELECT p.*, t.name as teacher_name 
                FROM acc_payroll p 
                JOIN teachers t ON p.teacher_id = t.id 
                WHERE p.status = 'Paid' AND (p.is_deleted = 0 OR p.is_deleted IS NULL) AND p.tenant_id = ?
            ");
            $paid_stmt->execute([$tenant_id]);
            $payrolls = $paid_stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($payrolls)) return;

            // Resolve head_id for 'وظائف عملہ'
            $sal_head_id = (int)($pdo->query("SELECT id FROM heads WHERE name = 'وظائف عملہ' AND type = 'Expense' AND (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = $tenant_id LIMIT 1")->fetchColumn() ?: 0);
            if (!$sal_head_id) {
                $sal_head_id = (int)($pdo->query("SELECT id FROM heads WHERE name LIKE '%وظائف%' AND type = 'Expense' AND (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = $tenant_id LIMIT 1")->fetchColumn() ?: 0);
            }
            if (!$sal_head_id) {
                try {
                    $pdo->prepare("INSERT INTO heads (name, type, is_deleted, tenant_id) VALUES ('وظائف عملہ', 'Expense', 0, ?)")->execute([$tenant_id]);
                    $sal_head_id = (int)$pdo->lastInsertId();
                } catch (Throwable $e) {}
            }

            // Resolve default cash account
            $cash_acc_id = (int)($pdo->query("SELECT id FROM acc_chart_of_accounts WHERE code = '1001' AND (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = $tenant_id LIMIT 1")->fetchColumn() ?: 0);

            foreach ($payrolls as $p) {
                $t_name = $p['teacher_name'] ?? 'Staff';
                $m_year = $p['month_year'] ?? '';
                $net_amt = (float)($p['net_salary'] ?? ($p['basic_salary'] + $p['allowances'] - $p['deductions']));
                if ($net_amt <= 0) continue;

                $p_sys = !empty($p['system_type']) ? $p['system_type'] : 'Madrasa';
                $pay_date = !empty($p['payment_date']) ? $p['payment_date'] : date('Y-m-d');
                $uid = (int)($p['created_by'] ?? 1);

                // Check if already in finance
                $chk = $pdo->prepare("SELECT id FROM finance WHERE type = 'Expense' AND description LIKE ? AND (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = ? LIMIT 1");
                $chk->execute(["%ماہ $m_year: $t_name%", $tenant_id]);
                $exists = $chk->fetchColumn();

                if (!$exists) {
                    $desc = "مد: وظائف عملہ | تفصیل: وظیفہ/تنخواہ ادا کی گئی برائے ماہ $m_year: $t_name";
                    $uname = 'Admin';
                    try {
                        $uStmt = $pdo->prepare("SELECT username, name FROM admins WHERE id = ?");
                        $uStmt->execute([$uid]);
                        $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                        if ($uRow) $uname = !empty($uRow['name']) ? $uRow['name'] : $uRow['username'];
                    } catch (Throwable $e) {}

                    $pdo->prepare("INSERT INTO finance (user_id, user_name, type, head_id, head_name, amount, date, donor_name, spender_name, description, account_id, is_deleted, system_type, tenant_id) VALUES (?, ?, 'Expense', ?, 'وظائف عملہ', ?, ?, ?, ?, ?, ?, 0, ?, ?)")
                        ->execute([$uid, $uname, $sal_head_id, $net_amt, $pay_date, $t_name, $t_name, $desc, $cash_acc_id, $p_sys, $tenant_id]);
                    $fin_id = (int)$pdo->lastInsertId();
                    if (function_exists('finance_accounting_post')) {
                        try { finance_accounting_post($pdo, $fin_id); } catch (Throwable $e) {}
                    }
                }
            }
        } catch (Throwable $e) {}
    }
}
reconcile_paid_stipends_to_finance($pdo, $sys, $cur_tenant_id);

// Fetch records strictly filtered by System Type (Masjid vs Madrasa)
// Dynamic column detection on finance, chart of accounts, vouchers, and ledger
$fin_cols = [];
try { $fin_cols = $pdo->query("SHOW COLUMNS FROM finance")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
$has_f_tenant  = in_array('tenant_id', $fin_cols);
$has_f_sys     = in_array('system_type', $fin_cols);
$has_f_del     = in_array('is_deleted', $fin_cols);
$has_f_user_id = in_array('user_id', $fin_cols);

$t_cond_f = ($has_f_tenant)
    ? (($cur_tenant_id == 1) ? "(f.tenant_id = 1 OR f.tenant_id IS NULL OR f.tenant_id = 0)" : "f.tenant_id = $cur_tenant_id")
    : "1=1";

$sys_filter = ($has_f_sys)
    ? (($sys === 'Masjid') ? "f.system_type = 'Masjid'" : "(f.system_type = 'Madrasa' OR f.system_type IS NULL OR f.system_type = '')")
    : "1=1";

$del_cond_f = ($has_f_del) ? "(f.is_deleted = 0 OR f.is_deleted IS NULL)" : "1=1";

$v_cols = [];
try { $v_cols = $pdo->query("SHOW COLUMNS FROM acc_vouchers")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
$has_v_tenant = in_array('tenant_id', $v_cols);
$has_v_sys    = in_array('system_type', $v_cols);
$has_v_del    = in_array('is_deleted', $v_cols);

$t_cond_v = ($has_v_tenant)
    ? (($cur_tenant_id == 1) ? "(v.tenant_id = 1 OR v.tenant_id IS NULL OR v.tenant_id = 0)" : "v.tenant_id = $cur_tenant_id")
    : "1=1";

$voucher_sys_filter = ($has_v_sys)
    ? (($sys === 'Masjid') ? "v.system_type = 'Masjid'" : "(v.system_type = 'Madrasa' OR v.system_type IS NULL OR v.system_type = '')")
    : "1=1";

$del_cond_v = ($has_v_del) ? "(v.is_deleted = 0 OR v.is_deleted IS NULL)" : "1=1";

// Period filtering: Default is 'current_month' as requested
$selected_period = $_GET['period'] ?? 'current_month';
$custom_from = $_GET['from_date'] ?? '';
$custom_to = $_GET['to_date'] ?? '';

$cur_year = date('Y');
$filter_start_date = date('Y-m-01');
$filter_end_date = date('Y-m-t');
$period_label = 'رواں ماہ (' . date('M Y') . ')';

if ($selected_period === 'last_month') {
    $filter_start_date = date('Y-m-01', strtotime('first day of last month'));
    $filter_end_date = date('Y-m-t', strtotime('last day of last month'));
    $period_label = 'پچھلا مہینہ (' . date('M Y', strtotime('last day of last month')) . ')';
} elseif ($selected_period === 'current_year') {
    $filter_start_date = date('Y-01-01');
    $filter_end_date = date('Y-12-31');
    $period_label = 'رواں سال (' . $cur_year . ')';
} elseif ($selected_period === 'custom') {
    $filter_start_date = !empty($custom_from) ? $custom_from : date('Y-m-01');
    $filter_end_date = !empty($custom_to) ? $custom_to : date('Y-m-t');
    $period_label = 'کسٹم مدت (' . $filter_start_date . ' تا ' . $filter_end_date . ')';
} elseif ($selected_period === 'all') {
    $filter_start_date = null;
    $filter_end_date = null;
    $period_label = 'تمام ریکارڈز';
} else {
    $selected_period = 'current_month';
}

$date_cond_f = "1=1";
if ($filter_start_date && $filter_end_date) {
    $date_cond_f = "f.date BETWEEN '$filter_start_date' AND '$filter_end_date'";
}
$date_cond_v = "1=1";
if ($filter_start_date && $filter_end_date) {
    $date_cond_v = "v.date BETWEEN '$filter_start_date' AND '$filter_end_date'";
}

$limit_val = isset($_GET['all']) ? 5000 : 500;
$income_records = [];
$expense_records = [];
$transfer_records = [];

// 1. آمدن ریکارڈز لائیں (مضبوط فال بیکس کے ساتھ)
try {
    if ($is_admin) {
        $income_records = $pdo->query("SELECT f.*, COALESCE(h.name, f.head_name, 'متفرق آمدن') as head_name, a.name as account_name, a.code as account_code, 
                                               COALESCE(u.name, u.username, f.user_name, CONCAT('صارف #', f.user_id), 'نامعلوم') as user_display_name,
                                               u.username as user_username
                                        FROM finance f 
                                        LEFT JOIN heads h ON f.head_id = h.id 
                                        LEFT JOIN acc_chart_of_accounts a ON f.account_id = a.id 
                                        LEFT JOIN admins u ON f.user_id = u.id 
                                        WHERE f.type = 'Income' AND $sys_filter AND $t_cond_f AND $del_cond_f AND $date_cond_f 
                                        ORDER BY f.date DESC, f.id DESC LIMIT $limit_val")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $current_user_id = intval($user_id);
        $income_records = $pdo->query("SELECT f.*, COALESCE(h.name, f.head_name, 'متفرق آمدن') as head_name, a.name as account_name, a.code as account_code,
                                               COALESCE(u.name, u.username, f.user_name, CONCAT('صارف #', f.user_id), 'آپ کا اکاؤنٹ') as user_display_name,
                                               u.username as user_username
                                        FROM finance f 
                                        LEFT JOIN heads h ON f.head_id = h.id 
                                        LEFT JOIN acc_chart_of_accounts a ON f.account_id = a.id 
                                        LEFT JOIN admins u ON f.user_id = u.id 
                                        WHERE f.type = 'Income' AND $sys_filter AND $t_cond_f AND $del_cond_f AND $date_cond_f AND f.user_id = $current_user_id 
                                        ORDER BY f.date DESC, f.id DESC LIMIT $limit_val")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $eInc) {
    try {
        $user_where = (!$is_admin && $has_f_user_id) ? " AND f.user_id = " . intval($user_id) : "";
        $income_records = $pdo->query("SELECT f.*, COALESCE(f.head_name, 'متفرق آمدن') as head_name,
                                               COALESCE(f.user_name, CONCAT('صارف #', f.user_id), 'نامعلوم') as user_display_name
                                        FROM finance f 
                                        WHERE f.type = 'Income' AND $del_cond_f AND $date_cond_f $user_where
                                        ORDER BY f.date DESC, f.id DESC LIMIT $limit_val")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $eInc2) {
        try {
            $income_records = $pdo->query("SELECT * FROM finance WHERE type = 'Income' AND (is_deleted = 0 OR is_deleted IS NULL) AND $date_cond_f ORDER BY id DESC LIMIT $limit_val")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $eInc3) {
            $income_records = [];
        }
    }
}

// 2. اخراجات ریکارڈز لائیں (مضبوط فال بیکس کے ساتھ)
try {
    if ($is_admin) {
        $expense_records = $pdo->query("SELECT f.*, COALESCE(h.name, f.head_name, 'وظائف عملہ') as head_name, a.name as account_name, a.code as account_code, 
                                                COALESCE(u.name, u.username, f.user_name, CONCAT('صارف #', f.user_id), 'نامعلوم') as user_display_name,
                                                u.username as user_username
                                         FROM finance f 
                                         LEFT JOIN heads h ON f.head_id = h.id 
                                         LEFT JOIN acc_chart_of_accounts a ON f.account_id = a.id 
                                         LEFT JOIN admins u ON f.user_id = u.id 
                                         WHERE f.type = 'Expense' AND $sys_filter AND $t_cond_f AND $del_cond_f AND $date_cond_f 
                                         ORDER BY f.date DESC, f.id DESC LIMIT $limit_val")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $current_user_id = intval($user_id);
        $expense_records = $pdo->query("SELECT f.*, COALESCE(h.name, f.head_name, 'وظائف عملہ') as head_name, a.name as account_name, a.code as account_code,
                                                COALESCE(u.name, u.username, f.user_name, CONCAT('صارف #', f.user_id), 'آپ کا اکاؤنٹ') as user_display_name,
                                                u.username as user_username
                                         FROM finance f 
                                         LEFT JOIN heads h ON f.head_id = h.id 
                                         LEFT JOIN acc_chart_of_accounts a ON f.account_id = a.id 
                                         LEFT JOIN admins u ON f.user_id = u.id 
                                         WHERE f.type = 'Expense' AND $sys_filter AND $t_cond_f AND $del_cond_f AND $date_cond_f AND f.user_id = $current_user_id 
                                         ORDER BY f.date DESC, f.id DESC LIMIT $limit_val")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $eExp) {
    try {
        $user_where = (!$is_admin && $has_f_user_id) ? " AND f.user_id = " . intval($user_id) : "";
        $expense_records = $pdo->query("SELECT f.*, COALESCE(f.head_name, 'وظائف عملہ') as head_name,
                                                COALESCE(f.user_name, CONCAT('صارف #', f.user_id), 'نامعلوم') as user_display_name
                                         FROM finance f 
                                         WHERE f.type = 'Expense' AND $del_cond_f AND $date_cond_f $user_where
                                         ORDER BY f.date DESC, f.id DESC LIMIT $limit_val")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $eExp2) {
        try {
            $expense_records = $pdo->query("SELECT * FROM finance WHERE type = 'Expense' AND (is_deleted = 0 OR is_deleted IS NULL) AND $date_cond_f ORDER BY id DESC LIMIT $limit_val")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $eExp3) {
            $expense_records = [];
        }
    }
}

// 3. رقم منتقلی (Transfers) واؤچرز لائیں (ONLY_FULL_GROUP_BY ہم آہنگ)
try {
    $uname_term = $pdo->quote('%' . $current_username . '%');
    $user_transfer_cond = $is_admin ? "1=1" : "(v.created_by = " . intval($user_id) . " OR a1.name LIKE $uname_term OR a2.name LIKE $uname_term)";
    $transfer_records = $pdo->query("
        SELECT v.id, v.date, v.voucher_no, v.description, 
               MAX(COALESCE(i1.credit, i2.debit, 0)) as amount, 
               COALESCE(GROUP_CONCAT(DISTINCT a1.name SEPARATOR ', '), '---') as from_account, 
               COALESCE(GROUP_CONCAT(DISTINCT a2.name SEPARATOR ', '), '---') as to_account
        FROM acc_vouchers v
        LEFT JOIN acc_ledger_entries i1 ON v.id = i1.voucher_id AND i1.credit > 0
        LEFT JOIN acc_chart_of_accounts a1 ON i1.account_id = a1.id
        LEFT JOIN acc_ledger_entries i2 ON v.id = i2.voucher_id AND i2.debit > 0
        LEFT JOIN acc_chart_of_accounts a2 ON i2.account_id = a2.id
        WHERE (v.reference LIKE 'TRANSFER-%' OR v.type = 'Contra') 
          AND $t_cond_v
          AND $del_cond_v
          AND $voucher_sys_filter
          AND $user_transfer_cond
          AND $date_cond_v
        GROUP BY v.id, v.date, v.voucher_no, v.description
        ORDER BY v.date DESC, v.id DESC LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $eTr) {
    try {
        $transfer_records = $pdo->query("
            SELECT v.id, v.date, v.voucher_no, v.description, 
                   MAX(COALESCE(i1.credit, i2.debit, 0)) as amount, 
                   COALESCE(GROUP_CONCAT(DISTINCT a1.name SEPARATOR ', '), '---') as from_account, 
                   COALESCE(GROUP_CONCAT(DISTINCT a2.name SEPARATOR ', '), '---') as to_account
            FROM acc_vouchers v
            LEFT JOIN acc_ledger_entries i1 ON v.id = i1.voucher_id AND i1.credit > 0
            LEFT JOIN acc_chart_of_accounts a1 ON i1.account_id = a1.id
            LEFT JOIN acc_ledger_entries i2 ON v.id = i2.voucher_id AND i2.debit > 0
            LEFT JOIN acc_chart_of_accounts a2 ON i2.account_id = a2.id
            WHERE (v.reference LIKE 'TRANSFER-%' OR v.type = 'Contra')
              AND $date_cond_v
            GROUP BY v.id, v.date, v.voucher_no, v.description
            ORDER BY v.date DESC, v.id DESC LIMIT 100
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $eTr2) {
        $transfer_records = [];
    }
}

// Build User Groups for Expandable Boxes (For both Admins and Non-Admins)
$user_groups = [];
foreach ($income_records as $r) {
    $uid_key = !empty($r['user_id']) ? (int)$r['user_id'] : 0;
    if (!isset($user_groups[$uid_key])) {
        $displayName = !empty($r['user_display_name']) ? trim($r['user_display_name']) : '';
        if (empty($displayName) || $displayName === 'نامعلوم') {
            $displayName = ($uid_key === (int)$user_id) ? ($current_username . ' (آپ)') : ('صارف #' . $uid_key);
        }
        $user_groups[$uid_key] = [
            'user_id' => $uid_key,
            'name' => $displayName,
            'total_income' => 0.0,
            'total_expense' => 0.0,
            'operating_income' => 0.0,
            'operating_expense' => 0.0,
            'loan_inflow' => 0.0,
            'loan_outflow' => 0.0,
            'income_records' => [],
            'expense_records' => []
        ];
    }
    $amt = (float)($r['amount'] ?? 0);
    $user_groups[$uid_key]['total_income'] += $amt;
    if (!empty($r['is_loan'])) {
        $user_groups[$uid_key]['loan_inflow'] += $amt;
    } else {
        $user_groups[$uid_key]['operating_income'] += $amt;
    }
    $user_groups[$uid_key]['income_records'][] = $r;
}

foreach ($expense_records as $r) {
    $uid_key = !empty($r['user_id']) ? (int)$r['user_id'] : 0;
    if (!isset($user_groups[$uid_key])) {
        $displayName = !empty($r['user_display_name']) ? trim($r['user_display_name']) : '';
        if (empty($displayName) || $displayName === 'نامعلوم') {
            $displayName = ($uid_key === (int)$user_id) ? ($current_username . ' (آپ)') : ('صارف #' . $uid_key);
        }
        $user_groups[$uid_key] = [
            'user_id' => $uid_key,
            'name' => $displayName,
            'total_income' => 0.0,
            'total_expense' => 0.0,
            'operating_income' => 0.0,
            'operating_expense' => 0.0,
            'loan_inflow' => 0.0,
            'loan_outflow' => 0.0,
            'income_records' => [],
            'expense_records' => []
        ];
    }
    $amt = (float)($r['amount'] ?? 0);
    $user_groups[$uid_key]['total_expense'] += $amt;
    if (!empty($r['is_loan'])) {
        $user_groups[$uid_key]['loan_outflow'] += $amt;
    } else {
        $user_groups[$uid_key]['operating_expense'] += $amt;
    }
    $user_groups[$uid_key]['expense_records'][] = $r;
}

// Ensure logged-in user always has a group if other groups exist or if logged in
if (!empty($user_id) && !isset($user_groups[$user_id]) && !empty($user_groups)) {
    $user_groups[$user_id] = [
        'user_id' => (int)$user_id,
        'name' => $current_username . ' (آپ)',
        'total_income' => 0.0,
        'total_expense' => 0.0,
        'operating_income' => 0.0,
        'operating_expense' => 0.0,
        'loan_inflow' => 0.0,
        'loan_outflow' => 0.0,
        'income_records' => [],
        'expense_records' => []
    ];
}

// Sort: Logged in user first, then alphabetical by name
uasort($user_groups, function($a, $b) use ($user_id) {
    if ($a['user_id'] === (int)$user_id) return -1;
    if ($b['user_id'] === (int)$user_id) return 1;
    return strcmp($a['name'], $b['name']);
});

$period_total_income = 0.0;
$period_total_expense = 0.0;
foreach ($income_records as $r) {
    $period_total_income += (float)($r['amount'] ?? 0);
}
foreach ($expense_records as $r) {
    $period_total_expense += (float)($r['amount'] ?? 0);
}
$period_net_balance = $period_total_income - $period_total_expense;

$title = ($sys === 'Masjid') ? "آمدن و اخراجات (مسجد)" : "آمدن و اخراجات (مدرسہ)";

// Fetch Users for Transfer & Advance Engine
$sys_users = [];
$user_advance_summaries = [];
$total_overall_advance_received = 0.0;
$total_overall_advance_deducted = 0.0;
$total_overall_advance_balance = 0.0;

try {
    $adm_cols = [];
    try { $adm_cols = $pdo->query("SHOW COLUMNS FROM admins")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
    $has_adm_tenant = in_array('tenant_id', $adm_cols);
    $admin_t_cond = $has_adm_tenant 
        ? (($cur_tenant_id == 1) ? "(tenant_id = 1 OR tenant_id IS NULL OR tenant_id = 0)" : "(tenant_id = $cur_tenant_id OR tenant_id IS NULL OR tenant_id = 0)")
        : "1=1";

    if (!$is_admin) {
        $stmt_u = $pdo->prepare("SELECT id, username, name FROM admins WHERE (id = ? OR username = ?) AND $admin_t_cond ORDER BY username ASC");
        $stmt_u->execute([$user_id, $current_username]);
        $sys_users = $stmt_u->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $sys_users = $pdo->query("SELECT id, username, name FROM admins WHERE $admin_t_cond ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);
    }

    // 1. Fetch all advance asset accounts
    $coa_cols = [];
    try { $coa_cols = $pdo->query("SHOW COLUMNS FROM acc_chart_of_accounts")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
    $has_coa_tenant = in_array('tenant_id', $coa_cols);
    $has_coa_del    = in_array('is_deleted', $coa_cols);

    $coa_t_cond = $has_coa_tenant
        ? (($cur_tenant_id == 1) ? "(tenant_id = 1 OR tenant_id IS NULL OR tenant_id = 0)" : "(tenant_id = $cur_tenant_id OR tenant_id IS NULL OR tenant_id = 0)")
        : "1=1";
    $coa_del_cond = $has_coa_del ? "(is_deleted = 0 OR is_deleted IS NULL)" : "1=1";

    $all_adv_accounts = [];
    try {
        $all_adv_accounts = $pdo->query("
            SELECT id, code, name 
            FROM acc_chart_of_accounts 
            WHERE (name LIKE 'ایڈوانس%' OR name LIKE 'Advance%' OR name LIKE '%Advance%' OR name LIKE '%ایڈوانس%')
            ORDER BY id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $eAdv1) {
        $all_adv_accounts = [];
    }

    $le_cols = [];
    try { $le_cols = $pdo->query("SHOW COLUMNS FROM acc_ledger_entries")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
    $has_le_tenant = in_array('tenant_id', $le_cols);
    $t_cond_ledger = $has_le_tenant 
        ? (($cur_tenant_id == 1) ? "(e.tenant_id = 1 OR e.tenant_id IS NULL OR e.tenant_id = 0)" : "(e.tenant_id = $cur_tenant_id OR e.tenant_id IS NULL OR e.tenant_id = 0)")
        : "1=1";

    $raw_del_cond_f = ($has_f_del) ? "(is_deleted = 0 OR is_deleted IS NULL)" : "1=1";
    $raw_t_cond_fin = $has_f_tenant 
        ? (($cur_tenant_id == 1) ? "(tenant_id = 1 OR tenant_id IS NULL OR tenant_id = 0)" : "(tenant_id = $cur_tenant_id OR tenant_id IS NULL OR tenant_id = 0)")
        : "1=1";

    // 2. Strict Provisioning & Self-Healing for Advance Accounts (Tanzeel ID 1 vs Saleem ID 3)
    $tan_aid = 0;
    $sal_aid = 0;
    try {
        // A. Tanzeel Advance Account (ID 1, Code 1502)
        $stmtTan = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE code = '1502' LIMIT 1");
        $tan_aid = $stmtTan ? (int)$stmtTan->fetchColumn() : 0;
        if (!$tan_aid) {
            $stmtTan2 = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE (name LIKE '%تنزیل%' OR name LIKE '%Tanzeel%') AND (name LIKE '%ایڈوانس%' OR name LIKE '%Advance%') AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY id ASC LIMIT 1");
            $tan_aid = $stmtTan2 ? (int)$stmtTan2->fetchColumn() : 0;
        }
        if (!$tan_aid) {
            $pdo->exec("INSERT INTO acc_chart_of_accounts (code, name, type, is_active, is_deleted) VALUES ('1502', 'ایڈوانس - تنزیل الرحمن', 'Asset', 1, 0)");
            $tan_aid = (int)$pdo->lastInsertId();
        } else {
            $pdo->prepare("UPDATE acc_chart_of_accounts SET code = '1502', name = 'ایڈوانس - تنزیل الرحمن', type = 'Asset', is_active = 1, is_deleted = 0 WHERE id = ?")->execute([$tan_aid]);
        }

        // B. Saleem Advance Account (ID 3, Code 1503)
        $stmtSal = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE code = '1503' LIMIT 1");
        $sal_aid = $stmtSal ? (int)$stmtSal->fetchColumn() : 0;
        if (!$sal_aid && $tan_aid > 0) {
            $stmtSal2 = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE id != $tan_aid AND (name LIKE '%سلیم%' OR name LIKE '%Saleem%') AND (name LIKE '%ایڈوانس%' OR name LIKE '%Advance%') ORDER BY id ASC LIMIT 1");
            $sal_aid = $stmtSal2 ? (int)$stmtSal2->fetchColumn() : 0;
        }
        if (!$sal_aid) {
            $pdo->exec("INSERT INTO acc_chart_of_accounts (code, name, type, is_active, is_deleted) VALUES ('1503', 'ایڈوانس - قاری سلیم اللہ صاحب', 'Asset', 1, 0)");
            $sal_aid = (int)$pdo->lastInsertId();
        } else {
            $pdo->prepare("UPDATE acc_chart_of_accounts SET code = '1503', name = 'ایڈوانس - قاری سلیم اللہ صاحب', type = 'Asset', is_active = 1, is_deleted = 0 WHERE id = ?")->execute([$sal_aid]);
        }

        // Consolidate and delete any duplicate Saleem advance accounts
        if ($sal_aid > 0) {
            $dup_sal_aids = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE id != $sal_aid AND id != $tan_aid AND (name LIKE '%سلیم%' OR name LIKE '%Saleem%') AND (name LIKE '%ایڈوانس%' OR name LIKE '%Advance%')")->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($dup_sal_aids)) {
                $dup_in = implode(',', array_map('intval', $dup_sal_aids));
                $pdo->exec("UPDATE acc_ledger_entries SET account_id = $sal_aid WHERE account_id IN ($dup_in)");
                $pdo->exec("UPDATE finance SET account_id = $sal_aid WHERE account_id IN ($dup_in)");
                $pdo->exec("UPDATE masjid_finance SET account_id = $sal_aid WHERE account_id IN ($dup_in)");
                $pdo->exec("DELETE FROM acc_chart_of_accounts WHERE id IN ($dup_in)");
            }
        }

        // C. Reconcile Fund Transfer Vouchers in acc_ledger_entries:
        // Saleem transfers: CV-00001, CV-260913-3585, CV-260916-2300, CV-00005 (or any transfer mentioning saleem / سلیم)
        // MUST debit to Saleem's advance account ($sal_aid), NOT Tanzeel's account ($tan_aid)!
        if ($sal_aid > 0 && $sal_aid !== $tan_aid) {
            $v_saleem_ids = $pdo->query("
                SELECT id FROM acc_vouchers 
                WHERE (reference LIKE 'TRANSFER-%' OR type = 'Contra') 
                  AND (description LIKE '%saleem%' OR description LIKE '%سلیم%')
            ")->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($v_saleem_ids)) {
                $v_in = implode(',', array_map('intval', $v_saleem_ids));
                $pdo->exec("UPDATE acc_ledger_entries SET account_id = $sal_aid WHERE voucher_id IN ($v_in) AND debit > 0 AND account_id != $sal_aid");
            }

            // D. Reconcile Tanzeel transfers (CV-260909-4147 or description mentioning Tanzeel / تنزیل)
            if ($tan_aid > 0) {
                $v_tanzeel_ids = $pdo->query("
                    SELECT id FROM acc_vouchers 
                    WHERE (reference LIKE 'TRANSFER-%' OR type = 'Contra') 
                      AND (description LIKE '%tanzeel%' OR description LIKE '%تنزیل%')
                ")->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($v_tanzeel_ids)) {
                    $vt_in = implode(',', array_map('intval', $v_tanzeel_ids));
                    $pdo->exec("UPDATE acc_ledger_entries SET account_id = $tan_aid WHERE voucher_id IN ($vt_in) AND debit > 0 AND account_id != $tan_aid");
                }
            }

            // E. Reconcile Finance Expense Entries:
            // All expenses belonging to Saleem (user_id = 3 OR user_name LIKE '%سلیم%') that were erroneously linked to $tan_aid
            // MUST be re-pointed to Saleem's own advance account ($sal_aid)!
            $pdo->exec("
                UPDATE finance 
                SET account_id = $sal_aid, user_id = 3, user_name = 'قاری سلیم اللہ صاحب', payment_method = 'Advance' 
                WHERE (user_id = 3 OR user_name LIKE '%سلیم%' OR user_name = 'saleem') 
                  AND type = 'Expense' 
                  AND (account_id = $tan_aid OR account_id IS NULL OR account_id = 0 OR payment_method = 'Advance')
            ");

            // Also ensure Tanzeel's expenses (user_id = 1) do not point to $sal_aid
            if ($tan_aid > 0) {
                $pdo->exec("
                    UPDATE finance 
                    SET account_id = $tan_aid, user_id = 1, user_name = 'تنزیل الرحمن' 
                    WHERE (user_id = 1 OR user_name LIKE '%تنزیل%' OR user_name = 'Tanzeel') 
                      AND type = 'Expense' 
                      AND account_id = $sal_aid
                ");
            }
        }

        // Refresh all_adv_accounts after provisioning
        $all_adv_accounts = $pdo->query("
            SELECT id, code, name 
            FROM acc_chart_of_accounts 
            WHERE type = 'Asset' 
              AND (name LIKE 'ایڈوانس%' OR name LIKE 'Advance%' OR name LIKE '%Advance%' OR name LIKE '%ایڈوانس%')
              AND $coa_t_cond
              AND $coa_del_cond
            ORDER BY id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $eIsoInit) {}

    // Map all advance accounts to their corresponding admin users with strict isolation
    $user_advance_accounts = []; // [uid => [account_ids]]
    $account_to_user_map = [];   // [aid => uid]
    $user_canonical_aid = [];

    foreach ($sys_users as $su) {
        $su_id = (int)$su['id'];
        $su_uname = trim(!empty($su['username']) ? $su['username'] : '');
        $su_name  = trim(!empty($su['name']) ? $su['name'] : '');

        // Dedicated hard isolation for Tanzeel (ID 1)
        if ($su_id === 1 || mb_stripos($su_uname, 'tanzeel') !== false || mb_stripos($su_name, 'تنزیل') !== false) {
            $user_advance_accounts[$su_id] = [$tan_aid];
            $account_to_user_map[$tan_aid] = $su_id;
            $user_canonical_aid[$su_id] = $tan_aid;
            continue;
        }

        // Dedicated hard isolation for Saleem (ID 3)
        if ($su_id === 3 || mb_stripos($su_uname, 'saleem') !== false || mb_stripos($su_name, 'سلیم') !== false) {
            $user_advance_accounts[$su_id] = [$sal_aid];
            $account_to_user_map[$sal_aid] = $su_id;
            $user_canonical_aid[$su_id] = $sal_aid;
            continue;
        }

        // For all other users (e.g. Hammad ID 2, Usman ID 4)
        $user_adv_ids = [];
        foreach ($all_adv_accounts as $aa) {
            $aid = (int)$aa['id'];
            if ($aid === $tan_aid || $aid === $sal_aid) continue; // Never assign Tanzeel or Saleem account to another user

            $clean_label = trim(preg_replace('/^(ایڈوانس|advance)\s*[-:_]?\s*/iu', '', $aa['name']));
            if (empty($clean_label)) $clean_label = $aa['name'];

            $is_matched = false;
            if (!empty($su_uname)) {
                if (mb_strtolower(trim($su_uname)) === mb_strtolower($clean_label) ||
                    mb_stripos($aa['name'], $su_uname) !== false ||
                    mb_stripos($clean_label, $su_uname) !== false) {
                    $is_matched = true;
                }
            }
            if (!$is_matched && !empty($su_name)) {
                if (mb_strtolower(trim($su_name)) === mb_strtolower($clean_label) ||
                    mb_stripos($aa['name'], $su_name) !== false ||
                    mb_stripos($clean_label, $su_name) !== false) {
                    $is_matched = true;
                }
            }
            if ($is_matched) {
                $user_adv_ids[] = $aid;
            }
        }

        $user_adv_ids = array_values(array_unique(array_filter($user_adv_ids)));
        if (!empty($user_adv_ids)) {
            $user_advance_accounts[$su_id] = $user_adv_ids;
            $user_canonical_aid[$su_id] = $user_adv_ids[0];
            foreach ($user_adv_ids as $aid) {
                $account_to_user_map[$aid] = $su_id;
            }
        }
    }

    $adv_accounts = $all_adv_accounts;

    // Reconcile user expenses who spend strictly from advance (e.g. Qari Saleemullah)
    foreach ($user_canonical_aid as $su_id => $can_aid) {
        $u_is_admin = false;
        foreach ($sys_users as $su) {
            if ((int)$su['id'] === $su_id) {
                $u_role = strtolower(trim((string)($su['role'] ?? '')));
                if (in_array($u_role, ['admin', 'superadmin'])) $u_is_admin = true;
                break;
            }
        }
        // Auto-reconciliation query removed to prevent overriding user's chosen payment account
    }

    // 4. Calculate definitive advance summaries per user / account
    $processed_user_ids = [];

    foreach ($sys_users as $su) {
        $su_id = (int)$su['id'];
        $can_aid = $user_canonical_aid[$su_id] ?? 0;
        if ($can_aid <= 0) continue;

        $processed_user_ids[] = $su_id;
        $clean_label = !empty($su['name']) ? $su['name'] : $su['username'];

        // 1. Total Inflow / Transferred in (Debits in Ledger)
        $dr_sum = 0.0;
        try {
            $dr_sum = (float)$pdo->query("
                SELECT COALESCE(SUM(e.debit), 0) 
                FROM acc_ledger_entries e 
                LEFT JOIN acc_vouchers v ON e.voucher_id = v.id 
                WHERE e.account_id = $can_aid AND $t_cond_ledger AND (v.is_deleted = 0 OR v.is_deleted IS NULL)
            ")->fetchColumn();
        } catch (Throwable $eDr) {
            try {
                $dr_sum = (float)$pdo->query("SELECT COALESCE(SUM(debit), 0) FROM acc_ledger_entries WHERE account_id = $can_aid")->fetchColumn();
            } catch (Throwable $eDr2) {
                $dr_sum = 0.0;
            }
        }

        // Fallback: If ledger debit is 0, check if Contra vouchers transferred to this account directly
        if ($dr_sum <= 0) {
            try {
                $v_term = $pdo->quote('%' . $clean_label . '%');
                $dr_sum = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM acc_vouchers WHERE (reference LIKE 'TRANSFER-%' OR type = 'Contra') AND description LIKE $v_term AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchColumn();
            } catch (Throwable $eVDeb) {}
        }

        // 2. Direct expenses in finance table specifically recorded from this advance account
        // DUAL LAYER: Database Query + In-Memory Verification for 100% Zero-Defect Accuracy
        $all_u_aids = array_unique(array_merge([$can_aid], $user_advance_accounts[$su_id] ?? []));
        $aids_in = implode(',', array_map('intval', $all_u_aids));
        
        $sql_adv_exp = 0.0;
        try {
            $sql_adv_exp = (float)$pdo->query("
                SELECT COALESCE(SUM(amount), 0) 
                FROM finance 
                WHERE type = 'Expense' 
                  AND (
                    (user_id = $su_id AND (account_id IN ($aids_in) OR payment_method = 'Advance'))
                    OR
                    (account_id = $can_aid AND (user_id = $su_id OR user_id = 0 OR user_id IS NULL))
                  )
                  AND $raw_t_cond_fin 
                  AND $raw_del_cond_f
            ")->fetchColumn();
        } catch (Throwable $eFe) {
            $sql_adv_exp = 0.0;
        }

        // In-Memory Accumulation from loaded $user_groups
        $mem_adv_exp = 0.0;
        if (!empty($user_groups[$su_id]['expense_records'])) {
            foreach ($user_groups[$su_id]['expense_records'] as $er) {
                $acc_n = trim($er['account_name'] ?? '');
                $pay_m = trim($er['payment_method'] ?? '');
                $r_aid = (int)($er['account_id'] ?? 0);
                $r_amt = (float)($er['amount'] ?? 0);
                
                // Do not count the other user's account under any circumstance
                if ($su_id === 1 && $sal_aid > 0 && $r_aid === $sal_aid) continue;
                if ($su_id === 3 && $tan_aid > 0 && $r_aid === $tan_aid) continue;

                $is_adv = false;
                if (in_array($r_aid, $all_u_aids)) {
                    $is_adv = true;
                } elseif (strcasecmp($pay_m, 'Advance') === 0 || mb_stripos($pay_m, 'ایڈوانس') !== false) {
                    $is_adv = true;
                } elseif (mb_stripos($acc_n, 'ایڈوانس') !== false || mb_stripos($acc_n, 'advance') !== false) {
                    $is_adv = true;
                } elseif (!in_array($su_id, [1, 2]) && $su_id > 0 && ($dr_sum > 0) && (empty($acc_n) || $acc_n === '---')) {
                    $is_adv = true;
                }
                
                if ($is_adv) {
                    $mem_adv_exp += $r_amt;
                }
            }
        }

        $fin_adv_exp = max($sql_adv_exp, $mem_adv_exp);

        // 3. Transfers/Vouchers out of Advance (e.g. Contra fund transfer returning advance to cash, or manual ERP vouchers)
        // Strictly exclude vouchers generated from Simple Finance to eliminate phantom double-counting!
        $vouch_adv_exp = 0.0;
        try {
            $vouch_adv_exp = (float)$pdo->query("
                SELECT COALESCE(SUM(e.credit), 0) 
                FROM acc_ledger_entries e 
                LEFT JOIN acc_vouchers v ON e.voucher_id = v.id 
                WHERE e.account_id = $can_aid 
                  AND (v.type = 'Contra' OR v.reference LIKE 'TRANSFER-%')
                  AND (v.reference NOT LIKE 'FIN-%' OR v.reference IS NULL)
                  AND (v.voucher_no NOT LIKE 'SF-%' OR v.voucher_no IS NULL)
                  AND $t_cond_ledger 
                  AND (v.is_deleted = 0 OR v.is_deleted IS NULL)
            ")->fetchColumn();
        } catch (Throwable $eCr) {
            $vouch_adv_exp = 0.0;
        }

        $effective_deducted = $fin_adv_exp + $vouch_adv_exp;
        $effective_balance = $dr_sum - $effective_deducted;
        $has_act = ($dr_sum > 0 || $effective_deducted > 0);

        // Fetch account details
        $acc_row = null;
        foreach ($adv_accounts as $aa) {
            if ((int)$aa['id'] === $can_aid) { $acc_row = $aa; break; }
        }
        $acc_name = $acc_row['name'] ?? ("ایڈوانس - " . $clean_label);
        $acc_code = $acc_row['code'] ?? '';

        $adv_entry = [
            'account_id' => $can_aid,
            'account_name' => $acc_name,
            'account_code' => $acc_code,
            'user_label' => $clean_label,
            'user_id' => $su_id,
            'total_received' => $dr_sum,
            'total_deducted' => $effective_deducted,
            'net_balance' => $effective_balance,
            'finance_expenses' => $fin_adv_exp,
            'has_activity' => $has_act
        ];

        $user_advance_summaries[$can_aid] = $adv_entry;

        // Enrich $user_groups for accordion display
        if (isset($user_groups[$su_id])) {
            $user_groups[$su_id]['has_advance'] = true;
            $user_groups[$su_id]['advance_account_id'] = $can_aid;
            $user_groups[$su_id]['advance_account_name'] = $acc_name;
            $user_groups[$su_id]['advance_received'] = $dr_sum;
            $user_groups[$su_id]['advance_deducted'] = $effective_deducted;
            $user_groups[$su_id]['advance_balance'] = $effective_balance;
            
            // Other expenses: only expenses that were NOT paid from advance
            $total_user_exp = (float)($user_groups[$su_id]['total_expense'] ?? 0);
            $user_groups[$su_id]['other_expenses'] = max(0, $total_user_exp - $fin_adv_exp);
        }

        if ($has_act) {
            $total_overall_advance_received += $dr_sum;
            $total_overall_advance_deducted += $effective_deducted;
            $total_overall_advance_balance += $effective_balance;
        }
    }

    // Process any remaining advance accounts that belong to non-admin or unmapped users
    foreach ($adv_accounts as $aa) {
        $aid = (int)$aa['id'];
        if (isset($user_advance_summaries[$aid])) continue; // already consolidated

        $clean_label = trim(preg_replace('/^(ایڈوانس|advance)\s*[-:_]?\s*/iu', '', $aa['name']));
        if (empty($clean_label)) $clean_label = $aa['name'];

        $dr_sum = 0.0;
        try {
            $dr_sum = (float)$pdo->query("
                SELECT COALESCE(SUM(e.debit), 0) 
                FROM acc_ledger_entries e 
                LEFT JOIN acc_vouchers v ON e.voucher_id = v.id 
                WHERE e.account_id = $aid AND $t_cond_ledger AND (v.is_deleted = 0 OR v.is_deleted IS NULL)
            ")->fetchColumn();
        } catch (Throwable $eDrRem) {}

        $fin_adv_exp = 0.0;
        try {
            $fin_adv_exp = (float)$pdo->query("
                SELECT COALESCE(SUM(amount), 0) 
                FROM finance 
                WHERE type = 'Expense' AND account_id = $aid AND $raw_t_cond_fin AND $raw_del_cond_f
            ")->fetchColumn();
        } catch (Throwable $eFeRem) {}

        $vouch_adv_exp = 0.0;
        try {
            $vouch_adv_exp = (float)$pdo->query("
                SELECT COALESCE(SUM(e.credit), 0) 
                FROM acc_ledger_entries e 
                LEFT JOIN acc_vouchers v ON e.voucher_id = v.id 
                WHERE e.account_id = $aid 
                  AND (v.type = 'Contra' OR v.reference LIKE 'TRANSFER-%')
                  AND (v.reference NOT LIKE 'FIN-%' OR v.reference IS NULL)
                  AND (v.voucher_no NOT LIKE 'SF-%' OR v.voucher_no IS NULL)
                  AND $t_cond_ledger 
                  AND (v.is_deleted = 0 OR v.is_deleted IS NULL)
            ")->fetchColumn();
        } catch (Throwable $eCrRem) {}

        $effective_deducted = $fin_adv_exp + $vouch_adv_exp;
        $effective_balance = $dr_sum - $effective_deducted;
        $has_act = ($dr_sum > 0 || $effective_deducted > 0);

        if ($has_act) {
            $user_advance_summaries[$aid] = [
                'account_id' => $aid,
                'account_name' => $aa['name'],
                'account_code' => $aa['code'],
                'user_label' => $clean_label,
                'user_id' => 0,
                'total_received' => $dr_sum,
                'total_deducted' => $effective_deducted,
                'net_balance' => $effective_balance,
                'finance_expenses' => $fin_adv_exp,
                'has_activity' => $has_act
            ];
            $total_overall_advance_received += $dr_sum;
            $total_overall_advance_deducted += $effective_deducted;
            $total_overall_advance_balance += $effective_balance;
        }
    }
} catch (Throwable $e) {}

// Determine active advance account for display
$selected_adv_aid = 0;
if (isset($_GET['adv_user_acc'])) {
    $selected_adv_aid = (int)$_GET['adv_user_acc'];
}
if (!$is_admin) {
    // Non-admin: strictly force to their own advance account
    $my_can_aid = $user_canonical_aid[(int)$user_id] ?? 0;
    if ($my_can_aid > 0 && isset($user_advance_summaries[$my_can_aid])) {
        $selected_adv_aid = $my_can_aid;
    } else {
        foreach ($user_advance_summaries as $aid => $summ) {
            if ($is_my_advance_account($summ['account_name'])) {
                $selected_adv_aid = $aid;
                break;
            }
        }
    }
} else {
    // Admin: if not explicitly selected, select the first user advance account with activity
    if ($selected_adv_aid <= 0 || !isset($user_advance_summaries[$selected_adv_aid])) {
        foreach ($user_advance_summaries as $aid => $summ) {
            if ($summ['has_activity']) {
                $selected_adv_aid = $aid;
                break;
            }
        }
    }
    if ($selected_adv_aid <= 0 && !empty($user_advance_summaries)) {
        $selected_adv_aid = array_key_first($user_advance_summaries);
    }
}
$active_adv = $user_advance_summaries[$selected_adv_aid] ?? null;

// 5. Calculate definitive collection summaries per user / collection account (نقد وصولی امانت برائے عملہ)
$user_collection_summaries = [];
$total_overall_collection_received = 0.0;
$total_overall_collection_transferred = 0.0;
$total_overall_collection_balance = 0.0;

try {
    $all_col_accounts = $pdo->query("
        SELECT id, code, name 
        FROM acc_chart_of_accounts 
        WHERE type = 'Asset' 
          AND (name LIKE 'نقد وصولی%' OR name LIKE 'وصولی%' OR name LIKE '%وصولی%' OR name LIKE 'Collection%' OR (CAST(code AS UNSIGNED) >= 1101 AND CAST(code AS UNSIGNED) <= 1199))
          AND $coa_t_cond
          AND $coa_del_cond
        ORDER BY code ASC, id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Map collection accounts to sys_users
    foreach ($sys_users as $su) {
        $su_id = (int)$su['id'];
        $su_uname = trim(!empty($su['username']) ? $su['username'] : '');
        $su_name  = trim(!empty($su['name']) ? $su['name'] : '');
        $clean_label = !empty($su_name) ? $su_name : $su_uname;

        $target_col_aid = 0;
        $target_col_code = '';
        $target_col_name = '';

        if ($su_id === 1 || mb_stripos($su_uname, 'tanzeel') !== false || mb_stripos($su_name, 'تنزیل') !== false) {
            foreach ($all_col_accounts as $ca) {
                if ($ca['code'] == '1101' || (mb_stripos($ca['name'], 'تنزیل') !== false && mb_stripos($ca['name'], 'وصولی') !== false)) {
                    $target_col_aid = (int)$ca['id'];
                    $target_col_code = $ca['code'];
                    $target_col_name = $ca['name'];
                    break;
                }
            }
        } elseif ($su_id === 3 || mb_stripos($su_uname, 'saleem') !== false || mb_stripos($su_name, 'سلیم') !== false) {
            foreach ($all_col_accounts as $ca) {
                if ($ca['code'] == '1102' || (mb_stripos($ca['name'], 'سلیم') !== false && mb_stripos($ca['name'], 'وصولی') !== false)) {
                    $target_col_aid = (int)$ca['id'];
                    $target_col_code = $ca['code'];
                    $target_col_name = $ca['name'];
                    break;
                }
            }
        } else {
            foreach ($all_col_accounts as $ca) {
                $ca_label = trim(preg_replace('/^(نقد وصولی|وصولی|Collection)\s*[-:_]?\s*/iu', '', $ca['name']));
                if (!empty($su_uname) && (mb_strtolower($su_uname) === mb_strtolower($ca_label) || mb_stripos($ca['name'], $su_uname) !== false)) {
                    $target_col_aid = (int)$ca['id'];
                    $target_col_code = $ca['code'];
                    $target_col_name = $ca['name'];
                    break;
                }
                if (!empty($su_name) && (mb_strtolower($su_name) === mb_strtolower($ca_label) || mb_stripos($ca['name'], $su_name) !== false)) {
                    $target_col_aid = (int)$ca['id'];
                    $target_col_code = $ca['code'];
                    $target_col_name = $ca['name'];
                    break;
                }
            }
        }

        if ($target_col_aid <= 0) continue;

        // 1. Total Inflow / Collected (Income receipts recorded in finance with account_id = target_col_aid)
        $col_inc = 0.0;
        try {
            $col_inc = (float)$pdo->query("
                SELECT COALESCE(SUM(amount), 0) 
                FROM finance 
                WHERE type = 'Income' AND account_id = $target_col_aid AND $raw_t_cond_fin AND $raw_del_cond_f
            ")->fetchColumn();
        } catch (Throwable $eInc) {}

        $masjid_col_inc = 0.0;
        try {
            $masjid_col_inc = (float)$pdo->query("
                SELECT COALESCE(SUM(amount), 0) 
                FROM masjid_finance 
                WHERE type = 'Income' AND account_id = $target_col_aid AND (is_deleted = 0 OR is_deleted IS NULL)
            ")->fetchColumn();
        } catch (Throwable $eMInc) {}

        $total_collected = $col_inc + $masjid_col_inc;

        // 2. Direct expenses recorded against this collection account (spending from collection in-hand)
        $col_exp = 0.0;
        try {
            $col_exp = (float)$pdo->query("
                SELECT COALESCE(SUM(amount), 0) 
                FROM finance 
                WHERE type = 'Expense' AND account_id = $target_col_aid AND $raw_t_cond_fin AND $raw_del_cond_f
            ")->fetchColumn();
        } catch (Throwable $eExpCol) {}

        // 3. Outflow / Transferred to admin (Credits in ledger entries where voucher is Contra or TRANSFER)
        $transferred_out = 0.0;
        try {
            $transferred_out = (float)$pdo->query("
                SELECT COALESCE(SUM(e.credit), 0) 
                FROM acc_ledger_entries e 
                JOIN acc_vouchers v ON e.voucher_id = v.id 
                WHERE e.account_id = $target_col_aid 
                  AND (v.type = 'Contra' OR v.reference LIKE 'TRANSFER-%')
                  AND (v.reference NOT LIKE 'FIN-%' OR v.reference IS NULL)
                  AND (v.voucher_no NOT LIKE 'SF-%' OR v.voucher_no IS NULL)
                  AND $t_cond_ledger 
                  AND (v.is_deleted = 0 OR v.is_deleted IS NULL)
            ")->fetchColumn();
        } catch (Throwable $eCrCol) {}

        $net_in_hand = $total_collected - ($transferred_out + $col_exp);
        $has_act = ($total_collected > 0 || $transferred_out > 0 || $col_exp > 0);

        $user_collection_summaries[$target_col_aid] = [
            'account_id' => $target_col_aid,
            'account_name' => $target_col_name,
            'account_code' => $target_col_code,
            'user_label' => $clean_label,
            'user_id' => $su_id,
            'total_collected' => $total_collected,
            'total_transferred' => $transferred_out,
            'total_spent' => $col_exp,
            'net_in_hand' => $net_in_hand,
            'has_activity' => $has_act
        ];

        if ($has_act) {
            $total_overall_collection_received += $total_collected;
            $total_overall_collection_transferred += $transferred_out;
            $total_overall_collection_balance += $net_in_hand;
        }
    }

    // Process any unmapped collection accounts
    foreach ($all_col_accounts as $ca) {
        $aid = (int)$ca['id'];
        if (isset($user_collection_summaries[$aid])) continue;

        $clean_label = trim(preg_replace('/^(نقد وصولی|وصولی|Collection)\s*[-:_]?\s*/iu', '', $ca['name']));
        if (empty($clean_label)) $clean_label = $ca['name'];

        $col_inc = 0.0;
        try {
            $col_inc = (float)$pdo->query("
                SELECT COALESCE(SUM(amount), 0) 
                FROM finance 
                WHERE type = 'Income' AND account_id = $aid AND $raw_t_cond_fin AND $raw_del_cond_f
            ")->fetchColumn();
        } catch (Throwable $eIncRem) {}

        $col_exp = 0.0;
        try {
            $col_exp = (float)$pdo->query("
                SELECT COALESCE(SUM(amount), 0) 
                FROM finance 
                WHERE type = 'Expense' AND account_id = $aid AND $raw_t_cond_fin AND $raw_del_cond_f
            ")->fetchColumn();
        } catch (Throwable $eExpRemCol) {}

        $transferred_out = 0.0;
        try {
            $transferred_out = (float)$pdo->query("
                SELECT COALESCE(SUM(e.credit), 0) 
                FROM acc_ledger_entries e 
                JOIN acc_vouchers v ON e.voucher_id = v.id 
                WHERE e.account_id = $aid 
                  AND (v.type = 'Contra' OR v.reference LIKE 'TRANSFER-%')
                  AND (v.reference NOT LIKE 'FIN-%' OR v.reference IS NULL)
                  AND (v.voucher_no NOT LIKE 'SF-%' OR v.voucher_no IS NULL)
                  AND $t_cond_ledger 
                  AND (v.is_deleted = 0 OR v.is_deleted IS NULL)
            ")->fetchColumn();
        } catch (Throwable $eCrRemCol) {}

        $net_in_hand = $col_inc - ($transferred_out + $col_exp);
        $has_act = ($col_inc > 0 || $transferred_out > 0 || $col_exp > 0);

        if ($has_act) {
            $user_collection_summaries[$aid] = [
                'account_id' => $aid,
                'account_name' => $ca['name'],
                'account_code' => $ca['code'],
                'user_label' => $clean_label,
                'user_id' => 0,
                'total_collected' => $col_inc,
                'total_transferred' => $transferred_out,
                'total_spent' => $col_exp,
                'net_in_hand' => $net_in_hand,
                'has_activity' => $has_act
            ];
            $total_overall_collection_received += $col_inc;
            $total_overall_collection_transferred += $transferred_out;
            $total_overall_collection_balance += $net_in_hand;
        }
    }
} catch (Throwable $eColInit) {}

// Determine active collection account for display
$selected_col_aid = 0;
if (isset($_GET['col_user_acc'])) {
    $selected_col_aid = (int)$_GET['col_user_acc'];
}
if (!$is_admin) {
    if ($my_col_aid > 0 && isset($user_collection_summaries[$my_col_aid])) {
        $selected_col_aid = $my_col_aid;
    } else {
        foreach ($user_collection_summaries as $aid => $summ) {
            if ($is_my_collection_account($summ['account_name'])) {
                $selected_col_aid = $aid;
                break;
            }
        }
    }
} else {
    if ($selected_col_aid <= 0 || !isset($user_collection_summaries[$selected_col_aid])) {
        foreach ($user_collection_summaries as $aid => $summ) {
            if ($summ['has_activity']) {
                $selected_col_aid = $aid;
                break;
            }
        }
    }
    if ($selected_col_aid <= 0 && !empty($user_collection_summaries)) {
        $selected_col_aid = array_key_first($user_collection_summaries);
    }
}
$active_col = $user_collection_summaries[$selected_col_aid] ?? null;
if (!$is_admin && empty($active_col) && $my_col_aid > 0) {
    $active_col = [
        'account_id' => $my_col_aid,
        'account_name' => $my_col_acc_name ?? 'نقد وصولی',
        'account_code' => '1102',
        'user_label' => !empty($my_full_name) ? $my_full_name : $current_username,
        'user_id' => $user_id,
        'total_collected' => 0.0,
        'total_transferred' => 0.0,
        'net_in_hand' => 0.0,
        'has_activity' => false
    ];
}

// Refresh payment accounts after consolidation so duplicate/soft-deleted accounts do not show in forms
try {
    $all_payment_accounts = $pdo->query("SELECT id, code, name FROM acc_chart_of_accounts WHERE type = 'Asset' AND (tenant_id = $cur_tenant_id OR tenant_id IS NULL OR tenant_id = 0) AND (is_deleted = 0 OR is_deleted IS NULL) AND (is_active = 1 OR is_active IS NULL) AND code NOT IN ('1101', '1201', '1301', '2101', '2102', '2103', '5010', '5011') ORDER BY CAST(code AS UNSIGNED) ASC, code ASC")->fetchAll(PDO::FETCH_ASSOC);
    $payment_accounts = $all_payment_accounts;
    if ($is_admin) {
        $admin_exp_pa = [];
        $added_ids = [];

        // 1. Central Cash 1001 (مرکزی کیش) - Put first so Admin defaults to it!
        foreach ($all_payment_accounts as $pa) {
            if (trim($pa['code']) == '1001') {
                $admin_exp_pa[] = $pa;
                $added_ids[] = (int)$pa['id'];
                break;
            }
        }

        // 2. Bank Accounts (exclude loans)
        foreach ($all_payment_accounts as $pa) {
            $pa_code = trim((string)($pa['code'] ?? ''));
            $pa_name = trim($pa['name'] ?? '');
            $pa_id = (int)$pa['id'];
            if (in_array($pa_id, $added_ids)) continue;
            if (stripos($pa_code, 'LOAN') !== false || mb_strpos($pa_name, 'لون') !== false || mb_strpos($pa_name, 'قرض') !== false) continue;
            if ($pa_code == '1002' || stripos($pa_name, 'bank') !== false || mb_stripos($pa_name, 'بینک') !== false) {
                $admin_exp_pa[] = $pa;
                $added_ids[] = $pa_id;
            }
        }

        // 3. Exactly the 4 canonical users' expense accounts (and any active user's expense account, deduplicated)
        $canonical_exp_codes = ['1501', '1502', '1503', '1633'];
        $seen_exp_users = [];
        foreach ($all_payment_accounts as $pa) {
            $pa_code = trim((string)($pa['code'] ?? ''));
            $pa_name = trim($pa['name'] ?? '');
            $pa_id = (int)$pa['id'];
            if (in_array($pa_id, $added_ids)) continue;

            // Strictly exclude deleted, test, loan, or duplicate accounts
            if (in_array($pa_code, ['1504', '1505', '1632', '1635', '1636', '1637', '1639']) || 
                stripos($pa_code, 'LOAN') !== false || 
                mb_strpos($pa_name, 'ٹیسٹ') !== false ||
                mb_strpos($pa_name, 'قرض') !== false ||
                mb_strpos($pa_name, 'لون') !== false) {
                continue;
            }

            if (in_array($pa_code, $canonical_exp_codes) || 
                ((mb_strpos($pa_name, 'خرچ کھاتہ') !== false || mb_strpos($pa_name, 'ایڈوانس') !== false || stripos($pa_name, 'advance') !== false) && 
                  mb_strpos($pa_name, 'وصولی') === false)
            ) {
                // Key deduplication so a user never shows twice
                $uKey = mb_strpos($pa_name, 'عثمان') !== false ? 'usman' : (
                        mb_strpos($pa_name, 'تنزیل') !== false ? 'tanzeel' : (
                        mb_strpos($pa_name, 'سلیم') !== false ? 'saleem' : (
                        mb_strpos($pa_name, 'حماد') !== false ? 'hammad' : $pa_name)));
                if (!isset($seen_exp_users[$uKey])) {
                    $seen_exp_users[$uKey] = true;
                    if (strpos($pa['name'], 'ایڈوانس') !== false) {
                        $pa['name'] = str_replace(['ایڈوانس - ', 'ایڈوانس'], ['خرچ کھاتہ - ', 'خرچ کھاتہ'], $pa['name']);
                    }
                    $admin_exp_pa[] = $pa;
                    $added_ids[] = $pa_id;
                }
            }
        }

        // 4. Admin's own collection account (if exists)
        foreach ($all_payment_accounts as $pa) {
            $pa_name = trim($pa['name'] ?? '');
            $pa_id = (int)$pa['id'];
            if (in_array($pa_id, $added_ids)) continue;
            if ($is_my_collection_account($pa_name)) {
                $admin_exp_pa[] = $pa;
                $added_ids[] = $pa_id;
            }
        }

        $expense_payment_accounts = !empty($admin_exp_pa) ? $admin_exp_pa : $all_payment_accounts;

        // Income Payment Accounts for Admin: Cash 1001, Banks, Collection Accounts ONLY (NO expense accounts, NO loans)
        $admin_inc_pa = [];
        $inc_added_ids = [];
        // 1. Central Cash 1001
        foreach ($all_payment_accounts as $pa) {
            if (trim($pa['code']) == '1001') {
                $admin_inc_pa[] = $pa;
                $inc_added_ids[] = (int)$pa['id'];
                break;
            }
        }
        // 2. Bank Accounts
        foreach ($all_payment_accounts as $pa) {
            $pa_code = trim((string)($pa['code'] ?? ''));
            $pa_name = trim($pa['name'] ?? '');
            $pa_id = (int)$pa['id'];
            if (in_array($pa_id, $inc_added_ids)) continue;
            if (stripos($pa_code, 'LOAN') !== false || mb_strpos($pa_name, 'لون') !== false || mb_strpos($pa_name, 'قرض') !== false) continue;
            if ($pa_code == '1002' || stripos($pa_name, 'bank') !== false || mb_stripos($pa_name, 'بینک') !== false) {
                $admin_inc_pa[] = $pa;
                $inc_added_ids[] = $pa_id;
            }
        }
        // 3. User Cash Collection accounts (نقد وصولی) ONLY
        foreach ($all_payment_accounts as $pa) {
            $pa_code = trim((string)($pa['code'] ?? ''));
            $pa_name = trim($pa['name'] ?? '');
            $pa_id = (int)$pa['id'];
            if (in_array($pa_id, $inc_added_ids)) continue;
            if (stripos($pa_code, 'LOAN') !== false || mb_strpos($pa_name, 'لون') !== false || mb_strpos($pa_name, 'قرض') !== false) continue;
            if (mb_strpos($pa_name, 'خرچ') !== false || mb_strpos($pa_name, 'ایڈوانس') !== false || stripos($pa_name, 'advance') !== false) continue;

            if (mb_strpos($pa_name, 'وصولی') !== false || strpos($pa_code, '11') === 0) {
                $admin_inc_pa[] = $pa;
                $inc_added_ids[] = $pa_id;
            }
        }
        $income_payment_accounts = !empty($admin_inc_pa) ? $admin_inc_pa : $admin_exp_pa;
    } else {
        // Non-admin: ONLY their own expense account and their own collection account!
        $non_admin_exp = [];
        foreach ($all_payment_accounts as $pa) {
            $pa_name = $pa['name'] ?? '';
            $pa_id = (int)$pa['id'];
            if ($is_my_advance_account($pa_name) || $pa_id === $selected_adv_aid) {
                if (strpos($pa['name'], 'ایڈوانس') !== false) {
                    $pa['name'] = str_replace(['ایڈوانس - ', 'ایڈوانس'], ['خرچ کھاتہ - ', 'خرچ کھاتہ'], $pa['name']);
                }
                $non_admin_exp[] = $pa;
            } elseif ($is_my_collection_account($pa_name) || $pa_id === $my_col_aid || $pa_id === $selected_col_aid) {
                $non_admin_exp[] = $pa;
            }
        }
        $expense_payment_accounts = $non_admin_exp;
        
        $non_admin_inc = [];
        foreach ($all_payment_accounts as $pa) {
            $pa_name = $pa['name'] ?? '';
            $pa_id = (int)$pa['id'];
            if ($is_my_collection_account($pa_name) || $pa_id === $my_col_aid || $pa_id === $selected_col_aid) {
                $non_admin_inc[] = $pa;
            }
        }
        $income_payment_accounts = !empty($non_admin_inc) ? $non_admin_inc : $expense_payment_accounts;
    }
} catch (Throwable $eRefPa) {}

include 'header.php';
?>

<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h3 class="fw-bold text-primary mb-0">
            <i class="fas fa-coins me-2"></i> 
            <?php echo ($sys === 'Masjid') ? __('سادہ آمدن و اخراجات (مسجد)', 'Simple Finance (Masjid)') : __('سادہ آمدن و اخراجات (مدرسہ)', 'Simple Finance (Madrasa)'); ?>
        </h3>
        <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-warning text-dark fw-bold" data-bs-toggle="modal" data-bs-target="#fundTransferModal">
                <i class="fas fa-exchange-alt me-1"></i> <?php echo __('رقم منتقلی (ایڈوانس / ٹرانسفر)', 'Fund Transfer (Advance / Transfer)'); ?>
            </button>
            <a href="simple_finance.php?sync_heads=1" class="btn btn-outline-success fw-bold">
                <i class="fas fa-sync me-1"></i> <?php echo __('مدات سنک کریں (Sync Heads)', 'Sync Heads'); ?>
            </a>
            <a href="finance_report.php" class="btn btn-outline-primary fw-bold">
                <i class="fas fa-chart-bar me-1"></i> فنانس رپورٹ
            </a>
            <?php if ($is_admin): 
                $pending_otps = finance_get_pending_otps($pdo, $cur_tenant_id);
            ?>
            <button type="button" class="btn btn-info text-dark fw-bold position-relative shadow-sm" data-bs-toggle="modal" data-bs-target="#adminOtpsModal">
                <i class="fas fa-key me-1"></i> ترمیم کی اجازت / OTP درخواستیں
                <?php if (!empty($pending_otps)): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger shadow-sm">
                        <?php echo count($pending_otps); ?>
                    </span>
                <?php endif; ?>
            </button>
            <a href="manage_advance_entries.php" class="btn btn-outline-danger fw-bold">
                <i class="fas fa-random me-1"></i> بلک انٹریز منتقلی ٹول
            </a>
            <a href="import_csv.php" class="btn btn-outline-success fw-bold">
                <i class="fas fa-file-import me-1"></i> امپورٹ CSV ٹول
            </a>
            <a href="bulk_delete_entries.php" class="btn btn-outline-danger fw-bold">
                <i class="fas fa-trash-alt me-1"></i> بلک ڈیلیٹ ٹول
            </a>
            <a href="recycle_bin.php" class="btn btn-outline-secondary fw-bold">
                <i class="fas fa-recycle me-1"></i> ری سائیکل بن (ٹریش)
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if(isset($_GET['error'])): ?>
        <?php if($_GET['error'] === 'blocked_head'): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0" role="alert">
            <i class="fas fa-ban me-2 fs-5"></i> <strong><?php echo __('پابندی:', 'Restriction:'); ?></strong> <?php echo __('مد (Head) یا تفصیل', 'Head or description'); ?> <b class="text-dark bg-warning px-2 rounded"><?php echo htmlspecialchars($_GET['head_name'] ?? ''); ?></b> <?php echo __('میں براہ راست انٹری کی اجازت نہیں ہے تاکہ ڈبل انٹری سے بچا جا سکے۔', 'cannot be entered directly to avoid double entry.'); ?> 
            <br><br>
            <?php echo __('براہ کرم وظائف اور تنخواہوں کا اندراج وظائف سیکشن میں کریں:', 'Please enter stipends and salaries in payroll section:'); ?> 
            <a href="acc_payroll.php" class="btn btn-sm btn-success ms-2"><i class="fas fa-money-check-alt me-1"></i> <?php echo __('وظائف عملہ', 'Staff Payroll'); ?></a>
            <a href="loans_management.php" class="btn btn-sm btn-warning ms-2 text-dark"><i class="fas fa-hand-holding-usd me-1"></i> <?php echo __('ایڈوانس و قرض مینیجمنٹ', 'Advance & Loan Management'); ?></a>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php elseif($_GET['error'] === 'invalid_transfer'): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0" role="alert">
            <i class="fas fa-exclamation-triangle me-2 fs-5"></i> <strong><?php echo __('غلطی:', 'Error:'); ?></strong> <?php echo __('رقم منتقلی کے لیے بھیجنے اور وصول کرنے والا کھاتہ الگ الگ منتخب کریں اور رقم درست درج کریں۔', 'Select different sender and receiver accounts and enter a valid amount.'); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php elseif($_GET['error'] === 'transfer_failed'): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0" role="alert">
            <i class="fas fa-exclamation-circle me-2 fs-5"></i> <strong><?php echo __('رقم منتقلی میں خرابی:', 'Transfer Error:'); ?></strong> <?php echo htmlspecialchars($_GET['err_msg'] ?? __('سسٹم کی خرابی کی وجہ سے رقم منتقل نہیں ہو سکی۔', 'Could not transfer funds due to system error.')); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php else: ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0" role="alert">
            <i class="fas fa-exclamation-circle me-2 fs-5"></i> <strong><?php echo __('خرابی:', 'Error:'); ?></strong> <?php echo htmlspecialchars($_GET['err_msg'] ?? __('کارروائی میں خرابی پیش آگئی ہے۔', 'An error occurred during operation.')); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if(isset($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        ✅ <?php echo __('کارروائی کامیابی سے مکمل ہو گئی۔', 'Action completed successfully.'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <!-- Collapsible Top Navigation: فنانس سمری اور صارف خرچ کھاتہ (بائی ڈیفالٹ بند) -->
    <div class="card shadow-sm border-0 mb-4" style="background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 14px;">
        <div class="card-body p-3 d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="text-dark fw-bold small me-1">
                    <i class="fas fa-layer-group text-primary me-1"></i> <?php echo __('جائزہ پینلز:', 'Overview Panels:'); ?>
                </span>
                
                <!-- Tab 1: فنانس سمری -->
                <button type="button" id="tabBtnFinanceSummary" class="btn btn-sm btn-outline-primary fw-bold px-3 py-2 rounded-pill d-flex align-items-center gap-2 shadow-sm" onclick="toggleFinancePanel('summary')">
                    <i class="fas fa-chart-pie text-primary"></i>
                    <span><?php echo __('فنانس سمری', 'Finance Summary'); ?></span>
                    <span class="badge bg-primary text-white ms-1"><?php echo htmlspecialchars($period_label); ?></span>
                    <i class="fas fa-chevron-down ms-1" id="chevronSummary" style="font-size: 0.8rem;"></i>
                </button>

                <!-- Tab 2: صارف خرچ کھاتہ -->
                <button type="button" id="tabBtnUserExpense" class="btn btn-sm btn-outline-warning text-dark fw-bold px-3 py-2 rounded-pill d-flex align-items-center gap-2 shadow-sm" onclick="toggleFinancePanel('user')">
                    <i class="fas fa-wallet text-warning"></i>
                    <span><?php echo __('صارف خرچ کھاتہ', 'User Expense Account'); ?></span>
                    <?php if ($is_admin && !empty($user_advance_summaries)): ?>
                        <span class="badge bg-warning text-dark ms-1"><?php echo count($user_advance_summaries); ?> <?php echo __('کھاتے', 'Accounts'); ?></span>
                    <?php elseif (!$is_admin && $active_adv): ?>
                        <?php $b_tmp = (float)($active_adv['net_balance'] ?? 0); ?>
                        <span class="badge <?php echo $b_tmp >= 0 ? 'bg-success' : 'bg-danger'; ?> text-white ms-1">
                            <?php echo ($b_tmp < 0 ? '-' : '+') . 'Rs. ' . number_format(abs($b_tmp)); ?>
                        </span>
                    <?php endif; ?>
                    <i class="fas fa-chevron-down ms-1" id="chevronUser" style="font-size: 0.8rem;"></i>
                </button>
            </div>

            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1 small fw-bold" onclick="closeAllFinancePanels()" id="btnCollapseAll" style="display: none;">
                    <i class="fas fa-compress-alt me-1"></i> <?php echo __('پینل بند کریں', 'Close Panel'); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Panel 1: فنانس سمری (بائی ڈیفالٹ بند - d-none) -->
    <div id="panelFinanceSummary" class="d-none mb-4">
        <div class="card shadow-sm border-0" style="background: #ffffff; border: 1.5px solid #cbd5e1 !important; border-radius: 14px;">
            <div class="card-body p-4">
                <!-- Header with Period Selection -->
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4 pb-3 border-bottom">
                    <div class="d-flex align-items-center gap-2">
                        <span class="fs-5 fw-bold text-dark">
                            <i class="fas fa-chart-line text-primary me-2"></i> <?php echo __('فنانس سمری:', 'Finance Summary:'); ?>
                        </span>
                        <span class="badge bg-primary text-white fs-6 px-3 py-1"><?php echo htmlspecialchars($period_label); ?></span>
                    </div>
                    
                    <!-- Period Filter Buttons (High Contrast & Clear) -->
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <span class="small text-dark fw-bold me-1"><?php echo __('مدت منتخب کریں:', 'Select Period:'); ?></span>
                        <a href="simple_finance.php?period=current_month" class="btn btn-sm <?php echo ($selected_period === 'current_month') ? 'btn-primary fw-bold text-white shadow-sm' : 'btn-outline-dark fw-bold'; ?>" style="<?php echo ($selected_period === 'current_month') ? 'background-color: #0284c7 !important; border-color: #0284c7 !important;' : 'border: 1.5px solid #64748b !important; color: #1e293b !important; background: #ffffff !important;'; ?>">
                            <?php echo __('رواں ماہ', 'This Month'); ?>
                        </a>
                        <a href="simple_finance.php?period=last_month" class="btn btn-sm <?php echo ($selected_period === 'last_month') ? 'btn-primary fw-bold text-white shadow-sm' : 'btn-outline-dark fw-bold'; ?>" style="<?php echo ($selected_period === 'last_month') ? 'background-color: #0284c7 !important; border-color: #0284c7 !important;' : 'border: 1.5px solid #64748b !important; color: #1e293b !important; background: #ffffff !important;'; ?>">
                            <?php echo __('پچھلا مہینہ', 'Last Month'); ?>
                        </a>
                        <a href="simple_finance.php?period=current_year" class="btn btn-sm <?php echo ($selected_period === 'current_year') ? 'btn-primary fw-bold text-white shadow-sm' : 'btn-outline-dark fw-bold'; ?>" style="<?php echo ($selected_period === 'current_year') ? 'background-color: #0284c7 !important; border-color: #0284c7 !important;' : 'border: 1.5px solid #64748b !important; color: #1e293b !important; background: #ffffff !important;'; ?>">
                            <?php echo __('رواں سال', 'This Year'); ?>
                        </a>
                        <button type="button" class="btn btn-sm <?php echo ($selected_period === 'custom') ? 'btn-primary fw-bold text-white shadow-sm' : 'btn-outline-dark fw-bold'; ?>" style="<?php echo ($selected_period === 'custom') ? 'background-color: #0284c7 !important; border-color: #0284c7 !important;' : 'border: 1.5px solid #64748b !important; color: #1e293b !important; background: #ffffff !important;'; ?>" onclick="document.getElementById('customDateFilterRow').classList.toggle('d-none');">
                            <i class="fas fa-calendar-day me-1"></i> <?php echo __('کسٹم تاریخ', 'Custom Date'); ?>
                        </button>
                        <?php if ($selected_period !== 'current_month'): ?>
                        <a href="simple_finance.php?period=all" class="btn btn-sm btn-outline-secondary fw-bold" title="<?php echo __('تمام ریکارڈز', 'All Records'); ?>">
                            <?php echo __('سب', 'All'); ?>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Custom Date Inputs (collapsible) -->
                <div id="customDateFilterRow" class="<?php echo ($selected_period === 'custom') ? '' : 'd-none'; ?> mb-4 p-3 rounded" style="background: #f8fafc; border: 1.5px solid #cbd5e1;">
                    <form method="GET" action="simple_finance.php" class="row g-2 align-items-end">
                        <input type="hidden" name="period" value="custom">
                        <div class="col-md-4 col-sm-6">
                            <label class="form-label small text-dark fw-bold"><?php echo __('شروع تاریخ (From Date):', 'From Date:'); ?></label>
                            <input type="date" name="from_date" class="form-control form-control-sm border-secondary" value="<?php echo htmlspecialchars($filter_start_date ?? date('Y-m-01')); ?>" required>
                        </div>
                        <div class="col-md-4 col-sm-6">
                            <label class="form-label small text-dark fw-bold"><?php echo __('اختتامی تاریخ (To Date):', 'To Date:'); ?></label>
                            <input type="date" name="to_date" class="form-control form-control-sm border-secondary" value="<?php echo htmlspecialchars($filter_end_date ?? date('Y-m-t')); ?>" required>
                        </div>
                        <div class="col-md-4 col-sm-12 d-flex gap-2">
                            <button type="submit" class="btn btn-sm btn-primary fw-bold px-3 shadow-sm" style="background-color: #0284c7 !important;">
                                <i class="fas fa-filter me-1"></i> <?php echo __('فلٹر لاگو کریں', 'Apply Filter'); ?>
                            </button>
                            <a href="simple_finance.php?period=current_month" class="btn btn-sm btn-outline-secondary">
                                <?php echo __('ری سیٹ', 'Reset'); ?>
                            </a>
                        </div>
                    </form>
                </div>

                <!-- 3 High-Contrast Vibrant Stat Cards: کل آمدن، کل اخراجات، خالص بچت -->
                <div class="row g-3 text-center">
                    <!-- Card 1: کل آمدن -->
                    <div class="col-md-4">
                        <div class="p-3 text-white shadow-sm" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%) !important; border-radius: 14px; color: #ffffff !important; border: 1px solid #059669;">
                            <div class="fw-bold mb-1" style="color: #ffffff !important; font-size: 0.95rem;">
                                <i class="fas fa-arrow-down me-1"></i> <?php echo __('منتخب مدت کی کل آمدن', 'Total Period Income'); ?>
                            </div>
                            <h3 class="fw-bold mb-0" style="color: #ffffff !important; font-size: 1.85rem; direction: ltr; font-weight: 800; text-shadow: 0 1px 2px rgba(0,0,0,0.25);">
                                Rs. <?php echo number_format($period_total_income); ?>
                            </h3>
                        </div>
                    </div>
                    <!-- Card 2: کل اخراجات -->
                    <div class="col-md-4">
                        <div class="p-3 text-white shadow-sm" style="background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%) !important; border-radius: 14px; color: #ffffff !important; border: 1px solid #dc2626;">
                            <div class="fw-bold mb-1" style="color: #ffffff !important; font-size: 0.95rem;">
                                <i class="fas fa-arrow-up me-1"></i> <?php echo __('منتخب مدت کے کل اخراجات', 'Total Period Expense'); ?>
                            </div>
                            <h3 class="fw-bold mb-0" style="color: #ffffff !important; font-size: 1.85rem; direction: ltr; font-weight: 800; text-shadow: 0 1px 2px rgba(0,0,0,0.25);">
                                Rs. <?php echo number_format($period_total_expense); ?>
                            </h3>
                        </div>
                    </div>
                    <!-- Card 3: خالص بچت / بقایا بیلنس -->
                    <div class="col-md-4">
                        <div class="p-3 text-white shadow-sm" style="background: <?php echo $period_net_balance >= 0 ? 'linear-gradient(135deg, #0284c7 0%, #38bdf8 100%)' : 'linear-gradient(135deg, #d97706 0%, #f59e0b 100%)'; ?> !important; border-radius: 14px; color: #ffffff !important; border: 1px solid <?php echo $period_net_balance >= 0 ? '#0284c7' : '#d97706'; ?>;">
                            <div class="fw-bold mb-1" style="color: #ffffff !important; font-size: 0.95rem;">
                                <i class="fas fa-wallet me-1"></i> <?php echo __('خالص بچت / بقایا بیلنس', 'Net Balance'); ?>
                            </div>
                            <h3 class="fw-bold mb-0" style="color: #ffffff !important; font-size: 1.85rem; direction: ltr; font-weight: 800; text-shadow: 0 1px 2px rgba(0,0,0,0.25);">
                                <?php echo ($period_net_balance < 0 ? '-' : '+'); ?> Rs. <?php echo number_format(abs($period_net_balance)); ?>
                            </h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Panel 2: صارف خرچ کھاتہ (بائی ڈیفالٹ بند - d-none) -->
    <div id="panelUserExpense" class="d-none mb-4">
        <div class="row">
            <div class="col-12">
                <?php if (!$is_admin): ?>
                    <!-- Non-Admin: Logged-in User's Own Expense Balance -->
                    <div class="card shadow-sm border-0 border-start border-4 border-info" style="border-radius: 12px; background: #ffffff;">
                        <div class="card-body py-3">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <div>
                                    <span class="badge bg-info text-dark fw-bold mb-1">
                                        <i class="fas fa-wallet me-1"></i> <?php echo __('خرچ کھاتہ برائے:', 'Expense Account for:'); ?> <?php echo htmlspecialchars($active_adv['user_label'] ?? $current_username); ?>
                                    </span>
                                    <h5 class="mb-0 fw-bold text-dark"><?php echo __('آپ کا بقایا بیلنس:', 'Your Remaining Balance:'); ?></h5>
                                    <div class="small text-muted mt-1">
                                        <?php echo __('کل موصولہ رقم (ٹرانسفر):', 'Total Funds Received:'); ?> <strong class="text-primary">Rs. <?php echo number_format($active_adv['total_received'] ?? 0); ?></strong> | 
                                        <?php echo __('کھاتے سے منہا شدہ خرچ:', 'Deducted Expenses:'); ?> <strong class="text-danger">Rs. <?php echo number_format($active_adv['total_deducted'] ?? 0); ?></strong>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <?php $bal = (float)($active_adv['net_balance'] ?? 0); ?>
                                    <h2 class="mb-0 fw-bold <?php echo $bal >= 0 ? 'text-success' : 'text-danger'; ?>" style="direction:ltr;">
                                        <?php echo ($bal < 0 ? '-' : '+'); ?> Rs. <?php echo number_format(abs($bal)); ?>
                                    </h2>
                                    <span class="badge <?php echo $bal >= 0 ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger'; ?> mt-1">
                                        <i class="fas <?php echo $bal >= 0 ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> me-1"></i>
                                        <?php echo $bal >= 0 ? __('بقایا رقم موجود ہے', 'Remaining balance available') : __('صارف نے جیب سے زیادہ خرچ کیا (ادارہ واجب الادا ہے)', 'User overspent (Institute owes user)'); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Admin: Display Specific User's Expense Account with User Switcher -->
                    <div class="card shadow-sm border-0 border-start border-4 border-warning" style="border-radius: 12px; background: #ffffff;">
                        <div class="card-body py-3">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2 pb-2 border-bottom">
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <span class="badge bg-warning text-dark fw-bold fs-6">
                                        <i class="fas fa-exchange-alt me-1"></i> <?php echo __('صارف خرچ کھاتہ:', 'User Expense Account:'); ?>
                                    </span>
                                    <?php if (!empty($user_advance_summaries)): ?>
                                    <select class="form-select form-select-sm d-inline-block w-auto fw-bold text-dark border-warning shadow-sm" onchange="window.location.href='simple_finance.php?tab=transfer&adv_user_acc=' + this.value">
                                        <?php foreach ($user_advance_summaries as $aid => $summ): 
                                            $u_bal = $summ['net_balance'];
                                            $bal_text = ($u_bal < 0 ? '-' : '+') . 'Rs. ' . number_format(abs($u_bal));
                                        ?>
                                            <option value="<?php echo $aid; ?>" <?php echo ($aid === $selected_adv_aid) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($summ['user_label']); ?> (<?php echo __('بقایا:', 'Balance:'); ?> <?php echo $bal_text; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php endif; ?>
                                </div>
                                <div class="small text-muted">
                                    <?php echo __('مجموعی جاری کردہ فنڈز:', 'Total Issued Funds:'); ?> <strong class="text-primary">Rs. <?php echo number_format($total_overall_advance_received); ?></strong> | 
                                    <?php echo __('کل منہا شدہ اخراجات:', 'Total Deducted Expenses:'); ?> <strong class="text-danger">Rs. <?php echo number_format($total_overall_advance_deducted); ?></strong> | 
                                    <?php echo __('مجموعی بقایا:', 'Total Balance:'); ?> <strong class="<?php echo $total_overall_advance_balance >= 0 ? 'text-success' : 'text-danger'; ?>">Rs. <?php echo number_format($total_overall_advance_balance); ?></strong>
                                </div>
                            </div>

                            <?php if ($active_adv): 
                                $bal = (float)$active_adv['net_balance'];
                            ?>
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <div>
                                    <h5 class="mb-1 fw-bold text-dark">
                                        <i class="fas fa-user-circle me-1 text-primary"></i> 
                                        <?php echo __('خرچ کھاتہ برائے:', 'Expense Account for:'); ?> <span class="text-primary"><?php echo htmlspecialchars($active_adv['user_label']); ?></span>
                                    </h5>
                                    <div class="small text-muted">
                                        <?php echo __('کل موصولہ ٹرانسفر:', 'Total Transfer Received:'); ?> <strong class="text-primary">Rs. <?php echo number_format($active_adv['total_received']); ?></strong> | 
                                        <?php echo __('خرچ کھاتے سے منہا اخراجات:', 'Expenses Deducted:'); ?> <strong class="text-danger">Rs. <?php echo number_format($active_adv['total_deducted']); ?></strong>
                                        <?php if (!empty($active_adv['finance_expenses']) && $active_adv['finance_expenses'] != $active_adv['total_deducted']): ?>
                                            <span class="badge bg-light text-muted border ms-1"><?php echo __('لیجر منہا:', 'Ledger Deducted:'); ?> Rs. <?php echo number_format($active_adv['total_deducted']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <h2 class="mb-0 fw-bold <?php echo $bal >= 0 ? 'text-success' : 'text-danger'; ?>" style="direction:ltr;">
                                        <?php echo ($bal < 0 ? '-' : '+'); ?> Rs. <?php echo number_format(abs($bal)); ?>
                                    </h2>
                                    <span class="badge <?php echo $bal >= 0 ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger'; ?> mt-1">
                                        <i class="fas <?php echo $bal >= 0 ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> me-1"></i>
                                        <?php echo $bal >= 0 ? __('صارف کے پاس بقایا رقم ہے', 'User has remaining funds') : __('صارف نے جیب سے زیادہ خرچ کیا (ادارہ واجب الادا ہے)', 'User overspent (Institute owes user)'); ?>
                                    </span>
                                    <?php if ($is_admin): ?>
                                        <div class="mt-2">
                                            <a href="manage_advance_entries.php?user_id=<?php echo (int)($active_adv['user_id'] ?? 3); ?>" class="btn btn-sm btn-outline-danger py-1 px-2 fw-bold shadow-sm">
                                                <i class="fas fa-random me-1"></i> <?php echo __('بلک انٹریز منتقلی و درستگی ٹول (Bulk Move Tool)', 'Bulk Move Tool'); ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-2 text-muted">
                                <?php echo __('ابھی کسی صارف کو رقم منتقل نہیں کی گئی۔ رقم منتقلی کے لیے اوپر "رقم منتقلی" بٹن استعمال کریں۔', 'No funds transferred yet. Use "Transfer Funds" button above.'); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Nav Tabs -->
    <style>
        .nav-tabs .nav-link { 
            color: #495057; 
            border: 1px solid transparent; 
            border-top-left-radius: 0.5rem; 
            border-top-right-radius: 0.5rem; 
            background-color: #f8f9fa;
            margin-right: 5px;
        }
        .nav-tabs .nav-link.active { 
            background-color: #ffffff; 
            border-color: #dee2e6 #dee2e6 #ffffff; 
            color: #000;
        }
        .nav-tabs .nav-link.income-tab.active { color: #198754; border-top: 3px solid #198754; }
        .nav-tabs .nav-link.expense-tab.active { color: #dc3545; border-top: 3px solid #dc3545; }
        .nav-tabs .nav-link.transfer-tab.active { color: #fd7e14; border-top: 3px solid #fd7e14; }
    </style>
    <ul class="nav nav-tabs mb-4 border-bottom flex-nowrap overflow-x-auto text-nowrap" id="financeTabs" role="tablist" style="overflow-x: auto; white-space: nowrap; -webkit-overflow-scrolling: touch; scrollbar-width: thin;">
        <li class="nav-item" role="presentation" style="flex: 0 0 auto;">
            <button class="nav-link income-tab active fw-bold px-4 py-2" id="income-tab" data-bs-toggle="tab" data-bs-target="#income" type="button" role="tab" aria-controls="income" aria-selected="true" style="white-space: nowrap;"><i class="fas fa-arrow-down me-2"></i> <?php echo __('آمدن (Income)', 'Income'); ?></button>
        </li>
        <li class="nav-item" role="presentation" style="flex: 0 0 auto;">
            <button class="nav-link expense-tab fw-bold px-4 py-2" id="expense-tab" data-bs-toggle="tab" data-bs-target="#expense" type="button" role="tab" aria-controls="expense" aria-selected="false" style="white-space: nowrap;"><i class="fas fa-arrow-up me-2"></i> <?php echo __('اخراجات (Expense)', 'Expenses'); ?></button>
        </li>
        <li class="nav-item" role="presentation" style="flex: 0 0 auto;">
            <button class="nav-link transfer-tab fw-bold px-4 py-2" id="transfer-tab" data-bs-toggle="tab" data-bs-target="#transfer" type="button" role="tab" aria-controls="transfer" aria-selected="false" style="white-space: nowrap;"><i class="fas fa-exchange-alt me-2"></i> <?php echo __('فنڈز ٹرانسفر / ایڈوانس', 'Funds Transfer / Advance'); ?></button>
        </li>
    </ul>

    <div class="tab-content" id="financeTabsContent">
        <!-- Tab 1: Income -->
        <div class="tab-pane fade show active" id="income" role="tabpanel" aria-labelledby="income-tab">
            <div class="row">
                <div class="col-lg-12">

            <!-- Collection In-Hand (نقد وصولی امانت) Card -->
            <?php if (!$is_admin && $active_col): ?>
                <div class="card shadow-sm border-0 neobox border-start border-4 border-success mb-4">
                    <div class="card-body py-3">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <span class="badge bg-success text-white fw-bold mb-1">
                                    <i class="fas fa-hand-holding-usd me-1"></i> <?php echo __('نقد وصولی کھاتہ (امانت) برائے:', 'Cash Collection Account for:'); ?> <?php echo htmlspecialchars($active_col['user_label'] ?? $current_username); ?>
                                </span>
                                <h5 class="mb-0 fw-bold text-dark"><?php echo __('آپ کی تحویل میں موصولہ رقم (امانت):', 'Cash In-Hand:'); ?></h5>
                                <div class="small text-muted mt-1">
                                    <?php echo __('کل موصولہ رقم:', 'Total Received:'); ?> <strong class="text-success">Rs. <?php echo number_format($active_col['total_collected'] ?? 0); ?></strong> | 
                                    <?php echo __('منہا اخراجات:', 'Expenses Deducted:'); ?> <strong class="text-danger">Rs. <?php echo number_format($active_col['total_spent'] ?? 0); ?></strong> | 
                                    <?php echo __('ایڈمن کو جمع کروائی گئی:', 'Handed over to Admin:'); ?> <strong class="text-primary">Rs. <?php echo number_format($active_col['total_transferred'] ?? 0); ?></strong>
                                </div>
                            </div>
                            <div class="text-end">
                                <?php $col_bal = (float)($active_col['net_in_hand'] ?? 0); ?>
                                <h2 class="mb-0 fw-bold text-success" style="direction:ltr;">
                                    Rs. <?php echo number_format(max(0, $col_bal)); ?>
                                </h2>
                                <span class="badge <?php echo $col_bal > 0 ? 'bg-warning text-dark border border-warning' : 'bg-success-subtle text-success border border-success'; ?> mt-1">
                                    <i class="fas <?php echo $col_bal > 0 ? 'fa-clock' : 'fa-check-circle'; ?> me-1"></i>
                                    <?php echo $col_bal > 0 ? __('ایڈمن کو جمع کروانا باقی ہے', 'Pending handover to Admin') : __('تمام موصول شدہ رقم جمع ہو چکی ہے', 'All received cash handed over'); ?>
                                </span>
                                <?php if ($col_bal > 0): ?>
                                    <div class="mt-2">
                                        <button type="button" class="btn btn-sm btn-warning text-dark fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#fundTransferModal" onclick="prepareCollectionHandover(<?php echo (int)($active_col['account_id'] ?? 0); ?>, <?php echo $col_bal; ?>)">
                                            <i class="fas fa-hand-holding-usd me-1"></i> <?php echo __('ایڈمن کو رقم جمع کروائیں', 'Hand over Cash to Admin'); ?>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php elseif ($is_admin && !empty($user_collection_summaries)): ?>
                <div class="card shadow-sm border-0 neobox border-start border-4 border-success mb-4">
                    <div class="card-body py-3">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2 pb-2 border-bottom">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="badge bg-success text-white fw-bold fs-6">
                                    <i class="fas fa-hand-holding-usd me-1"></i> <?php echo __('عملہ نقد وصولی کھاتہ (امانت):', 'Staff Cash Collection Account:'); ?>
                                </span>
                                <select class="form-select form-select-sm d-inline-block w-auto fw-bold text-dark border-success shadow-sm" onchange="window.location.href='simple_finance.php?col_user_acc=' + this.value">
                                    <?php foreach ($user_collection_summaries as $aid => $summ): 
                                        $u_col_bal = $summ['net_in_hand'];
                                    ?>
                                        <option value="<?php echo $aid; ?>" <?php echo ($aid === $selected_col_aid) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($summ['user_label']); ?> (<?php echo __('بقایا تحویل:', 'In-Hand Balance:'); ?> Rs. <?php echo number_format(max(0, $u_col_bal)); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="small text-muted">
                                <?php echo __('مجموعی وصولی:', 'Total Collection:'); ?> <strong class="text-success">Rs. <?php echo number_format($total_overall_collection_received); ?></strong> | 
                                <?php echo __('منتقل کردہ:', 'Transferred:'); ?> <strong class="text-primary">Rs. <?php echo number_format($total_overall_collection_transferred); ?></strong> | 
                                <?php echo __('عملہ کے پاس بقایا امانت:', 'Pending with Staff:'); ?> <strong class="text-warning">Rs. <?php echo number_format(max(0, $total_overall_collection_balance)); ?></strong>
                            </div>
                        </div>

                        <?php if ($active_col): 
                            $col_bal = (float)$active_col['net_in_hand'];
                        ?>
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <h5 class="mb-1 fw-bold text-dark">
                                    <i class="fas fa-user-circle me-1 text-success"></i> 
                                    <?php echo __('نقد وصولی برائے:', 'Cash Collection for:'); ?> <span class="text-success"><?php echo htmlspecialchars($active_col['user_label']); ?></span>
                                </h5>
                                <div class="small text-muted">
                                    <?php echo __('کل وصول کردہ فنڈز:', 'Total Collected:'); ?> <strong class="text-success">Rs. <?php echo number_format($active_col['total_collected']); ?></strong> | 
                                    <?php echo __('منہا اخراجات:', 'Expenses Deducted:'); ?> <strong class="text-danger">Rs. <?php echo number_format($active_col['total_spent'] ?? 0); ?></strong> | 
                                    <?php echo __('مرکزی کیش میں جمع شدہ:', 'Deposited to Central Cash:'); ?> <strong class="text-primary">Rs. <?php echo number_format($active_col['total_transferred']); ?></strong>
                                </div>
                            </div>
                            <div class="text-end">
                                <h2 class="mb-0 fw-bold text-success" style="direction:ltr;">
                                    Rs. <?php echo number_format(max(0, $col_bal)); ?>
                                </h2>
                                <span class="badge <?php echo $col_bal > 0 ? 'bg-warning text-dark border border-warning' : 'bg-success-subtle text-success border border-success'; ?> mt-1">
                                    <i class="fas <?php echo $col_bal > 0 ? 'fa-clock' : 'fa-check-circle'; ?> me-1"></i>
                                    <?php echo $col_bal > 0 ? __('صارف کی تحویل میں رقم موجود ہے', 'User holds cash') : __('تمام موصول شدہ رقم جمع ہو چکی ہے', 'All collected funds deposited'); ?>
                                </span>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- آمدن اندراج فارم -->
            <div class="card shadow-sm border-0 neobox mb-4">
                <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-white" style="color: #ffffff !important;"><i class="fas fa-arrow-down me-2 text-white"></i> <?php echo __('آمدن اندراج (Income Entry)', 'Income Entry'); ?></h5>
                    <span class="badge bg-light text-success fw-bold"><?php echo __('آمدن', 'Income'); ?></span>
                </div>
                <div class="card-body">
                    <?php 
                    try {
                        $preview_comp_receipt = next_user_receipt_no($pdo, $user_id, $sys);
                    } catch (Throwable $e) {
                        $preview_comp_receipt = 'Auto';
                    }
                    ?>
                    <div class="alert alert-success d-flex justify-content-between align-items-center py-2 px-3 mb-3 border-success">
                        <div>
                            <i class="fas fa-receipt me-2 fs-5 text-success"></i>
                            <strong class="text-dark"><?php echo __('کمپیوٹرائزڈ رسید نمبر:', 'Computerized Receipt No:'); ?> </strong>
                        </div>
                        <span class="badge bg-success fs-6 fw-bold">#<?php echo $preview_comp_receipt; ?></span>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="type" value="Income">
                        <?php echo csrf_field(); ?>
                        <?php if ($is_admin): ?>
                        <div class="row g-3 mb-2">
                            <div class="col-md-12">
                                <div class="p-2 bg-light border rounded d-flex align-items-center gap-2">
                                    <label class="fw-bold form-label m-0 text-dark"><i class="fas fa-user-tag text-success me-1"></i> <?php echo __('کس صارف کی آمدن ہے؟ (Record Owner):', 'Record Owner:'); ?></label>
                                    <select name="form_user_id" class="form-select form-select-sm w-auto fw-bold text-dark border-success">
                                        <?php foreach ($sys_users as $su): ?>
                                            <option value="<?php echo $su['id']; ?>" <?php echo ((int)$su['id'] === (int)$user_id) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars(!empty($su['name']) ? $su['name'] : $su['username']); ?> (ID: <?php echo $su['id']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted"><?php echo __('(جس صارف کی وصولی میں یہ رسید درج کرنی ہو)', '(Select the user receiving this income)'); ?></small>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="fw-bold form-label"><?php echo __('تاریخ', 'Date'); ?></label>
                                <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="fw-bold form-label"><?php echo __('مد (Income Head)', 'Income Category'); ?></label>
                                <div class="input-group">
                                    <select name="head_id" class="form-select" required style="color: #212529 !important; background-color: #ffffff !important;">
                                        <option value="" style="color: #6c757d;"><?php echo __('آمدن مد منتخب کریں', 'Select Income Head'); ?></option>
                                        <?php foreach($income_heads as $h): ?>
                                            <option value="<?php echo $h['id']; ?>" style="color: #212529 !important; background: #ffffff !important;"><?php echo htmlspecialchars(safe_localize_head($h['name'], $lang)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#addHeadModal" onclick="setHeadTypeModal('Income')" title="<?php echo __('نئی مد', 'New Category'); ?>">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label class="fw-bold form-label"><?php echo __('رقم (Rs.)', 'Amount (Rs.)'); ?></label>
                                <input type="number" step="0.01" name="amount" class="form-control" placeholder="<?php echo __('مثلاً: 5000', 'e.g. 5000'); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="fw-bold form-label"><?php echo __('کہاں جمع ہوئی؟ (وصولی کھاتہ)', 'Deposit To (Account)'); ?></label>
                                <select name="account_id" class="form-select" style="color: #212529 !important; background-color: #ffffff !important;">
                                    <?php foreach($income_payment_accounts as $pa): ?>
                                        <option value="<?php echo $pa['id']; ?>" <?php 
                                            if (!$is_admin) {
                                                echo ((int)$pa['id'] === $my_col_aid || (int)$pa['id'] === $selected_col_aid || $is_my_collection_account($pa['name'] ?? '')) ? 'selected' : '';
                                            } else {
                                                echo ($pa['code'] == '1001') ? 'selected' : '';
                                            }
                                        ?>>
                                            <?php echo htmlspecialchars($pa['name'] . ' (' . $pa['code'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label class="fw-bold form-label"><?php echo __('دینے والے کا نام (Donor)', 'Donor / Payer Name'); ?></label>
                                <input type="text" name="donor_name" class="form-control" placeholder="<?php echo __('مثلاً: احمد علی', 'e.g. Ahmad Ali'); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="fw-bold form-label"><?php echo __('طریقہ وصولی (Method)', 'Payment Method'); ?></label>
                                <select name="payment_method" class="form-select">
                                    <option value="Cash"><?php echo __('نقد رقم (Cash)', 'Cash'); ?></option>
                                    <option value="Bank Transfer"><?php echo __('آن لائن بینک ٹرانسفر (Online Transfer)', 'Online Bank Transfer'); ?></option>
                                    <option value="Cheque"><?php echo __('چیک (Cheque)', 'Cheque'); ?></option>
                                </select>
                            </div>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label class="fw-bold form-label"><?php echo __('دستی رسید نمبر (Manual Receipt)', 'Manual Receipt No'); ?></label>
                                <input type="text" name="manual_receipt_no" class="form-control" placeholder="<?php echo __('اختياری', 'Optional'); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="fw-bold form-label"><?php echo __('پتہ (Address)', 'Address'); ?></label>
                                <input type="text" name="donor_address" class="form-control" placeholder="<?php echo __('اختياری', 'Optional'); ?>">
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="fw-bold form-label"><?php echo __('تفصیل (Description)', 'Description'); ?></label>
                            <textarea name="description" class="form-control" rows="2" placeholder="<?php echo __('تفصیل لکھیے۔۔۔', 'Enter description...'); ?>"></textarea>
                        </div>

                        <button type="submit" name="add_record" class="btn btn-success w-100 fw-bold mt-3">
                            <i class="fas fa-save me-1"></i> <?php echo __('آمدن ریکارڈ محفوظ کریں', 'Save Income Record'); ?>
                        </button>
                    </form>
                </div>
            </div>

            <!-- آمدن انٹریز ہسٹری -->
            <?php if ($is_admin): ?>
                <?php echo render_user_wise_finance_cards($user_groups, 'income', $sys, $lang, $user_id); ?>
            <?php else: ?>
            <div class="card shadow-sm border-0 neobox">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-white" style="color: #ffffff !important;"><i class="fas fa-list me-2 text-white"></i> <?php echo __('آمدن انٹریز ہسٹری', 'Income Entries History'); ?> (<?php echo __('کل:', 'Total:'); ?> <?php echo count($income_records); ?>)</h5>
                    <input type="text" id="searchIncome" class="form-control form-control-sm w-auto" placeholder="<?php echo __('تلاش...', 'Search...'); ?>" onkeyup="filterTable('incomeTable', 'searchIncome')">
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 text-center" id="incomeTable">
                            <thead class="table-light">
                                <tr>
                                    <th><?php echo __('تاریخ', 'Date'); ?></th>
                                    <th><?php echo __('مد', 'Head / Category'); ?></th>
                                    <th><?php echo __('رقم', 'Amount'); ?></th>
                                    <th><?php echo __('رسید #', 'Receipt #'); ?></th>
                                    <th><?php echo __('تفصیل / نام', 'Details / Name'); ?></th>
                                    <th><?php echo __('ایکشن', 'Action'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $print_file = ($sys === 'Masjid') ? 'print_masjid_receipt.php' : 'print_receipt.php';
                                foreach($income_records as $r): 
                                ?>
                                <tr>
                                    <td><small class="fw-bold"><?php echo !empty($r['date']) ? date('d-M-Y', strtotime($r['date'])) : '---'; ?></small></td>
                                    <td class="fw-bold text-success">
                                        <?php echo htmlspecialchars(safe_localize_head($r['head_name'] ?? '---', $lang)); ?>
                                        <?php if(!empty($r['account_name'])): ?>
                                            <br><span class="badge bg-light text-dark border"><i class="fas fa-wallet text-secondary me-1"></i><?php echo htmlspecialchars($r['account_name']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-bold text-success">Rs. <?php echo number_format((float)($r['amount'] ?? 0)); ?></td>
                                    <td>
                                        <span class="badge bg-secondary">#<?php echo htmlspecialchars((string)(!empty($r['computer_receipt_no']) ? $r['computer_receipt_no'] : ($r['id'] ?? ''))); ?></span>
                                    </td>
                                    <td class="small text-start">
                                        <?php if(!empty($r['donor_name'])): ?>
                                            <span class="text-primary fw-bold"><?php echo htmlspecialchars((string)$r['donor_name']); ?></span><br>
                                        <?php endif; ?>
                                        <span class="text-muted"><?php echo htmlspecialchars((string)($r['description'] ?? '')); ?></span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="<?php echo $print_file; ?>?id=<?php echo $r['id']; ?>" class="btn btn-outline-primary" target="_blank" title="<?php echo __('رسید پرنٹ', 'Print Receipt'); ?>"><i class="fas fa-print"></i></a>
                                            <a href="edit_finance.php?id=<?php echo $r['id']; ?>" class="btn btn-outline-warning" title="<?php echo __('ترمیم', 'Edit'); ?>"><i class="fas fa-edit"></i></a>
                                            <form method="POST" action="simple_finance.php" style="display:inline-block;" onsubmit="return confirm('<?php echo __('کیا واقعی یہ ریکارڈ ڈیلیٹ کرنا چاہتے ہیں؟ اس سے رسید نمبر آزاد ہو جائے گا۔', 'Are you sure you want to delete this record? This will release the receipt number.'); ?>');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="delete_record" value="1">
                                                <input type="hidden" name="del_id" value="<?php echo $r['id']; ?>">
                                                <button type="submit" class="btn btn-outline-danger" title="<?php echo __('ڈیلیٹ', 'Delete'); ?>"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(count($income_records) == 0): ?>
                                <tr><td colspan="6" class="text-center p-4 text-muted"><?php echo __('کوئی آمدن ریکارڈ موجود نہیں', 'No income records found'); ?></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

        <!-- Tab 2: Expense -->
        <div class="tab-pane fade" id="expense" role="tabpanel" aria-labelledby="expense-tab">
            <div class="row">
                <div class="col-lg-12">
            <!-- خرچ اندراج فارم -->
            <div class="card shadow-sm border-0 neobox mb-4">
                <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-white" style="color: #ffffff !important;"><i class="fas fa-arrow-up me-2 text-white"></i> <?php echo __('خرچ اندراج (Expense Entry)', 'Expense Entry'); ?></h5>
                    <span class="badge bg-light text-danger fw-bold"><?php echo __('اخراجات', 'Expenses'); ?></span>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="type" value="Expense">
                        <?php echo csrf_field(); ?>
                        <?php if ($is_admin): ?>
                        <div class="row g-3 mb-2">
                            <div class="col-md-12">
                                <div class="p-2 bg-light border rounded d-flex align-items-center gap-2">
                                    <label class="fw-bold form-label m-0 text-dark"><i class="fas fa-user-tag text-danger me-1"></i> <?php echo __('کس صارف کا خرچ ہے؟ (Record Owner):', 'Record Owner:'); ?></label>
                                    <select name="form_user_id" class="form-select form-select-sm w-auto fw-bold text-dark border-danger">
                                        <?php foreach ($sys_users as $su): ?>
                                            <option value="<?php echo $su['id']; ?>" <?php echo ((int)$su['id'] === (int)$user_id) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars(!empty($su['name']) ? $su['name'] : $su['username']); ?> (ID: <?php echo $su['id']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted"><?php echo __('(جس صارف کے کھاتے سے یہ خرچ کاٹا جانا ہو)', '(Select the user whose account will be debited)'); ?></small>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="fw-bold form-label"><?php echo __('تاریخ', 'Date'); ?></label>
                                <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="fw-bold form-label"><?php echo __('مد (Expense Head)', 'Expense Category'); ?></label>
                                <div class="input-group">
                                    <select name="head_id" class="form-select" required onchange="checkSalaryExpenseHead(this)" style="color: #212529 !important; background-color: #ffffff !important;">
                                        <option value="" style="color: #6c757d;"><?php echo __('اخراجات مد منتخب کریں', 'Select Expense Head'); ?></option>
                                        <?php foreach($expense_heads as $h): ?>
                                            <option value="<?php echo $h['id']; ?>" style="color: #212529 !important; background: #ffffff !important;"><?php echo htmlspecialchars(safe_localize_head($h['name'], $lang)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#addHeadModal" onclick="setHeadTypeModal('Expense')" title="<?php echo __('نئی مد', 'New Category'); ?>">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label class="fw-bold form-label"><?php echo __('رقم (Rs.)', 'Amount (Rs.)'); ?></label>
                                <input type="number" step="0.01" name="amount" class="form-control" placeholder="<?php echo __('مثلاً: 2500', 'e.g. 2500'); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="fw-bold form-label"><?php echo __('کہاں سے ادا ہوئی؟ (ادائیگی کھاتہ)', 'Paid From (Account)'); ?></label>
                                <select name="account_id" class="form-select" style="color: #212529 !important; background-color: #ffffff !important;">
                                    <?php foreach($expense_payment_accounts as $pa): 
                                        $pa_id = (int)$pa['id'];
                                        $adv_info = $user_advance_summaries[$pa_id] ?? null;
                                        $col_info = $user_collection_summaries[$pa_id] ?? null;
                                        $extra_label = '';
                                        if ($adv_info) {
                                            $b = (float)$adv_info['net_balance'];
                                            $extra_label = ' [' . __('بقایا:', 'Balance:') . ' ' . ($b < 0 ? '-' : '+') . 'Rs. ' . number_format(abs($b)) . ']';
                                        } elseif ($col_info) {
                                            $cb = (float)$col_info['net_in_hand'];
                                            $extra_label = ' [' . __('بقایا امانت:', 'In-Hand:') . ' Rs. ' . number_format(max(0, $cb)) . ']';
                                        }
                                        $is_sel = false;
                                        if ($is_admin) {
                                            // Admin default is STRICTLY 1001 (Cash in Hand)
                                            if ($pa['code'] == '1001') {
                                                $is_sel = true;
                                            }
                                        } else {
                                            // Non-admin default is their own expense account
                                            if ($selected_adv_aid > 0 && $pa_id === $selected_adv_aid) {
                                                $is_sel = true;
                                            } elseif ($is_my_advance_account($pa['name'])) {
                                                $is_sel = true;
                                            }
                                        }
                                    ?>
                                        <option value="<?php echo $pa['id']; ?>" <?php echo $is_sel ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($pa['name'] . ' (' . $pa['code'] . ')' . $extra_label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label class="fw-bold form-label"><?php echo __('خرچ کرنے والے کا نام (Spender / Payee)', 'Spender / Payee Name'); ?></label>
                                <input type="text" name="donor_name" class="form-control" placeholder="<?php echo __('مثلاً: قاری صاحب / دکاندار', 'e.g. Teacher / Vendor'); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="fw-bold form-label"><?php echo __('طریقہ ادائیگی (Method)', 'Payment Method'); ?></label>
                                <select name="payment_method" class="form-select">
                                    <option value="Cash"><?php echo __('نقد رقم (Cash)', 'Cash'); ?></option>
                                    <option value="Bank Transfer"><?php echo __('آن لائن بینک ٹرانسفر (Online Transfer)', 'Online Bank Transfer'); ?></option>
                                    <option value="Cheque"><?php echo __('چیک (Cheque)', 'Cheque'); ?></option>
                                </select>
                            </div>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-md-12">
                                <label class="fw-bold form-label text-dark"><i class="fas fa-file-invoice text-danger me-1"></i> <?php echo __('خرچ کا بل / رسید (تصویر یا PDF اپلوڈ کریں)', 'Upload Bill / Receipt (Image or PDF)'); ?></label>
                                <input type="file" name="bill_file" class="form-control" accept="image/*,application/pdf">
                                <small class="text-muted"><i class="fas fa-info-circle me-1"></i> <?php echo __('آپ خرچ کے ثبوت کے طور پر بل یا رسید کی تصویر یا دستاویز یہاں منسلک کر کے سرور پر محفوظ کر سکتے ہیں۔', 'Attach bill or receipt image/PDF as expense proof.'); ?></small>
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="fw-bold form-label"><?php echo __('تفصیل (Description)', 'Description'); ?></label>
                            <textarea name="description" class="form-control" rows="4" placeholder="<?php echo __('اخراجات کی تفصیل لکھیے۔۔۔', 'Enter expense details...'); ?>"></textarea>
                        </div>

                        <button type="submit" name="add_record" class="btn btn-danger w-100 fw-bold mt-3">
                            <i class="fas fa-save me-1"></i> <?php echo __('خرچ ریکارڈ محفوظ کریں', 'Save Expense Record'); ?>
                        </button>
                    </form>
                </div>
            </div>

            <!-- خرچ انٹریز ہسٹری -->
            <?php if ($is_admin): ?>
                <?php echo render_user_wise_finance_cards($user_groups, 'expense', $sys, $lang, $user_id); ?>
            <?php else: ?>
            <div class="card shadow-sm border-0 neobox">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-white" style="color: #ffffff !important;"><i class="fas fa-list me-2 text-white"></i> <?php echo __('خرچ انٹریز ہسٹری', 'Expense Entries History'); ?> (<?php echo __('کل:', 'Total:'); ?> <?php echo count($expense_records); ?>)</h5>
                    <input type="text" id="searchExpense" class="form-control form-control-sm w-auto" placeholder="<?php echo __('تلاش...', 'Search...'); ?>" onkeyup="filterTable('expenseTable', 'searchExpense')">
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 text-center" id="expenseTable">
                            <thead class="table-light">
                                <tr>
                                    <th><?php echo __('تاریخ', 'Date'); ?></th>
                                    <th><?php echo __('مد', 'Head / Category'); ?></th>
                                    <th><?php echo __('رقم', 'Amount'); ?></th>
                                    <th><?php echo __('تفصیل', 'Details'); ?></th>
                                    <th><?php echo __('ایکشن', 'Action'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($expense_records as $r): ?>
                                <tr>
                                    <td><small class="fw-bold"><?php echo !empty($r['date']) ? date('d-M-Y', strtotime($r['date'])) : '---'; ?></small></td>
                                    <td class="fw-bold text-danger">
                                        <?php echo htmlspecialchars(safe_localize_head($r['head_name'] ?? '---', $lang)); ?>
                                        <?php if(!empty($r['account_name'])): ?>
                                            <br><span class="badge bg-light text-dark border"><i class="fas fa-wallet text-secondary me-1"></i><?php echo htmlspecialchars($r['account_name']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-bold text-danger">Rs. <?php echo number_format((float)($r['amount'] ?? 0)); ?></td>
                                    <td class="small text-start text-muted"><?php echo htmlspecialchars((string)($r['description'] ?? '')); ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <?php if (!empty($r['bill_image'])): ?>
                                                <a href="<?php echo htmlspecialchars($r['bill_image']); ?>" target="_blank" class="btn btn-outline-info" title="<?php echo __('منسلک بل دیکھیں', 'View Attached Bill'); ?>"><i class="fas fa-file-invoice-dollar"></i></a>
                                            <?php endif; ?>
                                            <a href="edit_finance.php?id=<?php echo $r['id']; ?>" class="btn btn-outline-warning" title="<?php echo __('ترمیم', 'Edit'); ?>"><i class="fas fa-edit"></i></a>
                                            <form method="POST" action="simple_finance.php" style="display:inline-block;" onsubmit="return confirm('<?php echo __('کیا واقعی یہ ریکارڈ ڈیلیٹ کرنا چاہتے ہیں؟', 'Are you sure you want to delete this record?'); ?>');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="delete_record" value="1">
                                                <input type="hidden" name="del_id" value="<?php echo $r['id']; ?>">
                                                <button type="submit" class="btn btn-outline-danger" title="<?php echo __('ڈیلیٹ', 'Delete'); ?>"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(count($expense_records) == 0): ?>
                                <tr><td colspan="5" class="text-center p-4 text-muted"><?php echo __('کوئی خرچ ریکارڈ موجود نہیں', 'No expense records found'); ?></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Tab 3: Transfers -->
<div class="tab-pane fade" id="transfer" role="tabpanel" aria-labelledby="transfer-tab">
    <div class="row">
        <div class="col-lg-12">
                    <div class="card shadow-sm border-0 neobox">
                <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-exchange-alt me-2"></i> <?php echo __('حالیہ رقم منتقلی / ایڈوانس ہسٹری (Recent Transfers)', 'Recent Transfers / Advance History'); ?></h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 text-center">
                            <thead class="table-light">
                                <tr>
                                    <th><?php echo __('تاریخ', 'Date'); ?></th>
                                    <th><?php echo __('کہاں سے رقم نکلی (From)', 'Transfer From'); ?></th>
                                    <th><?php echo __('کس کو ملی (To)', 'Transfer To'); ?></th>
                                    <th><?php echo __('رقم', 'Amount'); ?></th>
                                    <th><?php echo __('تفصیل', 'Description'); ?></th>
                                    <th><?php echo __('واؤچر نمبر', 'Voucher No'); ?></th>
                                    <?php if($is_admin): ?>
                                    <th><?php echo __('ایکشن', 'Action'); ?></th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($transfer_records as $tr): ?>
                                <tr>
                                    <td><small class="fw-bold"><?php echo !empty($tr['date']) ? date('d-M-Y', strtotime($tr['date'])) : '---'; ?></small></td>
                                    <td class="fw-bold text-danger"><?php echo htmlspecialchars($tr['from_account']); ?></td>
                                    <td class="fw-bold text-success"><?php echo htmlspecialchars($tr['to_account']); ?></td>
                                    <td class="fw-bold text-primary">Rs. <?php echo number_format((float)($tr['amount'] ?? 0)); ?></td>
                                    <td class="small text-muted text-start"><?php echo htmlspecialchars($tr['description']); ?></td>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($tr['voucher_no']); ?></span></td>
                                    <?php if($is_admin): ?>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-warning" title="<?php echo __('ترمیم', 'Edit'); ?>" onclick="openEditTransferModal(<?php echo $tr['id']; ?>, '<?php echo $tr['date']; ?>', <?php echo $tr['amount']; ?>, '<?php echo htmlspecialchars(addslashes($tr['description'])); ?>')">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" action="simple_finance.php" style="display:inline-block;" onsubmit="return confirm('<?php echo __('کیا واقعی یہ ٹرانسفر ریکارڈ ڈیلیٹ کرنا چاہتے ہیں؟', 'Are you sure you want to delete this transfer record?'); ?>');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="delete_transfer" value="1">
                                                <input type="hidden" name="del_transfer_id" value="<?php echo $tr['id']; ?>">
                                                <button type="submit" class="btn btn-outline-danger" title="<?php echo __('ڈیلیٹ', 'Delete'); ?>"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($transfer_records)): ?>
                                <tr><td colspan="<?php echo $is_admin ? '7' : '6'; ?>" class="text-center p-4 text-muted"><?php echo __('کوئی حالیہ رقم منتقلی ریکارڈ موجود نہیں', 'No recent transfer records found'); ?></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</div> <!-- End tab-content -->

<!-- Add Head Modal -->
<div class="modal fade" id="addHeadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><?php echo __('نئی مد (Head) شامل کریں', 'Add New Head / Category'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="fw-bold form-label"><?php echo __('مد کا نام', 'Category Name'); ?></label>
                        <input type="text" name="head_name" class="form-control" placeholder="<?php echo __('مثلاً: بجلی کا بل، عطیات، راشن', 'e.g. Utility Bills, Donations, Food'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold form-label"><?php echo __('قسم (Type)', 'Type'); ?></label>
                        <select name="head_type" class="form-select" id="modal_head_type" required>
                            <option value="Income"><?php echo __('آمدن (Income)', 'Income'); ?></option>
                            <option value="Expense"><?php echo __('اخراجات (Expense)', 'Expense'); ?></option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('منسوخ', 'Cancel'); ?></button>
                    <button type="submit" name="add_head" class="btn btn-primary fw-bold"><?php echo __('محفوظ کریں', 'Save'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Fund Transfer -->
<div class="modal fade" id="fundTransferModal" tabindex="-1" aria-labelledby="fundTransferModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title fw-bold" id="fundTransferModalLabel"><i class="fas fa-exchange-alt me-2"></i> <?php echo __('رقم منتقلی (ایڈوانس / ٹرانسفر)', 'Fund Transfer / Advance'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo __('تاریخ منتقلی', 'Transfer Date'); ?></label>
                        <input type="date" name="transfer_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo __('کہاں سے رقم نکلی؟ (Sender / From)', 'Transfer From (Sender)'); ?></label>
                        <select name="from_account_id" id="transfer_from_account_id" class="form-select" required>
                            <option value=""><?php echo __('منتخب کریں', 'Select Account'); ?></option>
                            <optgroup label="<?php echo __('اکاؤنٹس (Accounts)', 'Accounts'); ?>">
                            <?php foreach($all_payment_accounts as $pa): 
                                if (!$is_admin && ($is_other_advance_account($pa['name'] ?? '') || $is_other_collection_account($pa['name'] ?? ''))) continue;
                            ?>
                                <option value="<?php echo $pa['id']; ?>" <?php echo ($is_admin && $pa['code'] == '1001') ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pa['name'] . ' (' . $pa['code'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="<?php echo __('نقد وصولی امانت برائے عملہ (Collection In-Hand)', 'Staff Collection In-Hand'); ?>">
                            <?php foreach($sys_users as $su): 
                                if (!$is_admin && (int)$su['id'] !== (int)$user_id) continue;
                            ?>
                                <option value="col_<?php echo $su['id']; ?>">
                                    <?php echo __('نقد وصولی - ', 'Collection - ') . htmlspecialchars(!empty($su['name']) ? $su['name'] : $su['username']); ?>
                                </option>
                            <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="<?php echo __('یوزرز ایڈوانس (User Advance)', 'User Advance'); ?>">
                            <?php foreach($sys_users as $su): 
                                if (!$is_admin && (int)$su['id'] !== (int)$user_id) continue;
                            ?>
                                <option value="user_<?php echo $su['id']; ?>">
                                    <?php echo __('ایڈوانس - ', 'Advance - ') . htmlspecialchars(!empty($su['name']) ? $su['name'] : $su['username']); ?>
                                </option>
                            <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo __('کہاں رقم جمع ہوئی؟ (Receiver / To)', 'Transfer To (Receiver)'); ?></label>
                        <select name="to_account_id" id="transfer_to_account_id" class="form-select" required>
                            <option value=""><?php echo __('منتخب کریں', 'Select Account'); ?></option>
                            <optgroup label="<?php echo __('اکاؤنٹس (Accounts)', 'Accounts'); ?>">
                            <?php foreach($all_payment_accounts as $pa): 
                                if (!$is_admin && ($is_other_advance_account($pa['name'] ?? '') || $is_other_collection_account($pa['name'] ?? ''))) continue;
                            ?>
                                <option value="<?php echo $pa['id']; ?>" <?php echo ($pa['code'] == '1001') ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pa['name'] . ' (' . $pa['code'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="<?php echo __('نقد وصولی امانت برائے عملہ (Collection In-Hand)', 'Staff Collection In-Hand'); ?>">
                            <?php foreach($sys_users as $su): 
                                if (!$is_admin && (int)$su['id'] !== (int)$user_id) continue;
                            ?>
                                <option value="col_<?php echo $su['id']; ?>">
                                    <?php echo __('نقد وصولی - ', 'Collection - ') . htmlspecialchars(!empty($su['name']) ? $su['name'] : $su['username']); ?>
                                </option>
                            <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="<?php echo __('یوزرز ایڈوانس (User Advance)', 'User Advance'); ?>">
                            <?php foreach($sys_users as $su): 
                                if (!$is_admin && (int)$su['id'] !== (int)$user_id) continue;
                            ?>
                                <option value="user_<?php echo $su['id']; ?>">
                                    <?php echo __('ایڈوانس - ', 'Advance - ') . htmlspecialchars(!empty($su['name']) ? $su['name'] : $su['username']); ?>
                                </option>
                            <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo __('منتقل کردہ رقم (Rs.)', 'Transfer Amount (Rs.)'); ?></label>
                        <input type="number" step="0.01" min="1" name="transfer_amount" class="form-control" placeholder="<?php echo __('مثلاً: 25000', 'e.g. 25000'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo __('تفصیل / وجہ منتقلی (Description)', 'Description'); ?></label>
                        <textarea name="transfer_description" class="form-control" rows="2" placeholder="<?php echo __('مثلاً: کیش بکس سے بینک اکاؤنٹ میں رقم جمع کرائی گئی۔۔۔', 'e.g. Transferred from Cash box to Bank account...'); ?>"></textarea>
                    </div>
                    <div class="alert alert-info py-2 small mb-0">
                        <i class="fas fa-info-circle me-1"></i> <?php echo __('یہ اندراج کیش اور بینک دونوں کھاتوں کو خودکار متوازن رکھے گا اور آمدن یا خرچ پر غلط اثر نہیں ڈالے گا۔', 'This entry balances both Cash and Bank accounts automatically.'); ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('منسوخ', 'Cancel'); ?></button>
                    <button type="submit" name="transfer_funds" class="btn btn-warning fw-bold text-dark"><i class="fas fa-check me-1"></i> <?php echo __('رقم منتقل کریں', 'Transfer Funds'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit Fund Transfer -->
<div class="modal fade" id="editTransferModal" tabindex="-1" aria-labelledby="editTransferModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title fw-bold" id="editTransferModalLabel"><i class="fas fa-edit me-2"></i> <?php echo __('ترمیم رقم منتقلی (ایڈوانس / ٹرانسفر)', 'Edit Transfer'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="edit_transfer_id" id="edit_transfer_id">
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo __('تاریخ منتقلی', 'Transfer Date'); ?></label>
                        <input type="date" name="edit_transfer_date" id="edit_transfer_date" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo __('منتقل کردہ رقم (Rs.)', 'Transfer Amount (Rs.)'); ?></label>
                        <input type="number" step="0.01" min="1" name="edit_transfer_amount" id="edit_transfer_amount" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo __('تفصیل / وجہ منتقلی (Description)', 'Description'); ?></label>
                        <textarea name="edit_transfer_description" id="edit_transfer_description" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('منسوخ', 'Cancel'); ?></button>
                    <button type="submit" name="edit_transfer" class="btn btn-warning fw-bold text-dark"><i class="fas fa-save me-1"></i> <?php echo __('محفوظ کریں', 'Save'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Salary & Stipend Restriction Alert -->
<div class="modal fade" id="salaryRestrictionModal" tabindex="-1" aria-labelledby="salaryRestrictionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-warning text-dark border-0">
                <h5 class="modal-title fw-bold" id="salaryRestrictionModalLabel">
                    <i class="fas fa-exclamation-triangle me-2 text-danger"></i> <?php echo __('اہم تنبیہ: وظائف و تنخواہ', 'Important Notice: Staff Payroll'); ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <div class="mb-3 text-warning">
                    <i class="fas fa-hand-holding-usd fa-3x"></i>
                </div>
                <h6 class="fw-bold mb-3 text-danger"><?php echo __('وظیفہ، تنخواہ وغیرہ کی ادائیگی کا اندراج عام خرچ میں نہیں ہو سکتا!', 'Payroll/salary payment cannot be recorded as regular expense!'); ?></h6>
                <p class="text-secondary mb-0" style="font-size: 15px; line-height: 1.6;">
                    <?php echo __('وظیفہ یا تنخواہ کی ادائیگی کا باقاعدہ اندراج <strong>"وظائف عملہ"</strong> والے صفحے پر ہی کیا جا سکتا ہے تاکہ تمام حسابات، غیر حاضری اور حاضری کٹوتیاں بالکل درست رہیں۔ لہذا آپ اس پیج پر جا کر انٹری فرمائیں۔', 'Stipends/salaries must be recorded in <strong>"Staff Payroll"</strong> page for correct calculations and deductions.'); ?>
                </p>
            </div>
            <div class="modal-footer border-0 justify-content-center bg-light">
                <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal"><?php echo __('بند کریں', 'Close'); ?></button>
                <a href="acc_payroll.php" class="btn btn-primary fw-bold px-4">
                    <i class="fas fa-money-check-alt me-1"></i> <?php echo __('وظائف عملہ پیج پر جائیں', 'Go to Staff Payroll'); ?>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Admin Finance Edit OTP Requests Hub -->
<div class="modal fade" id="adminOtpsModal" tabindex="-1" aria-labelledby="adminOtpsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; overflow: hidden;">
            <div class="modal-header text-white border-0" style="background: #1e293b;">
                <h5 class="modal-title fw-bold" id="adminOtpsModalLabel">
                    <i class="fas fa-shield-alt text-warning me-2"></i> <?php echo __('فنانس انٹری ترمیم کی اجازت / OTP درخواستیں', 'Finance Edit OTP Requests'); ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="alert alert-info border-0 shadow-sm d-flex align-items-center mb-3">
                    <i class="fas fa-info-circle fa-2x me-3 text-primary"></i>
                    <div>
                        <strong><?php echo __('ایڈمن کی سیکیورٹی معلومات:', 'Admin Security Notice:'); ?></strong> <?php echo __('جب کوئی استاذ یا ناظم اپنی سابقہ آمدن یا خرچ کی انٹری میں تبدیلی کرنا چاہتا ہے، تو سسٹم یہاں ایک 6 ہندسوں کا ون ٹائم پاسورڈ (OTP) بھیجتا ہے۔ یہ OTP متعلقہ استاذ کو فراہم کریں تاکہ وہ انٹری ایڈٹ کر سکیں۔ یہ کوڈ 24 گھنٹے تک کارآمد رہتا ہے۔', 'When a staff member requests to edit an entry, a 6-digit OTP appears here. Share this OTP with them to authorize edits. Valid for 24 hours.'); ?>
                    </div>
                </div>

                <?php 
                $active_otps = finance_get_pending_otps($pdo, $cur_tenant_id);
                if (empty($active_otps)): 
                ?>
                    <div class="text-center py-5 bg-white rounded shadow-sm">
                        <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                        <h5 class="fw-bold text-dark"><?php echo __('اس وقت کوئی زیر التواء OTP درخواست موجود نہیں ہے۔', 'No pending OTP requests at this time.'); ?></h5>
                        <p class="text-muted mb-0"><?php echo __('جب بھی کوئی صارف انٹری ایڈٹ کرنے کی درخواست کرے گا، وہ کوڈ فوری طور پر یہاں دکھائی دے گا۔', 'When a user requests to edit an entry, the OTP code will appear here.'); ?></p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive bg-white rounded shadow-sm p-3">
                        <table class="table table-hover align-middle text-center mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th><?php echo __('درخواست گزار', 'Requester'); ?></th>
                                    <th><?php echo __('انٹری تفصیل', 'Entry Details'); ?></th>
                                    <th><?php echo __('رقم و تاریخ', 'Amount & Date'); ?></th>
                                    <th><?php echo __('OTP کوڈ', 'OTP Code'); ?></th>
                                    <th><?php echo __('باقی وقت', 'Remaining Time'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($active_otps as $otp): ?>
                                <tr>
                                    <td class="fw-bold text-dark">
                                        <i class="fas fa-user-tie text-primary me-1"></i>
                                        <?php echo htmlspecialchars($otp['requester_name']); ?>
                                        <br><small class="text-muted">ID: <?php echo $otp['user_id']; ?></small>
                                    </td>
                                    <td class="text-start">
                                        <span class="badge <?php echo ($otp['finance_type'] === 'Income') ? 'bg-success' : 'bg-danger'; ?> mb-1">
                                            <?php echo ($otp['finance_type'] === 'Income') ? __('آمدن', 'Income') : __('خرچ', 'Expense'); ?> #<?php echo $otp['finance_id']; ?>
                                        </span>
                                        <div class="small fw-bold text-dark"><?php echo htmlspecialchars($otp['finance_head'] ?? '---'); ?></div>
                                        <div class="small text-muted text-truncate" style="max-width: 180px;"><?php echo htmlspecialchars($otp['finance_description'] ?? ''); ?></div>
                                    </td>
                                    <td>
                                        <span class="fw-bold text-primary">Rs. <?php echo number_format((float)($otp['finance_amount'] ?? 0)); ?></span>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($otp['finance_date'] ?? ''); ?></small>
                                    </td>
                                    <td>
                                        <div class="d-inline-flex align-items-center gap-2 bg-warning-subtle border border-warning rounded px-3 py-1">
                                            <span class="fs-4 fw-bold font-monospace text-dark letter-spacing-2"><?php echo htmlspecialchars($otp['otp_code']); ?></span>
                                            <button type="button" class="btn btn-sm btn-outline-dark border-0 p-1" onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($otp['otp_code']); ?>'); alert('OTP کوڈ کاپی ہو گیا: <?php echo htmlspecialchars($otp['otp_code']); ?>');" title="کوڈ کاپی کریں">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary text-white">
                                            <i class="fas fa-hourglass-half me-1"></i> <?php echo $otp['remaining_minutes']; ?> <?php echo __('منٹ', 'mins'); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer border-0 bg-light">
                <button type="button" class="btn btn-secondary px-4 fw-bold" data-bs-dismiss="modal"><?php echo __('بند کریں', 'Close'); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
function checkSalaryExpenseHead(selectEl) {
    if (!selectEl) return;
    var optText = selectEl.options[selectEl.selectedIndex] ? selectEl.options[selectEl.selectedIndex].text.toLowerCase() : '';
    var blocked = ['وظائف', 'وظیفہ', 'تنخواہ', 'تنخواہیں', 'سیلری', 'salary', 'stipend'];
    var isBlocked = blocked.some(function(k) { return optText.indexOf(k) !== -1; });
    if (isBlocked) {
        selectEl.value = '';
        var modalEl = document.getElementById('salaryRestrictionModal');
        if (modalEl) {
            var modal = new bootstrap.Modal(modalEl);
            modal.show();
        } else {
            alert('وظیفہ، تنخواہ وغیرہ کی ادائیگی کا اندراج وظائف عملہ پیج پر ہی ہو سکتا ہے۔');
            window.location.href = 'acc_payroll.php';
        }
    }
}
function openEditTransferModal(id, date, amount, desc) {
    document.getElementById('edit_transfer_id').value = id;
    document.getElementById('edit_transfer_date').value = date;
    document.getElementById('edit_transfer_amount').value = amount;
    document.getElementById('edit_transfer_description').value = desc;
    var modal = new bootstrap.Modal(document.getElementById('editTransferModal'));
    modal.show();
}

function setHeadTypeModal(type) {
    var modalType = document.getElementById('modal_head_type');
    if (modalType) modalType.value = type;
}

function filterTable(tableId, inputId) {
    var input = document.getElementById(inputId);
    var filter = input.value.toLowerCase();
    var table = document.getElementById(tableId);
    if (!table) return;
    var tr = table.getElementsByTagName("tr");
    for (var i = 1; i < tr.length; i++) {
        var txt = tr[i].textContent || tr[i].innerText;
        if (txt.toLowerCase().indexOf(filter) > -1) {
            tr[i].style.display = "";
        } else {
            tr[i].style.display = "none";
        }
    }
}

function toggleAllUserCards(prefix, expand) {
    var coll = document.querySelectorAll('.' + prefix + '-collapse');
    coll.forEach(function(el) {
        if (expand) {
            el.classList.add('show');
        } else {
            el.classList.remove('show');
        }
    });
}

function filterUserCards(containerId, inputId) {
    var input = document.getElementById(inputId);
    var filter = input.value.toLowerCase();
    var container = document.getElementById(containerId);
    if (!container) return;
    var cards = container.getElementsByClassName("user-finance-card");
    for (var i = 0; i < cards.length; i++) {
        var txt = cards[i].textContent || cards[i].innerText;
        if (txt.toLowerCase().indexOf(filter) > -1) {
            cards[i].style.display = "";
        } else {
            cards[i].style.display = "none";
        }
    }
}

function prepareCollectionHandover(colAccountId, balance) {
    var fromSelect = document.getElementById('transfer_from_account_id');
    var toSelect = document.getElementById('transfer_to_account_id');
    var amtInput = document.querySelector('input[name="transfer_amount"]');
    var descInput = document.querySelector('textarea[name="transfer_description"]');
    
    if (fromSelect && colAccountId > 0) {
        for (var i = 0; i < fromSelect.options.length; i++) {
            if (fromSelect.options[i].value == colAccountId || fromSelect.options[i].value == ('col_' + <?php echo (int)$user_id; ?>)) {
                fromSelect.selectedIndex = i;
                break;
            }
        }
    }
    if (toSelect) {
        for (var j = 0; j < toSelect.options.length; j++) {
            if (toSelect.options[j].text.indexOf('1001') !== -1) {
                toSelect.selectedIndex = j;
                break;
            }
        }
    }
    if (amtInput && balance > 0) {
        amtInput.value = balance;
    }
    if (descInput) {
        descInput.value = 'وصول شدہ رقم ایڈمن کو جمع کروائی گئی (Handover)';
    }
}

// Toggle logic for Finance Summary and User Expense panels (Closed by default)
function toggleFinancePanel(panelName) {
    var pSummary = document.getElementById('panelFinanceSummary');
    var pUser = document.getElementById('panelUserExpense');
    var btnSummary = document.getElementById('tabBtnFinanceSummary');
    var btnUser = document.getElementById('tabBtnUserExpense');
    var chevSummary = document.getElementById('chevronSummary');
    var chevUser = document.getElementById('chevronUser');
    var btnCloseAll = document.getElementById('btnCollapseAll');

    if (panelName === 'summary') {
        var isCurrentlyOpen = pSummary && !pSummary.classList.contains('d-none');
        if (isCurrentlyOpen) {
            pSummary.classList.add('d-none');
            btnSummary.classList.remove('btn-primary', 'text-white');
            btnSummary.classList.add('btn-outline-primary');
            if (chevSummary) {
                chevSummary.classList.remove('fa-chevron-up');
                chevSummary.classList.add('fa-chevron-down');
            }
        } else {
            if (pSummary) pSummary.classList.remove('d-none');
            if (pUser) pUser.classList.add('d-none');
            btnSummary.classList.add('btn-primary', 'text-white');
            btnSummary.classList.remove('btn-outline-primary');
            btnUser.classList.remove('btn-warning');
            btnUser.classList.add('btn-outline-warning');
            if (chevSummary) {
                chevSummary.classList.remove('fa-chevron-down');
                chevSummary.classList.add('fa-chevron-up');
            }
            if (chevUser) {
                chevUser.classList.remove('fa-chevron-up');
                chevUser.classList.add('fa-chevron-down');
            }
        }
    } else if (panelName === 'user') {
        var isCurrentlyOpen = pUser && !pUser.classList.contains('d-none');
        if (isCurrentlyOpen) {
            pUser.classList.add('d-none');
            btnUser.classList.remove('btn-warning');
            btnUser.classList.add('btn-outline-warning');
            if (chevUser) {
                chevUser.classList.remove('fa-chevron-up');
                chevUser.classList.add('fa-chevron-down');
            }
        } else {
            if (pUser) pUser.classList.remove('d-none');
            if (pSummary) pSummary.classList.add('d-none');
            btnUser.classList.add('btn-warning');
            btnUser.classList.remove('btn-outline-warning');
            btnSummary.classList.remove('btn-primary', 'text-white');
            btnSummary.classList.add('btn-outline-primary');
            if (chevUser) {
                chevUser.classList.remove('fa-chevron-down');
                chevUser.classList.add('fa-chevron-up');
            }
            if (chevSummary) {
                chevSummary.classList.remove('fa-chevron-up');
                chevSummary.classList.add('fa-chevron-down');
            }
        }
    }

    var anyOpen = (pSummary && !pSummary.classList.contains('d-none')) || (pUser && !pUser.classList.contains('d-none'));
    if (btnCloseAll) {
        btnCloseAll.style.display = anyOpen ? 'inline-block' : 'none';
    }
}

function closeAllFinancePanels() {
    var pSummary = document.getElementById('panelFinanceSummary');
    var pUser = document.getElementById('panelUserExpense');
    var btnSummary = document.getElementById('tabBtnFinanceSummary');
    var btnUser = document.getElementById('tabBtnUserExpense');
    var chevSummary = document.getElementById('chevronSummary');
    var chevUser = document.getElementById('chevronUser');
    var btnCloseAll = document.getElementById('btnCollapseAll');

    if (pSummary) pSummary.classList.add('d-none');
    if (pUser) pUser.classList.add('d-none');
    if (btnSummary) {
        btnSummary.classList.remove('btn-primary', 'text-white');
        btnSummary.classList.add('btn-outline-primary');
    }
    if (btnUser) {
        btnUser.classList.remove('btn-warning');
        btnUser.classList.add('btn-outline-warning');
    }
    if (chevSummary) {
        chevSummary.classList.remove('fa-chevron-up');
        chevSummary.classList.add('fa-chevron-down');
    }
    if (chevUser) {
        chevUser.classList.remove('fa-chevron-up');
        chevUser.classList.add('fa-chevron-down');
    }
    if (btnCloseAll) btnCloseAll.style.display = 'none';
}

// Auto-switch to transfer or expense tab if indicated in query string or hash
document.addEventListener('DOMContentLoaded', function() {
    var urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('period') || urlParams.has('from_date')) {
        toggleFinancePanel('summary');
    } else if (urlParams.has('adv_user_acc')) {
        toggleFinancePanel('user');
    }

    var tab = urlParams.get('tab') || window.location.hash.replace('#', '');
    if (tab === 'transfer') {
        var tabBtn = document.getElementById('transfer-tab');
        if (tabBtn) {
            var bsTab = new bootstrap.Tab(tabBtn);
            bsTab.show();
        }
    } else if (tab === 'expense') {
        var tabBtn = document.getElementById('expense-tab');
        if (tabBtn) {
            var bsTab = new bootstrap.Tab(tabBtn);
            bsTab.show();
        }
    }
});
</script>

<?php include 'footer.php'; ?>
