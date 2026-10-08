<?php
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

$msg = '';

$cur_tenant_id = get_current_tenant_id($pdo);

// Handle Add/Edit/Resequence
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'resequence') {
        if (function_exists('resequence_chart_of_accounts')) {
            resequence_chart_of_accounts($pdo, $cur_tenant_id);
        }
        sync_all_heads_with_chart_and_departments($pdo, $cur_tenant_id);
        $msg = "<div class='alert alert-success'><i class='fas fa-check-circle me-2'></i> تمام کھاتوں کے کوڈز کو کامیابی کے ساتھ 1000، 2000، 3000، 4000 اور 5000 کی سیریز میں ترتیب دے دیا گیا۔</div>";
    } else if ($_POST['action'] == 'add_account' || $_POST['action'] == 'edit_account') {
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $type = trim($_POST['type'] ?? '');
        $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if (empty($code) && !empty($type) && function_exists('get_next_chart_code')) {
            $code = get_next_chart_code($pdo, $type, $cur_tenant_id);
        }
        
        if ($code && $name && $type) {
            if ($_POST['action'] == 'add_account') {
                $stmt = $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, parent_id, is_active, tenant_id) VALUES (?, ?, ?, ?, ?, ?)");
                if ($stmt->execute([$code, $name, $type, $parent_id, $is_active, $cur_tenant_id])) {
                    if (function_exists('resequence_chart_of_accounts')) {
                        resequence_chart_of_accounts($pdo, $cur_tenant_id);
                    }
                    sync_all_heads_with_chart_and_departments($pdo, $cur_tenant_id);
                    $msg = "<div class='alert alert-success'>اکاؤنٹ کامیابی سے شامل کر دیا گیا۔ (Account added successfully)</div>";
                } else {
                    $msg = "<div class='alert alert-danger'>اکاؤنٹ شامل نہیں ہو سکا۔ کوڈ پہلے سے موجود ہو سکتا ہے۔</div>";
                }
            } else if ($_POST['action'] == 'edit_account' && isset($_POST['id'])) {
                $id = (int)$_POST['id'];
                $stmt = $pdo->prepare("UPDATE acc_chart_of_accounts SET code=?, name=?, type=?, parent_id=?, is_active=? WHERE id=? AND tenant_id=?");
                if ($stmt->execute([$code, $name, $type, $parent_id, $is_active, $id, $cur_tenant_id])) {
                    if (function_exists('resequence_chart_of_accounts')) {
                        resequence_chart_of_accounts($pdo, $cur_tenant_id);
                    }
                    sync_all_heads_with_chart_and_departments($pdo, $cur_tenant_id);
                    $msg = "<div class='alert alert-success'>اکاؤنٹ کامیابی سے اپ ڈیٹ ہو گیا۔ (Account updated successfully)</div>";
                } else {
                    $msg = "<div class='alert alert-danger'>اکاؤنٹ اپ ڈیٹ نہیں ہو سکا۔</div>";
                }
            }
        } else {
            $msg = "<div class='alert alert-warning'>براہ کرم تمام لازمی خانے پر کریں۔</div>";
        }
    } else if ($_POST['action'] == 'delete_account' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("UPDATE acc_chart_of_accounts SET is_deleted=1 WHERE id=? AND tenant_id=?");
        if ($stmt->execute([$id, $cur_tenant_id])) {
            sync_all_heads_with_chart_and_departments($pdo, $cur_tenant_id);
            $msg = "<div class='alert alert-success'>اکاؤنٹ حذف ہو گیا۔ (Account deleted successfully)</div>";
        }
    }
}

try {
    sync_all_heads_with_chart_and_departments($pdo, $cur_tenant_id);
    $acc_stmt = $pdo->prepare("SELECT a.*, p.name as parent_name FROM acc_chart_of_accounts a LEFT JOIN acc_chart_of_accounts p ON a.parent_id = p.id WHERE (a.is_deleted = 0 OR a.is_deleted IS NULL) AND a.tenant_id = ? ORDER BY CAST(a.code AS UNSIGNED) ASC, a.code ASC");
    $acc_stmt->execute([$cur_tenant_id]);
    $accounts = $acc_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $msg = "<div class='alert alert-danger'>Database Error: Table acc_chart_of_accounts might be missing. Please run database migrations. (" . htmlspecialchars($e->getMessage()) . ")</div>";
    $accounts = [];
}

// Categorize accounts
$rev_accounts = [];
$exp_accounts = [];
$asset_accounts = [];
$liab_accounts = [];
$eq_accounts = [];

foreach ($accounts as $acc) {
    $t = $acc['type'] ?? '';
    if ($t === 'Revenue' || $t === 'Income') {
        $rev_accounts[] = $acc;
    } elseif ($t === 'Expense') {
        $exp_accounts[] = $acc;
    } elseif ($t === 'Asset') {
        $asset_accounts[] = $acc;
    } elseif ($t === 'Liability') {
        $liab_accounts[] = $acc;
    } elseif ($t === 'Equity') {
        $eq_accounts[] = $acc;
    }
}

$next_codes = [
    'Asset' => function_exists('get_next_chart_code') ? get_next_chart_code($pdo, 'Asset', $cur_tenant_id) : '1001',
    'Liability' => function_exists('get_next_chart_code') ? get_next_chart_code($pdo, 'Liability', $cur_tenant_id) : '2001',
    'Equity' => function_exists('get_next_chart_code') ? get_next_chart_code($pdo, 'Equity', $cur_tenant_id) : '3001',
    'Revenue' => function_exists('get_next_chart_code') ? get_next_chart_code($pdo, 'Revenue', $cur_tenant_id) : '4001',
    'Expense' => function_exists('get_next_chart_code') ? get_next_chart_code($pdo, 'Expense', $cur_tenant_id) : '5001',
];

$is_urdu = ($lang !== 'en');
$title = $is_urdu ? "چارٹ آف اکاؤنٹس - ERP" : "Chart of Accounts - ERP";
include 'header.php';

$render_table = function($list, $tableId = '') use ($is_urdu, $lang) {
?>
    <div class="table-responsive">
        <table class="table table-bordered table-striped table-hover align-middle datatable" id="<?php echo $tableId; ?>">
            <thead class="table-dark">
                <tr>
                    <th style="width: 12%;"><?php echo $is_urdu ? 'کوڈ (Code)' : 'Code'; ?></th>
                    <th><?php echo $is_urdu ? 'اکاؤنٹ کا نام (Account Name)' : 'Account Name'; ?></th>
                    <th style="width: 15%;"><?php echo $is_urdu ? 'قسم (Type)' : 'Type'; ?></th>
                    <th style="width: 20%;"><?php echo $is_urdu ? 'بنیادی اکاؤنٹ (Parent Account)' : 'Parent Account'; ?></th>
                    <th style="width: 12%;"><?php echo $is_urdu ? 'کیفیت (Status)' : 'Status'; ?></th>
                    <th style="width: 12%;" class="text-center"><?php echo $is_urdu ? 'کارروائی (Actions)' : 'Actions'; ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($list)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-3"><?php echo $is_urdu ? 'کوئی اکاؤنٹ موجود نہیں' : 'No accounts found'; ?></td></tr>
                <?php endif; ?>
                <?php foreach ($list as $acc): ?>
                <tr>
                    <td class="fw-bold fs-6"><span class="badge bg-dark"><?php echo htmlspecialchars($acc['code']); ?></span></td>
                    <td class="fw-bold text-dark">
                        <?php 
                        $dispName = localize_head_name($acc['name'], $lang);
                        echo htmlspecialchars($dispName);
                        if ($is_urdu && $dispName !== $acc['name']) {
                            echo " <small class='text-muted'>(" . htmlspecialchars($acc['name']) . ")</small>";
                        }
                        ?>
                    </td>
                    <td>
                        <?php 
                        $badgeClass = 'bg-secondary';
                        if ($acc['type'] == 'Revenue' || $acc['type'] == 'Income') $badgeClass = 'bg-success';
                        elseif ($acc['type'] == 'Expense') $badgeClass = 'bg-danger';
                        elseif ($acc['type'] == 'Asset') $badgeClass = 'bg-primary';
                        elseif ($acc['type'] == 'Liability') $badgeClass = 'bg-warning text-dark';
                        elseif ($acc['type'] == 'Equity') $badgeClass = 'bg-info';
                        ?>
                        <span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars(localize_acc_type($acc['type'], $lang)); ?></span>
                    </td>
                    <td>
                        <?php 
                        if (!empty($acc['parent_name'])) {
                            echo htmlspecialchars(localize_head_name($acc['parent_name'], $lang));
                        } else {
                            echo '-';
                        }
                        ?>
                    </td>
                    <td>
                        <?php if ($acc['is_active']): ?>
                            <span class="badge bg-success"><?php echo $is_urdu ? 'فعال (Active)' : 'Active'; ?></span>
                        <?php else: ?>
                            <span class="badge bg-danger"><?php echo $is_urdu ? 'غیر فعال (Inactive)' : 'Inactive'; ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-outline-primary me-1" 
                            onclick="editAccount(<?php echo htmlspecialchars(json_encode($acc)); ?>)">
                            <i class="fas fa-edit"></i>
                        </button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('<?php echo $is_urdu ? 'کیا آپ واقعی یہ اکاؤنٹ حذف کرنا چاہتے ہیں؟' : 'Are you sure you want to delete this account?'; ?>');">
                            <input type="hidden" name="action" value="delete_account">
                            <input type="hidden" name="id" value="<?php echo $acc['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php
};
?>

<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <h4 class="mb-0 text-primary fw-bold">
            <i class="fas fa-list-alt me-2"></i> 
            <?php echo $is_urdu ? 'چارٹ آف اکاؤنٹس (Chart of Accounts)' : 'Chart of Accounts'; ?>
        </h4>
        <div class="d-flex gap-2">
            <form method="POST" class="d-inline" onsubmit="return confirm('کیا آپ تمام کھاتوں کے کوڈز کو تسلسل میں لانا چاہتے ہیں؟');">
                <input type="hidden" name="action" value="resequence">
                <button type="submit" class="btn btn-outline-secondary fw-bold shadow-sm" title="کوڈز کی خودکار نمبرنگ درست کریں">
                    <i class="fas fa-sort-numeric-down me-1"></i> <?php echo $is_urdu ? 'کوڈز کا تسلسل (Resequence)' : 'Resequence Codes'; ?>
                </button>
            </form>
            <button type="button" class="btn btn-primary fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#addAccountModal">
                <i class="fas fa-plus me-1"></i> 
                <?php echo $is_urdu ? 'نیا اکاؤنٹ شامل کریں (Add Account)' : 'Add Account'; ?>
            </button>
        </div>
    </div>

    <?php echo $msg; ?>

    <!-- Category Tabs -->
    <ul class="nav nav-pills mb-3 gap-2 flex-wrap" id="chartTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active fw-bold" id="all-tab" data-bs-toggle="pill" data-bs-target="#tab-all" type="button" role="tab">
                <i class="fas fa-th-list me-1"></i> تمام کھاتے (All) <span class="badge bg-secondary ms-1"><?php echo count($accounts); ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold text-success border border-success" id="rev-tab" data-bs-toggle="pill" data-bs-target="#tab-rev" type="button" role="tab">
                <i class="fas fa-arrow-down me-1"></i> آمدن کھاتے (Revenue: 4000s) <span class="badge bg-success ms-1"><?php echo count($rev_accounts); ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold text-danger border border-danger" id="exp-tab" data-bs-toggle="pill" data-bs-target="#tab-exp" type="button" role="tab">
                <i class="fas fa-arrow-up me-1"></i> اخراجات (Expense: 5000s) <span class="badge bg-danger ms-1"><?php echo count($exp_accounts); ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold text-primary border border-primary" id="asset-tab" data-bs-toggle="pill" data-bs-target="#tab-asset" type="button" role="tab">
                <i class="fas fa-wallet me-1"></i> اثاثہ جات (Assets: 1000s) <span class="badge bg-primary ms-1"><?php echo count($asset_accounts); ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold text-dark border border-warning" id="liab-tab" data-bs-toggle="pill" data-bs-target="#tab-liab" type="button" role="tab">
                <i class="fas fa-hand-holding-usd me-1"></i> واجبات (Liabilities: 2000s) <span class="badge bg-warning text-dark ms-1"><?php echo count($liab_accounts); ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold text-info border border-info" id="eq-tab" data-bs-toggle="pill" data-bs-target="#tab-eq" type="button" role="tab">
                <i class="fas fa-balance-scale me-1"></i> سرمایہ (Equity: 3000s) <span class="badge bg-info ms-1"><?php echo count($eq_accounts); ?></span>
            </button>
        </li>
    </ul>

    <div class="tab-content" id="chartTabsContent">
        <div class="tab-pane fade show active" id="tab-all" role="tabpanel">
            <div class="card p-4 bg-white shadow-sm rounded border-0">
                <?php $render_table($accounts, 'tbl-all'); ?>
            </div>
        </div>
        <div class="tab-pane fade" id="tab-rev" role="tabpanel">
            <div class="card p-4 bg-white shadow-sm rounded border-0 border-top border-3 border-success">
                <h5 class="fw-bold text-success mb-3"><i class="fas fa-arrow-down me-2"></i> آمدن کھاتے (Revenue / Income - 4000 سیریز)</h5>
                <?php $render_table($rev_accounts, 'tbl-rev'); ?>
            </div>
        </div>
        <div class="tab-pane fade" id="tab-exp" role="tabpanel">
            <div class="card p-4 bg-white shadow-sm rounded border-0 border-top border-3 border-danger">
                <h5 class="fw-bold text-danger mb-3"><i class="fas fa-arrow-up me-2"></i> اخراجات کھاتے (Expense - 5000 سیریز)</h5>
                <?php $render_table($exp_accounts, 'tbl-exp'); ?>
            </div>
        </div>
        <div class="tab-pane fade" id="tab-asset" role="tabpanel">
            <div class="card p-4 bg-white shadow-sm rounded border-0 border-top border-3 border-primary">
                <h5 class="fw-bold text-primary mb-3"><i class="fas fa-wallet me-2"></i> اثاثہ جات کھاتے (Assets - 1000 سیریز)</h5>
                <?php $render_table($asset_accounts, 'tbl-asset'); ?>
            </div>
        </div>
        <div class="tab-pane fade" id="tab-liab" role="tabpanel">
            <div class="card p-4 bg-white shadow-sm rounded border-0 border-top border-3 border-warning">
                <h5 class="fw-bold text-warning text-dark mb-3"><i class="fas fa-hand-holding-usd me-2"></i> واجبات کھاتے (Liabilities - 2000 سیریز)</h5>
                <?php $render_table($liab_accounts, 'tbl-liab'); ?>
            </div>
        </div>
        <div class="tab-pane fade" id="tab-eq" role="tabpanel">
            <div class="card p-4 bg-white shadow-sm rounded border-0 border-top border-3 border-info">
                <h5 class="fw-bold text-info mb-3"><i class="fas fa-balance-scale me-2"></i> سرمایہ کھاتے (Equity - 3000 سیریز)</h5>
                <?php $render_table($eq_accounts, 'tbl-eq'); ?>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Account Modal -->
<div class="modal fade" id="addAccountModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle"><?php echo $is_urdu ? 'نیا اکاؤنٹ شامل کریں' : 'Add Account'; ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add_account">
                    <input type="hidden" name="id" id="accountId" value="">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $is_urdu ? 'قسم (Type)' : 'Type'; ?> <span class="text-danger">*</span></label>
                        <select name="type" id="accountType" class="form-select" required>
                            <option value="">-- <?php echo $is_urdu ? 'قسم منتخب کریں' : 'Select Type'; ?> --</option>
                            <option value="Revenue"><?php echo $is_urdu ? 'آمدن (Revenue: 4000s)' : 'Revenue (4000s)'; ?></option>
                            <option value="Expense"><?php echo $is_urdu ? 'اخراجات (Expense: 5000s)' : 'Expense (5000s)'; ?></option>
                            <option value="Asset"><?php echo $is_urdu ? 'اثاثہ جات (Asset: 1000s)' : 'Asset (1000s)'; ?></option>
                            <option value="Liability"><?php echo $is_urdu ? 'واجبات (Liability: 2000s)' : 'Liability (2000s)'; ?></option>
                            <option value="Equity"><?php echo $is_urdu ? 'سرمایہ (Equity: 3000s)' : 'Equity (3000s)'; ?></option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $is_urdu ? 'اکاؤنٹ کوڈ (Account Code)' : 'Account Code'; ?> <span class="text-danger">*</span></label>
                        <input type="text" name="code" id="accountCode" class="form-control" required placeholder="قسم منتخب کرنے پر خودکار تجویز ہوگا">
                        <small class="text-muted">قسم منتخب کرتے ہی اگلا دستیاب تسلسل کوڈ خودکار آ جائے گا۔</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $is_urdu ? 'اکاؤنٹ کا نام (Account Name)' : 'Account Name'; ?> <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="accountName" class="form-control" required placeholder="مثلاً: عطیات، راشن، بجلی بل">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $is_urdu ? 'بنیادی اکاؤنٹ اختیاری (Parent Account)' : 'Parent Account (Optional)'; ?></label>
                        <select name="parent_id" id="parentAccount" class="form-select select2" style="width: 100%;">
                            <option value="">-- <?php echo $is_urdu ? 'کوئی نہیں' : 'None'; ?> --</option>
                            <?php foreach ($accounts as $a): ?>
                                <option value="<?php echo $a['id']; ?>"><?php echo e($a['code'] . ' - ' . localize_head_name($a['name'], $lang)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="isActive" name="is_active" checked>
                        <label class="form-check-label fw-bold" for="isActive"><?php echo $is_urdu ? 'فعال ہے؟ (Is Active?)' : 'Is Active?'; ?></label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo $is_urdu ? 'بند کریں' : 'Close'; ?></button>
                    <button type="submit" class="btn btn-primary fw-bold"><?php echo $is_urdu ? 'محفوظ کریں' : 'Save Account'; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var isUrduPage = <?php echo $is_urdu ? 'true' : 'false'; ?>;
var nextCodes = <?php echo json_encode($next_codes); ?>;

document.getElementById('accountType').addEventListener('change', function() {
    if (document.getElementById('formAction').value === 'add_account') {
        var selType = this.value;
        if (nextCodes[selType]) {
            document.getElementById('accountCode').value = nextCodes[selType];
        }
    }
});

function editAccount(acc) {
    document.getElementById('modalTitle').innerText = isUrduPage ? 'اکاؤنٹ میں ترمیم کریں (Edit Account)' : 'Edit Account';
    document.getElementById('formAction').value = 'edit_account';
    document.getElementById('accountId').value = acc.id;
    document.getElementById('accountCode').value = acc.code;
    document.getElementById('accountName').value = acc.name;
    document.getElementById('accountType').value = acc.type;
    
    let parentSelect = document.getElementById('parentAccount');
    parentSelect.value = acc.parent_id || '';
    
    // Trigger Select2 update if applicable
    if (typeof jQuery !== 'undefined' && jQuery(parentSelect).hasClass('select2-hidden-accessible')) {
        jQuery(parentSelect).trigger('change');
    }
    
    document.getElementById('isActive').checked = (acc.is_active == 1);
    
    new bootstrap.Modal(document.getElementById('addAccountModal')).show();
}

// Reset modal on close
document.getElementById('addAccountModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('modalTitle').innerText = isUrduPage ? 'نیا اکاؤنٹ شامل کریں (Add Account)' : 'Add Account';
    document.getElementById('formAction').value = 'add_account';
    document.getElementById('accountId').value = '';
    document.getElementById('accountCode').value = '';
    document.getElementById('accountName').value = '';
    document.getElementById('accountType').value = '';
    document.getElementById('parentAccount').value = '';
    document.getElementById('isActive').checked = true;
    
    let parentSelect = document.getElementById('parentAccount');
    if (typeof jQuery !== 'undefined' && jQuery(parentSelect).hasClass('select2-hidden-accessible')) {
        jQuery(parentSelect).trigger('change');
    }
});

$(document).ready(function() {
    if($.fn.DataTable) {
        $('.datatable').DataTable({
            "pageLength": 25,
            "order": [[0, "asc"]]
        });
    }
});
</script>

<?php include 'footer.php'; ?>
