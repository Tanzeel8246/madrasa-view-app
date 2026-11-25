<?php
/**
 * ════════════════════════════════════════════════════════════════════
 *  MC-MMS v2.1.2 Authenticated Secure Export Gateway (D-01)
 *  Serves sensitive data exports (.csv, .xlsx, .sql) ONLY to verified,
 *  authenticated administrator sessions with path traversal protection.
 * ════════════════════════════════════════════════════════════════════
 */

ob_start();
require_once __DIR__ . '/session_init.php';
if (session_status() === PHP_SESSION_NONE) { 
    if (function_exists('madrasa_safe_session_start')) {
        madrasa_safe_session_start();
    } else {
        session_start();
    }
}
require_once __DIR__ . '/db.php';

// 1. Authentication & Permission Verification
$role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$userId = (int)($_SESSION['user_id'] ?? 0);
$allowedRoles = ['admin', 'superadmin', 'super_admin', 'administrator', 'nazim'];

if ($userId <= 0 || !in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    die("<!DOCTYPE html><html dir='rtl' lang='ur'><head><meta charset='utf-8'><title>403 - غیر مجاز رسائی</title><link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css' rel='stylesheet'></head><body class='bg-light d-flex align-items-center justify-content-center min-vh-100 p-3'><div class='card shadow-sm border-0 text-center p-4' style='max-width:450px;'><h4 class='text-danger fw-bold mb-3'>403 - رسائی ممنوع ہے</h4><p class='text-muted mb-4'>اس فائل کو ڈاؤنلوڈ کرنے کے لیے مجاز ایڈمن لاگ ان ہونا لازمی ہے۔</p><a href='login.php' class='btn btn-primary px-4'>لاگ ان صفحہ پر جائیں</a></div></body></html>");
}

// 2. File Name Validation (Zero Path Traversal)
$rawFile = $_GET['file'] ?? '';
if (empty($rawFile)) {
    http_response_code(400);
    die("براہِ کرم فائل کا نام فراہم کریں۔");
}

$cleanFile = basename($rawFile);
if ($cleanFile !== $rawFile || strpos($rawFile, '..') !== false || strpos($rawFile, '/') !== false || strpos($rawFile, '\\') !== false) {
    http_response_code(400);
    die("غیر قانونی فائل پاتھ درخواست کی گئی ہے۔");
}

// 3. Resolve File Location in Private Storage
$privateDir = __DIR__ . '/storage/exports';
$filePath = $privateDir . '/' . $cleanFile;

if (!file_exists($filePath) || !is_file($filePath)) {
    http_response_code(404);
    die("درخواست کردہ فائل موجود نہیں ہے۔");
}

// 4. Content-Type Mapping
$ext = strtolower(pathinfo($cleanFile, PATHINFO_EXTENSION));
$mimeMap = [
    'csv'  => 'text/csv; charset=utf-8',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'xls'  => 'application/vnd.ms-excel',
    'sql'  => 'application/sql; charset=utf-8',
    'json' => 'application/json; charset=utf-8',
    'txt'  => 'text/plain; charset=utf-8',
    'pdf'  => 'application/pdf',
];
$contentType = $mimeMap[$ext] ?? 'application/octet-stream';

// 5. Send Secure Binary Stream
while (ob_get_level() > 0) { @ob_end_clean(); }
header('Content-Description: File Transfer');
header('Content-Type: ' . $contentType);
header('Content-Disposition: attachment; filename="' . rawurlencode($cleanFile) . '"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: private, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
exit;
