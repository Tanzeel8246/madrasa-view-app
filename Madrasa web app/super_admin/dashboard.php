<?php
/**
 * ════════════════════════════════════════════════════════════════════
 *  سوپر ایڈمن ڈیش بورڈ (SaaS Super Admin Dashboard)
 * ════════════════════════════════════════════════════════════════════
 */

$page_title = 'کلاؤڈ SaaS میٹرکس و اوور ویو';
require_once __DIR__ . '/header.php';

// Metrics
$totalTenants = 0;
$activeTenants = 0;
$trialTenants = 0;
$suspendedTenants = 0;
$totalStudents = 0;
$totalTeachers = 0;
$totalRevenue = 0;
$recentTenants = [];

try {
    $totalTenants = (int)$pdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
    $activeTenants = (int)$pdo->query("SELECT COUNT(*) FROM tenants WHERE status = 'active'")->fetchColumn();
    $trialTenants = (int)$pdo->query("SELECT COUNT(*) FROM tenants WHERE status = 'trial'")->fetchColumn();
    $suspendedTenants = (int)$pdo->query("SELECT COUNT(*) FROM tenants WHERE status = 'suspended'")->fetchColumn();

    $totalStudents = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE is_deleted = 0 OR is_deleted IS NULL")->fetchColumn();
    $totalTeachers = (int)$pdo->query("SELECT COUNT(*) FROM teachers WHERE is_deleted = 0 OR is_deleted IS NULL")->fetchColumn();
    $totalRevenue = (float)$pdo->query("SELECT SUM(amount) FROM payments WHERE status = 'completed'")->fetchColumn();

    $recentTenants = $pdo->query("SELECT t.*, p.name as plan_name, s.ends_at as subscription_ends,
                                  (SELECT COUNT(*) FROM students st WHERE st.tenant_id = t.id AND (st.is_deleted = 0 OR st.is_deleted IS NULL)) as std_count
                                  FROM tenants t 
                                  LEFT JOIN subscriptions s ON t.id = s.tenant_id AND s.status IN ('active','trial')
                                  LEFT JOIN subscription_plans p ON s.plan_id = p.id
                                  ORDER BY t.id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
?>

<!-- Quick Action Header -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h5 class="fw-bold mb-1" style="color: var(--sa-text-main);">اہم انتظامی میٹرکس و شماریات</h5>
        <small style="color: var(--sa-text-muted);">پلیٹ فارم کی مجموعی کارکردگی اور تمام اداروں کا خلاصہ</small>
    </div>
    <div class="d-flex gap-2">
        <a href="tenants.php?action=create" class="btn btn-primary rounded-pill fw-bold px-3 shadow-sm">
            <i class="fas fa-plus me-1"></i> نیا ادارہ شامل کریں
        </a>
        <a href="payments.php" class="btn btn-success rounded-pill fw-bold px-3 shadow-sm">
            <i class="fas fa-hand-holding-usd me-1"></i> وصولی درج کریں
        </a>
    </div>
</div>

<!-- Stats Grid -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card-stat">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">کل ادارے (Tenants)</div>
                    <div class="stat-value text-primary"><?php echo $totalTenants; ?></div>
                </div>
                <span class="fs-1 text-primary opacity-50"><i class="fas fa-building"></i></span>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="card-stat">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">فعال سبسکرپشنز</div>
                    <div class="stat-value text-success"><?php echo $activeTenants; ?></div>
                </div>
                <span class="fs-1 text-success opacity-50"><i class="fas fa-check-circle"></i></span>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="card-stat">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">14 دن مفت ٹرائل</div>
                    <div class="stat-value text-warning"><?php echo $trialTenants; ?></div>
                </div>
                <span class="fs-1 text-warning opacity-50"><i class="fas fa-clock"></i></span>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="card-stat">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">کل طلبہ (تمام ادارے)</div>
                    <div class="stat-value text-info"><?php echo $totalStudents; ?></div>
                </div>
                <span class="fs-1 text-info opacity-50"><i class="fas fa-user-graduate"></i></span>
            </div>
        </div>
    </div>
</div>

<!-- Secondary Stats Row -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-6 col-lg-4">
        <div class="card-custom">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">مجموعی ساس آمدن (Revenue)</div>
                    <div class="stat-value text-success" style="font-size: 1.8rem;">روپے <?php echo number_format($totalRevenue); ?></div>
                </div>
                <span class="fs-2 text-success opacity-75"><i class="fas fa-coins"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-lg-4">
        <div class="card-custom">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">کل رجسٹرڈ اساتذہ</div>
                    <div class="stat-value" style="font-size: 1.8rem;"><?php echo $totalTeachers; ?></div>
                </div>
                <span class="fs-2 text-primary opacity-75"><i class="fas fa-chalkboard-teacher"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-lg-4">
        <div class="card-custom">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">معطل شدہ ادارے</div>
                    <div class="stat-value text-danger" style="font-size: 1.8rem;"><?php echo $suspendedTenants; ?></div>
                </div>
                <span class="fs-2 text-danger opacity-75"><i class="fas fa-ban"></i></span>
            </div>
        </div>
    </div>
</div>

<!-- Recent Tenants Table -->
<div class="card-custom">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0" style="color: var(--sa-text-main);">
            <i class="fas fa-history text-primary me-2"></i> تازہ ترین رجسٹرڈ ادارے
        </h5>
        <a href="tenants.php" class="btn btn-outline-primary btn-sm rounded-pill px-3 fw-bold">
            تمام دیکھیں <i class="fas fa-arrow-left ms-1"></i>
        </a>
    </div>

    <div class="table-responsive">
        <table class="table-custom">
            <thead>
                <tr>
                    <th>کوڈ</th>
                    <th>ادارے کا نام</th>
                    <th>شہر / رابطہ</th>
                    <th>حیثیت (Status)</th>
                    <th>پیکج / پلان</th>
                    <th>طلبہ</th>
                    <th>رجسٹریشن تاریخ</th>
                    <th class="text-center">ایکشنز</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentTenants)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4" style="color: var(--sa-text-muted);">ابھی تک کوئی ادارہ رجسٹر نہیں ہوا۔</td>
                    </tr>
                <?php else: foreach ($recentTenants as $t): ?>
                    <tr>
                        <td>
                            <span class="badge px-3 py-1 font-monospace fs-6 fw-bold" style="background: rgba(14, 165, 233, 0.25); color: #ffffff !important; border: 1.5px solid #38bdf8; letter-spacing: 1px;">
                                <?php echo htmlspecialchars($t['code']); ?>
                            </span>
                        </td>
                        <td>
                            <div class="fw-bold" style="color: var(--sa-text-main); font-size: 1rem;"><?php echo htmlspecialchars($t['name']); ?></div>
                        </td>
                        <td>
                            <div><?php echo htmlspecialchars($t['city'] ?: 'پاکستان'); ?></div>
                            <small style="color: var(--sa-text-muted);"><?php echo htmlspecialchars($t['phone'] ?: '-'); ?></small>
                        </td>
                        <td>
                            <?php if ($t['status'] === 'active'): ?>
                                <span class="badge bg-success px-3 py-1">فعال (Active)</span>
                            <?php elseif ($t['status'] === 'trial'): ?>
                                <span class="badge bg-warning text-dark px-3 py-1">ٹرائل (Trial)</span>
                            <?php elseif ($t['status'] === 'suspended'): ?>
                                <span class="badge bg-danger px-3 py-1">معطل (Suspended)</span>
                            <?php else: ?>
                                <span class="badge bg-secondary px-3 py-1"><?php echo htmlspecialchars($t['status']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25">
                                <?php echo htmlspecialchars($t['plan_name'] ?: 'بنیادی'); ?>
                            </span>
                        </td>
                        <td>
                            <strong style="color: var(--sa-text-main);"><?php echo (int)($t['std_count'] ?? 0); ?></strong>
                        </td>
                        <td>
                            <small class="font-monospace" style="color: var(--sa-text-muted);">
                                <?php echo !empty($t['created_at']) ? date('d M Y', strtotime($t['created_at'])) : '-'; ?>
                            </small>
                        </td>
                        <td class="text-center">
                            <div class="d-flex justify-content-center gap-1">
                                <a href="impersonate.php?tenant_id=<?php echo $t['id']; ?>" class="btn btn-sm btn-outline-info rounded-pill px-2" title="ادارے میں داخل ہوں (Login as Admin)">
                                    <i class="fas fa-sign-in-alt me-1"></i> داخل ہوں
                                </a>
                                <a href="tenants.php?search=<?php echo urlencode($t['code']); ?>" class="btn btn-sm btn-outline-primary rounded-pill px-2" title="تفصیلات">
                                    <i class="fas fa-edit"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
