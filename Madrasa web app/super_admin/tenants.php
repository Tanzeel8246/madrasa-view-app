<?php
/**
 * ════════════════════════════════════════════════════════════════════
 *  سوپر ایڈمن — رجسٹرڈ ادارے (Tenants Management)
 * ════════════════════════════════════════════════════════════════════
 */

$page_title = 'رجسٹرڈ ادارے و ٹیننٹس (Tenants)';
require_once __DIR__ . '/header.php';

$msg = '';
$error = '';

// خودکار سیلف ہیلنگ: یقینی بنائیں کہ tenants ٹیبل میں allowed_modules کالم موجود ہے
try {
    $colChk = $pdo->query("SHOW COLUMNS FROM `tenants` LIKE 'allowed_modules'")->fetch();
    if (!$colChk) {
        $pdo->exec("ALTER TABLE `tenants` ADD COLUMN `allowed_modules` TEXT DEFAULT NULL");
    }
} catch (Throwable $eCols) {}

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. نیا ادارہ شامل کرنا (Create Tenant)
    if ($action === 'create_tenant') {
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $contact_person = trim($_POST['contact_person'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $system_type = trim($_POST['system_type'] ?? 'Madrasa');
        if (!in_array($system_type, ['Madrasa', 'Masjid', 'Both'])) {
            $system_type = 'Madrasa';
        }
        $plan_id = (int)($_POST['plan_id'] ?? 1);
        $duration_months = (int)($_POST['duration_months'] ?? 1);
        $admin_username = trim($_POST['admin_username'] ?? '');
        $admin_password = $_POST['admin_password'] ?? '';

        if (empty($name) || empty($code) || empty($admin_username) || empty($admin_password)) {
            $error = "ادارے کا نام، کوڈ، ایڈمن یوزر اور پاس ورڈ درج کرنا لازمی ہے۔";
        } else {
            try {
                $chk = $pdo->prepare("SELECT id FROM tenants WHERE code = ?");
                $chk->execute([$code]);
                if ($chk->fetch()) {
                    $error = "یہ ادارہ کوڈ ({$code}) پہلے سے زیرِ استعمال ہے۔ کوئی دوسرا کوڈ منتخب کریں۔";
                } else {
                    $pdo->beginTransaction();

                    $allMods = function_exists('get_default_module_keys') ? get_default_module_keys() : [];
                    $rawMods = isset($_POST['modules']) && is_array($_POST['modules']) ? array_values($_POST['modules']) : $allMods;
                    if ($system_type === 'Madrasa') {
                        $rawMods = array_values(array_diff($rawMods, ['masjid_wing']));
                    } elseif ($system_type === 'Masjid') {
                        $rawMods = array_values(array_diff($rawMods, ['darse_nizami_academics', 'hostel', 'admissions', 'recruitment', 'library']));
                        if (!in_array('masjid_wing', $rawMods)) {
                            $rawMods[] = 'masjid_wing';
                        }
                    }
                    $selected_modules = json_encode($rawMods);

                    try {
                        $stmt = $pdo->prepare("INSERT INTO tenants (name, code, contact_person, email, phone, city, system_type, status, allowed_modules, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW())");
                        $stmt->execute([$name, $code, $contact_person, $email, $phone, $city, $system_type, $selected_modules]);
                    } catch (Throwable $eIns) {
                        $pdo->exec("ALTER TABLE `tenants` ADD COLUMN `allowed_modules` TEXT DEFAULT NULL");
                        $pdo->exec("ALTER TABLE `tenants` ADD COLUMN `system_type` VARCHAR(50) DEFAULT 'Madrasa'");
                        $stmt = $pdo->prepare("INSERT INTO tenants (name, code, contact_person, email, phone, city, system_type, status, allowed_modules, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW())");
                        $stmt->execute([$name, $code, $contact_person, $email, $phone, $city, $system_type, $selected_modules]);
                    }
                    $newTenantId = (int)$pdo->lastInsertId();

                    $adminSysType = ($system_type === 'Masjid') ? 'Masjid' : 'Madrasa';
                    $hash = password_hash($admin_password, PASSWORD_DEFAULT);
                    $admStmt = $pdo->prepare("INSERT INTO admins (tenant_id, name, username, password, role, system_type, created_at) VALUES (?, ?, ?, ?, 'admin', ?, NOW())");
                    $admStmt->execute([$newTenantId, $contact_person ?: $name, $admin_username, $hash, $adminSysType]);

                    $startDate = date('Y-m-d');
                    $endDate = date('Y-m-d', strtotime("+{$duration_months} months"));
                    $subStmt = $pdo->prepare("INSERT INTO subscriptions (tenant_id, plan_id, status, starts_at, ends_at, auto_renew, created_at) VALUES (?, ?, 'active', ?, ?, 1, NOW())");
                    $subStmt->execute([$newTenantId, $plan_id, $startDate, $endDate]);

                    $setStmt = $pdo->prepare("INSERT INTO settings (tenant_id, madrasa_name, phone, address, created_at) VALUES (?, ?, ?, ?, NOW())");
                    $setStmt->execute([$newTenantId, $name, $phone, $city]);

                    $logStmt = $pdo->prepare("INSERT INTO tenant_activity_log (tenant_id, user_id, action, details, ip_address, created_at) VALUES (?, ?, 'tenant_created', ?, ?, NOW())");
                    $logStmt->execute([$newTenantId, $_SESSION['super_admin_id'] ?? 1, "نیا ادارہ {$name} ({$code}) بنایا گیا مع سسٹم ٹائپ {$system_type}", $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);

                    $pdo->commit();
                    $msg = "نیا ادارہ '{$name}' باکامیاب شامل کر دیا گیا ہے! کوڈ: {$code}";
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = "خرابی: " . $e->getMessage();
            }
        }
    }

    // 2. اسٹیٹس تبدیل کرنا (Change Status)
    if ($action === 'update_status') {
        $tenant_id = (int)($_POST['tenant_id'] ?? 0);
        $new_status = $_POST['status'] ?? 'active';
        if (in_array($new_status, ['active', 'trial', 'suspended', 'expired', 'cancelled'])) {
            try {
                // Ensure columns are varchar
                try {
                    $pdo->exec("ALTER TABLE tenants MODIFY COLUMN status VARCHAR(30) NOT NULL DEFAULT 'trial'");
                    $pdo->exec("ALTER TABLE subscriptions MODIFY COLUMN status VARCHAR(30) NOT NULL DEFAULT 'trial'");
                } catch (Throwable $eA) {}

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

    // 3. سبسکرپشن میں توسیع (Extend / Change Plan)
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

    // 4. خودکار آن لائن رجسٹریشن کنٹرول (Toggle Public Self-Registration)
    if ($action === 'toggle_public_registration') {
        $current_reg = (int)get_saas_setting($pdo, 'allow_public_registration', 1);
        $new_val = $current_reg ? '0' : '1';
        set_saas_setting($pdo, 'allow_public_registration', $new_val);
        $msg = ($new_val === '1') ? "آن لائن خودکار رجسٹریشن کھول دی گئی ہے (فعال)!" : "آن لائن خودکار رجسٹریشن کامیابی سے بند کر دی گئی ہے (معطل)!";
    }

    // 5. ماڈیول پرمیشنز اپڈیٹ کرنا (Update Tenant Module Permissions)
    if ($action === 'update_tenant_modules') {
        $tenant_id = (int)($_POST['tenant_id'] ?? 0);
        $modules = isset($_POST['modules']) && is_array($_POST['modules'])
            ? json_encode(array_values($_POST['modules']))
            : json_encode([]);

        try {
            try {
                $stmt = $pdo->prepare("UPDATE tenants SET allowed_modules = ? WHERE id = ?");
                $stmt->execute([$modules, $tenant_id]);
            } catch (Throwable $eUp) {
                $pdo->exec("ALTER TABLE `tenants` ADD COLUMN `allowed_modules` TEXT DEFAULT NULL");
                $stmt = $pdo->prepare("UPDATE tenants SET allowed_modules = ? WHERE id = ?");
                $stmt->execute([$modules, $tenant_id]);
            }

            $logStmt = $pdo->prepare("INSERT INTO tenant_activity_log (tenant_id, user_id, action, details, ip_address, created_at) VALUES (?, ?, 'modules_updated', ?, ?, NOW())");
            $logStmt->execute([$tenant_id, $_SESSION['super_admin_id'] ?? 1, "ماڈیول پرمیشنز اپڈیٹ کی گئیں", $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);

            $msg = "ادارے کی ماڈیول پرمیشنز کامیابی سے اپڈیٹ اور نافذ کر دی گئی ہیں!";
        } catch (Throwable $e) {
            $error = "خرابی: " . $e->getMessage();
        }
    }

    // 6. ایڈمن کے یوزرنیم و پاسورڈ تبدیل کرنا (Update Admin Credentials)
    if ($action === 'update_admin_credentials') {
        $tenant_id = (int)($_POST['tenant_id'] ?? 0);
        $admin_username = trim($_POST['admin_username'] ?? '');
        $admin_password = $_POST['admin_password'] ?? '';
        $admin_name = trim($_POST['admin_name'] ?? '');

        if ($tenant_id <= 0 || empty($admin_username)) {
            $error = "ادارے کا انتخاب اور ایڈمن یوزرنیم درج کرنا لازمی ہے۔";
        } else {
            try {
                // Check if username is already taken by another tenant admin
                $chkUser = $pdo->prepare("SELECT id FROM admins WHERE username = ? AND tenant_id != ? LIMIT 1");
                $chkUser->execute([$admin_username, $tenant_id]);
                if ($chkUser->fetch()) {
                    $error = "یہ یوزرنیم ({$admin_username}) پہلے سے کسی دوسرے ادارے یا صارف کے لیے زیرِ استعمال ہے۔";
                } else {
                    // Check super_admins table as well
                    $chkSa = $pdo->prepare("SELECT id FROM super_admins WHERE username = ? LIMIT 1");
                    $chkSa->execute([$admin_username]);
                    if ($chkSa->fetch()) {
                        $error = "یہ یوزرنیم سوپر ایڈمن کے لیے مخصوص ہے۔ براہ کرم کوئی دوسرا یوزرنیم منتخب کریں۔";
                    } else {
                        // Find existing admin for this tenant
                        $admStmt = $pdo->prepare("SELECT id, name FROM admins WHERE tenant_id = ? ORDER BY id ASC LIMIT 1");
                        $admStmt->execute([$tenant_id]);
                        $existingAdmin = $admStmt->fetch(PDO::FETCH_ASSOC);

                        if ($existingAdmin) {
                            $aId = (int)$existingAdmin['id'];
                            $targetName = !empty($admin_name) ? $admin_name : $existingAdmin['name'];
                            if (!empty($admin_password)) {
                                $hash = password_hash($admin_password, PASSWORD_DEFAULT);
                                $up = $pdo->prepare("UPDATE admins SET name = ?, username = ?, password = ? WHERE id = ? AND tenant_id = ?");
                                $up->execute([$targetName, $admin_username, $hash, $aId, $tenant_id]);
                            } else {
                                $up = $pdo->prepare("UPDATE admins SET name = ?, username = ? WHERE id = ? AND tenant_id = ?");
                                $up->execute([$targetName, $admin_username, $aId, $tenant_id]);
                            }
                        } else {
                            // Provision new admin if none exists
                            $passToUse = !empty($admin_password) ? $admin_password : 'admin123';
                            $hash = password_hash($passToUse, PASSWORD_DEFAULT);
                            $ins = $pdo->prepare("INSERT INTO admins (tenant_id, name, username, password, role, system_type, created_at) VALUES (?, ?, ?, ?, 'admin', 'Madrasa', NOW())");
                            $ins->execute([$tenant_id, $admin_name ?: 'ایڈمنسٹریٹر', $admin_username, $hash]);
                        }

                        $logStmt = $pdo->prepare("INSERT INTO tenant_activity_log (tenant_id, user_id, action, details, ip_address, created_at) VALUES (?, ?, 'credentials_updated', ?, ?, NOW())");
                        $logStmt->execute([$tenant_id, $_SESSION['super_admin_id'] ?? 1, "ایڈمن یوزرنیم ({$admin_username}) اور کوائف تبدیل کیے گئے", $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);

                        $msg = "ادارے کے ایڈمن کے یوزرنیم و لاگ ان کوائف کامیابی سے اپڈیٹ کر دیے گئے!";
                    }
                }
            } catch (Throwable $e) {
                $error = "خرابی: " . $e->getMessage();
            }
        }
    }

    // 7. ادارہ اور تمام متعلقہ ڈیٹا مستقل ڈیلیٹ کرنا (Hard Delete Tenant)
    if ($action === 'delete_tenant') {
        $tenant_id = (int)($_POST['tenant_id'] ?? 0);
        if ($tenant_id <= 1) {
            $error = "مرکزی سسٹم یا ابتدائی ڈیفالٹ ادارے (MAIN01) کو ڈیلیٹ کرنے کی اجازت نہیں ہے۔";
        } else {
            try {
                // Get tenant info for logging
                $tInfoStmt = $pdo->prepare("SELECT name, code FROM tenants WHERE id = ? LIMIT 1");
                $tInfoStmt->execute([$tenant_id]);
                $tInfo = $tInfoStmt->fetch(PDO::FETCH_ASSOC);

                if (!$tInfo) {
                    $error = "ادارہ موجود نہیں ہے یا پہلے ہی حذف ہو چکا ہے۔";
                } else {
                    $tName = $tInfo['name'];
                    $tCode = $tInfo['code'];

                    $pdo->beginTransaction();

                    // Complete cascade cleanup of all tenant-scoped tables
                    $tenantTables = [
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

                    foreach ($tenantTables as $tbl) {
                        try {
                            $delStmt = $pdo->prepare("DELETE FROM `$tbl` WHERE tenant_id = ?");
                            $delStmt->execute([$tenant_id]);
                        } catch (Throwable $eTbl) {
                            // Skip if table does not exist
                        }
                    }

                    // Delete from tenants table itself
                    $delTenant = $pdo->prepare("DELETE FROM tenants WHERE id = ?");
                    $delTenant->execute([$tenant_id]);

                    $pdo->commit();

                    $msg = "ادارہ '{$tName}' ({$tCode}) مع تمام متعلقہ طلبہ، اساتذہ، فنانس، ریکارڈز اور ایڈمن اکاؤنٹس مکمل طور پر ڈیلیٹ کر دیا گیا!";
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = "ڈیلیٹ کرنے میں خرابی: " . $e->getMessage();
            }
        }
    }
}

// Fetch all plans
$plans = [];
try {
    $plans = $pdo->query("SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY price ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Fetch public registration setting
$allow_public_reg = (int)get_saas_setting($pdo, 'allow_public_registration', 1);

// Search & Filter Tenants
$search = trim($_GET['search'] ?? '');
$cleanSearch = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{061C}\x{FEFF}\x{00A0}]/u', '', $search);
$alnumSearch = preg_replace('/[^a-zA-Z0-9]/', '', $cleanSearch);
$statusFilter = $_GET['status'] ?? '';

$sql = "SELECT t.*, 
               p.name as plan_name,
               s.ends_at as subscription_ends,
               s.status as subscription_status,
               (SELECT COUNT(*) FROM students st WHERE st.tenant_id = t.id AND (st.is_deleted = 0 OR st.is_deleted IS NULL)) as student_count,
               (SELECT COUNT(*) FROM teachers tc WHERE tc.tenant_id = t.id AND (tc.is_deleted = 0 OR tc.is_deleted IS NULL)) as teacher_count,
               (SELECT a.username FROM admins a WHERE a.tenant_id = t.id ORDER BY a.id ASC LIMIT 1) as admin_username,
               (SELECT a.name FROM admins a WHERE a.tenant_id = t.id ORDER BY a.id ASC LIMIT 1) as admin_name
        FROM tenants t
        LEFT JOIN subscriptions s ON t.id = s.tenant_id AND s.id = (SELECT MAX(id) FROM subscriptions WHERE tenant_id = t.id)
        LEFT JOIN subscription_plans p ON s.plan_id = p.id
        WHERE 1=1";

$params = [];
if (!empty($cleanSearch)) {
    $searchPattern = "%{$cleanSearch}%";
    $alnumPattern = !empty($alnumSearch) ? "%{$alnumSearch}%" : $searchPattern;
    $sql .= " AND (
        t.name LIKE ? OR 
        t.code LIKE ? OR 
        t.contact_person LIKE ? OR 
        t.phone LIKE ? OR
        REPLACE(REPLACE(REPLACE(t.code, '-', ''), ' ', ''), '_', '') LIKE ?
    )";
    $params = array_merge($params, [$searchPattern, $searchPattern, $searchPattern, $searchPattern, $alnumPattern]);
}
if (!empty($statusFilter)) {
    $sql .= " AND t.status = ?";
    $params[] = $statusFilter;
}

$sql .= " ORDER BY t.id DESC";

$tenants = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
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

<!-- Registration Control Card -->
<div class="card-custom mb-4 py-3 px-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="rounded-circle p-2 <?php echo $allow_public_reg ? 'bg-success text-white' : 'bg-danger text-white'; ?>" style="width: 44px; height: 44px; display: flex; align-items: center; justify-content: center;">
                <i class="fas <?php echo $allow_public_reg ? 'fa-user-plus' : 'fa-user-lock'; ?> fa-lg"></i>
            </div>
            <div>
                <h6 class="fw-bold mb-0">آن لائن خودکار ادارہ رجسٹریشن (Public Self-Registration)</h6>
                <small class="text-secondary">اس فیچر کو بند کرنے سے غیر متعلقہ افراد یا مدارس رجسٹریشن نہیں کر سکیں گے اور رجسٹریشن پورٹل بند ہو جائے گا۔</small>
            </div>
        </div>
        <div class="d-flex align-items-center gap-3">
            <span class="badge <?php echo $allow_public_reg ? 'bg-success' : 'bg-danger'; ?> px-3 py-2 rounded-pill fw-bold" style="font-size: 0.9rem;">
                <?php echo $allow_public_reg ? '✅ رجسٹریشن فعال ہے (Open)' : '⛔ رجسٹریشن بند ہے (Closed)'; ?>
            </span>
            <form method="POST" class="d-inline mb-0">
                <input type="hidden" name="action" value="toggle_public_registration">
                <button type="submit" class="btn <?php echo $allow_public_reg ? 'btn-outline-danger' : 'btn-outline-success'; ?> btn-sm fw-bold rounded-pill px-3 py-1">
                    <?php echo $allow_public_reg ? '⛔ رجسٹریشن بند کریں' : '✅ رجسٹریشن کھولیں'; ?>
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Action Header & Search -->
<div class="card-custom mb-4 py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <form method="GET" class="d-flex flex-wrap gap-2 flex-grow-1">
            <input type="text" name="search" class="form-control" style="max-width: 320px;" placeholder="نام، کوڈ، فون سے تلاش کریں..." value="<?php echo htmlspecialchars($search); ?>">
            <select name="status" class="form-select" style="max-width: 180px;">
                <option value="">تمام اسٹیٹس</option>
                <option value="active" <?php echo $statusFilter==='active'?'selected':''; ?>>فعال (Active)</option>
                <option value="trial" <?php echo $statusFilter==='trial'?'selected':''; ?>>ٹرائل (Trial)</option>
                <option value="suspended" <?php echo $statusFilter==='suspended'?'selected':''; ?>>معطل (Suspended)</option>
                <option value="expired" <?php echo $statusFilter==='expired'?'selected':''; ?>>ختم شدہ (Expired)</option>
            </select>
            <button type="submit" class="btn btn-outline-primary px-3 fw-bold">فلٹر</button>
            <?php if(!empty($search) || !empty($statusFilter)): ?>
                <a href="tenants.php" class="btn btn-outline-secondary">ری سیٹ</a>
            <?php endif; ?>
        </form>

        <button class="btn btn-primary rounded-pill fw-bold px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#createTenantModal">
            <i class="fas fa-plus-circle me-1"></i> نیا ادارہ شامل کریں
        </button>
    </div>
</div>

<!-- Tenants Table -->
<div class="card-custom mb-5">
    <div class="table-responsive pb-4" style="min-height: 280px;">
        <table class="table-custom">
            <thead>
                <tr>
                    <th>#</th>
                    <th>ادارہ کوڈ</th>
                    <th>نام ادارہ</th>
                    <th>رابطہ کار / فون</th>
                    <th>پیکج / پلان</th>
                    <th>طلبہ / اساتذہ</th>
                    <th>میعاد (Expiry)</th>
                    <th>حیثیت</th>
                    <th class="text-center">ایکشنز</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tenants)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-4" style="color: var(--sa-text-muted);">کوئی ادارہ نہیں ملا۔</td>
                    </tr>
                <?php else: foreach ($tenants as $i => $t): ?>
                    <tr>
                        <td><?php echo $i + 1; ?></td>
                        <td>
                            <span class="badge px-3 py-1 font-monospace fs-6 fw-bold" style="background: rgba(14, 165, 233, 0.25); color: #ffffff !important; border: 1.5px solid #38bdf8; letter-spacing: 1px;">
                                <?php echo htmlspecialchars($t['code']); ?>
                            </span>
                        </td>
                        <td>
                            <div class="fw-bold" style="color: var(--sa-text-main); font-size: 1rem;"><?php echo htmlspecialchars($t['name']); ?></div>
                            <small style="color: var(--sa-text-muted);"><?php echo htmlspecialchars($t['city'] ?: 'پاکستان'); ?></small>
                        </td>
                        <td>
                            <div style="color: var(--sa-text-main);"><?php echo htmlspecialchars($t['contact_person'] ?: '-'); ?></div>
                            <small style="color: var(--sa-text-muted);"><?php echo htmlspecialchars($t['phone'] ?: '-'); ?></small>
                        </td>
                        <td>
                            <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25">
                                <?php echo htmlspecialchars($t['plan_name'] ?: 'پہلے سے موجود'); ?>
                            </span>
                        </td>
                        <td>
                            <strong style="color: var(--sa-text-main);"><?php echo $t['student_count']; ?></strong> طلبہ /
                            <span style="color: var(--sa-text-muted);"><?php echo $t['teacher_count']; ?> اساتذہ</span>
                        </td>
                        <td>
                            <?php 
                                $exp = $t['subscription_ends'];
                                if ($exp) {
                                    $daysLeft = round((strtotime($exp) - time()) / 86400);
                                    if ($daysLeft > 7) {
                                        echo "<span class='text-success font-monospace'>" . date('d M Y', strtotime($exp)) . " ({$daysLeft} دن)</span>";
                                    } elseif ($daysLeft >= 0) {
                                        echo "<span class='text-warning fw-bold font-monospace'>" . date('d M Y', strtotime($exp)) . " ({$daysLeft} دن باقی)</span>";
                                    } else {
                                        echo "<span class='text-danger fw-bold font-monospace'>ختم شد (" . date('d M Y', strtotime($exp)) . ")</span>";
                                    }
                                } else {
                                    echo "<span style='color: var(--sa-text-muted);'>غیر معینہ</span>";
                                }
                            ?>
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
                        <td class="text-center">
                            <div class="d-flex justify-content-center gap-1">
                                <!-- One-Click Impersonate Button -->
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
                                            <a class="dropdown-item py-2" href="javascript:void(0)" onclick='openModulesModal(<?php echo $t['id']; ?>, <?php echo json_encode($t['name']); ?>, <?php echo json_encode($t['allowed_modules'] ?? ""); ?>)'>
                                                <i class="fas fa-cubes me-2 text-warning"></i> ماڈیول پرمیشنز ایڈجسٹ کریں
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

<!-- Modal: Create Tenant -->
<div class="modal fade" id="createTenantModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="create_tenant">
                <div class="modal-header" style="border-color: var(--sa-border);">
                    <h5 class="modal-title fw-bold" style="color: var(--sa-text-main);"><i class="fas fa-plus-circle me-2 text-primary"></i> نیا ادارہ (Tenant) شامل کریں</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label stat-label">ادارے کا نام *</label>
                            <input type="text" name="name" class="form-control" required placeholder="مثال: جامعہ دارالعلوم الاسلامیہ">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label stat-label">منفرد ادارہ کوڈ (Institute Code) *</label>
                            <input type="text" name="code" class="form-control font-monospace" required placeholder="مثال: DAR01" style="text-transform: uppercase;">
                            <small style="color: var(--sa-text-muted);">یہ کوڈ موبائل ایپ اور ویب میں لاگ ان کے لیے استعمال ہوگا</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label stat-label">رابطہ کار (مہتمم / ناظم)</label>
                            <input type="text" name="contact_person" class="form-control" placeholder="مولانا عبداللہ صاحب">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label stat-label">واٹس ایپ / موبائل نمبر</label>
                            <input type="text" name="phone" class="form-control" placeholder="03001234567">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label stat-label">ای میل ایڈریس</label>
                            <input type="email" name="email" class="form-control" placeholder="info@jamia.org">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label stat-label">شہر / پتہ</label>
                            <input type="text" name="city" class="form-control" placeholder="لاہور">
                        </div>
                        
                        <div class="col-12"><hr style="border-color: var(--sa-border);"></div>
                        <h6 class="fw-bold text-primary mb-1">ایڈمن اکاؤنٹ تفصیلات</h6>

                        <div class="col-md-6">
                            <label class="form-label stat-label">ایڈمن یوزر نیم *</label>
                            <input type="text" name="admin_username" class="form-control" required placeholder="admin">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label stat-label">پاس ورڈ *</label>
                            <input type="password" name="admin_password" class="form-control" required placeholder="••••••••">
                        </div>

                        <div class="col-12"><hr style="border-color: var(--sa-border);"></div>
                        <h6 class="fw-bold text-primary mb-1">نوعیتِ ادارہ و سبسکرپشن پلان</h6>

                        <div class="col-12">
                            <label class="form-label stat-label">نوعیتِ ادارہ (System Type) *</label>
                            <select name="system_type" class="form-select fw-bold">
                                <option value="Madrasa" selected>مدرسہ نظام (Madrasa Only — مسجد و مکتب فیچرز مکمل لاکڈ)</option>
                                <option value="Masjid">مسجد و مکتب نظام (Masjid/Maktab Only — مدرسہ فیچرز مکمل لاکڈ)</option>
                                <option value="Both">مشترکہ نظام (Both — مدرسہ + مسجد و مکتب دونوں فعال)</option>
                            </select>
                            <small style="color: var(--sa-text-muted);">جس نوعیت کا انتخاب کریں گے، کلائنٹ پینل میں صرف اسی کے فیچرز فعال ہوں گے اور دوسرا حصہ مکمل لاک رہے گا۔</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label stat-label">پیکج منتخب کریں *</label>
                            <select name="plan_id" class="form-select">
                                <?php foreach($plans as $p): ?>
                                    <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?> (روپے <?php echo number_format($p['price']); ?> / ماہ)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label stat-label">مدت (ماہانہ)</label>
                            <select name="duration_months" class="form-select">
                                <option value="1">1 ماہ</option>
                                <option value="3">3 ماہ</option>
                                <option value="6">6 ماہ</option>
                                <option value="12" selected>1 سال (12 ماہ)</option>
                            </select>
                        </div>
                        <div class="col-12"><hr style="border-color: var(--sa-border);"></div>
                        <div class="col-12 d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="fw-bold text-primary mb-0"><i class="fas fa-cubes me-1"></i> ماڈیولز پرمیشنز (Module Permissions)</h6>
                                <small style="color: var(--sa-text-muted);">اس ادارے کو کون کون سے ماڈیولز الاٹ کرنے ہیں؟</small>
                            </div>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-primary" onclick="toggleAllCheckboxes('createTenantModal', true)">تمام منتخب کریں</button>
                                <button type="button" class="btn btn-outline-secondary" onclick="toggleAllCheckboxes('createTenantModal', false)">تمام غیر منتخب کریں</button>
                            </div>
                        </div>

                        <?php 
                        $sysGroups = function_exists('get_all_system_modules') ? get_all_system_modules() : [];
                        foreach ($sysGroups as $gKey => $group): 
                        ?>
                            <div class="col-12">
                                <div class="p-2 rounded mb-1" style="background: rgba(59, 130, 246, 0.08); border-right: 3px solid var(--sa-primary);">
                                    <strong class="text-primary small"><?php echo htmlspecialchars($group['title']); ?></strong>
                                </div>
                                <div class="row g-2">
                                    <?php foreach ($group['modules'] as $mKey => $mInfo): ?>
                                        <div class="col-md-6 col-lg-4">
                                            <div class="form-check p-2 rounded border" style="border-color: var(--sa-card-border) !important; background: var(--sa-input-bg);">
                                                <input class="form-check-input ms-2 me-0" type="checkbox" name="modules[]" value="<?php echo $mKey; ?>" id="create_mod_<?php echo $mKey; ?>" checked>
                                                <label class="form-check-label small fw-bold" for="create_mod_<?php echo $mKey; ?>" style="color: var(--sa-text-main); cursor: pointer;">
                                                    <?php echo htmlspecialchars($mInfo['name']); ?>
                                                    <div class="text-muted" style="font-size: 0.72rem; font-weight: normal;"><?php echo htmlspecialchars($mInfo['desc']); ?></div>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer" style="border-color: var(--sa-border);">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold">ادارہ محفوظ کریں</button>
                </div>
            </form>
        </div>
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
                    <p style="color: var(--sa-text-main);">ادارہ: <strong id="extendTenantName" class="text-primary"></strong></p>
                    <div class="mb-3">
                        <label class="form-label stat-label">پلان / پیکج</label>
                        <select name="plan_id" class="form-select">
                            <?php foreach($plans as $p): ?>
                                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?> (روپے <?php echo number_format($p['price']); ?> / ماہ)</option>
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

<!-- Modal: Edit Tenant Modules -->
<div class="modal fade" id="editTenantModulesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="update_tenant_modules">
                <input type="hidden" name="tenant_id" id="editModulesTenantId">
                <div class="modal-header" style="border-color: var(--sa-border);">
                    <h5 class="modal-title fw-bold" style="color: var(--sa-text-main);">
                        <i class="fas fa-cubes me-2 text-warning"></i> ماڈیولز پرمیشنز کنٹرول (Module Access)
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex justify-content-between align-items-center mb-3 p-3 rounded" style="background: var(--sa-input-bg); border: 1px solid var(--sa-border);">
                        <div>
                            <span style="color: var(--sa-text-muted);">ادارہ:</span>
                            <strong id="editModulesTenantName" class="text-primary fs-5 ms-1"></strong>
                        </div>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-primary" onclick="toggleAllCheckboxes('editTenantModulesModal', true)">تمام منتخب کریں</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="toggleAllCheckboxes('editTenantModulesModal', false)">تمام غیر منتخب کریں</button>
                        </div>
                    </div>

                    <?php 
                    $allGroups = function_exists('get_all_system_modules') ? get_all_system_modules() : [];
                    foreach ($allGroups as $gKey => $group): 
                    ?>
                        <div class="mb-3">
                            <div class="p-2 rounded mb-2" style="background: rgba(59, 130, 246, 0.08); border-right: 3px solid var(--sa-primary);">
                                <strong class="text-primary small"><?php echo htmlspecialchars($group['title']); ?></strong>
                            </div>
                            <div class="row g-2">
                                <?php foreach ($group['modules'] as $mKey => $mInfo): ?>
                                    <div class="col-md-6 col-lg-4">
                                        <div class="form-check p-2 rounded border h-100" style="border-color: var(--sa-card-border) !important; background: var(--sa-input-bg);">
                                            <input class="form-check-input ms-2 me-0 edit-mod-chk" type="checkbox" name="modules[]" value="<?php echo $mKey; ?>" id="edit_mod_<?php echo $mKey; ?>">
                                            <label class="form-check-label small fw-bold" for="edit_mod_<?php echo $mKey; ?>" style="color: var(--sa-text-main); cursor: pointer;">
                                                <?php echo htmlspecialchars($mInfo['name']); ?>
                                                <div class="text-muted" style="font-size: 0.72rem; font-weight: normal;"><?php echo htmlspecialchars($mInfo['desc']); ?></div>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="modal-footer" style="border-color: var(--sa-border);">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" class="btn btn-warning px-4 fw-bold text-dark"><i class="fas fa-save me-1"></i> پرمیشنز محفوظ کریں</button>
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

function openModulesModal(tenantId, tenantName, allowedModulesRaw) {
    document.getElementById('editModulesTenantId').value = tenantId;
    document.getElementById('editModulesTenantName').innerText = tenantName;

    // Default modules list if not set
    const defaultModules = <?php echo json_encode(function_exists('get_default_module_keys') ? get_default_module_keys() : []); ?>;
    let allowedList = defaultModules;

    if (allowedModulesRaw && allowedModulesRaw !== "") {
        try {
            const parsed = typeof allowedModulesRaw === 'string' ? JSON.parse(allowedModulesRaw) : allowedModulesRaw;
            if (Array.isArray(parsed)) {
                allowedList = parsed;
            }
        } catch(e) {}
    }

    const modal = document.getElementById('editTenantModulesModal');
    const chks = modal.querySelectorAll('.edit-mod-chk');
    chks.forEach(function(chk) {
        chk.checked = allowedList.includes(chk.value);
    });

    new bootstrap.Modal(modal).show();
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

function toggleAllCheckboxes(modalId, state) {
    const m = document.getElementById(modalId);
    if (!m) return;
    const inputs = m.querySelectorAll('input[type="checkbox"][name="modules[]"]');
    inputs.forEach(function(inp) {
        inp.checked = state;
    });
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
