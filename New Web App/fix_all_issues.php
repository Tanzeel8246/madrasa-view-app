<?php
// =============================================
// MADRASA APP - COMPLETE FIX SCRIPT
// Upload to: public_html/Madrasa web app/fix_all_issues.php
// Run once in browser, then DELETE this file!
// =============================================

// Security: Only allow from specific IP or with password
$fix_password = 'fix2024madrasa';
if (!isset($_GET['key']) || $_GET['key'] !== $fix_password) {
    die('<h2 style="color:red">Access Denied. Add ?key=fix2024madrasa to URL</h2>');
}

require_once __DIR__ . '/env_loader.php';

$host = getenv('MADRASA_DB_HOST') ?: 'localhost';
$dbname = getenv('MADRASA_DB_NAME') ?: 'u349923428_madrasa';
$username = getenv('MADRASA_DB_USER') ?: 'u349923428_madrasa';
$password = getenv('MADRASA_DB_PASSWORD') ?: 'Abdulrahman*8246';

echo '<!DOCTYPE html><html dir="rtl" lang="ur"><head><meta charset="UTF-8">
<title>Madrasa Fix Script</title>
<style>
body { font-family: Arial; padding: 20px; background: #f5f5f5; }
.ok { color: green; font-weight: bold; }
.err { color: red; font-weight: bold; }
.warn { color: orange; font-weight: bold; }
.box { background: white; padding: 15px; border-radius: 8px; margin: 10px 0; border-left: 4px solid #28a745; }
.box.error { border-color: red; }
h1 { color: #284D3D; }
h3 { color: #333; border-bottom: 1px solid #ddd; }
</style></head><body>';

echo '<h1>🔧 مدرسہ ایپ - مکمل فکس اسکرپٹ</h1>';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo '<div class="box"><span class="ok">✅ ڈیٹا بیس سے کنکشن کامیاب!</span> DB: ' . $dbname . '</div>';
} catch(Exception $e) {
    die('<div class="box error"><span class="err">❌ ڈیٹا بیس کنکشن فیل: ' . $e->getMessage() . '</span></div>');
}

// ============================================================
// STEP 1: Check and Create Missing Tables
// ============================================================
echo '<h3>1️⃣ مسنگ ٹیبلز چیک اور بناؤ</h3>';

$missing_tables = [
    'teacher_period_attendance' => "
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'parent_complaints' => "
        CREATE TABLE IF NOT EXISTS `parent_complaints` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `student_id` int(11) NOT NULL,
          `date` date NOT NULL,
          `category` varchar(255) DEFAULT NULL,
          `description` text NOT NULL,
          `status` varchar(50) DEFAULT 'Pending',
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'ai_settings' => "
        CREATE TABLE IF NOT EXISTS `ai_settings` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `provider` varchar(50) NOT NULL,
          `api_keys` text NOT NULL,
          `active_model` varchar(100) DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `provider` (`provider`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'settings' => "
        CREATE TABLE IF NOT EXISTS `settings` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `madrasa_name` varchar(255) DEFAULT NULL,
          `phone` varchar(50) DEFAULT NULL,
          `current_hijri_year` varchar(10) DEFAULT '1447',
          `week_start_day` int(1) DEFAULT 6,
          `ai_provider` varchar(50) DEFAULT 'gemini',
          `gemini_api_key` varchar(255) DEFAULT NULL,
          `groq_api_key` varchar(255) DEFAULT NULL,
          `whatsapp_api_key` varchar(255) DEFAULT NULL,
          `sms_api_key` varchar(255) DEFAULT NULL,
          `auto_notify_day` int(1) DEFAULT 4,
          `auto_notify_time` varchar(10) DEFAULT '18:00',
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'heads' => "
        CREATE TABLE IF NOT EXISTS `heads` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `name` varchar(255) NOT NULL,
          `type` varchar(50) DEFAULT 'Income',
          `is_deleted` tinyint DEFAULT 0,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'departments' => "
        CREATE TABLE IF NOT EXISTS `departments` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `name` varchar(255) NOT NULL,
          `description` text DEFAULT NULL,
          `status` varchar(20) DEFAULT 'Active',
          `is_deleted` tinyint DEFAULT 0,
          `is_synced` tinyint DEFAULT 1,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'class_periods' => "
        CREATE TABLE IF NOT EXISTS `class_periods` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `class_id` int(11) NOT NULL,
          `period_no` int(11) NOT NULL,
          `teacher_id` int(11) DEFAULT NULL,
          `teacher_ids` text DEFAULT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
];

foreach ($missing_tables as $table => $sql) {
    try {
        // Check if exists
        $check = $pdo->query("SHOW TABLES LIKE '$table'")->fetchColumn();
        if ($check) {
            echo '<div class="box">⚠️ <span class="warn">ٹیبل پہلے سے موجود: <code>' . $table . '</code></span></div>';
        } else {
            $pdo->exec($sql);
            echo '<div class="box"><span class="ok">✅ نئی ٹیبل بنائی: <code>' . $table . '</code></span></div>';
        }
    } catch (Exception $e) {
        echo '<div class="box error"><span class="err">❌ خرابی: ' . $table . ' - ' . $e->getMessage() . '</span></div>';
    }
}

// ============================================================
// STEP 2: Add Missing Columns to Existing Tables
// ============================================================
echo '<h3>2️⃣ مسنگ کالمز شامل کریں</h3>';

$columns_to_add = [
    ['period_attendance', 'remarks', 'TEXT DEFAULT NULL'],
    ['daily_hifz', 'sabaq_time', "VARCHAR(20) DEFAULT NULL"],
    ['daily_hifz', 'sabqi_time', "VARCHAR(20) DEFAULT NULL"],
    ['daily_hifz', 'manzil_time', "VARCHAR(20) DEFAULT NULL"],
    ['teachers', 'house_rent', 'DECIMAL(10,2) DEFAULT 0'],
    ['teachers', 'medical_allowance', 'DECIMAL(10,2) DEFAULT 0'],
    ['teachers', 'travel_allowance', 'DECIMAL(10,2) DEFAULT 0'],
    ['teachers', 'daily_deduction_rate', 'DECIMAL(10,2) DEFAULT 0'],
    ['teachers', 'photo', 'VARCHAR(255) DEFAULT NULL'],
    ['teachers', 'assigned_classes', 'TEXT'],
    ['teachers', 'assigned_periods', 'TEXT'],
    ['admins', 'role', "VARCHAR(50) DEFAULT 'admin'"],
    ['admins', 'permissions', 'TEXT'],
    ['settings', 'week_start_day', 'INT(1) DEFAULT 6'],
    ['acc_chart_of_accounts', 'is_deleted', 'TINYINT DEFAULT 0'],
    ['finance', 'is_deleted', 'TINYINT DEFAULT 0'],
    ['notices', 'is_deleted', 'TINYINT DEFAULT 0'],
    ['students', 'is_deleted', 'TINYINT DEFAULT 0'],
    ['students', 'roll_number', 'VARCHAR(50) DEFAULT NULL'],
];

foreach ($columns_to_add as $col) {
    [$table, $column, $def] = $col;
    try {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $def");
        echo '<div class="box"><span class="ok">✅ کالم شامل: <code>' . $table . '.' . $column . '</code></span></div>';
    } catch (Exception $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'Duplicate column') !== false || strpos($msg, 'already exists') !== false) {
            echo '<div class="box">⚠️ <span class="warn">پہلے سے موجود: <code>' . $table . '.' . $column . '</code></span></div>';
        } else {
            echo '<div class="box error"><span class="err">❌ ' . $table . '.' . $column . ': ' . $msg . '</span></div>';
        }
    }
}

// ============================================================
// STEP 3: Fix heads table - add default data if empty
// ============================================================
echo '<h3>3️⃣ مالیاتی مدات (Finance Heads) چیک کریں</h3>';

try {
    $headCount = $pdo->query("SELECT COUNT(*) FROM heads WHERE is_deleted = 0 OR is_deleted IS NULL")->fetchColumn();
    echo '<div class="box">موجودہ مدات: <strong>' . $headCount . '</strong></div>';
    
    if ($headCount == 0) {
        $default_heads = [
            ['ماہانہ فیس', 'Income'],
            ['عطیہ', 'Income'],
            ['داخلہ فیس', 'Income'],
            ['زکوۃ', 'Income'],
            ['چندہ', 'Income'],
            ['تنخواہ', 'Expense'],
            ['بجلی / پانی / گیس', 'Expense'],
            ['اسٹیشنری', 'Expense'],
            ['مرمت و دیکھ بھال', 'Expense'],
            ['کھانا / لنگر', 'Expense'],
            ['دیگر اخراجات', 'Expense'],
        ];
        $stmt = $pdo->prepare("INSERT INTO heads (name, type, is_deleted) VALUES (?, ?, 0)");
        foreach ($default_heads as $h) {
            $stmt->execute($h);
        }
        echo '<div class="box"><span class="ok">✅ ' . count($default_heads) . ' ڈیفالٹ مدات شامل کی گئیں۔</span></div>';
    } else {
        echo '<div class="box">⚠️ <span class="warn">مدات پہلے سے موجود ہیں - کچھ نہیں کیا۔</span></div>';
    }

    $setCount = $pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn();
    if ($setCount == 0) {
        $pdo->exec("INSERT INTO settings (id) VALUES (1)");
    }
} catch(Exception $e) {
    echo '<div class="box error"><span class="err">❌ مدات کی خرابی: ' . $e->getMessage() . '</span></div>';
}

// ============================================================
// STEP 4: Check hifz_report.php issue - test the queries
// ============================================================
echo '<h3>4️⃣ حفظ رپورٹ ٹیسٹ کریں</h3>';

try {
    // Test the exact query that hifz_report.php uses
    $test = $pdo->query("SELECT COUNT(*) FROM daily_hifz")->fetchColumn();
    echo '<div class="box"><span class="ok">✅ daily_hifz ٹیبل کام کر رہی ہے - ' . $test . ' ریکارڈز</span></div>';
} catch(Exception $e) {
    echo '<div class="box error"><span class="err">❌ daily_hifz مسئلہ: ' . $e->getMessage() . '</span></div>';
}

try {
    $test2 = $pdo->query("SELECT COUNT(*) FROM parent_complaints")->fetchColumn();
    echo '<div class="box"><span class="ok">✅ parent_complaints ٹیبل کام کر رہی ہے - ' . $test2 . ' ریکارڈز</span></div>';
} catch(Exception $e) {
    echo '<div class="box error"><span class="err">❌ parent_complaints مسئلہ: ' . $e->getMessage() . '</span></div>';
}

try {
    $test3 = $pdo->query("SELECT COUNT(*) FROM teacher_period_attendance")->fetchColumn();
    echo '<div class="box"><span class="ok">✅ teacher_period_attendance ٹیبل کام کر رہی ہے - ' . $test3 . ' ریکارڈز</span></div>';
} catch(Exception $e) {
    echo '<div class="box error"><span class="err">❌ teacher_period_attendance مسئلہ: ' . $e->getMessage() . '</span></div>';
}

// ============================================================
// STEP 5: Check API sync issue
// ============================================================
echo '<h3>5️⃣ API سنک چیک کریں</h3>';

try {
    // Check if API secret is set
    $api_secret = getenv('MADRASA_API_SECRET');
    if ($api_secret) {
        echo '<div class="box"><span class="ok">✅ API_SECRET موجود ہے</span></div>';
    } else {
        echo '<div class="box error"><span class="err">❌ API_SECRET غائب ہے - سنک کام نہیں کرے گی!</span></div>';
    }
    
    // Check all synced tables exist
    $sync_tables = ['students', 'teachers', 'finance', 'daily_hifz', 'attendance', 'period_attendance', 
                    'teacher_period_attendance', 'attendance_teachers', 'notices', 'classes', 
                    'departments', 'acc_chart_of_accounts', 'acc_vouchers', 'acc_ledger_entries',
                    'parent_complaints', 'complaints', 'leave_applications', 'fee_submissions',
                    'academic_reports', 'exam_results', 'exams', 'heads'];
    
    $missing = [];
    foreach($sync_tables as $t) {
        $exists = $pdo->query("SHOW TABLES LIKE '$t'")->fetchColumn();
        if (!$exists) $missing[] = $t;
    }
    
    if (empty($missing)) {
        echo '<div class="box"><span class="ok">✅ تمام سنک ٹیبلز موجود ہیں!</span></div>';
    } else {
        echo '<div class="box error"><span class="err">❌ یہ ٹیبلز غائب ہیں: ' . implode(', ', $missing) . '</span></div>';
    }
    
} catch(Exception $e) {
    echo '<div class="box error"><span class="err">❌ چیک میں خرابی: ' . $e->getMessage() . '</span></div>';
}

// ============================================================
// STEP 6: Check and fix admins table columns
// ============================================================
echo '<h3>6️⃣ تمام ٹیبلز کا خلاصہ</h3>';

try {
    $stmt = $pdo->query("SHOW TABLES");
    $all_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo '<div class="box">موجود ٹیبلز (' . count($all_tables) . '): <br><code>' . implode(', ', $all_tables) . '</code></div>';
} catch(Exception $e) {
    echo '<div class="box error"><span class="err">❌ ' . $e->getMessage() . '</span></div>';
}

echo '<br><div style="background:#28a745;color:white;padding:20px;border-radius:8px;font-size:18px;text-align:center;">';
echo '✅ فکس مکمل! اب یہ فائل ڈیلیٹ کریں اور ایپ ٹیسٹ کریں۔';
echo '</div>';

echo '<br><p style="color:red;font-weight:bold;">⚠️ سیکیورٹی: یہ فائل ابھی ڈیلیٹ کریں! cPanel File Manager سے حذف کریں۔</p>';

echo '</body></html>';
?>
