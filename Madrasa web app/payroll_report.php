<?php
/**
 * payroll_report.php - تنخواہ و پے رول رپورٹ روٹنگ
 * Bridges menu and direct URL access seamlessly to acc_payroll.php
 */
ob_start();
if (file_exists(__DIR__ . '/session_init.php')) {
    require_once __DIR__ . '/session_init.php';
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/acc_payroll.php';
