<?php
ob_start();
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/finance_accounting_bridge.php';
require_once __DIR__ . '/fcm_helper.php';
// پروڈکشن سیکیورٹی: ایرر کو ڈسپلے نہیں کریں، صرف لاگ فائل میں لکھیں
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

define('MADRASA_DEBUG', trim(getenv('MADRASA_DEBUG') ?: 'false') === 'true');

/**
 * ڈیبگ لاگ لکھنے کا فنکشن (Global)
 */
function write_log($msg) {
    if (!defined('MADRASA_DEBUG') || !MADRASA_DEBUG) return; // production میں خاموش
    try {
        $logDir = __DIR__ . "/logs";
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        file_put_contents($logDir . "/debug_log.txt", date('H:i:s') . " - " . $msg . PHP_EOL, FILE_APPEND);
    } catch (Exception $e) {}
}

// CORS - صرف اجازت یافتہ Origins اور موبائل ایپ کو اجازت دیں
$allowedOrigins = [
    'https://madrasa.mindcraftsai.com',
    'http://madrasa.mindcraftsai.com',
    'https://madrasa.mamfss.com',
    'http://madrasa.mamfss.com',
    'http://localhost',
    'http://localhost:8080',
    'http://localhost:3000',
    'capacitor://localhost',
    'ionic://localhost',
    'http://localhost:8100',
];
$origin = isset($_SERVER['HTTP_ORIGIN']) ? trim($_SERVER['HTTP_ORIGIN']) : '';

// اگر موبائل ایپ ہو (کوئی origin نہیں، یا 'null'، یا capacitor/ionic/localhost وغیرہ)
$isMobileOrLocal = ($origin === '' || $origin === 'null' || strpos($origin, 'capacitor://') === 0 || strpos($origin, 'ionic://') === 0 || strpos($origin, 'http://localhost') === 0 || strpos($origin, 'https://localhost') === 0);

if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
} elseif ($isMobileOrLocal) {
    header("Access-Control-Allow-Origin: *");
} else {
    // غیر مجاز ویب اوریجنز کو 403
    http_response_code(403);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode(['status' => 'error', 'message' => 'CORS forbidden: Origin not allowed']);
    exit;
}
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY, X-USER-TOKEN");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

header("Content-Type: application/json; charset=UTF-8");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");

$action = isset($_GET['action']) ? $_GET['action'] : '';

// سیکیورٹی کی (Security Key Configuration)
$envSecret = trim(function_exists('get_env_val') ? get_env_val('MADRASA_API_SECRET', 'u349923428_madrasau349923428_madrasa') : (getenv('MADRASA_API_SECRET') ?: 'u349923428_madrasau349923428_madrasa'));
if ($envSecret === '') {
    $envSecret = 'u349923428_madrasau349923428_madrasa';
}
define('API_SECRET_KEY', $envSecret);

/**
 * getallheaders() کا متبادل (ہوسٹنگ اور Nginx/FastCGI کے لیے)
 */
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

function b64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64url_decode($data) {
    $pad = strlen($data) % 4;
    if ($pad) $data .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($data, '-_', '+/'));
}

function issue_user_token($userId, $role, $permissions = [], $tenantId = null, $tenantCode = null) {
    $tid = $tenantId !== null ? (int)$tenantId : (function_exists('get_current_tenant_id') ? get_current_tenant_id() : 1);
    $tcode = $tenantCode !== null ? (string)$tenantCode : (function_exists('get_current_tenant_code') ? get_current_tenant_code() : 'MAIN01');
    $payload = [
        'uid' => (int)$userId,
        'role' => (string)$role,
        'permissions' => array_values($permissions ?: []),
        'tid' => $tid,
        'tcode' => $tcode,
        'iat' => time(),
        'exp' => time() + (86400 * 90) // 90 دن کی میعاد
    ];
    $body = b64url_encode(json_encode($payload));
    $sig = b64url_encode(hash_hmac('sha256', $body, API_SECRET_KEY, true));
    return $body . '.' . $sig;
}

function read_user_token() {
    $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
    $normHeaders = array_change_key_case($headers, CASE_LOWER);
    
    // 1. Direct custom header
    $token = $normHeaders['x-user-token'] 
          ?? ($_SERVER['HTTP_X_USER_TOKEN'] 
          ?? ($_SERVER['REDIRECT_HTTP_X_USER_TOKEN'] ?? ''));

    // 2. Authorization Bearer header
    if (!$token) {
        $auth = $normHeaders['authorization'] 
             ?? ($_SERVER['HTTP_AUTHORIZATION'] 
             ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if (strpos($auth, 'Bearer ') === 0) {
            $bearer = trim(substr($auth, 7));
            if (strpos($bearer, '.') !== false) {
                $token = $bearer;
            }
        }
    }

    // 3. Request parameters
    if (!$token) {
        $token = $_REQUEST['user_token'] 
              ?? ($_GET['user_token'] 
              ?? ($_POST['user_token'] 
              ?? ($_REQUEST['token'] 
              ?? ($_GET['token'] 
              ?? ($_POST['token'] ?? '')))));
    }

    // 4. Raw JSON payload fallback
    if (!$token && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        if (!empty($raw) && ($raw[0] === '{' || $raw[0] === '[')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded['user_token'])) {
                $token = (string)$decoded['user_token'];
            }
        }
    }

    return trim((string)$token);
}

function user_token_signature_is_valid() {
    $token = read_user_token();
    if (!$token || strpos($token, '.') === false) return false;

    [$body, $sig] = explode('.', $token, 2);
    $expected = b64url_encode(hash_hmac('sha256', $body, API_SECRET_KEY, true));
    if (!hash_equals($expected, $sig)) return false;

    $payload = json_decode(b64url_decode($body), true);
    if (!is_array($payload) || empty($payload['uid']) || empty($payload['role'])) return false;
    if (($payload['exp'] ?? 0) < time()) return false;
    return true;
}

function current_user() {
    static $user = false;
    if ($user !== false) return $user;

    $token = read_user_token();
    $user = null;
    if (!$token || strpos($token, '.') === false) {
        if (session_status() === PHP_SESSION_NONE && isset($_COOKIE[session_name()])) {
            session_start();
        }
        if (!empty($_SESSION['user_id'])) {
            $tenantId = !empty($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : (function_exists('get_current_tenant_id') ? get_current_tenant_id() : 1);
            $tenantCode = !empty($_SESSION['tenant_code']) ? (string)$_SESSION['tenant_code'] : (function_exists('get_current_tenant_code') ? get_current_tenant_code() : 'MAIN01');
            $user = [
                'id' => (int)$_SESSION['user_id'],
                'role' => strtolower((string)($_SESSION['role'] ?? 'admin')),
                'permissions' => is_array($_SESSION['permissions'] ?? null) ? $_SESSION['permissions'] : [],
                'system_type' => $_SESSION['system_type'] ?? 'Madrasa',
                'tenant_id' => $tenantId,
                'tenant_code' => $tenantCode
            ];
            return $user;
        }
        return null;
    }

    [$body, $sig] = explode('.', $token, 2);
    $expected = b64url_encode(hash_hmac('sha256', $body, API_SECRET_KEY, true));
    if (!hash_equals($expected, $sig)) return null;

    $payload = json_decode(b64url_decode($body), true);
    if (!is_array($payload) || empty($payload['uid']) || empty($payload['role'])) return null;
    if (($payload['exp'] ?? 0) < time()) return null;

    $tenantId = !empty($payload['tid']) ? (int)$payload['tid'] : (function_exists('get_current_tenant_id') ? get_current_tenant_id() : 1);
    $tenantCode = !empty($payload['tcode']) ? (string)$payload['tcode'] : (function_exists('get_current_tenant_code') ? get_current_tenant_code() : 'MAIN01');

    $resolvedUser = [
        'id' => (int)$payload['uid'],
        'role' => strtolower((string)$payload['role']),
        'permissions' => is_array($payload['permissions'] ?? null) ? $payload['permissions'] : [],
        'tenant_id' => $tenantId,
        'tenant_code' => $tenantCode
    ];

    try {
        global $pdo;
        if (isset($pdo) && $pdo instanceof PDO) {
            if (in_array($resolvedUser['role'], ['admin', 'nazim'], true)) {
                $stmt = $pdo->prepare("SELECT id, role, permissions, system_type FROM admins WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL) LIMIT 1");
                $stmt->execute([$resolvedUser['id'], $tenantId]);
                $admin = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$admin) {
                    // Check if super_admin
                    $saStmt = $pdo->prepare("SELECT id, name, username FROM super_admins WHERE id = ? LIMIT 1");
                    $saStmt->execute([$resolvedUser['id']]);
                    $sa = $saStmt->fetch(PDO::FETCH_ASSOC);
                    if ($sa) {
                        $resolvedUser['role'] = 'admin';
                        $resolvedUser['system_type'] = 'Madrasa';
                        $resolvedUser['is_super_admin'] = true;
                    } else {
                        return null;
                    }
                } else {
                    $role = !empty($admin['role']) ? strtolower((string)$admin['role']) : 'admin';
                    if ($role !== $resolvedUser['role']) return null;
                    $permissions = json_decode($admin['permissions'] ?? '[]', true);
                    $resolvedUser['permissions'] = is_array($permissions) ? $permissions : [];
                    $resolvedUser['system_type'] = $admin['system_type'] ?? 'Madrasa';
                }
            } elseif ($resolvedUser['role'] === 'teacher') {
                // Check teachers table first
                $tStmt = $pdo->prepare("SELECT id, name, system_type FROM teachers WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL) AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1");
                $tStmt->execute([$resolvedUser['id'], $tenantId]);
                $teacher = $tStmt->fetch(PDO::FETCH_ASSOC);
                if ($teacher) {
                    $resolvedUser['system_type'] = $teacher['system_type'] ?? 'Madrasa';
                    $resolvedUser['permissions'] = ['manage_attendance', 'manage_hifz', 'manage_academics', 'manage_teacher_attendance'];
                } else {
                    // Fallback: Check if teacher is registered in admins table
                    $stmtA = $pdo->prepare("SELECT id, role, permissions, system_type FROM admins WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL) LIMIT 1");
                    $stmtA->execute([$resolvedUser['id'], $tenantId]);
                    $admin = $stmtA->fetch(PDO::FETCH_ASSOC);
                    if (!$admin) return null;
                    $resolvedUser['permissions'] = json_decode($admin['permissions'] ?? '[]', true) ?: [];
                    $resolvedUser['system_type'] = $admin['system_type'] ?? 'Madrasa';
                }
            } elseif ($resolvedUser['role'] === 'student') {
                $stmt = $pdo->prepare("SELECT id, system_type FROM students WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL) AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1");
                $stmt->execute([$resolvedUser['id'], $tenantId]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$student) {
                    $stmtM = $pdo->prepare("SELECT id FROM maktab_students WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL) AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1");
                    $stmtM->execute([$resolvedUser['id'], $tenantId]);
                    if (!$stmtM->fetch(PDO::FETCH_ASSOC)) return null;
                    $resolvedUser['system_type'] = 'Masjid';
                } else {
                    $resolvedUser['system_type'] = $student['system_type'] ?? 'Madrasa';
                }
            } elseif ($resolvedUser['role'] === 'parent') {
                $stmt = $pdo->prepare("SELECT id FROM parents WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL) AND is_active = 1 LIMIT 1");
                $stmt->execute([$resolvedUser['id'], $tenantId]);
                if (!$stmt->fetch(PDO::FETCH_ASSOC)) return null;
            } else {
                return null;
            }
        }
    } catch (Exception $e) {
        return null;
    }

    $user = $resolvedUser;
    return $user;
}

function require_user($roles = null, $permission = null) {
    $user = current_user();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Login required']);
        exit;
    }
    if ($roles !== null) {
        $allowed = is_array($roles) ? $roles : [$roles];
        if (!in_array($user['role'], $allowed, true)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
            exit;
        }
    }
    if ($permission !== null && !has_permission($user['role'], $user['permissions'], $permission, $user['system_type'] ?? null)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
        exit;
    }
    return $user;
}

function strip_sensitive_row($row) {
    foreach (['password', 'gemini_api_key', 'groq_api_key', 'whatsapp_api_key', 'sms_api_key'] as $key) {
        if (array_key_exists($key, $row)) unset($row[$key]);
    }
    return $row;
}

function sync_permission_for_table($table) {
    $map = [
        'students' => 'manage_students',
        'teachers' => 'manage_teachers',
        'finance_entries' => 'manage_finance',
        'masjid_finance_entries' => 'manage_finance',
        'hifz_entries' => 'manage_hifz',
        'nazira_record' => 'manage_hifz',
        'nazira_entries' => 'manage_hifz',
        'qaida_records' => 'manage_hifz',
        'attendance_entries' => 'manage_attendance',
        'attendance_teachers' => 'manage_teacher_attendance',
        'teacher_period_attendance' => 'manage_teacher_attendance',
        'academic_reports' => 'manage_academics',
        'complaints' => 'manage_students',
        'acc_chart_of_accounts' => 'manage_erp',
        'acc_vouchers' => 'manage_erp',
        'acc_ledger_entries' => 'manage_erp',
        'acc_payroll' => 'manage_stipends',
        'acc_invoices' => 'manage_erp',
        'parent_complaints' => 'manage_requests',
        'notices' => 'manage_notices',
        'exams' => 'manage_exams',
        'exam_results' => 'manage_exams',
        'classes' => 'manage_classes',
        'departments' => 'manage_erp',
        'leave_applications' => 'manage_requests',
        'fee_submissions' => 'manage_finance',
        'in_kind_donations' => 'manage_finance',
        'masjid_assets' => 'manage_finance',
        'maktab_students' => 'manage_students',
        'maktab_teachers' => 'manage_teachers',
        'maktab_classes' => 'manage_classes',
        'maktab_exams' => 'manage_exams',
        'maktab_attendance' => 'manage_attendance',
        'maktab_nazira_record' => 'manage_hifz',
        'maktab_hifz_record' => 'manage_hifz',
        'maktab_qaida_records' => 'manage_hifz',
        'maktab_academic_reports' => 'manage_academics',
        'maktab_exam_results' => 'manage_exams',
        'teacher_leaves' => 'manage_teacher_attendance',
    ];
    return $map[$table] ?? null;
}

function filter_sync_payload_for_user($input, $user) {
    if ($user['role'] === 'admin' || $user['role'] === 'nazim') return $input;

    $filtered = [];
    foreach ($input as $table => $rows) {
        $permission = sync_permission_for_table($table);
        if ($table === 'complaints' && in_array($user['role'], ['admin', 'nazim', 'teacher'], true)) {
            $filtered[$table] = $rows;
        } elseif ($table === 'teacher_leaves' && in_array($user['role'], ['admin', 'nazim', 'teacher'], true)) {
            $filtered[$table] = $rows;
        } elseif (($table === 'attendance_teachers' || $table === 'teacher_period_attendance') && (has_permission($user['role'], $user['permissions'], 'manage_attendance') || has_permission($user['role'], $user['permissions'], 'manage_teacher_attendance'))) {
            $filtered[$table] = $rows;
        } elseif (($table === 'academic_reports' || $table === 'maktab_academic_reports') && (has_permission($user['role'], $user['permissions'], 'manage_academics') || has_permission($user['role'], $user['permissions'], 'manage_hifz'))) {
            $filtered[$table] = $rows;
        } elseif ($table === 'acc_payroll' && (has_permission($user['role'], $user['permissions'], 'manage_finance') || has_permission($user['role'], $user['permissions'], 'manage_erp') || has_permission($user['role'], $user['permissions'], 'manage_stipends') || has_permission($user['role'], $user['permissions'], 'manage_masjid_stipends'))) {
            $filtered[$table] = $rows;
        } elseif ($permission !== null && has_permission($user['role'], $user['permissions'], $permission)) {
            $filtered[$table] = $rows;
        }
    }
    return $filtered;
}

function sync_list_values($value) {
    if (is_array($value)) {
        $items = $value;
    } else {
        $raw = trim((string)$value);
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);
        $items = is_array($decoded) ? $decoded : explode(',', $raw);
    }
    $out = [];
    foreach ($items as $item) {
        $item = trim((string)$item);
        if ($item !== '') $out[] = $item;
    }
    return array_values(array_unique($out));
}

function sync_normalize_period_key($key) {
    $key = trim((string)$key);
    $legacy = [
        'hifz_sabaq' => 'hifz_morning',
        'hifz_sabqi' => 'hifz_after_breakfast',
        'hifz_manzil' => 'hifz_noon',
        'hifz_review' => 'hifz_evening',
    ];
    return $legacy[$key] ?? $key;
}

function sync_decode_teacher_periods($raw) {
    $decoded = json_decode(trim((string)$raw), true);
    if (!is_array($decoded)) return [];
    $out = [];
    foreach ($decoded as $className => $periods) {
        $list = is_array($periods) ? $periods : explode(',', (string)$periods);
        foreach ($list as $period) {
            $period = sync_normalize_period_key($period);
            if ($period !== '') $out[(string)$className][] = $period;
        }
    }
    foreach ($out as $className => $periods) {
        $out[$className] = array_values(array_unique(array_map('strval', $periods)));
    }
    return $out;
}

/**
 * یوزر کی تصدیق کا فنکشن
 */
function authenticate() {
    $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
    $normHeaders = array_change_key_case($headers, CASE_LOWER);
    
    // Check Authorization header
    $authHeader = $normHeaders['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    
    // Check X-API-KEY header / param
    $xApiKey = trim($normHeaders['x-api-key'] ?? 
               ($_SERVER['HTTP_X_API_KEY'] ?? 
               ($_SERVER['REDIRECT_HTTP_X_API_KEY'] ?? 
               ($_SERVER['X-API-KEY'] ?? 
               ($_REQUEST['api_key'] ?? '')))));
    
    $expectedBearer = 'Bearer ' . API_SECRET_KEY;
    
    if (hash_equals($expectedBearer, $authHeader) || hash_equals(API_SECRET_KEY, $xApiKey)) {
        return true;
    }

    if (user_token_signature_is_valid()) {
        return true;
    }

    if (session_status() === PHP_SESSION_NONE && isset($_COOKIE[session_name()])) {
        session_start();
    }
    if (!empty($_SESSION['user_id'])) {
        return true;
    }

    $token = read_user_token();
    write_log("Unauthorized Access: Action=" . ($_GET['action'] ?? 'none') . " TokenProvided=" . ($token ? 'YES (len=' . strlen($token) . ')' : 'NO') . " Method=" . ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized Access: Please log in again.']);
    exit;
}

// تمام ریکوئسٹس کو چیک کریں
$publicActions = [
    'login',
    'login_admin',
    'login_student',
    'login_parent',
    'get_stats',
    'request_otp',
    'verify_otp_and_reset',
    'reset_password_otp',
    'reset_password'
];
if (!in_array($action, $publicActions, true)) {
    authenticate();
}

/**
 * تاریخ کو درست فارمیٹ (YYYY-MM-DD) میں بدلنا
 */
function normalize_date($rawDate) {
    $rawDate = trim((string)$rawDate);
    if ($rawDate === '') return null;
    if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/', $rawDate, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/', $rawDate, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    $ts = strtotime($rawDate);
    return $ts ? date('Y-m-d', $ts) : $rawDate;
}

function normalize_shift($shiftRaw) {
    $s = strtolower(trim((string)$shiftRaw));
    $map = [
        '1' => 1, 'morning' => 1, 'lesson1' => 1, 'lesson_1' => 1,
        '2' => 2, 'afterbreakfast' => 2, 'after_breakfast' => 2, 'after breakfast' => 2, 'lesson2' => 2, 'lesson_2' => 2,
        '3' => 3, 'noon' => 3, 'lesson3' => 3, 'lesson_3' => 3,
        '4' => 4, 'evening' => 4, 'lesson4' => 4, 'lesson_4' => 4,
        '5' => 5, 'lesson5' => 5, 'lesson_5' => 5,
        '6' => 6, 'lesson6' => 6, 'lesson_6' => 6,
        '7' => 7, 'study' => 7,
        '8' => 8, 'revision' => 8
    ];
    return isset($map[$s]) ? $map[$s] : 1;
}

function normalize_status_code($statusRaw) {
    $original = trim((string)$statusRaw);
    $status = strtolower($original);
    if ($status === '' || $status === 'not marked' || mb_strpos($original, 'حاضری نہیں لگی') !== false) return null;
    if ($status === 'a' || $status === 'absent' || mb_strpos($original, 'غیر حاضر') !== false || mb_strpos($status, 'absent') !== false) return 'A';
    if ($status === 'l' || $status === 'leave' || mb_strpos($original, 'رخصت') !== false || mb_strpos($original, 'چھٹی') !== false || mb_strpos($status, 'leave') !== false) return 'L';
    if ($status === 'p' || $status === 'present' || mb_strpos($original, 'حاضر') !== false || mb_strpos($status, 'present') !== false) return 'P';
    return null;
}

function to_int_or_null($value) {
    if ($value === null || $value === '') return null;
    return is_numeric($value) ? (int)$value : null;
}

function map_grade($urdu) {
    $u = trim((string)$urdu);
    $u_lower = strtolower($u);
    if ($u === '' || $u === '-' || $u === '0') return '';
    if (in_array($u_lower, ['absent', 'leave', 'غیر حاضر', 'رخصت', 'ناغہ', 'نہیں سنایا', 'نہیں سنائی'])) return $u;
    if (in_array($u_lower, ['excellent', 'good', 'average', 'weak'])) return ucfirst($u_lower);
    if (mb_strpos($u, 'ممتاز') !== false || mb_strpos($u, 'بہترین') !== false || mb_strpos($u, 'اعلیٰ') !== false || mb_strpos($u, 'اچھے') !== false || mb_strpos($u, 'پختہ') !== false) return 'Excellent';
    if (mb_strpos($u, 'بہتر') !== false || mb_strpos($u, 'اچھا') !== false || mb_strpos($u, 'ٹھیک') !== false) return 'Good';
    if (mb_strpos($u, 'معمولی') !== false || mb_strpos($u, 'مناسب') !== false || mb_strpos($u, 'درمیانہ') !== false || mb_strpos($u, 'رعایتی پاس') !== false) return 'Average';
    if (mb_strpos($u, 'کمزور') !== false || mb_strpos($u, 'طلب') !== false || mb_strpos($u, 'کچا') !== false || mb_strpos($u, 'ناقص') !== false) return 'Weak';
    return $u !== '' ? $u : '';
}

function ensure_column(PDO $pdo, $table, $column, $definition) {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `" . $table . "` LIKE ?");
        $stmt->execute([$column]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) return true;
        $pdo->exec("ALTER TABLE `" . $table . "` ADD COLUMN `" . $column . "` " . $definition);
        return true;
    } catch (Exception $e) {
        write_log("Schema ensure failed for " . $table . "." . $column . ": " . $e->getMessage());
        return false;
    }
}

function ensure_head_id(PDO $pdo, $type) {
    try {
        $headId = $pdo->query("SELECT id FROM heads ORDER BY id ASC LIMIT 1")->fetchColumn();
        if ($headId) return (int)$headId;
        $headName = ($type === 'expense') ? 'Mobile Expense' : 'Mobile Income';
        $stmt = $pdo->prepare("INSERT INTO heads (name, type) VALUES (?, ?)");
        $stmt->execute([$headName, $type]);
        return (int)$pdo->lastInsertId();
    } catch (Exception $e) { return 1; }
}

function next_user_receipt_no(PDO $pdo, $userId, $preferredNo = null, $excludeFinanceId = null, $systemType = 'Madrasa', $tenant_id = null) {
    $userId = max(1, (int)$userId);
    if ($userId === 1) {
        // تنزیل الرحمن (ایڈمن ID 1) کا رسید تسلسل سابقہ 300000 سیریز (300041 آگے) سے جاری رہے
        $minNo = 300000;
        $maxNo = 400000;
    } elseif ($userId === 3) {
        // قاری سلیم اللہ صاحب (ID 3) کے لیے 700000 بلاک تاکہ تنزیل کے نمبرز سے تصادم نہ ہو
        $minNo = 700000;
        $maxNo = 800000;
    } else {
        $minNo = $userId * 100000;
        $maxNo = ($userId + 1) * 100000;
    }
    $preferredNo = to_int_or_null($preferredNo);
    $sysCond = ($systemType === 'Masjid') ? "system_type = 'Masjid'" : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
    $tCond = $tenant_id ? "AND (tenant_id = " . (int)$tenant_id . " OR tenant_id IS NULL OR tenant_id = 0)" : "";

    // Always find current highest active receipt number
    $stmt = $pdo->prepare("SELECT MAX(computer_receipt_no) FROM finance WHERE computer_receipt_no > ? AND computer_receipt_no < ? AND $sysCond $tCond AND (is_deleted = 0 OR is_deleted IS NULL)");
    $stmt->execute([$minNo, $maxNo]);
    $currentMax = (int)$stmt->fetchColumn();

    if ($systemType === 'Masjid') {
        try {
            $hasMasjidFinTable = !empty($pdo->query("SHOW TABLES LIKE 'masjid_finance'")->fetchAll());
            if ($hasMasjidFinTable) {
                $mStmt = $pdo->prepare("SELECT MAX(computer_receipt_no) FROM masjid_finance WHERE computer_receipt_no > ? AND computer_receipt_no < ? $tCond AND (is_deleted = 0 OR is_deleted IS NULL)");
                $mStmt->execute([$minNo, $maxNo]);
                $mMax = (int)$mStmt->fetchColumn();
                if ($mMax > $currentMax) {
                    $currentMax = $mMax;
                }
            }
        } catch (Exception $e) {}
    }

    $expectedNext = ($currentMax > $minNo) ? ($currentMax + 1) : ($minNo + 1);

    // If a preferred number is requested by mobile app, only accept it if it does not conflict AND is not jumping ahead of expected next
    if ($preferredNo !== null && $preferredNo > $minNo && $preferredNo < $maxNo && $preferredNo <= $expectedNext) {
        $sql = "SELECT id FROM finance WHERE computer_receipt_no = ? AND $sysCond $tCond AND (is_deleted = 0 OR is_deleted IS NULL)";
        $args = [$preferredNo];
        if ($excludeFinanceId) {
            $sql .= " AND id <> ?";
            $args[] = (int)$excludeFinanceId;
        }
        $sql .= " LIMIT 1";
        $checkStmt = $pdo->prepare($sql);
        $checkStmt->execute($args);
        if (!$checkStmt->fetchColumn()) return $preferredNo;
    }

    $nextNo = $expectedNext;
    if ($nextNo >= $maxNo) {
        throw new Exception("Receipt number quota exhausted for user " . $userId);
    }
    return $nextNo;
}

function ai_provider_key_list($value) {
    $parts = preg_split('/[\r\n,]+/', (string)$value);
    $keys = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') $keys[] = $part;
    }
    return array_values(array_unique($keys));
}

function call_groq_key($apiKey, $prompt) {
    if (trim((string)$apiKey) === '') return null;
    $url = "https://api.groq.com/openai/v1/chat/completions";
    $data = ['model' => 'llama-3.3-70b-versatile', 'messages' => [['role' => 'user', 'content' => $prompt]], 'temperature' => 0.7, 'max_tokens' => 256];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $res = curl_exec($ch);
    curl_close($ch);
    if (!$res) return null;
    $resData = json_decode($res, true);
    $content = trim((string)($resData['choices'][0]['message']['content'] ?? ''));
    return $content !== '' ? $content : null;
}

function call_gemini_key($apiKey, $prompt) {
    if (trim((string)$apiKey) === '') return null;
    $models = ['gemini-2.5-flash'];
    foreach ($models as $model) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);
        $data = ['contents' => [[ 'parts' => [[ 'text' => $prompt ]] ]]];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $res = curl_exec($ch);
        curl_close($ch);
        if (!$res) continue;
        $resData = json_decode($res, true);
        $content = trim((string)($resData['candidates'][0]['content']['parts'][0]['text'] ?? ''));
        if ($content !== '') return $content;
    }
    return null;
}

function call_ai_provider(PDO $pdo, $prompt) {
    try {
        if ($prompt === '') return 'AI prompt is empty.';
        $settings = $pdo->query("SELECT * FROM settings WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: [];
        $provider = strtolower(trim((string)($settings['ai_provider'] ?? 'gemini')));
        $geminiKeys = ai_provider_key_list($settings['gemini_api_key'] ?? '');
        $groqKeys = ai_provider_key_list($settings['groq_api_key'] ?? '');
        $attempts = ($provider === 'groq') ? ['groq', 'gemini'] : ['gemini', 'groq'];
        foreach ($attempts as $attempt) {
            $keys = $attempt === 'groq' ? $groqKeys : $geminiKeys;
            foreach ($keys as $key) {
                $content = $attempt === 'groq'
                    ? call_groq_key($key, $prompt)
                    : call_gemini_key($key, $prompt);
                if ($content !== null && trim($content) !== '') return $content;
            }
        }
        return 'AI settings incomplete or API call failed.';
    } catch (Exception $e) { return 'AI Error: ' . $e->getMessage(); }
}

require_once 'db.php';
try { $pdo->exec("SET NAMES utf8mb4"); } catch (Exception $e) {}

// Auto Schema Fixer (Legacy fallback; superseded by versioned migrations in /migrations/)
if (isset($_GET['run_schema_fix']) && $_GET['run_schema_fix'] === '1') {
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS heads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        type VARCHAR(50) NOT NULL,
        is_deleted TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_heads_type (type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS finance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        date DATE NOT NULL,
        type VARCHAR(50) NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        description TEXT DEFAULT NULL,
        head_id INT DEFAULT NULL,
        manual_receipt_no VARCHAR(100) DEFAULT NULL,
        donor_name VARCHAR(255) DEFAULT NULL,
        donor_address TEXT DEFAULT NULL,
        computer_receipt_no INT DEFAULT NULL,
        user_id INT DEFAULT 1,
        user_name VARCHAR(100) DEFAULT NULL,
        is_deleted TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_finance_date (date),
        INDEX idx_finance_user_receipt (user_id, computer_receipt_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensure_column($pdo, 'leave_applications', 'application_photo', 'VARCHAR(255) AFTER reason');
    ensure_column($pdo, 'fee_submissions', 'screenshot', 'VARCHAR(255) AFTER submission_date');
    ensure_column($pdo, 'fee_submissions', 'head_id', 'INT DEFAULT NULL');
    ensure_column($pdo, 'fee_submissions', 'description', 'TEXT DEFAULT NULL');
    ensure_column($pdo, 'fee_submissions', 'finance_id', 'INT DEFAULT NULL');
    ensure_column($pdo, 'finance', 'head_id', 'INT DEFAULT NULL');
    ensure_column($pdo, 'finance', 'manual_receipt_no', 'VARCHAR(100) DEFAULT NULL');
    ensure_column($pdo, 'finance', 'donor_name', 'VARCHAR(255) DEFAULT NULL');
    ensure_column($pdo, 'finance', 'donor_address', 'TEXT DEFAULT NULL');
    ensure_column($pdo, 'finance', 'computer_receipt_no', 'INT DEFAULT NULL');
    ensure_column($pdo, 'finance', 'is_deleted', 'TINYINT(1) DEFAULT 0');
    ensure_column($pdo, 'finance', 'user_id', 'INT DEFAULT 1');
    ensure_column($pdo, 'finance', 'user_name', 'VARCHAR(100) DEFAULT NULL');
    ensure_column($pdo, 'attendance_teachers', 'late_minutes', 'INT DEFAULT 0');
    ensure_column($pdo, 'attendance', 'status_5', 'VARCHAR(20) DEFAULT NULL');
    ensure_column($pdo, 'attendance', 'late_5', 'INT DEFAULT 0');
    ensure_column($pdo, 'attendance', 'status_6', 'VARCHAR(20) DEFAULT NULL');
    ensure_column($pdo, 'attendance', 'late_6', 'INT DEFAULT 0');
    ensure_column($pdo, 'attendance', 'status_7', 'VARCHAR(20) DEFAULT NULL');
    ensure_column($pdo, 'attendance', 'late_7', 'INT DEFAULT 0');
    ensure_column($pdo, 'attendance', 'status_8', 'VARCHAR(20) DEFAULT NULL');
    ensure_column($pdo, 'attendance', 'late_8', 'INT DEFAULT 0');
    $pdo->exec("CREATE TABLE IF NOT EXISTS acc_chart_of_accounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(20) UNIQUE NOT NULL,
        name VARCHAR(100) NOT NULL,
        type ENUM('Asset', 'Liability', 'Equity', 'Revenue', 'Expense') NOT NULL,
        parent_id INT DEFAULT NULL,
        is_active TINYINT(1) DEFAULT 1,
        is_deleted TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS acc_vouchers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        voucher_no VARCHAR(50) UNIQUE NOT NULL,
        date DATE NOT NULL,
        type ENUM('Journal', 'Receipt', 'Payment', 'Contra') NOT NULL,
        reference VARCHAR(100) DEFAULT NULL,
        description TEXT DEFAULT NULL,
        department_id INT DEFAULT NULL,
        status ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
        created_by INT DEFAULT NULL,
        approved_by INT DEFAULT NULL,
        is_deleted TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS acc_ledger_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        voucher_id INT NOT NULL,
        account_id INT NOT NULL,
        debit DECIMAL(15,2) DEFAULT 0.00,
        credit DECIMAL(15,2) DEFAULT 0.00,
        department_id INT DEFAULT NULL,
        description TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_acc_ledger_voucher (voucher_id),
        INDEX idx_acc_ledger_account (account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensure_column($pdo, 'acc_chart_of_accounts', 'is_deleted', 'TINYINT(1) DEFAULT 0');
    ensure_column($pdo, 'acc_chart_of_accounts', 'is_active', 'TINYINT(1) DEFAULT 1');
    ensure_column($pdo, 'acc_vouchers', 'department_id', 'INT DEFAULT NULL');
    ensure_column($pdo, 'acc_vouchers', 'is_deleted', 'TINYINT(1) DEFAULT 0');
    ensure_column($pdo, 'acc_vouchers', 'created_by', 'INT DEFAULT NULL');
    ensure_column($pdo, 'acc_ledger_entries', 'department_id', 'INT DEFAULT NULL');
    ensure_column($pdo, 'acc_ledger_entries', 'description', 'TEXT DEFAULT NULL');
    try {
        $accCount = (int)$pdo->query("SELECT COUNT(*) FROM acc_chart_of_accounts")->fetchColumn();
        if ($accCount === 0) {
            $seedAccounts = [
                ['1001', 'Cash in Hand', 'Asset'],
                ['1002', 'Bank Account', 'Asset'],
                ['1101', 'Accounts Receivable (Students)', 'Asset'],
                ['2001', 'Accounts Payable', 'Liability'],
                ['3001', 'General Fund', 'Equity'],
                ['4001', 'Fee Income', 'Revenue'],
                ['4002', 'Donation / Sadqa', 'Revenue'],
                ['4003', 'Zakat', 'Revenue'],
                ['5001', 'Teacher Salary', 'Expense'],
                ['5002', 'Utility Bills', 'Expense'],
                ['5003', 'Maintenance', 'Expense']
            ];
            $seedStmt = $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type) VALUES (?, ?, ?)");
            foreach ($seedAccounts as $acc) { $seedStmt->execute($acc); }
        }
    } catch (Exception $e) { write_log("Accounting seed failed: " . $e->getMessage()); }
    $pdo->exec("CREATE TABLE IF NOT EXISTS teacher_period_attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT NOT NULL,
        class_id INT DEFAULT NULL,
        date DATE NOT NULL,
        period_no INT NOT NULL,
        status VARCHAR(20) DEFAULT 'P',
        late_minutes INT DEFAULT 0,
        remarks TEXT DEFAULT NULL,
        is_deleted TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_teacher_class_date_period (teacher_id, class_id, date, period_no)
    )");
    ensure_column($pdo, 'teacher_period_attendance', 'late_minutes', 'INT DEFAULT 0');
    ensure_column($pdo, 'teacher_period_attendance', 'remarks', 'TEXT DEFAULT NULL');
    ensure_column($pdo, 'teacher_period_attendance', 'is_deleted', 'TINYINT(1) DEFAULT 0');
    $pdo->exec("CREATE TABLE IF NOT EXISTS parent_complaints (id INT AUTO_INCREMENT PRIMARY KEY, student_id INT, date DATE, category VARCHAR(100), description TEXT, status ENUM('Pending', 'Viewed') DEFAULT 'Pending', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS qaida_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT,
        date DATE,
        sabaq_1 VARCHAR(255), grade_1 VARCHAR(50),
        sabaq_2 VARCHAR(255), grade_2 VARCHAR(50),
        sabaq_3 VARCHAR(255), grade_3 VARCHAR(50),
        sabaq_4 VARCHAR(255), grade_4 VARCHAR(50),
        lesson_type VARCHAR(100) DEFAULT 'نورانی قاعدہ',
        is_deleted TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    ensure_column($pdo, 'qaida_records', 'hearing_time', 'VARCHAR(50)');
    ensure_column($pdo, 'qaida_records', 'qty_1', 'VARCHAR(255)');
    ensure_column($pdo, 'qaida_records', 'qty_2', 'VARCHAR(255)');
    ensure_column($pdo, 'qaida_records', 'qty_3', 'VARCHAR(255)');
    ensure_column($pdo, 'qaida_records', 'qty_4', 'VARCHAR(255)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS academic_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        date DATE NOT NULL,
        class_name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
        subject VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
        lesson_details TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
        grade VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
        remarks TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
        is_deleted TINYINT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS parents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
        phone VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        is_active TINYINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS student_parents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        parent_id INT NOT NULL,
        student_id INT NOT NULL,
        relation VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
        UNIQUE KEY parent_student_unique (parent_id, student_id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_loans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT NULL,
        borrower_name VARCHAR(255) NULL,
        phone VARCHAR(50) NULL,
        loan_direction VARCHAR(20) DEFAULT 'Given',
        loan_amount DECIMAL(15,2) DEFAULT 0,
        installment_amount DECIMAL(15,2) DEFAULT 0,
        repaid_amount DECIMAL(15,2) DEFAULT 0,
        remaining_amount DECIMAL(15,2) DEFAULT 0,
        date_issued DATE NULL,
        due_date DATE NULL,
        description TEXT NULL,
        status VARCHAR(50) DEFAULT 'Active',
        system_type VARCHAR(50) DEFAULT 'Madrasa',
        is_deleted TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    try { $pdo->exec("ALTER TABLE staff_loans MODIFY teacher_id INT NULL DEFAULT NULL"); } catch (Exception $e) {}
    ensure_column($pdo, 'staff_loans', 'loan_direction', "VARCHAR(20) DEFAULT 'Given'");
    ensure_column($pdo, 'staff_loans', 'borrower_name', "VARCHAR(255) DEFAULT NULL");
    ensure_column($pdo, 'staff_loans', 'phone', "VARCHAR(50) DEFAULT NULL");
    ensure_column($pdo, 'staff_loans', 'repaid_amount', "DECIMAL(15,2) DEFAULT 0");
    ensure_column($pdo, 'staff_loans', 'due_date', "DATE DEFAULT NULL");
    ensure_column($pdo, 'staff_loans', 'remaining_amount', "DECIMAL(15,2) DEFAULT 0");
    ensure_column($pdo, 'staff_loans', 'system_type', "VARCHAR(50) DEFAULT 'Madrasa'");
    ensure_column($pdo, 'staff_loans', 'is_deleted', "TINYINT(1) DEFAULT 0");

    $pdo->exec("CREATE TABLE IF NOT EXISTS loan_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        loan_id INT NULL,
        type VARCHAR(20) NOT NULL,
        borrower_lender_name VARCHAR(255) NULL,
        amount DECIMAL(15,2) DEFAULT 0,
        date DATE NOT NULL,
        payment_method VARCHAR(50) DEFAULT 'Cash',
        remarks TEXT NULL,
        system_type VARCHAR(50) DEFAULT 'Madrasa',
        created_by INT DEFAULT 1,
        is_deleted TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    ensure_column($pdo, 'loan_transactions', 'system_type', "VARCHAR(50) DEFAULT 'Madrasa'");
    ensure_column($pdo, 'loan_transactions', 'created_by', "INT DEFAULT 1");
    ensure_column($pdo, 'loan_transactions', 'is_deleted', "TINYINT(1) DEFAULT 0");

    $pdo->exec("CREATE TABLE IF NOT EXISTS acc_payroll (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT NOT NULL,
        month_year VARCHAR(20) NOT NULL,
        basic_salary DECIMAL(15,2) DEFAULT 0,
        allowances DECIMAL(15,2) DEFAULT 0,
        deductions DECIMAL(15,2) DEFAULT 0,
        net_salary DECIMAL(15,2) DEFAULT 0,
        status VARCHAR(50) DEFAULT 'Unpaid',
        payment_date DATE NULL,
        created_by INT DEFAULT 1,
        system_type VARCHAR(50) DEFAULT 'Madrasa',
        is_deleted TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    ensure_column($pdo, 'acc_payroll', 'system_type', "VARCHAR(50) DEFAULT 'Madrasa'");
    ensure_column($pdo, 'acc_payroll', 'created_by', "INT DEFAULT 1");
    ensure_column($pdo, 'acc_payroll', 'net_salary', "DECIMAL(15,2) DEFAULT 0");
    ensure_column($pdo, 'acc_payroll', 'is_deleted', "TINYINT(1) DEFAULT 0");
    ensure_column($pdo, 'students', 'hifz_section', "VARCHAR(50) DEFAULT 'regular'");
    ensure_column($pdo, 'maktab_students', 'hifz_section', "VARCHAR(50) DEFAULT 'regular'");
} catch (Exception $e) {}
}

// ===== تصویر اپلوڈ فنکشن (موبائل سے base64 ملتا ہے، سرور پر محفوظ کرتا ہے) =====
$uploadImage = function(string $imageData, string $type) use ($pdo): string {
    if (empty($imageData)) return '';

    // اگر پہلے سے سرور پر محفوظ پاتھ ہے (re-sync case)
    if (str_starts_with($imageData, 'uploads/')) return $imageData;
    if (str_starts_with($imageData, 'http')) return $imageData;

    // base64 data URL چھانٹیں: "data:image/jpeg;base64,/9j/..."
    if (str_contains($imageData, ';base64,')) {
        $imageData = explode(';base64,', $imageData)[1];
    }

    // decode کریں
    $decoded = base64_decode($imageData, true);
    if ($decoded === false || strlen($decoded) < 100) return '';

    $folder = ($type === 'fee') ? 'uploads/fees/' : 'uploads/leaves/';
    if (!is_dir($folder)) mkdir($folder, 0777, true);

    $filename = $folder . time() . '_' . uniqid() . '.jpg';
    file_put_contents($filename, $decoded);
    return $filename; // مثال: "uploads/fees/1234567_abc.jpg"
};

require_once __DIR__ . '/api/controllers/AuthController.php';
require_once __DIR__ . '/api/controllers/SyncController.php';
require_once __DIR__ . '/api/controllers/StudentController.php';
require_once __DIR__ . '/api/controllers/FinanceController.php';
require_once __DIR__ . '/api/controllers/AcademicController.php';
require_once __DIR__ . '/fcm_helper.php';

$response = [];

try {
    write_log("API Request Action: " . $action);
    $raw_input = file_get_contents("php://input");
    $input = json_decode($raw_input, true) ?: $_POST;

    // 🌟 Unified Single Login Endpoint (3x faster mobile login)
    if ($action == 'login') {
        $response = AuthController::login($pdo, $input);
    } elseif ($action == 'get_subscription_status') {
        $user = function_exists('current_user') ? current_user() : null;
        $tid = $user ? (int)($user['tenant_id'] ?? 1) : (int)($_GET['tenant_id'] ?? $input['tenant_id'] ?? 1);

        $subData = [
            'status' => 'success',
            'is_trial' => false,
            'days_left' => 0,
            'plan_name' => 'بنیادی پیکج (Basic)',
            'plan_code' => 'basic',
            'subscription_ends_at' => '',
            'subscription_status' => 'active',
            'max_students' => 50,
            'max_classes' => 3,
            'max_teachers' => 4,
            'price_monthly' => 1500,
            'price_yearly' => 15000,
            'used_students' => 0,
            'used_classes' => 0,
            'used_teachers' => 0,
        ];

        try {
            $sStmt = $pdo->prepare("SELECT s.*, p.name as plan_name, p.code as plan_code, p.max_students as p_max_students, p.max_classes as p_max_classes, p.max_teachers as p_max_teachers, p.price_monthly, p.price_yearly FROM subscriptions s LEFT JOIN subscription_plans p ON s.plan_id = p.id WHERE s.tenant_id = ? ORDER BY s.id DESC LIMIT 1");
            $sStmt->execute([$tid]);
            $sRow = $sStmt->fetch(PDO::FETCH_ASSOC);
            if ($sRow) {
                $subData['is_trial'] = ($sRow['status'] === 'trial');
                $subData['subscription_status'] = $sRow['status'];
                if (!empty($sRow['plan_name'])) $subData['plan_name'] = $sRow['plan_name'];
                if (!empty($sRow['plan_code'])) $subData['plan_code'] = $sRow['plan_code'];
                $subData['subscription_ends_at'] = $sRow['ends_at'] ?: '';
                if ($sRow['ends_at']) {
                    $subData['days_left'] = max(0, (int)ceil((strtotime($sRow['ends_at']) - time()) / 86400));
                }
                if (!empty($sRow['p_max_students'])) $subData['max_students'] = (int)$sRow['p_max_students'];
                if (!empty($sRow['p_max_classes'])) $subData['max_classes'] = (int)$sRow['p_max_classes'];
                if (!empty($sRow['p_max_teachers'])) $subData['max_teachers'] = (int)$sRow['p_max_teachers'];
                if (!empty($sRow['price_monthly'])) $subData['price_monthly'] = (float)$sRow['price_monthly'];
                if (!empty($sRow['price_yearly'])) $subData['price_yearly'] = (float)$sRow['price_yearly'];
            }

            $subData['used_students'] = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE (tenant_id = $tid OR tenant_id IS NULL) AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchColumn();
            $subData['used_classes'] = (int)$pdo->query("SELECT COUNT(*) FROM classes WHERE (tenant_id = $tid OR tenant_id IS NULL) AND (status = 'Active' OR status = '' OR status IS NULL)")->fetchColumn();
            $subData['used_teachers'] = (int)$pdo->query("SELECT COUNT(*) FROM teachers WHERE (tenant_id = $tid OR tenant_id IS NULL) AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchColumn();
        } catch (Throwable $eSub) {}

        $response = $subData;
    } elseif ($action == 'get_stats') {
        $response = StudentController::getStats($pdo);
    } elseif ($action == 'get_ai_insight') {
        require_user(['admin', 'nazim', 'teacher']);
        $response = AcademicController::getAiInsight($pdo, $input);
    } elseif ($action == 'login_student') {
        $response = AuthController::loginStudent($pdo, $input);
    } elseif ($action == 'login_parent') {
        $response = AuthController::loginParent($pdo, $input);
    } elseif ($action == 'login_admin') {
        $response = AuthController::loginAdmin($pdo, $input);
    } elseif ($action == 'sync_down') {
        $user = require_user();
        $response = SyncController::syncDown($pdo, $user);
    } elseif ($action == 'sync_up') {
        $user = require_user();
        $response = SyncController::syncUp($pdo, $user);
    } elseif ($action == 'request_otp') {
        $response = AuthController::requestOtp($pdo, $input);
    } elseif ($action == 'verify_otp_and_reset' || $action == 'reset_password_otp') {
        $response = AuthController::verifyOtpAndReset($pdo, $input);
    } elseif ($action == 'reset_password') {
        $response = ['status' => 'error', 'message' => 'Password reset requires OTP verification or admin approval. Please use action=request_otp.'];
    } elseif ($action == 'change_password') {
        $user = require_user();
        $response = AuthController::changePassword($pdo, $user, $input);
    } elseif ($action == 'add_notice') {
        $user = require_user(['admin', 'nazim']);
        $title = trim((string)($input['title'] ?? ''));
        $message = trim((string)($input['message'] ?? ($input['body'] ?? '')));
        $date = trim((string)($input['date'] ?? date('Y-m-d')));
        $sys = $user['system_type'] ?? ($input['system_type'] ?? 'Madrasa');

        if (!empty($title)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS notices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                message TEXT,
                date DATE,
                system_type VARCHAR(50) DEFAULT 'Madrasa',
                is_deleted TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $stmt = $pdo->prepare("INSERT INTO notices (title, message, date, system_type) VALUES (?, ?, ?, ?)");
            $stmt->execute([$title, $message, $date, $sys]);

            // FCM: تمام اساتذہ اور admins کو نوٹیفیکیشن
            $noticeTitle = $title ?: 'نیا اعلان';
            send_fcm_to_role($pdo, 'teacher', '📢 نیا اعلان', $noticeTitle, ['type' => 'notice']);
            send_fcm_to_role($pdo, 'admin', '📢 نیا اعلان', $noticeTitle, ['type' => 'notice']);

            $response = ['status' => 'success', 'message' => 'Notice added'];
        } else {
            $response = ['status' => 'error', 'message' => 'Notice title is required'];
        }
    } elseif ($action == 'update_fcm_token') {
        $user = require_user();
        $token = trim((string)($input['token'] ?? ($input['fcm_token'] ?? '')));
        $userId = intval($input['user_id'] ?? $user['id']);
        $role = strtolower(trim((string)($input['role'] ?? $user['role'])));

        if (!empty($token) && $userId > 0) {
            $table = in_array($role, ['admin', 'nazim', 'superadmin'], true) ? 'admins' : ($role === 'teacher' ? 'teachers' : 'students');
            ensure_column($pdo, $table, 'fcm_token', 'VARCHAR(255) DEFAULT NULL');
            $stmt = $pdo->prepare("UPDATE {$table} SET fcm_token = ? WHERE id = ?");
            $stmt->execute([$token, $userId]);

            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS user_fcm_tokens (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    role VARCHAR(50) NOT NULL,
                    fcm_token VARCHAR(255) NOT NULL,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY user_role_token_unique (user_id, role, fcm_token)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $stmtToken = $pdo->prepare("INSERT INTO user_fcm_tokens (user_id, role, fcm_token) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP");
                $stmtToken->execute([$userId, $role, $token]);
            } catch (Exception $e) {}

            $response = ['status' => 'success', 'message' => 'FCM token updated successfully'];
        } else {
            $response = ['status' => 'error', 'message' => 'Token and valid user ID are required'];
        }
    } elseif ($action == 'update_settings') {
        $user = require_user('admin');
        $tenantId = (int)($user['tenant_id'] ?? get_current_tenant_id());
        $input = json_decode(file_get_contents("php://input"), true) ?: $_POST;
        
        $uploadDir = __DIR__ . '/uploads/branding/';
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);

        // 1. Handle base64 images from Mobile
        $base64Map = [
            'logo_base64' => 'logo_path',
            'signature_stamp_base64' => 'signature_stamp_path',
            'receipt_bg_base64' => 'receipt_bg',
            'masjid_receipt_bg_base64' => 'masjid_receipt_bg',
            'id_card_bg_base64' => 'id_card_bg',
            'sanad_bg_base64' => 'sanad_bg',
            'masjid_logo_base64' => 'masjid_logo_path'
        ];
        foreach ($base64Map as $bKey => $targetCol) {
            if (!empty($input[$bKey])) {
                $raw = preg_replace('#^data:image/\w+;base64,#i', '', $input[$bKey]);
                $binary = base64_decode($raw);
                if ($binary) {
                    $fn = $targetCol . '_' . $tenantId . '_' . time() . '.png';
                    if (file_put_contents($uploadDir . $fn, $binary)) {
                        $input[$targetCol] = 'uploads/branding/' . $fn;
                    }
                }
            }
        }

        // 2. Handle multipart $_FILES from Web / Mobile Form
        $fileMap = [
            'logo_file' => 'logo_path',
            'signature_stamp_file' => 'signature_stamp_path',
            'receipt_bg_file' => 'receipt_bg',
            'masjid_receipt_bg_file' => 'masjid_receipt_bg',
            'id_card_bg_file' => 'id_card_bg',
            'sanad_bg_file' => 'sanad_bg',
            'masjid_logo_file' => 'masjid_logo_path'
        ];
        foreach ($fileMap as $fKey => $targetCol) {
            if (isset($_FILES[$fKey]) && $_FILES[$fKey]['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES[$fKey]['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'svg'])) {
                    $fn = $targetCol . '_' . $tenantId . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES[$fKey]['tmp_name'], $uploadDir . $fn)) {
                        $input[$targetCol] = 'uploads/branding/' . $fn;
                    }
                }
            }
        }

        $fields = ['madrasa_name', 'phone', 'address', 'tagline', 'current_hijri_year', 'week_start_day', 'ai_provider', 'gemini_api_key', 'groq_api_key', 'whatsapp_api_key', 'sms_api_key', 'auto_notify_day', 'auto_notify_time', 'logo_path', 'receipt_bg', 'masjid_receipt_bg', 'id_card_bg', 'sanad_bg', 'signature_stamp_path', 'masjid_name', 'masjid_logo_path', 'sanad_layout_json'];
        $up = []; $val = [];
        foreach ($fields as $f) { if (isset($input[$f])) { $up[] = "$f = ?"; $val[] = $input[$f]; } }
        if ($up) { 
            // Ensure row exists for this tenant
            $chk = $pdo->prepare("SELECT id FROM settings WHERE tenant_id = ? LIMIT 1");
            $chk->execute([$tenantId]);
            $sId = $chk->fetchColumn();
            if (!$sId) {
                $mName = $input['madrasa_name'] ?? 'جامعہ دارالعلوم';
                $pdo->prepare("INSERT INTO settings (tenant_id, madrasa_name) VALUES (?, ?)")->execute([$tenantId, $mName]);
                $sId = $pdo->lastInsertId();
            }
            $val[] = $sId;
            $val[] = $tenantId;
            $pdo->prepare("UPDATE settings SET " . implode(", ", $up) . " WHERE id = ? AND tenant_id = ?")->execute($val);

            // Also update tenants table name if madrasa_name changed
            if (!empty($input['madrasa_name'])) {
                try {
                    $pdo->prepare("UPDATE tenants SET name = ? WHERE id = ?")->execute([$input['madrasa_name'], $tenantId]);
                } catch (Throwable $e) {}
            }

            $st = $pdo->prepare("SELECT madrasa_name, phone, address, tagline, current_hijri_year, week_start_day, ai_provider, auto_notify_day, auto_notify_time, logo_path, receipt_bg, masjid_receipt_bg, id_card_bg, sanad_bg, signature_stamp_path, masjid_name, masjid_logo_path, sanad_layout_json FROM settings WHERE id = ?");
            $st->execute([$sId]);
            $updatedSettings = $st->fetch(PDO::FETCH_ASSOC);
            $response = ['status' => 'success', 'message' => 'Settings updated successfully', 'settings' => $updatedSettings]; 
        } else {
            $response = ['status' => 'error', 'message' => 'No settings changed'];
        }
    } elseif ($action == 'delete_account') {
        $user = require_user();
        // اکاؤنٹ حذف کرنے کا عمل (Soft Delete)
        $input = json_decode(file_get_contents("php://input"), true) ?: [];
        $user_id = intval($input['user_id'] ?? $user['id']);
        $role = trim((string)($input['role'] ?? $user['role']));
        if ($user['role'] !== 'admin') {
            $user_id = $user['id'];
            $role = $user['role'];
        }
        if (!$user_id) {
            $response = ['status' => 'error', 'message' => 'User ID required'];
        } elseif ($role === 'student' || $role === 'parent') {
            $pdo->prepare("UPDATE students SET is_deleted = 1, status = 'Deleted' WHERE id = ?")->execute([$user_id]);
            $response = ['status' => 'success', 'message' => 'Account deleted successfully'];
        } else {
            $response = ['status' => 'error', 'message' => 'Admin account cannot be deleted via API'];
        }
    } elseif ($action == 'get_admission_applications') {
        require_user(['admin', 'nazim'], 'manage_admissions');
        // داخلہ درخواستیں لیں (Admin موبائل کے لیے)
        $filterStatus = trim($_GET['filter'] ?? 'all');
        $allowedFilters = ['all', 'pending', 'interview', 'approved', 'rejected'];
        if (!in_array(strtolower($filterStatus), $allowedFilters)) $filterStatus = 'all';
        
        if ($filterStatus === 'all') {
            $apps = $pdo->query("SELECT * FROM admission_applications ORDER BY created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM admission_applications WHERE LOWER(status) = ? ORDER BY created_at DESC LIMIT 100");
            $stmt->execute([strtolower($filterStatus)]);
            $apps = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $countStmt = $pdo->query("SELECT status, COUNT(*) as c FROM admission_applications GROUP BY status");
        $counts = ['pending' => 0, 'interview' => 0, 'approved' => 0, 'rejected' => 0];
        foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $counts[strtolower($c['status'])] = (int)$c['c'];
        }
        $response = ['status' => 'success', 'applications' => $apps, 'counts' => $counts];
    } elseif ($action == 'update_admission_status') {
        require_user(['admin', 'nazim'], 'manage_admissions');
        // درخواست منظور یا مسترد کرنا
        $input = json_decode(file_get_contents("php://input"), true) ?: [];
        $app_id = intval($input['id'] ?? 0);
        $new_status = trim((string)($input['status'] ?? ''));
        $test_date = trim((string)($input['test_date'] ?? ''));
        $test_time = trim((string)($input['test_time'] ?? ''));
        $admin_note = trim((string)($input['admin_note'] ?? ''));
        $raw_status = strtolower($new_status);
        if ($raw_status === 'approved') $normalized_status = 'Approved';
        elseif ($raw_status === 'rejected') $normalized_status = 'rejected';
        elseif ($raw_status === 'interview' || $raw_status === 'interviewed') $normalized_status = 'interview';
        else $normalized_status = '';

        if (!$app_id || empty($normalized_status)) {
            $response = ['status' => 'error', 'message' => 'Invalid parameters'];
        } else {
            $pdo->prepare("UPDATE admission_applications SET status=?, test_date=?, test_time=?, admin_note=? WHERE id=?")
                ->execute([$normalized_status, $test_date ?: null, $test_time ?: null, $admin_note, $app_id]);

            $smsResult = ['success' => false, 'message' => 'SMS not required'];
            $appStmt = $pdo->prepare("SELECT * FROM admission_applications WHERE id = ? LIMIT 1");
            $appStmt->execute([$app_id]);
            $app = $appStmt->fetch(PDO::FETCH_ASSOC);
            $mobile = $app ? trim((string)($app['mobile_number'] ?? ($app['mobile_no'] ?? ''))) : '';

            if ($app && $mobile !== '') {
                require_once __DIR__ . '/send_sms.php';
                if ($normalized_status === 'Approved') {
                    $smsMessage = "محترم " . ($app['student_name'] ?? '') . "، آپ کا داخلہ باقاعدہ منظور ہو گیا ہے۔ جامعہ اشرف المدارس میں خوش آمدید۔";
                    $smsResult = send_sms($mobile, $smsMessage);
                } elseif ($normalized_status === 'interview') {
                    $smsMessage = "محترم " . ($app['student_name'] ?? '') . "، آپ کی داخلہ درخواست پر ٹیسٹ و انٹرویو مقرر کیا گیا ہے۔ تاریخ: " . ($test_date ?: '-') . " وقت: " . ($test_time ?: '-') . ($admin_note ? "۔ نوٹ: $admin_note" : "");
                    $smsResult = send_sms($mobile, $smsMessage);
                }

                try {
                    $hasSmsSentColumn = $pdo->query("SHOW COLUMNS FROM admission_applications LIKE 'sms_sent'")->fetch();
                    if ($hasSmsSentColumn) {
                        $pdo->prepare("UPDATE admission_applications SET sms_sent = ? WHERE id = ?")
                            ->execute([!empty($smsResult['success']) ? 1 : 0, $app_id]);
                    }
                } catch (Throwable $e) {}
            }

            // Also if Approved, enroll into students or maktab_students if not yet enrolled
            if ($normalized_status === 'Approved' && $app) {
                try {
                    $dept = strtolower(trim((string)($app['department'] ?? '')));
                    $isMaktab = (strpos($dept, 'مکتب') !== false || strpos($dept, 'maktab') !== false);
                    $targetTable = $isMaktab ? 'maktab_students' : 'students';

                    // Check if already in targetTable
                    $checkAdm = $pdo->prepare("SELECT id FROM $targetTable WHERE name = ? AND father_name = ? LIMIT 1");
                    $checkAdm->execute([$app['student_name'], $app['father_name']]);
                    if (!$checkAdm->fetch()) {
                        $current_hijri = $app['hijri_year'] ?? '1447';
                        $maxId = $pdo->query("SELECT MAX(id) FROM $targetTable")->fetchColumn() ?: 0;
                        $admNo = $current_hijri . "-" . str_pad($maxId + 1, 3, "0", STR_PAD_LEFT);
                        $today = date('Y-m-d');

                        $insStmt = $pdo->prepare("INSERT INTO $targetTable 
                            (name, father_name, admission_number, class, class_name, contact_number, address, status, admission_date, photo, system_type, residence_status) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?, ?, 'residential_aided')");
                        $insStmt->execute([
                            $app['student_name'],
                            $app['father_name'],
                            $admNo,
                            $app['department'] ?: 'حفظ',
                            $app['department'] ?: 'حفظ',
                            $mobile,
                            $app['address'] ?? '',
                            $today,
                            $app['photo'] ?? '',
                            $isMaktab ? 'Masjid' : 'Madrasa'
                        ]);
                    }
                } catch (Throwable $e) {}
            }

            $response = [
                'status' => 'success',
                'message' => "درخواست کی حالت تبدیل ہو گئی ($normalized_status)" . (!empty($smsResult['success']) ? ' اور SMS بھیج دیا گیا' : ''),
                'sms' => $smsResult
            ];
        }
    } elseif ($action == 'resend_admission_sms') {
        require_user(['admin', 'nazim'], 'manage_admissions');
        $input = json_decode(file_get_contents("php://input"), true) ?: $_POST;
        $app_id = intval($input['id'] ?? 0);
        if (!$app_id) {
            $response = ['status' => 'error', 'message' => 'Application ID required'];
        } else {
            $appStmt = $pdo->prepare("SELECT * FROM admission_applications WHERE id = ? LIMIT 1");
            $appStmt->execute([$app_id]);
            $app = $appStmt->fetch(PDO::FETCH_ASSOC);
            if (!$app) {
                $response = ['status' => 'error', 'message' => 'Application not found'];
            } else {
                $mobile = trim((string)($app['mobile_number'] ?? ($app['mobile_no'] ?? '')));
                if ($mobile === '') {
                    $response = ['status' => 'error', 'message' => 'Mobile number missing'];
                } else {
                    require_once __DIR__ . '/send_sms.php';
                    $smsMessage = "محترم " . ($app['student_name'] ?? '') . "، آپ کی درخواست برائے داخلہ منظور ہو گئی ہے۔ ٹیسٹ کی تاریخ: " . (($app['test_date'] ?? '') ?: '-') . " وقت: " . (($app['test_time'] ?? '') ?: '-');
                    if (!empty($app['admin_note'])) {
                        $smsMessage .= "۔ نوٹ: " . $app['admin_note'];
                    }
                    $smsResult = send_sms($mobile, $smsMessage);
                    try {
                        $hasSmsSentColumn = $pdo->query("SHOW COLUMNS FROM admission_applications LIKE 'sms_sent'")->fetch();
                        if ($hasSmsSentColumn) {
                            $pdo->prepare("UPDATE admission_applications SET sms_sent = ? WHERE id = ?")
                                ->execute([!empty($smsResult['success']) ? 1 : 0, $app_id]);
                        }
                    } catch (Throwable $e) {
                        // Keep compatible with older databases.
                    }
                    $response = [
                        'status' => !empty($smsResult['success']) ? 'success' : 'error',
                        'message' => $smsResult['message'] ?? (!empty($smsResult['success']) ? 'SMS sent' : 'SMS failed'),
                        'sms' => $smsResult
                    ];
                }
            }
        }
    } elseif ($action == 'get_users') {
        require_user('admin');
        $users = $pdo->query("SELECT id, username, name, role, permissions, allowed_classes, created_at FROM admins ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $response = ['status' => 'success', 'users' => $users];
    } elseif ($action == 'save_user') {
        require_user('admin');
        $input = json_decode(file_get_contents("php://input"), true) ?: $_POST;
        $id = $input['id'] ?? '';
        $name = trim($input['name'] ?? '');
        $username = trim($input['username'] ?? '');
        $role = $input['role'] ?? 'teacher';
        $password = $input['password'] ?? '';
        $permissions = isset($input['permissions']) && is_array($input['permissions']) ? json_encode($input['permissions']) : '[]';
        $allowed_classes = isset($input['allowed_classes']) && is_array($input['allowed_classes']) ? json_encode($input['allowed_classes']) : (isset($input['allowed_classes']) && is_string($input['allowed_classes']) ? $input['allowed_classes'] : '[]');

        if ($id) { // update
            if (!empty($password)) {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE admins SET name=?, username=?, role=?, permissions=?, allowed_classes=?, password=? WHERE id=?");
                $stmt->execute([$name, $username, $role, $permissions, $allowed_classes, $hashed, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE admins SET name=?, username=?, role=?, permissions=?, allowed_classes=? WHERE id=?");
                $stmt->execute([$name, $username, $role, $permissions, $allowed_classes, $id]);
            }
            if (function_exists('finance_ensure_user_canonical_accounts')) {
                finance_ensure_user_canonical_accounts($pdo, $id, $name, $username, 1);
            }
            $response = ['status' => 'success', 'message' => "User updated successfully"];
        } else { // insert
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO admins (name, username, password, role, permissions, allowed_classes) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $username, $hashed, $role, $permissions, $allowed_classes]);
            $newUid = (int)$pdo->lastInsertId();
            if (function_exists('finance_ensure_user_canonical_accounts')) {
                finance_ensure_user_canonical_accounts($pdo, $newUid, $name, $username, 1);
            }
            $response = ['status' => 'success', 'message' => "User added successfully with canonical accounts"];
        }
    } elseif ($action == 'delete_user') {
        require_user('admin');
        $id = intval($_GET['id'] ?? 0);
        if ($id === 1) {
            $response = ['status' => 'error', 'message' => 'مرکزی ایڈمن اکاؤنٹ (تنزیل الرحمن) کو حذف نہیں کیا جا سکتا۔'];
        } elseif ($id > 0) {
            $pdo->prepare("DELETE FROM admins WHERE id=?")->execute([$id]);
            $response = ['status' => 'success', 'message' => 'User deleted successfully'];
        } else {
            $response = ['status' => 'error', 'message' => 'Invalid ID'];
        }
    } elseif ($action == 'update_fcm_token') {
        $token = trim((string)($input['fcm_token'] ?? ($input['token'] ?? ($_POST['token'] ?? ($_GET['token'] ?? '')))));
        $currentUser = current_user();
        $userId = intval($input['user_id'] ?? ($_POST['user_id'] ?? ($_GET['user_id'] ?? ($currentUser['id'] ?? 0))));
        $role = trim((string)($input['role'] ?? ($_POST['role'] ?? ($_GET['role'] ?? ($currentUser['role'] ?? 'admin')))));

        if (!empty($token) && $userId > 0) {
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS user_fcm_tokens (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    role VARCHAR(50) NOT NULL,
                    fcm_token VARCHAR(255) NOT NULL,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_user_role (user_id, role)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $stmt = $pdo->prepare("INSERT INTO user_fcm_tokens (user_id, role, fcm_token) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE fcm_token = VALUES(fcm_token)");
                $stmt->execute([$userId, $role, $token]);

                $table = in_array($role, ['admin', 'teacher', 'nazim']) ? 'admins' : 'students';
                ensure_column($pdo, $table, 'fcm_token', 'VARCHAR(255) DEFAULT NULL');
                $pdo->prepare("UPDATE {$table} SET fcm_token = ? WHERE id = ?")->execute([$token, $userId]);
            } catch (Exception $e) {
                write_log("update_fcm_token error: " . $e->getMessage());
            }

            $response = ['status' => 'success', 'message' => 'FCM token updated'];
        } else {
            $response = ['status' => 'error', 'message' => 'Token or user missing'];
        }
    } elseif ($action == 'toggle_smart_alert_read') {
        require_user();
        $alert_id = trim($_POST['alert_id'] ?? '');
        $status = trim($_POST['status'] ?? ''); // 'read' or 'unread'
        $user_id = $_SESSION['user_id'];
        
        if (empty($alert_id) || !in_array($status, ['read', 'unread'])) {
            $response = ['status' => 'error', 'message' => 'Invalid parameters'];
        } else {
            if ($status === 'read') {
                $stmt = $pdo->prepare("INSERT INTO smart_notification_reads (alert_id, user_id, is_deleted) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE is_deleted=0");
                $stmt->execute([$alert_id, $user_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE smart_notification_reads SET is_deleted=1 WHERE alert_id=? AND user_id=?");
                $stmt->execute([$alert_id, $user_id]);
            }
            $response = ['status' => 'success', 'message' => 'Status updated'];
        }
    } elseif ($action == 'send_push_notification') {
        require_user('admin');
        require_once 'notifications.php';
        $title = trim($_POST['title'] ?? 'اطلاع');
        $body = trim($_POST['body'] ?? '');
        $type = trim($_POST['type'] ?? 'general');
        $targetUserId = isset($_POST['target_user_id']) ? intval($_POST['target_user_id']) : null;
        $targetRole = isset($_POST['target_role']) ? trim($_POST['target_role']) : null;

        sendPushNotificationFCM($pdo, $title, $body, $type, $targetUserId, $targetRole);
        $response = ['status' => 'success', 'message' => 'Push notification dispatched'];
    } elseif ($action == 'request_finance_edit_otp') {
        $user = current_user();
        if (!$user) {
            $uId = (int)($input['user_id'] ?? ($_POST['user_id'] ?? 0));
            $tId = (int)($input['tenant_id'] ?? ($_POST['tenant_id'] ?? 1));
            if ($uId > 0) {
                $uStmt = $pdo->prepare("SELECT id, username, name, role FROM admins WHERE id = ? LIMIT 1");
                $uStmt->execute([$uId]);
                $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                if (!$uRow) {
                    $uStmt = $pdo->prepare("SELECT id, name, username FROM teachers WHERE id = ? LIMIT 1");
                    $uStmt->execute([$uId]);
                    $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                    if ($uRow) $uRow['role'] = 'teacher';
                }
                if ($uRow) {
                    $user = [
                        'id' => (int)$uRow['id'],
                        'name' => $uRow['name'] ?? $uRow['username'],
                        'username' => $uRow['username'] ?? '',
                        'role' => $uRow['role'] ?? 'teacher',
                        'tenant_id' => $tId,
                    ];
                }
            }
        }
        if (!$user) {
            $user = require_user();
        }
        $response = FinanceController::requestEditOtp($pdo, $user, $input);
    } elseif ($action == 'verify_finance_edit_otp') {
        $user = current_user();
        if (!$user) {
            $uId = (int)($input['user_id'] ?? ($_POST['user_id'] ?? 0));
            $tId = (int)($input['tenant_id'] ?? ($_POST['tenant_id'] ?? 1));
            if ($uId > 0) {
                $user = ['id' => $uId, 'tenant_id' => $tId];
            }
        }
        if (!$user) {
            $user = require_user();
        }
        $response = FinanceController::verifyEditOtp($pdo, $user, $input);
    } elseif ($action == 'check_finance_edit_permission') {
        $user = current_user();
        if (!$user) {
            $uId = (int)($input['user_id'] ?? ($_POST['user_id'] ?? 0));
            if ($uId > 0) {
                $uStmt = $pdo->prepare("SELECT id, role FROM admins WHERE id = ? LIMIT 1");
                $uStmt->execute([$uId]);
                $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                $user = ['id' => $uId, 'role' => $uRow['role'] ?? 'staff', 'tenant_id' => (int)($input['tenant_id'] ?? 1)];
            }
        }
        if (!$user) {
            $user = require_user();
        }
        $response = FinanceController::checkEditPermission($pdo, $user, $input);
    } elseif ($action == 'get_admin_pending_otps') {
        $user = current_user();
        if (!$user) {
            $uId = (int)($_GET['user_id'] ?? ($input['user_id'] ?? 0));
            $tId = (int)($_GET['tenant_id'] ?? ($input['tenant_id'] ?? 1));
            if ($uId > 0) {
                $aStmt = $pdo->prepare("SELECT id, role FROM admins WHERE id = ? LIMIT 1");
                $aStmt->execute([$uId]);
                $aRow = $aStmt->fetch(PDO::FETCH_ASSOC);
                if ($aRow && in_array(strtolower($aRow['role']), ['admin', 'superadmin', 'super_admin', 'administrator'])) {
                    $user = ['id' => $uId, 'role' => 'admin', 'tenant_id' => $tId];
                }
            }
        }
        if (!$user) {
            $user = require_user(['admin', 'superadmin', 'super_admin', 'administrator']);
        }
        $response = FinanceController::getPendingOtps($pdo, $user, $input);
    } elseif ($action == 'upload_finance_bill') {
        $user = require_user();
        $response = FinanceController::uploadBill($pdo, $user, $input);
    } else { $response = ['status' => 'error', 'message' => 'Invalid Action']; }
} catch (Throwable $e) {
    write_log("API Error: " . $e->getMessage());
    error_log("Madrasa API Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    $err_msg = $e->getMessage();
    $is_admin = (isset($user) && is_array($user) && ($user['role'] ?? '') === 'admin');
    $show_detail = !IS_PRODUCTION || $is_admin || isset($_GET['debug']) || isset($_POST['debug']);
    $response = [
        'status' => 'error',
        'message' => $show_detail ? ("Server Error: " . $err_msg . " (in " . basename($e->getFile()) . ":" . $e->getLine() . ")") : 'An internal server error occurred. Please try again.',
        'detail' => $err_msg
    ];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
?>
