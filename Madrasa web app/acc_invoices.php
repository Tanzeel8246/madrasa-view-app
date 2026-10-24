<?php
require_once 'db.php';
require_once 'finance_accounting_bridge.php';
require_once 'financial_audit_helper.php';
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

$cur_tenant_id = (int)get_current_tenant_id($pdo);
$sys = $_SESSION['system_type'] ?? 'Madrasa';

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

if (function_exists('acc_ensure_standard_chart_and_orphans_repaired')) {
    acc_ensure_standard_chart_and_orphans_repaired($pdo, $cur_tenant_id);
}

// Ensure chart accounts (Receivables: 1003 Student Fees Receivable, and 4001 Fee Income)
$rec_acc = $pdo->query("SELECT id, code, name FROM acc_chart_of_accounts WHERE code = '1003' AND (is_deleted = 0 OR is_deleted IS NULL) AND (tenant_id = $cur_tenant_id OR tenant_id IS NULL OR tenant_id = 0) ORDER BY code ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$rec_acc_id = !empty($rec_acc['id']) ? (int)$rec_acc['id'] : finance_accounting_ensure_account($pdo, '1003', 'Student Fees Receivable (طلبہ فیس واجب الوصول)', 'Asset', $cur_tenant_id);
$rec_code = !empty($rec_acc['code']) ? $rec_acc['code'] : '1003';
$fee_acc_id = finance_accounting_ensure_account($pdo, '4001', 'Fee Income (آمدن تعلیمی فیس)', 'Revenue', $cur_tenant_id);
$cash_acc_id = finance_accounting_ensure_account($pdo, '1001', 'Cash in Hand (نقد رقم)', 'Asset', $cur_tenant_id);

// Handle Generation (D9 Fix: Auto-posts to double-entry ledger!)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate_invoices'])) {
    $month_year = $_POST['month_year'] ?? date('Y-m');
    $amount = (float)($_POST['default_amount'] ?? 0);
    $uid = $_SESSION['user_id'];
    
    if ($amount > 0) {
        $st_stmt = $pdo->prepare("SELECT id, name FROM students WHERE status = 'Active' AND (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = ?");
        $st_stmt->execute([$cur_tenant_id]);
        $students = $st_stmt->fetchAll(PDO::FETCH_ASSOC);

        $generated_count = 0;
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
                
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO acc_invoices (student_id, invoice_no, month_year, amount, status, created_by, tenant_id) VALUES (?, ?, ?, ?, 'Unpaid', ?, ?)");
                $stmt->execute([$st['id'], $inv_no, $month_year, $final_amount, $uid, $cur_tenant_id]);
                $inv_id = (int)$pdo->lastInsertId();

                // D9 Fix: Post Dr Student Receivable (1101) / Cr Fee Income (4001) Voucher!
                $v_no = "INV-V-" . str_pad($inv_id, 5, '0', STR_PAD_LEFT);
                $desc = "فیس انوائس # $inv_no - طالب علم: {$st['name']} (ماہ $month_year)";
                $vIns = $pdo->prepare("INSERT INTO acc_vouchers (voucher_no, date, type, reference, description, status, created_by, system_type, tenant_id, is_deleted) VALUES (?, ?, 'Journal', ?, ?, 'Approved', ?, ?, ?, 0)");
                $vIns->execute([$v_no, date('Y-m-d'), $inv_no, $desc, $uid, $sys, $cur_tenant_id]);
                $v_id = (int)$pdo->lastInsertId();

                $eIns = $pdo->prepare("INSERT INTO acc_ledger_entries (voucher_id, account_id, debit, credit, tenant_id) VALUES (?, ?, ?, ?, ?)");
                // Dr Student Fees Receivable
                $eIns->execute([$v_id, $rec_acc_id, $final_amount, 0, $cur_tenant_id]);
                // Cr Fee Income
                $eIns->execute([$v_id, $fee_acc_id, 0, $final_amount, $cur_tenant_id]);

                acc_log_audit($pdo, $cur_tenant_id, 'acc_invoices', $inv_id, 'GENERATE_INVOICE_WITH_VOUCHER', null, [
                    'invoice_no' => $inv_no, 'student_id' => $st['id'], 'amount' => $final_amount, 'voucher_id' => $v_id
                ], $uid, $_SESSION['name'] ?? 'Admin', 'Web');

                $pdo->commit();
                $generated_count++;
            }
        }
        $msg = "کامیابی: $generated_count طلبہ کی فیس انوائسز جاری ہوئیں اور ان کا ریسیویبل واؤچر (Dr 1101 / Cr 4001) براہ راست جنرل لیجر میں درج کر دیا گیا!";
    }
}

// Update Status: Mark Paid (D9 Fix: Auto-settles receivable with Cash!)
if (isset($_GET['mark_paid']) && is_numeric($_GET['mark_paid'])) {
    $inv_id = (int)$_GET['mark_paid'];
    $iStmt = $pdo->prepare("SELECT i.*, s.name as student_name FROM acc_invoices i JOIN students s ON i.student_id = s.id WHERE i.id = ? AND i.tenant_id = ? LIMIT 1");
    $iStmt->execute([$inv_id, $cur_tenant_id]);
    $inv = $iStmt->fetch(PDO::FETCH_ASSOC);

    if ($inv && $inv['status'] === 'Unpaid') {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE acc_invoices SET status = 'Paid' WHERE id = ? AND tenant_id = ?")->execute([$inv_id, $cur_tenant_id]);

        // Post Receipt Voucher: Dr Cash (1001) / Cr Student Receivable (1101)
        $pay_ref = 'PAY-' . $inv['invoice_no'];
        $v_no = "PAY-V-" . str_pad($inv_id, 5, '0', STR_PAD_LEFT);
        $desc = "فیس وصولی برائے انوائس # {$inv['invoice_no']} - طالب علم: {$inv['student_name']}";
        
        $vIns = $pdo->prepare("INSERT INTO acc_vouchers (voucher_no, date, type, reference, description, status, created_by, system_type, tenant_id, is_deleted) VALUES (?, ?, 'Receipt', ?, ?, 'Approved', ?, ?, ?, 0)");
        $vIns->execute([$v_no, date('Y-m-d'), $pay_ref, $desc, $user['id'], $sys, $cur_tenant_id]);
        $v_id = (int)$pdo->lastInsertId();

        $eIns = $pdo->prepare("INSERT INTO acc_ledger_entries (voucher_id, account_id, debit, credit, tenant_id) VALUES (?, ?, ?, ?, ?)");
        // Dr Cash in Hand (1001)
        $eIns->execute([$v_id, $cash_acc_id, (float)$inv['amount'], 0, $cur_tenant_id]);
        // Cr Student Fees Receivable (1101) - Settles the due!
        $eIns->execute([$v_id, $rec_acc_id, 0, (float)$inv['amount'], $cur_tenant_id]);

        acc_log_audit($pdo, $cur_tenant_id, 'acc_invoices', $inv_id, 'MARK_PAID_WITH_RECEIPT_VOUCHER', ['status' => 'Unpaid'], [
            'status' => 'Paid', 'voucher_id' => $v_id, 'amount' => $inv['amount']
        ], $user['id'], $_SESSION['name'] ?? 'Admin', 'Web');

        $pdo->commit();
        $msg = "فیس وصولی کامیابی سے درج ہو گئی۔ نقد کیش ڈیبٹ اور طالب علم کا کھاتہ واجب الوصول کریڈٹ ہو گیا۔";
    }
}

// Current Receivable Balance directly from the General Ledger
$total_invoiced = (float)($pdo->query("SELECT SUM(amount) FROM acc_invoices WHERE tenant_id = $cur_tenant_id AND status != 'Cancelled'")->fetchColumn() ?? 0);
$total_paid = (float)($pdo->query("SELECT SUM(amount) FROM acc_invoices WHERE tenant_id = $cur_tenant_id AND status = 'Paid'")->fetchColumn() ?? 0);
$total_unpaid = (float)($pdo->query("SELECT SUM(amount) FROM acc_invoices WHERE tenant_id = $cur_tenant_id AND status = 'Unpaid'")->fetchColumn() ?? 0);

$inv_stmt = $pdo->prepare("
    SELECT i.*, s.name as student_name, s.admission_number 
    FROM acc_invoices i
    JOIN students s ON i.student_id = s.id
    WHERE (s.is_deleted = 0 OR s.is_deleted IS NULL) AND i.tenant_id = ? AND s.tenant_id = ?
    ORDER BY i.id DESC LIMIT 100
");
$inv_stmt->execute([$cur_tenant_id, $cur_tenant_id]);
$invoices = $inv_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Fee Invoices - ERP Accounting";
include 'header.php';
?>

<div class="container py-4 urdu-text" style="font-family: 'Noto Nastaliq Urdu', serif;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-primary m-0"><i class="fas fa-file-invoice me-2"></i> تعلیمی فیس انوائسز (Fee Invoices & Receivables)</h3>
            <small class="text-muted">ڈبل انٹری فیس ریسیویبل کنٹرول کھاتہ (<?php echo htmlspecialchars($rec_code); ?> Student Fees Receivable)</small>
        </div>
        <button class="btn btn-warning fw-bold text-dark shadow" data-bs-toggle="modal" data-bs-target="#generateModal">
            <i class="fas fa-magic me-1"></i> اجتماعی فیس انوائسز جاری کریں
        </button>
    </div>

    <!-- D9 KPI Cards tying invoices directly to the General Ledger -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="stat-card bg-gradient-blue shadow text-white p-3 rounded">
                <div class="fs-6 text-uppercase">کل جاری شدہ فیس انوائسز</div>
                <div class="fs-3 fw-bold">Rs. <?php echo number_format($total_invoiced, 2); ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card bg-gradient-green shadow text-white p-3 rounded">
                <div class="fs-6 text-uppercase">کل وصول شدہ فیس (Paid)</div>
                <div class="fs-3 fw-bold">Rs. <?php echo number_format($total_paid, 2); ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card bg-gradient-red shadow text-white p-3 rounded">
                <div class="fs-6 text-uppercase">بقایا طلبہ فیس واجب الوصول (<?php echo htmlspecialchars($rec_code); ?>)</div>
                <div class="fs-3 fw-bold">Rs. <?php echo number_format($total_unpaid, 2); ?></div>
            </div>
        </div>
    </div>

    <?php if(!empty($msg)): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm">
        <i class="fas fa-check-circle me-2"></i> <?php echo $msg; ?>
        <button class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 neobox">
        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle table-sm text-center">
                    <thead class="table-dark">
                        <tr>
                            <th>انوائس #</th>
                            <th>ماہ</th>
                            <th>طالب علم</th>
                            <th class="text-end">رقم</th>
                            <th>حالت (Status)</th>
                            <th class="text-center">ایکشن</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($invoices as $inv): ?>
                        <tr>
                            <td class="fw-bold text-primary"><?php echo htmlspecialchars($inv['invoice_no']); ?></td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($inv['month_year']); ?></span></td>
                            <td><?php echo htmlspecialchars($inv['student_name']); ?> <small class="text-muted">(<?php echo htmlspecialchars($inv['admission_number']); ?>)</small></td>
                            <td class="fw-bold text-end">Rs. <?php echo number_format($inv['amount'], 2); ?></td>
                            <td>
                                <?php if($inv['status'] == 'Paid'): ?>
                                    <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i> وصول شدہ (Paid)</span>
                                <?php elseif($inv['status'] == 'Cancelled'): ?>
                                    <span class="badge bg-danger">منسوخ شدہ</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i> واجب الادا (Unpaid)</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if($inv['status'] == 'Unpaid'): ?>
                                    <a href="?mark_paid=<?php echo $inv['id']; ?>" class="btn btn-sm btn-success fw-bold" title="فیس وصول کریں (Mark as Paid)" onclick="return confirm('کیا فیس وصول ہو گئی ہے؟ تصدیق سے نقد کیش اور ریسیویبل واؤچر پوسٹ ہو جائے گا۔')">
                                        <i class="fas fa-check me-1"></i> وصول کریں
                                    </a>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-outline-primary" onclick="window.print()" title="پرنٹ"><i class="fas fa-print"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($invoices)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">کوئی فیس انوائس نہیں ملی۔</td></tr>
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
        <div class="modal-content urdu-text" style="font-family: 'Noto Nastaliq Urdu', serif;">
            <form method="POST">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title fw-bold"><i class="fas fa-magic me-2"></i> اجتماعی فیس انوائسز جاری کریں</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="fw-bold mb-1">انوائس کا مہینہ و سال</label>
                        <input type="month" name="month_year" class="form-control" value="<?php echo date('Y-m'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold mb-1">بنیادی ماہانہ فیس (روپے)</label>
                        <input type="number" step="0.01" name="default_amount" class="form-control" required placeholder="مثلاً 1500">
                        <small class="text-muted">تمام فعال طلبہ کے نام انوائس جاری ہوگی (دارالاقامہ ہاسٹل چارجز خودکار شامل ہوں گے) اور ریسیویبل واؤچر لیجر میں پوسٹ ہو جائے گا۔</small>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">منسوخ کریں</button>
                    <button type="submit" name="generate_invoices" class="btn btn-warning fw-bold text-dark"><i class="fas fa-cogs me-1"></i> انوائسز جاری کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
