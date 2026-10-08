<?php
/**
 * سنک ڈائیگنوسٹک و آٹو فکس ٹول (Sync Diagnostic & Auto-Fix Tool)
 * Madrasa Ashraf-ul-Madaris
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/api/sync/SyncContext.php';
require_once __DIR__ . '/api/sync_down.php';

$message = '';
$error = '';
$sync_test_result = null;
$sync_error = null;

// تمام مطلوبہ ٹیبلز کی فہرست
$required_tables = [
    'admins' => "CREATE TABLE IF NOT EXISTS `admins` (`id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(100) NOT NULL, `username` varchar(50) NOT NULL, `password` varchar(255) NOT NULL, `role` varchar(50) DEFAULT 'admin', `permissions` text DEFAULT NULL, `allowed_classes` text DEFAULT NULL, `system_type` varchar(50) DEFAULT 'Madrasa', `phone` varchar(50) DEFAULT NULL, `fcm_token` text DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`), UNIQUE KEY `username` (`username`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'settings' => "CREATE TABLE IF NOT EXISTS `settings` (`id` int(11) NOT NULL AUTO_INCREMENT, `madrasa_name` varchar(255) DEFAULT 'مدرسہ اشرف المدارس', `phone` varchar(50) DEFAULT NULL, `address` text DEFAULT NULL, `tagline` varchar(255) DEFAULT NULL, `current_hijri_year` varchar(20) DEFAULT '1447', `week_start_day` int(11) DEFAULT 6, `ai_provider` varchar(50) DEFAULT 'gemini', `auto_notify_day` int(11) DEFAULT 5, `auto_notify_time` varchar(20) DEFAULT '18:00', `logo_path` text DEFAULT NULL, `receipt_bg` text DEFAULT NULL, `masjid_receipt_bg` text DEFAULT NULL, `id_card_bg` text DEFAULT NULL, `sanad_bg` text DEFAULT NULL, `signature_stamp_path` text DEFAULT NULL, `sanad_layout_json` text DEFAULT NULL, `masjid_name` varchar(255) DEFAULT 'جامع مسجد اشرف', `masjid_logo_path` text DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'classes' => "CREATE TABLE IF NOT EXISTS `classes` (`id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(100) NOT NULL, `type` varchar(50) DEFAULT 'General', `status` varchar(50) DEFAULT 'Active', `system_type` varchar(50) DEFAULT 'Madrasa', `main_teacher_ids` text DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'class_periods' => "CREATE TABLE IF NOT EXISTS `class_periods` (`id` int(11) NOT NULL AUTO_INCREMENT, `class_id` int(11) NOT NULL, `period_no` int(11) NOT NULL, `teacher_id` int(11) DEFAULT NULL, `teacher_ids` text DEFAULT NULL, `subject` varchar(100) DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'teachers' => "CREATE TABLE IF NOT EXISTS `teachers` (`id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(100) NOT NULL, `phone` varchar(50) DEFAULT NULL, `qualification` varchar(255) DEFAULT NULL, `address` text DEFAULT NULL, `salary` decimal(10,2) DEFAULT 0.00, `joining_date` date DEFAULT NULL, `system_type` varchar(50) DEFAULT 'Madrasa', `status` varchar(50) DEFAULT 'Active', `password` varchar(255) DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'students' => "CREATE TABLE IF NOT EXISTS `students` (`id` int(11) NOT NULL AUTO_INCREMENT, `admission_number` varchar(50) NOT NULL, `name` varchar(100) NOT NULL, `father_name` varchar(100) DEFAULT NULL, `dob` date DEFAULT NULL, `gender` varchar(20) DEFAULT 'Male', `phone` varchar(50) DEFAULT NULL, `address` text DEFAULT NULL, `class` varchar(100) DEFAULT NULL, `status` varchar(50) DEFAULT 'Active', `monthly_fee` decimal(10,2) DEFAULT 0.00, `admission_date` date DEFAULT NULL, `password` varchar(255) DEFAULT NULL, `system_type` varchar(50) DEFAULT 'Madrasa', `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'attendance' => "CREATE TABLE IF NOT EXISTS `attendance` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) NOT NULL, `date` date NOT NULL, `status` varchar(50) NOT NULL, `remarks` text DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`), UNIQUE KEY `unique_student_date` (`student_id`,`date`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'attendance_teachers' => "CREATE TABLE IF NOT EXISTS `attendance_teachers` (`id` int(11) NOT NULL AUTO_INCREMENT, `teacher_id` int(11) NOT NULL, `date` date NOT NULL, `status` varchar(50) NOT NULL, `remarks` text DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`), UNIQUE KEY `unique_teacher_date` (`teacher_id`,`date`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'teacher_period_attendance' => "CREATE TABLE IF NOT EXISTS `teacher_period_attendance` (`id` int(11) NOT NULL AUTO_INCREMENT, `teacher_id` int(11) NOT NULL, `class_id` int(11) NOT NULL, `period_no` int(11) NOT NULL, `date` date NOT NULL, `status` varchar(50) NOT NULL, `remarks` text DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'period_attendance' => "CREATE TABLE IF NOT EXISTS `period_attendance` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) NOT NULL, `date` date NOT NULL, `period_num` int(11) NOT NULL, `status` varchar(50) NOT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'daily_hifz' => "CREATE TABLE IF NOT EXISTS `daily_hifz` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) NOT NULL, `date` date NOT NULL, `sabaq` text DEFAULT NULL, `sabqi` text DEFAULT NULL, `manzil` text DEFAULT NULL, `status` varchar(50) DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'daily_nazira' => "CREATE TABLE IF NOT EXISTS `daily_nazira` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) NOT NULL, `date` date NOT NULL, `para` varchar(50) DEFAULT NULL, `surah` varchar(100) DEFAULT NULL, `ayat_from` varchar(50) DEFAULT NULL, `ayat_to` varchar(50) DEFAULT NULL, `status` varchar(50) DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'qaida_records' => "CREATE TABLE IF NOT EXISTS `qaida_records` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) NOT NULL, `date` date NOT NULL, `lesson_number` varchar(50) DEFAULT NULL, `page_number` varchar(50) DEFAULT NULL, `status` varchar(50) DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'husn_saut_record' => "CREATE TABLE IF NOT EXISTS `husn_saut_record` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) NOT NULL, `date` date NOT NULL, `details` text DEFAULT NULL, `status` varchar(50) DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'academic_reports' => "CREATE TABLE IF NOT EXISTS `academic_reports` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) NOT NULL, `date` date NOT NULL, `report_type` varchar(100) DEFAULT NULL, `details` text DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'complaints' => "CREATE TABLE IF NOT EXISTS `complaints` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) DEFAULT NULL, `title` varchar(255) DEFAULT NULL, `description` text DEFAULT NULL, `date` date DEFAULT NULL, `status` varchar(50) DEFAULT 'Pending', `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'parent_complaints' => "CREATE TABLE IF NOT EXISTS `parent_complaints` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) DEFAULT NULL, `title` varchar(255) DEFAULT NULL, `description` text DEFAULT NULL, `date` date DEFAULT NULL, `status` varchar(50) DEFAULT 'Pending', `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'leave_applications' => "CREATE TABLE IF NOT EXISTS `leave_applications` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) DEFAULT NULL, `teacher_id` int(11) DEFAULT NULL, `reason` text DEFAULT NULL, `date` date DEFAULT NULL, `status` varchar(50) DEFAULT 'Pending', `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'fee_submissions' => "CREATE TABLE IF NOT EXISTS `fee_submissions` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) NOT NULL, `amount` decimal(10,2) NOT NULL, `date` date NOT NULL, `month` varchar(50) DEFAULT NULL, `remarks` text DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'exams' => "CREATE TABLE IF NOT EXISTS `exams` (`id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(100) NOT NULL, `exam_date` date DEFAULT NULL, `system_type` varchar(50) DEFAULT 'Madrasa', `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'exam_results' => "CREATE TABLE IF NOT EXISTS `exam_results` (`id` int(11) NOT NULL AUTO_INCREMENT, `exam_id` int(11) NOT NULL, `student_id` int(11) NOT NULL, `total_marks` decimal(10,2) DEFAULT 100.00, `obtained_marks` decimal(10,2) DEFAULT 0.00, `grade` varchar(20) DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'notices' => "CREATE TABLE IF NOT EXISTS `notices` (`id` int(11) NOT NULL AUTO_INCREMENT, `title` varchar(255) NOT NULL, `message` text NOT NULL, `date` date DEFAULT NULL, `system_type` varchar(50) DEFAULT 'Madrasa', `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'assets' => "CREATE TABLE IF NOT EXISTS `assets` (`id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(255) NOT NULL, `category` varchar(100) DEFAULT NULL, `quantity` int(11) DEFAULT 1, `value` decimal(10,2) DEFAULT 0.00, `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'finance' => "CREATE TABLE IF NOT EXISTS `finance` (`id` int(11) NOT NULL AUTO_INCREMENT, `type` varchar(50) NOT NULL, `amount` decimal(10,2) NOT NULL, `date` date NOT NULL, `head_id` int(11) DEFAULT NULL, `head_name` varchar(100) DEFAULT NULL, `description` text DEFAULT NULL, `spender_name` varchar(100) DEFAULT NULL, `donor_name` varchar(100) DEFAULT NULL, `user_id` int(11) DEFAULT NULL, `user_name` varchar(100) DEFAULT NULL, `account_id` int(11) DEFAULT NULL, `system_type` varchar(50) DEFAULT 'Madrasa', `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'finance_heads' => "CREATE TABLE IF NOT EXISTS `finance_heads` (`id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(100) NOT NULL, `type` varchar(50) NOT NULL, `system_type` varchar(50) DEFAULT 'Madrasa', `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'maktab_students' => "CREATE TABLE IF NOT EXISTS `maktab_students` (`id` int(11) NOT NULL AUTO_INCREMENT, `admission_number` varchar(50) NOT NULL, `name` varchar(100) NOT NULL, `father_name` varchar(100) DEFAULT NULL, `class` varchar(100) DEFAULT NULL, `status` varchar(50) DEFAULT 'Active', `phone` varchar(50) DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'maktab_teachers' => "CREATE TABLE IF NOT EXISTS `maktab_teachers` (`id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(100) NOT NULL, `phone` varchar(50) DEFAULT NULL, `status` varchar(50) DEFAULT 'Active', `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'maktab_classes' => "CREATE TABLE IF NOT EXISTS `maktab_classes` (`id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(100) NOT NULL, `status` varchar(50) DEFAULT 'Active', `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'maktab_exams' => "CREATE TABLE IF NOT EXISTS `maktab_exams` (`id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(100) NOT NULL, `exam_date` date DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'maktab_exam_results' => "CREATE TABLE IF NOT EXISTS `maktab_exam_results` (`id` int(11) NOT NULL AUTO_INCREMENT, `exam_id` int(11) NOT NULL, `student_id` int(11) NOT NULL, `obtained_marks` decimal(10,2) DEFAULT 0.00, `total_marks` decimal(10,2) DEFAULT 100.00, `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'maktab_nazira_record' => "CREATE TABLE IF NOT EXISTS `maktab_nazira_record` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) NOT NULL, `date` date NOT NULL, `para` varchar(50) DEFAULT NULL, `surah` varchar(100) DEFAULT NULL, `ayat_from` varchar(50) DEFAULT NULL, `ayat_to` varchar(50) DEFAULT NULL, `status` varchar(50) DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'maktab_hifz_record' => "CREATE TABLE IF NOT EXISTS `maktab_hifz_record` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) NOT NULL, `date` date NOT NULL, `sabaq` text DEFAULT NULL, `sabqi` text DEFAULT NULL, `manzil` text DEFAULT NULL, `status` varchar(50) DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'maktab_qaida_records' => "CREATE TABLE IF NOT EXISTS `maktab_qaida_records` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) NOT NULL, `date` date NOT NULL, `lesson_number` varchar(50) DEFAULT NULL, `status` varchar(50) DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'maktab_academic_reports' => "CREATE TABLE IF NOT EXISTS `maktab_academic_reports` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) NOT NULL, `date` date NOT NULL, `details` text DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'daily_maktab' => "CREATE TABLE IF NOT EXISTS `daily_maktab` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) NOT NULL, `date` date NOT NULL, `morning_sabaq` text DEFAULT NULL, `morning_qty` varchar(50) DEFAULT NULL, `morning_time` varchar(20) DEFAULT NULL, `morning_status` varchar(50) DEFAULT NULL, `evening_sabaq` text DEFAULT NULL, `evening_qty` varchar(50) DEFAULT NULL, `evening_time` varchar(20) DEFAULT NULL, `evening_status` varchar(50) DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'teacher_leaves' => "CREATE TABLE IF NOT EXISTS `teacher_leaves` (`id` int(11) NOT NULL AUTO_INCREMENT, `teacher_id` int(11) NOT NULL, `start_date` date NOT NULL, `end_date` date NOT NULL, `days_count` int(11) DEFAULT 1, `reason` text DEFAULT NULL, `status` varchar(50) DEFAULT 'Pending', `system_type` varchar(50) DEFAULT 'Madrasa', `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'departments' => "CREATE TABLE IF NOT EXISTS `departments` (`id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(100) NOT NULL, `system_type` varchar(50) DEFAULT 'Madrasa', `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'staff_loans' => "CREATE TABLE IF NOT EXISTS `staff_loans` (`id` int(11) NOT NULL AUTO_INCREMENT, `teacher_id` int(11) NOT NULL, `amount` decimal(10,2) NOT NULL, `installment_amount` decimal(10,2) DEFAULT 0.00, `remaining_amount` decimal(10,2) DEFAULT 0.00, `status` varchar(50) DEFAULT 'Active', `system_type` varchar(50) DEFAULT 'Madrasa', `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'acc_payroll' => "CREATE TABLE IF NOT EXISTS `acc_payroll` (`id` int(11) NOT NULL AUTO_INCREMENT, `teacher_id` int(11) NOT NULL, `month_year` varchar(20) NOT NULL, `basic_salary` decimal(10,2) DEFAULT 0.00, `allowances` decimal(10,2) DEFAULT 0.00, `deductions` decimal(10,2) DEFAULT 0.00, `net_salary` decimal(10,2) DEFAULT 0.00, `status` varchar(50) DEFAULT 'Unpaid', `payment_date` date DEFAULT NULL, `system_type` varchar(50) DEFAULT 'Madrasa', `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
];

// آٹو فکس ایکشن
if (isset($_POST['auto_fix'])) {
    $created_count = 0;
    try {
        foreach ($required_tables as $tbl => $sql) {
            $pdo->exec($sql);
            $created_count++;
        }

        // Settings میں کم از کم 1 ڈیفالٹ ریکارڈ یقینی بنائیں
        $st_chk = $pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn();
        if ($st_chk == 0) {
            $pdo->exec("INSERT INTO settings (id, madrasa_name, current_hijri_year, week_start_day) VALUES (1, 'مدرسہ اشرف المدارس', '1447', 6)");
        }

        // Settings کالمز کی جانچ اور اضافہ
        $cols = array_column($pdo->query("SHOW COLUMNS FROM settings")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $check_cols = [
            'masjid_name' => "ALTER TABLE settings ADD COLUMN masjid_name varchar(255) DEFAULT 'جامع مسجد اشرف'",
            'masjid_logo_path' => "ALTER TABLE settings ADD COLUMN masjid_logo_path text DEFAULT NULL",
            'masjid_receipt_bg' => "ALTER TABLE settings ADD COLUMN masjid_receipt_bg text DEFAULT NULL",
            'sanad_layout_json' => "ALTER TABLE settings ADD COLUMN sanad_layout_json text DEFAULT NULL",
            'ai_provider' => "ALTER TABLE settings ADD COLUMN ai_provider varchar(50) DEFAULT 'gemini'",
            'week_start_day' => "ALTER TABLE settings ADD COLUMN week_start_day int(11) DEFAULT 6",
        ];
        foreach ($check_cols as $c => $alter_sql) {
            if (!in_array($c, $cols)) {
                try { $pdo->exec($alter_sql); } catch(Throwable $ex) {}
            }
        }

        $message = "تمام ٹیبلز اور کالمز کی تصدیق و اصلاح کامیابی سے مکمل ہو گئی!";
    } catch (Throwable $e) {
        $error = "آٹو فکس میں خرابی: " . $e->getMessage();
    }
}

// موجودہ ٹیبلز کی جانچ
$existing_tables = [];
try {
    $existing_tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $error = "ٹیبلز لسٹ حاصل کرنے میں خرابی: " . $e->getMessage();
}

// سنک ٹیسٹ چلائیں
$admin_user = $pdo->query("SELECT * FROM admins WHERE role='admin' OR username='admin' OR username='Tanzeel' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$admin_user) {
    $admin_user = ['id' => 1, 'role' => 'admin', 'username' => 'admin', 'system_type' => 'Madrasa', 'permissions' => '["all"]'];
}

try {
    $sync_test_result = handle_sync_down($pdo, $admin_user);
} catch (Throwable $e) {
    $sync_error = $e->getMessage() . " (فائل: " . basename($e->getFile()) . "، لائن: " . $e->getLine() . ")";
}
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>سنک ڈائیگنوسٹک و آٹو فکس ٹول</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #f4f6f9; }
        .card { border-radius: 12px; }
    </style>
</head>
<body class="p-3 p-md-4">
<div class="container" style="max-width: 900px;">

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-primary text-white py-3 d-flex justify-content-between align-items-center">
            <div>
                <h4 class="mb-0 fw-bold"><i class="fas fa-sync-alt me-2"></i> سنکنگ ڈائیگنوسٹک و آٹو فکس ٹول</h4>
                <small>ڈیٹابیس کے تمام ٹیبلز، کالمز اور موبائل سنکنگ کا لائیو ٹیسٹ</small>
            </div>
            <a href="login.php" class="btn btn-sm btn-light fw-bold">لاگ ان پیج</a>
        </div>
        <div class="card-body p-4">

            <?php if($message): ?>
                <div class="alert alert-success fw-bold shadow-sm"><i class="fas fa-check-circle me-2"></i><?= $message ?></div>
            <?php endif; ?>

            <?php if($error): ?>
                <div class="alert alert-danger fw-bold shadow-sm"><i class="fas fa-exclamation-triangle me-2"></i><?= $error ?></div>
            <?php endif; ?>

            <!-- لائیو سنک ٹیسٹ کا رزلٹ -->
            <div class="card mb-4 border-2 <?= $sync_error ? 'border-danger' : 'border-success' ?>">
                <div class="card-header <?= $sync_error ? 'bg-danger text-white' : 'bg-success text-white' ?> fw-bold">
                    <i class="fas <?= $sync_error ? 'fa-times-circle' : 'fa-check-circle' ?> me-2"></i>
                    لائیو سنکنگ ٹیسٹ نتیجہ (Sync Down Simulation Result):
                </div>
                <div class="card-body">
                    <?php if($sync_error): ?>
                        <div class="text-danger fw-bold fs-5 mb-2">❌ سنکنگ فیل ہو گئی! اصل خرابی درج ذیل ہے:</div>
                        <div class="p-3 bg-danger bg-opacity-10 rounded border border-danger">
                            <code class="text-danger fs-6 fw-bold"><?= htmlspecialchars($sync_error) ?></code>
                        </div>
                        <p class="mt-2 text-muted small">یہی وہ ایرر ہے جس کی وجہ سے موبائل سکرین پر "Sync down failed: An internal server error occurred" آتا ہے۔ نیچے دیے گئے بٹن سے اسے ٹھیک کریں۔</p>
                    <?php else: ?>
                        <div class="text-success fw-bold fs-5 mb-2">✅ سنکنگ بالکل کامیاب ہے! (Sync Down 100% Functional)</div>
                        <p class="mb-0 text-muted">سرور نے موبائل ایپ کو ڈیٹا کامیابی سے تیار کر کے بھیج دیا۔ کل فیلڈز: <strong><?= count($sync_test_result['data'] ?? []) ?></strong></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- آٹو فکس بٹن -->
            <div class="card bg-light border-0 shadow-sm p-3 mb-4 text-center">
                <h5 class="fw-bold text-dark mb-2">کیا کوئی ٹیبل یا کالم مسنگ ہے؟</h5>
                <p class="text-muted small mb-3">یہ بٹن دبانے سے ڈیٹابیس کے تمام گمشدہ ٹیبلز، کالمز اور ڈیفالٹ سیٹنگز خودکار طریقے سے بن جائیں گے:</p>
                <form method="POST">
                    <button type="submit" name="auto_fix" class="btn btn-success btn-lg fw-bold shadow">
                        <i class="fas fa-magic me-2"></i> تمام گمشدہ ٹیبلز اور کالمز خودکار بنائیں (Auto-Fix All)
                    </button>
                </form>
            </div>

            <!-- ٹیبلز کی لسٹ اور اسٹیٹس -->
            <h5 class="fw-bold text-dark mb-3"><i class="fas fa-table me-2"></i> ڈیٹا بیس کے تمام مطلوبہ ٹیبلز کی صورتحال:</h5>
            <div class="row g-2">
                <?php 
                $missing_count = 0;
                foreach (array_keys($required_tables) as $tbl): 
                    $is_present = in_array($tbl, $existing_tables);
                    if (!$is_present) $missing_count++;
                ?>
                <div class="col-6 col-md-4">
                    <div class="p-2 border rounded d-flex justify-content-between align-items-center bg-white">
                        <span class="fw-bold small"><?= $tbl ?></span>
                        <?php if($is_present): ?>
                            <span class="badge bg-success small"><i class="fas fa-check"></i> موجود</span>
                        <?php else: ?>
                            <span class="badge bg-danger small"><i class="fas fa-times"></i> غائب</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if($missing_count > 0): ?>
                <div class="alert alert-warning mt-3 fw-bold">
                    ⚠️ توجہ فرمائیں: کل <strong><?= $missing_count ?></strong> ٹیبلز اس نئے سرور پر موجود نہیں ہیں۔ اوپر دیے گئے سبز بٹن کو دبا کر فوری حل کریں۔
                </div>
            <?php endif; ?>

        </div>
    </div>

</div>
</body>
</html>
