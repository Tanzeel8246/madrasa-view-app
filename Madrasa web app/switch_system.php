<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once 'db.php';

$cur_tenant_id = (int)get_current_tenant_id();
$sys = $_GET['sys'] ?? 'Madrasa';
$is_super = !empty($_SESSION['is_super_admin']) || !empty($_SESSION['impersonated_by_super_admin']) || ($cur_tenant_id <= 1);

// Fetch registered system_type for tenant
$regSys = $_SESSION['tenant_system_type'] ?? null;
if (!$regSys && $cur_tenant_id > 1) {
    try {
        $stStmt = $pdo->prepare("SELECT system_type FROM tenants WHERE id = ? LIMIT 1");
        $stStmt->execute([$cur_tenant_id]);
        $regSys = $stStmt->fetchColumn() ?: 'Madrasa';
        $_SESSION['tenant_system_type'] = $regSys;
    } catch (Throwable $e) {}
}
if (!$regSys) $regSys = 'Madrasa';

if ($sys === 'Masjid') {
    if (!$is_super && $regSys === 'Madrasa') {
        $_SESSION['system_type'] = 'Madrasa';
        header("Location: dashboard.php?locked=masjid");
        exit;
    }
    if (function_exists('is_module_allowed_for_tenant') && !is_module_allowed_for_tenant($pdo, 'masjid_wing', $cur_tenant_id)) {
        $_SESSION['system_type'] = 'Madrasa';
        header("Location: dashboard.php");
        exit;
    }
    $_SESSION['system_type'] = 'Masjid';
    header("Location: masjid_dashboard.php");
} else {
    if (!$is_super && $regSys === 'Masjid') {
        $_SESSION['system_type'] = 'Masjid';
        header("Location: masjid_dashboard.php?locked=madrasa");
        exit;
    }
    $_SESSION['system_type'] = 'Madrasa';
    header("Location: dashboard.php");
}
exit;
