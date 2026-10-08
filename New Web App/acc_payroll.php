<?php
require_once 'db.php';
require_once __DIR__ . '/finance_accounting_bridge.php';
session_start();

if (!isset($_SESSION['user_id']) || !app_has_perm('manage_erp')) {
    header("Location: dashboard.php");
    exit;
}
$role = $_SESSION['role'] ?? '';
$user = ['id' => $_SESSION['user_id'], 'role' => $role];

// Create table if not exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS acc_payroll (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT NOT NULL,
        month_year VARCHAR(20),
        basic_salary DECIMAL(15,2) DEFAULT 0,
        allowances DECIMAL(15,2) DEFAULT 0,
        deductions DECIMAL(15,2) DEFAULT 0,
        net_salary DECIMAL(15,2) GENERATED ALWAYS AS (basic_salary + allowances - deductions) STORED,
        status ENUM('Unpaid', 'Paid', 'Cancelled') DEFAULT 'Unpaid',
        payment_date DATE DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_by INT
    )");
} catch (Exception $e) {}

// Handle Generation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate_payroll'])) {
    $month_year = $_POST['month_year'] ?? date('Y-m');
    $uid = $_SESSION['user_id'];
    
    $teachers = $pdo->query("SELECT id, name, salary, house_rent, medical_allowance, travel_allowance, daily_deduction_rate FROM teachers WHERE status = 'Active' AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($teachers as $t) {
        $check = $pdo->prepare("SELECT id FROM acc_payroll WHERE teacher_id = ? AND month_year = ?");
        $check->execute([$t['id'], $month_year]);
        if ($check->rowCount() == 0) {
            $salary = (float)($t['salary'] ?? 0);
            $allowances = (float)($t['house_rent'] ?? 0) + (float)($t['medical_allowance'] ?? 0) + (float)($t['travel_allowance'] ?? 0);
            
            // Calculate Absences Deduction
            $absences_stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance_teachers WHERE teacher_id = ? AND DATE_FORMAT(date, '%Y-%m') = ? AND status = 'A'");
            $absences_stmt->execute([$t['id'], $month_year]);
            $absent_days = $absences_stmt->fetchColumn();
            
            $deduction_rate = (float)($t['daily_deduction_rate'] ?? 0);
            if ($deduction_rate == 0 && $salary > 0) {
                $deduction_rate = round($salary / 30); // fallback deduction
            }
            $deductions = $absent_days * $deduction_rate;

            $stmt = $pdo->prepare("INSERT INTO acc_payroll (teacher_id, month_year, basic_salary, allowances, deductions, status, created_by) VALUES (?, ?, ?, ?, ?, 'Unpaid', ?)");
            $stmt->execute([$t['id'], $month_year, $salary, $allowances, $deductions, $uid]);
            payroll_accounting_post($pdo, (int)$pdo->lastInsertId());
        }
    }
    $msg = "Payroll generated successfully for $month_year!";
}

// Handle Update Allowances/Deductions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_payroll'])) {
    $pid = (int)$_POST['payroll_id'];
    $allow = (float)$_POST['allowances'];
    $deduct = (float)$_POST['deductions'];
    $pdo->prepare("UPDATE acc_payroll SET allowances = ?, deductions = ? WHERE id = ? AND status = 'Unpaid'")->execute([$allow, $deduct, $pid]);
    payroll_accounting_post($pdo, $pid);
    header("Location: acc_payroll.php");
    exit;
}

// Update Status
if (isset($_GET['mark_paid']) && is_numeric($_GET['mark_paid'])) {
    $pid = (int)$_GET['mark_paid'];
    $pdo->prepare("UPDATE acc_payroll SET status = 'Paid', payment_date = CURRENT_DATE WHERE id = ?")->execute([$pid]);
    payroll_accounting_post($pdo, $pid);
    header("Location: acc_payroll.php");
    exit;
}

$payrolls = $pdo->query("
    SELECT p.*, t.name as teacher_name 
    FROM acc_payroll p
    JOIN teachers t ON p.teacher_id = t.id
    WHERE (t.is_deleted = 0 OR t.is_deleted IS NULL)
    ORDER BY p.id DESC LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

$title = "Payroll Management - ERP";
include 'header.php';
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-info"><i class="fas fa-users-cog me-2"></i> <?php echo t('teacher_payroll'); ?></h3>
        <button class="btn btn-info fw-bold text-white shadow" data-bs-toggle="modal" data-bs-target="#generateModal">
            <i class="fas fa-magic me-1"></i> <?php echo t('generate'); ?>
        </button>
    </div>

    <?php if(isset($msg)): ?>
    <div class="alert alert-success shadow-sm"><i class="fas fa-check-circle me-2"></i> <?php echo $msg; ?></div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 neobox">
        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle table-sm">
                    <thead class="table-light">
                        <tr>
                            <th><?php echo t('teacher'); ?></th>
                            <th><?php echo t('month'); ?></th>
                            <th><?php echo t('basic'); ?></th>
                            <th><?php echo t('allow_deduct'); ?></th>
                            <th><?php echo t('net_salary'); ?></th>
                            <th><?php echo t('status'); ?></th>
                            <th><?php echo t('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($payrolls as $p): ?>
                        <tr>
                            <td class="fw-bold text-primary"><?php echo htmlspecialchars($p['teacher_name']); ?></td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($p['month_year']); ?></span></td>
                            <td>Rs. <?php echo number_format($p['basic_salary'], 0); ?></td>
                            <td class="small">
                                <span class="text-success">+<?php echo number_format($p['allowances'], 0); ?></span> / 
                                <span class="text-danger">-<?php echo number_format($p['deductions'], 0); ?></span>
                            </td>
                            <td class="fw-bold fs-6">Rs. <?php echo number_format($p['net_salary'], 0); ?></td>
                            <td>
                                <?php if($p['status'] == 'Paid'): ?>
                                    <span class="badge bg-success"><i class="fas fa-check"></i> <?php echo t('paid'); ?></span>
                                <?php elseif($p['status'] == 'Cancelled'): ?>
                                    <span class="badge bg-danger"><?php echo t('cancelled'); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> <?php echo t('unpaid'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($p['status'] == 'Unpaid'): ?>
                                    <button class="btn btn-sm btn-primary" onclick="editPayroll(<?php echo $p['id']; ?>, <?php echo $p['allowances']; ?>, <?php echo $p['deductions']; ?>)"><i class="fas fa-edit"></i></button>
                                    <a href="?mark_paid=<?php echo $p['id']; ?>" class="btn btn-sm btn-success" title="Mark as Paid" onclick="return confirm('Confirm salary disbursed?')"><i class="fas fa-money-check-alt"></i> <?php echo t('pay'); ?></a>
                                <?php endif; ?>
                                <a href="print_salary_slip.php?id=<?php echo $p['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Print Slip"><i class="fas fa-print"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($payrolls)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4"><?php echo t('no_payroll_records'); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Generate Modal -->
<div class="modal fade" id="generateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-magic me-2"></i> <?php echo t('generate_month_payroll'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="fw-bold mb-1"><?php echo t('payroll_month'); ?></label>
                        <input type="month" name="month_year" class="form-control" value="<?php echo date('Y-m'); ?>" required>
                        <small class="text-muted mt-2 d-block"><?php echo t('pull_basic_salary'); ?></small>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('cancel'); ?></button>
                    <button type="submit" name="generate_payroll" class="btn btn-info fw-bold text-white"><i class="fas fa-cogs me-1"></i> <?php echo t('generate_payroll'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="payroll_id" id="edit_pid">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i> Update Salary Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="fw-bold mb-1">Allowances (Rs.)</label>
                        <input type="number" name="allowances" id="edit_allow" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold mb-1">Deductions (Rs.)</label>
                        <input type="number" name="deductions" id="edit_deduct" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('cancel'); ?></button>
                    <button type="submit" name="update_payroll" class="btn btn-primary fw-bold text-white"><i class="fas fa-save me-1"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editPayroll(id, allow, deduct) {
    document.getElementById('edit_pid').value = id;
    document.getElementById('edit_allow').value = allow;
    document.getElementById('edit_deduct').value = deduct;
    var myModal = new bootstrap.Modal(document.getElementById('editModal'));
    myModal.show();
}
</script>

<?php include 'footer.php'; ?>
