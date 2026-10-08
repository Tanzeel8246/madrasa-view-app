<?php
require_once 'db.php';
require_once 'finance_accounting_bridge.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id']) || !app_has_perm('manage_finance')) {
    header("Location: dashboard.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['name'] ?? 'Admin';
$sys = $_SESSION['system_type'] ?? 'Madrasa';

function next_user_receipt_no(PDO $pdo, $userId) {
    $userId = max(1, (int)$userId);
    $minNo = $userId * 100000;
    $maxNo = ($userId + 1) * 100000;
    $stmt = $pdo->prepare("SELECT MAX(computer_receipt_no) FROM finance WHERE computer_receipt_no > ? AND computer_receipt_no < ?");
    $stmt->execute([$minNo, $maxNo]);
    $currentMax = (int)$stmt->fetchColumn();
    $nextNo = ($currentMax > $minNo) ? ($currentMax + 1) : ($minNo + 1);
    if ($nextNo >= $maxNo) {
        throw new Exception("Receipt number quota exhausted for this user.");
    }
    return $nextNo;
}

// Delete Record
if (isset($_GET['del_id'])) {
    $delId = (int)$_GET['del_id'];
    $pdo->prepare("UPDATE finance SET is_deleted = 1 WHERE id = ?")->execute([$delId]);
    finance_accounting_delete_voucher($pdo, $delId);
    header("Location: simple_finance.php?msg=deleted");
    exit;
}

// Handle Add/Edit Record submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_record'])) {
    $type = $_POST['type']; // Income or Expense
    $date = $_POST['date'];
    $head_id = (int)$_POST['head_id'];
    $amount = (float)$_POST['amount'];
    $donor_name = $_POST['donor_name'] ?? '';
    $donor_address = $_POST['donor_address'] ?? '';
    $manual_receipt_no = $_POST['manual_receipt_no'] ?? '';
    $description = $_POST['description'] ?? '';
    
    if (isset($_POST['edit_id']) && !empty($_POST['edit_id'])) {
        $edit_id = (int)$_POST['edit_id'];
        $stmt = $pdo->prepare("UPDATE finance SET type=?, head_id=?, amount=?, date=?, donor_name=?, donor_address=?, manual_receipt_no=?, description=? WHERE id=?");
        $stmt->execute([$type, $head_id, $amount, $date, $donor_name, $donor_address, $manual_receipt_no, $description, $edit_id]);
        header("Location: simple_finance.php?msg=edited");
        exit;
    } else {
        $comp_receipt_no = ($type == 'Income') ? next_user_receipt_no($pdo, $user_id) : null;
        $stmt = $pdo->prepare("INSERT INTO finance (user_id, user_name, type, head_id, amount, date, donor_name, donor_address, manual_receipt_no, computer_receipt_no, description, is_deleted, system_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)");
        $stmt->execute([$user_id, $user_name, $type, $head_id, $amount, $date, $donor_name, $donor_address, $manual_receipt_no, $comp_receipt_no, $description, $sys]);
        finance_accounting_post($pdo, (int)$pdo->lastInsertId());
        header("Location: simple_finance.php?msg=added");
        exit;
    }
}

// Add New Head
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_head'])) {
    $head_name = trim($_POST['head_name']);
    $head_type = $_POST['head_type'];
    if (!empty($head_name) && !empty($head_type)) {
        $stmt = $pdo->prepare("INSERT INTO heads (name, type) VALUES (?, ?)");
        $stmt->execute([$head_name, $head_type]);
        sync_all_heads_with_chart_and_departments($pdo);
        header("Location: simple_finance.php?msg=head_added");
        exit;
    }
}

// Edit Head
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_head'])) {
    $head_id = (int)$_POST['head_id'];
    $head_name = trim($_POST['head_name']);
    $head_type = $_POST['head_type'];
    if ($head_id > 0 && !empty($head_name)) {
        $pdo->prepare("UPDATE heads SET name = ?, type = ? WHERE id = ?")->execute([$head_name, $head_type, $head_id]);
        sync_all_heads_with_chart_and_departments($pdo);
        header("Location: simple_finance.php?msg=head_updated");
        exit;
    }
}

// Delete Head
if (isset($_GET['del_head_id'])) {
    $del_head_id = (int)$_GET['del_head_id'];
    $pdo->prepare("UPDATE heads SET is_deleted = 1 WHERE id = ?")->execute([$del_head_id]);
    sync_all_heads_with_chart_and_departments($pdo);
    header("Location: simple_finance.php?msg=head_deleted");
    exit;
}

// Sync Heads
if (isset($_POST['sync_heads']) || isset($_GET['sync_heads'])) {
    sync_all_heads_with_chart_and_departments($pdo);
    header("Location: simple_finance.php?msg=heads_synced");
    exit;
}

// Fetch heads
sync_all_heads_with_chart_and_departments($pdo);
$income_heads = $pdo->query("SELECT * FROM heads WHERE type = 'Income' AND is_deleted = 0 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$expense_heads = $pdo->query("SELECT * FROM heads WHERE type = 'Expense' AND is_deleted = 0 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch records filtered by Income & Expense
$limit_val = isset($_GET['all']) ? 5000 : 500;
if (($_SESSION['role'] ?? '') === 'admin') {
    $income_records = $pdo->query("SELECT f.*, h.name as head_name FROM finance f LEFT JOIN heads h ON f.head_id = h.id WHERE f.type = 'Income' AND (f.system_type = '$sys' OR f.system_type IS NULL OR f.system_type = '') AND f.is_deleted = 0 ORDER BY f.date DESC, f.id DESC LIMIT $limit_val")->fetchAll(PDO::FETCH_ASSOC);
    $expense_records = $pdo->query("SELECT f.*, h.name as head_name FROM finance f LEFT JOIN heads h ON f.head_id = h.id WHERE f.type = 'Expense' AND (f.system_type = '$sys' OR f.system_type IS NULL OR f.system_type = '') AND f.is_deleted = 0 ORDER BY f.date DESC, f.id DESC LIMIT $limit_val")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $current_user_id = intval($_SESSION['user_id'] ?? 0);
    $income_records = $pdo->query("SELECT f.*, h.name as head_name FROM finance f LEFT JOIN heads h ON f.head_id = h.id WHERE f.type = 'Income' AND (f.system_type = '$sys' OR f.system_type IS NULL OR f.system_type = '') AND f.is_deleted = 0 AND f.user_id = $current_user_id ORDER BY f.date DESC, f.id DESC LIMIT $limit_val")->fetchAll(PDO::FETCH_ASSOC);
    $expense_records = $pdo->query("SELECT f.*, h.name as head_name FROM finance f LEFT JOIN heads h ON f.head_id = h.id WHERE f.type = 'Expense' AND (f.system_type = '$sys' OR f.system_type IS NULL OR f.system_type = '') AND f.is_deleted = 0 AND f.user_id = $current_user_id ORDER BY f.date DESC, f.id DESC LIMIT $limit_val")->fetchAll(PDO::FETCH_ASSOC);
}

$title = ($sys === 'Masjid') ? "آمدن و اخراجات (مسجد)" : "آمدن و اخراجات (مدرسہ)";
include 'header.php';
?>

<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h3 class="fw-bold text-primary mb-0">
            <i class="fas fa-coins me-2"></i> 
            <?php echo ($sys === 'Masjid') ? 'سادہ آمدن و اخراجات (مسجد)' : 'سادہ آمدن و اخراجات (مدرسہ)'; ?>
        </h3>
        <div class="d-flex gap-2">
            <a href="simple_finance.php?sync_heads=1" class="btn btn-outline-success fw-bold">
                <i class="fas fa-sync me-1"></i> مدات سنک کریں (Sync Heads)
            </a>
            <a href="finance_report.php" class="btn btn-outline-primary fw-bold">
                <i class="fas fa-chart-bar me-1"></i> فنانس رپورٹ
            </a>
        </div>
    </div>

    <?php if(isset($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        ✅ کارروائی کامیابی سے مکمل ہو گئی۔
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- COLUMN 1: INCOME SECTION (آمدن اندراج فارم اور ہسٹری) -->
        <div class="col-lg-6">
            <!-- آمدن اندراج فارم -->
            <div class="card shadow-sm border-0 neobox mb-4">
                <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-white" style="color: #ffffff !important;"><i class="fas fa-arrow-down me-2 text-white"></i> آمدن اندراج (Income Entry)</h5>
                    <span class="badge bg-light text-success fw-bold">آمدن</span>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="type" value="Income">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="fw-bold form-label">تاریخ</label>
                                <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="fw-bold form-label">مد (Income Head)</label>
                                <div class="input-group">
                                    <select name="head_id" class="form-select" required>
                                        <option value="">آمدن مد منتخب کریں</option>
                                        <?php foreach($income_heads as $h): ?>
                                            <option value="<?php echo $h['id']; ?>"><?php echo htmlspecialchars($h['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#addHeadModal" onclick="setHeadTypeModal('Income')" title="نئی مد">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label class="fw-bold form-label">رقم (Rs.)</label>
                                <input type="number" step="0.01" name="amount" class="form-control" placeholder="مثلاً: 5000" required>
                            </div>
                            <div class="col-md-6">
                                <label class="fw-bold form-label">دینے والے کا نام (Donor)</label>
                                <input type="text" name="donor_name" class="form-control" placeholder="مثلاً: احمد علی">
                            </div>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label class="fw-bold form-label">دستی رسید نمبر (Manual Receipt)</label>
                                <input type="text" name="manual_receipt_no" class="form-control" placeholder="اختياری">
                            </div>
                            <div class="col-md-6">
                                <label class="fw-bold form-label">پتہ (Address)</label>
                                <input type="text" name="donor_address" class="form-control" placeholder="اختياری">
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="fw-bold form-label">تفصیل (Description)</label>
                            <textarea name="description" class="form-control" rows="2" placeholder="تفصیل لکھیے۔۔۔"></textarea>
                        </div>

                        <button type="submit" name="add_record" class="btn btn-success w-100 fw-bold mt-3">
                            <i class="fas fa-save me-1"></i> آمدن ریکارڈ محفوظ کریں
                        </button>
                    </form>
                </div>
            </div>

            <!-- آمدن انٹریز ہسٹری -->
            <div class="card shadow-sm border-0 neobox">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-white" style="color: #ffffff !important;"><i class="fas fa-list me-2 text-white"></i> آمدن انٹریز ہسٹری (کل: <?php echo count($income_records); ?>)</h5>
                    <input type="text" id="searchIncome" class="form-control form-control-sm w-auto" placeholder="تلاش..." onkeyup="filterTable('incomeTable', 'searchIncome')">
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 text-center" id="incomeTable">
                            <thead class="table-light">
                                <tr>
                                    <th>تاریخ</th>
                                    <th>مد</th>
                                    <th>رقم</th>
                                    <th>تفصیل / نام</th>
                                    <th>ایکشن</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($income_records as $r): ?>
                                <tr>
                                    <td><small class="fw-bold"><?php echo date('d-M-Y', strtotime($r['date'])); ?></small></td>
                                    <td class="fw-bold text-success"><?php echo htmlspecialchars($r['head_name'] ?: '---'); ?></td>
                                    <td class="fw-bold text-success">Rs. <?php echo number_format($r['amount']); ?></td>
                                    <td class="small text-start">
                                        <?php if(!empty($r['donor_name'])): ?>
                                            <span class="text-primary fw-bold"><?php echo htmlspecialchars($r['donor_name']); ?></span><br>
                                        <?php endif; ?>
                                        <span class="text-muted"><?php echo htmlspecialchars($r['description']); ?></span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="print_masjid_receipt.php?id=<?php echo $r['id']; ?>" class="btn btn-outline-primary" target="_blank" title="رسید پرنٹ"><i class="fas fa-print"></i></a>
                                            <a href="edit_finance.php?id=<?php echo $r['id']; ?>" class="btn btn-outline-warning" title="ترمیم"><i class="fas fa-edit"></i></a>
                                            <a href="?del_id=<?php echo $r['id']; ?>" class="btn btn-outline-danger" onclick="return confirm('کیا واقعی یہ ریکارڈ ڈیلیٹ کرنا چاہتے ہیں؟')" title="ڈیلیٹ"><i class="fas fa-trash"></i></a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(count($income_records) == 0): ?>
                                <tr><td colspan="5" class="text-center p-4 text-muted">کوئی آمدن ریکارڈ موجود نہیں</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- COLUMN 2: EXPENSE SECTION (خرچ اندراج فارم اور ہسٹری) -->
        <div class="col-lg-6">
            <!-- خرچ اندراج فارم -->
            <div class="card shadow-sm border-0 neobox mb-4">
                <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-white" style="color: #ffffff !important;"><i class="fas fa-arrow-up me-2 text-white"></i> خرچ اندراج (Expense Entry)</h5>
                    <span class="badge bg-light text-danger fw-bold">اخراجات</span>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="type" value="Expense">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="fw-bold form-label">تاریخ</label>
                                <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="fw-bold form-label">مد (Expense Head)</label>
                                <div class="input-group">
                                    <select name="head_id" class="form-select" required>
                                        <option value="">اخراجات مد منتخب کریں</option>
                                        <?php foreach($expense_heads as $h): ?>
                                            <option value="<?php echo $h['id']; ?>"><?php echo htmlspecialchars($h['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#addHeadModal" onclick="setHeadTypeModal('Expense')" title="نئی مد">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-md-12">
                                <label class="fw-bold form-label">رقم (Rs.)</label>
                                <input type="number" step="0.01" name="amount" class="form-control" placeholder="مثلاً: 2500" required>
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="fw-bold form-label">تفصیل (Description)</label>
                            <textarea name="description" class="form-control" rows="4" placeholder="اخراجات کی تفصیل لکھیے۔۔۔"></textarea>
                        </div>

                        <button type="submit" name="add_record" class="btn btn-danger w-100 fw-bold mt-3">
                            <i class="fas fa-save me-1"></i> خرچ ریکارڈ محفوظ کریں
                        </button>
                    </form>
                </div>
            </div>

            <!-- خرچ انٹریز ہسٹری -->
            <div class="card shadow-sm border-0 neobox">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-white" style="color: #ffffff !important;"><i class="fas fa-list me-2 text-white"></i> خرچ انٹریز ہسٹری (کل: <?php echo count($expense_records); ?>)</h5>
                    <input type="text" id="searchExpense" class="form-control form-control-sm w-auto" placeholder="تلاش..." onkeyup="filterTable('expenseTable', 'searchExpense')">
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 text-center" id="expenseTable">
                            <thead class="table-light">
                                <tr>
                                    <th>تاریخ</th>
                                    <th>مد</th>
                                    <th>رقم</th>
                                    <th>تفصیل</th>
                                    <th>ایکشن</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($expense_records as $r): ?>
                                <tr>
                                    <td><small class="fw-bold"><?php echo date('d-M-Y', strtotime($r['date'])); ?></small></td>
                                    <td class="fw-bold text-danger"><?php echo htmlspecialchars($r['head_name'] ?: '---'); ?></td>
                                    <td class="fw-bold text-danger">Rs. <?php echo number_format($r['amount']); ?></td>
                                    <td class="small text-start text-muted"><?php echo htmlspecialchars($r['description']); ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="edit_finance.php?id=<?php echo $r['id']; ?>" class="btn btn-outline-warning" title="ترمیم"><i class="fas fa-edit"></i></a>
                                            <a href="?del_id=<?php echo $r['id']; ?>" class="btn btn-outline-danger" onclick="return confirm('کیا واقعی یہ ریکارڈ ڈیلیٹ کرنا چاہتے ہیں؟')" title="ڈیلیٹ"><i class="fas fa-trash"></i></a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(count($expense_records) == 0): ?>
                                <tr><td colspan="5" class="text-center p-4 text-muted">کوئی خرچ ریکارڈ موجود نہیں</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Head Modal -->
<div class="modal fade" id="addHeadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">نئی مد (Head) شامل کریں</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="fw-bold form-label">مد کا نام</label>
                        <input type="text" name="head_name" class="form-control" placeholder="مثلاً: بجلی کا بل، عطیات، راشن" required>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold form-label">قسم (Type)</label>
                        <select name="head_type" class="form-select" id="modal_head_type" required>
                            <option value="Income">آمدن (Income)</option>
                            <option value="Expense">اخراجات (Expense)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" name="add_head" class="btn btn-primary fw-bold">محفوظ کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
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
</script>

<?php include 'footer.php'; ?>
