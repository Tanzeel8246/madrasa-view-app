<?php
/**
 * ════════════════════════════════════════════════════════════════════
 *  سوپر ایڈمن پورٹل گیٹ وے (Super Admin Entry Gateway)
 * ════════════════════════════════════════════════════════════════════
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['is_super_admin'])) {
    header("Location: super_admin/dashboard.php");
} else {
    header("Location: super_admin/login.php");
}
exit;
