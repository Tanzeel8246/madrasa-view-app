<?php
require_once __DIR__ . '/env_loader.php';
// Global Configuration
define('IS_PRODUCTION', filter_var(getenv('MADRASA_IS_PRODUCTION') ?: 'true', FILTER_VALIDATE_BOOLEAN));

if (IS_PRODUCTION) {
    ini_set('display_errors', 0);
    error_reporting(0);
} else {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}

// db.php
$host = getenv('MADRASA_DB_HOST') ?: 'localhost';
$port = getenv('MADRASA_DB_PORT') ?: '';
$dbname = getenv('MADRASA_DB_NAME') ?: 'mamfss_madrasa';
$username = getenv('MADRASA_DB_USER') ?: 'mamfss_madrasa';
$password = getenv('MADRASA_DB_PASSWORD') ?: '';

if ($password === '') {
    die('Database password is not configured.');
}

try {
    $dsn = "mysql:host=$host;" . ($port !== '' ? "port=$port;" : '') . "dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    if (strpos($_SERVER['REQUEST_URI'] ?? '', 'api.php') !== false) {
        header('Content-Type: application/json');
        die(json_encode(['status' => 'error', 'message' => 'Database connection failed.']));
    } else {
        die(IS_PRODUCTION ? "Database connection failed." : "Database connection failed: " . $e->getMessage());
    }
}

try {
    // Auto-update schema for roles and permissions
    $pdo->exec("ALTER TABLE admins ADD COLUMN role VARCHAR(50) DEFAULT 'admin', ADD COLUMN permissions TEXT");
} catch (PDOException $e) {
    // Ignored if columns already exist
}

try {
    $pdo->exec("ALTER TABLE admins ADD COLUMN allowed_classes TEXT NULL");
} catch (PDOException $e) {
    // Ignored if column already exists
}

if (!function_exists('get_allowed_classes')) {
    function get_allowed_classes($pdo, $orderBy = 'id ASC', $apiRole = null, $apiUserId = null) {
        $role = $apiRole !== null ? $apiRole : ($_SESSION['role'] ?? '');
        $userId = $apiUserId !== null ? $apiUserId : ($_SESSION['user_id'] ?? 0);
        
        if (!$role) return [];
        if ($role === 'admin') {
            $sys = $_SESSION['system_type'] ?? 'Madrasa';
return $pdo->query("SELECT * FROM classes WHERE system_type = '$sys' ORDER BY $orderBy")->fetchAll(PDO::FETCH_ASSOC);
        }
        
        if (!$userId) return [];
        $stmt = $pdo->prepare("SELECT allowed_classes FROM admins WHERE id = ?");
        $stmt->execute([$userId]);
        $admin_row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $allowed_ids = [];
        if ($admin_row && !empty($admin_row['allowed_classes'])) {
            $decoded = json_decode($admin_row['allowed_classes'], true);
            if (is_array($decoded)) {
                $allowed_ids = $decoded;
            }
        }
        
        if (empty($allowed_ids)) {
            return [];
        }
        
        $placeholders = implode(',', array_fill(0, count($allowed_ids), '?'));
        $sys = $_SESSION['system_type'] ?? 'Madrasa';
$stmt = $pdo->prepare("SELECT * FROM classes WHERE id IN ($placeholders) AND system_type = '$sys' ORDER BY $orderBy");
        $stmt->execute($allowed_ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('app_has_perm')) {
    function app_has_perm($perm) {
        if (!isset($_SESSION['role'])) return false;
        if ($_SESSION['role'] === 'admin') return true;
        $perms = $_SESSION['permissions'] ?? [];
        if (is_string($perms)) {
            $decoded = json_decode($perms, true);
            $perms = is_array($decoded) ? $decoded : [];
        }
        return in_array($perm, $perms, true);
    }
}

if (!function_exists('get_period_date_range')) {
    function get_period_date_range($period = 'current_week', $custom_from = null, $custom_to = null, $week_start_day = 6) {
        $today = date('Y-m-d');
        switch ($period) {
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

// Auto-create new tables for Hifz/Attendance features
try {
    $pdo->exec("ALTER TABLE acc_chart_of_accounts ADD COLUMN parent_id INTEGER NULL");
} catch (PDOException $e) {}

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

/**
 * XSS Protection Helper
 */
function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Password Hashing Helpers
 */
function hash_pass($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

function verify_pass($password, $hash) {
    if (empty($hash)) return false;
    $hash = trim($hash);
    if ($password === $hash) return true;
    if (password_verify($password, $hash)) return true;
    if (md5($password) === strtolower($hash) || md5($password) === $hash) return true;
    if (sha1($password) === strtolower($hash) || sha1($password) === $hash) return true;
    return false;
}

// =================================================================

if (session_status() === PHP_SESSION_NONE) { session_start(); }
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
];

function t($key) { 
    global $lang, $trans; 
    // اگر لفظ ڈکشنری میں ہے تو واپس کرو، ورنہ وہی انگریزی لفظ دکھا دو تاکہ ایرر نہ آئے
    return $trans[$key][$lang] ?? $key; 
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
function sync_all_heads_with_chart_and_departments(PDO $pdo) {
    try {
        $checkHead = $pdo->prepare("SELECT id FROM heads WHERE name = ? LIMIT 1");
        $insertHead = $pdo->prepare("INSERT INTO heads (name, type, is_deleted) VALUES (?, ?, 0)");

        // 1. Sync acc_chart_of_accounts -> heads
        $chart_stmt = $pdo->query("SELECT name, type FROM acc_chart_of_accounts WHERE is_deleted = 0 OR is_deleted IS NULL");
        if ($chart_stmt) {
            $chart_accounts = $chart_stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($chart_accounts as $ca) {
                $name = trim($ca['name'] ?? '');
                if (empty($name)) continue;
                
                $rawType = strtolower(trim((string)($ca['type'] ?? '')));
                $type = ($rawType === 'income' || strpos($rawType, 'revenue') !== false) ? 'Income' : 'Expense';
                
                $checkHead->execute([$name]);
                if (!$checkHead->fetch()) {
                    $insertHead->execute([$name, $type]);
                }
            }
        }

        // 2. Sync departments -> heads (as Expense)
        $dept_stmt = $pdo->query("SELECT name FROM departments WHERE status IS NULL OR status = 'Active'");
        if ($dept_stmt) {
            $depts = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($depts as $d) {
                $name = trim($d['name'] ?? '');
                if (empty($name)) continue;
                
                $checkHead->execute([$name]);
                if (!$checkHead->fetch()) {
                    $insertHead->execute([$name, 'Expense']);
                }
            }
        }
    } catch (Exception $e) {
        // Suppress error
    }
}
?>
