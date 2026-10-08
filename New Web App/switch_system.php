<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$sys = $_GET['sys'] ?? 'Madrasa';
if ($sys === 'Masjid') {
    $_SESSION['system_type'] = 'Masjid';
    header("Location: masjid_dashboard.php");
} else {
    $_SESSION['system_type'] = 'Madrasa';
    header("Location: dashboard.php");
}
exit;
