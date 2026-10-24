<?php
/**
 * Central Database Orchestrator & Legacy Compatibility Bridge
 * 
 * Modularized for Phase 5 Architecture Refactor.
 * Maintains 100% backwards compatibility for all 200+ dependent web pages.
 */

// 1. Database Configuration & Connection
require_once __DIR__ . '/config/database.php';

// 2. Multi-Tenant SaaS Middleware & Core Helpers
require_once __DIR__ . '/middleware/tenant.php';
require_once __DIR__ . '/middleware/SafeTenantPDO.php';

// Schema self-healing available via function or on explicit request
if (isset($_GET['migrate_tenant_schema']) && $_GET['migrate_tenant_schema'] === '1') {
    ensure_tenant_isolation_schema($pdo, true);
}

// 3. Authentication, Permissions & Access Control Middleware
require_once __DIR__ . '/middleware/auth.php';

// Auto-heal and restore session from persistent auth cookie across entire application
if (function_exists('madrasa_ensure_session_started')) {
    madrasa_ensure_session_started();
}
if (isset($pdo) && function_exists('madrasa_restore_session_from_cookie')) {
    $curr_script = basename($_SERVER['PHP_SELF'] ?? '');
    $is_login_page = ($curr_script === 'login.php' || $curr_script === 'logout.php');
    if (!$is_login_page && !isset($_GET['logged_out']) && !isset($_GET['logout'])) {
        madrasa_restore_session_from_cookie($pdo);
    }
}

if (!function_exists('format_urdu_month_year')) {
    function format_urdu_month_year($my) {
        if (empty($my)) return '';
        $parts = explode('-', trim((string)$my));
        if (count($parts) < 2) return (string)$my;
        $year = $parts[0];
        $month = (int)$parts[1];
        $urduMonths = [
            1 => 'جنوری', 2 => 'فروری', 3 => 'مارچ', 4 => 'اپریل',
            5 => 'مئی', 6 => 'جون', 7 => 'جولائی', 8 => 'اگست',
            9 => 'ستمبر', 10 => 'اکتوبر', 11 => 'نومبر', 12 => 'دسمبر'
        ];
        $mName = $urduMonths[$month] ?? $parts[1];
        return $mName . ' ' . $year;
    }
}

if (!function_exists('resolve_alumni_category')) {
    function resolve_alumni_category($s) {
        $cat = strtolower(trim((string)($s['alumni_category'] ?? '')));
        $reason = trim((string)($s['leaving_reason'] ?? ''));
        $status = strtolower(trim((string)($s['status'] ?? '')));

        // 1. Direct category match
        if ($cat === 'expelled' || strpos($cat, 'خارج') !== false || strpos($cat, 'اخراج') !== false) {
            return 'expelled';
        }
        if ($cat === 'dropped' || strpos($cat, 'چھوڑ') !== false || strpos($cat, 'ترک') !== false || strpos($cat, 'شوقیہ') !== false) {
            return 'dropped';
        }

        // 2. Reason or status indicators
        if (strpos($reason, 'خارج') !== false || strpos($reason, 'اخراج') !== false || strpos($status, 'خارج') !== false || strpos($status, 'struck off') !== false || strpos($status, 'expelled') !== false) {
            return 'expelled';
        }
        if (strpos($reason, 'چھوڑ') !== false || strpos($reason, 'شوقیہ') !== false || strpos($reason, 'ترک') !== false || strpos($status, 'left') !== false || strpos($status, 'dropped') !== false) {
            return 'dropped';
        }

        return 'completed';
    }
}

if (!function_exists('is_student_inactive')) {
    function is_student_inactive($s, $check_date = null) {
        if (empty($s) || !is_array($s)) return false;
        
        $status = strtolower(trim((string)($s['status'] ?? '')));
        $reason = strtolower(trim((string)($s['leaving_reason'] ?? '')));

        // If explicitly active or present, student is ACTIVE
        if (in_array($status, ['active', 'present', 'حاضر'], true) || $status === '') {
            return false;
        }

        // 1. Status indicates left / alumni / expelled
        $inactive_statuses = [
            'alumni', 'left', 'struck off', 'dropped', 'expelled', 'graduated', 'inactive',
            'خارج', 'فارغ', 'فارغ التحصیل', 'چھوڑ دیا', 'ترک تعلیم'
        ];
        if (in_array($status, $inactive_statuses, true)) return true;
        if (strpos($status, 'خارج') !== false || strpos($status, 'فارغ') !== false || strpos($status, 'چھوڑ') !== false || strpos($status, 'ترک') !== false) return true;

        // 2. Leaving reason
        if (!empty($reason)) {
            if (strpos($reason, 'خارج') !== false || strpos($reason, 'چھوڑ') !== false || strpos($reason, 'ترک') !== false || strpos($reason, 'اخراج') !== false || strpos($reason, 'فارغ') !== false) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('get_period_date_range')) {
    function get_period_date_range($period = 'current_week', $custom_from = null, $custom_to = null, $week_start_day = 6) {
        $today = date('Y-m-d');
        switch ($period) {
            case 'all':
                // تمام تاریخیں / تمام ریکارڈز
                $from = '2000-01-01';
                $to = date('Y-m-d', strtotime('+5 years'));
                break;
            case 'this_month':
                // رواں مہینہ
                $from = date('Y-m-01');
                $to = $today;
                break;
            case 'weekly':
                // آخری 7 دن
                $from = date('Y-m-d', strtotime('-6 days'));
                $to = $today;
                break;
            case 'monthly':
                // آخری 30 دن
                $from = date('Y-m-d', strtotime('-29 days'));
                $to = $today;
                break;
            case 'quarterly':
                // آخری 90 دن
                $from = date('Y-m-d', strtotime('-89 days'));
                $to = $today;
                break;
            case 'semi_annually':
                // آخری 180 دن
                $from = date('Y-m-d', strtotime('-179 days'));
                $to = $today;
                break;
            case 'annually':
                // پچھلے سال آج کی تاریخ سے آج تک (آخری 365 دن)
                $from = date('Y-m-d', strtotime('-364 days'));
                $to = $today;
                break;
            case 'custom':
                $from = !empty($custom_from) ? $custom_from : $today;
                $to = !empty($custom_to) ? $custom_to : $today;
                break;
            case 'current_week':
            default:
                // رواں ہفتہ (ویک سٹارٹ سے آج تک)
                $now = new DateTime();
                $current_day_num = (int)$now->format('w');
                $days_since_start = ($current_day_num - (int)$week_start_day + 7) % 7;
                $start = clone $now;
                $start->modify("-$days_since_start days");
                $from = $start->format('Y-m-d');
                $to = $now->format('Y-m-d');
                break;
        }
        return ['from' => $from, 'to' => $to];
    }
}

// Auto-create new tables for Hifz/Attendance features (only on explicit migration request)
if (!function_exists('ensure_legacy_feature_tables')) {
    function ensure_legacy_feature_tables(PDO $pdo) {
        try {
            $pdo->exec("ALTER TABLE acc_chart_of_accounts ADD COLUMN parent_id INTEGER NULL");
        } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE finance ADD COLUMN account_id INT DEFAULT NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE finance ADD COLUMN payment_method VARCHAR(50) DEFAULT 'Cash'"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE finance ADD COLUMN tenant_id INT NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE masjid_finance ADD COLUMN tenant_id INT NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE acc_payroll ADD COLUMN payment_account_id INT DEFAULT NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE acc_payroll ADD COLUMN payment_method VARCHAR(50) DEFAULT 'Cash'"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE acc_payroll ADD COLUMN tenant_id INT NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE heads ADD COLUMN tenant_id INT NOT NULL DEFAULT 1"); } catch (Throwable $e) {}

try {
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS `teacher_period_attendance` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `teacher_id` int(11) NOT NULL,
      `class_id` int(11) NOT NULL,
      `date` date NOT NULL,
      `period_no` int(11) NOT NULL,
      `status` varchar(50) DEFAULT 'حاضر',
      `late_minutes` int(11) DEFAULT '0',
      `remarks` text DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS `parent_complaints` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `student_id` int(11) NOT NULL,
      `date` date NOT NULL,
      `category` varchar(255) DEFAULT NULL,
      `description` text NOT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS `ai_settings` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `provider` varchar(50) NOT NULL,
      `api_keys` text NOT NULL COMMENT 'JSON array of keys',
      `active_model` varchar(100) DEFAULT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `provider` (`provider`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS `attendance_teachers` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `teacher_id` int(11) NOT NULL,
      `date` date NOT NULL,
      `status` varchar(50) DEFAULT 'P',
      `late_minutes` int(11) DEFAULT 0,
      `remarks` text DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `unique_teacher_daily_attendance` (`teacher_id`, `date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS `period_attendance` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `student_id` int(11) NOT NULL,
      `date` date NOT NULL,
      `period_num` int(11) NOT NULL,
      `status` varchar(50) DEFAULT NULL,
      `remarks` text DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `unique_student_period_attendance` (`student_id`, `date`, `period_num`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS `smart_notification_reads` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `alert_id` varchar(100) NOT NULL,
      `user_id` int(11) NOT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `is_deleted` tinyint(1) DEFAULT 0,
      PRIMARY KEY (`id`),
      UNIQUE KEY `unique_user_alert` (`alert_id`, `user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (PDOException $e) {
    // Ignored
}

try { $pdo->exec("ALTER TABLE settings MODIFY gemini_api_key TEXT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE settings MODIFY groq_api_key TEXT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE settings ADD COLUMN ai_provider VARCHAR(50) DEFAULT 'gemini'"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE settings ADD COLUMN gemini_api_key TEXT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE settings ADD COLUMN groq_api_key TEXT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE attendance_teachers ADD COLUMN late_minutes INT DEFAULT 0"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE attendance_teachers ADD COLUMN remarks TEXT DEFAULT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE teacher_period_attendance ADD COLUMN late_minutes INT DEFAULT 0"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE teacher_period_attendance ADD COLUMN remarks TEXT DEFAULT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE teacher_period_attendance ADD COLUMN is_deleted TINYINT(1) DEFAULT 0"); } catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE period_attendance ADD COLUMN remarks TEXT DEFAULT NULL;");
} catch (PDOException $e) {
    // Ignored if column already exists
}

try { $pdo->exec("ALTER TABLE classes ADD COLUMN main_teacher_ids JSON NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE classes ADD COLUMN subjects_list TEXT NULL"); } catch (PDOException $e) {}
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS class_periods (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        period_no INT NOT NULL,
        books TEXT NULL,
        teacher_id INT NULL,
        teacher_ids JSON NULL,
        UNIQUE KEY unique_class_period (class_id, period_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE class_periods ADD COLUMN books TEXT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE class_periods ADD COLUMN teacher_id INT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE class_periods ADD COLUMN teacher_ids JSON NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE finance ADD COLUMN system_type VARCHAR(50) DEFAULT 'Madrasa'"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE finance ADD COLUMN is_deleted TINYINT(1) DEFAULT 0"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE finance ADD COLUMN computer_receipt_no INT DEFAULT NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE heads ADD COLUMN is_deleted TINYINT(1) DEFAULT 0"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE acc_chart_of_accounts ADD COLUMN system_type VARCHAR(50) DEFAULT 'Madrasa'"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE acc_chart_of_accounts ADD COLUMN is_deleted TINYINT(1) DEFAULT 0"); } catch (Throwable $e) {}


    $examCols = [
        'p1' => 'INT DEFAULT 0',
        'p2' => 'INT DEFAULT 0',
        'p3' => 'INT DEFAULT 0',
        'p4' => 'INT DEFAULT 0',
        'p5' => 'INT DEFAULT 0',
        'p6' => 'INT DEFAULT 0',
        'masail' => 'INT DEFAULT 0',
        'miqdar_khwandgi' => "VARCHAR(255) DEFAULT ''"
    ];
    foreach ($examCols as $col => $def) {
        try {
            $pdo->exec("ALTER TABLE exam_results ADD COLUMN $col $def");
        } catch (PDOException $e) {
            // Ignored if column already exists
        }
    }

    try {
        $pdo->exec("ALTER TABLE daily_hifz ADD COLUMN sabaq_time VARCHAR(20) DEFAULT NULL, ADD COLUMN sabqi_time VARCHAR(20) DEFAULT NULL, ADD COLUMN manzil_time VARCHAR(20) DEFAULT NULL");
    } catch (PDOException $e) {
        // Ignored if columns already exist
    }

    $teacherCols = [
        'house_rent' => 'DECIMAL(10,2) DEFAULT 0',
        'medical_allowance' => 'DECIMAL(10,2) DEFAULT 0',
        'travel_allowance' => 'DECIMAL(10,2) DEFAULT 0',
        'daily_deduction_rate' => 'DECIMAL(10,2) DEFAULT 0',
        'photo' => 'VARCHAR(255) DEFAULT NULL',
        'assigned_classes' => 'TEXT',
        'assigned_periods' => 'TEXT'
    ];
    foreach ($teacherCols as $col => $def) {
        try {
            $pdo->exec("ALTER TABLE teachers ADD COLUMN $col $def");
        } catch (PDOException $e) {
            // Ignored if column already exists
        }
    }

    $studentAlumniCols = [
        'alumni_category' => "VARCHAR(50) DEFAULT 'completed'",
        'wifaq_exam' => "TINYINT(1) DEFAULT 0",
        'wifaq_marks' => "VARCHAR(50) DEFAULT NULL",
        'mehrab_recitation' => "VARCHAR(50) DEFAULT NULL",
        'quran_edition' => "VARCHAR(50) DEFAULT '16 سطری'",
        'hifz_sequence' => "VARCHAR(50) DEFAULT 'reverse'",
    ];
    foreach ($studentAlumniCols as $col => $def) {
        try {
            $pdo->exec("ALTER TABLE students ADD COLUMN $col $def");
        } catch (PDOException $e) {}
        try {
            $pdo->exec("ALTER TABLE maktab_students ADD COLUMN $col $def");
        } catch (PDOException $e) {}
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS departments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            status VARCHAR(20) DEFAULT 'Active',
            is_deleted TINYINT DEFAULT 0,
            is_synced TINYINT DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (PDOException $e) {
            // Ignored if table creation is not permitted here
        }
    }
}
if (isset($_GET['migrate_legacy_schema']) && $_GET['migrate_legacy_schema'] === '1') {
    ensure_legacy_feature_tables($pdo);
}

/**
 * XSS Protection Helper
 */
function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Password Hashing Helpers are defined in middleware/auth.php
 */

// =================================================================

if (session_status() === PHP_SESSION_NONE) {
    if (function_exists('madrasa_safe_session_start')) {
        madrasa_safe_session_start();
    } else {
        @session_start();
    }
}
if (isset($_GET['lang'])) { $_SESSION['lang'] = $_GET['lang']; }
$lang = isset($_SESSION['lang']) ? $_SESSION['lang'] : 'ur';

// 📚 MASTER DICTIONARY (مکمل پروفیشنل لغت)
// =================================================================
$trans = [
    'cash_bank_balances' => ['ur' => 'نقد اور بینک بیلنس', 'en' => 'Cash & Bank Balances'],
    'recent_vouchers' => ['ur' => 'حالیہ واؤچرز', 'en' => 'Recent Vouchers'],
    'erp_accounts' => ['ur' => 'مالیات ڈیش بورڈ', 'en' => 'Finance Dashboard'],
    'total_revenue' => ['ur' => 'کل آمدن', 'en' => 'Total Revenue'],
    'total_expenses' => ['ur' => 'کل اخراجات', 'en' => 'Total Expenses'],
    'total_assets' => ['ur' => 'کل اثاثے', 'en' => 'Total Assets'],
    'net_income' => ['ur' => 'خالص منافع / بچت', 'en' => 'Net Income'],
    'no_cash_bank_accounts_found' => ['ur' => 'کوئی نقد یا بینک اکاؤنٹ نہیں ملا۔', 'en' => 'No Cash/Bank Accounts Found'],
    'no_vouchers_yet' => ['ur' => 'ابھی تک کوئی واؤچر نہیں۔', 'en' => 'No Vouchers Yet'],
    'voucher_no' => ['ur' => 'واؤچر نمبر', 'en' => 'Voucher #'],
    'action_s' => ['ur' => 'ایکشنز', 'en' => 'Actions'],
    'create_double_entry' => ['ur' => 'ڈبل انٹری واؤچر بنائیں', 'en' => 'Create Double-Entry Voucher'],
    'general_description' => ['ur' => 'عام تفصیل', 'en' => 'General Description'],
    'ledger_entries_balance' => ['ur' => 'لیجر اندراجات (بیلنس برابر ہونا ضروری ہے)', 'en' => 'Ledger Entries (Must Balance)'],
    'btn_save' => ['ur' => 'واؤچر محفوظ کریں', 'en' => 'Save Voucher'],
    'closing_balance' => ['ur' => 'اختتامی بیلنس:', 'en' => 'Closing Balance:'],

    'madrasa_ashraf' => ['ur' => 'مدرسہ اشرف المدارس', 'en' => 'Madrasa Ashraf-ul-Madaris'],
    'notice_board_ur' => ['ur' => 'نوٹس بورڈ', 'en' => 'Notice Board'],
    'btn_save_ur' => ['ur' => 'محفوظ کریں', 'en' => 'Save'],
    'contact' => ['ur' => 'رابطہ', 'en' => 'Contact'],
    'action_ur' => ['ur' => 'ایکشن', 'en' => 'Action'],
    'date_ur' => ['ur' => 'تاریخ', 'en' => 'Date'],
    'status_ur' => ['ur' => 'کیفیت', 'en' => 'Status'],
    'phone' => ['ur' => 'فون نمبر', 'en' => 'Phone'],

    'no_cash_bank_accounts_found' => ['ur' => 'کوئی کیش/بینک اکاؤنٹ نہیں ملا۔', 'en' => 'No cash/bank accounts found.'],
    'no_vouchers_yet' => ['ur' => 'ابھی تک کوئی واؤچر نہیں۔', 'en' => 'No vouchers yet.'],
    'view_all' => ['ur' => 'سب دیکھیں', 'en' => 'View All'],
    'asset_ur' => ['ur' => 'اثاثہ', 'en' => 'Asset'],
    'liability_ur' => ['ur' => 'واجبات', 'en' => 'Liability'],
    'equity_ur' => ['ur' => 'سرمایہ', 'en' => 'Equity'],
    'revenue_ur' => ['ur' => 'آمدنی', 'en' => 'Revenue'],
    'expense_ur' => ['ur' => 'خرچہ', 'en' => 'Expense'],
    'btn_cancel' => ['ur' => 'منسوخ کریں', 'en' => 'Cancel'],
    'account_code' => ['ur' => 'اکاؤنٹ کوڈ', 'en' => 'Account Code'],
    'save_account' => ['ur' => 'اکاؤنٹ محفوظ کریں', 'en' => 'Save Account'],
    'totals' => ['ur' => 'کل:', 'en' => 'Totals:'],

    'erp_dashboard' => ['ur' => 'ای آر پی اکاؤنٹس ڈیش بورڈ', 'en' => 'ERP Accounts Dashboard'],
    'new_voucher' => ['ur' => 'نیا واؤچر', 'en' => 'New Voucher'],
    'view_ledger' => ['ur' => 'لیجر دیکھیں', 'en' => 'View Ledger'],
    'total_revenue' => ['ur' => 'کل آمدنی', 'en' => 'Total Revenue'],
    'total_expenses' => ['ur' => 'کل اخراجات', 'en' => 'Total Expenses'],
    'total_assets' => ['ur' => 'کل اثاثے', 'en' => 'Total Assets'],
    'net_income' => ['ur' => 'خالص آمدنی', 'en' => 'Net Income'],
    'cash_bank_balances' => ['ur' => 'کیش اور بینک بیلنس', 'en' => 'Cash & Bank Balances'],
    'recent_vouchers' => ['ur' => 'حالیہ واؤچرز', 'en' => 'Recent Vouchers'],
    'balance' => ['ur' => 'بیلنس', 'en' => 'Balance'],
    'voucher' => ['ur' => 'واؤچر', 'en' => 'Voucher'],
    'type' => ['ur' => 'قسم', 'en' => 'Type'],
    'creator' => ['ur' => 'بنانے والا', 'en' => 'Creator'],
    'asset' => ['ur' => 'اثاثہ', 'en' => 'Asset'],
    'chart_of_accounts_title' => ['ur' => 'اکاؤنٹس کا چارٹ', 'en' => 'Chart of Accounts - ERP'],
    'liability' => ['ur' => 'واجبات', 'en' => 'Liability'],
    'equity' => ['ur' => 'سرمایہ', 'en' => 'Equity'],
    'revenue' => ['ur' => 'آمدنی', 'en' => 'Revenue'],
    'hifz_edu_report' => ['ur' => 'حفظ تعلیمی رپورٹ', 'en' => 'Hifz Educational Report'],
    'duration_lbl' => ['ur' => 'دورانیہ:', 'en' => 'Duration:'],
    'btn_search' => ['ur' => 'تلاش کریں', 'en' => 'Search'],
    'date_lbl' => ['ur' => 'تاریخ:', 'en' => 'Date:'],
    'lazy_students_lbl' => ['ur' => 'سست طلبہ (جن کی کوئی رپورٹ درج نہیں ہوئی)', 'en' => 'Lazy Students (No Report Entered)'],
    'roll_number' => ['ur' => 'رول نمبر', 'en' => 'Roll Number'],
    'name_lbl' => ['ur' => 'نام ', 'en' => 'Name '],
    'father_name_lbl' => ['ur' => 'ولدیت', 'en' => 'Father Name'],
    'no_lbl' => ['ur' => 'کوئی ', 'en' => 'No '],
    'not_recited' => ['ur' => ' نہیں سنایا', 'en' => ' Not Recited'],
    'hifz_overall_performance' => ['ur' => 'حفظ کی مجموعی کارکردگی', 'en' => 'Hifz Overall Performance'],
    'no_record_found' => ['ur' => 'کوئی ریکارڈ نہیں ملا۔', 'en' => 'No record found.'],

'active_students' => ['ur' => 'زیر تعلیم طلبہ', 'en' => 'Active Students'],
'active_teachers' => ['ur' => 'اساتذہ', 'en' => 'Teachers'],
'active_classes' => ['ur' => 'کلاسز (درجات)', 'en' => 'Classes (Grades)'],
'present_perc' => ['ur' => 'حاضر %', 'en' => 'Present %'],
'late_perc' => ['ur' => 'تاخیر %', 'en' => 'Late %'],
'leave_perc' => ['ur' => 'رخصت %', 'en' => 'Leave %'],
'today_attendance_perc' => ['ur' => 'آج کی حاضری فیصد', 'en' => 'Today\'s Attendance %'],
'this_month_expenses' => ['ur' => 'اس ماہ کے اخراجات', 'en' => 'This Month\'s Expenses'],
'alumni_graduates' => ['ur' => 'فارغ التحصیل', 'en' => 'Alumni / Graduates'],
'monthly_attendance_perc' => ['ur' => 'ماہانہ حاضری %', 'en' => 'Monthly Attendance %'],
'notices' => ['ur' => 'اعلانات', 'en' => 'Notices'],

'manage_classes' => ['ur' => 'کلاسز', 'en' => 'Classes'],
'manage_admissions' => ['ur' => 'داخلہ درخواستیں', 'en' => 'Admission Requests'],
'manage_hifz_daily' => ['ur' => 'حفظ (روزانہ اندراج)', 'en' => 'Hifz (Daily Entry)'],
'manage_hifz_report' => ['ur' => 'حفظ (تعلیمی رپورٹ)', 'en' => 'Hifz (Report)'],
'manage_academic_entry' => ['ur' => 'تعلیمی رپورٹ (اندراج)', 'en' => 'Academic Report (Entry)'],
'manage_academic_dashboard' => ['ur' => 'تعلیمی کارکردگی (اندراج)', 'en' => 'Academic Performance (Entry)'],
'manage_academic_view' => ['ur' => 'تعلیمی رپورٹ (دیکھیں)', 'en' => 'Academic Report (View)'],
'manage_users' => ['ur' => 'یوزرز کا انتظام', 'en' => 'User Management'],
'erp_accounts' => ['ur' => 'مالیات', 'en' => 'Finance'],
'finance_dashboard_menu' => ['ur' => 'ڈیش بورڈ', 'en' => 'Dashboard'],
'chart_of_accounts_menu' => ['ur' => 'اکاؤنٹس کا چارٹ', 'en' => 'Chart of Accounts'],
'vouchers_menu' => ['ur' => 'واؤچرز', 'en' => 'Vouchers'],
'general_ledger_menu' => ['ur' => 'جنرل لیجر', 'en' => 'General Ledger'],
'fee_invoices_menu' => ['ur' => 'فیس انوائسز', 'en' => 'Fee Invoices'],
'payroll_menu' => ['ur' => 'پے رول', 'en' => 'Payroll'],

'finance_dashboard' => ['ur' => 'مالیاتی ڈیش بورڈ', 'en' => 'Finance Dashboard'],
'chart_of_accounts' => ['ur' => 'اکاؤنٹس کا چارٹ', 'en' => 'Chart of Accounts'],
'account_type' => ['ur' => 'اکاؤنٹ ٹائپ', 'en' => 'Account Type'],
'account_name' => ['ur' => 'اکاؤنٹ کا نام', 'en' => 'Account Name'],
'opening_balance' => ['ur' => 'ابتدائی بیلنس', 'en' => 'Opening Balance'],
'create_new_account' => ['ur' => 'نیا اکاؤنٹ بنائیں', 'en' => 'Create New Account'],
'type_asset' => ['ur' => 'اثاثہ', 'en' => 'Asset'],
'type_liability' => ['ur' => 'واجب الادا', 'en' => 'Liability'],
'type_equity' => ['ur' => 'آمدن', 'en' => 'Income/Equity'],
'type_expense' => ['ur' => 'خرچ', 'en' => 'Expense'],
'enter_description' => ['ur' => 'تفصیل درج کریں', 'en' => 'Enter description'],
'transaction_type' => ['ur' => 'ٹرانزیکشن کی قسم', 'en' => 'Transaction Type'],
'debit' => ['ur' => 'ڈیبٹ', 'en' => 'Debit'],
'credit' => ['ur' => 'کریڈٹ', 'en' => 'Credit'],
'enter_amount' => ['ur' => 'رقم درج کریں', 'en' => 'Enter Amount'],
'ref_voucher_no' => ['ur' => 'حوالہ / واؤچر نمبر', 'en' => 'Ref/Voucher No'],
'vouchers_list' => ['ur' => 'واؤچرز کی فہرست', 'en' => 'Vouchers List'],
'enter_new_voucher' => ['ur' => 'نیا واؤچر درج کریں', 'en' => 'Enter New Voucher'],
'ledger_report' => ['ur' => 'لیجر رپورٹ', 'en' => 'Ledger Report'],
'select_account' => ['ur' => 'اکاؤنٹ منتخب کریں', 'en' => 'Select Account'],
'generate_report' => ['ur' => 'رپورٹ نکالیں', 'en' => 'Generate Report'],
'total_debit' => ['ur' => 'کل ڈیبٹ', 'en' => 'Total Debit'],
'total_credit' => ['ur' => 'کل کریڈٹ', 'en' => 'Total Credit'],
'balance_remaining' => ['ur' => 'بقیہ', 'en' => 'Remaining Balance'],
'fee_invoices' => ['ur' => 'فیس انوائسز', 'en' => 'Fee Invoices'],
'create_new_invoice' => ['ur' => 'نئی انوائس بنائیں', 'en' => 'Create New Invoice'],
'due_date' => ['ur' => 'ادائیگی کی آخری تاریخ', 'en' => 'Due Date'],
'student_id_name' => ['ur' => 'طالب علم کا آئی ڈی / نام', 'en' => 'Student ID / Name'],
'staff_payroll' => ['ur' => 'عملے کی تنخواہیں', 'en' => 'Staff Payroll'],
'record_salary_payment' => ['ur' => 'تنخواہ کی ادائیگی کا اندراج', 'en' => 'Record Salary Payment'],
'teacher_staff_name' => ['ur' => 'استاذ / عملہ کا نام', 'en' => 'Teacher/Staff Name'],
'basic_salary' => ['ur' => 'بیسک تنخواہ', 'en' => 'Basic Salary'],
'allowances' => ['ur' => 'الاؤنسز', 'en' => 'Allowances'],
'deductions' => ['ur' => 'کٹوتی', 'en' => 'Deductions'],
'net_pay' => ['ur' => 'خالص ادائیگی', 'en' => 'Net Pay'],
'payment_method' => ['ur' => 'ادائیگی کا طریقہ', 'en' => 'Payment Method'],
'pay_cash' => ['ur' => 'کیش', 'en' => 'Cash'],
'pay_bank' => ['ur' => 'بینک', 'en' => 'Bank Transfer'],
'pay_cheque' => ['ur' => 'چیک', 'en' => 'Cheque'],

'my_children_report' => ['ur' => 'میرے بچوں کی تعلیمی رپورٹ', 'en' => 'My Children\'s Academic Report'],
'attendance_summary' => ['ur' => 'حاضری کا خلاصہ', 'en' => 'Attendance Summary'],
'daily_hifz_record' => ['ur' => 'روزانہ کا حفظ ریکارڈ', 'en' => 'Daily Hifz Record'],
'subject_performance' => ['ur' => 'مضمون وار کارکردگی', 'en' => 'Subject-wise Performance'],
'child_name' => ['ur' => 'بچے کا نام', 'en' => 'Child\'s Name'],
'fee_payment' => ['ur' => 'فیس کی ادائیگی', 'en' => 'Fee Payment'],
'fee_month' => ['ur' => 'فیس کا مہینہ', 'en' => 'Fee Month'],
'amount_rs' => ['ur' => 'رقم', 'en' => 'Amount (Rs)'],
'fee_desc_msg' => ['ur' => 'فیس کی تفصیل یا پیغام', 'en' => 'Fee Details or Message'],
'pay_online_receipt' => ['ur' => 'آن لائن ادائیگی کریں / رسید بھیجیں', 'en' => 'Pay Online / Send Receipt'],
'previous_payments' => ['ur' => 'پچھلی ادائیگیاں', 'en' => 'Previous Payments'],
'leave_application' => ['ur' => 'چھٹی کی درخواست', 'en' => 'Leave Application'],
'leave_reason' => ['ur' => 'رخصت کی وجہ', 'en' => 'Reason for Leave'],
'start_date' => ['ur' => 'شروع کی تاریخ', 'en' => 'Start Date'],
'end_date' => ['ur' => 'ختم کی تاریخ', 'en' => 'End Date'],
'send_application' => ['ur' => 'درخواست بھیجیں', 'en' => 'Send Application'],
'your_past_applications' => ['ur' => 'آپ کی پچھلی درخواستیں', 'en' => 'Your Past Applications'],
'register_complaint' => ['ur' => 'شکایت درج کریں', 'en' => 'Register Complaint'],
'complaint_title' => ['ur' => 'شکایت کا عنوان', 'en' => 'Complaint Title'],
'complaint_details' => ['ur' => 'شکایت کی تفصیل', 'en' => 'Complaint Details'],
'software_settings' => ['ur' => 'سافٹ ویئر سیٹنگز', 'en' => 'Software Settings'],
'inst_name' => ['ur' => 'ادارے کا نام', 'en' => 'Institution Name'],
'inst_address' => ['ur' => 'ایڈریس', 'en' => 'Address'],
'logo' => ['ur' => 'لوگو', 'en' => 'Logo'],
'save_settings' => ['ur' => 'سیٹنگز محفوظ کریں', 'en' => 'Save Settings'],
'enter_username' => ['ur' => 'یوزر نیم درج کریں', 'en' => 'Enter Username'],
'enter_password' => ['ur' => 'پاسورڈ درج کریں', 'en' => 'Enter Password'],
'alumni' => ['ur' => 'فارغ التحصیل طلباء', 'en' => 'Alumni'],
'attendance_register' => ['ur' => 'حاضری رجسٹر', 'en' => 'Attendance Register'],
'attendance_report' => ['ur' => 'حاضری رپورٹ', 'en' => 'Attendance Report'],
'exams' => ['ur' => 'امتحانات', 'en' => 'Exams'],
'fee_invoices' => ['ur' => 'فیس انوائسز', 'en' => 'Fee Invoices'],
'generate' => ['ur' => 'جنریٹ کریں', 'en' => 'Generate'],
'invoice_no' => ['ur' => 'انوائس نمبر', 'en' => 'Invoice #'],
'month' => ['ur' => 'مہینہ', 'en' => 'Month'],
'amount' => ['ur' => 'رقم', 'en' => 'Amount'],
'student' => ['ur' => 'طالب علم', 'en' => 'Student'],
'status' => ['ur' => 'سٹیٹس', 'en' => 'Status'],
'actions' => ['ur' => 'ایکشنز', 'en' => 'Actions'],
'no_invoices_found' => ['ur' => 'کوئی انوائس نہیں ملی۔ نئی انوائس جنریٹ کریں!', 'en' => 'No invoices found. Generate new ones!'],
'generate_bulk_invoices' => ['ur' => 'بلک انوائسز جنریٹ کریں', 'en' => 'Generate Bulk Invoices'],
'invoice_month' => ['ur' => 'انوائس کا مہینہ', 'en' => 'Invoice Month (YYYY-MM)'],
'default_fee_amount' => ['ur' => 'ڈیفالٹ فیس', 'en' => 'Default Fee Amount (Rs.)'],
'billed_to_all' => ['ur' => 'یہ رقم تمام فعال طلباء کو بل کی جائے گی۔', 'en' => 'This amount will be billed to all active students.'],
'cancel' => ['ur' => 'منسوخ کریں', 'en' => 'Cancel'],
'generate_now' => ['ur' => 'ابھی جنریٹ کریں', 'en' => 'Generate Now'],
'teacher_payroll' => ['ur' => 'اساتذہ پے رول', 'en' => 'Teacher Payroll'],
'generate_month_payroll' => ['ur' => 'ماہانہ پے رول جنریٹ کریں', 'en' => 'Generate Month Payroll'],
'teacher' => ['ur' => 'استاد', 'en' => 'Teacher'],
'basic' => ['ur' => 'بنیادی', 'en' => 'Basic'],
'allow_deduct' => ['ur' => 'الاؤنس/کٹوتی', 'en' => 'Allow/Deduct'],
'net_salary' => ['ur' => 'خالص تنخواہ', 'en' => 'Net Salary'],
'no_payroll_records' => ['ur' => 'کوئی پے رول ریکارڈ نہیں ملا۔ نیا جنریٹ کریں!', 'en' => 'No payroll records found. Generate new ones!'],
'payroll_month' => ['ur' => 'پے رول کا مہینہ', 'en' => 'Payroll Month (YYYY-MM)'],
'generate_payroll' => ['ur' => 'پے رول جنریٹ کریں', 'en' => 'Generate Payroll'],
'pull_basic_salary' => ['ur' => 'یہ اساتذہ کی پروفائل سے بنیادی تنخواہ لے کر اس مہینے کی سلپ بنائے گا۔', 'en' => 'This will pull the basic salary configured in each teacher\'s profile and create unpaid slips for the selected month.'],
'paid' => ['ur' => 'ادا شدہ', 'en' => 'Paid'],
'unpaid' => ['ur' => 'ناقابل ادا', 'en' => 'Unpaid'],
'cancelled' => ['ur' => 'منسوخ', 'en' => 'Cancelled'],
'pay' => ['ur' => 'ادائیگی کریں', 'en' => 'Pay'],
'students' => ['ur' => 'طلباء', 'en' => 'Students'],
'teachers' => ['ur' => 'اساتذہ', 'en' => 'Teachers'],
'login_to_account' => ['ur' => 'مدرسہ اکاؤنٹ میں داخل ہوں', 'en' => 'Login to Madrasa Account'],
'change_password' => ['ur' => 'پاسورڈ تبدیل کریں', 'en' => 'Change Password'],
'current_password' => ['ur' => 'موجودہ پاسورڈ', 'en' => 'Current Password'],
'new_password' => ['ur' => 'نیا پاسورڈ', 'en' => 'New Password'],
'confirm_new_password' => ['ur' => 'نیا پاسورڈ (دوبارہ)', 'en' => 'Confirm New Password'],
'notice_board_mgmt' => ['ur' => 'نوٹس بورڈ کا انتظام', 'en' => 'Notice Board Management'],
'admissions_mgmt' => ['ur' => 'داخلہ درخواستوں کا انتظام', 'en' => 'Admissions Management'],
'applicant_name' => ['ur' => 'درخواست دہندہ کا نام', 'en' => 'Applicant Name'],
'father_name_admin' => ['ur' => 'والد کا نام', 'en' => 'Father\'s Name'],
'age' => ['ur' => 'عمر', 'en' => 'Age'],
'contact_number' => ['ur' => 'رابطہ نمبر', 'en' => 'Contact Number'],
'address_admin' => ['ur' => 'پتہ', 'en' => 'Address'],
'prev_education' => ['ur' => 'گزشتہ تعلیم', 'en' => 'Previous Education'],
'class_required' => ['ur' => 'کونسی کلاس میں داخلہ چاہیے؟', 'en' => 'Required Class for Admission'],
'photo_optional' => ['ur' => 'تصویر (اختیاری)', 'en' => 'Photo (Optional)'],
'cnic_bform' => ['ur' => 'شناختی کارڈ / ب فارم', 'en' => 'CNIC / B-Form'],
'father_cnic' => ['ur' => 'والد کا شناختی کارڈ', 'en' => 'Father\'s CNIC'],
'requests_complaints' => ['ur' => 'درخواستیں و شکایات', 'en' => 'Requests & Complaints'],
'fee_receipts' => ['ur' => 'فیس کی رسیدیں', 'en' => 'Fee Receipts'],
'leave_applications' => ['ur' => 'چھٹی کی درخواستیں', 'en' => 'Leave Applications'],
'parents_complaints' => ['ur' => 'والدین کی شکایات', 'en' => 'Parents Complaints'],

'student_name' => ['ur' => 'طالب علم کا نام', 'en' => 'Student Name'],
'time_period' => ['ur' => 'وقت', 'en' => 'Time Period'],
'time_morning' => ['ur' => 'صبح', 'en' => 'Morning'],
'time_noon' => ['ur' => 'ظہر', 'en' => 'Noon'],
'time_evening' => ['ur' => 'عصر / مغرب', 'en' => 'Asr / Maghrib'],
'time_night' => ['ur' => 'عشاء', 'en' => 'Isha'],
'status_present' => ['ur' => 'حاضر', 'en' => 'Present'],
'status_leave' => ['ur' => 'رخصت', 'en' => 'Leave'],
'status_absent' => ['ur' => 'غیر حاضر', 'en' => 'Absent'],
'status_sick' => ['ur' => 'بیمار', 'en' => 'Sick'],
'attendance_time' => ['ur' => 'حاضری کا وقت', 'en' => 'Attendance Time'],
'select_time' => ['ur' => 'وقت منتخب کریں', 'en' => 'Select Time'],
'select_all_status' => ['ur' => 'تمام طلباء کے لیے کیفیت منتخب کریں', 'en' => 'Select Status for All'],
'saving_attendance' => ['ur' => 'حاضری محفوظ کی جا رہی ہے...', 'en' => 'Saving attendance...'],
'attendance_saved' => ['ur' => 'حاضری کامیابی سے محفوظ ہو گئی۔', 'en' => 'Attendance saved successfully.'],
'no_students' => ['ur' => 'کوئی طالب علم موجود نہیں۔', 'en' => 'No students found.'],
'attendance_report_title' => ['ur' => 'رجسٹر حاضری (تفصیلی رپورٹ)', 'en' => 'Attendance Register (Detailed Report)'],
'week' => ['ur' => 'ہفتہ', 'en' => 'Week'],
'month' => ['ur' => 'مہینہ', 'en' => 'Month'],
'year' => ['ur' => 'سال', 'en' => 'Year'],
'filter' => ['ur' => 'فلٹر', 'en' => 'Filter'],
'total_presents' => ['ur' => 'کل حاضریاں', 'en' => 'Total Presents'],
'total_absents' => ['ur' => 'کل غیر حاضریاں', 'en' => 'Total Absents'],
'total_leaves' => ['ur' => 'کل رخصت', 'en' => 'Total Leaves'],
'total_sick' => ['ur' => 'کل بیمار', 'en' => 'Total Sick'],
'detailed_record' => ['ur' => 'تفصیلی ریکارڈ', 'en' => 'Detailed Record'],

'hifz_daily_perf' => ['ur' => 'حفظ (روزانہ کی کارکردگی)', 'en' => 'Hifz (Daily Performance)'],
'select_student' => ['ur' => 'طالب علم منتخب کریں', 'en' => 'Select Student'],
'sabaq_new' => ['ur' => 'سبق (نیا)', 'en' => 'Sabaq (New)'],
'select_parah' => ['ur' => 'پارہ منتخب کریں', 'en' => 'Select Parah'],
'lines_1_15' => ['ur' => 'لائنیں (1-15)', 'en' => 'Lines (1-15)'],
'sabqi_prev' => ['ur' => 'سبقی (پیچھے کا)', 'en' => 'Sabqi (Previous)'],
'qty_pages' => ['ur' => 'مقدار (صفحات/پاؤ)', 'en' => 'Quantity (Pages)'],
'manzil_solid' => ['ur' => 'منزل (پختہ)', 'en' => 'Manzil (Solid)'],
'parahs' => ['ur' => 'پارہ(ے)', 'en' => 'Parah(s)'],
'listener_teacher' => ['ur' => 'سننے والا استاذ', 'en' => 'Listener Teacher'],
'submit_entry' => ['ur' => 'اندراج کریں', 'en' => 'Submit Entry'],
'today_perf_record' => ['ur' => 'آج کی کارکردگی کا ریکارڈ', 'en' => 'Today\'s Performance Record'],
'sabaq' => ['ur' => 'سبق', 'en' => 'Sabaq'],
'sabqi' => ['ur' => 'سبقی', 'en' => 'Sabqi'],
'manzil' => ['ur' => 'منزل', 'en' => 'Manzil'],
'teacher' => ['ur' => 'استاذ', 'en' => 'Teacher'],
'hifz_detailed_report' => ['ur' => 'حفظ - تفصیلی رپورٹ', 'en' => 'Hifz - Detailed Report'],
'select_month' => ['ur' => 'مہینہ منتخب کریں', 'en' => 'Select Month'],
'view_report' => ['ur' => 'رپورٹ دیکھیں', 'en' => 'View Report'],
'student' => ['ur' => 'طالب علم', 'en' => 'Student'],
'academic_report_general' => ['ur' => 'تعلیمی رپورٹ (جنرل)', 'en' => 'Academic Report (General)'],
'subject' => ['ur' => 'مضمون', 'en' => 'Subject'],
'obtained_marks' => ['ur' => 'حاصل کردہ نمبر', 'en' => 'Obtained Marks'],
'total_marks' => ['ur' => 'کل نمبر', 'en' => 'Total Marks'],
'remarks' => ['ur' => 'تبصرہ / ریمارکس', 'en' => 'Remarks'],
'btn_print' => ['ur' => 'پرنٹ کریں', 'en' => 'Print'],
'type_here' => ['ur' => 'یہاں لکھیں...', 'en' => 'Type here...'],
'hifz_success_msg' => ['ur' => 'حفظ کا ریکارڈ کامیابی سے شامل کر دیا گیا۔', 'en' => 'Hifz record added successfully.'],

'users_perm_mgmt' => ['ur' => 'یوزرز اور پرمیشنز کا انتظام', 'en' => 'Users & Permissions Management'],
'add_new_user' => ['ur' => 'نیا یوزر شامل کریں', 'en' => 'Add New User'],
'name' => ['ur' => 'نام', 'en' => 'Name'],
'username' => ['ur' => 'یوزر نیم', 'en' => 'Username'],
'password' => ['ur' => 'پاسورڈ', 'en' => 'Password'],
'pwd_leave_blank' => ['ur' => 'تبدیل نہ کرنے کی صورت میں خالی چھوڑیں', 'en' => 'Leave blank if not changing'],
'role' => ['ur' => 'رول', 'en' => 'Role'],
'role_admin' => ['ur' => 'ایڈمن', 'en' => 'Admin'],
'role_nazim' => ['ur' => 'ناظم', 'en' => 'Nazim'],
'role_teacher' => ['ur' => 'استاذ', 'en' => 'Teacher'],
'permissions' => ['ur' => 'پرمیشنز', 'en' => 'Permissions'],
'admin_perm_notice' => ['ur' => 'ایڈمن کو خود بخود تمام پرمیشنز حاصل ہوتی ہیں۔', 'en' => 'Admins automatically have all permissions.'],
'clear_form' => ['ur' => 'فارم کلیئر کریں', 'en' => 'Clear Form'],
'current_users_staff' => ['ur' => 'موجودہ یوزرز اور عملہ', 'en' => 'Current Users & Staff'],
'action_col' => ['ur' => 'ایکشن', 'en' => 'Action'],
'all_perms' => ['ur' => 'تمام اختیارات', 'en' => 'All Permissions'],
'confirm_del_user' => ['ur' => 'کیا واقعی یہ یوزر حذف کرنا چاہتے ہیں؟', 'en' => 'Are you sure you want to delete this user?'],
'teachers_record' => ['ur' => 'اساتذہ کا ریکارڈ', 'en' => 'Teachers Record'],
'add_new_teacher' => ['ur' => 'نیا استاذ / سٹاف شامل کریں', 'en' => 'Add New Teacher/Staff'],
'phone_number' => ['ur' => 'فون نمبر', 'en' => 'Phone Number'],
'address' => ['ur' => 'پتہ', 'en' => 'Address'],
'salary' => ['ur' => 'تنخواہ', 'en' => 'Salary'],
'joining_date' => ['ur' => 'تاریخ شمولیت', 'en' => 'Joining Date'],
'current_teachers_list' => ['ur' => 'موجودہ اساتذہ کی فہرست', 'en' => 'Current Teachers List'],
'cnic' => ['ur' => 'شناختی کارڈ نمبر', 'en' => 'CNIC'],
'students_record' => ['ur' => 'طلباء کا ریکارڈ', 'en' => 'Students Record'],
'add_new_student' => ['ur' => 'نیا طالب علم شامل کریں', 'en' => 'Add New Student'],
'edit_delete' => ['ur' => 'ایڈٹ / حذف', 'en' => 'Edit / Delete'],
'btn_edit' => ['ur' => 'ایڈٹ', 'en' => 'Edit'],
'btn_delete' => ['ur' => 'حذف', 'en' => 'Delete'],
'dob' => ['ur' => 'تاریخ پیدائش', 'en' => 'Date of Birth'],
'class' => ['ur' => 'کلاس', 'en' => 'Class'],
'father_name' => ['ur' => 'ولدیت', 'en' => 'Father\'s Name'],
'admission_date' => ['ur' => 'داخلہ تاریخ', 'en' => 'Admission Date'],
'admission_no' => ['ur' => 'داخلہ نمبر', 'en' => 'Admission No'],
'fee_model' => ['ur' => 'فیس ماڈل', 'en' => 'Fee Model'],
'monthly_fee' => ['ur' => 'ماہانہ فیس', 'en' => 'Monthly Fee'],
'photo' => ['ur' => 'تصویر', 'en' => 'Photo'],
'current_students_list' => ['ur' => 'موجودہ طلباء کی فہرست', 'en' => 'Current Students List'],
'print_list' => ['ur' => 'پرنٹ لسٹ', 'en' => 'Print List'],
'export_excel' => ['ur' => 'ایکسپورٹ', 'en' => 'Export to Excel'],
'confirm_del_record' => ['ur' => 'کیا واقعی یہ ریکارڈ حذف کرنا چاہتے ہیں؟', 'en' => 'Are you sure you want to delete this record?'],
'classes_mgmt' => ['ur' => 'کلاسز کا انتظام', 'en' => 'Classes Management'],
'add_new_class' => ['ur' => 'نئی کلاس شامل کریں', 'en' => 'Add New Class'],
'class_name' => ['ur' => 'کلاس کا نام', 'en' => 'Class Name'],
'select_teacher' => ['ur' => 'استاذ منتخب کریں', 'en' => 'Select Teacher'],
'current_classes_list' => ['ur' => 'موجودہ کلاسز کی فہرست', 'en' => 'Current Classes List'],
'student_type' => ['ur' => 'طالب علم کی قسم', 'en' => 'Student Type'],
'type_boarder' => ['ur' => 'رہائشی', 'en' => 'Boarder (Resident)'],
'type_day_scholar' => ['ur' => 'غیر رہائشی', 'en' => 'Day Scholar'],

'dash_title' => ['ur' => 'مدرسہ ڈیش بورڈ', 'en' => 'Madrasa Dashboard'],
'welcome' => ['ur' => 'خوش آمدید', 'en' => 'Welcome'],
'quick_actions' => ['ur' => 'فوری کارروائیاں', 'en' => 'Quick Actions'],
'quick_admission' => ['ur' => 'نیا داخلہ', 'en' => 'New Admission'],
'quick_fee' => ['ur' => 'فیس وصولی', 'en' => 'Fee Collection'],
'quick_attendance' => ['ur' => 'حاضری لگائیں', 'en' => 'Mark Attendance'],
'quick_exam' => ['ur' => 'امتحانی نتیجہ', 'en' => 'Exam Result'],
'quick_complaint' => ['ur' => 'نئی شکایت', 'en' => 'New Complaint'],
'vital_stats' => ['ur' => 'اہم معلومات', 'en' => 'Vital Statistics'],
'dash_active_students' => ['ur' => 'فعال طلباء', 'en' => 'Active Students'],
'dash_active_teachers' => ['ur' => 'اساتذہ و عملہ', 'en' => 'Teachers & Staff'],
'dash_monthly_fee' => ['ur' => 'اس ماہ کی فیس وصولی', 'en' => 'Monthly Fee Collection'],
'dash_pending_req' => ['ur' => 'زیر التواء درخواستیں', 'en' => 'Pending Requests'],
'recent_activities' => ['ur' => 'حالیہ سرگرمیاں', 'en' => 'Recent Activities'],
'date' => ['ur' => 'تاریخ', 'en' => 'Date'],
'description' => ['ur' => 'تفصیل', 'en' => 'Description'],
'action' => ['ur' => 'کارروائی', 'en' => 'Action'],
'btn_approve' => ['ur' => 'منظور کریں', 'en' => 'Approve'],
'btn_reject' => ['ur' => 'مسترد کریں', 'en' => 'Reject'],
'save' => ['ur' => 'محفوظ کریں', 'en' => 'Save'],
'add_new_notice' => ['ur' => 'نیا نوٹس شامل کریں', 'en' => 'Add New Notice'],
'title' => ['ur' => 'عنوان', 'en' => 'Title'],
'no_record' => ['ur' => 'کوئی ریکارڈ موجود نہیں', 'en' => 'No Record Found'],
'status' => ['ur' => 'کیفیت', 'en' => 'Status'],
'status_pending' => ['ur' => 'زیر التوا', 'en' => 'Pending'],
'status_approved' => ['ur' => 'منظور شدہ', 'en' => 'Approved'],
'status_rejected' => ['ur' => 'مسترد', 'en' => 'Rejected'],
'exams' => ['ur' => 'امتحانات (نتیجہ)', 'en' => 'Exams (Result)'],

'nav_home' => ['ur' => 'ہوم', 'en' => 'Home'],
'nav_about' => ['ur' => 'ادارے کا تعارف', 'en' => 'About Us'],
'nav_depts' => ['ur' => 'شعبہ جات', 'en' => 'Departments'],
'nav_contact' => ['ur' => 'رابطہ', 'en' => 'Contact'],
'nav_login' => ['ur' => 'لاگ ان', 'en' => 'Login'],
'nav_dashboard' => ['ur' => 'ڈیش بورڈ', 'en' => 'Dashboard'],
'hero_subtitle' => ['ur' => 'تعمیرِ ملت کا عظیم قلعہ', 'en' => 'The Great Fortress of Nation Building'],
'hero_desc' => ['ur' => 'قرآن، تجوید اور حسن صوت کے ذریعے انسانیت کی اخلاقی و علمی آبیاری', 'en' => 'Nurturing humanity morally and intellectually through Quran, Tajweed, and beautiful recitation.'],
'hero_btn_admission' => ['ur' => 'داخلہ شروع ہے', 'en' => 'Admissions Open'],
'hero_btn_movement' => ['ur' => 'تحریکِ علم', 'en' => 'Knowledge Movement'],
'marquee_latest' => ['ur' => 'تازہ ترین', 'en' => 'Latest'],
'stat_students' => ['ur' => 'کل طلباء', 'en' => 'Total Students'],
'stat_teachers' => ['ur' => 'ماہر اساتذہ', 'en' => 'Expert Teachers'],
'stat_huffaz' => ['ur' => 'حفاظِ کرام', 'en' => 'Huffaz (Graduates)'],
'stat_classes' => ['ur' => 'کل کلاسز', 'en' => 'Active Classes'],
'stat_expenses' => ['ur' => 'اس ماہ کے اخراجات', 'en' => 'Monthly Expenses'],
'about_title' => ['ur' => 'علمِ دین کی ترویج کا روشن سفر', 'en' => 'The Bright Journey of Promoting Religious Knowledge'],
'about_desc_1' => ['ur' => 'قرآن، تجوید اور حسن صوت کا ایک معتبر علمی مرکز ہے، جہاں روایتی دینی تعلیم کو عصرِ حاضر کے تقاضوں سے ہم آہنگ کیا گیا ہے۔', 'en' => 'It is a reliable center for Quran, Tajweed, and beautiful recitation, where traditional religious education is aligned with modern needs.'],
'about_digital' => ['ur' => 'ڈیجیٹل مینجمنٹ سسٹم', 'en' => 'Digital Management System'],
'about_digital_desc' => ['ur' => 'ادارے نے اپنے تعلیمی و انتظامی نظام کو مکمل طور پر ڈیجیٹلائز کر دیا ہے۔ اس ایپ میں دو اہم پینلز موجود ہیں:', 'en' => 'The institution has fully digitized its educational and administrative systems. This app features two main panels:'],
'about_admin_panel' => ['ur' => 'ایڈمن و ٹیچر پینل:', 'en' => 'Admin & Teacher Panel:'],
'about_admin_desc' => ['ur' => 'طلباء کی حاضری، روزانہ کا سبق (حفظ و ناظرہ)، امتحانات اور فیس کا ریکارڈ باآسانی مرتب کریں۔', 'en' => 'Easily manage student attendance, daily lessons (Hifz & Nazra), exams, and fee records.'],
'about_parent_panel' => ['ur' => 'والدین پینل:', 'en' => 'Parent Panel:'],
'about_parent_desc' => ['ur' => 'والدین گھر بیٹھے اپنے بچوں کی کارکردگی، حاضری، رپورٹس اور فیس دیکھیں، نیز آن لائن چھٹی کی درخواست بھی کریں۔', 'en' => 'Parents can view their children\'s performance, attendance, reports, and fees from home, and apply for leave online.'],
'about_wifaq' => ['ur' => 'وفاق المدارس الحاق', 'en' => 'Affiliated with Wifaq-ul-Madaris'],
'about_hostel' => ['ur' => 'محفوظ رہائشی نظام', 'en' => 'Secure Boarding System'],
'depts_title' => ['ur' => 'نمایاں شعبہ جات', 'en' => 'Featured Departments'],
'dept_hifz' => ['ur' => 'حفظ قرآن کریم', 'en' => 'Hifz-e-Quran'],
'dept_hifz_desc' => ['ur' => 'قرآن مجید کو مکمل تجوید و لحن کے ساتھ حفظ کرنے کا شعبہ۔', 'en' => 'Memorization of the Holy Quran with complete Tajweed and melody.'],
'dept_nazra' => ['ur' => 'ناظرہ قرآن', 'en' => 'Nazra Quran'],
'dept_nazra_desc' => ['ur' => 'تجوید و مخارج کی درستی کے ساتھ قرآن پاک کی تلاوت۔', 'en' => 'Recitation of the Holy Quran with correct pronunciation and Tajweed.'],
'dept_qirat' => ['ur' => 'حسنِ صوت و قرات', 'en' => 'Beautiful Recitation & Qirat'],
'dept_qirat_desc' => ['ur' => 'مختلف مقاماتِ قرآنیہ میں مہارت حاصل کرنے کا خصوصی کورس۔', 'en' => 'A special course to master various Quranic Maqamat.'],
'btn_details' => ['ur' => 'تفصیلات', 'en' => 'Details'],
'service_dars' => ['ur' => 'درسِ نظامی (عالم کورس)', 'en' => 'Dars-e-Nizami (Aalim Course)'],
'service_dars_desc' => ['ur' => 'مکمل وفاق المدارس کے نصاب کے مطابق۔', 'en' => 'According to the complete Wifaq-ul-Madaris syllabus.'],
'service_modern' => ['ur' => 'عصری تعلیم', 'en' => 'Modern Education'],
'service_modern_desc' => ['ur' => 'پرائمری سے مڈل تک معیاری عصری تعلیم کا مربوط نظام۔', 'en' => 'An integrated system of quality modern education from primary to middle.'],
'audio_title' => ['ur' => 'سنیں ہمارے طلباء کی خوبصورت تلاوت', 'en' => 'Listen to our students\' beautiful recitation'],
'contact_title' => ['ur' => 'رابطہ کریں', 'en' => 'Contact Us'],
'contact_phone' => ['ur' => 'فون نمبر', 'en' => 'Phone Number'],
'contact_email' => ['ur' => 'ای میل', 'en' => 'Email Address'],
'contact_address' => ['ur' => 'پتہ', 'en' => 'Address'],
'contact_placeholder' => ['ur' => 'اپنا پیغام لکھیں', 'en' => 'Write your message'],
'contact_send' => ['ur' => 'پیغام بھیجیں', 'en' => 'Send Message'],
'footer_motto' => ['ur' => 'بامقصد تعلیم، روشن مستقبل', 'en' => 'Purposeful Education, Bright Future'],
'footer_links' => ['ur' => 'فوری لنکس', 'en' => 'Quick Links'],
'footer_admission' => ['ur' => 'داخلہ فارم', 'en' => 'Admission Form'],
'footer_contact_info' => ['ur' => 'رابطہ کی معلومات', 'en' => 'Contact Information'],
'footer_rights' => ['ur' => 'جملہ حقوق محفوظ ہیں۔', 'en' => 'All Rights Reserved.'],
'footer_dev' => ['ur' => 'تیار کردہ از', 'en' => 'Developed by'],

'only_review' => ['ur' => 'صرف جائزہ (ٹیسٹ)', 'en' => 'Only Review (Test)'],
    // --- 1. General (عام الفاظ) ---
    'app_name' => ['ur' => 'مدرسہ اشرف المدارس', 'en' => 'Madrasa Ashraf-ul-Madaris'],
    'dashboard' => ['ur' => 'ڈیش بورڈ', 'en' => 'Dashboard'],
    'home_page' => ['ur' => 'ہوم', 'en' => 'Home'],
    'login' => ['ur' => 'لاگ ان (داخل ہوں)', 'en' => 'Login'],
    'logout' => ['ur' => 'لاگ آؤٹ (باہر نکلیں)', 'en' => 'Logout'],
    'profile' => ['ur' => 'پاسورڈ کی تبدیلی', 'en' => 'Change Password'],
    'welcome' => ['ur' => 'خوش آمدید', 'en' => 'Welcome'],
    'print' => ['ur' => 'پرنٹ کریں', 'en' => 'Print'],
    'search' => ['ur' => 'تلاش کریں', 'en' => 'Search'],
    'save' => ['ur' => 'محفوظ کریں', 'en' => 'Save'],
    'select' => ['ur' => 'منتخب کریں', 'en' => 'Select'],
    'date' => ['ur' => 'تاریخ', 'en' => 'Date'],
    'date_from' => ['ur' => 'تاریخ (ابتداء)', 'en' => 'Date From'],
    'date_to' => ['ur' => 'تاریخ (انتہاء)', 'en' => 'Date To'],
    'details' => ['ur' => 'تفصیلات', 'en' => 'Details'],
    'action' => ['ur' => 'کارروائی / ایکشن', 'en' => 'Action'],
    'status' => ['ur' => 'کیفیت / سٹیٹس', 'en' => 'Status'],
    'no_record' => ['ur' => 'کوئی ریکارڈ موجود نہیں', 'en' => 'No Record Found'],
    'serial' => ['ur' => 'نمبر شمار', 'en' => 'Sr.'],

    // --- 2. Sidebar Menu (مینیو) ---
    'requests' => ['ur' => 'درخواستیں و شکایات', 'en' => 'Requests & Complaints'],
    'notice_board' => ['ur' => 'نوٹس بورڈ (اعلانات)', 'en' => 'Notice Board'],
    'students' => ['ur' => 'طلباء کا ریکارڈ', 'en' => 'Students'],
    'teachers' => ['ur' => 'اساتذہ کا ریکارڈ', 'en' => 'Teachers'],
    'hifz_daily' => ['ur' => 'حفظ: روزانہ کا اندراج', 'en' => 'Hifz Entry'],
    'hifz_report' => ['ur' => 'حفظ: تعلیمی رپورٹ', 'en' => 'Hifz Report'],
    'attendance' => ['ur' => 'حاضری (رجسٹر)', 'en' => 'Attendance'],
    'attendance_report' => ['ur' => 'حاضری کی رپورٹ', 'en' => 'Attendance Report'],
    'finance' => ['ur' => 'آمدن و خرچ (اکاؤنٹس)', 'en' => 'Finance / Accounts'],
    'finance_report' => ['ur' => 'مالیاتی رپورٹ', 'en' => 'Finance Report'],
    'complaint_entry' => ['ur' => 'شکایات کا اندراج', 'en' => 'Add Complaint'],
    'exams' => ['ur' => 'امتحانات (نتیجہ)', 'en' => 'Exams'],
    'settings' => ['ur' => 'سافٹ ویئر سیٹنگ', 'en' => 'Settings'],
    
    // --- 3. Student & Parent Panel (طلباء و والدین) ---
    'student' => ['ur' => 'طالب علم', 'en' => 'Student'],
    'father_name' => ['ur' => 'ولدیت', 'en' => 'Father Name'],
    'admission_no' => ['ur' => 'داخلہ نمبر', 'en' => 'Admission No'],
    'my_report' => ['ur' => 'میری تعلیمی رپورٹ', 'en' => 'My Report'],
    'pay_fee' => ['ur' => 'مدرسہ کے ساتھ تعاون کریں', 'en' => 'Cooperate with Madrasa'],
    'apply_leave' => ['ur' => 'چھٹی کی درخواست', 'en' => 'Apply Leave'],
    'parent_complaint' => ['ur' => 'اصلاحی شکایت درج کریں', 'en' => 'Register Complaint'],

    // --- 4. Hifz Module (شعبہ حفظ) ---
    'sabaq_section' => ['ur' => 'سبق (نیا)', 'en' => 'Sabaq (New)'],
    'sabqi_section' => ['ur' => 'سبقی (پیچھے کا سبق)', 'en' => 'Sabqi (Revision)'],
    'manzil_section' => ['ur' => 'منزل (پختہ)', 'en' => 'Manzil (Juz)'],
    'parah' => ['ur' => 'پارہ', 'en' => 'Parah'],
    'lines' => ['ur' => 'لائنیں', 'en' => 'Lines'],
    'amount' => ['ur' => 'مقدار', 'en' => 'Amount'],
    'listener' => ['ur' => 'سننے والا (استاد)', 'en' => 'Listener'],
    
    // Dropdown Items (Qualities)
    'excellent' => ['ur' => 'ممتاز', 'en' => 'Excellent'],
    'good' => ['ur' => 'بہتر', 'en' => 'Good'],
    'weak' => ['ur' => 'کمزور', 'en' => 'Weak'],
    
    // Dropdown Items (Quantities)
    'quarter' => ['ur' => 'پاؤ (1/4)', 'en' => 'Quarter'],
    'half' => ['ur' => 'آدھا (1/2)', 'en' => 'Half'],
    'three_quarter' => ['ur' => 'تین پاؤ (3/4)', 'en' => 'Three Quarter'],
    'full' => ['ur' => 'مکمل', 'en' => 'Full'],

    // --- 5. Attendance Module (حاضری) ---
    'attendance_reg' => ['ur' => 'رجسٹر حاضری', 'en' => 'Attendance Register'],
    'save_attendance' => ['ur' => 'حاضری محفوظ کریں', 'en' => 'Save Attendance'],
    'morning' => ['ur' => 'صبح', 'en' => 'Morning'],
    'noon' => ['ur' => 'ظہر', 'en' => 'Noon'],
    'evening' => ['ur' => 'شام (عصر/مغرب)', 'en' => 'Evening'],
    'night' => ['ur' => 'رات (عشاء)', 'en' => 'Night'],
    
    'status_p' => ['ur' => 'حاضر', 'en' => 'Present'],
    'status_a' => ['ur' => 'غیر حاضر', 'en' => 'Absent'],
    'status_l' => ['ur' => 'رخصت', 'en' => 'Leave'],
    'status_s' => ['ur' => 'بیمار', 'en' => 'Sick'],
    
    'delay' => ['ur' => 'تاخیر', 'en' => 'Delay'],
    'late_mins' => ['ur' => 'منٹ تاخیر', 'en' => 'mins late'],
    'on_time' => ['ur' => 'بلا تاخیر', 'en' => 'On Time'],

    // --- 6. Finance Module (مالیات) ---
    'income' => ['ur' => 'آمدن', 'en' => 'Income'],
    'expense' => ['ur' => 'خرچ', 'en' => 'Expense'],
    'category' => ['ur' => 'مد / کیٹیگری', 'en' => 'Category'],
    'description' => ['ur' => 'تفصیل', 'en' => 'Description'],
    'screenshot' => ['ur' => 'رسید / سکرین شاٹ', 'en' => 'Receipt/Screenshot'],
    'approved' => ['ur' => 'منظور شدہ', 'en' => 'Approved'],
    'rejected' => ['ur' => 'مسترد', 'en' => 'Rejected'],
    'pending' => ['ur' => 'زیر التواء', 'en' => 'Pending'],

    // --- 7. Complaints & Notes (شکایات) ---
    'complaints_section' => ['ur' => 'ہفتہ وار ادارتی نوٹ / شکایات', 'en' => 'Weekly Notes / Complaints'],
    'attention_required' => ['ur' => 'توجہ فرمائیں', 'en' => 'Attention Required'],
    'good_behavior' => ['ur' => 'ماشاءاللہ! لا قوۃ الا باللہ', 'en' => 'Mashallah! Excellent'],
    'no_complaint_msg' => ['ur' => 'اس دوران طالب علم کے خلاف کوئی شکایت نہیں ہے۔ ادارہ بچے کے اچھے اخلاق اور تعلیمی رویے کی قدر کرتا ہے۔', 'en' => 'No complaints recorded during this period. The institution appreciates the student\'s good behavior.'],
    // --- Hifz Quantities & Labels (یہ نئے الفاظ ڈکشنری میں شامل کریں) ---
'lines_verses' => ['ur' => 'لائنیں / آیات', 'en' => 'Lines / Verses'],
'select_qty' => ['ur' => 'مقدار منتخب کریں', 'en' => 'Select Quantity'],

// Manzil Dropdown Items
'q_1_4' => ['ur' => 'پاؤ (1/4) پارہ', 'en' => 'Quarter (1/4)'],
'q_1_2' => ['ur' => 'آدھا (1/2) پارہ', 'en' => 'Half (1/2)'],
'q_3_4' => ['ur' => 'تین پاؤ (3/4) پارہ', 'en' => 'Three Quarter (3/4)'],
'q_1' => ['ur' => '1 مکمل پارہ', 'en' => '1 Full Parah'],
'q_1_5' => ['ur' => 'ڈیڑھ (1.5) پارہ', 'en' => '1.5 Parah'],
'q_2' => ['ur' => '2 پارے', 'en' => '2 Parahs'],
'q_2_5' => ['ur' => 'ڈھائی (2.5) پارے', 'en' => '2.5 Parahs'],
'q_3' => ['ur' => '3 پارے', 'en' => '3 Parahs'],
'recent_admissions' => ['ur' => 'حالیہ داخلے', 'en' => 'Recent Admissions'],
'quick_links' => ['ur' => 'فوری روابط', 'en' => 'Quick Links'],
'student_id' => ['ur' => 'طالب علم کوڈ', 'en' => 'Student ID'],
'admission_date' => ['ur' => 'تاریخِ داخلہ', 'en' => 'Admission Date'],
'reversed' => ['ur' => 'تنسیخ شدہ', 'en' => 'Reversed'],
'cancelled' => ['ur' => 'منسوخ شدہ', 'en' => 'Cancelled'],
'sabqi' => ['ur' => 'سبقی', 'en' => 'Sabqi (Revision)'],
'on_time' => ['ur' => 'بروقت', 'en' => 'On Time'],
'teachers_record' => ['ur' => 'اساتذہ ریکارڈ', 'en' => 'Teachers Record'],
'attendance_register' => ['ur' => 'حاضری رجسٹر', 'en' => 'Attendance Register'],
];

function t($key) { 
    global $lang, $trans; 
    // اگر لفظ ڈکشنری میں ہے تو واپس کرو، ورنہ وہی انگریزی لفظ دکھا دو تاکہ ایرر نہ آئے
    $cur_lang = !empty($lang) ? $lang : ($_SESSION['lang'] ?? ($_COOKIE['app_language'] ?? 'ur'));
    if (isset($trans[$key][$cur_lang])) {
        return $trans[$key][$cur_lang];
    }
    $human = ucwords(str_replace('_', ' ', (string)$key));
    if ($cur_lang === 'ur') {
        $fallbacks_ur = [
            'recent admissions' => 'حالیہ داخلے',
            'quick links' => 'فوری روابط',
            'student id' => 'طالب علم کوڈ',
            'admission date' => 'تاریخِ داخلہ',
            'attendance register' => 'حاضری رجسٹر',
            'teachers record' => 'اساتذہ ریکارڈ',
            'sabqi' => 'سبقی',
        ];
        return $fallbacks_ur[strtolower($human)] ?? $human;
    }
    return $human; 
}

// فنکشن: تاریخ کو اردو مہینوں میں دکھانے کے لیے
function translateDate($dateStr) {
    global $lang;
    if(!$dateStr) return '-';
    $timestamp = strtotime($dateStr);
    if($lang == 'en') return date('d M, Y', $timestamp);
    
    $months = [
        'Jan' => 'جنوری', 'Feb' => 'فروری', 'Mar' => 'مارچ', 'Apr' => 'اپریل',
        'May' => 'مئی', 'Jun' => 'جون', 'Jul' => 'جولائی', 'Aug' => 'اگست',
        'Sep' => 'ستمبر', 'Oct' => 'اکتوبر', 'Nov' => 'نومبر', 'Dec' => 'دسمبر'
    ];
    $en_month = date('M', $timestamp);
    $ur_month = $months[$en_month] ?? $en_month;
    return date('d', $timestamp) . ' ' . $ur_month . ' ' . date('Y', $timestamp);
}

if (!function_exists('ensure_column')) {
    function ensure_column(PDO $pdo, $table, $column, $definition) {
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $stmt->execute([$column]);
            if (!$stmt->fetch()) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            }
        } catch (Throwable $e) {}
    }
}

if (!function_exists('get_next_chart_code')) {
    function get_next_chart_code(PDO $pdo, $type, $tenant_id = null) {
        if ($tenant_id === null) $tenant_id = (int)get_current_tenant_id();
        $rawType = ucfirst(strtolower(trim((string)$type)));
        $prefix = 5;
        $start = 5001;
        if ($rawType === 'Asset') {
            $prefix = 1;
            $start = 1001;
        } elseif ($rawType === 'Liability') {
            $prefix = 2;
            $start = 2001;
        } elseif ($rawType === 'Equity') {
            $prefix = 3;
            $start = 3001;
        } elseif ($rawType === 'Revenue' || $rawType === 'Income') {
            $prefix = 4;
            $start = 4001;
        } elseif ($rawType === 'Expense') {
            $prefix = 5;
            $start = 5001;
        }

        $min = $prefix * 1000;
        $max = ($prefix * 1000) + 998;
        try {
            $stmt = $pdo->prepare("
                SELECT MAX(CAST(code AS UNSIGNED)) as max_code 
                FROM acc_chart_of_accounts 
                WHERE (is_deleted = 0 OR is_deleted IS NULL)
                AND tenant_id = ?
                AND code REGEXP '^[0-9]+$' 
                AND CAST(code AS UNSIGNED) >= ? 
                AND CAST(code AS UNSIGNED) <= ?
            ");
            $stmt->execute([$tenant_id, $min, $max]);
            $val = $stmt->fetchColumn();
            if ($val && (int)$val >= $start) {
                return (string)((int)$val + 1);
            }
        } catch (Throwable $e) {}
        return (string)$start;
    }
}

if (!function_exists('resequence_chart_of_accounts')) {
    function resequence_chart_of_accounts(PDO $pdo, $tenant_id = null) {
        if ($tenant_id === null) $tenant_id = (int)get_current_tenant_id();
        try {
            $types = [
                'Asset' => ['start' => 1001],
                'Liability' => ['start' => 2001],
                'Equity' => ['start' => 3001],
                'Revenue' => ['start' => 4001],
                'Expense' => ['start' => 5001]
            ];
            
            foreach ($types as $type => $conf) {
                $stmt = $pdo->prepare("
                    SELECT id, code, name 
                    FROM acc_chart_of_accounts 
                    WHERE (is_deleted = 0 OR is_deleted IS NULL)
                    AND tenant_id = ?
                    AND (type = ? OR (type = 'Income' AND ? = 'Revenue'))
                    AND code != '3999'
                    ORDER BY 
                        CASE 
                            WHEN code REGEXP '^[0-9]+$' THEN CAST(code AS UNSIGNED) 
                            ELSE 999999 
                        END ASC, id ASC
                ");
                $stmt->execute([$tenant_id, $type, $type]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $curr = $conf['start'];
                foreach ($rows as $r) {
                    $newCode = (string)$curr;
                    if ($r['code'] !== $newCode) {
                        $up = $pdo->prepare("UPDATE acc_chart_of_accounts SET code = ? WHERE id = ? AND tenant_id = ?");
                        $up->execute([$newCode, $r['id'], $tenant_id]);
                    }
                    $curr++;
                }
            }
        } catch (Throwable $e) {}
    }
}

function sync_all_heads_with_chart_and_departments(PDO $pdo, $tenant_id = null) {
    if ($tenant_id === null) $tenant_id = (int)get_current_tenant_id();
    try {
        ensure_column($pdo, 'students', 'monthly_fee', 'DECIMAL(10,2) DEFAULT 0');
        ensure_column($pdo, 'fee_submissions', 'date', 'DATE DEFAULT NULL');
        ensure_column($pdo, 'fee_submissions', 'submission_date', 'DATE DEFAULT NULL');

        // 1. Deduplicate acc_chart_of_accounts by normalized name for this tenant
        try {
            $dup_stmt = $pdo->prepare("
                SELECT LOWER(TRIM(name)) as norm_name, COUNT(*) as cnt, MIN(id) as keep_id 
                FROM acc_chart_of_accounts 
                WHERE (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = ? AND name IS NOT NULL AND TRIM(name) != '' 
                GROUP BY LOWER(TRIM(name)) 
                HAVING cnt > 1
            ");
            $dup_stmt->execute([$tenant_id]);
            if ($dup_stmt) {
                $dups = $dup_stmt->fetchAll(PDO::FETCH_ASSOC);
                $del_dup = $pdo->prepare("UPDATE acc_chart_of_accounts SET is_deleted = 1 WHERE LOWER(TRIM(name)) = ? AND id != ? AND tenant_id = ?");
                foreach ($dups as $dup) {
                    try {
                        $pdo->prepare("UPDATE acc_ledger_entries SET account_id = ? WHERE tenant_id = ? AND account_id IN (SELECT id FROM (SELECT id FROM acc_chart_of_accounts WHERE LOWER(TRIM(name)) = ? AND id != ? AND tenant_id = ?) AS tmp)")
                            ->execute([(int)$dup['keep_id'], $tenant_id, $dup['norm_name'], (int)$dup['keep_id'], $tenant_id]);
                    } catch (Throwable $eMove) {}
                    $del_dup->execute([$dup['norm_name'], (int)$dup['keep_id'], $tenant_id]);
                }
            }
        } catch (Throwable $e) {}

        // 1b. Repair any ledger entries pointing to soft-deleted accounts that have an active counterpart
        try {
            $pdo->prepare("
                UPDATE acc_ledger_entries e
                JOIN acc_chart_of_accounts d ON e.account_id = d.id AND d.is_deleted = 1 AND d.tenant_id = ?
                JOIN acc_chart_of_accounts a ON LOWER(TRIM(d.name)) = LOWER(TRIM(a.name)) AND (a.is_deleted = 0 OR a.is_deleted IS NULL) AND a.tenant_id = ?
                SET e.account_id = a.id
                WHERE e.tenant_id = ?
            ")->execute([$tenant_id, $tenant_id, $tenant_id]);
        } catch (Throwable $e) {}

        // 2. Deduplicate heads by normalized name for this tenant
        try {
            $dup_heads = $pdo->prepare("
                SELECT LOWER(TRIM(name)) as norm_name, COUNT(*) as cnt, MIN(id) as keep_id 
                FROM heads 
                WHERE (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = ? AND name IS NOT NULL AND TRIM(name) != '' 
                GROUP BY LOWER(TRIM(name)) 
                HAVING cnt > 1
            ");
            $dup_heads->execute([$tenant_id]);
            if ($dup_heads) {
                $dups = $dup_heads->fetchAll(PDO::FETCH_ASSOC);
                $del_dup_head = $pdo->prepare("UPDATE heads SET is_deleted = 1 WHERE LOWER(TRIM(name)) = ? AND id != ? AND tenant_id = ?");
                foreach ($dups as $dup) {
                    $del_dup_head->execute([$dup['norm_name'], (int)$dup['keep_id'], $tenant_id]);
                }
            }
        } catch (Throwable $e) {}

        $checkHead = $pdo->prepare("SELECT id FROM heads WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) AND tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1");
        $insertHead = $pdo->prepare("INSERT INTO heads (name, type, is_deleted, tenant_id) VALUES (?, ?, 0, ?)");

        $checkChart = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) AND tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1");
        $insertChart = $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, system_type, is_deleted, tenant_id) VALUES (?, ?, ?, 'Madrasa', 0, ?)");

        // 3. Sync acc_chart_of_accounts -> heads (Only Revenue and Expense accounts)
        try {
            $pdo->prepare("UPDATE heads SET is_deleted = 1 WHERE tenant_id = ? AND LOWER(TRIM(name)) IN ('cash in hand', 'bank account', 'accounts receivable (students)', 'accounts payable', 'general fund')")->execute([$tenant_id]);
        } catch (Throwable $e) {}

        try {
            $chart_stmt = $pdo->prepare("SELECT name, type FROM acc_chart_of_accounts WHERE (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = ?");
            $chart_stmt->execute([$tenant_id]);
            if ($chart_stmt && $checkHead && $insertHead) {
                $chart_accounts = $chart_stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($chart_accounts as $ca) {
                    $name = trim($ca['name'] ?? '');
                    if (empty($name)) continue;
                    
                    $rawType = strtolower(trim((string)($ca['type'] ?? '')));
                    $isRev = ($rawType === 'income' || strpos($rawType, 'revenue') !== false);
                    $isExp = ($rawType === 'expense');
                    if (!$isRev && !isExp) continue; // Skip Asset, Liability, Equity!
                    $type = $isRev ? 'Income' : 'Expense';
                    
                    $checkHead->execute([$name, $tenant_id]);
                    if (!$checkHead->fetch()) {
                        $insertHead->execute([$name, $type, $tenant_id]);
                    }
                }
            }
        } catch (Throwable $e) {}

        // 4. Sync heads -> acc_chart_of_accounts
        try {
            $heads_stmt = $pdo->prepare("SELECT id, name, type FROM heads WHERE (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = ?");
            $heads_stmt->execute([$tenant_id]);
            if ($heads_stmt && $checkChart && $insertChart) {
                $all_heads = $heads_stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($all_heads as $h) {
                    $name = trim($h['name'] ?? '');
                    if (empty($name)) continue;
                    
                    $rawType = strtolower(trim((string)($h['type'] ?? '')));
                    $isInc = ($rawType === 'income' || strpos($rawType, 'revenue') !== false);
                    $chartType = $isInc ? 'Revenue' : 'Expense';
                    
                    $checkChart->execute([$name, $tenant_id]);
                    if (!$checkChart->fetch()) {
                        try {
                            $seqCode = get_next_chart_code($pdo, $chartType, $tenant_id);
                            $insertChart->execute([$seqCode, $name, $chartType, $tenant_id]);
                        } catch (Throwable $e) {}
                    }
                }
            }
        } catch (Throwable $e) {}

        // 5. Sync departments -> heads & acc_chart_of_accounts (as Expense)
        try {
            $dept_stmt = $pdo->prepare("SELECT name FROM departments WHERE (status IS NULL OR status = 'Active') AND tenant_id = ?");
            $dept_stmt->execute([$tenant_id]);
            if ($dept_stmt && $checkHead && $insertHead) {
                $depts = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($depts as $d) {
                    $name = trim($d['name'] ?? '');
                    if (empty($name)) continue;
                    
                    $checkHead->execute([$name, $tenant_id]);
                    if (!$checkHead->fetch()) {
                        try {
                            $insertHead->execute([$name, 'Expense', $tenant_id]);
                        } catch (Throwable $e) {}
                    }

                    if ($checkChart && $insertChart) {
                        $checkChart->execute([$name, $tenant_id]);
                        if (!$checkChart->fetch()) {
                            try {
                                $seqCode = get_next_chart_code($pdo, 'Expense', $tenant_id);
                                $insertChart->execute([$seqCode, $name, 'Expense', $tenant_id]);
                            } catch (Throwable $e) {}
                        }
                    }
                }
            }
        } catch (Throwable $e) {}

        // 6. Ensure standard sequence across all accounts
        resequence_chart_of_accounts($pdo, $tenant_id);
    } catch (Throwable $e) {
        // Suppress error
    }
}

function localize_head_name($name, $lang = null) {
    if (!$lang) {
        global $lang;
        if (!$lang) $lang = $_SESSION['lang'] ?? 'ur';
    }
    $name = trim((string)$name);
    if (empty($name)) return '';

    static $toUr = [
        'sadaqah' => 'صدقہ',
        'donation' => 'عطیات',
        'donation / sadqa' => 'عطیات و صدقات',
        'fitrana' => 'فطرانہ',
        'qurbani skin' => 'کھالیں قربانی',
        'ushr' => 'عشر',
        'fee' => 'فیس',
        'fee income' => 'آمدن فیس',
        'aid' => 'امداد',
        'zakat' => 'زکوٰۃ',
        'misc income' => 'متفرق آمدن',
        'ration' => 'راشن',
        'electricity bill' => 'بجلی بل',
        'utility bills' => 'یوٹیلیٹی بلز',
        'maintenance' => 'مرمت',
        'construction' => 'تعمیرات',
        'stipends' => 'وظائف',
        'teacher salary' => 'اساتذہ کی تنخواہیں',
        'medical' => 'ادویات',
        'internet bill' => 'انٹرنیٹ بل',
        'rewards' => 'انعامات',
        'hospitality' => 'ضیافت',
        'mobile package' => 'موبائل پیکیج',
        'gas bill' => 'گیس بل',
        'misc expense' => 'متفرق خرچ',
        'cash in hand' => 'نقد رقم (کیش ان ہینڈ)',
        'bank account' => 'بینک اکاؤنٹ',
        'accounts receivable (students)' => 'طلبہ کے ذمہ واجبات',
        'accounts payable' => 'واجب الادا رقوم',
        'general fund' => 'جنرل فنڈ',
    ];

    static $toEn = [
        'صدقہ' => 'Sadaqah',
        'عطیات' => 'Donation',
        'عطیات و صدقات' => 'Donation / Sadqa',
        'فطرانہ' => 'Fitrana',
        'کھالیں قربانی' => 'Qurbani Skin',
        'عشر' => 'Ushr',
        'فیس' => 'Fee',
        'آمدن فیس' => 'Fee Income',
        'امداد' => 'Aid',
        'زکوٰۃ' => 'Zakat',
        'متفرق آمدن' => 'Misc Income',
        'راشن' => 'Ration',
        'بجلی بل' => 'Electricity Bill',
        'یوٹیلیٹی بلز' => 'Utility Bills',
        'مرمت' => 'Maintenance',
        'تعمیرات' => 'Construction',
        'وظائف' => 'Stipends',
        'اساتذہ کی تنخواہیں' => 'Teacher Salary',
        'ادویات' => 'Medical',
        'انٹرنیٹ بل' => 'Internet Bill',
        'انعامات' => 'Rewards',
        'ضیافت' => 'Hospitality',
        'موبائل پیکیج' => 'Mobile Package',
        'گیس بل' => 'Gas Bill',
        'متفرق خرچ' => 'Misc Expense',
        'نقد رقم (کیش ان ہینڈ)' => 'Cash in Hand',
        'بینک اکاؤنٹ' => 'Bank Account',
        'طلبہ کے ذمہ واجبات' => 'Accounts Receivable (Students)',
        'واجب الادا رقوم' => 'Accounts Payable',
        'جنرل فنڈ' => 'General Fund',
    ];

    if ($lang === 'ur') {
        $lower = strtolower($name);
        return $toUr[$lower] ?? $name;
    } else {
        return $toEn[$name] ?? ($toEn[strtolower($name)] ?? $name);
    }
}

function localize_acc_type($type, $lang = null) {
    if (!$lang) {
        global $lang;
        if (!$lang) $lang = $_SESSION['lang'] ?? 'ur';
    }
    $t = trim((string)$type);
    if ($lang === 'en') return $t;

    $map = [
        'Asset' => 'اثاثہ جات (Asset)',
        'Liability' => 'واجبات (Liability)',
        'Equity' => 'سرمایہ (Equity)',
        'Revenue' => 'آمدن (Revenue)',
        'Income' => 'آمدن (Income)',
        'Expense' => 'اخراجات (Expense)',
    ];
    return $map[$t] ?? $t;
}

if (!function_exists('ensure_phase1_tables')) {
    function ensure_phase1_tables(PDO $pdo) {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS teacher_leaves (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    teacher_id INT NOT NULL,
                    leave_type VARCHAR(50) NOT NULL DEFAULT 'اتفاقی',
                    start_date DATE NOT NULL,
                    end_date DATE NOT NULL,
                    reason TEXT,
                    status VARCHAR(20) NOT NULL DEFAULT 'Pending',
                    approved_by VARCHAR(100) DEFAULT NULL,
                    system_type VARCHAR(20) DEFAULT 'Madrasa',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    is_deleted TINYINT(1) DEFAULT 0,
                    is_synced TINYINT(1) DEFAULT 0,
                    INDEX (teacher_id),
                    INDEX (start_date),
                    INDEX (status),
                    INDEX (system_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            try { $pdo->exec("ALTER TABLE teacher_leaves ADD COLUMN system_type VARCHAR(20) DEFAULT 'Madrasa'"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE teacher_leaves ADD INDEX idx_system_type (system_type)"); } catch (Throwable $e) {}
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS activity_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT DEFAULT NULL,
                    user_name VARCHAR(100) DEFAULT NULL,
                    action_type VARCHAR(50) NOT NULL,
                    description TEXT NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX (action_type),
                    INDEX (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
        } catch (Throwable $e) {}
    }
}

if (!function_exists('log_activity')) {
    function log_activity(PDO $pdo, $action_type, $description, $user_id = null, $user_name = null) {
        try {
            ensure_phase1_tables($pdo);
            if ($user_id === null && isset($_SESSION['user_id'])) {
                $user_id = (int)$_SESSION['user_id'];
            }
            if ($user_name === null) {
                $user_name = $_SESSION['user_name'] ?? ($_SESSION['name'] ?? ($_SESSION['role'] ?? 'سسٹم'));
            }
            $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, user_name, action_type, description, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$user_id, $user_name, $action_type, $description]);
        } catch (Throwable $e) {}
    }
}

if (!function_exists('approve_teacher_leave')) {
    function approve_teacher_leave(PDO $pdo, $leave_id, $new_status, $approved_by = null) {
        try {
            ensure_phase1_tables($pdo);
            $stmt = $pdo->prepare("SELECT * FROM teacher_leaves WHERE id = ? LIMIT 1");
            $stmt->execute([$leave_id]);
            $leave = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$leave) return false;

            if ($approved_by === null) {
                $approved_by = $_SESSION['user_name'] ?? ($_SESSION['name'] ?? ($_SESSION['role'] ?? 'Admin'));
            }

            $update = $pdo->prepare("UPDATE teacher_leaves SET status = ?, approved_by = ?, updated_at = NOW() WHERE id = ?");
            $update->execute([$new_status, $approved_by, $leave_id]);

            $tId = (int)$leave['teacher_id'];
            $startDate = $leave['start_date'];
            $endDate = $leave['end_date'];
            $sysType = $leave['system_type'] ?? 'Madrasa';
            $tTenantId = (int)($leave['tenant_id'] ?? get_current_tenant_id());

            // Teacher Name
            $tName = 'استاذ';
            try {
                if ($sysType === 'Masjid') {
                    $tStmt = $pdo->prepare("SELECT name FROM maktab_teachers WHERE id = ? AND tenant_id = ? LIMIT 1");
                    $tStmt->execute([$tId, $tTenantId]);
                    $tName = $tStmt->fetchColumn();
                }
                if (empty($tName)) {
                    $tStmt = $pdo->prepare("SELECT name FROM teachers WHERE id = ? AND tenant_id = ? LIMIT 1");
                    $tStmt->execute([$tId, $tTenantId]);
                    $tName = $tStmt->fetchColumn() ?: 'استاذ';
                }
            } catch (Throwable $e) {}

            if ($new_status === 'Approved') {
                $current = strtotime($startDate);
                $last = strtotime($endDate);

                while ($current <= $last) {
                    $curDate = date('Y-m-d', $current);
                    
                    if ($sysType === 'Masjid') {
                        // 1. Update/Insert in maktab_teacher_attendance
                        try {
                            $checkM = $pdo->prepare("SELECT id FROM maktab_teacher_attendance WHERE teacher_id = ? AND date = ? AND tenant_id = ? LIMIT 1");
                            $checkM->execute([$tId, $curDate, $tTenantId]);
                            $mAttId = $checkM->fetchColumn();
                            if ($mAttId) {
                                $pdo->prepare("UPDATE maktab_teacher_attendance SET status = 'L', is_synced = 0 WHERE id = ? AND tenant_id = ?")->execute([$mAttId, $tTenantId]);
                            } else {
                                $pdo->prepare("INSERT INTO maktab_teacher_attendance (teacher_id, date, status, is_synced, tenant_id) VALUES (?, ?, 'L', 0, ?)")->execute([$tId, $curDate, $tTenantId]);
                            }
                        } catch (Throwable $eMaktabAtt) {}

                        // 2. Also record in attendance_teachers with system_type = 'Masjid'
                        $check = $pdo->prepare("SELECT id FROM attendance_teachers WHERE teacher_id = ? AND date = ? AND tenant_id = ? LIMIT 1");
                        $check->execute([$tId, $curDate, $tTenantId]);
                        $attId = $check->fetchColumn();
                        if ($attId) {
                            $pdo->prepare("UPDATE attendance_teachers SET status = 'L', remarks = 'منظور شدہ رخصت' WHERE id = ? AND tenant_id = ?")->execute([$attId, $tTenantId]);
                        } else {
                            $pdo->prepare("INSERT INTO attendance_teachers (teacher_id, date, status, late_minutes, remarks, system_type, tenant_id) VALUES (?, ?, 'L', 0, 'منظور شدہ رخصت', 'Masjid', ?)")->execute([$tId, $curDate, $tTenantId]);
                        }
                    } else {
                        // 1. Update/Insert in attendance_teachers (Madrasa)
                        $check = $pdo->prepare("SELECT id FROM attendance_teachers WHERE teacher_id = ? AND date = ? AND tenant_id = ? LIMIT 1");
                        $check->execute([$tId, $curDate, $tTenantId]);
                        $attId = $check->fetchColumn();
                        if ($attId) {
                            $pdo->prepare("UPDATE attendance_teachers SET status = 'L', remarks = 'منظور شدہ رخصت' WHERE id = ? AND tenant_id = ?")->execute([$attId, $tTenantId]);
                        } else {
                            $pdo->prepare("INSERT INTO attendance_teachers (teacher_id, date, status, late_minutes, remarks, system_type, tenant_id) VALUES (?, ?, 'L', 0, 'منظور شدہ رخصت', 'Madrasa', ?)")->execute([$tId, $curDate, $tTenantId]);
                        }

                        // 2. Update in teacher_period_attendance if records exist
                        try {
                            $pdo->prepare("UPDATE teacher_period_attendance SET status = 'L', remarks = 'منظور شدہ رخصت' WHERE teacher_id = ? AND date = ? AND tenant_id = ?")->execute([$tId, $curDate, $tTenantId]);
                        } catch (Throwable $ePeriod) {}
                    }

                    $current = strtotime('+1 day', $current);
                }

                log_activity($pdo, 'leave', "استاذ محترم {$tName} کی رخصت منظور کی گئی ({$sysType} - از {$startDate} تا {$endDate})", null, $approved_by);
            } else if ($new_status === 'Rejected') {
                log_activity($pdo, 'leave', "استاذ محترم {$tName} کی رخصت کی درخواست مسترد کی گئی ({$sysType})", null, $approved_by);
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('ensure_phase2_tables')) {
    function ensure_phase2_tables(PDO $pdo) {
        try {
            // 1. Admission Inquiries (Pipeline)
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS admission_inquiries (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    student_name VARCHAR(150) NOT NULL,
                    father_name VARCHAR(150) NOT NULL,
                    phone VARCHAR(50) DEFAULT '',
                    class_applied VARCHAR(100) DEFAULT '',
                    gender VARCHAR(20) DEFAULT 'Male',
                    address TEXT,
                    status VARCHAR(30) NOT NULL DEFAULT 'Pending',
                    interview_date DATE DEFAULT NULL,
                    interview_notes TEXT,
                    admin_notes TEXT,
                    system_type VARCHAR(20) DEFAULT 'Madrasa',
                    converted_student_id INT DEFAULT NULL,
                    is_deleted TINYINT(1) DEFAULT 0,
                    is_synced TINYINT(1) DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX (status),
                    INDEX (system_type),
                    INDEX (interview_date),
                    INDEX (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // 2. Library Books Catalog
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS lib_books (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    book_title VARCHAR(255) NOT NULL,
                    author VARCHAR(150) DEFAULT '',
                    category VARCHAR(100) DEFAULT 'عام',
                    isbn VARCHAR(100) DEFAULT '',
                    rack_number VARCHAR(50) DEFAULT '',
                    total_copies INT DEFAULT 1,
                    available_copies INT DEFAULT 1,
                    system_type VARCHAR(20) DEFAULT 'Madrasa',
                    is_deleted TINYINT(1) DEFAULT 0,
                    is_synced TINYINT(1) DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX (category),
                    INDEX (system_type),
                    INDEX (is_deleted)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // 3. Library Issue & Return Register
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS lib_issues (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    book_id INT NOT NULL,
                    borrower_type VARCHAR(50) DEFAULT 'student',
                    borrower_id INT NOT NULL,
                    borrower_name VARCHAR(150) DEFAULT '',
                    borrower_contact VARCHAR(50) DEFAULT '',
                    issue_date DATE NOT NULL,
                    due_date DATE NOT NULL,
                    return_date DATE DEFAULT NULL,
                    status VARCHAR(30) NOT NULL DEFAULT 'Issued',
                    fine_amount DECIMAL(10,2) DEFAULT 0.00,
                    remarks TEXT,
                    system_type VARCHAR(20) DEFAULT 'Madrasa',
                    is_deleted TINYINT(1) DEFAULT 0,
                    is_synced TINYINT(1) DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX (book_id),
                    INDEX (borrower_id),
                    INDEX (status),
                    INDEX (due_date),
                    INDEX (system_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // Columns for Quran edition and full admission fields
            ensure_column($pdo, 'students', 'quran_edition', "VARCHAR(30) DEFAULT '16 سطری'");
            ensure_column($pdo, 'maktab_students', 'quran_edition', "VARCHAR(30) DEFAULT '16 سطری'");
            ensure_column($pdo, 'admission_inquiries', 'quran_edition', "VARCHAR(30) DEFAULT '16 سطری'");
            ensure_column($pdo, 'admission_inquiries', 'admission_number', "VARCHAR(50) DEFAULT ''");
            ensure_column($pdo, 'admission_inquiries', 'admission_date', "DATE DEFAULT NULL");
            ensure_column($pdo, 'admission_inquiries', 'graduation_date', "DATE DEFAULT NULL");
            ensure_column($pdo, 'admission_inquiries', 'duration', "VARCHAR(50) DEFAULT ''");
            ensure_column($pdo, 'admission_inquiries', 'residence_status', "VARCHAR(50) DEFAULT ''");
            ensure_column($pdo, 'admission_inquiries', 'photo', "VARCHAR(255) DEFAULT ''");
        } catch (Throwable $e) {}
    }
}

if (!function_exists('issue_library_book')) {
    function issue_library_book(PDO $pdo, $book_id, $borrower_type, $borrower_id, $borrower_name, $borrower_contact, $due_date, $remarks = '', $system_type = 'Madrasa', $tenant_id = null) {
        if ($tenant_id === null) $tenant_id = (int)get_current_tenant_id();
        try {
            ensure_phase2_tables($pdo);
            $bStmt = $pdo->prepare("SELECT book_title, available_copies FROM lib_books WHERE id = ? AND tenant_id = ?");
            $bStmt->execute([$book_id, $tenant_id]);
            $book = $bStmt->fetch(PDO::FETCH_ASSOC);
            if (!$book || (int)$book['available_copies'] <= 0) {
                return ['success' => false, 'message' => 'یہ کتاب فی الوقت دستیاب نہیں ہے!'];
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO lib_issues (book_id, borrower_type, borrower_id, borrower_name, borrower_contact, issue_date, due_date, status, remarks, system_type, tenant_id) VALUES (?, ?, ?, ?, ?, CURDATE(), ?, 'Issued', ?, ?, ?)");
            $stmt->execute([$book_id, $borrower_type, $borrower_id, $borrower_name, $borrower_contact, $due_date, $remarks, $system_type, $tenant_id]);
            $issueId = $pdo->lastInsertId();

            $pdo->prepare("UPDATE lib_books SET available_copies = GREATEST(0, available_copies - 1) WHERE id = ? AND tenant_id = ?")->execute([$book_id, $tenant_id]);
            $pdo->commit();

            log_activity($pdo, 'library', "کتاب '{$book['book_title']}' جاری کی گئی برائے {$borrower_name} (واپسی تاریخ: {$due_date})");
            return ['success' => true, 'issue_id' => $issueId];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}

if (!function_exists('return_library_book')) {
    function return_library_book(PDO $pdo, $issue_id, $fine = 0, $remarks = '', $tenant_id = null) {
        if ($tenant_id === null) $tenant_id = (int)get_current_tenant_id();
        try {
            ensure_phase2_tables($pdo);
            $iStmt = $pdo->prepare("SELECT i.*, b.book_title FROM lib_issues i JOIN lib_books b ON i.book_id = b.id WHERE i.id = ? AND i.tenant_id = ?");
            $iStmt->execute([$issue_id, $tenant_id]);
            $issue = $iStmt->fetch(PDO::FETCH_ASSOC);
            if (!$issue) return ['success' => false, 'message' => 'ریکارڈ نہیں ملا!'];

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE lib_issues SET status = 'Returned', return_date = CURDATE(), fine_amount = ?, remarks = CONCAT(IFNULL(remarks,''), ' | ', ?) WHERE id = ? AND tenant_id = ?")
                ->execute([$fine, $remarks, $issue_id, $tenant_id]);
            $pdo->prepare("UPDATE lib_books SET available_copies = LEAST(total_copies, available_copies + 1) WHERE id = ? AND tenant_id = ?")->execute([$issue['book_id'], $tenant_id]);
            $pdo->commit();

            log_activity($pdo, 'library', "کتاب '{$issue['book_title']}' واپس جمع کر لی گئی از {$issue['borrower_name']}");
            return ['success' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}

if (!function_exists('ensure_phase3_4_tables')) {
    function ensure_phase3_4_tables(PDO $pdo) {
        try {
            // 1. Inventory Items
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS inventory_items (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    item_code VARCHAR(50) NOT NULL,
                    name VARCHAR(200) NOT NULL,
                    category VARCHAR(100) DEFAULT 'عام',
                    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    purchase_price DECIMAL(10,2) DEFAULT 0.00,
                    current_stock INT NOT NULL DEFAULT 0,
                    alert_threshold INT DEFAULT 5,
                    unit VARCHAR(30) DEFAULT 'عدد',
                    description TEXT,
                    system_type VARCHAR(20) DEFAULT 'Madrasa',
                    is_deleted TINYINT(1) DEFAULT 0,
                    is_synced TINYINT(1) DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX (category),
                    INDEX (system_type),
                    INDEX (is_deleted)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // 2. Inventory Sales
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS inventory_sales (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    sale_no VARCHAR(50) NOT NULL,
                    item_id INT NOT NULL,
                    item_name VARCHAR(200) NOT NULL,
                    student_id INT DEFAULT NULL,
                    customer_name VARCHAR(150) DEFAULT '',
                    quantity INT NOT NULL DEFAULT 1,
                    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    payment_mode VARCHAR(50) DEFAULT 'Cash',
                    voucher_id INT DEFAULT NULL,
                    date DATE NOT NULL,
                    remarks TEXT,
                    system_type VARCHAR(20) DEFAULT 'Madrasa',
                    created_by INT DEFAULT NULL,
                    is_deleted TINYINT(1) DEFAULT 0,
                    is_synced TINYINT(1) DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX (item_id),
                    INDEX (student_id),
                    INDEX (date),
                    INDEX (system_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // 3. Hostel Rooms
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS hostel_rooms (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    room_number VARCHAR(50) NOT NULL,
                    room_name VARCHAR(150) DEFAULT '',
                    capacity_beds INT NOT NULL DEFAULT 4,
                    monthly_fee DECIMAL(10,2) DEFAULT 0.00,
                    status VARCHAR(30) DEFAULT 'Active',
                    remarks TEXT,
                    system_type VARCHAR(20) DEFAULT 'Madrasa',
                    is_deleted TINYINT(1) DEFAULT 0,
                    is_synced TINYINT(1) DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX (system_type),
                    INDEX (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // 4. Hostel Allocations
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS hostel_allocations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    room_id INT NOT NULL,
                    student_id INT NOT NULL,
                    student_name VARCHAR(150) DEFAULT '',
                    bed_number VARCHAR(20) DEFAULT '',
                    allocation_date DATE NOT NULL,
                    vacating_date DATE DEFAULT NULL,
                    status VARCHAR(30) DEFAULT 'Occupied',
                    remarks TEXT,
                    system_type VARCHAR(20) DEFAULT 'Madrasa',
                    is_deleted TINYINT(1) DEFAULT 0,
                    is_synced TINYINT(1) DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX (room_id),
                    INDEX (student_id),
                    INDEX (status),
                    INDEX (system_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // 5. Job Postings
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS job_postings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    title VARCHAR(200) NOT NULL,
                    department VARCHAR(100) DEFAULT '',
                    job_type VARCHAR(50) DEFAULT 'Full-time',
                    requirements TEXT,
                    salary_range VARCHAR(100) DEFAULT '',
                    deadline DATE DEFAULT NULL,
                    status VARCHAR(30) DEFAULT 'Open',
                    system_type VARCHAR(20) DEFAULT 'Madrasa',
                    is_deleted TINYINT(1) DEFAULT 0,
                    is_synced TINYINT(1) DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX (status),
                    INDEX (system_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // 6. Job Applicants
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS job_applicants (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    job_id INT NOT NULL,
                    applicant_name VARCHAR(150) NOT NULL,
                    father_name VARCHAR(150) DEFAULT '',
                    phone VARCHAR(50) DEFAULT '',
                    email VARCHAR(100) DEFAULT '',
                    qualification VARCHAR(200) DEFAULT '',
                    experience VARCHAR(200) DEFAULT '',
                    resume_path VARCHAR(255) DEFAULT '',
                    status VARCHAR(50) DEFAULT 'Pending',
                    interview_date DATE DEFAULT NULL,
                    interview_notes TEXT,
                    notes TEXT,
                    system_type VARCHAR(20) DEFAULT 'Madrasa',
                    converted_teacher_id INT DEFAULT NULL,
                    is_deleted TINYINT(1) DEFAULT 0,
                    is_synced TINYINT(1) DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX (job_id),
                    INDEX (status),
                    INDEX (system_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // 7. Web Pages
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS web_pages (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    page_slug VARCHAR(100) NOT NULL UNIQUE,
                    title VARCHAR(255) NOT NULL,
                    content_html MEDIUMTEXT,
                    meta_description TEXT,
                    status VARCHAR(30) DEFAULT 'Published',
                    sort_order INT DEFAULT 0,
                    system_type VARCHAR(20) DEFAULT 'Madrasa',
                    is_deleted TINYINT(1) DEFAULT 0,
                    is_synced TINYINT(1) DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX (status),
                    INDEX (system_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // 8. Announcements
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS announcements (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    title VARCHAR(255) NOT NULL,
                    body TEXT,
                    image_url VARCHAR(255) DEFAULT '',
                    category VARCHAR(50) DEFAULT 'خبر',
                    is_public TINYINT(1) DEFAULT 1,
                    target_audience VARCHAR(50) DEFAULT 'All',
                    publish_date DATE NOT NULL,
                    system_type VARCHAR(20) DEFAULT 'Madrasa',
                    is_deleted TINYINT(1) DEFAULT 0,
                    is_synced TINYINT(1) DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX (publish_date),
                    INDEX (system_type),
                    INDEX (is_deleted)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
        } catch (Throwable $e) {}
    }
}

if (!function_exists('record_inventory_sale_with_voucher')) {
    function record_inventory_sale_with_voucher(PDO $pdo, $item_id, $quantity, $student_id = null, $customer_name = '', $payment_mode = 'Cash', $remarks = '', $system_type = 'Madrasa', $created_by = null, $tenant_id = null) {
        if ($tenant_id === null) $tenant_id = (int)get_current_tenant_id();
        try {
            ensure_phase3_4_tables($pdo);
            $stmt = $pdo->prepare("SELECT * FROM inventory_items WHERE id = ? AND tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)");
            $stmt->execute([$item_id, $tenant_id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) return ['success' => false, 'message' => 'آئٹم نہیں ملا!'];

            $avail = (int)$item['current_stock'];
            if ($avail < $quantity) {
                return ['success' => false, 'message' => "موجودہ اسٹاک ناکافی ہے۔ دستیاب: $avail"];
            }

            $uPrice = (float)$item['unit_price'];
            $totalAmount = $uPrice * $quantity;
            $saleNo = 'SALE-' . date('ymd') . '-' . rand(100, 999);
            $today = date('Y-m-d');

            $pdo->beginTransaction();

            // 1. Create Sale Record
            $sStmt = $pdo->prepare("
                INSERT INTO inventory_sales (sale_no, item_id, item_name, student_id, customer_name, quantity, unit_price, total_amount, payment_mode, date, remarks, system_type, created_by, tenant_id, is_deleted, is_synced)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)
            ");
            $sStmt->execute([
                $saleNo, $item_id, $item['name'], $student_id, $customer_name,
                $quantity, $uPrice, $totalAmount, $payment_mode, $today, $remarks,
                $system_type, $created_by, $tenant_id
            ]);
            $saleId = $pdo->lastInsertId();

            // 2. Reduce Stock
            $pdo->prepare("UPDATE inventory_items SET current_stock = GREATEST(0, current_stock - ?), updated_at = NOW() WHERE id = ? AND tenant_id = ?")->execute([$quantity, $item_id, $tenant_id]);

            // 3. Double-Entry Accounting Voucher Integration
            $voucherId = null;
            try {
                // Ensure Cash Account (1001) and Shop Revenue Account (4005) exist
                $cashAcc = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE code = '1001' AND tenant_id = $tenant_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                $shopAcc = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE (code = '4005' OR name LIKE '%شاپ%' OR name LIKE '%Shop%') AND tenant_id = $tenant_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);

                if (!$shopAcc) {
                    $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, is_active, tenant_id) VALUES ('4005', 'آمدنی سکول شاپ / انونٹری', 'Revenue', 1, ?)")->execute([$tenant_id]);
                    $shopAccId = $pdo->lastInsertId();
                } else {
                    $shopAccId = $shopAcc['id'];
                }

                if ($cashAcc && $shopAccId) {
                    $cashAccId = $cashAcc['id'];
                    $vNo = 'RV-' . date('ymd') . '-' . rand(1000, 9999);
                    $desc = "سکول شاپ سیل: {$item['name']} x $quantity ($customer_name)";
                    $vStmt = $pdo->prepare("INSERT INTO acc_vouchers (voucher_no, date, type, reference, description, status, created_by, tenant_id) VALUES (?, ?, 'Receipt', ?, ?, 'Approved', ?, ?)");
                    $vStmt->execute([$vNo, $today, $saleNo, $desc, $created_by, $tenant_id]);
                    $voucherId = $pdo->lastInsertId();

                    // Ledger Lines: Debit Cash, Credit Shop Revenue
                    $lStmt = $pdo->prepare("INSERT INTO acc_ledger_entries (voucher_id, account_id, debit, credit, tenant_id) VALUES (?, ?, ?, ?, ?)");
                    $lStmt->execute([$voucherId, $cashAccId, $totalAmount, 0, $tenant_id]);
                    $lStmt->execute([$voucherId, $shopAccId, 0, $totalAmount, $tenant_id]);

                    $pdo->prepare("UPDATE inventory_sales SET voucher_id = ? WHERE id = ? AND tenant_id = ?")->execute([$voucherId, $saleId, $tenant_id]);
                }
            } catch (Throwable $e) {}

            $pdo->commit();
            log_activity($pdo, 'shop', "سکول شاپ سے '{$item['name']}' کی فروخت x $quantity (مبلغ: $totalAmount روپے)", null, $created_by);
            return ['success' => true, 'sale_id' => $saleId, 'sale_no' => $saleNo];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}

if (isset($_GET['migrate_phases_schema']) && $_GET['migrate_phases_schema'] === '1') {
    try {
        ensure_phase1_tables($pdo);
        ensure_phase2_tables($pdo);
        ensure_phase3_4_tables($pdo);
    } catch (Throwable $e) {}
}
