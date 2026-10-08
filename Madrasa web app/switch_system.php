<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once 'db.php';

$cur_tenant_id = (int)get_current_tenant_id();
$sys = $_GET['sys'] ?? 'Madrasa';

if ($sys === 'Masjid') {
    if (function_exists('is_module_allowed_for_tenant') && !is_module_allowed_for_tenant($pdo, 'masjid_wing', $cur_tenant_id)) {
        $_SESSION['system_type'] = 'Madrasa';
        header("Location: dashboard.php");
        exit;
    }
    $_SESSION['system_type'] = 'Masjid';
    header("Location: masjid_dashboard.php");
} else {
    $_SESSION['system_type'] = 'Madrasa';
    header("Location: dashboard.php");
}
exit;
