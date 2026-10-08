<?php
/**
 * ════════════════════════════════════════════════════════════════════
 *  سوپر ایڈمن — ادارے میں براہ راست لاگ ان (One-Click Impersonation)
 * ════════════════════════════════════════════════════════════════════
 */

ini_set('display_errors', 0);
error_reporting(0);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db.php';

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
    header("Location: tenants.php");
    exit;
}

// 2. صرف لاگ ان شدہ سوپر ایڈمن ہی امپرسنیٹ کر سکتا ہے
if (!isset($_SESSION['is_super_admin'])) {
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

    // ادارے کا ایڈمن تلاش کریں
    $admStmt = $pdo->prepare("SELECT * FROM admins WHERE (tenant_id = ? OR tenant_id IS NULL) AND (role = 'admin' OR role = 'superadmin') ORDER BY id ASC LIMIT 1");
    $admStmt->execute([$tenantId]);
    $admin = $admStmt->fetch(PDO::FETCH_ASSOC);

    $adminId = $admin ? (int)$admin['id'] : 1;
    $adminName = $admin ? $admin['name'] : 'ایڈمنسٹریٹر';
    $adminRole = $admin ? $admin['role'] : 'admin';
    $adminPerms = $admin && !empty($admin['permissions']) ? json_decode($admin['permissions'], true) : [];

    // سیشن سیٹ کریں
    $_SESSION['impersonated_by_super_admin'] = true;
    $_SESSION['impersonated_tenant_id'] = $tenantId;
    $_SESSION['user_id'] = $adminId;
    $_SESSION['role'] = $adminRole;
    $_SESSION['name'] = $adminName . " (بذریعہ سوپر ایڈمن)";
    $_SESSION['username'] = $admin['username'] ?? 'admin';
    $_SESSION['permissions'] = $adminPerms ?: [];
    $_SESSION['system_type'] = $admin['system_type'] ?? 'Madrasa';
    $_SESSION['tenant_id'] = $tenantId;
    $_SESSION['tenant_code'] = $tenant['code'];
    $_SESSION['tenant_name'] = $tenant['name'];

    // سرگرمی لاگ درج کریں
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $logStmt = $pdo->prepare("INSERT INTO tenant_activity_log (tenant_id, user_id, action, details, ip_address, created_at) VALUES (?, ?, 'super_admin_impersonation', 'سوپر ایڈمن نے ادارے میں براہ راست لاگ ان کیا', ?, NOW())");
        $logStmt->execute([$tenantId, $_SESSION['super_admin_id'] ?? 1, $ip]);
    } catch (Throwable $e) {}

    header("Location: ../dashboard.php");
    exit;

} catch (Throwable $e) {
    header("Location: tenants.php?error=" . urlencode($e->getMessage()));
    exit;
}
