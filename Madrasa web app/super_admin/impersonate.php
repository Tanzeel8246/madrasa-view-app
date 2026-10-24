<?php
/**
 * ════════════════════════════════════════════════════════════════════
 *  سوپر ایڈمن — ادارے میں براہ راست لاگ ان (One-Click Impersonation)
 * ════════════════════════════════════════════════════════════════════
 */

ini_set('display_errors', 0);
error_reporting(0);

// Global root-scoped session cookie configuration
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
               (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
               (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

    if (PHP_VERSION_ID >= 70300) {
        @session_set_cookie_params([
            'lifetime' => 86400 * 30,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    } else {
        @session_set_cookie_params(86400 * 30, '/', '', $isHttps, true);
    }
    @session_start();
}

require_once __DIR__ . '/../db.php';

// Restore super admin session from persistent cookie if needed
if (empty($_SESSION['is_super_admin']) && function_exists('madrasa_restore_session_from_cookie')) {
    madrasa_restore_session_from_cookie($pdo);
}

$action = $_GET['action'] ?? '';

// 1. امپرسنیشن ختم کر کے واپس سوپر ایڈمن پینل آنا
if ($action === 'stop') {
    unset($_SESSION['impersonated_by_super_admin']);
    unset($_SESSION['impersonated_tenant_id']);
    // عام یوزر سیشن کلیئر کریں لیکن سوپر ایڈمن برقرار رکھیں
    unset($_SESSION['user_id']);
    unset($_SESSION['role']);
    unset($_SESSION['name']);
    unset($_SESSION['permissions']);
    unset($_SESSION['tenant_id']);
    unset($_SESSION['tenant_code']);
    unset($_SESSION['tenant_name']);
    unset($_SESSION['system_type']);
    header("Location: tenants.php");
    exit;
}

// 2. صرف لاگ ان شدہ سوپر ایڈمن ہی امپرسنیٹ کر سکتا ہے
if (empty($_SESSION['is_super_admin'])) {
    if (!empty($_SESSION['role']) && in_array(strtolower((string)$_SESSION['role']), ['superadmin', 'super_admin'])) {
        $_SESSION['is_super_admin'] = true;
    } elseif (!empty($_SESSION['super_admin_id'])) {
        $_SESSION['is_super_admin'] = true;
    } elseif (function_exists('madrasa_restore_session_from_cookie')) {
        madrasa_restore_session_from_cookie($pdo);
    }
}

if (empty($_SESSION['is_super_admin'])) {
    header("Location: login.php");
    exit;
}

$tenantId = (int)($_GET['tenant_id'] ?? 0);
if ($tenantId <= 0) {
    header("Location: tenants.php");
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tenant) {
        header("Location: tenants.php?error=not_found");
        exit;
    }

    $tenantCode = trim((string)$tenant['code']);
    $tenantSysType = !empty($tenant['system_type']) ? $tenant['system_type'] : 'Madrasa';

    // یقینی بنائیں کہ admins ٹیبل میں tenant_id موجود ہے
    try {
        $aCols = $pdo->query("SHOW COLUMNS FROM admins")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('tenant_id', $aCols)) {
            $pdo->exec("ALTER TABLE admins ADD COLUMN tenant_id INT DEFAULT 1");
        }
    } catch (Throwable $eAc) {}

    // ادارے کا مخصوص ایڈمن تلاش کریں (Strict Tenant Match)
    $admQuery = ($tenantId === 1) 
        ? "SELECT * FROM admins WHERE (tenant_id = 1 OR tenant_id IS NULL OR tenant_id = 0) ORDER BY id ASC LIMIT 1" 
        : "SELECT * FROM admins WHERE tenant_id = ? ORDER BY id ASC LIMIT 1";
    $admStmt = $pdo->prepare($admQuery);
    if ($tenantId === 1) {
        $admStmt->execute();
    } else {
        $admStmt->execute([$tenantId]);
    }
    $admin = $admStmt->fetch(PDO::FETCH_ASSOC);

    // اگر اس ادارے کا اپنا ایڈمن اکاؤنٹ موجود نہ ہو تو خودکار طریقے سے تیار کریں
    if (!$admin) {
        $cleanCode = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $tenantCode ?: 'inst' . $tenantId));
        $defUser = 'admin_' . $cleanCode;
        
        // یوزرنیم کی انفرادیت یقینی بنائیں
        $uChk = $pdo->prepare("SELECT id FROM admins WHERE username = ? LIMIT 1");
        $uChk->execute([$defUser]);
        if ($uChk->fetch()) {
            $defUser = 'admin_' . $cleanCode . '_' . $tenantId;
        }

        $defPassHash = password_hash('admin123', PASSWORD_DEFAULT);
        $fullPerms = json_encode([
            'manage_students', 'manage_teachers', 'manage_attendance', 'manage_teacher_attendance',
            'manage_hifz', 'manage_academics', 'manage_finance', 'manage_erp',
            'manage_classes', 'manage_notices', 'manage_exams', 'manage_requests',
            'manage_settings', 'manage_stipends', 'manage_hostel', 'manage_library',
            'manage_inventory', 'manage_shop', 'manage_cms', 'manage_admissions',
            'manage_assets', 'manage_in_kind_donations', 'manage_loans', 'manage_website',
            'manage_complaints', 'add_complaint',
            'manage_masjid_attendance', 'manage_masjid_teacher_attendance', 'manage_masjid_attendance_report',
            'manage_masjid_hifz', 'manage_masjid_hifz_daily', 'manage_masjid_hifz_report',
            'manage_masjid_academics', 'manage_masjid_academic_dashboard', 'manage_masjid_academic_entry', 'manage_masjid_academic_view',
            'manage_masjid_students', 'manage_masjid_teachers', 'manage_masjid_classes', 'manage_masjid_exams',
            'manage_masjid_finance_entry', 'manage_masjid_finance_report',
            'manage_masjid_requests', 'manage_masjid_notices', 'manage_masjid_admissions', 'manage_masjid_complaints', 'manage_masjid_add_complaint'
        ], JSON_UNESCAPED_UNICODE);

        try {
            $insStmt = $pdo->prepare("INSERT INTO admins (name, username, password, role, tenant_id, system_type, permissions) VALUES (?, ?, ?, 'admin', ?, ?, ?)");
            $insStmt->execute([
                $tenant['name'] . ' ایڈمن',
                $defUser,
                $defPassHash,
                $tenantId,
                $tenantSysType,
                $fullPerms
            ]);
        } catch (Throwable $eInsAdm) {}

        if ($tenantId === 1) {
            $admStmt->execute();
        } else {
            $admStmt->execute([$tenantId]);
        }
        $admin = $admStmt->fetch(PDO::FETCH_ASSOC);
    }

    $adminId = $admin ? (int)$admin['id'] : 1;
    $adminName = $admin ? $admin['name'] : $tenant['name'] . ' ایڈمن';
    $adminRole = $admin ? ($admin['role'] ?: 'admin') : 'admin';
    $adminUsername = $admin ? $admin['username'] : 'admin';
    $adminPassword = $admin['password'] ?? 'admin123';
    $targetSysType = !empty($admin['system_type']) ? $admin['system_type'] : $tenantSysType;

    $adminPerms = !empty($admin['permissions']) ? json_decode($admin['permissions'], true) : [];
    if (empty($adminPerms)) {
        $adminPerms = [
            'manage_students', 'manage_teachers', 'manage_attendance', 'manage_teacher_attendance',
            'manage_hifz', 'manage_academics', 'manage_finance', 'manage_erp',
            'manage_classes', 'manage_notices', 'manage_exams', 'manage_requests',
            'manage_settings', 'manage_stipends', 'manage_hostel', 'manage_library',
            'manage_inventory', 'manage_shop', 'manage_cms', 'manage_admissions',
            'manage_assets', 'manage_in_kind_donations', 'manage_loans', 'manage_website',
            'manage_complaints', 'add_complaint'
        ];
    }

    // سیشن میں صارف و ادارہ مکمل طور پر سیٹ کریں
    $_SESSION['is_super_admin'] = true;
    $_SESSION['impersonated_by_super_admin'] = true;
    $_SESSION['impersonated_tenant_id'] = $tenantId;

    $_SESSION['user_id'] = $adminId;
    $_SESSION['role'] = $adminRole;
    $_SESSION['name'] = $adminName . " (بذریعہ سوپر ایڈمن)";
    $_SESSION['username'] = $adminUsername;
    $_SESSION['permissions'] = $adminPerms;
    $_SESSION['system_type'] = $targetSysType;
    $_SESSION['tenant_id'] = $tenantId;
    $_SESSION['tenant_code'] = $tenantCode;
    $_SESSION['tenant_name'] = $tenant['name'];
    $_SESSION['tenant_status'] = $tenant['status'] ?? 'active';
    $_SESSION['last_activity'] = time();

    // مستقل کوکی سیٹ کریں تاکہ براؤزر لیول پر لاگ ان کبھی ضائع نہ ہو
    if (function_exists('madrasa_generate_auth_token') && function_exists('madrasa_set_auth_cookies')) {
        $token = madrasa_generate_auth_token($adminId, $tenantId, $adminRole, $adminUsername, $adminPassword, $targetSysType);
        madrasa_set_auth_cookies($token, $tenantCode);
    }

    // سرگرمی لاگ درج کریں
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $logStmt = $pdo->prepare("INSERT INTO tenant_activity_log (tenant_id, user_id, action, details, ip_address, created_at) VALUES (?, ?, 'super_admin_impersonation', 'سوپر ایڈمن نے ادارے میں براہ راست لاگ ان کیا', ?, NOW())");
        $logStmt->execute([$tenantId, $_SESSION['super_admin_id'] ?? 1, $ip]);
    } catch (Throwable $e) {}

    // سیشن کو فائل میں فوری محفوظ کریں
    session_write_close();

    // ہدف ڈیش بورڈ کا تعین
    $dest = ($targetSysType === 'Masjid') ? '../masjid_dashboard.php' : '../dashboard.php';

    header("Location: " . $dest);
    ?>
    <!DOCTYPE html>
    <html lang="ur" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta http-equiv="refresh" content="0;url=<?php echo htmlspecialchars($dest); ?>">
        <title>منتقلی جاری ہے...</title>
    </head>
    <body style="font-family:sans-serif;text-align:center;padding:50px;direction:rtl;background:#090e17;color:#fff;">
        <h3>ادارے کے ڈیش بورڈ میں داخل ہو رہے ہیں...</h3>
        <p>براہ کرم انتظار فرمائیں۔ اگر صفحہ خودکار ری ڈائریکٹ نہ ہو تو <a href="<?php echo htmlspecialchars($dest); ?>" style="color:#10b981;font-weight:bold;">یہاں کلک کریں</a></p>
        <script>
            window.location.replace("<?php echo addslashes($dest); ?>");
        </script>
    </body>
    </html>
    <?php
    exit;

} catch (Throwable $e) {
    header("Location: tenants.php?error=" . urlencode($e->getMessage()));
    exit;
}
