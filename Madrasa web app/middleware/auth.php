<?php
/**
 * Authentication, Session & Permissions Middleware
 * 
 * Provides role checking, permission evaluation (including Masjid parity),
 * class-level data isolation, and password hashing/verification.
 */

if (!function_exists('has_permission')) {
    /**
     * Check if the user has a specific permission, dynamically handling Masjid permissions
     */
    function has_permission($role, $user_permissions, $permission, $system_type = null) {
        $role_lower = strtolower((string)$role);
        if (in_array($role_lower, ['admin', 'superadmin', 'super_admin', 'administrator'], true)) return true;
        
        if ($system_type === null && isset($_SESSION['system_type'])) {
            $system_type = $_SESSION['system_type'];
        }
        
        if ($system_type === 'Masjid') {
            if (strpos($permission, 'manage_masjid_') !== 0) {
                if ($permission === 'add_complaint') {
                    $permission = 'manage_masjid_add_complaint';
                } else {
                    $permission = str_replace('manage_', 'manage_masjid_', $permission);
                }
            }
        }
        
        if (is_string($user_permissions)) {
            $user_permissions = json_decode($user_permissions, true) ?: [];
        }

        // Default teacher permissions if user is teacher
        if ($role === 'teacher') {
            $teacherBase = [
                'manage_attendance', 'manage_hifz', 'manage_academics', 'manage_teacher_attendance',
                'manage_masjid_attendance', 'manage_masjid_hifz', 'manage_masjid_academics', 'manage_masjid_teacher_attendance'
            ];
            if (in_array($permission, $teacherBase, true)) return true;
        }

        if ($role_lower === 'nazim') {
            $nazimBase = [
                'manage_attendance', 'manage_teacher_attendance', 'manage_attendance_report',
                'manage_hifz', 'manage_hifz_daily', 'manage_hifz_report',
                'manage_academics', 'manage_academic_dashboard', 'manage_academic_entry', 'manage_academic_view',
                'manage_students', 'manage_teachers', 'manage_classes', 'manage_exams',
                'manage_finance_entry', 'manage_finance_report',
                'manage_requests', 'manage_notices', 'manage_admissions', 'manage_complaints', 'add_complaint',
                // Masjid Parity
                'manage_masjid_attendance', 'manage_masjid_teacher_attendance', 'manage_masjid_attendance_report',
                'manage_masjid_hifz', 'manage_masjid_hifz_daily', 'manage_masjid_hifz_report',
                'manage_masjid_academics', 'manage_masjid_academic_dashboard', 'manage_masjid_academic_entry', 'manage_masjid_academic_view',
                'manage_masjid_students', 'manage_masjid_teachers', 'manage_masjid_classes', 'manage_masjid_exams',
                'manage_masjid_finance_entry', 'manage_masjid_finance_report',
                'manage_masjid_requests', 'manage_masjid_notices', 'manage_masjid_admissions', 'manage_masjid_complaints', 'manage_masjid_add_complaint'
            ];
            if (in_array($permission, $nazimBase, true)) return true;
        }
        
        return in_array($permission, $user_permissions ?? [], true);
    }
}

if (!function_exists('get_allowed_classes')) {
    function get_allowed_classes($pdo, $orderBy = 'id ASC', $apiRole = null, $apiUserId = null, $apiSystemType = null) {
        $rawRole = $apiRole !== null ? $apiRole : ($_SESSION['role'] ?? '');
        $role = strtolower(trim((string)$rawRole));
        $userId = $apiUserId !== null ? (int)$apiUserId : (int)($_SESSION['user_id'] ?? 0);

        // Resolve system_type: prefer explicit param, then GET, then session, then DB lookup
        $sys = $apiSystemType ?? $_GET['system_type'] ?? $_SESSION['system_type'] ?? null;
        if (!$sys && $userId) {
            try {
                $sysStmt = $pdo->prepare("SELECT system_type FROM admins WHERE id = ? LIMIT 1");
                $sysStmt->execute([$userId]);
                $sysRow = $sysStmt->fetch(PDO::FETCH_ASSOC);
                $sys = $sysRow['system_type'] ?? 'Madrasa';
            } catch (Exception $e) { $sys = 'Madrasa'; }
        }
        if (!$sys) $sys = 'Madrasa';
        
        $sys_cond = ($sys === 'Masjid') ? "system_type = 'Masjid'" : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
        $tenantId = function_exists('get_current_tenant_id') ? (int)get_current_tenant_id() : 1;
        $tenant_cond = "tenant_id = $tenantId";

        try {
            // Admin has full access to all classes for current system_type
            if (in_array($role, ['admin', 'superadmin', 'super_admin', 'administrator'], true)) {
                try {
                    $res = $pdo->query("SELECT * FROM classes WHERE $sys_cond AND $tenant_cond AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY $orderBy")->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    $res = $pdo->query("SELECT * FROM classes WHERE $sys_cond AND $tenant_cond ORDER BY $orderBy")->fetchAll(PDO::FETCH_ASSOC);
                }
                return $res ?: [];
            }

            // Teachers: resolve classes from classes table (teacher_id, main_teacher_ids) and teachers.assigned_classes
            if ($role === 'teacher' && $userId) {
                $teacherClasses = [];
                // 1. Check classes where teacher_id matches
                try {
                    $tStmt = $pdo->prepare("SELECT * FROM classes WHERE teacher_id = ? AND $sys_cond AND $tenant_cond AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY $orderBy");
                    $tStmt->execute([$userId]);
                    $teacherClasses = $tStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Exception $e) {}

                // 2. Check classes where main_teacher_ids contains $userId
                try {
                    $mStmt = $pdo->prepare("SELECT * FROM classes WHERE (main_teacher_ids LIKE ? OR main_teacher_ids LIKE ?) AND $sys_cond AND $tenant_cond AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY $orderBy");
                    $mStmt->execute(['%' . $userId . '%', '%"' . $userId . '"%']);
                    $mClasses = $mStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    foreach ($mClasses as $mc) {
                        if (!in_array($mc['id'], array_column($teacherClasses, 'id'))) {
                            $teacherClasses[] = $mc;
                        }
                    }
                } catch (Exception $e) {}

                // 3. Check teachers table assigned_classes column
                try {
                    $tRowStmt = $pdo->prepare("SELECT assigned_classes FROM teachers WHERE id = ? AND $tenant_cond LIMIT 1");
                    $tRowStmt->execute([$userId]);
                    $tRow = $tRowStmt->fetch(PDO::FETCH_ASSOC);
                    if (!empty($tRow['assigned_classes'])) {
                        $rawAssigned = trim((string)$tRow['assigned_classes']);
                        $classNamesOrIds = array_filter(array_map('trim', explode(',', $rawAssigned)));
                        if (!empty($classNamesOrIds)) {
                            $quoted = implode(',', array_map(function($val) use ($pdo) { return $pdo->quote($val); }, $classNamesOrIds));
                            $extraStmt = $pdo->query("SELECT * FROM classes WHERE (name IN ($quoted) OR id IN ($quoted)) AND $sys_cond AND $tenant_cond AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY $orderBy");
                            if ($extraStmt) {
                                foreach ($extraStmt->fetchAll(PDO::FETCH_ASSOC) as $ec) {
                                    if (!in_array($ec['id'], array_column($teacherClasses, 'id'))) {
                                        $teacherClasses[] = $ec;
                                    }
                                }
                            }
                        }
                    }
                } catch (Exception $e) {}

                if (!empty($teacherClasses)) {
                    return $teacherClasses;
                }
            }

            // Non-admin (nazim, staff) restricted strictly by allowed_classes
            if ($userId) {
                $stmt = $pdo->prepare("SELECT allowed_classes, permissions FROM admins WHERE id = ?");
                $stmt->execute([$userId]);
                $admin_row = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($admin_row && array_key_exists('allowed_classes', $admin_row) && $admin_row['allowed_classes'] !== null) {
                    $decoded = json_decode((string)$admin_row['allowed_classes'], true);
                    if (is_array($decoded)) {
                        $allowed_ids = array_values(array_filter(array_map('intval', $decoded)));
                        if (empty($allowed_ids)) {
                            // If user is nazim, empty allowed_classes means no specific restriction (all classes allowed)
                            if ($role === 'nazim') {
                                try {
                                    $res = $pdo->query("SELECT * FROM classes WHERE $sys_cond AND $tenant_cond AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY $orderBy")->fetchAll(PDO::FETCH_ASSOC);
                                } catch (Exception $e) {
                                    $res = $pdo->query("SELECT * FROM classes WHERE $sys_cond AND $tenant_cond ORDER BY $orderBy")->fetchAll(PDO::FETCH_ASSOC);
                                }
                                return $res ?: [];
                            }
                            // Explicitly assigned empty allowed_classes -> access to zero classes
                            return [];
                        }
                        $placeholders = implode(',', array_fill(0, count($allowed_ids), '?'));
                        try {
                            $stmt = $pdo->prepare("SELECT * FROM classes WHERE id IN ($placeholders) AND $sys_cond AND $tenant_cond AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY $orderBy");
                            $stmt->execute($allowed_ids);
                            $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        } catch (Exception $e) {
                            $stmt = $pdo->prepare("SELECT * FROM classes WHERE id IN ($placeholders) AND $sys_cond AND $tenant_cond ORDER BY $orderBy");
                            $stmt->execute($allowed_ids);
                            $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        }
                        return $res ?: [];
                    }
                }
            }

            // If allowed_classes was unconfigured (NULL) in legacy record and user is nazim
            if ($role === 'nazim') {
                try {
                    $res = $pdo->query("SELECT * FROM classes WHERE $sys_cond AND $tenant_cond AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY $orderBy")->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    $res = $pdo->query("SELECT * FROM classes WHERE $sys_cond AND $tenant_cond ORDER BY $orderBy")->fetchAll(PDO::FETCH_ASSOC);
                }
                return $res ?: [];
            }

            return [];
        } catch (Exception $e) {
            return [];
        }
    }
}

if (!function_exists('get_user_allowed_class_names')) {
    function get_user_allowed_class_names($pdo, $apiRole = null, $apiUserId = null, $apiSystemType = null) {
        $classes = get_allowed_classes($pdo, 'name ASC', $apiRole, $apiUserId, $apiSystemType);
        return array_values(array_filter(array_unique(array_column($classes, 'name'))));
    }
}

if (!function_exists('get_student_class_privacy_sql')) {
    function get_student_class_privacy_sql($pdo, $classColumn = 'class', $apiRole = null, $apiUserId = null, $apiSystemType = null) {
        $rawRole = $apiRole !== null ? $apiRole : ($_SESSION['role'] ?? '');
        $role = strtolower(trim((string)$rawRole));
        if (in_array($role, ['admin', 'superadmin', 'administrator'], true)) {
            return "1=1";
        }
        $names = get_user_allowed_class_names($pdo, $apiRole, $apiUserId, $apiSystemType);
        if (empty($names)) {
            return "1=0";
        }
        $quoted = array_map(function($n) use ($pdo) { return $pdo->quote($n); }, $names);
        $inList = implode(',', $quoted);
        return "TRIM($classColumn) IN ($inList)";
    }
}

if (!function_exists('app_has_perm')) {
    function app_has_perm($perm) {
        if (!isset($_SESSION['role'])) return false;
        $uRole = strtolower(trim((string)$_SESSION['role']));
        if (in_array($uRole, ['admin', 'superadmin', 'super_admin', 'administrator'], true)) {
            return true;
        }
        $perms = $_SESSION['permissions'] ?? [];
        return has_permission($uRole, $perms, $perm);
    }
}

if (!function_exists('has_perm')) {
    function has_perm($perm) {
        return app_has_perm($perm);
    }
}

/**
 * Password Hashing Helpers
 */
if (!function_exists('hash_pass')) {
    function hash_pass($password) {
        return password_hash($password, PASSWORD_BCRYPT);
    }
}

if (!function_exists('verify_pass')) {
    function verify_pass($password, $hash) {
        if (empty($hash)) return false;
        $hash = trim($hash);
        if ($password === $hash) return true;
        if (password_verify($password, $hash)) return true;
        if (md5($password) === strtolower($hash) || md5($password) === $hash) return true;
        if (sha1($password) === strtolower($hash) || sha1($password) === $hash) return true;
        return false;
    }
}

/**
 * ════════════════════════════════════════════════════════════════════
 *  Dual-Layer Persistent Authentication (HMAC-SHA256 Cookie + Session)
 *  Provides unbreakable session self-healing across proxy/cPanel drops
 * ════════════════════════════════════════════════════════════════════
 */
if (!defined('MADRASA_AUTH_SECRET')) {
    define('MADRASA_AUTH_SECRET', 'minds_madrasa_ai_auth_key_2026_cloud_erp_secret_hmac');
}

if (!function_exists('madrasa_is_https')) {
    function madrasa_is_https() {
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') return true;
        if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) return true;
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') return true;
        if (!empty($_SERVER['HTTP_CF_VISITOR'])) {
            $cf = json_decode($_SERVER['HTTP_CF_VISITOR'], true);
            if (!empty($cf['scheme']) && $cf['scheme'] === 'https') return true;
        }
        return false;
    }
}

if (!function_exists('madrasa_ensure_session_started')) {
    function madrasa_ensure_session_started() {
        if (session_status() === PHP_SESSION_NONE) {
            $isHttps = madrasa_is_https();
            if (PHP_VERSION_ID >= 70300) {
                $params = [
                    'lifetime' => 86400 * 30,
                    'path' => '/',
                    'httponly' => true,
                    'samesite' => 'Lax'
                ];
                if ($isHttps) {
                    $params['secure'] = true;
                }
                @session_set_cookie_params($params);
            } else {
                @session_set_cookie_params(86400 * 30, '/', '', $isHttps, true);
            }
            @session_start();
        }
    }
}

if (!function_exists('madrasa_generate_auth_token')) {
    function madrasa_generate_auth_token($userId, $tenantId, $role, $username, $passwordHash, $systemType = 'Madrasa') {
        $salt = substr(hash('sha256', (string)$passwordHash), 0, 16);
        $payloadData = [
            'uid'  => (int)$userId,
            'tid'  => (int)$tenantId,
            'role' => (string)$role,
            'user' => (string)$username,
            'sys'  => (string)$systemType,
            'salt' => $salt,
            'exp'  => time() + (86400 * 30) // 30 days
        ];
        $json = json_encode($payloadData);
        $b64 = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $b64, MADRASA_AUTH_SECRET);
        return $b64 . '.' . $sig;
    }
}

if (!function_exists('madrasa_set_auth_cookies')) {
    function madrasa_set_auth_cookies($token, $tenantCode = '') {
        $isHttps = madrasa_is_https();
        $expire = time() + (86400 * 30);
        if (PHP_VERSION_ID >= 70300) {
            $cookieOpt = [
                'expires'  => $expire,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax'
            ];
            if ($isHttps) $cookieOpt['secure'] = true;
            @setcookie('madrasa_auth_token', $token, $cookieOpt);
            if (!empty($tenantCode)) {
                $tCookieOpt = [
                    'expires'  => $expire,
                    'path'     => '/',
                    'httponly' => false,
                    'samesite' => 'Lax'
                ];
                if ($isHttps) $tCookieOpt['secure'] = true;
                @setcookie('last_tenant_code', (string)$tenantCode, $tCookieOpt);
            }
        } else {
            @setcookie('madrasa_auth_token', $token, $expire, '/', '', $isHttps, true);
            if (!empty($tenantCode)) {
                @setcookie('last_tenant_code', (string)$tenantCode, $expire, '/', '', $isHttps, false);
            }
        }
        $_COOKIE['madrasa_auth_token'] = $token;
        if (!empty($tenantCode)) {
            $_COOKIE['last_tenant_code'] = (string)$tenantCode;
        }
    }
}

if (!function_exists('madrasa_clear_auth_cookies')) {
    function madrasa_clear_auth_cookies() {
        $isHttps = madrasa_is_https();
        $past = time() - 86400;
        if (PHP_VERSION_ID >= 70300) {
            $clearOpt = [
                'expires'  => $past,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax'
            ];
            if ($isHttps) $clearOpt['secure'] = true;
            @setcookie('madrasa_auth_token', '', $clearOpt);
            $clearTOpt = [
                'expires'  => $past,
                'path'     => '/',
                'httponly' => false,
                'samesite' => 'Lax'
            ];
            if ($isHttps) $clearTOpt['secure'] = true;
            @setcookie('last_tenant_code', '', $clearTOpt);
        } else {
            @setcookie('madrasa_auth_token', '', $past, '/', '', $isHttps, true);
            @setcookie('last_tenant_code', '', $past, '/', '', $isHttps, false);
        }
        unset($_COOKIE['madrasa_auth_token']);
        unset($_COOKIE['last_tenant_code']);
    }
}

if (!function_exists('madrasa_restore_session_from_cookie')) {
    function madrasa_restore_session_from_cookie($pdo) {
        if (!empty($_SESSION['user_id'])) {
            return true;
        }
        $token = $_COOKIE['madrasa_auth_token'] ?? '';
        if (empty($token) || strpos($token, '.') === false) {
            return false;
        }
        list($b64, $sig) = explode('.', $token, 2);
        $expectedSig = hash_hmac('sha256', $b64, MADRASA_AUTH_SECRET);
        if (!hash_equals($expectedSig, $sig)) {
            return false;
        }
        $remainder = strlen($b64) % 4;
        if ($remainder) {
            $b64 .= str_repeat('=', 4 - $remainder);
        }
        $json = base64_decode(strtr($b64, '-_', '+/'));
        $data = json_decode($json, true);
        if (!$data || !is_array($data)) {
            return false;
        }
        if (empty($data['exp']) || $data['exp'] < time()) {
            return false;
        }

        $uid = (int)($data['uid'] ?? 0);
        $tid = (int)($data['tid'] ?? 1);
        $role = strtolower((string)($data['role'] ?? 'admin'));
        $salt = (string)($data['salt'] ?? '');

        if ($uid <= 0) return false;

        try {
            if ($role === 'superadmin') {
                $stmt = $pdo->prepare("SELECT * FROM super_admins WHERE id = ? LIMIT 1");
                $stmt->execute([$uid]);
                $sa = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($sa && substr(hash('sha256', (string)$sa['password']), 0, 16) === $salt) {
                    $_SESSION['user_id'] = (int)$sa['id'];
                    $_SESSION['role'] = 'superadmin';
                    $_SESSION['is_super_admin'] = true;
                    $_SESSION['super_admin_id'] = (int)$sa['id'];
                    $_SESSION['name'] = $sa['name'] ?? 'سوپر ایڈمن';
                    $_SESSION['username'] = $sa['username'] ?? 'superadmin';
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
                    return true;
                }
            } elseif (in_array($role, ['admin', 'superadmin', 'super_admin', 'administrator', 'nazim', 'mudarris', 'accountant', 'staff'])) {
                $stmt = $pdo->prepare("
                    SELECT a.*, t.id as t_id, t.code as t_code, t.name as t_name, t.system_type as t_system_type
                    FROM admins a
                    LEFT JOIN tenants t ON a.tenant_id = t.id
                    WHERE a.id = ? AND (a.tenant_id = ? OR a.tenant_id IS NULL OR a.tenant_id = 0)
                    LIMIT 1
                ");
                $stmt->execute([$uid, $tid]);
                $adm = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($adm) {
                    $tCode = !empty($adm['t_code']) ? $adm['t_code'] : 'MAIN01';
                    $tName = !empty($adm['t_name']) ? $adm['t_name'] : 'کلاؤڈ ساس ادارہ';
                    $tSysType = !empty($adm['t_system_type']) ? $adm['t_system_type'] : 'Madrasa';
                    $admRole = !empty($adm['role']) ? $adm['role'] : 'admin';

                    $_SESSION['user_id'] = (int)$adm['id'];
                    $_SESSION['role'] = $admRole;
                    $_SESSION['name'] = $adm['name'];
                    $_SESSION['username'] = $adm['username'] ?? $adm['name'];
                    $perms = json_decode($adm['permissions'] ?? '[]', true) ?: [];
                    if (in_array(strtolower((string)$admRole), ['admin', 'superadmin', 'super_admin', 'administrator'], true) && empty($perms)) {
                        $perms = [
                            'manage_students', 'manage_teachers', 'manage_attendance', 'manage_teacher_attendance',
                            'manage_hifz', 'manage_academics', 'manage_finance', 'manage_erp',
                            'manage_classes', 'manage_notices', 'manage_exams', 'manage_requests',
                            'manage_settings', 'manage_stipends', 'manage_hostel', 'manage_library',
                            'manage_inventory', 'manage_shop', 'manage_cms', 'manage_admissions',
                            'manage_assets', 'manage_in_kind_donations', 'manage_loans', 'manage_website',
                            'manage_complaints', 'add_complaint'
                        ];
                    }
                    $_SESSION['permissions'] = $perms;
                    $_SESSION['system_type'] = (!empty($adm['system_type']) && $adm['system_type'] !== 'Both') ? $adm['system_type'] : ($tSysType === 'Masjid' ? 'Masjid' : 'Madrasa');
                    $_SESSION['tenant_system_type'] = $tSysType;
                    $_SESSION['tenant_id'] = (int)($adm['t_id'] ?? $adm['tenant_id'] ?? $tid);
                    $_SESSION['tenant_code'] = $tCode;
                    $_SESSION['tenant_name'] = $tName;
                    $_SESSION['last_activity'] = time();
                    return true;
                }
            } elseif ($role === 'teacher') {
                $stmt = $pdo->prepare("
                    SELECT tc.*, tn.id as t_id, tn.code as t_code, tn.name as t_name, tn.system_type as t_system_type
                    FROM teachers tc
                    LEFT JOIN tenants tn ON tc.tenant_id = tn.id
                    WHERE tc.id = ? AND (tc.tenant_id = ? OR tc.tenant_id IS NULL OR tc.tenant_id = 0)
                    LIMIT 1
                ");
                $stmt->execute([$uid, $tid]);
                $tc = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($tc) {
                    $tCode = !empty($tc['t_code']) ? $tc['t_code'] : 'MAIN01';
                    $tName = !empty($tc['t_name']) ? $tc['t_name'] : 'کلاؤڈ ساس ادارہ';
                    $tSysType = !empty($tc['t_system_type']) ? $tc['t_system_type'] : 'Madrasa';

                    $_SESSION['user_id'] = (int)$tc['id'];
                    $_SESSION['role'] = 'teacher';
                    $_SESSION['name'] = $tc['name'];
                    $_SESSION['username'] = $tc['username'] ?? $tc['name'];
                    $_SESSION['system_type'] = (!empty($tc['system_type']) && $tc['system_type'] !== 'Both') ? $tc['system_type'] : ($tSysType === 'Masjid' ? 'Masjid' : 'Madrasa');
                    $_SESSION['tenant_id'] = (int)($tc['t_id'] ?? $tc['tenant_id'] ?? $tid);
                    $_SESSION['tenant_code'] = $tCode;
                    $_SESSION['tenant_name'] = $tName;
                    $_SESSION['last_activity'] = time();
                    return true;
                }
            }
        } catch (Throwable $e) {}

        return false;
    }
}

