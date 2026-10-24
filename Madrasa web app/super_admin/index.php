<?php
/**
 * ════════════════════════════════════════════════════════════════════
 *  سوپر ایڈمن ڈائریکٹری انڈیکس (Super Admin Directory Index)
 * ════════════════════════════════════════════════════════════════════
 */
require_once __DIR__ . '/../session_init.php';
if (session_status() === PHP_SESSION_NONE) {
    madrasa_safe_session_start();
}

if (!empty($_SESSION['is_super_admin'])) {
    header("Location: dashboard.php");
} else {
    header("Location: login.php");
}
exit;
