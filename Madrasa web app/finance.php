<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';
require_once 'finance_accounting_bridge.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$u_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin = in_array($u_role, ['admin', 'superadmin', 'super_admin', 'administrator'], true);
if (!$is_admin && !app_has_perm('manage_erp') && !app_has_perm('manage_finance')) {
    header("Location: dashboard.php");
    exit;
}
$role = $_SESSION['role'] ?? '';
$user = ['id' => $_SESSION['user_id'], 'role' => $role];

finance_accounting_backfill($pdo);

$cur_tenant_id = (int)get_current_tenant_id();
$sys = $_SESSION['system_type'] ?? 'Madrasa';
$sys_cond = ($sys === 'Masjid') ? "system_type = 'Masjid'" : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

// Master Finance Totals (Accurate & Synced with Finance Ledger)
$total_income = (float)($pdo->query("SELECT SUM(amount) FROM finance WHERE type = 'Income' AND tenant_id = $cur_tenant_id AND $sys_cond AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchColumn() ?? 0);
$total_expense = (float)($pdo->query("SELECT SUM(amount) FROM finance WHERE type = 'Expense' AND tenant_id = $cur_tenant_id AND $sys_cond AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchColumn() ?? 0);
$net_balance = $total_income - $total_expense;

$summary = [
    'assets' => $net_balance,
    'liabilities' => 0,
    'equity' => 0,
    'revenue' => $total_income,
    'expense' => $total_expense
];

// Cash/Bank Balances
$cash_bank = [
    ['name' => 'Cash in Hand (نقد رقم)', 'bal' => $net_balance]
];

$v_sys_cond = ($sys === 'Masjid') ? "v.system_type = 'Masjid'" : "(v.system_type = 'Madrasa' OR v.system_type IS NULL OR v.system_type = '')";
$recent_vouchers = $pdo->query("
    SELECT v.*, u.name as creator 
    FROM acc_vouchers v 
    LEFT JOIN admins u ON v.created_by = u.id 
    WHERE v.tenant_id = $cur_tenant_id AND $v_sys_cond AND (v.is_deleted = 0 OR v.is_deleted IS NULL)
    ORDER BY v.id DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$title = "Finance Dashboard - ERP";
include 'header.php';
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-primary"><i class="fas fa-wallet me-2"></i> <?php echo t('erp_accounts'); ?></h3>
        <div>
            <a href="acc_vouchers.php" class="btn btn-success fw-bold"><i class="fas fa-plus me-1"></i> <?php echo t('new_voucher'); ?></a>
            <a href="acc_ledger.php" class="btn btn-outline-primary fw-bold"><i class="fas fa-book me-1"></i> <?php echo t('view_ledger'); ?></a>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card bg-gradient-blue shadow">
                <i class="fas fa-money-bill-wave stat-icon"></i>
                <div class="stat-value">Rs. <?php echo number_format($summary['revenue'], 0); ?></div>
                <div class="stat-label"><?php echo t('total_revenue'); ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card bg-gradient-red shadow">
                <i class="fas fa-chart-line stat-icon"></i>
                <div class="stat-value">Rs. <?php echo number_format($summary['expense'], 0); ?></div>
                <div class="stat-label"><?php echo t('total_expenses'); ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card bg-gradient-green shadow">
                <i class="fas fa-balance-scale stat-icon"></i>
                <div class="stat-value">Rs. <?php echo number_format($summary['assets'], 0); ?></div>
                <div class="stat-label"><?php echo t('total_assets'); ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card bg-gradient-gold shadow">
                <i class="fas fa-hand-holding-usd stat-icon"></i>
                <div class="stat-value">Rs. <?php echo number_format($summary['revenue'] - $summary['expense'], 0); ?></div>
                <div class="stat-label"><?php echo t('net_income'); ?></div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-4">
            <div class="card shadow-sm border-0 neobox h-100">
                <h5 class="fw-bold text-secondary mb-3 border-bottom pb-2"><i class="fas fa-university me-2"></i> <?php echo t('cash_bank_balances'); ?></h5>
                <?php if(empty($cash_bank)): ?>
                    <p class="text-muted"><?php echo t('no_cash_bank_accounts_found'); ?></p>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach($cash_bank as $cb): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span class="fw-bold"><i class="fas fa-money-check-alt text-success me-2"></i> <?php echo e($cb['name']); ?></span>
                            <span class="badge bg-primary rounded-pill fs-6">Rs. <?php echo number_format($cb['bal']??0, 2); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-8">
            <div class="card shadow-sm border-0 neobox h-100">
                <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2">
                    <h5 class="fw-bold text-secondary mb-0"><i class="fas fa-history me-2"></i> <?php echo t('recent_vouchers'); ?></h5>
                    <a href="acc_vouchers.php" class="btn btn-sm btn-outline-secondary"><?php echo t('view_all'); ?></a>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th><?php echo t('date'); ?></th>
                                <th><?php echo t('voucher_no'); ?></th>
                                <th><?php echo t('type'); ?></th>
                                <th><?php echo t('creator'); ?></th>
                                <th><?php echo t('status'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($recent_vouchers as $v): ?>
                            <tr>
                                <td class="small"><?php echo date('d-m-Y', strtotime($v['date'])); ?></td>
                                <td class="fw-bold text-primary"><?php echo e($v['voucher_no']); ?></td>
                                <td><span class="badge bg-secondary"><?php echo e($v['type']); ?></span></td>
                                <td class="small"><?php echo e($v['creator']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $v['status']=='Approved'?'success':($v['status']=='Rejected'?'danger':'warning'); ?>">
                                        <?php echo e($v['status']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($recent_vouchers)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3"><?php echo t('no_vouchers_yet'); ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
