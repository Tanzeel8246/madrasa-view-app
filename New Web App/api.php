<?php
ob_start();
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/finance_accounting_bridge.php';
// پروڈکشن سیکیورٹی: ایرر کو ڈسپلے نہیں کریں، صرف لاگ فائل میں لکھیں
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

/**
 * ڈیبگ لاگ لکھنے کا فنکشن (Global)
 */
function write_log($msg) {
    try {
        file_put_contents(__DIR__ . "/debug_log.txt", date('H:i:s') . " - " . $msg . PHP_EOL, FILE_APPEND);
    } catch (Exception $e) {}
}

// CORS - صرف اجازت یافتہ Origins کو اجازت دیں
$allowedOrigins = [
    'https://madrasa.mindcraftsai.com',
    'http://madrasa.mindcraftsai.com',
    'https://madrasa.mamfss.com',
    'http://madrasa.mamfss.com',
    'http://localhost',
    'capacitor://localhost',
    'http://localhost:8080',
    'http://localhost:3000',
];
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    // موبائل ایپ کے لیے کھلا چھوڑو (Capacitor/Flutter)
    header("Access-Control-Allow-Origin: *");
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
$envSecret = trim(getenv('MADRASA_API_SECRET') ?: '');
define('API_SECRET_KEY', ($envSecret !== '') ? $envSecret : 'ASHRAF_SECRET_2026');

if (API_SECRET_KEY === '') {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'API secret is not configured.']);
    exit;
}

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

function issue_user_token($userId, $role, $permissions = []) {
    $payload = [
        'uid' => (int)$userId,
        'role' => (string)$role,
        'permissions' => array_values($permissions ?: []),
        'iat' => time(),
        'exp' => time() + (86400 * 3650) // 10 سال: موبائل ایپ میں سیشن لاگ آؤٹ تک برقرار رہے
    ];
    $body = b64url_encode(json_encode($payload));
    $sig = b64url_encode(hash_hmac('sha256', $body, API_SECRET_KEY, true));
    return $body . '.' . $sig;
}

function read_user_token() {
    $headers = getallheaders();
    $normHeaders = array_change_key_case($headers, CASE_LOWER);
    return $normHeaders['x-user-token'] ?? ($_SERVER['HTTP_X_USER_TOKEN'] ?? ($_SERVER['REDIRECT_HTTP_X_USER_TOKEN'] ?? ''));
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
    if (!$token || strpos($token, '.') === false) return null;

    [$body, $sig] = explode('.', $token, 2);
    $expected = b64url_encode(hash_hmac('sha256', $body, API_SECRET_KEY, true));
    if (!hash_equals($expected, $sig)) return null;

    $payload = json_decode(b64url_decode($body), true);
    if (!is_array($payload) || empty($payload['uid']) || empty($payload['role'])) return null;
    if (($payload['exp'] ?? 0) < time()) return null;

    $resolvedUser = [
        'id' => (int)$payload['uid'],
        'role' => strtolower((string)$payload['role']),
        'permissions' => is_array($payload['permissions'] ?? null) ? $payload['permissions'] : []
    ];

    try {
        global $pdo;
        if (isset($pdo) && $pdo instanceof PDO) {
            if (in_array($resolvedUser['role'], ['admin', 'nazim', 'teacher'], true)) {
                $stmt = $pdo->prepare("SELECT id, role, permissions FROM admins WHERE id = ? LIMIT 1");
                $stmt->execute([$resolvedUser['id']]);
                $admin = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$admin) return null;
                $role = !empty($admin['role']) ? strtolower((string)$admin['role']) : 'admin';
                if ($role !== $resolvedUser['role']) return null;
                $permissions = json_decode($admin['permissions'] ?? '[]', true);
                $resolvedUser['permissions'] = is_array($permissions) ? $permissions : [];
            } elseif (in_array($resolvedUser['role'], ['student', 'parent'], true)) {
                $stmt = $pdo->prepare("SELECT id FROM students WHERE id = ? AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1");
                $stmt->execute([$resolvedUser['id']]);
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
    if ($permission !== null && $user['role'] !== 'admin' && !in_array($permission, $user['permissions'], true)) {
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
        'hifz_entries' => 'manage_hifz',
        'nazira_record' => 'manage_hifz',
        'nazira_entries' => 'manage_hifz',
        'attendance_entries' => 'manage_attendance',
        'attendance_teachers' => 'manage_teacher_attendance',
        'teacher_period_attendance' => 'manage_teacher_attendance',
        'academic_reports' => 'manage_academics',
        'complaints' => 'manage_students',
        'acc_chart_of_accounts' => 'manage_erp',
        'acc_vouchers' => 'manage_erp',
        'acc_ledger_entries' => 'manage_erp',
        'acc_payroll' => 'manage_finance',
        'acc_invoices' => 'manage_erp',
        'parent_complaints' => 'manage_requests',
        'notices' => 'manage_notices',
        'exams' => 'manage_exams',
        'exam_results' => 'manage_exams',
        'classes' => 'manage_classes',
        'departments' => 'manage_erp',
        'leave_applications' => 'manage_requests',
        'fee_submissions' => 'manage_finance',
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
        } elseif (($table === 'attendance_teachers' || $table === 'teacher_period_attendance') && (in_array('manage_attendance', $user['permissions'], true) || in_array('manage_teacher_attendance', $user['permissions'], true))) {
            $filtered[$table] = $rows;
        } elseif ($table === 'academic_reports' && (in_array('manage_academics', $user['permissions'], true) || in_array('manage_hifz', $user['permissions'], true))) {
            $filtered[$table] = $rows;
        } elseif ($table === 'acc_payroll' && (in_array('manage_finance', $user['permissions'], true) || in_array('manage_erp', $user['permissions'], true))) {
            $filtered[$table] = $rows;
        } elseif ($permission !== null && in_array($permission, $user['permissions'], true)) {
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
    $headers = getallheaders();
    $normHeaders = array_change_key_case($headers, CASE_LOWER);
    
    // Check Authorization header
    $authHeader = $normHeaders['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    
    // Check X-API-KEY header
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

    $providedKey = ($xApiKey !== '') ? substr($xApiKey, 0, 6) . '... (len=' . strlen($xApiKey) . ')' : 'NONE';
    $expectedKey = substr(API_SECRET_KEY, 0, 6) . '... (len=' . strlen(API_SECRET_KEY) . ')';
    $debugHeaders = json_encode(['headers' => getallheaders(), 'get' => $_GET, 'post' => $_POST]);
    write_log("Unauthorized Access: Action=" . ($_GET['action'] ?? 'none') . " ProvidedKey=" . $providedKey . " ExpectedKey=" . $expectedKey . " Debug=" . $debugHeaders);
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized Access']);
    exit;
}

// تمام ریکوئسٹس کو چیک کریں
$publicActions = ['get_stats', 'login_student', 'login_admin', 'login_parent', 'reset_password'];
if (!in_array($action, $publicActions, true)) {
    authenticate();
}

/**
 * تاریخ کو درست فارمیٹ (YYYY-MM-DD) میں بدلنا
 */
function normalize_date($rawDate) {
    $rawDate = trim((string)$rawDate);
    if ($rawDate === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) return $rawDate;
    if (strpos($rawDate, '/') !== false) {
        $parts = explode('/', $rawDate);
        if (count($parts) === 3) {
            $day = str_pad((string)((int)$parts[0]), 2, '0', STR_PAD_LEFT);
            $month = str_pad((string)((int)$parts[1]), 2, '0', STR_PAD_LEFT);
            $year = (string)((int)$parts[2]);
            if ($year !== '0') return $year . '-' . $month . '-' . $day;
        }
    }
    return $rawDate;
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
    if (in_array($u_lower, ['excellent', 'good', 'average', 'weak'])) return ucfirst($u_lower);
    if (mb_strpos($u, 'ممتاز') !== false || mb_strpos($u, 'بہترین') !== false || mb_strpos($u, 'اعلیٰ') !== false || mb_strpos($u, 'اچھے') !== false || mb_strpos($u, 'پختہ') !== false) return 'Excellent';
    if (mb_strpos($u, 'بہتر') !== false || mb_strpos($u, 'اچھا') !== false || mb_strpos($u, 'ٹھیک') !== false) return 'Good';
    if (mb_strpos($u, 'معمولی') !== false || mb_strpos($u, 'مناسب') !== false || mb_strpos($u, 'درمیانہ') !== false || mb_strpos($u, 'رعایتی پاس') !== false) return 'Average';
    if (mb_strpos($u, 'کمزور') !== false || mb_strpos($u, 'طلب') !== false || mb_strpos($u, 'کچا') !== false || mb_strpos($u, 'ناقص') !== false) return 'Weak';
    return 'Good';
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

function next_user_receipt_no(PDO $pdo, $userId, $preferredNo = null, $excludeFinanceId = null) {
    $userId = max(1, (int)$userId);
    $minNo = $userId * 100000;
    $maxNo = ($userId + 1) * 100000;
    $preferredNo = to_int_or_null($preferredNo);

    if ($preferredNo !== null && $preferredNo > $minNo && $preferredNo < $maxNo) {
        $sql = "SELECT id FROM finance WHERE computer_receipt_no = ?";
        $args = [$preferredNo];
        if ($excludeFinanceId) {
            $sql .= " AND id <> ?";
            $args[] = (int)$excludeFinanceId;
        }
        $sql .= " LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        if (!$stmt->fetchColumn()) return $preferredNo;
    }

    $stmt = $pdo->prepare("SELECT MAX(computer_receipt_no) FROM finance WHERE computer_receipt_no > ? AND computer_receipt_no < ?");
    $stmt->execute([$minNo, $maxNo]);
    $currentMax = (int)$stmt->fetchColumn();
    $nextNo = ($currentMax > $minNo) ? ($currentMax + 1) : ($minNo + 1);
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

// Auto Schema Fixer
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
} catch (Exception $e) {}

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

$response = [];

try {
    write_log("API Request Action: " . $action);
    if ($action == 'get_stats') {
        $total_students = $pdo->query("SELECT COUNT(*) FROM students WHERE LOWER(status) = 'active'")->fetchColumn();
        $total_teachers = $pdo->query("SELECT COUNT(*) FROM teachers WHERE LOWER(status) = 'active'")->fetchColumn();
        $total_hifz = $pdo->query("SELECT COUNT(*) FROM students WHERE LOWER(status) = 'active' AND (class LIKE '%حفظ%' OR class LIKE '%Hifz%')")->fetchColumn();
        $response = ['status' => 'success', 'data' => ['total_students' => (int)$total_students, 'total_teachers' => (int)$total_teachers, 'total_hifz' => (int)$total_hifz, 'total_depts' => 8]];
    } elseif ($action == 'get_ai_insight') {
        require_user(['admin', 'nazim', 'teacher']);
        $json_data = file_get_contents("php://input");
        $input = json_decode($json_data, true) ?: $_POST;
        $prompt = trim((string)($input['prompt'] ?? ''));
        if ($prompt === '') {
            $total_students = $pdo->query("SELECT COUNT(*) FROM students WHERE status='Active'")->fetchColumn();
            $prompt = "You are an AI Management Advisor for a Quran Madrasa. Total Students: $total_students. Provide a 2-line strategic advice in URDU for the ADMIN. Just Urdu text.";
        }
        $ai_response = call_ai_provider($pdo, $prompt);
        $response = ['status' => 'success', 'insight' => trim($ai_response)];
    } elseif ($action == 'login_student') {
        $json_data = file_get_contents("php://input");
        $input = json_decode($json_data, true) ?: $_POST;
        $username = trim((string)($input['username'] ?? ''));
        $password = trim((string)($input['password'] ?? ''));
        
        $stmt = $pdo->prepare("SELECT * FROM students WHERE admission_number = ? AND is_deleted = 0");
        $stmt->execute([$username]);
        $student = $stmt->fetch();
        
        if ($student && function_exists('verify_pass') && verify_pass($password, $student['password'])) {
            $response = [
                'status' => 'success',
                'message' => 'Login successful',
                'student_id' => $student['id'],
                'role' => 'student',
                'token' => issue_user_token($student['id'], 'student', [])
            ];
        } else {
            $response = ['status' => 'error', 'message' => 'Invalid credentials'];
        }
    } elseif ($action == 'login_parent') {
        $json_data = file_get_contents("php://input");
        $input = json_decode($json_data, true) ?: $_POST;
        $phone = trim((string)($input['phone'] ?? ''));
        $password = trim((string)($input['password'] ?? ''));
        
        $stmt = $pdo->prepare("SELECT * FROM parents WHERE phone = ? AND is_active = 1");
        $stmt->execute([$phone]);
        $parent = $stmt->fetch();
        
        if ($parent && function_exists('verify_pass') && verify_pass($password, $parent['password'])) {
            $response = [
                'status' => 'success',
                'message' => 'Login successful',
                'parent_id' => $parent['id'],
                'role' => 'parent',
                'token' => issue_user_token($parent['id'], 'parent', [])
            ];
        } else {
            $response = ['status' => 'error', 'message' => 'Invalid parent credentials'];
        }
    } elseif ($action == 'login_admin') {
        $json_data = file_get_contents("php://input");
        $input = json_decode($json_data, true) ?: $_POST;
        $username = trim((string)($input['username'] ?? ''));
        $password = trim((string)($input['password'] ?? ''));
        
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
        
        // verify_pass already available from db.php included above
        if ($admin && function_exists('verify_pass') && verify_pass($password, $admin['password'])) {
            $role = !empty($admin['role']) ? $admin['role'] : 'admin';
            $permissions = json_decode($admin['permissions'] ?? '[]', true) ?: [];
            $response = [
                'status' => 'success',
                'message' => 'Login successful',
                'admin_id' => $admin['id'],
                'role' => $role,
                'permissions' => $permissions,
                'token' => issue_user_token($admin['id'], $role, $permissions)
            ];
        } else {
            $response = ['status' => 'error', 'message' => 'Invalid admin credentials'];
        }
    } elseif ($action == 'sync_down') {
        $user = require_user();
        write_log("Sync Down Started");
        $data = [];
        $fetch = function($originalSql) use ($pdo) {
            try { 
                return $pdo->query($originalSql)->fetchAll(PDO::FETCH_ASSOC); 
            } catch (Exception $e) { 
                return [];
            }
        };
        $settings = $pdo->query("SELECT madrasa_name, phone, current_hijri_year, week_start_day, ai_provider, auto_notify_day, auto_notify_time FROM settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
        if ($settings) $data['settings'] = $settings;
        $allowed_classes = get_allowed_classes($pdo, 'id ASC', $user['role'] ?? null, $user['id'] ?? null);
        $data['classes'] = array_values(array_filter($allowed_classes, function($c) { return $c['status'] == 'Active'; }));
        $data['notices'] = $fetch("SELECT * FROM notices ORDER BY date DESC LIMIT 300");

        if ($user['role'] === 'student' || $user['role'] === 'parent') {
            $studentIds = [];
            if ($user['role'] === 'student') {
                $studentIds[] = (int)$user['id'];
            } else {
                $pid = (int)$user['id'];
                $stStmt = $pdo->prepare("SELECT student_id FROM student_parents WHERE parent_id = ?");
                $stStmt->execute([$pid]);
                $studentIds = $stStmt->fetchAll(PDO::FETCH_COLUMN);
            }

            if (empty($studentIds)) {
                $data['students'] = [];
                $data['hifz_history'] = [];
                $data['attendance_history'] = [];
                $data['academic_reports'] = [];
                $data['qaida_records'] = [];
                $data['complaints'] = [];
                $data['parent_complaints'] = [];
                $data['acc_chart_of_accounts'] = $pdo->query("SELECT * FROM acc_chart_of_accounts ORDER BY code ASC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);
                $data['acc_vouchers'] = $pdo->query("SELECT * FROM acc_vouchers ORDER BY date DESC, id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
                $data['acc_ledger_entries'] = $pdo->query("SELECT * FROM acc_ledger_entries ORDER BY id DESC LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC);
                $data['acc_payroll'] = $pdo->query("SELECT p.* FROM acc_payroll p JOIN teachers t ON p.teacher_id = t.id WHERE (t.is_deleted = 0 OR t.is_deleted IS NULL) AND (t.status = 'Active' OR t.status IS NULL OR t.status = '') ORDER BY p.id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
                $data['acc_invoices'] = $pdo->query("SELECT * FROM acc_invoices ORDER BY id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
                $data['departments'] = $pdo->query("SELECT * FROM departments ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $inClause = implode(',', array_map('intval', $studentIds));
                $data['students'] = array_map('strip_sensitive_row', $pdo->query("SELECT id, name, father_name, admission_number, class, contact_number, address, status, admission_date, graduation_date, duration, photo, leaving_reason, serial_no, hijri_year, is_deleted, residence_status FROM students WHERE id IN ($inClause)")->fetchAll(PDO::FETCH_ASSOC));
                $data['hifz_history'] = $pdo->query("SELECT * FROM daily_hifz WHERE student_id IN ($inClause) ORDER BY date DESC LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC);
                $data['nazira_history'] = $pdo->query("SELECT * FROM daily_nazira WHERE student_id IN ($inClause) ORDER BY date DESC LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC);
                $data['attendance_history'] = $pdo->query("SELECT * FROM period_attendance WHERE student_id IN ($inClause) ORDER BY date DESC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);
                $data['qaida_records'] = $pdo->query("SELECT * FROM qaida_records WHERE student_id IN ($inClause) ORDER BY date DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
                  $data['academic_reports'] = $pdo->query("SELECT * FROM academic_reports WHERE student_id IN ($inClause) ORDER BY date DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
                $data['complaints'] = $pdo->query("SELECT * FROM complaints WHERE student_id IN ($inClause) ORDER BY date DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
                $data['parent_complaints'] = $pdo->query("SELECT * FROM parent_complaints WHERE student_id IN ($inClause) ORDER BY date DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
                $data['leave_applications'] = $pdo->query("SELECT * FROM leave_applications WHERE student_id IN ($inClause) ORDER BY id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
                $data['fee_submissions'] = $pdo->query("SELECT * FROM fee_submissions WHERE student_id IN ($inClause) ORDER BY id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
                $data['exam_results'] = $pdo->query("SELECT * FROM exam_results WHERE student_id IN ($inClause) ORDER BY id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
                $data['finance_history'] = $pdo->query("
                    SELECT f.*, COALESCE(MONTH(f.date), 0) AS month, COALESCE(YEAR(f.date), 0) AS year 
                    FROM fee_submissions f 
                    WHERE f.student_id IN ($inClause)
                    ORDER BY f.date DESC, f.id DESC
                    LIMIT 500
                ")->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        if ($user['role'] === 'admin' || $user['role'] === 'nazim' || $user['role'] === 'teacher' || in_array('manage_students', $user['permissions'], true)) {
            if ($user['role'] === 'admin') {
                $data['students'] = array_map('strip_sensitive_row', $fetch("SELECT id, name, father_name, admission_number, class, contact_number, address, status, admission_date, graduation_date, duration, photo, leaving_reason, serial_no, hijri_year, is_deleted, residence_status FROM students ORDER BY id DESC LIMIT 10000"));
                $data['complaints'] = $fetch("SELECT * FROM complaints ORDER BY date DESC LIMIT 1000");
            } else {
                $allowed_class_names = array_column($allowed_classes, 'name');
                if (empty($allowed_class_names)) {
                    $data['students'] = [];
                    $data['complaints'] = [];
                } else {
                    $inClasses = implode(',', array_map(function($c) use ($pdo) { return $pdo->quote($c); }, $allowed_class_names));
                    $data['students'] = array_map('strip_sensitive_row', $fetch("SELECT id, name, father_name, admission_number, class, contact_number, address, status, admission_date, graduation_date, duration, photo, leaving_reason, serial_no, hijri_year, is_deleted, residence_status FROM students WHERE class IN ($inClasses) ORDER BY id DESC LIMIT 10000"));
                    $studentIds = array_column($data['students'], 'id');
                    if (!empty($studentIds)) {
                        $inStudentIds = implode(',', array_map('intval', $studentIds));
                        $data['complaints'] = $fetch("SELECT * FROM complaints WHERE student_id IN ($inStudentIds) ORDER BY date DESC LIMIT 1000");
                    } else {
                        $data['complaints'] = [];
                    }
                }
            }
        }
        if ($user['role'] === 'admin' || $user['role'] === 'nazim' || in_array('manage_finance', $user['permissions'], true)) {
            if ($user['role'] === 'admin' || $user['role'] === 'nazim') {
                // Madrasa finance (system_type = 'Madrasa' OR NULL)
                $data['finance_history'] = $fetch("SELECT * FROM finance WHERE (system_type = 'Madrasa' OR system_type IS NULL) AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY date DESC, id DESC LIMIT 5000");
                // Masjid finance (system_type = 'Masjid')
                $data['masjid_finance_history'] = $fetch("SELECT * FROM finance WHERE system_type = 'Masjid' AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY date DESC, id DESC LIMIT 5000");
            } else {
                $uid = intval($user['id'] ?? 0);
                $data['finance_history'] = $fetch("SELECT * FROM finance WHERE user_id = $uid AND (system_type = 'Madrasa' OR system_type IS NULL) AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY date DESC, id DESC LIMIT 5000");
                $data['masjid_finance_history'] = $fetch("SELECT * FROM finance WHERE user_id = $uid AND system_type = 'Masjid' AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY date DESC, id DESC LIMIT 5000");
            }
            sync_all_heads_with_chart_and_departments($pdo);
            $data['heads'] = $fetch("SELECT * FROM heads WHERE is_deleted = 0 OR is_deleted IS NULL ORDER BY type ASC, name ASC LIMIT 1000");
            $data['fee_submissions'] = $fetch("SELECT * FROM fee_submissions ORDER BY id DESC LIMIT 1000");
        }
        if ($user['role'] === 'admin' || $user['role'] === 'nazim' || $user['role'] === 'teacher' || in_array('manage_exams', $user['permissions'], true)) {
            $data['exams'] = $fetch("SELECT * FROM exams ORDER BY exam_date DESC, id DESC LIMIT 1000");
            if ($user['role'] === 'admin' || $user['role'] === 'nazim') {
                $data['exam_results'] = $fetch("SELECT * FROM exam_results ORDER BY id DESC LIMIT 5000");
            } else {
                // Reuse allowed student IDs if already built
                $inAllowedStdExams = $inAllowedStd ?? ($inAllowedStd2 ?? null);
                if (!$inAllowedStdExams) {
                    $allowed_class_names_ex = array_column($allowed_classes, 'name');
                    if (!empty($allowed_class_names_ex)) {
                        $inCEx = implode(',', array_map(function($c) use ($pdo) { return $pdo->quote($c); }, $allowed_class_names_ex));
                        $exStdIds = array_column($pdo->query("SELECT id FROM students WHERE class IN ($inCEx) AND is_deleted=0")->fetchAll(PDO::FETCH_ASSOC), 'id');
                        $inAllowedStdExams = !empty($exStdIds) ? implode(',', array_map('intval', $exStdIds)) : null;
                    }
                }
                if ($inAllowedStdExams) {
                    $data['exam_results'] = $fetch("SELECT * FROM exam_results WHERE student_id IN ($inAllowedStdExams) ORDER BY id DESC LIMIT 5000");
                } else {
                    $data['exam_results'] = [];
                }
            }
        }
        if ($user['role'] === 'admin' || $user['role'] === 'nazim' || in_array('manage_erp', $user['permissions'], true) || in_array('manage_finance', $user['permissions'], true)) {
            finance_accounting_backfill($pdo);
            $data['departments'] = $fetch("SELECT * FROM departments ORDER BY name ASC LIMIT 1000");
            $data['acc_chart_of_accounts'] = $fetch("SELECT * FROM acc_chart_of_accounts ORDER BY code ASC LIMIT 1000");
            $data['acc_vouchers'] = $fetch("SELECT * FROM acc_vouchers ORDER BY date DESC, id DESC LIMIT 1000");
            $data['acc_ledger_entries'] = $fetch("SELECT * FROM acc_ledger_entries ORDER BY id DESC LIMIT 5000");
            $data['acc_payroll'] = $fetch("SELECT p.* FROM acc_payroll p JOIN teachers t ON p.teacher_id = t.id WHERE (t.is_deleted = 0 OR t.is_deleted IS NULL) AND (t.status = 'Active' OR t.status IS NULL OR t.status = '') ORDER BY p.id DESC LIMIT 1000");
            $data['acc_invoices'] = $fetch("SELECT * FROM acc_invoices ORDER BY id DESC LIMIT 1000");
        }
            if ($user['role'] === 'admin' || $user['role'] === 'nazim' || in_array('manage_requests', $user['permissions'], true)) {
                $data['parent_complaints'] = $fetch("SELECT * FROM parent_complaints ORDER BY date DESC LIMIT 300");
                $data['leave_applications'] = $fetch("SELECT * FROM leave_applications ORDER BY id DESC LIMIT 300");
            }
            if ($user['role'] === 'admin' || $user['role'] === 'nazim' || in_array('manage_finance', $user['permissions'], true)) {
                $data['fee_submissions'] = $fetch("SELECT * FROM fee_submissions ORDER BY id DESC LIMIT 500");
            }
            if ($user['role'] === 'admin' || $user['role'] === 'nazim' || $user['role'] === 'teacher' || in_array('manage_hifz', $user['permissions'], true) || in_array('manage_academics', $user['permissions'], true)) {
                if ($user['role'] === 'admin' || $user['role'] === 'nazim') {
                    $data['hifz_history'] = $fetch("SELECT * FROM daily_hifz ORDER BY date DESC LIMIT 5000");
                    $data['nazira_history'] = $fetch("SELECT * FROM daily_nazira ORDER BY date DESC LIMIT 5000");
                } else {
                    // Filter by allowed classes via student join
                    $sync_class_names = array_column($allowed_classes, 'name');
                    if (!empty($sync_class_names)) {
                        $inSyncClasses = implode(',', array_map(function($c) use ($pdo) { return $pdo->quote($c); }, $sync_class_names));
                        $allowedStudentIds = array_column($pdo->query("SELECT id FROM students WHERE class IN ($inSyncClasses) AND is_deleted=0")->fetchAll(PDO::FETCH_ASSOC), 'id');
                    } else {
                        $allowedStudentIds = [];
                    }
                    if (!empty($allowedStudentIds)) {
                        $inAllowedStd = implode(',', array_map('intval', $allowedStudentIds));
                        $data['hifz_history'] = $fetch("SELECT * FROM daily_hifz WHERE student_id IN ($inAllowedStd) ORDER BY date DESC LIMIT 5000");
                        $data['nazira_history'] = $fetch("SELECT * FROM daily_nazira WHERE student_id IN ($inAllowedStd) ORDER BY date DESC LIMIT 5000");
                    } else {
                        $data['hifz_history'] = [];
                        $data['nazira_history'] = [];
                    }
                }
            }
            if ($user['role'] === 'admin' || $user['role'] === 'nazim' || $user['role'] === 'teacher' || in_array('manage_attendance', $user['permissions'], true)) {
                if ($user['role'] === 'admin' || $user['role'] === 'nazim') {
                    $data['attendance_history'] = $fetch("SELECT * FROM attendance ORDER BY date DESC LIMIT 5000");
                } else {
                    if (!isset($inAllowedStd)) {
                        $sync_class_names2 = array_column($allowed_classes, 'name');
                        if (!empty($sync_class_names2)) {
                            $inSyncClasses2 = implode(',', array_map(function($c) use ($pdo) { return $pdo->quote($c); }, $sync_class_names2));
                            $allowedStudentIds2 = array_column($pdo->query("SELECT id FROM students WHERE class IN ($inSyncClasses2) AND is_deleted=0")->fetchAll(PDO::FETCH_ASSOC), 'id');
                        } else { $allowedStudentIds2 = []; }
                        $inAllowedStd2 = !empty($allowedStudentIds2) ? implode(',', array_map('intval', $allowedStudentIds2)) : null;
                    } else {
                        $inAllowedStd2 = $inAllowedStd;
                    }
                    if ($inAllowedStd2) {
                        $data['attendance_history'] = $fetch("SELECT * FROM attendance WHERE student_id IN ($inAllowedStd2) ORDER BY date DESC LIMIT 5000");
                    } else {
                        $data['attendance_history'] = [];
                    }
                }
            }
            if ($user['role'] === 'admin' || $user['role'] === 'nazim' || in_array('manage_teachers', $user['permissions'], true) || in_array('manage_attendance', $user['permissions'], true) || in_array('manage_teacher_attendance', $user['permissions'], true)) {
                if ($user['role'] === 'admin' || $user['role'] === 'nazim' || in_array('manage_attendance', $user['permissions'], true) || in_array('manage_teacher_attendance', $user['permissions'], true)) {
                    $data['attendance_teachers'] = $fetch("SELECT * FROM attendance_teachers ORDER BY date DESC LIMIT 5000");
                    $data['teacher_period_attendance'] = $fetch("SELECT tpa.*, c.name AS class_name FROM teacher_period_attendance tpa LEFT JOIN classes c ON c.id = tpa.class_id ORDER BY tpa.date DESC, tpa.id DESC LIMIT 5000");
                }

                // Fetch teachers and add assigned_periods for mobile
                $data['teachers'] = $pdo->query("SELECT * FROM teachers")->fetchAll(PDO::FETCH_ASSOC);
                $all_cp = $pdo->query("SELECT cp.class_id, cp.period_no, cp.teacher_id, cp.teacher_ids, c.name AS class_name, c.main_teacher_ids FROM class_periods cp JOIN classes c ON c.id = cp.class_id WHERE (c.status = 'Active' OR c.status IS NULL OR c.status = '')")->fetchAll(PDO::FETCH_ASSOC);
                
                $explicit_map = [];
                foreach ($all_cp as $row) {
                    $cid = $row['class_id'];
                    $pno = $row['period_no'];
                    $tids = [];
                    if (!empty($row['teacher_ids'])) {
                        $decoded = json_decode($row['teacher_ids'], true);
                        if (is_array($decoded)) $tids = array_merge($tids, $decoded);
                    }
                    if (!empty($row['teacher_id'])) $tids[] = $row['teacher_id'];
                    foreach ($tids as $tid) {
                        $explicit_map[$cid][$tid][] = $pno;
                    }
                }
                
                $teacher_assigned_periods = [];
                foreach ($all_cp as $row) {
                    $cid = $row['class_id'];
                    $className = $row['class_name'] ?? '';
                    $pno = (string)$row['period_no'];
                    $tids_for_this_period = [];
                    if (!empty($row['teacher_ids'])) {
                        $decoded = json_decode($row['teacher_ids'], true);
                        if (is_array($decoded)) $tids_for_this_period = array_merge($tids_for_this_period, $decoded);
                    }
                    if (!empty($row['teacher_id'])) $tids_for_this_period[] = $row['teacher_id'];
                    if (!empty($row['main_teacher_ids'])) {
                        $m_ids = json_decode($row['main_teacher_ids'], true);
                        if (!is_array($m_ids)) $m_ids = array_filter(explode(',', $row['main_teacher_ids']));
                        foreach ($m_ids as $tid) {
                            $has_explicit = isset($explicit_map[$cid][$tid]) && count($explicit_map[$cid][$tid]) > 0;
                            if (!$has_explicit) {
                                $tids_for_this_period[] = $tid;
                            }
                        }
                    }
                    foreach (array_unique(array_filter($tids_for_this_period)) as $tid) {
                        if ($className !== '') $teacher_assigned_periods[$tid][$className][] = $pno;
                    }
                }
                
                foreach ($data['teachers'] as &$t) {
                    $tid = $t['id'];
                    $profileClasses = sync_list_values($t['assigned_classes'] ?? '');
                    $periodMap = sync_decode_teacher_periods($t['assigned_periods'] ?? '');
                    foreach (($teacher_assigned_periods[$tid] ?? []) as $className => $periods) {
                        if (!isset($periodMap[$className]) || empty($periodMap[$className])) {
                            $periodMap[$className] = $periods;
                        }
                    }
                    foreach ($periodMap as $className => $periods) {
                        $periodMap[$className] = array_values(array_unique(array_map('strval', $periods)));
                    }
                    $classList = array_values(array_unique(array_filter(array_merge($profileClasses, array_keys($periodMap)))));
                    $t['assigned_classes'] = implode(',', $classList);
                    $t['assigned_periods'] = json_encode($periodMap, JSON_UNESCAPED_UNICODE);
                }
            }
            if ($user['role'] === 'admin' || $user['role'] === 'nazim' || $user['role'] === 'teacher' || in_array('manage_hifz', $user['permissions'], true) || in_array('manage_academics', $user['permissions'], true)) {
                if ($user['role'] === 'admin' || $user['role'] === 'nazim') {
                    $data['academic_reports'] = $fetch("SELECT * FROM academic_reports ORDER BY date DESC LIMIT 5000");
                } else {
                    $inAllowedStdFinal = $inAllowedStd ?? ($inAllowedStd2 ?? null);
                    if ($inAllowedStdFinal) {
                        $data['academic_reports'] = $fetch("SELECT * FROM academic_reports WHERE student_id IN ($inAllowedStdFinal) ORDER BY date DESC LIMIT 5000");
                    } else {
                        $data['academic_reports'] = [];
                    }
                }
            }
            if ($user['role'] === 'admin' || $user['role'] === 'nazim' || $user['role'] === 'teacher' || in_array('manage_hifz', $user['permissions'], true)) {
                if ($user['role'] === 'admin' || $user['role'] === 'nazim') {
                    $data['qaida_records'] = $fetch("SELECT * FROM qaida_records WHERE (is_deleted = 0 OR is_deleted IS NULL) ORDER BY date DESC LIMIT 5000");
                } else {
                    $inAllowedStdQaida = $inAllowedStd ?? ($inAllowedStd2 ?? null);
                    if (!$inAllowedStdQaida) {
                        $sync_class_names_q = array_column($allowed_classes, 'name');
                        if (!empty($sync_class_names_q)) {
                            $inSyncClassesQ = implode(',', array_map(function($c) use ($pdo) { return $pdo->quote($c); }, $sync_class_names_q));
                            $allowedStudentIdsQ = array_column($pdo->query("SELECT id FROM students WHERE class IN ($inSyncClassesQ) AND is_deleted=0")->fetchAll(PDO::FETCH_ASSOC), 'id');
                            $inAllowedStdQaida = !empty($allowedStudentIdsQ) ? implode(',', array_map('intval', $allowedStudentIdsQ)) : null;
                        }
                    }
                    if ($inAllowedStdQaida) {
                        $data['qaida_records'] = $fetch("SELECT * FROM qaida_records WHERE student_id IN ($inAllowedStdQaida) AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY date DESC LIMIT 5000");
                    } else {
                        $data['qaida_records'] = [];
                    }
                }
            }
        $response = ['status' => 'success', 'data' => $data];
        write_log("Sync Down Finished");
    } elseif ($action == 'sync_up') {
        $user = require_user();
        $json_data = file_get_contents("php://input");
        write_log("Sync Up Data Received: " . strlen($json_data) . " bytes");
        $input = json_decode($json_data, true) ?: [];
        if ($user['role'] === 'student' || $user['role'] === 'parent') {
            $allowedStudentSync = ['parent_complaints', 'leave_applications', 'fee_submissions'];
            $parentStudentIds = [];
            if ($user['role'] === 'parent') {
                $stStmt = $pdo->prepare("SELECT student_id FROM student_parents WHERE parent_id = ?");
                $stStmt->execute([$user['id']]);
                $parentStudentIds = array_map('intval', $stStmt->fetchAll(PDO::FETCH_COLUMN));
            }
            $input = array_intersect_key($input, array_flip($allowedStudentSync));
            foreach ($allowedStudentSync as $key) {
                if (!empty($input[$key]) && is_array($input[$key])) {
                    foreach ($input[$key] as &$item) {
                        if (is_array($item) && $user['role'] === 'student') {
                            $item['student_id'] = $user['id'];
                        } elseif (is_array($item) && $user['role'] === 'parent') {
                            $requestedStudentId = (int)($item['student_id'] ?? 0);
                            if (!in_array($requestedStudentId, $parentStudentIds, true)) {
                                $item = null;
                            }
                        }
                    }
                    unset($item);
                    $input[$key] = array_values(array_filter($input[$key], 'is_array'));
                }
            }
        } elseif (!in_array($user['role'], ['admin', 'nazim', 'teacher'], true)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
            exit;
        } else {
            $input = filter_sync_payload_for_user($input, $user);
        }
        if (empty($input)) {
            write_log("Sync Up: Decoded input is empty or invalid JSON.");
        } else {
            write_log("Sync Up: Keys present: " . implode(', ', array_keys($input)));
        }
        $syncedIds = ['finance_entries' => [], 'masjid_finance_entries' => [], 'heads' => [], 'hifz_entries' => [], 'nazira_record' => [], 'students' => [], 'teachers' => [], 'attendance_entries' => [], 'attendance_teachers' => [], 'teacher_period_attendance' => [], 'complaints' => [], 'parent_complaints' => [], 'notices' => [], 'exams' => [], 'leave_applications' => [], 'fee_submissions' => [], 'academic_reports' => [], 'qaida_records' => [], 'exam_results' => [], 'classes' => [], 'departments' => [], 'acc_chart_of_accounts' => [], 'acc_vouchers' => [], 'acc_ledger_entries' => [], 'acc_payroll' => [], 'acc_invoices' => []];
        $serverIds = ['finance_entries' => [], 'teachers' => [], 'notices' => []];
        $syncErrors = [];

        // Permission setup for all sync_up checks
        $userRole = $user['role'] ?? '';
        $allowed_classes_up = get_allowed_classes($pdo, 'id ASC', $userRole, $user['id'] ?? null);
        $allowed_class_names_up = array_column($allowed_classes_up, 'name');

        // Image Upload Helper
        $uploadImage = function($base64Data, $prefix) {
            if (empty($base64Data) || strlen($base64Data) < 100) return $base64Data;
            // If it's already a filename (no base64 prefix), return it
            if (strpos($base64Data, 'data:image') === false && strpos($base64Data, ';base64,') === false) return $base64Data;
            
            try {
                $parts = explode(";base64,", $base64Data);
                if (count($parts) != 2) return $base64Data;
                $ext = 'jpg';
                if (strpos($parts[0], 'png') !== false) $ext = 'png';
                $filename = $prefix . "_" . time() . "_" . rand(1000, 9999) . "." . $ext;
                
                $folder = "uploads/students";
                if ($prefix === 'fee' || $prefix === 'leave' || $prefix === 'teacher') {
                    $folder = "uploads/" . ($prefix === 'fee' ? 'fees' : ($prefix === 'leave' ? 'leaves' : 'teachers'));
                }
                
                $path = __DIR__ . "/" . $folder . "/" . $filename;
                if (!is_dir(__DIR__ . "/" . $folder)) mkdir(__DIR__ . "/" . $folder, 0777, true);
                file_put_contents($path, base64_decode($parts[1]));
                return $folder . "/" . $filename;
            } catch (Exception $e) { return $base64Data; }
        };

        // Finance
        // Note: finance_entries are processed below (in the simple finance block)
        // Hifz
        if (!empty($input['hifz_entries']) && is_array($input['hifz_entries'])) {
            foreach ($input['hifz_entries'] as $h) {
                $localId = to_int_or_null($h['id'] ?? null);
                try {
                    $date = normalize_date($h['date'] ?? ''); $studentId = $h['student_id'] ?? null;
                    if (!$date || !$studentId) continue;

                    // Permission check: only allow entry for allowed classes
                    if ($userRole !== 'admin' && $userRole !== 'nazim') {
                        $hClsStmt = $pdo->prepare("SELECT class FROM students WHERE id = ? LIMIT 1");
                        $hClsStmt->execute([$studentId]);
                        $hCls = $hClsStmt->fetchColumn();
                        if ($hCls && !in_array($hCls, $allowed_class_names_up)) {
                            write_log("Sync Up: hifz_entries - user not allowed for class $hCls");
                            if ($localId !== null) $syncedIds['hifz_entries'][] = $localId;
                            continue;
                        }
                    }

                    $sabaq_status = map_grade($h['sabaq_grade'] ?? $h['sabaq_status'] ?? '');
                    $sabqi_status = map_grade($h['sabqi_grade'] ?? $h['sabqi_status'] ?? '');
                    $manzil_status = map_grade($h['manzil_grade'] ?? $h['manzil_status'] ?? '');
                    $check = $pdo->prepare("SELECT * FROM daily_hifz WHERE date = ? AND student_id = ?");
                    $check->execute([$date, $studentId]);
                    $existingHifz = $check->fetch(PDO::FETCH_ASSOC);
                    if (!$existingHifz) {
                        $stmt = $pdo->prepare("INSERT INTO daily_hifz (date, student_id, sabaq_parah, sabaq_lines, sabaq_status, sabqi_parah, sabqi_amount, sabqi_status, sabqi_listener, manzil_parah, manzil_amount, manzil_status, manzil_listener, sabaq_time, sabqi_time, manzil_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $res = $stmt->execute([$date, $studentId, $h['sabaq_para'] ?? '', $h['sabaq_lines'] ?? '', $sabaq_status, $h['sabqi_para'] ?? '', $h['sabqi_qty'] ?? '', $sabqi_status, $h['sabqi_listener'] ?? '', $h['manzil_details'] ?? '', $h['manzil_total_qty'] ?? '', $manzil_status, $h['manzil_listener'] ?? '', $h['sabaq_time'] ?? '', $h['sabqi_time'] ?? '', $h['manzil_time'] ?? '']);
                        write_log("Hifz Insert Result: " . ($res ? "Success" : "Fail"));
                    } else {
                        $keepOld = function($newValue, $oldValue) {
                            return trim((string)$newValue) !== '' ? $newValue : $oldValue;
                        };
                        $stmt = $pdo->prepare("UPDATE daily_hifz SET sabaq_parah=?, sabaq_lines=?, sabaq_status=?, sabqi_parah=?, sabqi_amount=?, sabqi_status=?, sabqi_listener=?, manzil_parah=?, manzil_amount=?, manzil_status=?, manzil_listener=?, sabaq_time=?, sabqi_time=?, manzil_time=? WHERE id=?");
                        $res = $stmt->execute([
                            $keepOld($h['sabaq_para'] ?? '', $existingHifz['sabaq_parah'] ?? ''),
                            $keepOld($h['sabaq_lines'] ?? '', $existingHifz['sabaq_lines'] ?? ''),
                            $keepOld($sabaq_status, $existingHifz['sabaq_status'] ?? ''),
                            $keepOld($h['sabqi_para'] ?? '', $existingHifz['sabqi_parah'] ?? ''),
                            $keepOld($h['sabqi_qty'] ?? '', $existingHifz['sabqi_amount'] ?? ''),
                            $keepOld($sabqi_status, $existingHifz['sabqi_status'] ?? ''),
                            $keepOld($h['sabqi_listener'] ?? '', $existingHifz['sabqi_listener'] ?? ''),
                            $keepOld($h['manzil_details'] ?? '', $existingHifz['manzil_parah'] ?? ''),
                            $keepOld($h['manzil_total_qty'] ?? '', $existingHifz['manzil_amount'] ?? ''),
                            $keepOld($manzil_status, $existingHifz['manzil_status'] ?? ''),
                            $keepOld($h['manzil_listener'] ?? '', $existingHifz['manzil_listener'] ?? ''),
                            $keepOld($h['sabaq_time'] ?? '', $existingHifz['sabaq_time'] ?? ''),
                            $keepOld($h['sabqi_time'] ?? '', $existingHifz['sabqi_time'] ?? ''),
                            $keepOld($h['manzil_time'] ?? '', $existingHifz['manzil_time'] ?? ''),
                            $existingHifz['id'],
                        ]);
                        write_log("Hifz Update Result: " . ($res ? "Success" : "Fail"));
                    }
                    if ($localId !== null) $syncedIds['hifz_entries'][] = $localId;
                } catch (Exception $e) {
                    write_log("SQL Error in Sync: " . $e->getMessage());
                    $syncErrors[] = $e->getMessage();
                }
            }
        }
        
        if (!empty($input['nazira_record'])) {
            foreach ($input['nazira_record'] as $row) {
                $localId = to_int_or_null($row['id'] ?? null);
                $date = normalize_date($row['date'] ?? '');
                $student_id = $row['student_id'] ?? null;
                if (!$date || !$student_id) continue;

                // Permission check for nazira
                if ($userRole !== 'admin' && $userRole !== 'nazim') {
                    $nClsStmt = $pdo->prepare("SELECT class FROM students WHERE id = ? LIMIT 1");
                    $nClsStmt->execute([$student_id]);
                    $nCls = $nClsStmt->fetchColumn();
                    if ($nCls && !in_array($nCls, $allowed_class_names_up)) {
                        write_log("Sync Up: nazira_record - user not allowed for class $nCls");
                        if ($localId !== null) $syncedIds['nazira_record'][] = $localId;
                        continue;
                    }
                }
                $check = $pdo->prepare("SELECT * FROM daily_nazira WHERE date = ? AND student_id = ?");
                $check->execute([$date, $student_id]);
                $ex = $check->fetch(PDO::FETCH_ASSOC);
                if (!$ex) {
                    $stmt = $pdo->prepare("INSERT INTO daily_nazira (date, student_id, morning_sabaq, morning_qty, morning_time, morning_status, evening_sabaq, evening_qty, evening_time, evening_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$date, $student_id, $row['morning_sabaq'], $row['morning_qty'] ?? '', $row['morning_time'] ?? '', $row['morning_status'], $row['evening_sabaq'], $row['evening_qty'] ?? '', $row['evening_time'] ?? '', $row['evening_status']]);
                } else {
                    $stmt = $pdo->prepare("UPDATE daily_nazira SET morning_sabaq=?, morning_qty=?, morning_time=?, morning_status=?, evening_sabaq=?, evening_qty=?, evening_time=?, evening_status=? WHERE id=?");
                    $stmt->execute([
                        trim($row['morning_sabaq'] ?? '') !== '' ? $row['morning_sabaq'] : $ex['morning_sabaq'],
                        isset($row['morning_qty']) ? $row['morning_qty'] : $ex['morning_qty'],
                        isset($row['morning_time']) ? $row['morning_time'] : $ex['morning_time'],
                        trim($row['morning_status'] ?? '') !== '' ? $row['morning_status'] : $ex['morning_status'],
                        trim($row['evening_sabaq'] ?? '') !== '' ? $row['evening_sabaq'] : $ex['evening_sabaq'],
                        isset($row['evening_qty']) ? $row['evening_qty'] : $ex['evening_qty'],
                        isset($row['evening_time']) ? $row['evening_time'] : $ex['evening_time'],
                        trim($row['evening_status'] ?? '') !== '' ? $row['evening_status'] : $ex['evening_status'],
                        $ex['id']
                    ]);
                }
                if ($localId !== null) $syncedIds['nazira_record'][] = $localId;
            }
        }

        // Academic Reports
        if (!empty($input['academic_reports']) && is_array($input['academic_reports'])) {
            foreach ($input['academic_reports'] as $ar) {
                $localId = to_int_or_null($ar['id'] ?? null);
                try {
                    $date = normalize_date($ar['date'] ?? '');
                    $studentId = $ar['student_id'] ?? null;
                    if (!$date || !$studentId) continue;

                    // Permission check using class_name from payload
                    if ($userRole !== 'admin' && $userRole !== 'nazim') {
                        $arCls = $ar['class_name'] ?? '';
                        if ($arCls === '') {
                            $arClsStmt = $pdo->prepare("SELECT class FROM students WHERE id = ? LIMIT 1");
                            $arClsStmt->execute([$studentId]);
                            $arCls = $arClsStmt->fetchColumn() ?: '';
                        }
                        if ($arCls && !in_array($arCls, $allowed_class_names_up)) {
                            write_log("Sync Up: academic_reports - user not allowed for class $arCls");
                            if ($localId !== null) $syncedIds['academic_reports'][] = $localId;
                            continue;
                        }
                    }
                    
                    $check = $pdo->prepare("SELECT id FROM academic_reports WHERE student_id = ? AND date = ? AND subject = ?");
                    $check->execute([$studentId, $date, $ar['subject'] ?? '']);
                    $existingAr = $check->fetch(PDO::FETCH_ASSOC);
                    if (!$existingAr) {
                        $stmt = $pdo->prepare("INSERT INTO academic_reports (student_id, date, class_name, subject, lesson_details, grade, remarks) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$studentId, $date, $ar['class_name'] ?? '', $ar['subject'] ?? '', $ar['lesson_details'] ?? '', $ar['grade'] ?? '', $ar['remarks'] ?? '']);
                    } else {
                        $stmt = $pdo->prepare("UPDATE academic_reports SET class_name=?, lesson_details=?, grade=?, remarks=? WHERE id=?");
                        $stmt->execute([$ar['class_name'] ?? '', $ar['lesson_details'] ?? '', $ar['grade'] ?? '', $ar['remarks'] ?? '', $existingAr['id']]);
                    }
                    if ($localId !== null) $syncedIds['academic_reports'][] = $localId;
                } catch (Exception $e) {
                    write_log("SQL Error in Sync Academic Reports: " . $e->getMessage());
                    $syncErrors[] = $e->getMessage();
                }
            }
        }
        $userRole = $user['role'] ?? '';
        $allowed_classes = get_allowed_classes($pdo, 'id ASC', $userRole, $user['id'] ?? null);
        $allowed_class_names = array_column($allowed_classes, 'name');

        // Students
        if (!empty($input['students']) && is_array($input['students'])) {
            foreach ($input['students'] as $s) {
                $localId = to_int_or_null($s['id'] ?? null);
                try {
                    $name = trim((string)($s['name'] ?? '')); if ($name === '') continue;
                    $sClass = $s['class_name'] ?? $s['class'] ?? '';
                    if ($userRole !== 'admin' && !in_array($sClass, $allowed_class_names)) {
                        write_log("Sync Up: User not allowed to update/insert student for class $sClass.");
                        if ($localId !== null) $syncedIds['students'][] = $localId;
                        continue;
                    }
                    $admissionNo = trim((string)($s['admission_number'] ?? ($s['roll_number'] ?? '')));
                    $stmtCheck = $pdo->prepare("SELECT id, is_deleted FROM students WHERE admission_number = ? AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1");
                    $stmtCheck->execute([$admissionNo]);
                    $row = $admissionNo ? $stmtCheck->fetch() : null;
                    if ($row) {
                        if ($row['is_deleted'] == 1) {
                            write_log("Sync Up: Student $admissionNo is deleted on server. Skipping.");
                            if ($localId !== null) $syncedIds['students'][] = $localId;
                            continue;
                        }
                        $existingId = $row['id'];
                        $rawPass = $s['password'] ?? '';
                        $finalPass = (strlen($rawPass) > 0 && strlen($rawPass) < 20) ? hash_pass($rawPass) : $rawPass;
                        $newStatus = trim((string)($s['status'] ?? 'Active'));
                        $photoPath = $uploadImage($s['photo'] ?? '', 'std');
                        $residence_status = $s['residence_status'] ?? '';
                        
                        $stmt = $pdo->prepare("UPDATE students SET name=?, father_name=?, admission_number=?, class=?, contact_number=?, address=?, password=COALESCE(NULLIF(?, ''), password), status=?, admission_date=?, graduation_date=?, duration=?, leaving_reason=?, photo=COALESCE(NULLIF(?, ''), photo), residence_status=? WHERE id=?");
                        $res = $stmt->execute([$name, $s['father_name'] ?? '', $admissionNo, $s['class_name'] ?? $s['class'] ?? '', $s['contact_number'] ?? $s['mobile_no'] ?? '', $s['address'] ?? '', $finalPass, $newStatus, normalize_date($s['admission_date'] ?? ''), normalize_date($s['graduation_date'] ?? ''), $s['duration'] ?? '', $s['leaving_reason'] ?? '', $photoPath, $residence_status, $existingId]);
                        write_log("Student Update Result ($admissionNo / Status: $newStatus): " . ($res ? "Success" : "Fail"));
                    } else {
                        $rawPass = $s['password'] ?? '';
                        $finalPass = (strlen($rawPass) > 0 && strlen($rawPass) < 20) ? hash_pass($rawPass) : $rawPass;
                        $photoPath = $uploadImage($s['photo'] ?? '', 'std');
                        $residence_status = $s['residence_status'] ?? '';
                        
                        $stmt = $pdo->prepare("INSERT INTO students (name, father_name, admission_number, class, contact_number, address, password, status, admission_date, graduation_date, duration, photo, residence_status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?, ?, ?, ?)");
                        $res = $stmt->execute([$name, $s['father_name'] ?? '', $admissionNo, $s['class_name'] ?? $s['class'] ?? '', $s['contact_number'] ?? $s['mobile_no'] ?? '', $s['address'] ?? '', $finalPass, normalize_date($s['admission_date'] ?? ''), normalize_date($s['graduation_date'] ?? ''), $s['duration'] ?? '', $photoPath, $residence_status]);
                        $newId = $pdo->lastInsertId();
                        write_log("Student Insert Result ($admissionNo): " . ($res ? "Success (New ID: $newId)" : "Fail"));
                    }
                    if ($localId !== null) $syncedIds['students'][] = $localId;
                } catch (Exception $e) {
                    write_log("SQL Error in Student Sync: " . $e->getMessage());
                    $syncErrors[] = $e->getMessage();
                }
            }
        }
        // Teachers
        if (!empty($input['teachers']) && is_array($input['teachers'])) {
            foreach ($input['teachers'] as $t) {
                $localId = to_int_or_null($t['id'] ?? null);
                try {
                    $name = trim((string)($t['name'] ?? '')); if ($name === '') continue;
                    $cnic = trim((string)($t['cnic'] ?? ''));
                    $savedTeacherId = null;
                    
                    $teacherData = null;
                    
                    if ($localId) {
                        $row = $pdo->prepare("SELECT id, name, father_name, cnic, is_deleted FROM teachers WHERE id = ? LIMIT 1");
                        $row->execute([$localId]);
                        $teacherData = $row->fetch();
                        if ($teacherData) {
                            $sameCnic = $cnic !== '' && trim((string)($teacherData['cnic'] ?? '')) === $cnic;
                            $sameName = trim((string)($teacherData['name'] ?? '')) === $name
                                && trim((string)($teacherData['father_name'] ?? '')) === trim((string)($t['father_name'] ?? ''));
                            if (!$sameCnic && !$sameName) {
                                $teacherData = null;
                            }
                        }
                    }
                    
                    // صرف تب میچ کریں اگر CNIC موجود ہو
                    if (!$teacherData && !empty($cnic)) {
                        $row = $pdo->prepare("SELECT id, name, father_name, cnic, is_deleted FROM teachers WHERE cnic = ? LIMIT 1");
                        $row->execute([$cnic]);
                        $teacherData = $row->fetch();
                    }
                    
                    // اگر CNIC سے نہ ملے تو نام اور ولدیت سے چیک کریں (لیکن یہ بہت واضح ہونا چاہیے)
                    if (!$teacherData && !empty($name)) {
                        $row = $pdo->prepare("SELECT id, name, father_name, cnic, is_deleted FROM teachers WHERE name = ? AND father_name = ? AND (cnic = '' OR cnic IS NULL) LIMIT 1");
                        $row->execute([$name, $t['father_name'] ?? '']);
                        $teacherData = $row->fetch();
                    }

                    if ((int)($t['is_deleted'] ?? 0) === 1) {
                        if ($teacherData) {
                            $pdo->prepare("UPDATE teachers SET is_deleted = 1 WHERE id = ?")->execute([$teacherData['id']]);
                            $savedTeacherId = (int)$teacherData['id'];
                        }
                        if ($localId !== null) {
                            $syncedIds['teachers'][] = $localId;
                            if ($savedTeacherId) $serverIds['teachers'][(string)$localId] = $savedTeacherId;
                        }
                        continue;
                    }

                    if ($teacherData) {
                        if ($teacherData['is_deleted'] == 1) {
                            if ($localId !== null) {
                                $syncedIds['teachers'][] = $localId;
                                $serverIds['teachers'][(string)$localId] = (int)$teacherData['id'];
                            }
                            continue;
                        }
                        $existingId = $teacherData['id'];
                        $photoPath = $uploadImage($t['photo'] ?? '', 'teacher');
                          $stmt = $pdo->prepare("UPDATE teachers SET name=?, father_name=?, phone=?, cnic=?, qualification=?, salary=?, house_rent=?, medical_allowance=?, travel_allowance=?, daily_deduction_rate=?, joining_date=?, status=?, address=?, photo=COALESCE(NULLIF(?, ''), photo), assigned_classes=?, assigned_periods=?, is_deleted=0 WHERE id=?");
                          $stmt->execute([$name, $t['father_name'] ?? '', $t['phone'] ?? '', $cnic, $t['qualification'] ?? '', to_int_or_null($t['salary'] ?? null), to_int_or_null($t['house_rent'] ?? null), to_int_or_null($t['medical_allowance'] ?? null), to_int_or_null($t['travel_allowance'] ?? null), to_int_or_null($t['daily_deduction_rate'] ?? null), normalize_date($t['joining_date'] ?? ''), $t['status'] ?? 'Active', $t['address'] ?? '', $photoPath, $t['assigned_classes'] ?? '', $t['assigned_periods'] ?? '', $existingId]);
                          $savedTeacherId = (int)$existingId;
                    } else {
                        $photoPath = $uploadImage($t['photo'] ?? '', 'teacher');
                          $stmt = $pdo->prepare("INSERT INTO teachers (name, father_name, phone, cnic, qualification, salary, house_rent, medical_allowance, travel_allowance, daily_deduction_rate, joining_date, status, address, photo, assigned_classes, assigned_periods, is_deleted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");
                          $stmt->execute([$name, $t['father_name'] ?? '', $t['phone'] ?? '', $cnic, $t['qualification'] ?? '', to_int_or_null($t['salary'] ?? null), to_int_or_null($t['house_rent'] ?? null), to_int_or_null($t['medical_allowance'] ?? null), to_int_or_null($t['travel_allowance'] ?? null), to_int_or_null($t['daily_deduction_rate'] ?? null), normalize_date($t['joining_date'] ?? ''), $t['status'] ?? 'Active', $t['address'] ?? '', $photoPath, $t['assigned_classes'] ?? '', $t['assigned_periods'] ?? '']);
                          $savedTeacherId = (int)$pdo->lastInsertId();
                    }
                    if ($localId !== null) {
                        $syncedIds['teachers'][] = $localId;
                        if ($savedTeacherId) $serverIds['teachers'][(string)$localId] = $savedTeacherId;
                    }
                } catch (Exception $e) {
                    write_log("SQL Error in Teacher Sync: " . $e->getMessage());
                    $syncErrors[] = $e->getMessage();
                }
            }
        }
        
        // Attendance Teachers
        if (!empty($input['attendance_teachers']) && is_array($input['attendance_teachers'])) {
            foreach ($input['attendance_teachers'] as $a) {
                $localId = to_int_or_null($a['id'] ?? null);
                try {
                    $teacher_id = to_int_or_null($a['teacher_id'] ?? null);
                    if (!$teacher_id) continue;
                    
                    $date = normalize_date($a['date'] ?? '');
                    if (empty($date)) continue;

                    $status = $a['status'] ?? 'P';
                    $late = to_int_or_null($a['late_minutes'] ?? null) ?: 0;
                    $remarks = $a['remarks'] ?? '';

                    $stmt = $pdo->prepare("INSERT INTO attendance_teachers (teacher_id, date, status, late_minutes, remarks) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status), late_minutes = VALUES(late_minutes), remarks = VALUES(remarks)");
                    $stmt->execute([$teacher_id, $date, $status, $late, $remarks]);
                    if ($localId !== null) $syncedIds['attendance_teachers'][] = $localId;
                } catch (Exception $e) {
                    write_log("SQL Error in Attendance Teachers Sync: " . $e->getMessage());
                    $syncErrors[] = $e->getMessage();
                }
            }
        }
        
        // Attendance
        if (!empty($input['attendance_entries']) && is_array($input['attendance_entries'])) {
            foreach ($input['attendance_entries'] as $a) {
                $localId = to_int_or_null($a['id'] ?? null);
                try {
                    $date = normalize_date($a['date'] ?? ''); $studentId = $a['student_id'] ?? null;
                    if (!$date || !$studentId) continue;

                    // Permission check
                    if ($userRole !== 'admin' && $userRole !== 'nazim') {
                        $aClsStmt = $pdo->prepare("SELECT class FROM students WHERE id = ? LIMIT 1");
                        $aClsStmt->execute([$studentId]);
                        $aCls = $aClsStmt->fetchColumn();
                        if ($aCls && !in_array($aCls, $allowed_class_names_up)) {
                            write_log("Sync Up: attendance_entries - user not allowed for class $aCls");
                            if ($localId !== null) $syncedIds['attendance_entries'][] = $localId;
                            continue;
                        }
                    }

                    $shift = normalize_shift($a['shift'] ?? '1');
                    $statusCol = "status_{$shift}"; $lateCol = "late_{$shift}";
                    $check = $pdo->prepare("SELECT id FROM attendance WHERE date = ? AND student_id = ?");
                    $check->execute([$date, $studentId]);
                    if ($check->rowCount() > 0) {
                        $pdo->prepare("UPDATE attendance SET $statusCol = ?, $lateCol = ? WHERE id = ?")->execute([normalize_status_code($a['status'] ?? 'P'), (int)($a['delay_minutes'] ?? 0), $check->fetchColumn()]);
                    } else {
                        $pdo->prepare("INSERT INTO attendance (date, student_id, $statusCol, $lateCol) VALUES (?, ?, ?, ?)")->execute([$date, $studentId, normalize_status_code($a['status'] ?? 'P'), (int)($a['delay_minutes'] ?? 0)]);
                    }
                    
                    // Auto SMS Hook for Absent/Leave
                    $statusCode = normalize_status_code($a['status'] ?? 'P');
                    if ($statusCode === 'A' || $statusCode === 'L') { // A = Absent, L = Leave
                        $st = $pdo->prepare("SELECT name, contact_number FROM students WHERE id = ?");
                        $st->execute([$studentId]);
                        $s_data = $st->fetch();
                        if ($s_data && !empty($s_data['contact_number'])) {
                            $statusText = ($statusCode === 'A') ? "غیر حاضر" : "چھٹی پر";
                            $msg = "اطلاع: محترم والدین، آپ کا بچہ " . $s_data['name'] . " آج مدرسہ سے " . $statusText . " ہے۔";
                            try {
                                require_once __DIR__ . '/send_sms.php';
                                send_sms($s_data['contact_number'], $msg);
                            } catch (Throwable $e) {
                                write_log("Attendance SMS Error: " . $e->getMessage());
                            }
                        }
                    }

                    if ($localId !== null) $syncedIds['attendance_entries'][] = $localId;
                } catch (Exception $e) {
                    write_log("SQL Error in Sync (Attendance): " . $e->getMessage());
                    $syncErrors[] = $e->getMessage();
                }
            }
        }
        // Finance
        if (!empty($input['finance_entries']) && is_array($input['finance_entries'])) {
            foreach ($input['finance_entries'] as $f) {
                $localId = to_int_or_null($f['id'] ?? null);
                $serverId = to_int_or_null($f['server_id'] ?? null);
                try {
                    if ((int)($f['is_deleted'] ?? 0) === 1) {
                        $deletedServerId = $serverId;
                        if ($serverId) {
                            $pdo->prepare("UPDATE finance SET is_deleted = 1 WHERE id = ?")->execute([$serverId]);
                            finance_accounting_delete_voucher($pdo, $serverId);
                        } else {
                            $findDeleted = $pdo->prepare("SELECT id FROM finance WHERE date = ? AND type = ? AND amount = ? AND description = ? LIMIT 1");
                            $findDeleted->execute([normalize_date($f['date'] ?? ''), trim($f['type'] ?? ''), (float)($f['amount'] ?? 0), $f['description'] ?? '']);
                            $deletedServerId = to_int_or_null($findDeleted->fetchColumn());
                            if ($deletedServerId) {
                                $pdo->prepare("UPDATE finance SET is_deleted = 1 WHERE id = ?")->execute([$deletedServerId]);
                                finance_accounting_delete_voucher($pdo, $deletedServerId);
                            }
                        }
                        if ($localId !== null) {
                            $syncedIds['finance_entries'][] = $localId;
                            if ($deletedServerId) $serverIds['finance_entries'][(string)$localId] = (int)$deletedServerId;
                        }
                        continue;
                    }
                    $date = normalize_date($f['date'] ?? '');
                    $type = trim($f['type'] ?? '');
                    $amount = (float)($f['amount'] ?? 0);
                    $desc = $f['description'] ?? '';
                    $uid = to_int_or_null($f['user_id'] ?? null) ?: 1;
                    $headId = to_int_or_null($f['head_id'] ?? null);
                    if (!$headId) {
                        $headId = ensure_head_id($pdo, strtolower($type) === 'expense' ? 'expense' : 'income');
                    }
                    if ($serverId) {
                        $check = $pdo->prepare("SELECT id FROM finance WHERE id = ? LIMIT 1");
                        $check->execute([$serverId]);
                        $existingId = $check->fetchColumn();
                    } else {
                        $existingId = null;
                    }
                    $userName = trim($f['user_name'] ?? 'Admin');
                    
                    $computer_receipt_no = to_int_or_null($f['computer_receipt_no'] ?? null);
                    $savedId = null;
                    if ($existingId) {
                        $savedId = (int)$existingId;
                        if ($type === 'Income') {
                            $existingNo = $pdo->prepare("SELECT computer_receipt_no FROM finance WHERE id = ?");
                            $existingNo->execute([$existingId]);
                            $computer_receipt_no = next_user_receipt_no($pdo, $uid, $computer_receipt_no ?: $existingNo->fetchColumn(), $existingId);
                        } else {
                            $computer_receipt_no = null;
                        }
                        $pdo->prepare("UPDATE finance SET date = ?, type = ?, amount = ?, description = ?, head_id = ?, manual_receipt_no = ?, donor_name = ?, donor_address = ?, computer_receipt_no = ?, user_id = ?, user_name = ?, is_deleted = 0 WHERE id = ?")
                            ->execute([$date, $type, $amount, $desc, $headId, $f['manual_receipt_no'] ?? '', $f['donor_name'] ?? '', $f['donor_address'] ?? '', $computer_receipt_no, $uid, $userName, $existingId]);
                        finance_accounting_post($pdo, $savedId);
                    } else {
                        $computer_receipt_no = ($type === 'Income') ? next_user_receipt_no($pdo, $uid, $computer_receipt_no) : null;
                        $pdo->prepare("INSERT INTO finance (date, type, amount, description, head_id, manual_receipt_no, donor_name, donor_address, computer_receipt_no, user_id, user_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                            ->execute([$date, $type, $amount, $desc, $headId, $f['manual_receipt_no'] ?? '', $f['donor_name'] ?? '', $f['donor_address'] ?? '', $computer_receipt_no, $uid, $userName]);
                        $savedId = (int)$pdo->lastInsertId();
                        finance_accounting_post($pdo, $savedId);
                    }
                    if ($localId !== null) {
                        $syncedIds['finance_entries'][] = $localId;
                        if ($savedId) $serverIds['finance_entries'][(string)$localId] = (int)$savedId;
                    }
                } catch (Exception $e) {
                    write_log("SQL Error in Sync (Finance): " . $e->getMessage());
                    $syncErrors[] = $e->getMessage();
                }
            }
        }
        // Masjid Finance Entries
        if (!empty($input['masjid_finance_entries']) && is_array($input['masjid_finance_entries'])) {
            foreach ($input['masjid_finance_entries'] as $f) {
                $localId = to_int_or_null($f['id'] ?? null);
                $serverId = to_int_or_null($f['server_id'] ?? null);
                try {
                    if ((int)($f['is_deleted'] ?? 0) === 1) {
                        $deletedServerId = $serverId;
                        if ($serverId) {
                            $pdo->prepare("UPDATE finance SET is_deleted = 1 WHERE id = ? AND system_type = 'Masjid'")->execute([$serverId]);
                        } else {
                            $findDeleted = $pdo->prepare("SELECT id FROM finance WHERE date = ? AND type = ? AND amount = ? AND description = ? AND system_type = 'Masjid' LIMIT 1");
                            $findDeleted->execute([normalize_date($f['date'] ?? ''), trim($f['type'] ?? ''), (float)($f['amount'] ?? 0), $f['description'] ?? '']);
                            $deletedServerId = to_int_or_null($findDeleted->fetchColumn());
                            if ($deletedServerId) {
                                $pdo->prepare("UPDATE finance SET is_deleted = 1 WHERE id = ?")->execute([$deletedServerId]);
                            }
                        }
                        if ($localId !== null) {
                            $syncedIds['masjid_finance_entries'][] = $localId;
                            if ($deletedServerId) $serverIds['finance_entries'][(string)$localId] = (int)$deletedServerId;
                        }
                        continue;
                    }
                    $date = normalize_date($f['date'] ?? '');
                    $type = trim($f['type'] ?? '');
                    $amount = (float)($f['amount'] ?? 0);
                    $desc = $f['description'] ?? '';
                    $uid = to_int_or_null($f['user_id'] ?? null) ?: 1;
                    $headId = to_int_or_null($f['head_id'] ?? null);
                    if (!$headId) {
                        $headId = ensure_head_id($pdo, strtolower($type) === 'expense' ? 'expense' : 'income');
                    }
                    $computer_receipt_no = to_int_or_null($f['computer_receipt_no'] ?? null);
                    $userName = trim($f['user_name'] ?? 'Admin');
                    $savedId = null;
                    if ($serverId) {
                        $check = $pdo->prepare("SELECT id FROM finance WHERE id = ? AND system_type = 'Masjid' LIMIT 1");
                        $check->execute([$serverId]);
                        $existingId = $check->fetchColumn();
                    } else {
                        $existingId = null;
                    }
                    if ($existingId) {
                        $savedId = (int)$existingId;
                        $pdo->prepare("UPDATE finance SET date = ?, type = ?, amount = ?, description = ?, head_id = ?, manual_receipt_no = ?, donor_name = ?, donor_address = ?, computer_receipt_no = ?, user_id = ?, user_name = ?, system_type = 'Masjid', is_deleted = 0 WHERE id = ?")
                            ->execute([$date, $type, $amount, $desc, $headId, $f['manual_receipt_no'] ?? '', $f['donor_name'] ?? '', $f['donor_address'] ?? '', $computer_receipt_no, $uid, $userName, $existingId]);
                    } else {
                        $pdo->prepare("INSERT INTO finance (date, type, amount, description, head_id, manual_receipt_no, donor_name, donor_address, computer_receipt_no, user_id, user_name, system_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Masjid')")
                            ->execute([$date, $type, $amount, $desc, $headId, $f['manual_receipt_no'] ?? '', $f['donor_name'] ?? '', $f['donor_address'] ?? '', $computer_receipt_no, $uid, $userName]);
                        $savedId = (int)$pdo->lastInsertId();
                    }
                    if ($localId !== null) {
                        $syncedIds['masjid_finance_entries'][] = $localId;
                        if ($savedId) $serverIds['finance_entries'][(string)$localId] = (int)$savedId;
                    }
                } catch (Exception $e) {
                    write_log("SQL Error in Sync (Masjid Finance): " . $e->getMessage());
                    $syncErrors[] = $e->getMessage();
                }
            }
        }
        // Others
        foreach (['teacher_period_attendance', 'heads', 'complaints', 'parent_complaints', 'notices', 'leave_applications', 'fee_submissions', 'exams', 'qaida_records', 'exam_results', 'classes', 'departments', 'acc_chart_of_accounts', 'acc_vouchers', 'acc_ledger_entries', 'acc_payroll', 'acc_invoices'] as $key) {
            if (!empty($input[$key]) && is_array($input[$key])) {
                foreach ($input[$key] as $item) {
                    $localId = to_int_or_null($item['id'] ?? null);
                    try {

                        if ($key == 'teacher_period_attendance') {
                            $teacherId = to_int_or_null($item['teacher_id'] ?? null);
                            $dateVal = normalize_date($item['date'] ?? '');
                            $periodNo = to_int_or_null($item['period_no'] ?? null);
                            if (!$teacherId || !$dateVal || !$periodNo) continue;
                            $classId = to_int_or_null($item['class_id'] ?? null);
                            $className = trim((string)($item['class_name'] ?? ''));
                            if (!$classId && $className !== '') {
                                $classStmt = $pdo->prepare("SELECT id FROM classes WHERE name = ? LIMIT 1");
                                $classStmt->execute([$className]);
                                $classId = to_int_or_null($classStmt->fetchColumn());
                            }
                            if (!$classId) continue;
                            $status = $item['status'] ?? 'P';
                            $late = to_int_or_null($item['late_minutes'] ?? null) ?: 0;
                            $remarks = $item['remarks'] ?? '';
                            $check = $pdo->prepare("SELECT id FROM teacher_period_attendance WHERE teacher_id = ? AND class_id = ? AND date = ? AND period_no = ? LIMIT 1");
                            $check->execute([$teacherId, $classId, $dateVal, $periodNo]);
                            $existing = $check->fetchColumn();
                            if ($existing) {
                                $pdo->prepare("UPDATE teacher_period_attendance SET status = ?, late_minutes = ?, remarks = ? WHERE id = ?")
                                    ->execute([$status, $late, $remarks, $existing]);
                            } else {
                                $pdo->prepare("INSERT INTO teacher_period_attendance (teacher_id, class_id, date, period_no, status, late_minutes, remarks) VALUES (?, ?, ?, ?, ?, ?, ?)")
                                    ->execute([$teacherId, $classId, $dateVal, $periodNo, $status, $late, $remarks]);
                            }
                            // Keep daily teacher attendance summary in sync for legacy pages/payroll.
                            $daily = $pdo->prepare("SELECT id FROM attendance_teachers WHERE teacher_id = ? AND date = ? LIMIT 1");
                            $daily->execute([$teacherId, $dateVal]);
                            $dailyId = $daily->fetchColumn();
                            if ($dailyId) {
                                $pdo->prepare("UPDATE attendance_teachers SET status = ?, late_minutes = GREATEST(COALESCE(late_minutes,0), ?), remarks = ? WHERE id = ?")
                                    ->execute([$status, $late, $remarks, $dailyId]);
                            } else {
                                $pdo->prepare("INSERT INTO attendance_teachers (teacher_id, date, status, late_minutes, remarks) VALUES (?, ?, ?, ?, ?)")
                                    ->execute([$teacherId, $dateVal, $status, $late, $remarks]);
                            }
                            if ($localId !== null) $syncedIds['teacher_period_attendance'][] = $localId;
                            continue;
                        }

                        if ($key == 'heads') {
                            $name = trim($item['name'] ?? '');
                            $type = trim($item['type'] ?? '');
                            $serverId = to_int_or_null($item['id'] ?? null);
                            $isDeleted = (int)($item['is_deleted'] ?? 0);
                            if ($name === '') {
                                continue;
                            }
                            if ($serverId) {
                                $check = $pdo->prepare("SELECT id FROM heads WHERE id = ? LIMIT 1");
                                $check->execute([$serverId]);
                            } else {
                                $check = $pdo->prepare("SELECT id FROM heads WHERE name = ? AND type = ? LIMIT 1");
                                $check->execute([$name, $type]);
                            }
                            $existingId = $check->fetchColumn();
                            if ($isDeleted === 1) {
                                if ($existingId) {
                                    try { $pdo->prepare("UPDATE heads SET is_deleted = 1 WHERE id = ?")->execute([$existingId]); }
                                    catch (Exception $e) { $pdo->prepare("DELETE FROM heads WHERE id = ?")->execute([$existingId]); }
                                }
                                if ($localId !== null) $syncedIds['heads'][] = $localId;
                                continue;
                            }
                            if ($existingId) {
                                try { $pdo->prepare("UPDATE heads SET name = ?, type = ?, is_deleted = 0 WHERE id = ?")->execute([$name, $type, $existingId]); }
                                catch (Exception $e) { $pdo->prepare("UPDATE heads SET name = ?, type = ? WHERE id = ?")->execute([$name, $type, $existingId]); }
                            } else {
                                $pdo->prepare("INSERT INTO heads (name, type) VALUES (?, ?)")->execute([$name, $type]);
                            }
                            if ($localId !== null) $syncedIds['heads'][] = $localId;
                            continue;
                        }

                        if ($key == 'departments') {
                            $name = trim($item['name'] ?? '');
                            if ($name === '') {
                                continue;
                            }
                            $check = $pdo->prepare("SELECT id FROM departments WHERE name = ? LIMIT 1");
                            $check->execute([$name]);
                            if ($check->rowCount() > 0) {
                                $existing = $check->fetch();
                                $pdo->prepare("UPDATE departments SET description = ?, status = ? WHERE id = ?")
                                    ->execute([$item['description'] ?? '', $item['status'] ?? 'Active', $existing['id']]);
                            } else {
                                $pdo->prepare("INSERT INTO departments (name, description, status) VALUES (?, ?, ?)")
                                    ->execute([$name, $item['description'] ?? '', $item['status'] ?? 'Active']);
                            }
                            if ($localId !== null) $syncedIds['departments'][] = $localId;
                            continue;
                        }

                        if ($key == 'acc_chart_of_accounts') {
                            $code = trim($item['code'] ?? '');
                            $name = trim($item['name'] ?? '');
                            $serverId = to_int_or_null($item['id'] ?? null);
                            $isDeleted = (int)($item['is_deleted'] ?? 0);
                            
                            $existing = false;
                            if ($serverId) {
                                $check = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE id = ? LIMIT 1");
                                $check->execute([$serverId]);
                                $existing = $check->fetch();
                            } elseif ($code !== '') {
                                $check = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE code = ? LIMIT 1");
                                $check->execute([$code]);
                                $existing = $check->fetch();
                            }
                            
                            if ($isDeleted === 1) {
                                if ($existing) {
                                    $pdo->prepare("UPDATE acc_chart_of_accounts SET is_deleted = 1 WHERE id = ?")
                                        ->execute([$existing['id']]);
                                }
                                if ($localId !== null) $syncedIds['acc_chart_of_accounts'][] = $localId;
                                continue;
                            }
                            
                            if ($name === '') {
                                continue;
                            }
                            
                            if ($existing) {
                                $pdo->prepare("UPDATE acc_chart_of_accounts SET name = ?, type = ?, code = ?, is_deleted = 0 WHERE id = ?")
                                    ->execute([$name, $item['type'] ?? '', $code, $existing['id']]);
                            } else {
                                if ($code === '') $code = 'ACC-' . time() . rand(10,99);
                                $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, is_deleted) VALUES (?, ?, ?, 0)")
                                    ->execute([$code, $name, $item['type'] ?? '']);
                            }
                            if ($localId !== null) $syncedIds['acc_chart_of_accounts'][] = $localId;
                            continue;
                        }

                        if ($key == 'acc_vouchers') {
                            $voucherNo = trim($item['voucher_no'] ?? '');
                            if ($voucherNo === '') {
                                continue;
                            }
                            $check = $pdo->prepare("SELECT id FROM acc_vouchers WHERE voucher_no = ? LIMIT 1");
                            $check->execute([$voucherNo]);
                            $item_is_deleted = (int)($item['is_deleted'] ?? 0);
                            if ($item_is_deleted == 1) {
                                if ($check->rowCount() > 0) {
                                    $existing = $check->fetch();
                                    $pdo->prepare("DELETE FROM acc_vouchers WHERE id = ?")->execute([$existing['id']]);
                                }
                                if ($localId !== null) $syncedIds['acc_vouchers'][] = $localId;
                                continue;
                            }
                            
                            $payload = [
                                normalize_date($item['date'] ?? ''),
                                $item['type'] ?? 'Journal',
                                $item['reference'] ?? '',
                                $item['description'] ?? '',
                                to_int_or_null($item['department_id'] ?? null),
                                $item['status'] ?? 'Pending',
                                to_int_or_null($item['created_by'] ?? null) ?: $user['id'],
                            ];
                            if ($check->rowCount() > 0) {
                                $existing = $check->fetch();
                                // DO NOT overwrite status if it was approved/rejected on server unless we really want to?
                                // Let's just update all
                                $pdo->prepare("UPDATE acc_vouchers SET date = ?, type = ?, reference = ?, description = ?, department_id = ?, status = ?, created_by = ? WHERE id = ?")
                                    ->execute([...$payload, $existing['id']]);
                            } else {
                                $pdo->prepare("INSERT INTO acc_vouchers (voucher_no, date, type, reference, description, department_id, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                                    ->execute([$voucherNo, ...$payload]);
                            }
                            if ($localId !== null) $syncedIds['acc_vouchers'][] = $localId;
                            continue;
                        }

                        if ($key == 'acc_ledger_entries') {
                            $voucherId = to_int_or_null($item['voucher_id'] ?? null);
                            $voucherNo = trim($item['voucher_no'] ?? '');
                            if ($voucherNo !== '') {
                                $voucherStmt = $pdo->prepare("SELECT id FROM acc_vouchers WHERE voucher_no = ? LIMIT 1");
                                $voucherStmt->execute([$voucherNo]);
                                $serverVoucherId = $voucherStmt->fetchColumn();
                                if ($serverVoucherId) {
                                    $voucherId = (int)$serverVoucherId;
                                }
                            }
                            $accountId = to_int_or_null($item['account_id'] ?? null);
                            $accountCode = trim($item['account_code'] ?? '');
                            if ($accountCode !== '') {
                                $accountStmt = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE code = ? LIMIT 1");
                                $accountStmt->execute([$accountCode]);
                                $serverAccountId = $accountStmt->fetchColumn();
                                if ($serverAccountId) {
                                    $accountId = (int)$serverAccountId;
                                }
                            }
                            if (!$voucherId || !$accountId) {
                                continue;
                            }
                            $debit = (float)($item['debit'] ?? 0);
                            $credit = (float)($item['credit'] ?? 0);
                            $check = $pdo->prepare("SELECT id FROM acc_ledger_entries WHERE voucher_id = ? AND account_id = ? AND debit = ? AND credit = ? LIMIT 1");
                            $check->execute([$voucherId, $accountId, $debit, $credit]);
                            if ($check->rowCount() > 0) {
                                $existing = $check->fetch();
                                $pdo->prepare("UPDATE acc_ledger_entries SET department_id = ? WHERE id = ?")
                                    ->execute([to_int_or_null($item['department_id'] ?? null), $existing['id']]);
                            } else {
                                $pdo->prepare("INSERT INTO acc_ledger_entries (voucher_id, account_id, debit, credit, department_id) VALUES (?, ?, ?, ?, ?)")
                                    ->execute([$voucherId, $accountId, $debit, $credit, to_int_or_null($item['department_id'] ?? null)]);
                            }
                            if ($localId !== null) $syncedIds['acc_ledger_entries'][] = $localId;
                            continue;
                        }

                        if ($key == 'acc_payroll') {
                            $check = $pdo->prepare("SELECT id FROM acc_payroll WHERE teacher_id = ? AND month_year = ?");
                            $check->execute([$item['teacher_id'], $item['month_year']]);
                            if ($check->rowCount() > 0) {
                                $existing = $check->fetch();
                                $pdo->prepare("UPDATE acc_payroll SET basic_salary = ?, allowances = ?, deductions = ?, status = ?, payment_date = ? WHERE id = ?")->execute([$item['basic_salary'] ?? 0, $item['allowances'] ?? 0, $item['deductions'] ?? 0, $item['status'] ?? 'Unpaid', $item['payment_date'] ?? null, $existing['id']]);
                                payroll_accounting_post($pdo, (int)$existing['id']);
                                $syncedIds['acc_payroll'][] = $item['id'];
                            } else {
                                $pdo->prepare("INSERT INTO acc_payroll (teacher_id, month_year, basic_salary, allowances, deductions, status, payment_date) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([$item['teacher_id'], $item['month_year'], $item['basic_salary'] ?? 0, $item['allowances'] ?? 0, $item['deductions'] ?? 0, $item['status'] ?? 'Unpaid', $item['payment_date'] ?? null]);
                                payroll_accounting_post($pdo, (int)$pdo->lastInsertId());
                                $syncedIds['acc_payroll'][] = $item['id'];
                            }
                        }
                        if ($key == 'acc_invoices') {
                            $check = $pdo->prepare("SELECT id FROM acc_invoices WHERE student_id = ? AND month_year = ?");
                            $check->execute([$item['student_id'], $item['month_year']]);
                            if ($check->rowCount() > 0) {
                                $existing = $check->fetch();
                                $pdo->prepare("UPDATE acc_invoices SET amount = ?, status = ? WHERE id = ?")->execute([$item['amount'] ?? 0, $item['status'] ?? 'Unpaid', $existing['id']]);
                                $syncedIds['acc_invoices'][] = $item['id'];
                            } else {
                                $pdo->prepare("INSERT INTO acc_invoices (student_id, invoice_no, month_year, amount, status) VALUES (?, ?, ?, ?, ?)")->execute([$item['student_id'], $item['invoice_no'] ?? '', $item['month_year'], $item['amount'] ?? 0, $item['status'] ?? 'Unpaid']);
                                $syncedIds['acc_invoices'][] = $item['id'];
                            }
                        }

                        if ($key == 'complaints') {
                            $check = $pdo->prepare("SELECT id FROM complaints WHERE student_id = ? AND date = ? AND complaint = ?");
                            $check->execute([$item['student_id'], normalize_date($item['date']), $item['complaint']]);
                            if ($check->rowCount() == 0) {
                                $pdo->prepare("INSERT INTO complaints (student_id, date, complaint) VALUES (?, ?, ?)")->execute([$item['student_id'], normalize_date($item['date']), $item['complaint']]);
                            }
                        }
                        if ($key == 'parent_complaints') {
                            $ev = $uploadImage($item['evidence'] ?? '', 'complaint');
                            if ((int)($item['is_deleted'] ?? 0) === 1) {
                                $serverId = to_int_or_null($item['id'] ?? null);
                                if ($serverId) {
                                    $pdo->prepare("DELETE FROM parent_complaints WHERE id = ?")->execute([$serverId]);
                                }
                                if ($localId !== null) $syncedIds['parent_complaints'][] = $localId;
                                continue;
                            }
                            $check = $pdo->prepare("SELECT id FROM parent_complaints WHERE student_id = ? AND date = ? AND description = ?");
                            $check->execute([$item['student_id'], normalize_date($item['date']), $item['description']]);
                            $existing = $check->fetch();
                            if (!$existing) {
                                $pdo->prepare("INSERT INTO parent_complaints (student_id, date, category, description, evidence, status) VALUES (?, ?, ?, ?, ?, ?)")->execute([$item['student_id'], normalize_date($item['date']), $item['category'] ?? '', $item['description'], $ev, $item['status'] ?? 'Pending']);
                            } else {
                                // Update status if already exists
                                $pdo->prepare("UPDATE parent_complaints SET status = ? WHERE id = ?")->execute([$item['status'] ?? 'Pending', $existing['id']]);
                            }
                        }
                        if ($key == 'classes') {
                            $serverId = to_int_or_null($item['id'] ?? null);
                            if ((int)($item['is_deleted'] ?? 0) === 1) {
                                if ($serverId) {
                                    $pdo->prepare("DELETE FROM classes WHERE id = ?")->execute([$serverId]);
                                }
                                if ($localId !== null) $syncedIds['classes'][] = $localId;
                                continue;
                            }
                            $check = $serverId
                                ? $pdo->prepare("SELECT id FROM classes WHERE id = ?")
                                : $pdo->prepare("SELECT id FROM classes WHERE name = ? AND COALESCE(section, '') = ?");
                            $check->execute($serverId ? [$serverId] : [$item['name'], $item['section'] ?? '']);
                            if ($check->rowCount() > 0) {
                                $pdo->prepare("UPDATE classes SET name=?, section=?, status=?, class_type=?, main_teacher_ids=?, p1_name=?, p2_name=?, p3_name=?, p4_name=?, p5_name=?, p6_name=?, subjects_list=? WHERE id=?")
                                    ->execute([$item['name'], $item['section'] ?? '', $item['status'] ?? 'Active', $item['class_type'] ?? 'Regular', $item['main_teacher_ids'] ?? '[]', $item['p1_name'] ?? 'Period 1', $item['p2_name'] ?? 'Period 2', $item['p3_name'] ?? 'Period 3', $item['p4_name'] ?? 'Period 4', $item['p5_name'] ?? 'Period 5', $item['p6_name'] ?? 'Period 6', $item['subjects_list'] ?? null, $check->fetchColumn()]);
                            } else {
                                $pdo->prepare("INSERT INTO classes (name, section, status, class_type, main_teacher_ids, p1_name, p2_name, p3_name, p4_name, p5_name, p6_name, subjects_list) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                                    ->execute([$item['name'], $item['section'] ?? '', $item['status'] ?? 'Active', $item['class_type'] ?? 'Regular', $item['main_teacher_ids'] ?? '[]', $item['p1_name'] ?? 'Period 1', $item['p2_name'] ?? 'Period 2', $item['p3_name'] ?? 'Period 3', $item['p4_name'] ?? 'Period 4', $item['p5_name'] ?? 'Period 5', $item['p6_name'] ?? 'Period 6', $item['subjects_list'] ?? null]);
                            }
                        }
                        if ($key == 'notices') {
                            $serverId = to_int_or_null($item['server_id'] ?? null);
                            $clientId = to_int_or_null($item['id'] ?? null);
                            $noticeDate = normalize_date($item['date'] ?? null);
                            if ((int)($item['is_deleted'] ?? 0) === 1) {
                                if (!$serverId && $clientId) {
                                    $serverId = $clientId;
                                }
                                if ($serverId) {
                                    $pdo->prepare("UPDATE notices SET is_deleted = 1 WHERE id = ?")->execute([$serverId]);
                                } else {
                                    $pdo->prepare("UPDATE notices SET is_deleted = 1 WHERE title = ? AND date = ?")
                                        ->execute([$item['title'] ?? '', $noticeDate]);
                                }
                                if ($localId !== null) $syncedIds['notices'][] = $localId;
                                continue;
                            }
                            if ($serverId) {
                                $pdo->prepare("UPDATE notices SET title = ?, message = ?, date = ?, is_deleted = 0 WHERE id = ?")
                                    ->execute([$item['title'] ?? '', $item['message'] ?? '', $noticeDate, $serverId]);
                                $savedNoticeId = $serverId;
                            } else {
                                $check = $pdo->prepare("SELECT id FROM notices WHERE title = ? AND date = ? LIMIT 1");
                                $check->execute([$item['title'] ?? '', $noticeDate]);
                                $existingId = to_int_or_null($check->fetchColumn());
                                if ($existingId) {
                                    $pdo->prepare("UPDATE notices SET message = ?, is_deleted = 0 WHERE id = ?")
                                        ->execute([$item['message'] ?? '', $existingId]);
                                    $savedNoticeId = $existingId;
                                } else {
                                    $pdo->prepare("INSERT INTO notices (title, message, date) VALUES (?, ?, ?)")
                                        ->execute([$item['title'] ?? '', $item['message'] ?? '', $noticeDate]);
                                    $savedNoticeId = (int)$pdo->lastInsertId();
                                }
                            }
                            if ($localId !== null && !empty($savedNoticeId)) {
                                $serverIds['notices'][(string)$localId] = (int)$savedNoticeId;
                            }
                        }
                        if ($key == 'leave_applications') {
                            $photo = $uploadImage($item['application_photo'] ?? '', 'leave');
                            if ((int)($item['is_deleted'] ?? 0) === 1) {
                                $serverId = to_int_or_null($item['id'] ?? null);
                                if ($serverId) {
                                    $pdo->prepare("DELETE FROM leave_applications WHERE id = ?")->execute([$serverId]);
                                }
                                if ($localId !== null) $syncedIds['leave_applications'][] = $localId;
                                continue;
                            }
                            $serverId = to_int_or_null($item['id'] ?? null);
                            if ($serverId) {
                                $check = $pdo->prepare("SELECT id FROM leave_applications WHERE id = ? LIMIT 1");
                                $check->execute([$serverId]);
                            } else {
                                $check = $pdo->prepare("SELECT id FROM leave_applications WHERE student_id = ? AND date_from = ? AND date_to = ? AND reason = ? LIMIT 1");
                                $check->execute([$item['student_id'], $item['date_from'], $item['date_to'], $item['reason'] ?? '']);
                            }
                            $existingId = $check->fetchColumn();
                            if ($existingId) {
                                $pdo->prepare("UPDATE leave_applications SET date_from = ?, date_to = ?, reason = ?, application_photo = COALESCE(NULLIF(?, ''), application_photo), status = ? WHERE id = ?")
                                    ->execute([$item['date_from'], $item['date_to'], $item['reason'], $photo, $item['status'] ?? 'Pending', $existingId]);
                            } else {
                                $pdo->prepare("INSERT INTO leave_applications (student_id, date_from, date_to, reason, application_photo, status) VALUES (?, ?, ?, ?, ?, ?)")->execute([$item['student_id'], $item['date_from'], $item['date_to'], $item['reason'], $photo, $item['status'] ?? 'Pending']);
                                $existingId = $pdo->lastInsertId();
                            }
                            if ($localId !== null) $syncedIds['leave_applications'][] = $localId;
                        }
                        if ($key == 'fee_submissions') {
                            $shot = $uploadImage($item['screenshot'] ?? '', 'fee');
                            if ((int)($item['is_deleted'] ?? 0) === 1) {
                                $serverId = to_int_or_null($item['id'] ?? null);
                                if ($serverId) {
                                    $pdo->prepare("DELETE FROM fee_submissions WHERE id = ?")->execute([$serverId]);
                                }
                                if ($localId !== null) $syncedIds['fee_submissions'][] = $localId;
                                continue;
                            }
                            $serverId = to_int_or_null($item['id'] ?? null);
                            if ($serverId) {
                                $check = $pdo->prepare("SELECT id, finance_id FROM fee_submissions WHERE id = ? LIMIT 1");
                                $check->execute([$serverId]);
                            } else {
                                $check = $pdo->prepare("SELECT id, finance_id FROM fee_submissions WHERE student_id = ? AND amount = ? AND submission_date = ? LIMIT 1");
                                $check->execute([$item['student_id'], $item['amount'], $item['submission_date']]);
                            }
                            $existing = $check->fetch(PDO::FETCH_ASSOC);
                            $financeId = to_int_or_null($existing['finance_id'] ?? null);
                            $status = $item['status'] ?? 'Pending';

                            if ($status === 'Approved' && !$financeId) {
                                $studentStmt = $pdo->prepare("SELECT name FROM students WHERE id = ? LIMIT 1");
                                $studentStmt->execute([$item['student_id']]);
                                $studentName = $studentStmt->fetchColumn() ?: '';
                                $desc = !empty($item['description']) ? $item['description'] . " (تعاؤن ID: " . ($existing['id'] ?? 'mobile') . ")" : "Online Cooperation/Fee";
                                
                                $adminName = 'Admin';
                                $adminId = 1;
                                if (!empty($user['id'])) {
                                    $adminId = (int)$user['id'];
                                    $adminStmt = $pdo->prepare("SELECT name FROM admins WHERE id = ? LIMIT 1");
                                    $adminStmt->execute([$adminId]);
                                    $adminName = $adminStmt->fetchColumn() ?: 'Admin';
                                }
                                $compNo = next_user_receipt_no($pdo, $adminId);

                                $stmt = $pdo->prepare("INSERT INTO finance (date, type, amount, description, donor_name, head_id, computer_receipt_no, user_id, user_name) VALUES (?, 'Income', ?, ?, ?, ?, ?, ?, ?)");
                                $stmt->execute([date('Y-m-d'), $item['amount'], $desc, $studentName, to_int_or_null($item['head_id'] ?? null), $compNo, $adminId, $adminName]);
                                $financeId = (int)$pdo->lastInsertId();
                                
                                require_once 'finance_accounting_bridge.php';
                                finance_accounting_post($pdo, $financeId);
                            }

                            if ($existing) {
                                $pdo->prepare("UPDATE fee_submissions SET amount = ?, submission_date = ?, screenshot = COALESCE(NULLIF(?, ''), screenshot), status = ?, head_id = ?, description = ?, finance_id = ? WHERE id = ?")
                                    ->execute([$item['amount'], $item['submission_date'], $shot, $status, to_int_or_null($item['head_id'] ?? null), $item['description'] ?? '', $financeId, $existing['id']]);
                            } else {
                                $pdo->prepare("INSERT INTO fee_submissions (student_id, amount, submission_date, screenshot, status, head_id, description, finance_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")->execute([$item['student_id'], $item['amount'], $item['submission_date'], $shot, $status, to_int_or_null($item['head_id'] ?? null), $item['description'] ?? '', $financeId]);
                            }
                            if ($localId !== null) $syncedIds['fee_submissions'][] = $localId;
                            
                            // Auto SMS Hook for Fee Received
                            $st = $pdo->prepare("SELECT name, contact_number FROM students WHERE id = ?");
                            $st->execute([$item['student_id']]);
                            $s_data = $st->fetch();
                            if ($status === 'Approved' && $s_data && !empty($s_data['contact_number'])) {
                                $msg = "اطلاع: محترم والدین، آپ کے بچے " . $s_data['name'] . " کی فیس مبلغ " . $item['amount'] . " روپے موصول ہو گئی ہے۔ شکریہ۔";
                                try {
                                    require_once __DIR__ . '/send_sms.php';
                                    send_sms($s_data['contact_number'], $msg);
                                } catch (Throwable $e) {
                                    write_log("Fee SMS Error: " . $e->getMessage());
                                }
                            }
                        }
                        if ($key == 'exams') {
                            $check = $pdo->prepare("SELECT id FROM exams WHERE exam_name = ? AND exam_date = ?");
                            $check->execute([$item['exam_name'], normalize_date($item['exam_date'])]);
                            if ($check->rowCount() > 0) {
                                $existingId = $check->fetchColumn();
                                $pdo->prepare("UPDATE exams SET total_marks = ?, passing_marks = ? WHERE id = ?")
                                    ->execute([(int)($item['total_marks'] ?? 100), (int)($item['passing_marks'] ?? 33), $existingId]);
                            } else {
                                $pdo->prepare("INSERT INTO exams (exam_name, exam_date, total_marks, passing_marks) VALUES (?, ?, ?, ?)")
                                    ->execute([$item['exam_name'], normalize_date($item['exam_date']), (int)($item['total_marks'] ?? 100), (int)($item['passing_marks'] ?? 33)]);
                            }
                        }
                        if ($key == 'exam_results') {
                            // Permission check for exam_results
                            if ($userRole !== 'admin' && $userRole !== 'nazim') {
                                $erStudentId = $item['student_id'] ?? null;
                                if ($erStudentId) {
                                    $erClsStmt = $pdo->prepare("SELECT class FROM students WHERE id = ? LIMIT 1");
                                    $erClsStmt->execute([$erStudentId]);
                                    $erCls = $erClsStmt->fetchColumn();
                                    if ($erCls && !in_array($erCls, $allowed_class_names_up)) {
                                        write_log("Sync Up: exam_results - user not allowed for class $erCls");
                                        continue;
                                    }
                                }
                            }
                            $check = $pdo->prepare("SELECT id FROM exam_results WHERE exam_id = ? AND student_id = ? AND subject = ?");
                            $check->execute([$item['exam_id'], $item['student_id'], $item['subject'] ?? 'Quran']);
                            if ($check->rowCount() > 0) {
                                $pdo->prepare("UPDATE exam_results SET marks_obtained=?, total_marks=?, grade=?, status=?, remarks=?, pukhtagi=?, tajweed=?, lahja=?, masail=?, miqdar_khwandgi=?, p1=?, p2=?, p3=?, p4=?, p5=?, p6=?, subjects_marks=? WHERE id=?")
                                    ->execute([$item['marks_obtained'] ?? 0, $item['total_marks'] ?? 100, $item['grade'] ?? '', $item['status'] ?? 'Pass', $item['remarks'] ?? '', $item['pukhtagi'] ?? 0, $item['tajweed'] ?? 0, $item['lahja'] ?? 0, $item['masail'] ?? 0, $item['miqdar_khwandgi'] ?? '', $item['p1'] ?? 0, $item['p2'] ?? 0, $item['p3'] ?? 0, $item['p4'] ?? 0, $item['p5'] ?? 0, $item['p6'] ?? 0, $item['subjects_marks'] ?? null, $check->fetchColumn()]);
                            } else {
                                $pdo->prepare("INSERT INTO exam_results (exam_id, student_id, subject, marks_obtained, total_marks, grade, status, remarks, pukhtagi, tajweed, lahja, masail, miqdar_khwandgi, p1, p2, p3, p4, p5, p6, subjects_marks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                                    ->execute([$item['exam_id'], $item['student_id'], $item['subject'] ?? 'Quran', $item['marks_obtained'] ?? 0, $item['total_marks'] ?? 100, $item['grade'] ?? '', $item['status'] ?? 'Pass', $item['remarks'] ?? '', $item['pukhtagi'] ?? 0, $item['tajweed'] ?? 0, $item['lahja'] ?? 0, $item['masail'] ?? 0, $item['miqdar_khwandgi'] ?? '', $item['p1'] ?? 0, $item['p2'] ?? 0, $item['p3'] ?? 0, $item['p4'] ?? 0, $item['p5'] ?? 0, $item['p6'] ?? 0, $item['subjects_marks'] ?? null]);
                            }
                        }
                        if ($key == 'qaida_records') {
                            $isDeleted = (int)($item['is_deleted'] ?? 0);
                            $dateVal = normalize_date($item['date'] ?? '');
                            $studentId = $item['student_id'] ?? null;
                            if (!$dateVal || !$studentId) continue;

                            // Check student's class permission
                            if ($userRole !== 'admin' && $userRole !== 'nazim') {
                                $stdClsStmt = $pdo->prepare("SELECT class FROM students WHERE id = ? LIMIT 1");
                                $stdClsStmt->execute([$studentId]);
                                $stdCls = $stdClsStmt->fetchColumn();
                                if ($stdCls && !in_array($stdCls, $allowed_class_names_up)) {
                                    write_log("Sync Up: qaida_records - user not allowed for class $stdCls");
                                    if ($localId !== null) $syncedIds[$key][] = $localId;
                                    continue;
                                }
                            }
                            
                            $check = $pdo->prepare("SELECT id FROM qaida_records WHERE student_id = ? AND date = ? LIMIT 1");
                            $check->execute([$studentId, $dateVal]);
                            $existingId = $check->fetchColumn();
                            
                            if ($isDeleted === 1) {
                                if ($existingId) {
                                    $pdo->prepare("UPDATE qaida_records SET is_deleted = 1 WHERE id = ?")->execute([$existingId]);
                                }
                            } else {
                                $dataArgs = [
                                    $item['sabaq_1'] ?? '', $item['grade_1'] ?? '', $item['qty_1'] ?? '',
                                    $item['sabaq_2'] ?? '', $item['grade_2'] ?? '', $item['qty_2'] ?? '',
                                    $item['sabaq_3'] ?? '', $item['grade_3'] ?? '', $item['qty_3'] ?? '',
                                    $item['sabaq_4'] ?? '', $item['grade_4'] ?? '', $item['qty_4'] ?? '',
                                    $item['lesson_type'] ?? 'نورانی قاعدہ', $item['hearing_time'] ?? ''
                                ];
                                if ($existingId) {
                                    $pdo->prepare("UPDATE qaida_records SET sabaq_1=?, grade_1=?, qty_1=?, sabaq_2=?, grade_2=?, qty_2=?, sabaq_3=?, grade_3=?, qty_3=?, sabaq_4=?, grade_4=?, qty_4=?, lesson_type=?, hearing_time=?, is_deleted=0 WHERE id=?")
                                        ->execute([...$dataArgs, $existingId]);
                                } else {
                                    $pdo->prepare("INSERT INTO qaida_records (student_id, date, sabaq_1, grade_1, qty_1, sabaq_2, grade_2, qty_2, sabaq_3, grade_3, qty_3, sabaq_4, grade_4, qty_4, lesson_type, hearing_time, is_deleted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)")
                                        ->execute([$studentId, $dateVal, ...$dataArgs]);
                                }
                            }
                        }
                        if ($localId !== null) $syncedIds[$key][] = $localId;
                    } catch (Exception $e) { $syncErrors[] = "$key: " . $e->getMessage(); }
                }
            }
        }
        $response = ['status' => empty($syncErrors) ? 'success' : 'partial_success', 'synced_ids' => $syncedIds, 'server_ids' => $serverIds, 'errors' => $syncErrors];
    } elseif ($action == 'reset_password') {
        $response = ['status' => 'error', 'message' => 'Password reset requires admin approval. Please contact the madrasa office.'];
    } elseif ($action == 'change_password') {
        $user = require_user();
        $input = json_decode(file_get_contents("php://input"), true) ?: $_POST;
        $old_pass = (string)($input['old_password'] ?? '');
        $new_pass = $input['new_password'] ?? '';
        if (!$old_pass || !$new_pass) {
            $response = ['status' => 'error', 'message' => 'Missing required fields'];
        } elseif ($user['role'] === 'student' || $user['role'] === 'parent') {
            $stmt = $user['role'] === 'parent'
                ? $pdo->prepare("SELECT password FROM parents WHERE id = ? AND is_active = 1")
                : $pdo->prepare("SELECT password FROM students WHERE id = ? AND is_deleted = 0");
            $stmt->execute([$user['id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && verify_pass($old_pass, $row['password'])) {
                $hashed = hash_pass($new_pass);
                if ($user['role'] === 'parent') {
                    $pdo->prepare("UPDATE parents SET password = ? WHERE id = ?")->execute([$hashed, $user['id']]);
                } else {
                    $pdo->prepare("UPDATE students SET password = ? WHERE id = ?")->execute([$hashed, $user['id']]);
                }
                $response = ['status' => 'success', 'message' => 'Password updated'];
            } else {
                $response = ['status' => 'error', 'message' => 'Old password is incorrect'];
            }
        } else {
            $stmt = $pdo->prepare("SELECT password FROM admins WHERE id = ? LIMIT 1");
            $stmt->execute([$user['id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && verify_pass($old_pass, $row['password'])) {
                $hashed = hash_pass($new_pass);
                $pdo->prepare("UPDATE admins SET password = ? WHERE id = ?")->execute([$hashed, $user['id']]);
                $response = ['status' => 'success', 'message' => 'Password updated'];
            } else {
                $response = ['status' => 'error', 'message' => 'Old password is incorrect'];
            }
        }
    } elseif ($action == 'update_settings') {
        require_user('admin');
        $input = json_decode(file_get_contents("php://input"), true) ?: [];
        $fields = ['madrasa_name', 'phone', 'current_hijri_year', 'week_start_day', 'ai_provider', 'gemini_api_key', 'groq_api_key', 'whatsapp_api_key', 'sms_api_key', 'auto_notify_day', 'auto_notify_time'];
        $up = []; $val = [];
        foreach ($fields as $f) { if (isset($input[$f])) { $up[] = "$f = ?"; $val[] = $input[$f]; } }
        if ($up) { $pdo->prepare("UPDATE settings SET " . implode(", ", $up) . " WHERE id = 1")->execute($val); $response = ['status' => 'success']; }
        else $response = ['status' => 'error'];
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
        $allowedFilters = ['all', 'pending', 'approved', 'rejected'];
        if (!in_array(strtolower($filterStatus), $allowedFilters)) $filterStatus = 'all';
        
        if ($filterStatus === 'all') {
            $apps = $pdo->query("SELECT * FROM admission_applications ORDER BY created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM admission_applications WHERE LOWER(status) = ? ORDER BY created_at DESC LIMIT 100");
            $stmt->execute([strtolower($filterStatus)]);
            $apps = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $countStmt = $pdo->query("SELECT status, COUNT(*) as c FROM admission_applications GROUP BY status");
        $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
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
        $allowedStatuses = ['Approved', 'rejected'];
        if (!$app_id || !in_array($new_status, $allowedStatuses)) {
            $response = ['status' => 'error', 'message' => 'Invalid parameters'];
        } else {
            $pdo->prepare("UPDATE admission_applications SET status=?, test_date=?, test_time=?, admin_note=? WHERE id=?")
                ->execute([$new_status, $test_date ?: null, $test_time ?: null, $admin_note, $app_id]);

            $smsResult = ['success' => false, 'message' => 'SMS not required'];
            if ($new_status === 'Approved') {
                $appStmt = $pdo->prepare("SELECT * FROM admission_applications WHERE id = ? LIMIT 1");
                $appStmt->execute([$app_id]);
                $app = $appStmt->fetch(PDO::FETCH_ASSOC);
                $mobile = $app ? trim((string)($app['mobile_number'] ?? ($app['mobile_no'] ?? ''))) : '';
                if ($app && $mobile !== '') {
                    require_once __DIR__ . '/send_sms.php';
                    $smsMessage = "محترم " . ($app['student_name'] ?? '') . "، آپ کی درخواست داخلہ منظور ہو گئی ہے۔ ٹیسٹ کی تاریخ: " . ($test_date ?: '-') . " وقت: " . ($test_time ?: '-') . ($admin_note ? "۔ نوٹ: $admin_note" : "");
                    $smsResult = send_sms($mobile, $smsMessage);

                    try {
                        $hasSmsSentColumn = $pdo->query("SHOW COLUMNS FROM admission_applications LIKE 'sms_sent'")->fetch();
                        if ($hasSmsSentColumn) {
                            $pdo->prepare("UPDATE admission_applications SET sms_sent = ? WHERE id = ?")
                                ->execute([!empty($smsResult['success']) ? 1 : 0, $app_id]);
                        }
                    } catch (Throwable $e) {
                        // Older deployments may not have sms_sent yet; approval should still complete.
                    }
                } else {
                    $smsResult = ['success' => false, 'message' => 'Mobile number missing'];
                }
            }

            $response = [
                'status' => 'success',
                'message' => "Application $new_status" . ($new_status === 'Approved' ? (!empty($smsResult['success']) ? ' and SMS sent' : ' but SMS not sent: ' . ($smsResult['message'] ?? 'Unknown error')) : ''),
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
            $response = ['status' => 'success', 'message' => "User updated successfully"];
        } else { // insert
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO admins (name, username, password, role, permissions, allowed_classes) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $username, $hashed, $role, $permissions, $allowed_classes]);
            $response = ['status' => 'success', 'message' => "User added successfully"];
        }
    } elseif ($action == 'delete_user') {
        require_user('admin');
        $id = intval($_GET['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM admins WHERE id=?")->execute([$id]);
            $response = ['status' => 'success', 'message' => 'User deleted successfully'];
        } else {
            $response = ['status' => 'error', 'message' => 'Invalid ID'];
        }
    } else { $response = ['status' => 'error', 'message' => 'Invalid Action']; }
} catch (Exception $e) {
    write_log("API Error: " . $e->getMessage());
    // پروڈکشن میں اصل خرابی ظاہر نہیں کرتے
    $response = ['status' => 'error', 'message' => 'An internal server error occurred. Please try again.'];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
?>
