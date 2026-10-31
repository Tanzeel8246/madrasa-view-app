<?php
/**
 * Multi-Tenant SaaS Middleware & Core Helpers
 * 
 * Provides tenant resolution, context resolution, SaaS settings,
 * module permissions catalog, and subscription checks.
 */

if (!function_exists('get_current_tenant_id')) {
    function get_current_tenant_id($explicit = null) {
        if ($explicit !== null && is_numeric($explicit) && (int)$explicit > 0) {
            return (int)$explicit;
        }
        // 1. Web session
        if (isset($_SESSION['tenant_id']) && (int)$_SESSION['tenant_id'] > 0) {
            return (int)$_SESSION['tenant_id'];
        }
        // 2. HTTP headers (API)
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        $norm = array_change_key_case($headers, CASE_LOWER);
        if (!empty($norm['x-tenant-id']) && is_numeric($norm['x-tenant-id'])) {
            return (int)$norm['x-tenant-id'];
        }
        if (!empty($_SERVER['HTTP_X_TENANT_ID']) && is_numeric($_SERVER['HTTP_X_TENANT_ID'])) {
            return (int)$_SERVER['HTTP_X_TENANT_ID'];
        }
        // 3. Request param
        if (!empty($_REQUEST['tenant_id']) && is_numeric($_REQUEST['tenant_id'])) {
            return (int)$_REQUEST['tenant_id'];
        }
        // 4. Default fallback for backwards compatibility
        return 1;
    }
}

if (!function_exists('get_current_tenant_code')) {
    function get_current_tenant_code() {
        if (!empty($_SESSION['tenant_code'])) {
            return strtoupper(trim((string)$_SESSION['tenant_code']));
        }
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        $norm = array_change_key_case($headers, CASE_LOWER);
        if (!empty($norm['x-tenant-code'])) {
            return strtoupper(trim((string)$norm['x-tenant-code']));
        }
        if (!empty($_SERVER['HTTP_X_TENANT_CODE'])) {
            return strtoupper(trim((string)$_SERVER['HTTP_X_TENANT_CODE']));
        }
        if (!empty($_REQUEST['tenant_code'])) {
            return strtoupper(trim((string)$_REQUEST['tenant_code']));
        }
        return 'MAIN01';
    }
}

if (!function_exists('resolve_tenant_by_code')) {
    function resolve_tenant_by_code($pdo, $code) {
        $raw = (string)$code;
        // 1. Remove invisible Unicode directional formatting characters (LRM, RLM, ALM, ZWSP, BOM, etc.)
        $clean = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{061C}\x{FEFF}\x{00A0}]/u', '', $raw);
        // 2. Normalize Unicode dashes/hyphens to ASCII hyphen
        $clean = preg_replace('/[\x{2010}-\x{2015}\x{2212}]/u', '-', $clean);
        // 3. Convert Eastern Arabic & Urdu digits to ASCII digits
        $easternDigits = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $westernDigits = ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
        $clean = str_replace($easternDigits, $westernDigits, $clean);
        $c = strtoupper(trim(preg_replace('/\s+/', '', $clean)));
        if (empty($c)) $c = 'MAIN01';
        try {
            $stmt = $pdo->prepare("SELECT * FROM tenants WHERE UPPER(code) = ? OR UPPER(slug) = ? LIMIT 1");
            $stmt->execute([$c, $c]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;

            // Hyphen & whitespace agnostic match (e.g. M-26-001 vs M26001 vs M_26_001)
            $cNoHyphen = str_replace(['-', '_'], '', $c);
            if (!empty($cNoHyphen)) {
                $stmt2 = $pdo->prepare("SELECT * FROM tenants WHERE UPPER(REPLACE(REPLACE(code, '-', ''), '_', '')) = ? OR UPPER(REPLACE(REPLACE(slug, '-', ''), '_', '')) = ? LIMIT 1");
                $stmt2->execute([$cNoHyphen, $cNoHyphen]);
                $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);
                if ($row2) return $row2;
            }

            // Fallback for default tenant / single-madrasa mode
            if ($c === 'MAIN01' || $c === 'DEFAULT') {
                try {
                    $row1 = $pdo->query("SELECT * FROM tenants WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                    if ($row1) return $row1;

                    $first = $pdo->query("SELECT * FROM tenants ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                    if ($first) return $first;
                } catch (Throwable $te1) {}

                // Auto-create/seed default tenant row if table exists or can be created
                try {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `tenants` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `code` VARCHAR(50) UNIQUE NOT NULL,
                            `name` VARCHAR(255) NOT NULL,
                            `slug` VARCHAR(100) UNIQUE NOT NULL,
                            `system_type` VARCHAR(50) DEFAULT 'Madrasa',
                            `status` VARCHAR(30) NOT NULL DEFAULT 'active',
                            `allowed_modules` TEXT DEFAULT NULL,
                            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                    ");
                    $pdo->exec("
                        INSERT IGNORE INTO `tenants` (`id`, `code`, `name`, `slug`, `system_type`, `status`)
                        VALUES (1, 'MAIN01', 'مدرسہ اشرف المدارس', 'main01', 'Madrasa', 'active')
                    ");
                    $seeded = $pdo->query("SELECT * FROM tenants WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                    if ($seeded) return $seeded;
                } catch (Throwable $te2) {}

                // Virtual fallback if database user lacks DDL privileges
                return [
                    'id' => 1,
                    'code' => 'MAIN01',
                    'name' => 'مدرسہ اشرف المدارس',
                    'slug' => 'main01',
                    'system_type' => 'Madrasa',
                    'status' => 'active'
                ];
            }
            return null;
        } catch (Throwable $e) {
            if ($c === 'MAIN01' || $c === 'DEFAULT' || empty($code)) {
                return [
                    'id' => 1,
                    'code' => 'MAIN01',
                    'name' => 'مدرسہ اشرف المدارس',
                    'slug' => 'main01',
                    'system_type' => 'Madrasa',
                    'status' => 'active'
                ];
            }
            return null;
        }
    }
}

if (!function_exists('get_tenant_id')) {
    function get_tenant_id() {
        return (int)get_current_tenant_id();
    }
}

if (!function_exists('tenant_sql')) {
    function tenant_sql($alias = '') {
        $tid = (int)get_current_tenant_id();
        return $alias ? " {$alias}.tenant_id = {$tid} " : " tenant_id = {$tid} ";
    }
}

if (!function_exists('get_tenant_settings')) {
    function get_tenant_settings(PDO $pdo, $tenant_id = null) {
        if ($tenant_id === null || (int)$tenant_id <= 0) {
            $tenant_id = (int)get_current_tenant_id();
        }
        $tenant_id = (int)$tenant_id;
        try {
            $stmt = $pdo->prepare("SELECT * FROM settings WHERE tenant_id = ? ORDER BY id ASC LIMIT 1");
            $stmt->execute([$tenant_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;

            // If not found by tenant_id, auto-provision for this tenant
            $tName = '';
            try {
                $tStmt = $pdo->prepare("SELECT name FROM tenants WHERE id = ? LIMIT 1");
                $tStmt->execute([$tenant_id]);
                $tName = (string)$tStmt->fetchColumn();
            } catch (Throwable $te) {}

            if ($tName === '') {
                // Fallback to id=1 if this is tenant 1
                if ($tenant_id === 1) {
                    $row1 = $pdo->query("SELECT * FROM settings WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                    if ($row1) {
                        try { $pdo->prepare("UPDATE settings SET tenant_id = 1 WHERE id = 1")->execute(); } catch (Throwable $ue) {}
                        return $row1;
                    }
                }
                $tName = 'جامعہ دارالعلوم';
            }

            // Insert new settings row for this tenant
            $ins = $pdo->prepare("INSERT INTO settings (tenant_id, madrasa_name, current_hijri_year, week_start_day) VALUES (?, ?, '1447', 6)");
            $ins->execute([$tenant_id, $tName]);
            $newId = $pdo->lastInsertId();

            $stmt2 = $pdo->prepare("SELECT * FROM settings WHERE id = ? LIMIT 1");
            $stmt2->execute([$newId]);
            $newRow = $stmt2->fetch(PDO::FETCH_ASSOC);
            if ($newRow) return $newRow;
        } catch (Throwable $e) {}

        // Ultimate fallback
        try {
            $fallback = $pdo->query("SELECT * FROM settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($fallback) return $fallback;
        } catch (Throwable $e) {}

        return [
            'tenant_id' => $tenant_id,
            'madrasa_name' => 'جامعہ دارالعلوم',
            'phone' => '',
            'address' => '',
            'tagline' => 'العلم نور - تعلیم و تربیت کا گہوارہ',
            'current_hijri_year' => '1447',
            'week_start_day' => 6,
            'logo_path' => 'logo.png',
            'receipt_bg' => 'uploads/receipt_bg.webp',
            'masjid_receipt_bg' => 'uploads/MAMFSS.webp',
            'id_card_bg' => null,
            'sanad_bg' => null,
            'signature_stamp_path' => null,
            'masjid_name' => 'مسجد اشرف المدارس',
            'masjid_logo_path' => null
        ];
    }
}

if (!function_exists('get_saas_setting')) {
    function get_saas_setting($pdo, $key, $default = null) {
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM saas_settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute([$key]);
            $val = $stmt->fetchColumn();
            return ($val !== false && $val !== null) ? $val : $default;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('set_saas_setting')) {
    function set_saas_setting($pdo, $key, $value) {
        try {
            $stmt = $pdo->prepare("INSERT INTO saas_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
            return $stmt->execute([$key, (string)$value]);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('get_all_system_modules')) {
    function get_all_system_modules() {
        return [
            'core_academics' => [
                'title' => 'بنیادی تعلیمی ونگ (Core Academics)',
                'modules' => [
                    'classes' => ['name' => 'کلاسز کا انتظام', 'desc' => 'کلاسز، سیکشنز اور درجات کا انتظام'],
                    'students' => ['name' => 'طلباء مینجمنٹ', 'desc' => 'طلباء کا ریکارڈ، داخلہ، کوائف و سابقہ طلباء (Alumni)'],
                    'teachers' => ['name' => 'اساتذہ و عملہ', 'desc' => 'اساتذہ کا ریکارڈ، کوائف و پروفائلز'],
                    'attendance' => ['name' => 'حاضری رجسٹر و رپورٹس', 'desc' => 'طلبہ و اساتذہ کی روزانہ حاضری، رخصت اور QR حاضری'],
                    'hifz' => ['name' => 'حفظ و ناظرہ و تعلیمی رپورٹس', 'desc' => 'روزانہ سبق، سبقی، منزل اور درسی تعلیمی کارکردگی'],
                    'exams' => ['name' => 'امتحانات و رزلٹ کارڈز', 'desc' => 'امتحانات کا انعقاد، نتائج کا اندراج اور اسناد'],
                    'darse_nizami_academics' => ['name' => 'درس نظامی و عصری تعلیمی نظام', 'desc' => 'کورس ڈھانچہ، مضامین وار نمبرات انٹری، سالانہ ترقی، نتیجہ نامہ اور لیٹر پیڈ پرنٹ'],
                    'admissions' => ['name' => 'آن لائن داخلہ پائپ لائن', 'desc' => 'داخلہ فارم، ٹیسٹ اور داخلہ درخواستیں'],
                    'notices' => ['name' => 'نوٹس بورڈ و اعلانات', 'desc' => 'ادارہ جاتی پیغامات، اعلانات اور نوٹیفیکیشنز'],
                    'requests' => ['name' => 'درخواستیں و شکایات', 'desc' => 'والدین و اساتذہ کی شکایات و اصلاحی نوٹسز']
                ]
            ],
            'finance_accounts' => [
                'title' => 'مالیات و اکاؤنٹس (Finance & ERP)',
                'modules' => [
                    'simple_finance' => ['name' => 'سادہ فنانس (آمدن و خرچ)', 'desc' => 'روزمرہ کیش انکم و ایکسپنس، کیش ان ہینڈ اور عطیات'],
                    'assets_donations' => ['name' => 'اثاثہ جات و اشیاء عطیات', 'desc' => 'ادارہ کے فکسڈ اثاثے اور اشیاء کے عطیات'],
                    'payroll' => ['name' => 'مشاہرہ و وظائف عملہ', 'desc' => 'ماہانہ تنخواہوں/وظائف کا حساب کتاب اور ادائیگیاں'],
                    'loans' => ['name' => 'ایڈوانس و قرض مینیجمنٹ', 'desc' => 'عملہ کے قرضے، کٹوتی اور وصولیاں'],
                    'erp_accounts' => ['name' => 'مکمل ڈبل انٹری ERP اکاؤنٹس', 'desc' => 'چارٹ آف اکاؤنٹس، واؤچرز، لیجر، ٹرائل بیلنس، انکم اسٹیٹمنٹ، بیلنس شیٹ اور فیس انوائسز']
                ]
            ],
            'operations_campus' => [
                'title' => 'جدید آپریشنز و کیمپس (Phase 3 & 4)',
                'modules' => [
                    'library' => ['name' => 'لائبریری مینجمنٹ', 'desc' => 'کتب کی کیٹلاگ، اجراء و واپسی'],
                    'inventory' => ['name' => 'سکول شاپ و انونٹری', 'desc' => 'کتب، کاپیاں و اشیاء کی فروخت و اسٹاک'],
                    'hostel' => ['name' => 'ہاسٹل و رہائش مینیجمنٹ', 'desc' => 'کمرے، بیڈز اور رہائشی طلبہ کا انتظام'],
                    'recruitment' => ['name' => 'بھرتی و تقرری (HR)', 'desc' => 'ملازمت کے اشتہارات، امیدوار اور انٹرویوز'],
                    'cms' => ['name' => 'ویب سائٹ و پبلک CMS', 'desc' => 'ادارے کی ویب سائٹ، گیلری اور پبلک پورٹل مواد']
                ]
            ],
            'masjid_wing' => [
                'title' => 'مسجد و مکتب ونگ (Masjid & Maktab)',
                'modules' => [
                    'masjid_wing' => ['name' => 'مسجد و مکتب مکمل نظام', 'desc' => 'مسجد کا الگ موڈ، مکتب قرآنی کلاسز، مسجد فنانس اور اعتکاف']
                ]
            ]
        ];
    }
}

if (!function_exists('get_default_module_keys')) {
    function get_default_module_keys() {
        return [
            'classes', 'students', 'teachers', 'attendance', 'hifz',
            'exams', 'darse_nizami_academics', 'admissions', 'notices', 'requests', 'simple_finance',
            'assets_donations', 'payroll', 'loans', 'erp_accounts',
            'library', 'inventory', 'hostel', 'recruitment', 'cms', 'masjid_wing'
        ];
    }
}

if (!function_exists('get_tenant_allowed_modules')) {
    function get_tenant_allowed_modules($pdo, $tenantId = null) {
        $tid = ($tenantId !== null) ? (int)$tenantId : (int)get_current_tenant_id();
        static $memCache = [];
        if (isset($memCache[$tid])) {
            return $memCache[$tid];
        }

        try {
            $stmt = $pdo->prepare("SELECT allowed_modules FROM tenants WHERE id = ? LIMIT 1");
            $stmt->execute([$tid]);
            $raw = $stmt->fetchColumn();
            if ($raw !== false && $raw !== null && trim((string)$raw) !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $memCache[$tid] = $decoded;
                    return $decoded;
                }
            }
        } catch (Throwable $e) {
            try {
                $colChk = $pdo->query("SHOW COLUMNS FROM `tenants` LIKE 'allowed_modules'")->fetch();
                if (!$colChk) {
                    $pdo->exec("ALTER TABLE `tenants` ADD COLUMN `allowed_modules` TEXT DEFAULT NULL");
                }
            } catch (Throwable $eCol) {}
        }

        $defaults = get_default_module_keys();
        $memCache[$tid] = $defaults;
        return $defaults;
    }
}

if (!function_exists('is_module_allowed_for_tenant')) {
    function is_module_allowed_for_tenant($pdo, $moduleKey, $tenantId = null) {
        $tid = ($tenantId !== null) ? (int)$tenantId : (int)get_current_tenant_id();

        // 1. Super admin global override (only when super admin is logged in or impersonating)
        if (!empty($_SESSION['is_super_admin']) || !empty($_SESSION['impersonated_by_super_admin'])) {
            return true;
        }

        // 2. Default root tenant 1 has full master access
        if ($tid <= 1) {
            return true;
        }

        // 3. Strict System Type Isolation Guard
        // If tenant is Madrasa-only, masjid_wing is strictly forbidden
        $sysType = $_SESSION['tenant_system_type'] ?? null;
        if (!$sysType) {
            try {
                $stStmt = $pdo->prepare("SELECT system_type FROM tenants WHERE id = ? LIMIT 1");
                $stStmt->execute([$tid]);
                $stVal = $stStmt->fetchColumn();
                if ($stVal) $sysType = $stVal;
            } catch (Throwable $eSt) {}
        }
        if (!$sysType) $sysType = 'Madrasa';

        if ($sysType === 'Madrasa' && $moduleKey === 'masjid_wing') {
            return false;
        }

        if ($sysType === 'Masjid') {
            $madrasaOnlyModules = ['darse_nizami_academics', 'hostel', 'admissions', 'recruitment', 'library'];
            if (in_array($moduleKey, $madrasaOnlyModules, true)) {
                return false;
            }
        }

        // 4. Check tenant allowed modules list
        $allowed = get_tenant_allowed_modules($pdo, $tid);
        return in_array($moduleKey, $allowed, true);
    }
}

if (!function_exists('is_mod_allowed')) {
    function is_mod_allowed($moduleKey, $tenantId = null) {
        global $pdo;
        if (function_exists('is_module_allowed_for_tenant') && isset($pdo)) {
            return is_module_allowed_for_tenant($pdo, $moduleKey, $tenantId);
        }
        return true;
    }
}

/**
 * ٹیننٹ کے فعال سبسکرپشن پلان اور مقررہ حدود حاصل کرنے کا مرکزی فنکشن
 */
if (!function_exists('get_tenant_plan_limits')) {
    function get_tenant_plan_limits($pdo, $tenantId = null) {
        $tid = ($tenantId !== null) ? (int)$tenantId : (int)get_current_tenant_id();
        if ($tid <= 1) {
            return [
                'plan_name' => 'مرکزی انٹرپرائز (Master Enterprise)',
                'plan_code' => 'enterprise',
                'max_students' => 99999,
                'max_classes' => 99999,
                'max_teachers' => 99999,
                'has_mobile_app' => 1,
                'has_desktop_app' => 1,
                'status' => 'active',
                'system_type' => 'Both'
            ];
        }

        try {
            // First check active or trial subscription
            $stmt = $pdo->prepare("
                SELECT s.status as sub_status, s.ends_at, s.plan_id,
                       p.id as p_id, p.name as plan_name, p.code as plan_code,
                       p.max_students, p.max_classes, p.max_teachers,
                       p.has_mobile_app, p.has_desktop_app,
                       t.status as tenant_status, t.system_type as tenant_system_type
                FROM tenants t
                LEFT JOIN subscriptions s ON t.id = s.tenant_id AND s.status IN ('active', 'trial')
                LEFT JOIN subscription_plans p ON s.plan_id = p.id
                WHERE t.id = ?
                ORDER BY s.id DESC LIMIT 1
            ");
            $stmt->execute([$tid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['plan_name'])) {
                return [
                    'plan_name' => $row['plan_name'],
                    'plan_code' => $row['plan_code'] ?: 'free',
                    'max_students' => (int)($row['max_students'] ?: 20),
                    'max_classes' => (int)($row['max_classes'] ?: 1),
                    'max_teachers' => (int)($row['max_teachers'] ?: 2),
                    'has_mobile_app' => (int)($row['has_mobile_app'] ?? 1),
                    'has_desktop_app' => (int)($row['has_desktop_app'] ?? 1),
                    'status' => $row['sub_status'] ?: ($row['tenant_status'] ?: 'trial'),
                    'system_type' => $row['tenant_system_type'] ?: 'Madrasa'
                ];
            }

            // Fallback to latest subscription row regardless of status
            $stmt2 = $pdo->prepare("
                SELECT s.status as sub_status, s.ends_at, s.plan_id,
                       p.id as p_id, p.name as plan_name, p.code as plan_code,
                       p.max_students, p.max_classes, p.max_teachers,
                       p.has_mobile_app, p.has_desktop_app,
                       t.status as tenant_status, t.system_type as tenant_system_type
                FROM tenants t
                LEFT JOIN subscriptions s ON t.id = s.tenant_id
                LEFT JOIN subscription_plans p ON s.plan_id = p.id
                WHERE t.id = ?
                ORDER BY s.id DESC LIMIT 1
            ");
            $stmt2->execute([$tid]);
            $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);
            if ($row2 && !empty($row2['plan_name'])) {
                return [
                    'plan_name' => $row2['plan_name'],
                    'plan_code' => $row2['plan_code'] ?: 'free',
                    'max_students' => (int)($row2['max_students'] ?: 20),
                    'max_classes' => (int)($row2['max_classes'] ?: 1),
                    'max_teachers' => (int)($row2['max_teachers'] ?: 2),
                    'has_mobile_app' => (int)($row2['has_mobile_app'] ?? 1),
                    'has_desktop_app' => (int)($row2['has_desktop_app'] ?? 1),
                    'status' => $row2['sub_status'] ?: ($row2['tenant_status'] ?: 'trial'),
                    'system_type' => $row2['tenant_system_type'] ?: 'Madrasa'
                ];
            }
        } catch (Throwable $e) {}

        return [
            'plan_name' => 'مفت آزمائش (Free Trial)',
            'plan_code' => 'free',
            'max_students' => 20,
            'max_classes' => 1,
            'max_teachers' => 2,
            'has_mobile_app' => 1,
            'has_desktop_app' => 1,
            'status' => 'trial',
            'system_type' => 'Madrasa'
        ];
    }
}

/**
 * ٹیننٹ کے لائیو استعمال کے میٹرکس کا واحد باضابطہ ذریعہ (Single Source of Truth)
 * تمام اسکرینوں (سبسکرپشن، ڈیش بورڈ، کوٹہ چیک، موبائل API) کے لیے 100% یکساں گنتی
 */
if (!function_exists('get_tenant_live_usage')) {
    function get_tenant_live_usage($pdo, $tenantId = null, $sys = null) {
        $tid = ($tenantId !== null) ? (int)$tenantId : (int)get_current_tenant_id();
        if ($sys === null) {
            $sys = $_SESSION['system_type'] ?? $_SESSION['tenant_system_type'] ?? null;
            if (!$sys && $tid) {
                try {
                    $stStmt = $pdo->prepare("SELECT system_type FROM tenants WHERE id = ? LIMIT 1");
                    $stStmt->execute([$tid]);
                    $sys = $stStmt->fetchColumn();
                } catch (Throwable $e) {}
            }
            if (!$sys) $sys = 'Madrasa';
        }

        $usedClasses = 0;
        $usedStudents = 0;
        $usedTeachers = 0;

        $tCond = ($tid <= 1) ? "(tenant_id = 1 OR tenant_id IS NULL OR tenant_id = 0)" : "tenant_id = $tid";

        // Helper to check column existence safely
        $hasCol = function($tbl, $col) use ($pdo) {
            try {
                return (bool)$pdo->query("SHOW COLUMNS FROM `$tbl` LIKE '$col'")->fetch();
            } catch (Throwable $e) { return false; }
        };

        // 1. Classes Count (100% Isolated to System Type)
        try {
            $hasIsDel = $hasCol('classes', 'is_deleted');
            $delCls = $hasIsDel ? " AND (is_deleted = 0 OR is_deleted IS NULL)" : "";
            $hasStatus = $hasCol('classes', 'status');
            $actCls = $hasStatus ? " AND (status = 'Active' OR status = '' OR status IS NULL)" : "";
            $hasSys = $hasCol('classes', 'system_type');

            if ($sys === 'Masjid') {
                if ($hasSys) {
                    $usedClasses = (int)$pdo->query("SELECT COUNT(*) FROM classes WHERE $tCond AND system_type = 'Masjid' $delCls $actCls")->fetchColumn();
                } else {
                    try {
                        $usedClasses = (int)$pdo->query("SELECT COUNT(*) FROM maktab_classes WHERE $tCond $delCls $actCls")->fetchColumn();
                    } catch (Throwable $eM) { $usedClasses = 0; }
                }
            } else {
                $sysCond = $hasSys ? " AND (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')" : "";
                $usedClasses = (int)$pdo->query("SELECT COUNT(*) FROM classes WHERE $tCond $sysCond $delCls $actCls")->fetchColumn();
            }
        } catch (Throwable $e) {}

        // 2. Teachers Count
        try {
            $hasIsDel = $hasCol('teachers', 'is_deleted');
            $delTea = $hasIsDel ? " AND (is_deleted = 0 OR is_deleted IS NULL)" : "";
            $hasStatus = $hasCol('teachers', 'status');
            $actTea = $hasStatus ? " AND (status = 'Active' OR status = '' OR status IS NULL)" : "";
            $hasSys = $hasCol('teachers', 'system_type');

            if ($sys === 'Masjid') {
                if ($hasSys) {
                    $usedTeachers = (int)$pdo->query("SELECT COUNT(*) FROM teachers WHERE $tCond AND system_type = 'Masjid' $delTea $actTea")->fetchColumn();
                } else {
                    try {
                        $usedTeachers = (int)$pdo->query("SELECT COUNT(*) FROM maktab_teachers WHERE $tCond $delTea $actTea")->fetchColumn();
                    } catch (Throwable $eM) { $usedTeachers = 0; }
                }
            } else {
                $sysCond = $hasSys ? " AND (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')" : "";
                $usedTeachers = (int)$pdo->query("SELECT COUNT(*) FROM teachers WHERE $tCond $sysCond $delTea $actTea")->fetchColumn();
            }
        } catch (Throwable $e) {}

        // 3. Students Count (Excludes alumni/struck off/inactive)
        try {
            $hasIsDel = $hasCol('students', 'is_deleted');
            $delStd = $hasIsDel ? " AND (is_deleted = 0 OR is_deleted IS NULL)" : "";
            $hasStatus = $hasCol('students', 'status');
            $actStd = $hasStatus ? " AND (status = 'Active' OR status = '' OR status IS NULL OR status = 'حاضر')" : "";
            $hasSys = $hasCol('students', 'system_type');

            if ($sys === 'Masjid') {
                $hasMaktabTable = false;
                try {
                    $hasMaktabTable = !empty($pdo->query("SHOW TABLES LIKE 'maktab_students'")->fetchAll());
                } catch (Throwable $eT) {}

                if ($hasMaktabTable) {
                    $hasIsDelM = $hasCol('maktab_students', 'is_deleted');
                    $delStdM = $hasIsDelM ? " AND (is_deleted = 0 OR is_deleted IS NULL)" : "";
                    $hasStatusM = $hasCol('maktab_students', 'status');
                    $actStdM = $hasStatusM ? " AND (status = 'Active' OR status = '' OR status IS NULL OR status = 'حاضر')" : "";
                    $usedStudents = (int)$pdo->query("SELECT COUNT(*) FROM maktab_students WHERE $tCond $delStdM $actStdM")->fetchColumn();
                } elseif ($hasSys) {
                    $usedStudents = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE $tCond AND system_type = 'Masjid' $delStd $actStd")->fetchColumn();
                }
            } else {
                $sysCond = $hasSys ? " AND (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')" : "";
                $usedStudents = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE $tCond $sysCond $delStd $actStd")->fetchColumn();
            }
        } catch (Throwable $e) {}

        return [
            'classes' => $usedClasses,
            'teachers' => $usedTeachers,
            'students' => $usedStudents
        ];
    }
}

/**
 * کسی بھی نئے ریکارڈ (کلاس، استاد، طالب علم) کے اندراج سے پہلے کوٹہ لِمٹ کی سخت جانچ
 */
if (!function_exists('check_quota_limit')) {
    function check_quota_limit($pdo, $type, $tenantId = null) {
        $tid = ($tenantId !== null) ? (int)$tenantId : (int)get_current_tenant_id();
        if ($tid <= 1 || !empty($_SESSION['is_super_admin'])) {
            return ['allowed' => true, 'current' => 0, 'max' => 99999];
        }

        $limits = get_tenant_plan_limits($pdo, $tid);
        $usage = get_tenant_live_usage($pdo, $tid);

        // 1. درجات و کلاسز کی حد (Classes Quota)
        if ($type === 'classes') {
            $totalClasses = $usage['classes'];
            $max = (int)$limits['max_classes'];
            if ($totalClasses >= $max) {
                return [
                    'allowed' => false,
                    'current' => $totalClasses,
                    'max' => $max,
                    'message' => "آپ کے موجودہ پلان ({$limits['plan_name']}) میں کلاسز کی زیادہ سے زیادہ حد ({$max}) مکمل ہو چکی ہے۔ نئی کلاس شامل کرنے کے لیے سبسکرپشن پلان اپ گریڈ کریں۔"
                ];
            }
            return ['allowed' => true, 'current' => $totalClasses, 'max' => $max];
        }

        // 2. اساتذہ کی حد (Teachers Quota)
        if ($type === 'teachers') {
            $totalTeachers = $usage['teachers'];
            $max = (int)$limits['max_teachers'];
            if ($totalTeachers >= $max) {
                return [
                    'allowed' => false,
                    'current' => $totalTeachers,
                    'max' => $max,
                    'message' => "آپ کے موجودہ پلان ({$limits['plan_name']}) میں اساتذہ و عملہ کی زیادہ سے زیادہ حد ({$max}) مکمل ہو چکی ہے۔ مزید اساتذہ شامل کرنے کے لیے سبسکرپشن پلان اپ گریڈ کریں۔"
                ];
            }
            return ['allowed' => true, 'current' => $totalTeachers, 'max' => $max];
        }

        // 3. طلبہ کی حد (Students Quota)
        if ($type === 'students') {
            $totalStudents = $usage['students'];
            $max = (int)$limits['max_students'];
            if ($totalStudents >= $max) {
                return [
                    'allowed' => false,
                    'current' => $totalStudents,
                    'max' => $max,
                    'message' => "آپ کے موجودہ پلان ({$limits['plan_name']}) میں طلبہ کی زیادہ سے زیادہ حد ({$max}) مکمل ہو چکی ہے۔ مزید طلبہ داخل کرنے کے لیے سبسکرپشن پلان اپ گریڈ کریں۔"
                ];
            }
            return ['allowed' => true, 'current' => $totalStudents, 'max' => $max];
        }

        return ['allowed' => true, 'current' => 0, 'max' => 99999];
    }
}

if (!function_exists('ensure_tenant_isolation_schema')) {
    function ensure_tenant_isolation_schema($pdo, $force = false) {
        static $executed = false;
        if ($executed && !$force) return;
        $executed = true;

        $cacheFlag = __DIR__ . '/../.tenant_schema_v7_isolation_fixes_done';
        if (!$force && file_exists($cacheFlag)) return;

        try {
            // 1. SaaS settings table
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `saas_settings` (
                    `setting_key` VARCHAR(50) PRIMARY KEY,
                    `setting_value` TEXT,
                    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            $chk = $pdo->query("SELECT COUNT(*) FROM saas_settings WHERE setting_key = 'allow_public_registration'")->fetchColumn();
            if ($chk == 0) {
                $pdo->exec("INSERT INTO saas_settings (setting_key, setting_value) VALUES ('allow_public_registration', '1')");
            }

            // 1b. Ensure tenants table exists with default MAIN01
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `tenants` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `code` VARCHAR(50) UNIQUE NOT NULL,
                        `name` VARCHAR(255) NOT NULL,
                        `slug` VARCHAR(100) UNIQUE NOT NULL,
                        `system_type` VARCHAR(50) DEFAULT 'Madrasa',
                        `status` VARCHAR(30) NOT NULL DEFAULT 'active',
                        `allowed_modules` TEXT DEFAULT NULL,
                        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");
                $hasMain = (int)$pdo->query("SELECT COUNT(*) FROM `tenants` WHERE `code` = 'MAIN01' OR `id` = 1")->fetchColumn();
                if ($hasMain === 0) {
                    $pdo->exec("
                        INSERT INTO `tenants` (`id`, `code`, `name`, `slug`, `system_type`, `status`)
                        VALUES (1, 'MAIN01', 'مدرسہ اشرف المدارس', 'main01', 'Madrasa', 'active')
                    ");
                }
            } catch (Throwable $eTen) {}

            // 1c. Ensure subscription_plans table exists and has all SaaS columns & 5 plans
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `subscription_plans` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `code` VARCHAR(50) NOT NULL UNIQUE,
                        `name` VARCHAR(100) NOT NULL,
                        `name_en` VARCHAR(100) DEFAULT NULL,
                        `description` TEXT DEFAULT NULL,
                        `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                        `price_monthly` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                        `price_yearly` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                        `max_students` INT DEFAULT 50,
                        `max_classes` INT DEFAULT 5,
                        `max_teachers` INT DEFAULT 10,
                        `max_admins` INT DEFAULT 3,
                        `has_mobile_app` TINYINT(1) DEFAULT 1,
                        `has_desktop_app` TINYINT(1) DEFAULT 1,
                        `has_sms` TINYINT(1) DEFAULT 0,
                        `has_custom_domain` TINYINT(1) DEFAULT 0,
                        `features_json` TEXT DEFAULT NULL,
                        `is_active` TINYINT(1) DEFAULT 1,
                        `is_free_trial` TINYINT(1) DEFAULT 0,
                        `trial_days` INT DEFAULT 14,
                        `sort_order` INT DEFAULT 0,
                        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");

                $pCols = $pdo->query("SHOW COLUMNS FROM `subscription_plans`")->fetchAll(PDO::FETCH_COLUMN);
                $pNewCols = [
                    'code' => "VARCHAR(50) DEFAULT 'basic'",
                    'max_classes' => "INT DEFAULT 5",
                    'has_desktop_app' => "TINYINT(1) DEFAULT 1",
                    'has_mobile_app' => "TINYINT(1) DEFAULT 1",
                    'has_sms' => "TINYINT(1) DEFAULT 0",
                    'has_custom_domain' => "TINYINT(1) DEFAULT 0",
                    'price' => "DECIMAL(10,2) DEFAULT 0.00",
                    'price_monthly' => "DECIMAL(10,2) DEFAULT 0.00",
                    'price_yearly' => "DECIMAL(10,2) DEFAULT 0.00"
                ];
                foreach ($pNewCols as $colName => $colDef) {
                    if (!in_array($colName, $pCols)) {
                        try { $pdo->exec("ALTER TABLE `subscription_plans` ADD COLUMN `$colName` $colDef"); } catch (Throwable $e) {}
                    }
                }

                // Seed/Update the 5 official SaaS subscription packages
                $saasPlans = [
                    [
                        'code' => 'free',
                        'name' => 'مفت آزمائش (Free Trial)',
                        'name_en' => 'Free Trial',
                        'description' => '20 طلبہ، 1 کلاس، 2 اساتذہ و عملہ — صرف ویب پینل رسائی',
                        'price' => 0.00,
                        'price_monthly' => 0.00,
                        'price_yearly' => 0.00,
                        'max_students' => 20,
                        'max_classes' => 1,
                        'max_teachers' => 2,
                        'has_mobile_app' => 0,
                        'has_desktop_app' => 0,
                        'is_free_trial' => 1,
                        'sort_order' => 1
                    ],
                    [
                        'code' => 'basic',
                        'name' => 'بنیادی پیکج (Basic)',
                        'name_en' => 'Basic Plan',
                        'description' => '50 طلبہ، 3 کلاسز، 4 اساتذہ و عملہ — تینوں پلیٹ فارمز (ویب + اینڈرائڈ + ونڈوز)',
                        'price' => 1000.00,
                        'price_monthly' => 1000.00,
                        'price_yearly' => 10000.00,
                        'max_students' => 50,
                        'max_classes' => 3,
                        'max_teachers' => 4,
                        'has_mobile_app' => 1,
                        'has_desktop_app' => 1,
                        'is_free_trial' => 0,
                        'sort_order' => 2
                    ],
                    [
                        'code' => 'standard',
                        'name' => 'معیاری پیکج (Standard)',
                        'name_en' => 'Standard Plan',
                        'description' => '100 طلبہ، 6 کلاسز، 8 اساتذہ و عملہ — تینوں پلیٹ فارمز (ویب + اینڈرائڈ + ونڈوز)',
                        'price' => 2000.00,
                        'price_monthly' => 2000.00,
                        'price_yearly' => 20000.00,
                        'max_students' => 100,
                        'max_classes' => 6,
                        'max_teachers' => 8,
                        'has_mobile_app' => 1,
                        'has_desktop_app' => 1,
                        'is_free_trial' => 0,
                        'sort_order' => 3
                    ],
                    [
                        'code' => 'premium_200',
                        'name' => 'ایڈوانس پیکج (Advanced)',
                        'name_en' => 'Advanced Plan',
                        'description' => '200 طلبہ، 12 کلاسز، 16 اساتذہ و عملہ — تینوں پلیٹ فارمز (ویب + اینڈرائڈ + ونڈوز)',
                        'price' => 3000.00,
                        'price_monthly' => 3000.00,
                        'price_yearly' => 30000.00,
                        'max_students' => 200,
                        'max_classes' => 12,
                        'max_teachers' => 16,
                        'has_mobile_app' => 1,
                        'has_desktop_app' => 1,
                        'is_free_trial' => 0,
                        'sort_order' => 4
                    ],
                    [
                        'code' => 'enterprise',
                        'name' => 'لامحدود انٹرپرائز (Unlimited)',
                        'name_en' => 'Enterprise Plan',
                        'description' => 'لامحدود طلبہ، کلاسز، اساتذہ و عملہ — تمام خصوصیات و ترجیحی کسٹم سپورٹ',
                        'price' => 0.00,
                        'price_monthly' => 0.00,
                        'price_yearly' => 0.00,
                        'max_students' => 99999,
                        'max_classes' => 99999,
                        'max_teachers' => 99999,
                        'has_mobile_app' => 1,
                        'has_desktop_app' => 1,
                        'is_free_trial' => 0,
                        'sort_order' => 5
                    ]
                ];

                try {
                    $pdo->exec("UPDATE `subscription_plans` SET `is_active` = 0 WHERE `price` IN (2000, 5000, 10000) OR `code` NOT IN ('free', 'basic', 'standard', 'premium_200', 'enterprise')");
                } catch (Throwable $eLegacy) {}

                foreach ($saasPlans as $sp) {
                    $chk = $pdo->prepare("SELECT id FROM `subscription_plans` WHERE `code` = ? LIMIT 1");
                    $chk->execute([$sp['code']]);
                    $existingId = $chk->fetchColumn();
                    if ($existingId) {
                        $upStmt = $pdo->prepare("UPDATE `subscription_plans` SET `name` = ?, `description` = ?, `price` = ?, `price_monthly` = ?, `price_yearly` = ?, `max_students` = ?, `max_classes` = ?, `max_teachers` = ?, `has_mobile_app` = ?, `has_desktop_app` = ?, `sort_order` = ? WHERE `id` = ?");
                        $upStmt->execute([$sp['name'], $sp['description'], $sp['price'], $sp['price_monthly'], $sp['price_yearly'], $sp['max_students'], $sp['max_classes'], $sp['max_teachers'], $sp['has_mobile_app'], $sp['has_desktop_app'], $sp['sort_order'], $existingId]);
                    } else {
                        $inStmt = $pdo->prepare("INSERT INTO `subscription_plans` (`code`, `name`, `name_en`, `description`, `price`, `price_monthly`, `price_yearly`, `max_students`, `max_classes`, `max_teachers`, `has_mobile_app`, `has_desktop_app`, `is_free_trial`, `sort_order`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $inStmt->execute([$sp['code'], $sp['name'], $sp['name_en'], $sp['description'], $sp['price'], $sp['price_monthly'], $sp['price_yearly'], $sp['max_students'], $sp['max_classes'], $sp['max_teachers'], $sp['has_mobile_app'], $sp['has_desktop_app'], $sp['is_free_trial'], $sp['sort_order']]);
                    }
                }
                @touch($cacheFlag);
            } catch (Throwable $ePlans) {}

            // 2. Fix status truncation for tenants & subscriptions (VARCHAR instead of restrictive ENUM)
            try { $pdo->exec("ALTER TABLE `tenants` MODIFY COLUMN `status` VARCHAR(30) NOT NULL DEFAULT 'trial'"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE `subscriptions` MODIFY COLUMN `status` VARCHAR(30) NOT NULL DEFAULT 'trial'"); } catch (Throwable $e) {}
            try {
                $tCols = $pdo->query("SHOW COLUMNS FROM `tenants`")->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array('allowed_modules', $tCols)) {
                    $pdo->exec("ALTER TABLE `tenants` ADD COLUMN `allowed_modules` TEXT DEFAULT NULL");
                }
            } catch (Throwable $e) {}

            // 2b. Comprehensive Darse Nizami & Academic System Tables
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `course_structure` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `tenant_id` INT NOT NULL DEFAULT 1,
                        `name` VARCHAR(255) NOT NULL,
                        `course_type` VARCHAR(50) DEFAULT 'Darse_Nizami',
                        `total_years` INT NOT NULL DEFAULT 8,
                        `gender` VARCHAR(20) DEFAULT 'بنین',
                        `description` TEXT DEFAULT NULL,
                        `is_active` TINYINT(1) DEFAULT 1,
                        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX (`tenant_id`),
                        INDEX (`course_type`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");

                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `course_subjects` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `tenant_id` INT NOT NULL DEFAULT 1,
                        `course_id` INT NOT NULL,
                        `year_no` INT NOT NULL DEFAULT 1,
                        `year_title` VARCHAR(100) DEFAULT NULL,
                        `subject_name` VARCHAR(255) NOT NULL,
                        `book_name` VARCHAR(255) DEFAULT NULL,
                        `max_marks` DECIMAL(6,2) NOT NULL DEFAULT 100.00,
                        `pass_marks` DECIMAL(6,2) NOT NULL DEFAULT 40.00,
                        `subject_order` INT DEFAULT 0,
                        `is_optional` TINYINT(1) DEFAULT 0,
                        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                        INDEX (`tenant_id`),
                        INDEX (`course_id`),
                        INDEX (`year_no`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");

                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `student_course_enrollment` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `tenant_id` INT NOT NULL DEFAULT 1,
                        `student_id` INT NOT NULL,
                        `course_id` INT NOT NULL,
                        `current_year` INT NOT NULL DEFAULT 1,
                        `academic_session` VARCHAR(50) DEFAULT NULL,
                        `enrollment_date` DATE DEFAULT NULL,
                        `status` VARCHAR(20) DEFAULT 'active',
                        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                        INDEX (`tenant_id`),
                        INDEX (`student_id`),
                        INDEX (`course_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");

                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `exam_subject_results` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `tenant_id` INT NOT NULL DEFAULT 1,
                        `exam_id` INT NOT NULL,
                        `student_id` INT NOT NULL,
                        `course_id` INT NOT NULL DEFAULT 0,
                        `year_no` INT NOT NULL DEFAULT 1,
                        `subject_id` INT NOT NULL DEFAULT 0,
                        `subject_name` VARCHAR(255) NOT NULL,
                        `marks_obtained` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
                        `max_marks` DECIMAL(6,2) NOT NULL DEFAULT 100.00,
                        `is_absent` TINYINT(1) DEFAULT 0,
                        `grade` VARCHAR(20) DEFAULT 'مقبول',
                        `remarks` VARCHAR(255) DEFAULT NULL,
                        `is_synced` TINYINT(1) DEFAULT 0,
                        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                        INDEX (`tenant_id`),
                        INDEX (`exam_id`),
                        INDEX (`student_id`),
                        INDEX (`course_id`),
                        INDEX (`subject_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");

                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `student_year_promotion` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `tenant_id` INT NOT NULL DEFAULT 1,
                        `student_id` INT NOT NULL,
                        `course_id` INT NOT NULL,
                        `from_year` INT NOT NULL,
                        `to_year` INT NOT NULL,
                        `academic_session` VARCHAR(50) DEFAULT NULL,
                        `status` VARCHAR(20) DEFAULT 'Promoted',
                        `promoted_by` INT DEFAULT NULL,
                        `remarks` TEXT DEFAULT NULL,
                        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                        INDEX (`tenant_id`),
                        INDEX (`student_id`),
                        INDEX (`course_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");
            } catch (Throwable $eAcadTables) {}

            // 3. Ensure tenant_id exists and backfill 1 on legacy rows across all tables
            $tables = [
                'admins', 'students', 'teachers', 'classes', 'class_periods', 'attendance', 'period_attendance',
                'attendance_teachers', 'teacher_period_attendance', 'teacher_leaves', 'daily_hifz', 'daily_nazira',
                'qaida_records', 'academic_reports', 'exams', 'exam_results',
                'parent_complaints', 'complaints', 'parents', 'fee_submissions', 'admission_applications', 'admission_inquiries',
                'notices', 'activity_logs', 'leave_applications', 'heads', 'finance',
                'staff_loans', 'loan_transactions', 'staff_salaries', 'staff_stipends', 'assets',
                'in_kind_donations', 'inventory_items', 'inventory_sales', 'hostel_rooms',
                'hostel_allocations', 'lib_books', 'lib_issues', 'web_pages', 'announcements',
                'job_postings', 'job_applicants', 'settings', 'smart_notification_reads',
                'acc_chart_of_accounts', 'acc_vouchers', 'acc_ledger_entries', 'acc_invoices',
                'acc_payroll', 'acc_departments', 'maktab_classes', 'maktab_teachers',
                'course_structure', 'course_subjects', 'student_course_enrollment', 'exam_subject_results', 'student_year_promotion'
            ];

            foreach ($tables as $t) {
                try {
                    $cols = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN);
                    if (!in_array('tenant_id', $cols)) {
                        $pdo->exec("ALTER TABLE `$t` ADD COLUMN `tenant_id` INT NOT NULL DEFAULT 1");
                    }
                    $pdo->exec("UPDATE `$t` SET `tenant_id` = 1 WHERE `tenant_id` IS NULL OR `tenant_id` = 0");
                } catch (Throwable $eT) {}
            }

            // 4. Ensure specific vital columns exist across core tables
            $essential_cols = [
                'students' => [
                    'system_type' => "VARCHAR(50) DEFAULT 'Madrasa'",
                    'is_deleted' => "TINYINT DEFAULT 0",
                    'residence_status' => "VARCHAR(50) DEFAULT ''",
                    'quran_edition' => "VARCHAR(50) DEFAULT '16 سطری'"
                ],
                'finance' => [
                    'system_type' => "VARCHAR(50) DEFAULT 'Madrasa'",
                    'is_deleted' => "TINYINT DEFAULT 0",
                    'head_name' => "VARCHAR(255) DEFAULT NULL",
                    'account_id' => "INT DEFAULT NULL",
                    'payment_method' => "VARCHAR(50) DEFAULT 'Cash'",
                    'deleted_at' => "DATETIME DEFAULT NULL",
                    'original_receipt_no' => "VARCHAR(100) DEFAULT NULL"
                ],
                'heads' => [
                    'is_deleted' => "TINYINT DEFAULT 0"
                ],
                'admission_applications' => [
                    'serial_no' => "INT DEFAULT NULL"
                ],
                'acc_chart_of_accounts' => [
                    'system_type' => "VARCHAR(50) DEFAULT 'Madrasa'",
                    'is_deleted' => "TINYINT DEFAULT 0",
                    'is_active' => "TINYINT(1) DEFAULT 1"
                ],
                'acc_vouchers' => [
                    'system_type' => "VARCHAR(50) DEFAULT 'Madrasa'",
                    'is_deleted' => "TINYINT(1) DEFAULT 0",
                    'created_by' => "INT DEFAULT NULL"
                ],
                'classes' => [
                    'subjects_list' => "VARCHAR(255) DEFAULT NULL",
                    'section' => "VARCHAR(50) DEFAULT NULL",
                    'system_type' => "VARCHAR(50) DEFAULT 'Madrasa'",
                    'class_type' => "VARCHAR(50) DEFAULT 'Darse Nizami / Other'"
                ],
                'class_periods' => [
                    'tenant_id' => "INT NOT NULL DEFAULT 1"
                ]
            ];

            foreach ($essential_cols as $tbl => $colsMap) {
                try {
                    $existingCols = $pdo->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($colsMap as $cName => $cDef) {
                        if (!in_array($cName, $existingCols)) {
                            try { $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN `$cName` $cDef"); } catch (Throwable $e) {}
                        }
                    }
                } catch (Throwable $e) {}
            }

            // 4b. Multi-tenant Index Isolation: Drop legacy global unique keys and replace with tenant-scoped composite keys
            $uniqueFixes = [
                'classes' => ['name', 'idx_tenant_class', "(`tenant_id`, `name`, `section`, `system_type`)"],
                'students' => ['admission_number', 'idx_tenant_adm_no', "(`tenant_id`, `admission_number`)"],
                'maktab_students' => ['admission_number', 'idx_tenant_maktab_adm_no', "(`tenant_id`, `admission_number`)"],
                'admission_applications' => ['admission_number', 'idx_tenant_app_adm_no', "(`tenant_id`, `admission_number`)"],
                'acc_chart_of_accounts' => ['code', 'idx_tenant_acc_code', "(`tenant_id`, `code`)"]
            ];

            foreach ($uniqueFixes as $tbl => $fix) {
                list($targetCol, $compositeIdxName, $compositeDef) = $fix;
                try {
                    $indexes = $pdo->query("SHOW INDEX FROM `$tbl`")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($indexes as $idx) {
                        if ($idx['Non_unique'] == 0 && $idx['Key_name'] !== 'PRIMARY' && $idx['Column_name'] === $targetCol) {
                            $kName = $idx['Key_name'];
                            $hasTenant = false;
                            foreach ($indexes as $idx2) {
                                if ($idx2['Key_name'] === $kName && $idx2['Column_name'] === 'tenant_id') {
                                    $hasTenant = true;
                                }
                            }
                            if (!$hasTenant) {
                                try { $pdo->exec("ALTER TABLE `$tbl` DROP INDEX `$kName`"); } catch (Throwable $eDrop) {}
                            }
                        }
                    }
                    try {
                        $pdo->exec("ALTER TABLE `$tbl` ADD UNIQUE KEY `$compositeIdxName` $compositeDef");
                    } catch (Throwable $eAddIdx) {}
                } catch (Throwable $eTblIdx) {}
            }

            // 5. Self-healing restore: Restore advance expenses for users who spend from Advance accounts
            $restoreFlag = __DIR__ . '/../.restore_user_advance_expenses_v6_done';
            if (!file_exists($restoreFlag)) {
                try {
                    $advAcctRows = $pdo->query("SELECT id, name FROM acc_chart_of_accounts WHERE type = 'Asset' AND (name LIKE 'ایڈوانس%' OR name LIKE 'Advance%') AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchAll(PDO::FETCH_ASSOC);
                    $admRows = $pdo->query("SELECT id, username, name, role FROM admins")->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($advAcctRows as $aa) {
                        $aid = (int)$aa['id'];
                        $clean_label = trim(preg_replace('/^(ایڈوانس|advance)\s*[-:]?\s*/iu', '', $aa['name']));
                        if (empty($clean_label)) continue;

                        $matchedUser = null;
                        foreach ($admRows as $u) {
                            $un = !empty($u['username']) ? $u['username'] : '';
                            $nm = !empty($u['name']) ? $u['name'] : '';
                            if (mb_strtolower(trim($un)) === mb_strtolower($clean_label) ||
                                mb_strtolower(trim($nm)) === mb_strtolower($clean_label) ||
                                (!empty($un) && mb_stripos($aa['name'], $un) !== false) ||
                                (!empty($nm) && mb_stripos($aa['name'], $nm) !== false) ||
                                (!empty($nm) && mb_stripos($clean_label, $nm) !== false) ||
                                (!empty($un) && mb_stripos($clean_label, $un) !== false)) {
                                $matchedUser = $u;
                                break;
                            }
                        }

                        // Auto-overwrite removed to preserve user/account segregation integrity
                    }
                    @touch($restoreFlag);
                } catch (Throwable $eR) {}
            }

            @touch($cacheFlag);
        } catch (Throwable $e) {}
    }
}

// Lightweight tenant safety check (only runs heavy migration if explicitly requested via ?migrate_tenant_schema=1)

if (!function_exists('check_tenant_subscription')) {
    function check_tenant_subscription($pdo, $tenantId, $clientPlatform = null) {
        // اگر سنگل مدرسہ یا ڈیفالٹ ٹیننٹ (id <= 1) ہو تو ہمیشہ ایکٹو رکھیں
        if ((int)$tenantId <= 1) {
            return [
                'ok' => true,
                'tenant' => [
                    'id' => 1,
                    'code' => 'MAIN01',
                    'name' => 'مدرسہ اشرف المدارس',
                    'status' => 'active',
                    'has_mobile_app' => 1,
                    'has_desktop_app' => 1,
                    'max_students' => 99999,
                    'max_classes' => 99999,
                    'max_teachers' => 99999
                ]
            ];
        }
        try {
            $stmt = $pdo->prepare("
                SELECT t.*, s.status as sub_status, s.ends_at, s.plan_id,
                       COALESCE(p.name, 'کسٹم پلان') as plan_name,
                       COALESCE(p.code, 'custom') as plan_code,
                       COALESCE(p.max_students, t.max_students, 50) as plan_max_students,
                       COALESCE(p.max_classes, 5) as plan_max_classes,
                       COALESCE(p.max_teachers, t.max_teachers, 10) as plan_max_teachers,
                       COALESCE(p.has_mobile_app, 1) as plan_has_mobile,
                       COALESCE(p.has_desktop_app, 1) as plan_has_desktop
                FROM tenants t 
                LEFT JOIN subscriptions s ON t.id = s.tenant_id AND s.status IN ('active', 'trial')
                LEFT JOIN subscription_plans p ON s.plan_id = p.id
                WHERE t.id = ? 
                ORDER BY s.id DESC LIMIT 1
            ");
            $stmt->execute([(int)$tenantId]);
            $t = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$t) return ['ok' => true, 'tenant' => null];

            if ($t['status'] === 'suspended') {
                return ['ok' => false, 'message' => 'آپ کے ادارے کا اکاؤنٹ معطل (Suspended) کر دیا گیا ہے۔ انتظامیہ سے رابطہ کریں۔'];
            }
            if ($t['status'] === 'cancelled') {
                return ['ok' => false, 'message' => 'ادارے کی رکنیت منسوخ ہو چکی ہے۔'];
            }

            // چیک میعاد
            if (!empty($t['ends_at'])) {
                if (strtotime($t['ends_at']) < strtotime(date('Y-m-d'))) {
                    return ['ok' => false, 'message' => 'آپ کے ادارے کی سبسکرپشن کی میعاد ختم ہو چکی ہے، براہ کرم تجدید کروائیں۔'];
                }
            } elseif ($t['status'] === 'trial' && !empty($t['trial_ends_at'])) {
                if (strtotime($t['trial_ends_at']) < strtotime(date('Y-m-d'))) {
                    return ['ok' => false, 'message' => 'آپ کا 30 روزہ مفت ٹرائل ختم ہو چکا ہے۔ جاری رکھنے کے لیے پلان منتخب کریں۔'];
                }
            }

            // پلیٹ فارم رسائی کی جانچ (Web vs Mobile vs Windows)
            if ($clientPlatform === null) {
                $clientPlatform = strtolower(trim((string)(
                    $_SERVER['HTTP_X_CLIENT_PLATFORM'] ?? 
                    $_REQUEST['client_platform'] ?? 
                    $_POST['client_platform'] ?? 
                    ''
                )));
            }

            $hasMobile = isset($t['plan_has_mobile']) ? (int)$t['plan_has_mobile'] : 1;
            $hasDesktop = isset($t['plan_has_desktop']) ? (int)$t['plan_has_desktop'] : 1;

            if (($clientPlatform === 'mobile' || $clientPlatform === 'android' || $clientPlatform === 'ios') && $hasMobile === 0) {
                return [
                    'ok' => false,
                    'message' => 'مفت پلان (Free Plan) میں صرف ویب پینل کی سہولت شامل ہے۔ اینڈرائڈ اور ونڈوز ایپ استعمال کرنے کے لیے براہ کرم اپنا سبسکرپشن پلان اپ گریڈ کریں۔'
                ];
            }

            if (($clientPlatform === 'windows' || $clientPlatform === 'desktop') && $hasDesktop === 0) {
                return [
                    'ok' => false,
                    'message' => 'مفت پلان (Free Plan) میں صرف ویب پینل کی سہولت شامل ہے۔ اینڈرائڈ اور ونڈوز ایپ استعمال کرنے کے لیے براہ کرم اپنا سبسکرپشن پلان اپ گریڈ کریں۔'
                ];
            }

            return ['ok' => true, 'tenant' => $t];
        } catch (Throwable $e) {
            return ['ok' => true, 'tenant' => null];
        }
    }
}

// Auto-invoke self-healing schema if PDO is active
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        ensure_tenant_isolation_schema($pdo);
    } catch (Throwable $eInit) {}
}
