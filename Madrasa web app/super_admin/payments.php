<?php
/**
 * ════════════════════════════════════════════════════════════════════
 *  سوپر ایڈمن — وصولیاں و فنانس (Payments & Billing Management)
 * ════════════════════════════════════════════════════════════════════
 */

$page_title = 'وصولیاں و فنانس مینجمنٹ (Payments & Billing)';
require_once __DIR__ . '/header.php';

$msg = '';
$error = '';

// Handle Manual Payment Entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_payment') {
    $tenant_id = (int)($_POST['tenant_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $method = trim($_POST['method'] ?? 'manual');
    $reference_no = trim($_POST['reference_no'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $extend_months = (int)($_POST['extend_months'] ?? 1);
    $paid_at = !empty($_POST['paid_at']) ? $_POST['paid_at'] : date('Y-m-d H:i:s');

    if ($tenant_id <= 0 || $amount <= 0) {
        $error = "ادارہ منتخب کرنا اور درست رقم درج کرنا لازمی ہے۔";
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Record Payment
            $pStmt = $pdo->prepare("INSERT INTO payments (tenant_id, amount, currency, method, reference_no, status, paid_at, notes, created_at) 
                                    VALUES (?, ?, 'PKR', ?, ?, 'completed', ?, ?, NOW())");
            $pStmt->execute([$tenant_id, $amount, $method, $reference_no, $paid_at, $notes]);

            // 2. Extend subscription
            if ($extend_months > 0) {
                $curSub = $pdo->prepare("SELECT ends_at, plan_id FROM subscriptions WHERE tenant_id = ? ORDER BY id DESC LIMIT 1");
                $curSub->execute([$tenant_id]);
                $subData = $curSub->fetch(PDO::FETCH_ASSOC);

                $lastEnd = $subData['ends_at'] ?? null;
                $planId = $subData['plan_id'] ?? 1;

                $baseDate = ($lastEnd && strtotime($lastEnd) > time()) ? $lastEnd : date('Y-m-d');
                $newEnd = date('Y-m-d', strtotime("{$baseDate} +{$extend_months} months"));

                $pdo->prepare("UPDATE subscriptions SET status = 'active', ends_at = ? WHERE tenant_id = ?")
                    ->execute([$newEnd, $tenant_id]);

                $pdo->prepare("UPDATE tenants SET status = 'active' WHERE id = ?")
                    ->execute([$tenant_id]);
            }

            // 3. Log Activity
            $logStmt = $pdo->prepare("INSERT INTO tenant_activity_log (tenant_id, user_id, action, details, ip_address, created_at) 
                                      VALUES (?, ?, 'payment_recorded', ?, ?, NOW())");
            $logStmt->execute([$tenant_id, $_SESSION['super_admin_id'] ?? 1, "ادائیگی موصول: روپے {$amount} ({$method}) - میعاد میں {$extend_months} ماہ توسیع", $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);

            $pdo->commit();
            $msg = "ادائیگی باکامیاب درج کر دی گئی ہے اور ادارے کی سبسکرپشن میں {$extend_months} ماہ کی توسیع نافذ ہو گئی ہے!";
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "خرابی: " . $e->getMessage();
        }
    }
}

// Fetch Metrics
$totalCollected = 0;
$thisMonthCollected = 0;
$totalPaymentsCount = 0;
try {
    $totalCollected = (float)$pdo->query("SELECT SUM(amount) FROM payments WHERE status = 'completed'")->fetchColumn();
    $firstDay = date('Y-m-01 00:00:00');
    $stmtM = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE status = 'completed' AND paid_at >= ?");
    $stmtM->execute([$firstDay]);
    $thisMonthCollected = (float)$stmtM->fetchColumn();
    $totalPaymentsCount = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'completed'")->fetchColumn();
} catch (Throwable $e) {}

// Fetch Tenants for dropdown
$tenantsList = [];
try {
    $tenantsList = $pdo->query("SELECT id, name, code FROM tenants ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Fetch Payments List
$search = trim($_GET['search'] ?? '');
$filterMethod = $_GET['method'] ?? '';

$sql = "SELECT p.*, t.name as tenant_name, t.code as tenant_code 
        FROM payments p 
        LEFT JOIN tenants t ON p.tenant_id = t.id 
        WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND (t.name LIKE ? OR t.code LIKE ? OR p.reference_no LIKE ? OR p.notes LIKE ?)";
    $params = array_merge($params, ["%{$search}%", "%{$search}%", "%{$search}%", "%{$search}%"]);
}
if (!empty($filterMethod)) {
    $sql .= " AND p.method = ?";
    $params[] = $filterMethod;
}
$sql .= " ORDER BY p.id DESC LIMIT 100";

$payments = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
?>

<?php if (!empty($msg)): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
        <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i> <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Metrics Row -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
        <div class="card-stat">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">کل موصولہ رقم (Total Revenue)</div>
                    <div class="stat-value text-success">روپے <?php echo number_format($totalCollected); ?></div>
                </div>
                <span class="fs-1 text-success opacity-50"><i class="fas fa-coins"></i></span>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card-stat">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">رواں ماہ کی وصولی (This Month)</div>
                    <div class="stat-value text-primary">روپے <?php echo number_format($thisMonthCollected); ?></div>
                </div>
                <span class="fs-1 text-primary opacity-50"><i class="fas fa-calendar-check"></i></span>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card-stat">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">کل ٹرانزیکشنز کی تعداد</div>
                    <div class="stat-value" style="color: var(--sa-text-main);"><?php echo $totalPaymentsCount; ?></div>
                </div>
                <span class="fs-1 text-info opacity-50"><i class="fas fa-receipt"></i></span>
            </div>
        </div>
    </div>
</div>

<!-- Controls Bar -->
<div class="card-custom mb-4 py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <form method="GET" class="d-flex flex-wrap gap-2 flex-grow-1">
            <input type="text" name="search" class="form-control" style="max-width: 320px;" placeholder="ادارہ، حوالہ نمبر، تفصیل سے تلاش کریں..." value="<?php echo htmlspecialchars($search); ?>">
            <select name="method" class="form-select" style="max-width: 180px;">
                <option value="">تمام ذرائع (Methods)</option>
                <option value="bank" <?php echo $filterMethod==='bank'?'selected':''; ?>>بینک ٹرانسفر (Bank)</option>
                <option value="easypaisa" <?php echo $filterMethod==='easypaisa'?'selected':''; ?>>ایزی پیسہ (EasyPaisa)</option>
                <option value="jazzcash" <?php echo $filterMethod==='jazzcash'?'selected':''; ?>>جاز کیش (JazzCash)</option>
                <option value="cash" <?php echo $filterMethod==='cash'?'selected':''; ?>>کیش وصولی (Cash)</option>
            </select>
            <button type="submit" class="btn btn-outline-primary px-3 fw-bold">فلٹر</button>
            <?php if(!empty($search) || !empty($filterMethod)): ?>
                <a href="payments.php" class="btn btn-outline-secondary">ری سیٹ</a>
            <?php endif; ?>
        </form>

        <button class="btn btn-success rounded-pill fw-bold px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#recordPaymentModal">
            <i class="fas fa-plus-circle me-1"></i> نئی وصولی درج کریں
        </button>
    </div>
</div>

<!-- Payments Table -->
<div class="card-custom">
    <div class="table-responsive">
        <table class="table-custom">
            <thead>
                <tr>
                    <th>#</th>
                    <th>ادارہ</th>
                    <th>رقم (روپے)</th>
                    <th>ادائیگی کا ذریعہ</th>
                    <th>حوالہ / سلپ نمبر</th>
                    <th>تاریخ ادائیگی</th>
                    <th>تفصیل / نوٹس</th>
                    <th>حیثیت</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4" style="color: var(--sa-text-muted);">کوئی ادائیگی ریکارڈ نہیں ملی۔</td>
                    </tr>
                <?php else: foreach ($payments as $idx => $p): ?>
                    <tr>
                        <td><?php echo $idx + 1; ?></td>
                        <td>
                            <div class="fw-bold" style="color: var(--sa-text-main);"><?php echo htmlspecialchars($p['tenant_name'] ?: 'مرکزی ادارہ'); ?></div>
                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 font-monospace small">
                                <?php echo htmlspecialchars($p['tenant_code'] ?: 'MAIN01'); ?>
                            </span>
                        </td>
                        <td>
                            <span class="fw-bold text-success fs-6">روپے <?php echo number_format($p['amount']); ?></span>
                        </td>
                        <td>
                            <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 text-uppercase">
                                <?php echo htmlspecialchars($p['method']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="font-monospace"><?php echo htmlspecialchars($p['reference_no'] ?: '-'); ?></span>
                        </td>
                        <td>
                            <?php echo date('d M Y, h:i A', strtotime($p['paid_at'])); ?>
                        </td>
                        <td>
                            <small style="color: var(--sa-text-muted);"><?php echo htmlspecialchars($p['notes'] ?: '-'); ?></small>
                        </td>
                        <td>
                            <span class="badge bg-success">کامیاب (Completed)</span>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Record Payment -->
<div class="modal fade" id="recordPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="record_payment">
                <div class="modal-header" style="border-color: var(--sa-border);">
                    <h5 class="modal-title fw-bold" style="color: var(--sa-text-main);">
                        <i class="fas fa-hand-holding-usd text-success me-2"></i> نئی فیس / سبسکرپشن وصولی درج کریں
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label stat-label">ادارہ منتخب کریں *</label>
                            <select name="tenant_id" class="form-select" required>
                                <option value="">-- ادارہ منتخب کریں --</option>
                                <?php foreach ($tenantsList as $t): ?>
                                    <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?> (<?php echo htmlspecialchars($t['code']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label stat-label">موصولہ رقم (روپے) *</label>
                            <input type="number" name="amount" class="form-control" required placeholder="مثال: 5000">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label stat-label">ادائیگی کا ذریعہ (Payment Method) *</label>
                            <select name="method" class="form-select" required>
                                <option value="bank">بینک ٹرانسفر (Bank Transfer)</option>
                                <option value="easypaisa">ایزی پیسہ (EasyPaisa)</option>
                                <option value="jazzcash">جاز کیش (JazzCash)</option>
                                <option value="cash">کیش (Cash In Hand)</option>
                                <option value="cheque">چیک (Cheque)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label stat-label">حوالہ نمبر / رسید نمبر (Slip / TID)</label>
                            <input type="text" name="reference_no" class="form-control" placeholder="TID-987654321">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label stat-label">تاریخ ادائیگی</label>
                            <input type="datetime-local" name="paid_at" class="form-control" value="<?php echo date('Y-m-d\TH:i'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label stat-label">سبسکرپشن میں کتنے ماہ کی توسیع کرنی ہے؟</label>
                            <select name="extend_months" class="form-select">
                                <option value="0">کوئی توسیع نہیں (صرف لاگ کریں)</option>
                                <option value="1" selected>1 ماہ کی توسیع</option>
                                <option value="3">3 ماہ کی توسیع</option>
                                <option value="6">6 ماہ کی توسیع</option>
                                <option value="12">1 سال (12 ماہ) کی توسیع</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label stat-label">اضافی تفصیل یا نوٹس</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="بینک کا نام، بھیجنے والے کا نام وغیرہ..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-color: var(--sa-border);">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" class="btn btn-success px-4 fw-bold">وصولی محفوظ کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
