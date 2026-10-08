<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
global $system_type;
$system_type = $_SESSION['system_type'] ?? 'Madrasa';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
$u_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin = in_array($u_role, ['admin', 'superadmin', 'super_admin', 'administrator'], true);
if (!$is_admin && !app_has_perm('manage_loans') && !app_has_perm('manage_masjid_loans') && !app_has_perm('manage_finance') && !app_has_perm('manage_erp')) {
    header("Location: dashboard.php");
    exit;
}

$msg = '';
$uid = $_SESSION['user_id'];
$is_en = (isset($_SESSION['lang']) && $_SESSION['lang'] === 'en');
$txt = [
    'title' => $is_en ? 'Staff Loans & Advances' : 'عملہ کے قرض اور ایڈوانس',
    'staff' => $is_en ? 'Staff Name' : 'نام عملہ',
    'loan_amt' => $is_en ? 'Loan Amount' : 'کل قرض کی رقم',
    'installment' => $is_en ? 'Monthly Installment' : 'ماہانہ کٹوتی قسط',
    'date' => $is_en ? 'Date Issued' : 'تاریخ اجراء',
    'status' => $is_en ? 'Status' : 'سٹیٹس',
    'issue_loan' => $is_en ? 'Issue New Loan' : 'نیا قرض جاری کریں',
    'desc' => $is_en ? 'Description' : 'تفصیل',
    'save' => $is_en ? 'Save' : 'محفوظ کریں',
    'msg_saved' => $is_en ? 'Loan issued successfully and recorded in Finance.' : 'قرض جاری کر دیا گیا ہے اور فنانس میں درج ہو گیا ہے۔',
    'active' => $is_en ? 'Active' : 'فعال',
    'paid' => $is_en ? 'Paid' : 'ادا شدہ',
    'remaining' => $is_en ? 'Remaining Balance' : 'بقیہ قرض',
    'sal_head' => $is_en ? 'Staff Loans' : 'سٹاف لون (قرضہ جات)'
];

// Ensure "Staff Loans" head exists
$sal_head_id = 0;
try {
    $sh = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE code = 'LOAN-001' LIMIT 1")->fetchColumn();
    if (!$sh) {
        $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, is_deleted, system_type) VALUES ('LOAN-001', ?, 'Asset', 0, '$system_type')")->execute([$txt['sal_head']]);
        $sal_head_id = (int)$pdo->lastInsertId();
    } else {
        $sal_head_id = (int)$sh;
    }
} catch (Exception $e) {}

if (isset($_POST['issue_loan'])) {
    $teacher_id = (int)$_POST['teacher_id'];
    $amt = (float)$_POST['loan_amount'];
    $inst = (float)$_POST['installment_amount'];
    $date = $_POST['date_issued'] ?? date('Y-m-d');
    $desc = trim($_POST['description'] ?? '');
    
    $staff = $pdo->prepare("SELECT name FROM teachers WHERE id = ?");
    $staff->execute([$teacher_id]);
    $staff_name = $staff->fetchColumn();

    $fin_desc = "{$txt['title']}: $staff_name - " . $desc;
    
    $pdo->prepare("INSERT INTO staff_loans (teacher_id, loan_amount, installment_amount, remaining_amount, date_issued, description, status, system_type) VALUES (?, ?, ?, ?, ?, ?, 'Active', '$system_type')")
        ->execute([$teacher_id, $amt, $inst, $amt, $date, $desc]);
        
    $fin_stmt = $pdo->prepare("INSERT INTO finance (user_id, user_name, type, head_id, amount, date, donor_name, description, is_deleted, system_type) VALUES (?, ?, 'Expense', ?, ?, ?, ?, ?, 0, '$system_type')");
    $fin_stmt->execute([$uid, $_SESSION['name'] ?? 'Admin', $sal_head_id, $amt, $date, $staff_name, $fin_desc]);
    
    $msg = "<div class='alert alert-success fw-bold'>✅ {$txt['msg_saved']}</div>";
}

$loans = $pdo->query("SELECT l.*, t.name as staff_name FROM staff_loans l JOIN teachers t ON l.teacher_id = t.id WHERE l.is_deleted = 0 AND l.system_type = '$system_type' ORDER BY l.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$teachers = $pdo->query("SELECT id, name FROM teachers WHERE status = 'Active' AND (is_deleted = 0 OR is_deleted IS NULL) AND system_type = '$system_type' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$title = $txt['title'];
include 'header.php';
?>

<div class="container py-4" dir="<?php echo $is_en ? 'ltr' : 'rtl'; ?>">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold text-primary"><i class="fas fa-hand-holding-usd me-2"></i> <?php echo $txt['title']; ?></h2>
        <button class="btn btn-success fw-bold" data-bs-toggle="modal" data-bs-target="#loanModal">
            <i class="fas fa-plus"></i> <?php echo $txt['issue_loan']; ?>
        </button>
    </div>
    
    <?php if($msg) echo $msg; ?>

    <div class="card shadow-sm border-0 neobox">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th><?php echo $txt['staff']; ?></th>
                            <th><?php echo $txt['loan_amt']; ?></th>
                            <th><?php echo $txt['installment']; ?></th>
                            <th><?php echo $txt['remaining']; ?></th>
                            <th><?php echo $txt['date']; ?></th>
                            <th><?php echo $txt['status']; ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($loans as $l): ?>
                            <tr>
                                <td><?php echo $l['id']; ?></td>
                                <td class="fw-bold"><?php echo $l['staff_name']; ?></td>
                                <td class="text-danger fw-bold">Rs.<?php echo number_format($l['loan_amount'], 2); ?></td>
                                <td class="text-warning fw-bold">Rs.<?php echo number_format($l['installment_amount'], 2); ?></td>
                                <td class="text-primary fw-bold">Rs.<?php echo number_format($l['remaining_amount'], 2); ?></td>
                                <td><?php echo $l['date_issued']; ?></td>
                                <td>
                                    <?php if($l['status'] == 'Active'): ?>
                                        <span class="badge bg-success"><?php echo $txt['active']; ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?php echo $txt['paid']; ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="loanModal" tabindex="-1">
    <div class="modal-dialog" dir="<?php echo $is_en ? 'ltr' : 'rtl'; ?>">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-hand-holding-usd me-2"></i> <?php echo $txt['issue_loan']; ?></h5>
                <button type="button" class="btn-close btn-close-white m-0" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $txt['staff']; ?></label>
                        <select name="teacher_id" class="form-select" required>
                            <option value=""><?php echo $is_en ? 'Select Staff' : 'سٹاف منتخب کریں'; ?></option>
                            <?php foreach($teachers as $t): ?>
                                <option value="<?php echo $t['id']; ?>"><?php echo $t['name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold"><?php echo $txt['loan_amt']; ?> (Rs.)</label>
                            <input type="number" step="0.01" name="loan_amount" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold"><?php echo $txt['installment']; ?> (Rs.)</label>
                            <input type="number" step="0.01" name="installment_amount" class="form-control" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $txt['date']; ?></label>
                        <input type="date" name="date_issued" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $txt['desc']; ?></label>
                        <textarea name="description" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="issue_loan" class="btn btn-success fw-bold px-4"><?php echo $txt['save']; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
