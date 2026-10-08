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

                    $selected_modules = isset($_POST['modules']) && is_array($_POST['modules']) 
                        ? json_encode(array_values($_POST['modules'])) 
                        : json_encode(get_default_module_keys());

                    try {
                        $stmt = $pdo->prepare("INSERT INTO tenants (name, code, contact_person, email, phone, city, status, allowed_modules, created_at) VALUES (?, ?, ?, ?, ?, ?, 'active', ?, NOW())");
                        $stmt->execute([$name, $code, $contact_person, $email, $phone, $city, $selected_modules]);
                    } catch (Throwable $eIns) {
                        $pdo->exec("ALTER TABLE `tenants` ADD COLUMN `allowed_modules` TEXT DEFAULT NULL");
                        $stmt = $pdo->prepare("INSERT INTO tenants (name, code, contact_person, email, phone, city, status, allowed_modules, created_at) VALUES (?, ?, ?, ?, ?, ?, 'active', ?, NOW())");
                        $stmt->execute([$name, $code, $contact_person, $email, $phone, $city, $selected_modules]);
                    }
                    $newTenantId = (int)$pdo->lastInsertId();

                    $hash = password_hash($admin_password, PASSWORD_DEFAULT);
                    $admStmt = $pdo->prepare("INSERT INTO admins (tenant_id, name, username, password, role, system_type, created_at) VALUES (?, ?, ?, ?, 'admin', 'Madrasa', NOW())");
                    $admStmt->execute([$newTenantId, $contact_person ?: $name, $admin_username, $hash]);

                    $startDate = date('Y-m-d');
                    $endDate = date('Y-m-d', strtotime("+{$duration_months} months"));
                    $subStmt = $pdo->prepare("INSERT INTO subscriptions (tenant_id, plan_id, status, starts_at, ends_at, auto_renew, created_at) VALUES (?, ?, 'active', ?, ?, 1, NOW())");
                    $subStmt->execute([$newTenantId, $plan_id, $startDate, $endDate]);

                    $setStmt = $pdo->prepare("INSERT INTO settings (tenant_id, madrasa_name, phone, address, created_at) VALUES (?, ?, ?, ?, NOW())");
                    $setStmt->execute([$newTenantId, $name, $phone, $city]);

                    $logStmt = $pdo->prepare("INSERT INTO tenant_activity_log (tenant_id, user_id, action, details, ip_address, created_at) VALUES (?, ?, 'tenant_created', ?, ?, NOW())");
                    $logStmt->execute([$newTenantId, $_SESSION['super_admin_id'] ?? 1, "نیا ادارہ {$name} ({$code}) بنایا گیا مع مخصوص ماڈیولز", $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);

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
            $curSub = $pdo->prepare("SELECT ends_at FROM subscriptions WHERE tenant_id = ? ORDER BY id DESC LIMIT 1");
            $curSub->execute([$tenant_id]);
            $lastEnd = $curSub->fetchColumn();

            $baseDate = ($lastEnd && strtotime($lastEnd) > time()) ? $lastEnd : date('Y-m-d');
            $newEnd = date('Y-m-d', strtotime("{$baseDate} +{$months} months"));

            $upSub = $pdo->prepare("INSERT INTO subscriptions (tenant_id, plan_id, status, starts_at, ends_at, created_at) 
                                    VALUES (?, ?, 'active', CURDATE(), ?, NOW()) 
                                    ON DUPLICATE KEY UPDATE plan_id = VALUES(plan_id), status = 'active', ends_at = VALUES(ends_at)");
            $upSub->execute([$tenant_id, $plan_id, $newEnd]);

            $pdo->prepare("UPDATE tenants SET status = 'active' WHERE id = ?")->execute([$tenant_id]);

            $logStmt = $pdo->prepare("INSERT INTO tenant_activity_log (tenant_id, user_id, action, details, ip_address, created_at) VALUES (?, ?, 'subscription_extended', ?, ?, NOW())");
            $logStmt->execute([$tenant_id, $_SESSION['super_admin_id'] ?? 1, "سبسکرپشن {$newEnd} تک بڑھائی گئی", $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);

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
$statusFilter = $_GET['status'] ?? '';

$sql = "SELECT t.*, 
               p.name as plan_name,
               s.ends_at as subscription_ends,
               s.status as subscription_status,
               (SELECT COUNT(*) FROM students st WHERE st.tenant_id = t.id AND (st.is_deleted = 0 OR st.is_deleted IS NULL)) as student_count,
               (SELECT COUNT(*) FROM teachers tc WHERE tc.tenant_id = t.id AND (tc.is_deleted = 0 OR tc.is_deleted IS NULL)) as teacher_count
        FROM tenants t
        LEFT JOIN subscriptions s ON t.id = s.tenant_id AND s.id = (SELECT MAX(id) FROM subscriptions WHERE tenant_id = t.id)
        LEFT JOIN subscription_plans p ON s.plan_id = p.id
        WHERE 1=1";

$params = [];
if (!empty($search)) {
    $sql .= " AND (t.name LIKE ? OR t.code LIKE ? OR t.contact_person LIKE ? OR t.phone LIKE ?)";
    $params = array_merge($params, ["%{$search}%", "%{$search}%", "%{$search}%", "%{$search}%"]);
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
                        <h6 class="fw-bold text-primary mb-1">سبسکرپشن پلان</h6>

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
