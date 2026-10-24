<?php
/**
 * ════════════════════════════════════════════════════════════════════
 *  سوپر ایڈمن ڈیش بورڈ (SaaS Super Admin Dashboard)
 * ════════════════════════════════════════════════════════════════════
 */

$page_title = 'کلاؤڈ SaaS میٹرکس و اوور ویو';
require_once __DIR__ . '/header.php';

$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. اسٹیٹس تبدیل کرنا (Change Status)
    if ($action === 'update_status') {
        $tenant_id = (int)($_POST['tenant_id'] ?? 0);
        $new_status = $_POST['status'] ?? 'active';
        if (in_array($new_status, ['active', 'trial', 'suspended', 'expired', 'cancelled'])) {
            try {
                $stmt = $pdo->prepare("UPDATE tenants SET status = ? WHERE id = ?");
                $stmt->execute([$new_status, $tenant_id]);
                $stmtSub = $pdo->prepare("UPDATE subscriptions SET status = ? WHERE tenant_id = ? ORDER BY id DESC LIMIT 1");
                $stmtSub->execute([$new_status, $tenant_id]);

                $logStmt = $pdo->prepare("INSERT INTO tenant_activity_log (tenant_id, user_id, action, details, ip_address, created_at) VALUES (?, ?, 'status_changed', ?, ?, NOW())");
                $logStmt->execute([$tenant_id, $_SESSION['super_admin_id'] ?? 1, "اسٹیٹس تبدیل ہو کر {$new_status} ہوا", $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);

                $msg = "ادارے کا اسٹیٹس کامیابی سے '{$new_status}' کر دیا گیا۔";
            } catch (Throwable $e) {
                $error = "خرابی: " . $e->getMessage();
            }
        }
    }

    // 2. سبسکرپشن میں توسیع (Extend Subscription)
    if ($action === 'extend_subscription') {
        $tenant_id = (int)($_POST['tenant_id'] ?? 0);
        $plan_id = (int)($_POST['plan_id'] ?? 1);
        $months = (int)($_POST['months'] ?? 1);
        try {
            $curSub = $pdo->prepare("SELECT id, ends_at FROM subscriptions WHERE tenant_id = ? ORDER BY id DESC LIMIT 1");
            $curSub->execute([$tenant_id]);
            $lastSubRow = $curSub->fetch(PDO::FETCH_ASSOC);
            $lastEnd = $lastSubRow ? $lastSubRow['ends_at'] : null;

            $baseDate = ($lastEnd && strtotime($lastEnd) > time()) ? $lastEnd : date('Y-m-d');
            $newEnd = date('Y-m-d', strtotime("{$baseDate} +{$months} months"));

            if ($lastSubRow && !empty($lastSubRow['id'])) {
                $upSub = $pdo->prepare("UPDATE subscriptions SET plan_id = ?, status = 'active', ends_at = ? WHERE id = ?");
                $upSub->execute([$plan_id, $newEnd, $lastSubRow['id']]);
            } else {
                $upSub = $pdo->prepare("INSERT INTO subscriptions (tenant_id, plan_id, status, starts_at, ends_at, created_at) VALUES (?, ?, 'active', CURDATE(), ?, NOW())");
                $upSub->execute([$tenant_id, $plan_id, $newEnd]);
            }

            $pdo->prepare("UPDATE tenants SET status = 'active' WHERE id = ?")->execute([$tenant_id]);

            $logStmt = $pdo->prepare("INSERT INTO tenant_activity_log (tenant_id, user_id, action, details, ip_address, created_at) VALUES (?, ?, 'subscription_extended', ?, ?, NOW())");
            $logStmt->execute([$tenant_id, $_SESSION['super_admin_id'] ?? 1, "سبسکرپشن پلان ID {$plan_id} کے ساتھ {$newEnd} تک بڑھائی گئی", $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);

            $msg = "سبسکرپشن کامیابی سے {$newEnd} تک بڑھا دی گئی ہے۔";
        } catch (Throwable $e) {
            $error = "خرابی: " . $e->getMessage();
        }
    }

    // 3. ایڈمن یوزرنیم و پاسورڈ تبدیل کرنا (Update Admin Credentials)
    if ($action === 'update_admin_credentials') {
        $tenant_id = (int)($_POST['tenant_id'] ?? 0);
        $admin_username = trim($_POST['admin_username'] ?? '');
        $admin_password = $_POST['admin_password'] ?? '';
        $admin_name = trim($_POST['admin_name'] ?? '');

        if ($tenant_id <= 0 || empty($admin_username)) {
            $error = "ادارے کا انتخاب اور ایڈمن یوزرنیم درج کرنا لازمی ہے۔";
        } else {
            try {
                $chkUser = $pdo->prepare("SELECT id FROM admins WHERE username = ? AND tenant_id != ? LIMIT 1");
                $chkUser->execute([$admin_username, $tenant_id]);
                if ($chkUser->fetch()) {
                    $error = "یہ یوزرنیم ({$admin_username}) پہلے سے زیرِ استعمال ہے۔";
                } else {
                    $admStmt = $pdo->prepare("SELECT id, name FROM admins WHERE tenant_id = ? ORDER BY id ASC LIMIT 1");
                    $admStmt->execute([$tenant_id]);
                    $existingAdmin = $admStmt->fetch(PDO::FETCH_ASSOC);

                    if ($existingAdmin) {
                        $aId = (int)$existingAdmin['id'];
                        $targetName = !empty($admin_name) ? $admin_name : $existingAdmin['name'];
                        if (!empty($admin_password)) {
                            $hash = password_hash($admin_password, PASSWORD_DEFAULT);
                            $pdo->prepare("UPDATE admins SET name = ?, username = ?, password = ? WHERE id = ? AND tenant_id = ?")->execute([$targetName, $admin_username, $hash, $aId, $tenant_id]);
                        } else {
                            $pdo->prepare("UPDATE admins SET name = ?, username = ? WHERE id = ? AND tenant_id = ?")->execute([$targetName, $admin_username, $aId, $tenant_id]);
                        }
                    } else {
                        $passToUse = !empty($admin_password) ? $admin_password : 'admin123';
                        $hash = password_hash($passToUse, PASSWORD_DEFAULT);
                        $pdo->prepare("INSERT INTO admins (tenant_id, name, username, password, role, system_type, created_at) VALUES (?, ?, ?, ?, 'admin', 'Madrasa', NOW())")->execute([$tenant_id, $admin_name ?: 'ایڈمنسٹریٹر', $admin_username, $hash]);
                    }
                    $msg = "ادارے کے ایڈمن کے یوزرنیم و کوائف کامیابی سے اپڈیٹ کر دیے گئے!";
                }
            } catch (Throwable $e) {
                $error = "خرابی: " . $e->getMessage();
            }
        }
    }

    // 4. ادارہ ڈیلیٹ کرنا (Delete Tenant)
    if ($action === 'delete_tenant') {
        $tenant_id = (int)($_POST['tenant_id'] ?? 0);
        if ($tenant_id <= 1) {
            $error = "مرکزی سسٹم یا ڈیفالٹ ادارے (MAIN01) کو ڈیلیٹ کرنے کی اجازت نہیں ہے۔";
        } else {
            try {
                $tInfo = $pdo->query("SELECT name, code FROM tenants WHERE id = $tenant_id")->fetch(PDO::FETCH_ASSOC);
                if ($tInfo) {
                    $pdo->beginTransaction();
                    $tables = [
                        'admins', 'students', 'teachers', 'classes', 'class_periods', 'attendance', 'period_attendance',
                        'attendance_teachers', 'teacher_period_attendance', 'teacher_leaves', 'daily_hifz', 'daily_nazira',
                        'qaida_records', 'academic_reports', 'exams', 'exam_results',
                        'parent_complaints', 'complaints', 'fee_submissions', 'admission_applications', 'admission_inquiries',
                        'notices', 'activity_logs', 'leave_applications', 'heads', 'finance', 'masjid_finance',
                        'staff_loans', 'loan_transactions', 'staff_salaries', 'staff_stipends', 'assets',
                        'in_kind_donations', 'inventory_items', 'inventory_sales', 'hostel_rooms',
                        'hostel_allocations', 'lib_books', 'lib_issues', 'web_pages', 'announcements',
                        'job_postings', 'job_applicants', 'settings', 'smart_notification_reads',
                        'acc_chart_of_accounts', 'acc_vouchers', 'acc_ledger_entries', 'acc_invoices',
                        'acc_payroll', 'acc_departments', 'maktab_classes', 'maktab_teachers',
                        'course_structure', 'course_subjects', 'student_course_enrollment', 'exam_subject_results', 'student_year_promotion',
                        'subscriptions', 'payments', 'tenant_activity_log'
                    ];
                    foreach ($tables as $tbl) {
                        try { $pdo->prepare("DELETE FROM `$tbl` WHERE tenant_id = ?")->execute([$tenant_id]); } catch (Throwable $eTbl) {}
                    }
                    $pdo->prepare("DELETE FROM tenants WHERE id = ?")->execute([$tenant_id]);
                    $pdo->commit();
                    $msg = "ادارہ '{$tInfo['name']}' مع تمام متعلقہ ریکارڈز مکمل ڈیلیٹ کر دیا گیا!";
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = "خرابی: " . $e->getMessage();
            }
        }
    }
}

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
                                  (SELECT COUNT(*) FROM students st WHERE st.tenant_id = t.id AND (st.is_deleted = 0 OR st.is_deleted IS NULL)) as std_count,
                                  (SELECT a.username FROM admins a WHERE a.tenant_id = t.id ORDER BY a.id ASC LIMIT 1) as admin_username,
                                  (SELECT a.name FROM admins a WHERE a.tenant_id = t.id ORDER BY a.id ASC LIMIT 1) as admin_name
                                  FROM tenants t 
                                  LEFT JOIN subscriptions s ON t.id = s.tenant_id AND s.id = (SELECT MAX(id) FROM subscriptions WHERE tenant_id = t.id)
                                  LEFT JOIN subscription_plans p ON s.plan_id = p.id
                                  ORDER BY t.id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$plans = [];
try {
    $plans = $pdo->query("SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY price ASC")->fetchAll(PDO::FETCH_ASSOC);
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
                            <div class="dropdown d-inline-block">
                                <button class="btn btn-sm dropdown-toggle rounded-pill px-3 py-1 fw-bold shadow-sm <?php echo $t['status'] === 'active' ? 'btn-success' : ($t['status'] === 'trial' ? 'btn-warning text-dark' : 'btn-danger'); ?>" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="اسٹیٹس تبدیل کرنے کے لیے کلک کریں">
                                    <i class="fas <?php echo $t['status'] === 'active' ? 'fa-check-circle' : ($t['status'] === 'trial' ? 'fa-clock' : 'fa-ban'); ?> me-1"></i>
                                    <?php echo $t['status'] === 'active' ? 'فعال (Active)' : ($t['status'] === 'trial' ? 'ٹرائل (Trial)' : 'معطل (Suspended)'); ?>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow border">
                                    <li><h6 class="dropdown-header text-muted small">نیا اسٹیٹس منتخب کریں:</h6></li>
                                    <li>
                                        <form method="POST" class="m-0 p-0">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="tenant_id" value="<?php echo $t['id']; ?>">
                                            <input type="hidden" name="status" value="active">
                                            <button type="submit" class="dropdown-item py-2 text-success <?php echo $t['status'] === 'active' ? 'fw-bold active' : ''; ?>">
                                                <i class="fas fa-check-circle me-2"></i> فعال کریں (Active)
                                            </button>
                                        </form>
                                    </li>
                                    <li>
                                        <form method="POST" class="m-0 p-0">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="tenant_id" value="<?php echo $t['id']; ?>">
                                            <input type="hidden" name="status" value="trial">
                                            <button type="submit" class="dropdown-item py-2 text-warning <?php echo $t['status'] === 'trial' ? 'fw-bold active' : ''; ?>">
                                                <i class="fas fa-clock me-2"></i> ٹرائل موڈ (Trial)
                                            </button>
                                        </form>
                                    </li>
                                    <li>
                                        <form method="POST" class="m-0 p-0">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="tenant_id" value="<?php echo $t['id']; ?>">
                                            <input type="hidden" name="status" value="suspended">
                                            <button type="submit" class="dropdown-item py-2 text-danger <?php echo $t['status'] === 'suspended' ? 'fw-bold active' : ''; ?>">
                                                <i class="fas fa-ban me-2"></i> معطل کریں (Suspend)
                                            </button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
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
                                <a href="impersonate.php?tenant_id=<?php echo $t['id']; ?>" class="btn btn-sm btn-info rounded-pill px-3 fw-bold text-white shadow-sm" title="ادارے میں داخل ہوں">
                                    <i class="fas fa-sign-in-alt me-1"></i> لاگ ان
                                </a>

                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle rounded-pill px-3" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-popper-config='{"strategy":"fixed"}'>
                                        انتظام
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow border">
                                        <li>
                                            <a class="dropdown-item py-2" href="javascript:void(0)" onclick="openExtendModal(<?php echo $t['id']; ?>, '<?php echo htmlspecialchars(addslashes($t['name'])); ?>')">
                                                <i class="fas fa-calendar-plus me-2 text-info"></i> سبسکرپشن میں توسیع
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item py-2" href="javascript:void(0)" onclick="openCredentialsModal(<?php echo $t['id']; ?>, '<?php echo htmlspecialchars(addslashes($t['name'])); ?>', '<?php echo htmlspecialchars(addslashes($t['admin_username'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($t['admin_name'] ?? '')); ?>')">
                                                <i class="fas fa-key me-2 text-info"></i> یوزرنیم و پاسورڈ تبدیل کریں
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item py-2" href="payments.php?search=<?php echo urlencode($t['code']); ?>">
                                                <i class="fas fa-receipt me-2 text-success"></i> ادائیگیاں دیکھیں
                                            </a>
                                        </li>
                                        <li><hr class="dropdown-divider my-1"></li>
                                        <li>
                                            <form method="POST" onsubmit="return confirm('کیا آپ واقعی اس ادارے کا اسٹیٹس تبدیل کرنا چاہتے ہیں؟');" class="m-0 p-0">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="tenant_id" value="<?php echo $t['id']; ?>">
                                                <?php if ($t['status'] === 'suspended'): ?>
                                                    <input type="hidden" name="status" value="active">
                                                    <button type="submit" class="dropdown-item text-success py-2"><i class="fas fa-play me-2"></i> بحال کریں (Activate)</button>
                                                <?php else: ?>
                                                    <input type="hidden" name="status" value="suspended">
                                                    <button type="submit" class="dropdown-item text-danger py-2"><i class="fas fa-ban me-2"></i> معطل کریں (Suspend)</button>
                                                <?php endif; ?>
                                            </form>
                                        </li>
                                        <?php if ($t['id'] != 1 && strtoupper((string)$t['code']) !== 'MAIN01'): ?>
                                            <li><hr class="dropdown-divider my-1"></li>
                                            <li>
                                                <a class="dropdown-item py-2 text-danger fw-bold" href="javascript:void(0)" onclick="openDeleteTenantModal(<?php echo $t['id']; ?>, '<?php echo htmlspecialchars(addslashes($t['name'])); ?>', '<?php echo htmlspecialchars(addslashes($t['code'])); ?>')">
                                                    <i class="fas fa-trash-alt me-2"></i> ادارہ مکمل ڈیلیٹ کریں
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Extend Subscription -->
<div class="modal fade" id="extendSubModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="extend_subscription">
                <input type="hidden" name="tenant_id" id="extendTenantId">
                <div class="modal-header" style="border-color: var(--sa-border);">
                    <h5 class="modal-title fw-bold" style="color: var(--sa-text-main);"><i class="fas fa-calendar-plus me-2 text-info"></i> سبسکرپشن میں توسیع</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3 p-3 rounded" style="background: var(--sa-input-bg); border: 1px solid var(--sa-border);">
                        <span style="color: var(--sa-text-muted);">ادارہ:</span>
                        <strong id="extendTenantName" class="text-primary fs-6 ms-1"></strong>
                    </div>
                    <div class="mb-3">
                        <label class="form-label stat-label">پیکج منتخب کریں</label>
                        <select name="plan_id" class="form-select">
                            <?php foreach ($plans as $p): ?>
                                <option value="<?php echo $p['id']; ?>">
                                    <?php echo htmlspecialchars($p['name']); ?> — (روپے <?php echo number_format($p['price']); ?> / ماہ)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label stat-label">کتنے ماہ کی توسیع کرنی ہے؟</label>
                        <select name="months" class="form-select">
                            <option value="1">1 ماہ</option>
                            <option value="3">3 ماہ</option>
                            <option value="6">6 ماہ</option>
                            <option value="12" selected>12 ماہ (1 سال)</option>
                            <option value="24">24 ماہ (2 سال)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer" style="border-color: var(--sa-border);">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" class="btn btn-info px-4 fw-bold text-white">توسیع نافذ کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit Tenant Admin Credentials -->
<div class="modal fade" id="editCredentialsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="update_admin_credentials">
                <input type="hidden" name="tenant_id" id="credTenantId">
                <div class="modal-header" style="border-color: var(--sa-border);">
                    <h5 class="modal-title fw-bold" style="color: var(--sa-text-main);">
                        <i class="fas fa-key me-2 text-info"></i> ایڈمن یوزرنیم و پاسورڈ تبدیل کریں
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3 p-3 rounded" style="background: var(--sa-input-bg); border: 1px solid var(--sa-border);">
                        <span style="color: var(--sa-text-muted);">ادارہ:</span>
                        <strong id="credTenantName" class="text-primary fs-6 ms-1"></strong>
                    </div>

                    <div class="mb-3">
                        <label class="form-label stat-label">ایڈمن کا نام (Admin Full Name)</label>
                        <input type="text" name="admin_name" id="credAdminName" class="form-control" placeholder="مثال: مولانا عبداللہ">
                    </div>

                    <div class="mb-3">
                        <label class="form-label stat-label">لاگ ان یوزرنیم (Username) *</label>
                        <div class="input-group">
                            <span class="input-group-text bg-transparent border-end-0" style="color: var(--sa-text-muted);"><i class="fas fa-user"></i></span>
                            <input type="text" name="admin_username" id="credAdminUsername" class="form-control font-monospace border-start-0" required placeholder="admin_code">
                        </div>
                        <small style="color: var(--sa-text-muted);">یہ یوزرنیم تمام اداروں میں منفرد ہونا ضروری ہے</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label stat-label">نیا پاسورڈ (New Password)</label>
                        <div class="input-group">
                            <span class="input-group-text bg-transparent border-end-0" style="color: var(--sa-text-muted);"><i class="fas fa-lock"></i></span>
                            <input type="password" name="admin_password" id="credAdminPassword" class="form-control font-monospace border-start-0 border-end-0" placeholder="نیا پاسورڈ درج کریں">
                            <button class="btn btn-outline-secondary border-start-0" type="button" onclick="togglePasswordVisibility('credAdminPassword', 'credEyeIcon')">
                                <i class="fas fa-eye" id="credEyeIcon"></i>
                            </button>
                        </div>
                        <small class="text-warning">اگر موجودہ پرانا پاسورڈ برقرار رکھنا ہے تو اسے خالی چھوڑ دیں</small>
                    </div>
                </div>
                <div class="modal-footer" style="border-color: var(--sa-border);">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" class="btn btn-info px-4 fw-bold text-white">
                        <i class="fas fa-check-circle me-1"></i> کوائف محفوظ کریں
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Delete Tenant Confirmation -->
<div class="modal fade" id="deleteTenantModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-danger">
            <form method="POST">
                <input type="hidden" name="action" value="delete_tenant">
                <input type="hidden" name="tenant_id" id="delTenantId">
                <div class="modal-header bg-danger bg-opacity-10 border-danger">
                    <h5 class="modal-title fw-bold text-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i> ادارہ ڈیلیٹ کرنے کی حتمی تصدیق
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center my-3">
                        <div class="rounded-circle bg-danger bg-opacity-20 d-inline-flex p-3 mb-3 text-danger">
                            <i class="fas fa-trash-alt fa-3x"></i>
                        </div>
                        <h5 class="fw-bold" style="color: var(--sa-text-main);">کیا آپ واقعی اس ادارے کو مکمل ڈیلیٹ کرنا چاہتے ہیں؟</h5>
                    </div>

                    <div class="p-3 rounded mb-3" style="background: var(--sa-input-bg); border: 1px solid var(--sa-border);">
                        <div class="mb-1">
                            <span style="color: var(--sa-text-muted);">ادارے کا نام:</span>
                            <strong id="delTenantName" class="text-white fs-6 ms-1"></strong>
                        </div>
                        <div>
                            <span style="color: var(--sa-text-muted);">ادارہ کوڈ:</span>
                            <strong id="delTenantCode" class="text-danger font-monospace fs-6 ms-1"></strong>
                        </div>
                    </div>

                    <div class="alert alert-danger border-0 d-flex align-items-center gap-2 mb-0 py-2">
                        <i class="fas fa-shield-virus fa-lg text-danger"></i>
                        <small class="mb-0"><strong>تنبیہ:</strong> اس ادارے کا تمام ڈیٹا (طلبہ، اساتذہ، حاضری، فنانس، فیسیں، کلاسز اور ایڈمن اکاؤنٹس) مستقل طور پر صاف ہو جائے گا۔ یہ عمل ناقابلِ واپسی ہے!</small>
                    </div>
                </div>
                <div class="modal-footer" style="border-color: var(--sa-border);">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" class="btn btn-danger px-4 fw-bold">
                        <i class="fas fa-trash me-1"></i> جی ہاں، مکمل ڈیلیٹ کریں
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openExtendModal(tenantId, tenantName) {
    document.getElementById('extendTenantId').value = tenantId;
    document.getElementById('extendTenantName').innerText = tenantName;
    new bootstrap.Modal(document.getElementById('extendSubModal')).show();
}

function openCredentialsModal(tenantId, tenantName, adminUsername, adminName) {
    document.getElementById('credTenantId').value = tenantId;
    document.getElementById('credTenantName').innerText = tenantName;
    document.getElementById('credAdminUsername').value = adminUsername || '';
    document.getElementById('credAdminName').value = adminName || '';
    document.getElementById('credAdminPassword').value = '';
    new bootstrap.Modal(document.getElementById('editCredentialsModal')).show();
}

function openDeleteTenantModal(tenantId, tenantName, tenantCode) {
    document.getElementById('delTenantId').value = tenantId;
    document.getElementById('delTenantName').innerText = tenantName;
    document.getElementById('delTenantCode').innerText = tenantCode;
    new bootstrap.Modal(document.getElementById('deleteTenantModal')).show();
}

function togglePasswordVisibility(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    if (!input || !icon) return;
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
