<?php
ob_start();
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// سیکیورٹی اور ڈیبگنگ
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/session_init.php';
if (session_status() === PHP_SESSION_NONE) {
    madrasa_safe_session_start();
}

try {
    require_once 'db.php';
} catch (Throwable $e) {
    die("<div style='font-family:sans-serif;padding:40px;text-align:center;direction:rtl;'><h2>ڈیٹابیس رابطہ کی خرابی</h2><p style='color:#c00;font-size:16px;'>" . htmlspecialchars($e->getMessage()) . " (" . basename($e->getFile()) . ":" . $e->getLine() . ")</p></div>");
}

if (isset($_GET['logout']) || isset($_GET['logged_out'])) {
    session_unset();
    session_destroy();
    if (function_exists('madrasa_clear_auth_cookies')) {
        madrasa_clear_auth_cookies();
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

// زبان کا انتخاب اور کُکی پر پائیداری
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'];
    @setcookie('app_language', $_GET['lang'], time() + (86400 * 365), '/');
}
$lang = $_SESSION['lang'] ?? ($_COOKIE['app_language'] ?? 'ur');
if (!isset($_GET['logout']) && !isset($_GET['logged_out']) && isset($_SESSION['user_id'])) {
    $dest = (($_SESSION['system_type'] ?? '') === 'Masjid') ? 'masjid_dashboard.php' : 'dashboard.php';
    if (!headers_sent()) {
        header("Location: $dest");
    }
    echo "<script>window.location.replace('$dest');</script>";
    exit;
}

$error = '';

// خودکار ڈیٹابیس اسکیما اصلاح برائے فول پروف پاسورڈ و ملٹی ٹیننسی
try { $pdo->exec("ALTER TABLE admins MODIFY COLUMN password VARCHAR(255) NOT NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE teachers MODIFY COLUMN password VARCHAR(255) NOT NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE teachers ADD COLUMN username VARCHAR(100) DEFAULT NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE teachers ADD COLUMN email VARCHAR(150) DEFAULT NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE teachers ADD COLUMN is_deleted TINYINT(1) DEFAULT 0"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE students MODIFY COLUMN password VARCHAR(255) DEFAULT NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE students ADD COLUMN tenant_id INT DEFAULT 1"); } catch (Throwable $e) {}
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `parents` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `tenant_id` INT DEFAULT 1,
            `name` VARCHAR(150) NOT NULL,
            `phone` VARCHAR(50) DEFAULT NULL,
            `password` VARCHAR(255) DEFAULT NULL,
            `is_active` TINYINT(1) DEFAULT 1,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE parents MODIFY COLUMN password VARCHAR(255) DEFAULT NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE parents ADD COLUMN tenant_id INT DEFAULT 1"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE parents ADD COLUMN is_active TINYINT(1) DEFAULT 1"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE admins ADD COLUMN email VARCHAR(100) DEFAULT NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE admins ADD COLUMN phone VARCHAR(50) DEFAULT NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE admins ADD COLUMN is_deleted TINYINT(1) DEFAULT 0"); } catch (Throwable $e) {}

// کُکی سے خودکار MAIN01 کو فلٹر میں گھسنے سے روکیں تاکہ نئے ادارے لاک آؤٹ نہ ہوں
$cookieTenant = $_COOKIE['last_tenant_code'] ?? '';
if ($cookieTenant === 'MAIN01') {
    $cookieTenant = '';
}
$tenant_code = strtoupper(trim($_POST['tenant_code'] ?? ($_GET['code'] ?? ($_GET['tenant_code'] ?? ''))));

/**
 * فول پروف پاسورڈ ویریفکیشن ہیلپر (بمع خودکار مرمت برائے Truncated/MD5/Plaintext)
 */
if (!function_exists('smart_verify_login_password')) {
    function smart_verify_login_password($inputPassword, $storedHash, &$needsRehash = false) {
        $needsRehash = false;
        if (empty($storedHash)) return false;
        $storedHash = trim((string)$storedHash);

        // 1. معیاری مکمل Bcrypt/Argon2 تصدیق
        if (password_verify($inputPassword, $storedHash)) {
            return true;
        }

        // 2. اسکیما کی تنگی (VARCHAR 32/50) کی وجہ سے کٹے ہوئے (Truncated) Bcrypt ہیش کی سمارٹ مرمت
        if (preg_match('/^\$2[ayb]\$/', $storedHash) && strlen($storedHash) < 60) {
            $computed = crypt($inputPassword, $storedHash);
            if ($computed !== false && substr($computed, 0, strlen($storedHash)) === $storedHash) {
                $needsRehash = true;
                return true;
            }
        }

        // 3. MD5 ہیش میچ (Legacy MD5 Fallback)
        if (md5($inputPassword) === strtolower($storedHash) || md5($inputPassword) === $storedHash) {
            $needsRehash = true;
            return true;
        }

        // 4. SHA1 ہیش میچ
        if (sha1($inputPassword) === strtolower($storedHash) || sha1($inputPassword) === $storedHash) {
            $needsRehash = true;
            return true;
        }

        // 5. سادہ ٹیکسٹ میچ (Plaintext fallback)
        if ($inputPassword === $storedHash) {
            $needsRehash = true;
            return true;
        }

        return false;
    }
}

$adminCols = [];
try {
    $adminCols = $pdo->query("SHOW COLUMNS FROM admins")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $eCols) {
    $adminCols = ['id', 'username', 'password', 'tenant_id', 'role', 'name'];
}

$teacherCols = [];
try {
    $teacherCols = $pdo->query("SHOW COLUMNS FROM teachers")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $eTcols) {
    $teacherCols = ['id', 'name', 'phone', 'tenant_id'];
}

$studentCols = [];
try {
    $studentCols = $pdo->query("SHOW COLUMNS FROM students")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $eScols) {
    $studentCols = ['id', 'name', 'admission_number', 'phone', 'tenant_id'];
}

$parentCols = [];
try {
    $parentCols = $pdo->query("SHOW COLUMNS FROM parents")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $ePcols) {
    $parentCols = ['id', 'name', 'phone', 'password', 'tenant_id'];
}

$tenantCols = [];
try {
    $tenantCols = $pdo->query("SHOW COLUMNS FROM tenants")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $eTcols) {
    $tenantCols = ['id', 'code', 'name', 'slug', 'system_type', 'status'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $tenant_input = trim($_POST['tenant_code'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = "براہِ کرم یوزر نیم اور پاسورڈ درج کریں۔";
        } else {
            // 0. سپر ایڈمن چیک (Super Admin Account)
            try {
                $saStmt = $pdo->prepare("SELECT * FROM super_admins WHERE (LOWER(username) = LOWER(?) OR email = ?) LIMIT 1");
                $saStmt->execute([$username, $username]);
                $sa = $saStmt->fetch();
                $saRehash = false;
                if ($sa && smart_verify_login_password($password, $sa['password'], $saRehash)) {
                    if (session_status() === PHP_SESSION_NONE) { session_start(); }
                    @session_regenerate_id(true);

                    $_SESSION['user_id'] = (int)$sa['id'];
                    $_SESSION['role'] = 'superadmin';
                    $_SESSION['is_super_admin'] = true;
                    $_SESSION['super_admin_id'] = (int)$sa['id'];
                    $_SESSION['super_admin_name'] = $sa['name'] ?? 'سوپر ایڈمن';
                    $_SESSION['super_admin_user'] = $username;
                    $_SESSION['name'] = $sa['name'] ?? 'سوپر ایڈمن';
                    $_SESSION['username'] = $username;
                    $_SESSION['permissions'] = [
                        'manage_students', 'manage_teachers', 'manage_attendance', 'manage_teacher_attendance',
                        'manage_hifz', 'manage_academics', 'manage_finance', 'manage_erp',
                        'manage_classes', 'manage_notices', 'manage_exams', 'manage_requests',
                        'manage_settings', 'manage_stipends', 'manage_hostel', 'manage_library',
                        'manage_inventory', 'manage_shop', 'manage_cms', 'manage_admissions'
                    ];
                    $_SESSION['system_type'] = 'Madrasa';
                    $_SESSION['tenant_id'] = 1;
                    $_SESSION['tenant_code'] = 'MAIN01';
                    $_SESSION['tenant_name'] = 'مرکزی کلاؤڈ ساس پورٹل';
                    $_SESSION['last_activity'] = time();
                    
                    if ($saRehash) {
                        try {
                            $newSaHash = password_hash($password, PASSWORD_DEFAULT);
                            $pdo->prepare("UPDATE super_admins SET password = ? WHERE id = ?")->execute([$newSaHash, $sa['id']]);
                        } catch (Throwable $eUpSa) {}
                    }

                    if (function_exists('madrasa_generate_auth_token') && function_exists('madrasa_set_auth_cookies')) {
                        $token = madrasa_generate_auth_token($sa['id'], 1, 'superadmin', $username, $sa['password'], 'Madrasa');
                        madrasa_set_auth_cookies($token, 'MAIN01');
                    }

                    session_write_close();
                    if (!headers_sent()) {
                        header("Location: super_admin/dashboard.php");
                    }
                    echo "<!DOCTYPE html><html lang='ur' dir='rtl'><head><meta charset='UTF-8'><meta http-equiv='refresh' content='0;url=super_admin/dashboard.php'></head><body><script>window.location.replace('super_admin/dashboard.php');</script></body></html>";
                    exit;
                }
            } catch (Throwable $eSa) {}

            // 1. ایڈمنز جامع اسمارٹ تلاش (Multi-Tenant Universal Admin Discovery)
            // یوزر نیم، نام، ای میل، فون، واٹس ایپ، ادارہ کوڈ یا مدرسہ کے نام میں سے کسی سے بھی خودکار تلاش
            $admWhereParts = [];
            $adminParams = [];

            // Columns to check in admins table
            foreach (['username', 'name', 'email', 'phone'] as $ac) {
                if (in_array($ac, $adminCols)) {
                    $admWhereParts[] = "LOWER(a.`$ac`) = LOWER(?)";
                    $adminParams[] = $username;
                }
            }

            $hasATenant = in_array('tenant_id', $adminCols);

            // Columns to check in tenants table
            if ($hasATenant) {
                foreach (['name', 'email', 'phone', 'whatsapp', 'code', 'slug'] as $tc) {
                    if (in_array($tc, $tenantCols)) {
                        $admWhereParts[] = "LOWER(t.`$tc`) = LOWER(?)";
                        $adminParams[] = $username;
                    }
                }
            }

            if (empty($admWhereParts)) {
                $admWhereParts[] = "LOWER(a.username) = LOWER(?)";
                $adminParams[] = $username;
            }

            $adminWhereSql = '(' . implode(' OR ', $admWhereParts) . ')';

            if ($hasATenant) {
                $adminQuery = "
                    SELECT a.*, 
                           t.id as t_id, t.code as t_code, t.name as t_name, 
                           t.system_type as t_system_type, t.status as t_status
                    FROM admins a
                    LEFT JOIN tenants t ON a.tenant_id = t.id
                    WHERE $adminWhereSql
                ";
            } else {
                $adminQuery = "
                    SELECT a.*, 
                           1 as t_id, 'MAIN01' as t_code, 'کلاؤڈ ساس ادارہ' as t_name, 
                           'Madrasa' as t_system_type, 'active' as t_status
                    FROM admins a
                    WHERE $adminWhereSql
                ";
            }
            if (in_array('is_deleted', $adminCols)) {
                $adminQuery .= " AND (a.is_deleted = 0 OR a.is_deleted IS NULL)";
            }

            // اگر صارف نے ادارہ کوڈ دیا ہو تو اس ٹیننٹ کو فوقیت دیں
            $prefCode = (!empty($tenant_input) && strtoupper($tenant_input) !== 'MAIN01') ? strtoupper($tenant_input) : '';
            $admOrderParts = [];
            $admOrderParams = [];
            if (!empty($prefCode) && $hasATenant) {
                if (in_array('code', $tenantCols)) {
                    $admOrderParts[] = "WHEN UPPER(t.code) = UPPER(?) THEN 1";
                    $admOrderParams[] = $prefCode;
                }
                if (in_array('slug', $tenantCols)) {
                    $admOrderParts[] = "WHEN UPPER(t.slug) = UPPER(?) THEN 2";
                    $admOrderParams[] = $prefCode;
                }
                if (is_numeric($prefCode)) {
                    $admOrderParts[] = "WHEN a.tenant_id = ? THEN 3";
                    $admOrderParams[] = (int)$prefCode;
                }
            }

            if (!empty($admOrderParts)) {
                $adminQuery .= " ORDER BY CASE " . implode(' ', $admOrderParts) . " ELSE 4 END, a.id DESC LIMIT 25";
                $adminParams = array_merge($adminParams, $admOrderParams);
            } else {
                $adminQuery .= " ORDER BY a.id DESC LIMIT 25";
            }

            $candidateAdmins = [];
            try {
                $stmtAdmins = $pdo->prepare($adminQuery);
                $stmtAdmins->execute($adminParams);
                $candidateAdmins = $stmtAdmins->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $eAdmExec) {
                $candidateAdmins = [];
            }

            // تمام میچنگ ایڈمنز میں پاسورڈ کی فول پروف جانچ
            $matchedAdmin = null;
            $needsRehash = false;
            foreach ($candidateAdmins as $cand) {
                if (!empty($cand['password']) && smart_verify_login_password($password, $cand['password'], $needsRehash)) {
                    $matchedAdmin = $cand;
                    if ($needsRehash) {
                        try {
                            $newHash = password_hash($password, PASSWORD_DEFAULT);
                            $pdo->prepare("UPDATE admins SET password = ? WHERE id = ?")->execute([$newHash, $cand['id']]);
                        } catch (Throwable $eRehash) {}
                    }
                    break;
                }
            }

            if ($matchedAdmin) {
                $tId = (int)($matchedAdmin['t_id'] ?? $matchedAdmin['tenant_id'] ?? 1);
                $tCode = !empty($matchedAdmin['t_code']) ? $matchedAdmin['t_code'] : 'MAIN01';
                $tName = !empty($matchedAdmin['t_name']) ? $matchedAdmin['t_name'] : 'کلاؤڈ ساس ادارہ';
                $tSysType = !empty($matchedAdmin['t_system_type']) ? $matchedAdmin['t_system_type'] : 'Madrasa';

                // سبسکرپشن جانچ
                if ($tId > 1 && function_exists('check_tenant_subscription')) {
                    $subChk = check_tenant_subscription($pdo, $tId);
                    if (!$subChk['ok']) {
                        $error = $subChk['message'];
                    }
                }

                if (empty($error)) {
                    if (session_status() === PHP_SESSION_NONE) {
                        session_start();
                    }

                    $_SESSION['user_id'] = (int)$matchedAdmin['id'];
                    $_SESSION['role'] = !empty($matchedAdmin['role']) ? $matchedAdmin['role'] : 'admin';
                    $_SESSION['name'] = $matchedAdmin['name'];
                    $_SESSION['username'] = $matchedAdmin['username'] ?? $matchedAdmin['name'];
                    $_SESSION['permissions'] = json_decode($matchedAdmin['permissions'] ?? '[]', true) ?: [];
                    $_SESSION['system_type'] = (!empty($matchedAdmin['system_type']) && $matchedAdmin['system_type'] !== 'Both') ? $matchedAdmin['system_type'] : ($tSysType === 'Masjid' ? 'Masjid' : 'Madrasa');
                    $_SESSION['tenant_system_type'] = $tSysType;
                    $_SESSION['tenant_id'] = $tId;
                    $_SESSION['tenant_code'] = $tCode;
                    $_SESSION['tenant_name'] = $tName;
                    $_SESSION['last_activity'] = time();

                    // Set persistent dual-layer authentication token
                    if (function_exists('madrasa_generate_auth_token') && function_exists('madrasa_set_auth_cookies')) {
                        $token = madrasa_generate_auth_token($matchedAdmin['id'], $tId, $_SESSION['role'], $_SESSION['username'], $matchedAdmin['password'], $_SESSION['system_type']);
                        madrasa_set_auth_cookies($token, $tCode);
                    } else {
                        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
                        setcookie('last_tenant_code', $tCode, time() + (86400 * 30), '/', null, $isHttps, false);
                    }
                    session_write_close();

                    $dest = ($_SESSION['system_type'] === 'Masjid') ? 'masjid_dashboard.php' : 'dashboard.php';
                    if (!headers_sent()) {
                        header("Location: $dest");
                    }
                    echo "<!DOCTYPE html><html lang='ur' dir='rtl'><head><meta charset='UTF-8'><meta http-equiv='refresh' content='0;url=$dest'></head><body><script>window.location.replace('$dest');</script></body></html>";
                    exit;
                }
            }

            // 2. اساتذہ چیک کریں (Teachers table)
            if (empty($error)) {
                $hasTTenant = in_array('tenant_id', $teacherCols);
                $tWhereParts = [];
                $tParams = [];

                if (in_array('username', $teacherCols)) {
                    $tWhereParts[] = "LOWER(tc.username) = LOWER(?)";
                    $tParams[] = $username;
                }
                if (in_array('name', $teacherCols)) {
                    $tWhereParts[] = "LOWER(tc.name) = LOWER(?)";
                    $tParams[] = $username;
                }
                if (in_array('email', $teacherCols)) {
                    $tWhereParts[] = "tc.email = ?";
                    $tParams[] = $username;
                }
                if (in_array('phone', $teacherCols)) {
                    $tWhereParts[] = "tc.phone = ?";
                    $tParams[] = $username;
                }
                if ($hasTTenant && in_array('code', $tenantCols)) {
                    $tWhereParts[] = "UPPER(tn.code) = UPPER(?)";
                    $tParams[] = $username;
                }

                if (empty($tWhereParts)) {
                    $tWhereParts[] = "LOWER(tc.name) = LOWER(?)";
                    $tParams[] = $username;
                }

                $tWhereSql = '(' . implode(' OR ', $tWhereParts) . ')';
                $tDelSql = in_array('is_deleted', $teacherCols) ? "AND (tc.is_deleted = 0 OR tc.is_deleted IS NULL)" : "";

                if ($hasTTenant) {
                    $tQuery = "
                        SELECT tc.*, 
                               tn.id as t_id, tn.code as t_code, tn.name as t_name, 
                               tn.system_type as t_system_type
                        FROM teachers tc
                        LEFT JOIN tenants tn ON tc.tenant_id = tn.id
                        WHERE $tWhereSql $tDelSql
                        ORDER BY 
                            CASE 
                                WHEN ? != '' AND UPPER(tn.code) = UPPER(?) THEN 1 
                                ELSE 2 
                            END, 
                            tc.id DESC
                        LIMIT 15
                    ";
                    $tParams[] = $prefCode;
                    $tParams[] = $prefCode;
                } else {
                    $tQuery = "
                        SELECT tc.*, 
                               1 as t_id, 'MAIN01' as t_code, 'کلاؤڈ ساس ادارہ' as t_name, 
                               'Madrasa' as t_system_type
                        FROM teachers tc
                        WHERE $tWhereSql $tDelSql
                        ORDER BY tc.id DESC
                        LIMIT 15
                    ";
                }

                $candTeachers = [];
                try {
                    $stmtTeachers = $pdo->prepare($tQuery);
                    $stmtTeachers->execute($tParams);
                    $candTeachers = $stmtTeachers->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $eTcExec) {
                    $candTeachers = [];
                }

                foreach ($candTeachers as $teacher) {
                    $tRehash = false;
                    if (!empty($teacher['password']) && smart_verify_login_password($password, $teacher['password'], $tRehash)) {
                        $tId = (int)($teacher['t_id'] ?? $teacher['tenant_id'] ?? 1);
                        $tCode = !empty($teacher['t_code']) ? $teacher['t_code'] : 'MAIN01';
                        $tName = !empty($teacher['t_name']) ? $teacher['t_name'] : 'کلاؤڈ ساس ادارہ';
                        $tSysType = !empty($teacher['t_system_type']) ? $teacher['t_system_type'] : 'Madrasa';

                        if (session_status() === PHP_SESSION_NONE) { session_start(); }

                        $_SESSION['user_id'] = (int)$teacher['id'];
                        $_SESSION['role'] = 'teacher';
                        $_SESSION['name'] = $teacher['name'];
                        $_SESSION['username'] = $teacher['username'] ?? $teacher['phone'] ?? $teacher['name'];
                        $_SESSION['permissions'] = ['manage_attendance', 'manage_hifz', 'manage_academics', 'manage_teacher_attendance'];
                        $_SESSION['system_type'] = $teacher['system_type'] ?? ($tSysType === 'Masjid' ? 'Masjid' : 'Madrasa');
                        $_SESSION['tenant_id'] = $tId;
                        $_SESSION['tenant_code'] = $tCode;
                        $_SESSION['tenant_name'] = $tName;
                        $_SESSION['last_activity'] = time();

                        if ($tRehash) {
                            try {
                                $newHash = password_hash($password, PASSWORD_DEFAULT);
                                $pdo->prepare("UPDATE teachers SET password = ? WHERE id = ?")->execute([$newHash, $teacher['id']]);
                            } catch (Throwable $eUpTc) {}
                        }

                        // Set persistent dual-layer authentication token
                        if (function_exists('madrasa_generate_auth_token') && function_exists('madrasa_set_auth_cookies')) {
                            $token = madrasa_generate_auth_token($teacher['id'], $tId, 'teacher', $_SESSION['username'], $teacher['password'], $_SESSION['system_type']);
                            madrasa_set_auth_cookies($token, $tCode);
                        } else {
                            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
                            setcookie('last_tenant_code', $tCode, time() + (86400 * 30), '/', null, $isHttps, false);
                        }
                        session_write_close();

                        $dest = ($_SESSION['system_type'] === 'Masjid') ? 'masjid_dashboard.php' : 'dashboard.php';
                        if (!headers_sent()) {
                            header("Location: $dest");
                        }
                        echo "<!DOCTYPE html><html lang='ur' dir='rtl'><head><meta charset='UTF-8'><meta http-equiv='refresh' content='0;url=$dest'></head><body><script>window.location.replace('$dest');</script></body></html>";
                        exit;
                    }
                }
            }

            // 3. طلبہ چیک کریں (Students table)
            if (empty($error)) {
                $hasSTenant = in_array('tenant_id', $studentCols);
                $sWhereParts = [];
                $stdParams = [];

                if (in_array('admission_number', $studentCols)) {
                    $sWhereParts[] = "s.admission_number = ?";
                    $stdParams[] = $username;
                }
                if (in_array('roll_number', $studentCols)) {
                    $sWhereParts[] = "s.roll_number = ?";
                    $stdParams[] = $username;
                }
                if (in_array('phone', $studentCols)) {
                    $sWhereParts[] = "s.phone = ?";
                    $stdParams[] = $username;
                }
                if (in_array('contact_number', $studentCols)) {
                    $sWhereParts[] = "s.contact_number = ?";
                    $stdParams[] = $username;
                }
                if (in_array('guardian_phone', $studentCols)) {
                    $sWhereParts[] = "s.guardian_phone = ?";
                    $stdParams[] = $username;
                }
                if (in_array('name', $studentCols)) {
                    $sWhereParts[] = "LOWER(s.name) = LOWER(?)";
                    $stdParams[] = $username;
                }
                if ($hasSTenant && in_array('code', $tenantCols)) {
                    $sWhereParts[] = "UPPER(tn.code) = UPPER(?)";
                    $stdParams[] = $username;
                }

                if (empty($sWhereParts)) {
                    $sWhereParts[] = "s.id = ?";
                    $stdParams[] = (int)$username;
                }

                $sWhereSql = '(' . implode(' OR ', $sWhereParts) . ')';
                $sDelSql = in_array('is_deleted', $studentCols) ? "AND (s.is_deleted = 0 OR s.is_deleted IS NULL)" : "";

                if ($hasSTenant) {
                    $stdQuery = "
                        SELECT s.*, tn.id as t_id, tn.code as t_code, tn.name as t_name, tn.system_type as t_system_type
                        FROM students s
                        LEFT JOIN tenants tn ON s.tenant_id = tn.id
                        WHERE $sWhereSql $sDelSql
                        ORDER BY 
                            CASE 
                                WHEN ? != '' AND UPPER(tn.code) = UPPER(?) THEN 1 
                                ELSE 2 
                            END, 
                            s.id DESC
                        LIMIT 15
                    ";
                    $stdParams[] = $prefCode;
                    $stdParams[] = $prefCode;
                } else {
                    $stdQuery = "
                        SELECT s.*, 1 as t_id, 'MAIN01' as t_code, 'کلاؤڈ ساس ادارہ' as t_name, 'Madrasa' as t_system_type
                        FROM students s
                        WHERE $sWhereSql $sDelSql
                        ORDER BY s.id DESC
                        LIMIT 15
                    ";
                }

                $candStudents = [];
                try {
                    $stmtStudents = $pdo->prepare($stdQuery);
                    $stmtStudents->execute($stdParams);
                    $candStudents = $stmtStudents->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $eStdExec) {
                    $candStudents = [];
                }

                foreach ($candStudents as $student) {
                    $sRehash = false;
                    $storedPass = trim((string)($student['password'] ?? ''));
                    // اگر طالب علم کے ریکارڈ میں پاسورڈ خالی ہے تو ڈیفالٹ 123456 تسلیم کریں
                    $isPassValid = (!empty($storedPass)) 
                        ? smart_verify_login_password($password, $storedPass, $sRehash) 
                        : ($password === '123456');

                    if ($isPassValid) {
                        $tId = (int)($student['t_id'] ?? $student['tenant_id'] ?? 1);
                        $tCode = !empty($student['t_code']) ? $student['t_code'] : 'MAIN01';
                        $tName = !empty($student['t_name']) ? $student['t_name'] : 'کلاؤڈ ساس ادارہ';

                        if (session_status() === PHP_SESSION_NONE) { session_start(); }

                        $_SESSION['user_id'] = (int)$student['id'];
                        $_SESSION['role'] = 'parent';
                        $_SESSION['name'] = $student['name'];
                        $_SESSION['adm_no'] = $student['admission_number'];
                        $_SESSION['system_type'] = $student['system_type'] ?? 'Madrasa';
                        $_SESSION['tenant_id'] = $tId;
                        $_SESSION['tenant_code'] = $tCode;
                        $_SESSION['tenant_name'] = $tName;
                        $_SESSION['last_activity'] = time();

                        if ($sRehash) {
                            try {
                                $newHash = password_hash($password, PASSWORD_DEFAULT);
                                $pdo->prepare("UPDATE students SET password = ? WHERE id = ?")->execute([$newHash, $student['id']]);
                            } catch (Throwable $eUpStd) {}
                        }

                        // Set persistent dual-layer authentication token
                        if (function_exists('madrasa_generate_auth_token') && function_exists('madrasa_set_auth_cookies')) {
                            $token = madrasa_generate_auth_token($student['id'], $tId, 'parent', $student['admission_number'] ?? $student['name'], $student['password'], $_SESSION['system_type']);
                            madrasa_set_auth_cookies($token, $tCode);
                        } else {
                            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
                            setcookie('last_tenant_code', $tCode, time() + (86400 * 30), '/', '', $isHttps, false);
                        }
                        session_write_close();

                        if (!headers_sent()) {
                            header("Location: dashboard.php");
                        }
                        echo "<!DOCTYPE html><html lang='ur' dir='rtl'><head><meta charset='UTF-8'><meta http-equiv='refresh' content='0;url=dashboard.php'></head><body><script>window.location.replace('dashboard.php');</script></body></html>";
                        exit;
                    }
                }
            }

            // 4. والدین چیک کریں (Parents table - Dynamic & Fail-Safe)
            if (empty($error) && !empty($parentCols)) {
                try {
                    $hasPTenant = in_array('tenant_id', $parentCols);
                    $hasPActive = in_array('is_active', $parentCols);
                    $hasPPhone  = in_array('phone', $parentCols);
                    $hasPName   = in_array('name', $parentCols);

                    $pWhereParts = [];
                    $pParams = [];

                    if ($hasPPhone) {
                        $pWhereParts[] = "p.phone = ?";
                        $pParams[] = $username;
                    }
                    if ($hasPName) {
                        $pWhereParts[] = "LOWER(p.name) = LOWER(?)";
                        $pParams[] = $username;
                    }
                    if ($hasPTenant && in_array('code', $tenantCols)) {
                        $pWhereParts[] = "UPPER(tn.code) = UPPER(?)";
                        $pParams[] = $username;
                    }

                    if (empty($pWhereParts)) {
                        $pWhereParts[] = "p.id = ?";
                        $pParams[] = (int)$username;
                    }

                    $pWhereSql = '(' . implode(' OR ', $pWhereParts) . ')';
                    $pActiveSql = $hasPActive ? "AND (p.is_active = 1 OR p.is_active IS NULL)" : "";

                    if ($hasPTenant) {
                        $pQuery = "
                            SELECT p.*, tn.id as t_id, tn.code as t_code, tn.name as t_name
                            FROM parents p
                            LEFT JOIN tenants tn ON p.tenant_id = tn.id
                            WHERE $pWhereSql $pActiveSql
                            ORDER BY 
                                CASE 
                                    WHEN ? != '' AND UPPER(tn.code) = UPPER(?) THEN 1 
                                    ELSE 2 
                                END, 
                                p.id DESC
                            LIMIT 15
                        ";
                        $pParams[] = $prefCode;
                        $pParams[] = $prefCode;
                    } else {
                        $pQuery = "
                            SELECT p.*, 1 as t_id, 'MAIN01' as t_code, 'کلاؤڈ ساس ادارہ' as t_name
                            FROM parents p
                            WHERE $pWhereSql $pActiveSql
                            ORDER BY p.id DESC
                            LIMIT 15
                        ";
                    }

                    $stmtParents = $pdo->prepare($pQuery);
                    $stmtParents->execute($pParams);
                    $candParents = $stmtParents->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($candParents as $parent) {
                        $pRehash = false;
                        if (!empty($parent['password']) && smart_verify_login_password($password, $parent['password'], $pRehash)) {
                            $tId = (int)($parent['t_id'] ?? $parent['tenant_id'] ?? 1);
                            $tCode = !empty($parent['t_code']) ? $parent['t_code'] : 'MAIN01';
                            $tName = !empty($parent['t_name']) ? $parent['t_name'] : 'کلاؤڈ ساس ادارہ';

                            if (session_status() === PHP_SESSION_NONE) { session_start(); }

                            $_SESSION['user_id'] = (int)$parent['id'];
                            $_SESSION['role'] = 'parent';
                            $_SESSION['name'] = $parent['name'] ?? 'والد/سرپرست';
                            $_SESSION['tenant_id'] = $tId;
                            $_SESSION['tenant_code'] = $tCode;
                            $_SESSION['tenant_name'] = $tName;
                            $_SESSION['last_activity'] = time();

                            if ($pRehash) {
                                try {
                                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                                    $pdo->prepare("UPDATE parents SET password = ? WHERE id = ?")->execute([$newHash, $parent['id']]);
                                } catch (Throwable $eUpPar) {}
                            }

                            // Set persistent dual-layer authentication token
                            if (function_exists('madrasa_generate_auth_token') && function_exists('madrasa_set_auth_cookies')) {
                                $token = madrasa_generate_auth_token($parent['id'], $tId, 'parent', $parent['phone'] ?? $parent['name'], $parent['password'], 'Madrasa');
                                madrasa_set_auth_cookies($token, $tCode);
                            } else {
                                $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
                                setcookie('last_tenant_code', $tCode, time() + (86400 * 30), '/', null, $isHttps, false);
                            }
                            session_write_close();

                            if (!headers_sent()) {
                                header("Location: dashboard.php");
                            }
                            echo "<!DOCTYPE html><html lang='ur' dir='rtl'><head><meta charset='UTF-8'><meta http-equiv='refresh' content='0;url=dashboard.php'></head><body><script>window.location.replace('dashboard.php');</script></body></html>";
                            exit;
                        }
                    }
                } catch (Throwable $eParent) {
                    // Safe failover: parent login check error gracefully ignored
                }
            }

            if (empty($error)) {
                $error = "غلط یوزر نیم یا پاسورڈ درج کیا گیا ہے۔ براہِ کرم درست معلومات درج کریں۔";
            }
        }
    } catch(PDOException $e) {
        $error = "لاگ ان میں تکنیکی خرابی: " . htmlspecialchars($e->getMessage());
    } catch(Throwable $e) {
        $error = "لاگ ان میں تکنیکی خرابی: " . htmlspecialchars($e->getMessage());
    }
}

// ڈائنامک پورٹل برانڈنگ و لوگو (بائے مائنڈ کرافٹس اے آئی)
$mindcrafts_logo = "app_logo_mcmms.svg";
$portal_name = "MC-MMS (مدرسہ و مسجد مینجمنٹ سسٹم)";
$portal_sub = "بائے مائنڈ کرافٹس اے آئی (Mindcrafts AI Cloud ERP)";
$portal_logo = $mindcrafts_logo;
$is_custom_tenant = false;

if (!empty($tenant_code)) {
    try {
        $tInfo = resolve_tenant_by_code($pdo, $tenant_code);
        if ($tInfo && !empty($tInfo['name'])) {
            $portal_name = $tInfo['name'];
            $isMasjid = ($tInfo['system_type'] === 'Masjid');
            $portal_sub = $isMasjid ? 'جامع مسجد و مکتب کلاؤڈ پورٹل' : 'جامع نظامِ انتظام و تعلیم';
            $is_custom_tenant = true;
            $appSet = get_tenant_settings($pdo, $tInfo['id']);
            if (!empty($appSet['logo_path']) && $appSet['logo_path'] !== 'logo.png' && !str_contains(strtolower($appSet['logo_path']), 'ashraf') && file_exists(__DIR__ . '/' . $appSet['logo_path'])) {
                $portal_logo = $appSet['logo_path'];
            } else {
                $portal_logo = $isMasjid ? 'default_masjid_logo.svg' : 'default_madrasa_logo.svg';
            }
        }
    } catch (Throwable $e) {}
}
?>

<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" dir="<?php echo ($lang=='ur')?'rtl':'ltr'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo ($lang=='ur')?'لاگ ان':'Login'; ?> - <?php echo htmlspecialchars($portal_name); ?></title>
    
    <script>
        // Immediate theme load logic to prevent FOUC
        (function() {
            const savedTheme = localStorage.getItem('theme') || 'default';
            if (savedTheme !== 'default') {
                document.documentElement.className = 'theme-' + savedTheme;
            }
        })();
    </script>

    <?php if($lang == 'ur'): ?>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <?php else: ?>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;700&family=Plus+Jakarta+Sans:wght@500;700;800&display=swap" rel="stylesheet">
    
    <?php include __DIR__ . '/theme_styles.php'; ?>

    <style>
        body {
            font-family: <?php echo ($lang=='ur') ? "'Noto Nastaliq Urdu', Tahoma, sans-serif" : "'Plus Jakarta Sans', Roboto, sans-serif"; ?>;
            background-color: var(--bg) !important;
            color: var(--text-main) !important;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px 10px;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        .login-box {
            background: var(--card-bg) !important;
            color: var(--text-main) !important;
            padding: 35px 30px;
            border-radius: 20px;
            width: 100%;
            max-width: 440px;
            box-shadow: var(--card-shadow, 0 10px 30px rgba(0,0,0,0.15)) !important;
            border: 1px solid var(--card-border, rgba(255,255,255,0.1)) !important;
        }
        .form-control::placeholder,
        .form-control::-webkit-input-placeholder,
        .form-control::-moz-placeholder {
            color: #94a3b8 !important;
            opacity: 0.95 !important;
        }
        .text-muted {
            color: #cbd5e1 !important;
        }
        .form-control {
            background-color: var(--input-bg, #161D2B) !important;
            color: var(--text-main, #F8FAFC) !important;
            border-color: var(--input-border, rgba(255, 255, 255, 0.15)) !important;
            padding: 10px 14px;
        }
        .form-control:focus {
            border-color: var(--primary, #10b981) !important;
            box-shadow: 0 0 0 0.25rem rgba(16, 185, 129, 0.22) !important;
            color: var(--text-main, #F8FAFC) !important;
        }
        .input-group-text {
            border-color: var(--input-border, rgba(255, 255, 255, 0.15)) !important;
        }
    </style>
</head>
<body>
    <div class="login-box text-center">
        <div class="d-flex justify-content-end mb-2">
            <a href="?lang=<?php echo ($lang=='ur')?'en':'ur'; ?>" class="btn btn-outline-success btn-sm rounded-pill px-3 fw-bold" style="font-size: 11px;">
                <i class="fas fa-globe me-1"></i> <?php echo ($lang=='ur') ? 'English' : 'اردو'; ?>
            </a>
        </div>
        <div class="mb-3">
            <div style="display: inline-block; position: relative; margin-bottom: 12px;">
                <img src="<?php echo htmlspecialchars($portal_logo); ?>" alt="Mindcrafts AI Logo" style="width: 88px; height: 88px; border-radius: 22px; border: 2px solid rgba(16, 185, 129, 0.4); background: #061814; object-fit: contain; box-shadow: 0 8px 24px rgba(16, 185, 129, 0.25); padding: 4px;">
            </div>
            
            <h3 class="mb-1 text-success fw-bold urdu-font"><?php echo htmlspecialchars($portal_name); ?></h3>
            <p class="text-muted small mb-2 urdu-font"><?php echo htmlspecialchars($portal_sub); ?></p>
            <div class="badge px-3 py-1 mb-2" style="background: rgba(6, 182, 212, 0.15); color: #38bdf8; border: 1px solid rgba(6, 182, 212, 0.3); border-radius: 50px; font-size: 11px;">
                <i class="fas fa-brain me-1"></i> <?php echo ($lang=='en') ? 'By Mindcrafts AI Cloud ERP' : 'بائے مائنڈ کرافٹس اے آئی (Mindcrafts AI)'; ?>
            </div>
        </div>
        
        <?php if (isset($_GET['registered']) || (isset($_GET['msg']) && $_GET['msg'] === 'registered')): ?>
            <div class="alert alert-success p-2 small mb-3 text-start border-0 shadow-sm" style="background: rgba(16, 185, 129, 0.18); color: #10b981; border-radius: 12px;">
                <i class="fas fa-check-circle me-1"></i> <strong><?php echo ($lang=='en') ? 'Congratulations!' : 'مبارک ہو!'; ?></strong> <?php echo ($lang=='en') ? 'Your institute has been successfully registered. Please log in with your credentials.' : 'آپ کا ادارہ کامیابی سے رجسٹر ہو گیا ہے۔ براہِ کرم اپنے یوزر نیم اور پاسورڈ سے لاگ ان کریں۔'; ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['timeout'])): ?>
            <div class="alert alert-warning p-2 small mb-3 text-start border-0 shadow-sm" style="background: rgba(245, 158, 11, 0.18); color: #f59e0b; border-radius: 12px;">
                <i class="fas fa-clock me-1"></i> <?php echo ($lang=='en') ? 'Your session has expired. Please log in again.' : 'سیشن کی میعاد ختم ہو گئی ہے۔ براہِ کرم دوبارہ لاگ ان کریں۔'; ?>
            </div>
        <?php endif; ?>

        <?php if(!empty($error)): ?>
            <div class="alert alert-danger p-3 small mb-3 text-start border-0 shadow-sm" style="background: rgba(239, 68, 68, 0.22); color: #fca5a5; border-radius: 12px; font-size: 13px; line-height: 1.6;">
                <i class="fas fa-exclamation-triangle me-1"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="login.php">
            <div class="mb-3 text-start">
                <label class="form-label text-success fw-bold small"><?php echo ($lang=='en') ? 'Institute Code (Optional)' : 'ادارہ کوڈ (Institute Code) — اختیاری'; ?></label>
                <div class="input-group">
                    <span class="input-group-text border-end-0" style="background-color: var(--input-bg, #161D2B); border-color: var(--input-border, rgba(255,255,255,0.15)); color: var(--primary, #10b981);">
                        <i class="fas fa-university"></i>
                    </span>
                    <input type="text" name="tenant_code" class="form-control text-uppercase border-start-0" value="<?php echo htmlspecialchars($tenant_code); ?>" placeholder="<?php echo ($lang=='en') ? 'e.g. M-26-001 (or leave blank)' : 'مثلاً M-26-001 (یا خالی چھوڑیں)'; ?>">
                </div>
                <small class="text-muted d-block mt-1" style="font-size: 11px;">
                    <?php echo ($lang=='en') ? 'New institutes can enter their registration code, or leave blank to log in directly.' : 'نئے ادارے اپنا رجسٹریشن کوڈ درج کر سکتے ہیں، یا اسے خالی چھوڑ کر براہِ راست لاگ ان کریں۔'; ?>
                </small>
            </div>
            
            <div class="mb-3 text-start">
                <label class="form-label text-success fw-bold small"><?php echo ($lang=='en') ? 'Username / Email / Phone / Institute Code' : 'یوزر نیم / ای میل / فون نمبر / ادارہ کوڈ'; ?></label>
                <div class="input-group">
                    <span class="input-group-text border-end-0" style="background-color: var(--input-bg, #161D2B); border-color: var(--input-border, rgba(255,255,255,0.15)); color: var(--primary, #10b981);">
                        <i class="fas fa-user"></i>
                    </span>
                    <input type="text" name="username" class="form-control border-start-0" required placeholder="<?php echo ($lang=='en') ? 'Enter username, email, phone or institute code...' : 'یوزر نیم، ای میل، فون یا ادارہ کوڈ درج کریں...'; ?>" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                </div>
            </div>
            
            <div class="mb-4 text-start">
                <label class="form-label text-success fw-bold small"><?php echo ($lang=='en') ? 'Password' : 'پاسورڈ'; ?></label>
                <div class="input-group">
                    <span class="input-group-text border-end-0" style="background-color: var(--input-bg, #161D2B); border-color: var(--input-border, rgba(255,255,255,0.15)); color: var(--primary, #10b981);">
                        <i class="fas fa-lock"></i>
                    </span>
                    <input type="password" name="password" id="login_password" class="form-control border-start-0 border-end-0" required placeholder="<?php echo ($lang=='en') ? 'Enter password...' : 'پاسورڈ درج کریں...'; ?>">
                    <button class="btn" type="button" onclick="togglePassView()" style="background-color: var(--input-bg, #161D2B); border: 1px solid var(--input-border, rgba(255,255,255,0.15)); border-right: 0; color: var(--text-muted, #94a3b8);">
                        <i class="fas fa-eye" id="pass_eye_icon"></i>
                    </button>
                </div>
            </div>
            
            <button type="submit" name="login" class="btn btn-success w-100 py-2 fw-bold shadow-sm" style="border-radius: 12px; font-size: 16px;">
                <i class="fas fa-sign-in-alt me-1"></i> <?php echo ($lang=='en') ? 'Secure Login' : 'محفوظ لاگ ان کریں'; ?>
            </button>
        </form>

        <div class="mt-4 pt-3 border-top" style="border-color: rgba(255,255,255,0.1) !important;">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <a href="register.php" class="text-success text-decoration-none small fw-bold"><i class="fas fa-plus-circle me-1"></i> <?php echo ($lang=='en') ? 'Register New Institute' : 'نیا ادارہ رجسٹر کریں'; ?></a>
                <a href="forgot_password.php" class="text-danger text-decoration-none small"><?php echo ($lang=='en') ? 'Forgot Password?' : 'پاسورڈ بھول گئے؟'; ?></a>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top border-secondary border-opacity-10">
                <a href="index.php" class="small text-muted text-decoration-none"><?php echo ($lang=='en') ? '← Go to Home Page' : '← ہوم لینڈنگ پیج پر جائیں'; ?></a>
                <a href="super_admin.php" class="small text-info text-opacity-75 text-decoration-none"><i class="fas fa-shield-alt me-1"></i> <?php echo ($lang=='en') ? 'Super Admin Portal' : 'سپر ایڈمن پورٹل'; ?></a>
            </div>
            <div class="text-center mt-3 pt-2 border-top border-secondary border-opacity-10">
                <span class="badge bg-secondary bg-opacity-25 text-secondary border font-monospace" style="font-size: 11px;">v5.7.134 (SaaS Edition)</span>
            </div>
        </div>
    </div>

    <script>
    function togglePassView() {
        var p = document.getElementById('login_password');
        var icon = document.getElementById('pass_eye_icon');
        if (p.type === 'password') {
            p.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            p.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }
    </script>
</body>
</html>