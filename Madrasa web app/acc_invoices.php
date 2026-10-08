<?php
require_once 'db.php';
session_start();

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

// Create table if not exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS acc_invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL DEFAULT 1,
        student_id INT NOT NULL,
        invoice_no VARCHAR(50),
        month_year VARCHAR(20),
        amount DECIMAL(15,2),
        status ENUM('Unpaid', 'Paid', 'Cancelled') DEFAULT 'Unpaid',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_by INT,
        INDEX (tenant_id)
    )");
} catch (Exception $e) {}

// Handle Generation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate_invoices'])) {
    $month_year = $_POST['month_year'] ?? date('Y-m');
    $amount = (float)($_POST['default_amount'] ?? 0);
    $uid = $_SESSION['user_id'];
    
    if ($amount > 0) {
        $st_stmt = $pdo->prepare("SELECT id, name FROM students WHERE status = 'Active' AND (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = ?");
        $st_stmt->execute([$cur_tenant_id]);
        $students = $st_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($students as $st) {
            $check = $pdo->prepare("SELECT id FROM acc_invoices WHERE student_id = ? AND month_year = ? AND tenant_id = ?");
            $check->execute([$st['id'], $month_year, $cur_tenant_id]);
            if ($check->rowCount() == 0) {
                // Check active hostel allocation
                $hostel_fee = 0.0;
                try {
                    $hStmt = $pdo->prepare("SELECT a.*, r.monthly_fee, r.room_number FROM hostel_allocations a JOIN hostel_rooms r ON a.room_id = r.id WHERE a.student_id = ? AND a.tenant_id = ? AND a.status = 'Occupied' AND (a.is_deleted = 0 OR a.is_deleted IS NULL) LIMIT 1");
                    $hStmt->execute([$st['id'], $cur_tenant_id]);
                    $hRoom = $hStmt->fetch(PDO::FETCH_ASSOC);
                    if ($hRoom && !empty($hRoom['monthly_fee'])) {
                        $hostel_fee = (float)$hRoom['monthly_fee'];
                    }
                } catch (Exception $e) {}

                $final_amount = $amount + $hostel_fee;
                $inv_no = "INV-" . date('ym') . "-" . rand(1000, 9999);
                $stmt = $pdo->prepare("INSERT INTO acc_invoices (student_id, invoice_no, month_year, amount, status, created_by, tenant_id) VALUES (?, ?, ?, ?, 'Unpaid', ?, ?)");
                $stmt->execute([$st['id'], $inv_no, $month_year, $final_amount, $uid, $cur_tenant_id]);
            }
        }
        $msg = "Invoices generated successfully for $month_year (Hostel charges auto-included where applicable)!";
    }
}

// Update Status
if (isset($_GET['mark_paid']) && is_numeric($_GET['mark_paid'])) {
    $pdo->prepare("UPDATE acc_invoices SET status = 'Paid' WHERE id = ? AND tenant_id = ?")->execute([$_GET['mark_paid'], $cur_tenant_id]);
    header("Location: acc_invoices.php");
    exit;
}

$inv_stmt = $pdo->prepare("
    SELECT i.*, s.name as student_name, s.admission_number 
    FROM acc_invoices i
    JOIN students s ON i.student_id = s.id
    WHERE (s.is_deleted = 0 OR s.is_deleted IS NULL) AND i.tenant_id = ? AND s.tenant_id = ?
    ORDER BY i.id DESC LIMIT 100
");
$inv_stmt->execute([$cur_tenant_id, $cur_tenant_id]);
$invoices = $inv_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Fee Invoices - ERP";
include 'header.php';
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-warning"><i class="fas fa-file-invoice me-2"></i> <?php echo t('fee_invoices'); ?></h3>
        <button class="btn btn-warning fw-bold text-dark shadow" data-bs-toggle="modal" data-bs-target="#generateModal">
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
                            <th><?php echo t('invoice_no'); ?></th>
                            <th><?php echo t('month'); ?></th>
                            <th><?php echo t('student'); ?></th>
                            <th><?php echo t('amount'); ?></th>
                            <th><?php echo t('status'); ?></th>
                            <th><?php echo t('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($invoices as $inv): ?>
                        <tr>
                            <td class="fw-bold text-primary"><?php echo htmlspecialchars($inv['invoice_no']); ?></td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($inv['month_year']); ?></span></td>
                            <td><?php echo htmlspecialchars($inv['student_name']); ?> <small class="text-muted">(<?php echo htmlspecialchars($inv['admission_number']); ?>)</small></td>
                            <td class="fw-bold">Rs. <?php echo number_format($inv['amount'], 2); ?></td>
                            <td>
                                <?php if($inv['status'] == 'Paid'): ?>
                                    <span class="badge bg-success"><i class="fas fa-check"></i> <?php echo t('paid'); ?></span>
                                <?php elseif($inv['status'] == 'Cancelled'): ?>
                                    <span class="badge bg-danger"><?php echo t('cancelled'); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> <?php echo t('unpaid'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($inv['status'] == 'Unpaid'): ?>
                                    <a href="?mark_paid=<?php echo $inv['id']; ?>" class="btn btn-sm btn-success" title="Mark as Paid" onclick="return confirm('Confirm payment received?')"><i class="fas fa-check"></i></a>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-outline-primary" onclick="window.print()" title="Print"><i class="fas fa-print"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($invoices)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4"><?php echo t('no_invoices_found'); ?></td></tr>
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
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title fw-bold"><i class="fas fa-magic me-2"></i> <?php echo t('generate_bulk_invoices'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="fw-bold mb-1"><?php echo t('invoice_month'); ?></label>
                        <input type="month" name="month_year" class="form-control" value="<?php echo date('Y-m'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold mb-1"><?php echo t('default_fee_amount'); ?></label>
                        <input type="number" step="0.01" name="default_amount" class="form-control" required placeholder="e.g. 1500">
                        <small class="text-muted"><?php echo t('billed_to_all'); ?></small>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('cancel'); ?></button>
                    <button type="submit" name="generate_invoices" class="btn btn-warning fw-bold text-dark"><i class="fas fa-cogs me-1"></i> <?php echo t('generate_now'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
