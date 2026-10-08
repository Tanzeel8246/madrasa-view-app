<?php
/**
 * سوپر ایڈمن لاگ آؤٹ (Super Admin Logout)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

unset($_SESSION['is_super_admin']);
unset($_SESSION['super_admin_id']);
unset($_SESSION['super_admin_name']);
unset($_SESSION['super_admin_email']);

header("Location: login.php");
exit;
