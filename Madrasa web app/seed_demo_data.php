<?php
/**
 * جامع ڈیمو ڈیٹا جنریٹر و سنک ویریفیکیشن ٹول (Comprehensive Demo Data Seeder & Sync Verification)
 * Madrasa Ashraf-ul-Madaris
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(300);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/api/sync/SyncContext.php';
require_once __DIR__ . '/api/sync_down.php';

// نرم ایس کیو ایل موڈ سیٹ کریں تاکہ غیر ضروری 1364 یا 1265 وارننگز کی وجہ سے عمل رک نہ سکے
try {
    $pdo->exec("SET SESSION sql_mode = ''");
} catch (Throwable $e) {}

$message = '';
$error = '';
$sync_test_result = null;

// ۱. تمام مطلوبہ ٹیبلز کی تخلیق (Auto Schema creation if missing)
$tables_sql = [
    'admins' => "CREATE TABLE IF NOT EXISTS `admins` (`id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(100) NOT NULL, `username` varchar(50) NOT NULL, `password` varchar(255) NOT NULL, `role` varchar(50) DEFAULT 'admin', `permissions` text DEFAULT NULL, `allowed_classes` text DEFAULT NULL, `system_type` varchar(50) DEFAULT 'Madrasa', `phone` varchar(50) DEFAULT NULL, `fcm_token` text DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`), UNIQUE KEY `username` (`username`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'settings' => "CREATE TABLE IF NOT EXISTS `settings` (`id` int(11) NOT NULL AUTO_INCREMENT, `madrasa_name` varchar(255) DEFAULT 'مدرسہ اشرف المدارس', `phone` varchar(50) DEFAULT NULL, `address` text DEFAULT NULL, `tagline` varchar(255) DEFAULT NULL, `current_hijri_year` varchar(20) DEFAULT '1447', `week_start_day` int(11) DEFAULT 6, `ai_provider` varchar(50) DEFAULT 'gemini', `auto_notify_day` int(11) DEFAULT 5, `auto_notify_time` varchar(20) DEFAULT '18:00', `logo_path` text DEFAULT NULL, `receipt_bg` text DEFAULT NULL, `masjid_receipt_bg` text DEFAULT NULL, `id_card_bg` text DEFAULT NULL, `sanad_bg` text DEFAULT NULL, `signature_stamp_path` text DEFAULT NULL, `sanad_layout_json` text DEFAULT NULL, `masjid_name` varchar(255) DEFAULT 'جامع مسجد اشرف', `masjid_logo_path` text DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'classes' => "CREATE TABLE IF NOT EXISTS `classes` (`id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(100) NOT NULL, `class_type` varchar(50) DEFAULT 'General', `type` varchar(50) DEFAULT 'General', `status` varchar(50) DEFAULT 'Active', `system_type` varchar(50) DEFAULT 'Madrasa', `main_teacher_ids` text DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'class_periods' => "CREATE TABLE IF NOT EXISTS `class_periods` (`id` int(11) NOT NULL AUTO_INCREMENT, `class_id` int(11) NOT NULL, `period_no` int(11) NOT NULL, `teacher_id` int(11) DEFAULT NULL, `teacher_ids` text DEFAULT NULL, `subject` varchar(100) DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'departments' => "CREATE TABLE IF NOT EXISTS `departments` (`id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(100) NOT NULL, `system_type` varchar(50) DEFAULT 'Madrasa', `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'teachers' => "CREATE TABLE IF NOT EXISTS `teachers` (`id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(100) NOT NULL, `phone` varchar(50) DEFAULT NULL, `qualification` varchar(255) DEFAULT NULL, `address` text DEFAULT NULL, `salary` decimal(10,2) DEFAULT 0.00, `joining_date` date DEFAULT NULL, `system_type` varchar(50) DEFAULT 'Madrasa', `status` varchar(50) DEFAULT 'Active', `assigned_classes` text DEFAULT NULL, `password` varchar(255) DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'students' => "CREATE TABLE IF NOT EXISTS `students` (`id` int(11) NOT NULL AUTO_INCREMENT, `admission_number` varchar(50) NOT NULL, `roll_number` varchar(50) DEFAULT NULL, `name` varchar(100) NOT NULL, `father_name` varchar(100) DEFAULT NULL, `dob` date DEFAULT NULL, `gender` varchar(20) DEFAULT 'Male', `phone` varchar(50) DEFAULT NULL, `mobile_no` varchar(50) DEFAULT NULL, `address` text DEFAULT NULL, `class` varchar(100) DEFAULT NULL, `class_name` varchar(100) DEFAULT NULL, `status` varchar(50) DEFAULT 'Active', `monthly_fee` decimal(10,2) DEFAULT 0.00, `admission_date` date DEFAULT NULL, `system_type` varchar(50) DEFAULT 'Madrasa', `residence_status` varchar(50) DEFAULT 'مستقل', `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'attendance' => "CREATE TABLE IF NOT EXISTS `attendance` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) NOT NULL, `date` date NOT NULL, `status` varchar(50) NOT NULL, `remarks` text DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`), UNIQUE KEY `unique_student_date` (`student_id`,`date`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'attendance_teachers' => "CREATE TABLE IF NOT EXISTS `attendance_teachers` (`id` int(11) NOT NULL AUTO_INCREMENT, `teacher_id` int(11) NOT NULL, `date` date NOT NULL, `status` varchar(50) NOT NULL, `remarks` text DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`), UNIQUE KEY `unique_teacher_date` (`teacher_id`,`date`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'teacher_period_attendance' => "CREATE TABLE IF NOT EXISTS `teacher_period_attendance` (`id` int(11) NOT NULL AUTO_INCREMENT, `teacher_id` int(11) NOT NULL, `class_id` int(11) NOT NULL, `period_no` int(11) NOT NULL, `date` date NOT NULL, `status` varchar(50) NOT NULL, `remarks` text DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'teacher_leaves' => "CREATE TABLE IF NOT EXISTS `teacher_leaves` (`id` int(11) NOT NULL AUTO_INCREMENT, `teacher_id` int(11) NOT NULL, `start_date` date NOT NULL, `end_date` date NOT NULL, `days_count` int(11) DEFAULT 1, `reason` text DEFAULT NULL, `status` varchar(50) DEFAULT 'Approved', `system_type` varchar(50) DEFAULT 'Madrasa', `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'daily_hifz' => "CREATE TABLE IF NOT EXISTS `daily_hifz` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) NOT NULL, `date` date NOT NULL, `sabaq_parah` varchar(50) DEFAULT NULL, `sabaq_lines` varchar(50) DEFAULT NULL, `sabaq_status` varchar(50) DEFAULT 'ممتاز', `sabqi_parah` varchar(50) DEFAULT NULL, `sabqi_amount` varchar(50) DEFAULT '1 پاؤ', `sabqi_status` varchar(50) DEFAULT 'جید', `manzil_parah` varchar(50) DEFAULT NULL, `manzil_amount` varchar(50) DEFAULT 'آدھا پارہ', `manzil_status` varchar(50) DEFAULT 'ممتاز', `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'daily_nazira' => "CREATE TABLE IF NOT EXISTS `daily_nazira` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) NOT NULL, `date` date NOT NULL, `para` varchar(50) DEFAULT NULL, `surah` varchar(100) DEFAULT NULL, `ayat_from` varchar(50) DEFAULT NULL, `ayat_to` varchar(50) DEFAULT NULL, `status` varchar(50) DEFAULT 'مکمل', `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'qaida_records' => "CREATE TABLE IF NOT EXISTS `qaida_records` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) NOT NULL, `date` date NOT NULL, `lesson_number` varchar(50) DEFAULT NULL, `page_number` varchar(50) DEFAULT NULL, `status` varchar(50) DEFAULT 'پاس', `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'husn_saut_record' => "CREATE TABLE IF NOT EXISTS `husn_saut_record` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) NOT NULL, `date` date NOT NULL, `details` text DEFAULT NULL, `status` varchar(50) DEFAULT 'بہترین', `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'academic_reports' => "CREATE TABLE IF NOT EXISTS `academic_reports` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) NOT NULL, `date` date NOT NULL, `report_type` varchar(100) DEFAULT 'ماہانہ جائزہ', `details` text DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'exams' => "CREATE TABLE IF NOT EXISTS `exams` (`id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(100) NOT NULL, `exam_date` date DEFAULT NULL, `system_type` varchar(50) DEFAULT 'Madrasa', `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'exam_results' => "CREATE TABLE IF NOT EXISTS `exam_results` (`id` int(11) NOT NULL AUTO_INCREMENT, `exam_id` int(11) NOT NULL, `student_id` int(11) NOT NULL, `total_marks` decimal(10,2) DEFAULT 100.00, `obtained_marks` decimal(10,2) DEFAULT 0.00, `grade` varchar(20) DEFAULT 'A+', `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'complaints' => "CREATE TABLE IF NOT EXISTS `complaints` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) DEFAULT NULL, `title` varchar(255) DEFAULT NULL, `complaint` text DEFAULT NULL, `description` text DEFAULT NULL, `date` date DEFAULT NULL, `status` varchar(50) DEFAULT 'حل شدہ', `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'parent_complaints' => "CREATE TABLE IF NOT EXISTS `parent_complaints` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) DEFAULT NULL, `category` varchar(100) DEFAULT 'عمومی', `title` varchar(255) DEFAULT NULL, `description` text DEFAULT NULL, `date` date DEFAULT NULL, `status` varchar(50) DEFAULT 'جاری', `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'leave_applications' => "CREATE TABLE IF NOT EXISTS `leave_applications` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) DEFAULT NULL, `teacher_id` int(11) DEFAULT NULL, `reason` text DEFAULT NULL, `date` date DEFAULT NULL, `date_from` date DEFAULT NULL, `date_to` date DEFAULT NULL, `status` varchar(50) DEFAULT 'منظور شدہ', `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'fee_submissions' => "CREATE TABLE IF NOT EXISTS `fee_submissions` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) NOT NULL, `amount` decimal(10,2) NOT NULL, `date` date NOT NULL, `month` varchar(50) DEFAULT NULL, `remarks` text DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'notices' => "CREATE TABLE IF NOT EXISTS `notices` (`id` int(11) NOT NULL AUTO_INCREMENT, `title` varchar(255) NOT NULL, `message` text NOT NULL, `date` date DEFAULT NULL, `system_type` varchar(50) DEFAULT 'Madrasa', `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'assets' => "CREATE TABLE IF NOT EXISTS `assets` (`id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(255) NOT NULL, `category` varchar(100) DEFAULT NULL, `quantity` int(11) DEFAULT 1, `value` decimal(10,2) DEFAULT 0.00, `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'heads' => "CREATE TABLE IF NOT EXISTS `heads` (`id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(100) NOT NULL, `type` varchar(50) NOT NULL, `system_type` varchar(50) DEFAULT 'Madrasa', `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'finance' => "CREATE TABLE IF NOT EXISTS `finance` (`id` int(11) NOT NULL AUTO_INCREMENT, `type` varchar(50) NOT NULL, `amount` decimal(10,2) NOT NULL, `date` date NOT NULL, `head_id` int(11) DEFAULT NULL, `head_name` varchar(100) DEFAULT NULL, `description` text DEFAULT NULL, `spender_name` varchar(100) DEFAULT NULL, `donor_name` varchar(100) DEFAULT NULL, `user_id` int(11) DEFAULT NULL, `user_name` varchar(100) DEFAULT 'Admin', `account_id` int(11) DEFAULT NULL, `system_type` varchar(50) DEFAULT 'Madrasa', `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'staff_loans' => "CREATE TABLE IF NOT EXISTS `staff_loans` (`id` int(11) NOT NULL AUTO_INCREMENT, `teacher_id` int(11) NOT NULL, `amount` decimal(10,2) NOT NULL, `installment_amount` decimal(10,2) DEFAULT 0.00, `remaining_amount` decimal(10,2) DEFAULT 0.00, `status` varchar(50) DEFAULT 'Active', `system_type` varchar(50) DEFAULT 'Madrasa', `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'loan_transactions' => "CREATE TABLE IF NOT EXISTS `loan_transactions` (`id` int(11) NOT NULL AUTO_INCREMENT, `loan_id` int(11) NOT NULL, `amount` decimal(10,2) NOT NULL, `date` date NOT NULL, `notes` text DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'acc_payroll' => "CREATE TABLE IF NOT EXISTS `acc_payroll` (`id` int(11) NOT NULL AUTO_INCREMENT, `teacher_id` int(11) NOT NULL, `month_year` varchar(20) NOT NULL, `basic_salary` decimal(10,2) DEFAULT 0.00, `allowances` decimal(10,2) DEFAULT 0.00, `deductions` decimal(10,2) DEFAULT 0.00, `net_salary` decimal(10,2) DEFAULT 0.00, `status` varchar(50) DEFAULT 'Paid', `payment_date` date DEFAULT NULL, `system_type` varchar(50) DEFAULT 'Madrasa', `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'acc_chart_of_accounts' => "CREATE TABLE IF NOT EXISTS `acc_chart_of_accounts` (`id` int(11) NOT NULL AUTO_INCREMENT, `code` varchar(50) NOT NULL, `name` varchar(100) NOT NULL, `type` varchar(50) NOT NULL, `parent_id` int(11) DEFAULT NULL, `system_type` varchar(50) DEFAULT 'Madrasa', `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'acc_vouchers' => "CREATE TABLE IF NOT EXISTS `acc_vouchers` (`id` int(11) NOT NULL AUTO_INCREMENT, `voucher_no` varchar(50) NOT NULL, `type` varchar(50) NOT NULL, `date` date NOT NULL, `description` text DEFAULT NULL, `system_type` varchar(50) DEFAULT 'Madrasa', `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'acc_ledger_entries' => "CREATE TABLE IF NOT EXISTS `acc_ledger_entries` (`id` int(11) NOT NULL AUTO_INCREMENT, `voucher_id` int(11) NOT NULL, `account_id` int(11) NOT NULL, `debit` decimal(10,2) DEFAULT 0.00, `credit` decimal(10,2) DEFAULT 0.00, `narration` text DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'acc_invoices' => "CREATE TABLE IF NOT EXISTS `acc_invoices` (`id` int(11) NOT NULL AUTO_INCREMENT, `invoice_no` varchar(50) NOT NULL, `student_id` int(11) DEFAULT NULL, `date` date NOT NULL, `due_date` date DEFAULT NULL, `amount` decimal(10,2) NOT NULL, `status` varchar(50) DEFAULT 'Unpaid', `system_type` varchar(50) DEFAULT 'Madrasa', `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'in_kind_donations' => "CREATE TABLE IF NOT EXISTS `in_kind_donations` (`id` int(11) NOT NULL AUTO_INCREMENT, `item_name` varchar(255) NOT NULL, `quantity` int(11) DEFAULT 1, `estimated_value` decimal(10,2) DEFAULT 0.00, `donor_name` varchar(100) DEFAULT NULL, `date` date NOT NULL, `system_type` varchar(50) DEFAULT 'Madrasa', `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    // مکتب / مسجد تعلیمی ماڈیول
    'maktab_classes' => "CREATE TABLE IF NOT EXISTS `maktab_classes` (`id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(100) NOT NULL, `status` varchar(50) DEFAULT 'Active', `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'maktab_teachers' => "CREATE TABLE IF NOT EXISTS `maktab_teachers` (`id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(100) NOT NULL, `phone` varchar(50) DEFAULT NULL, `status` varchar(50) DEFAULT 'Active', `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'maktab_students' => "CREATE TABLE IF NOT EXISTS `maktab_students` (`id` int(11) NOT NULL AUTO_INCREMENT, `admission_number` varchar(50) NOT NULL, `name` varchar(100) NOT NULL, `father_name` varchar(100) DEFAULT NULL, `class` varchar(100) DEFAULT NULL, `status` varchar(50) DEFAULT 'Active', `phone` varchar(50) DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'period_attendance' => "CREATE TABLE IF NOT EXISTS `period_attendance` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) NOT NULL, `date` date NOT NULL, `period_num` int(11) NOT NULL, `status` varchar(50) NOT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'maktab_exams' => "CREATE TABLE IF NOT EXISTS `maktab_exams` (`id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(100) NOT NULL, `exam_date` date DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'maktab_exam_results' => "CREATE TABLE IF NOT EXISTS `maktab_exam_results` (`id` int(11) NOT NULL AUTO_INCREMENT, `exam_id` int(11) NOT NULL, `student_id` int(11) NOT NULL, `obtained_marks` decimal(10,2) DEFAULT 0.00, `total_marks` decimal(10,2) DEFAULT 100.00, `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'maktab_nazira_record' => "CREATE TABLE IF NOT EXISTS `maktab_nazira_record` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) NOT NULL, `date` date NOT NULL, `para` varchar(50) DEFAULT NULL, `surah` varchar(100) DEFAULT NULL, `ayat_from` varchar(50) DEFAULT NULL, `ayat_to` varchar(50) DEFAULT NULL, `status` varchar(50) DEFAULT 'مکمل', `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'maktab_hifz_record' => "CREATE TABLE IF NOT EXISTS `maktab_hifz_record` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) NOT NULL, `date` date NOT NULL, `sabaq` text DEFAULT NULL, `sabqi` text DEFAULT NULL, `manzil` text DEFAULT NULL, `status` varchar(50) DEFAULT 'عمدہ', `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'maktab_qaida_records' => "CREATE TABLE IF NOT EXISTS `maktab_qaida_records` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) NOT NULL, `date` date NOT NULL, `lesson_number` varchar(50) DEFAULT NULL, `status` varchar(50) DEFAULT 'جاری', `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'maktab_academic_reports' => "CREATE TABLE IF NOT EXISTS `maktab_academic_reports` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) NOT NULL, `date` date NOT NULL, `details` text DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'daily_maktab' => "CREATE TABLE IF NOT EXISTS `daily_maktab` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_id` int(11) NOT NULL, `date` date NOT NULL, `morning_sabaq` text DEFAULT NULL, `morning_qty` varchar(50) DEFAULT '1 صفحہ', `morning_time` varchar(20) DEFAULT '07:30', `morning_status` varchar(50) DEFAULT 'مکمل', `evening_sabaq` text DEFAULT NULL, `evening_qty` varchar(50) DEFAULT '1 صفحہ', `evening_time` varchar(20) DEFAULT '17:30', `evening_status` varchar(50) DEFAULT 'مکمل', `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    // ایڈوانس ماڈیولز (Phases 2, 3, 4)
    'admission_inquiries' => "CREATE TABLE IF NOT EXISTS `admission_inquiries` (`id` int(11) NOT NULL AUTO_INCREMENT, `student_name` varchar(100) NOT NULL, `father_name` varchar(100) DEFAULT NULL, `phone` varchar(50) DEFAULT NULL, `target_class` varchar(100) DEFAULT NULL, `status` varchar(50) DEFAULT 'زیر غور', `created_at` timestamp NULL DEFAULT current_timestamp(), `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'lib_books' => "CREATE TABLE IF NOT EXISTS `lib_books` (`id` int(11) NOT NULL AUTO_INCREMENT, `title` varchar(255) NOT NULL, `author` varchar(100) DEFAULT NULL, `category` varchar(100) DEFAULT NULL, `quantity` int(11) DEFAULT 1, `created_at` timestamp NULL DEFAULT current_timestamp(), `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'lib_issues' => "CREATE TABLE IF NOT EXISTS `lib_issues` (`id` int(11) NOT NULL AUTO_INCREMENT, `book_id` int(11) NOT NULL, `student_id` int(11) DEFAULT NULL, `issue_date` date NOT NULL, `return_date` date DEFAULT NULL, `status` varchar(50) DEFAULT 'جاری شدہ', `created_at` timestamp NULL DEFAULT current_timestamp(), `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'inventory_items' => "CREATE TABLE IF NOT EXISTS `inventory_items` (`id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(255) NOT NULL, `category` varchar(100) DEFAULT NULL, `quantity` int(11) DEFAULT 0, `price` decimal(10,2) DEFAULT 0.00, `created_at` timestamp NULL DEFAULT current_timestamp(), `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'inventory_sales' => "CREATE TABLE IF NOT EXISTS `inventory_sales` (`id` int(11) NOT NULL AUTO_INCREMENT, `item_id` int(11) NOT NULL, `quantity` int(11) NOT NULL, `total_amount` decimal(10,2) NOT NULL, `customer_name` varchar(100) DEFAULT NULL, `sale_date` date NOT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'hostel_rooms' => "CREATE TABLE IF NOT EXISTS `hostel_rooms` (`id` int(11) NOT NULL AUTO_INCREMENT, `room_number` varchar(50) NOT NULL, `capacity` int(11) DEFAULT 4, `system_type` varchar(50) DEFAULT 'Madrasa', `created_at` timestamp NULL DEFAULT current_timestamp(), `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'hostel_allocations' => "CREATE TABLE IF NOT EXISTS `hostel_allocations` (`id` int(11) NOT NULL AUTO_INCREMENT, `room_id` int(11) NOT NULL, `student_id` int(11) NOT NULL, `bed_no` varchar(20) DEFAULT '1', `created_at` timestamp NULL DEFAULT current_timestamp(), `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'job_postings' => "CREATE TABLE IF NOT EXISTS `job_postings` (`id` int(11) NOT NULL AUTO_INCREMENT, `title` varchar(255) NOT NULL, `department` varchar(100) DEFAULT NULL, `requirements` text DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'job_applicants' => "CREATE TABLE IF NOT EXISTS `job_applicants` (`id` int(11) NOT NULL AUTO_INCREMENT, `posting_id` int(11) NOT NULL, `applicant_name` varchar(100) NOT NULL, `phone` varchar(50) DEFAULT NULL, `status` varchar(50) DEFAULT 'موصول شدہ', `created_at` timestamp NULL DEFAULT current_timestamp(), `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'web_pages' => "CREATE TABLE IF NOT EXISTS `web_pages` (`id` int(11) NOT NULL AUTO_INCREMENT, `title` varchar(255) NOT NULL, `slug` varchar(100) NOT NULL, `content` text DEFAULT NULL, `sort_order` int(11) DEFAULT 0, `created_at` timestamp NULL DEFAULT current_timestamp(), `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    'announcements' => "CREATE TABLE IF NOT EXISTS `announcements` (`id` int(11) NOT NULL AUTO_INCREMENT, `title` varchar(255) NOT NULL, `body` text DEFAULT NULL, `publish_date` date DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), `is_deleted` tinyint(1) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
];

// ٹیبلز کی خودکار تخلیق
foreach ($tables_sql as $tName => $tSql) {
    try {
        $pdo->exec($tSql);
    } catch (Throwable $e) {}
}

// سیٹنگز ریکارڈ کو یقینی بنائیں
try {
    $hasSettings = $pdo->query("SELECT id FROM settings LIMIT 1")->fetch();
    if (!$hasSettings) {
        $pdo->exec("INSERT INTO settings (id, madrasa_name, current_hijri_year, week_start_day) VALUES (1, 'مدرسہ اشرف المدارس', '1447', 6)");
    }
} catch (Throwable $e) {}

/**
 * کالمز کو خودکار پہچان کر ریکارڈ تلاش کرنے کا محفوظ ترین فنکشن
 */
function find_record_id($pdo, $table, array $candidates) {
    static $col_cache = [];
    if (!isset($col_cache[$table])) {
        try {
            $col_cache[$table] = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            $col_cache[$table] = [];
        }
    }
    $cols = $col_cache[$table];
    if (empty($cols)) return false;

    foreach ($candidates as $col => $val) {
        if (in_array($col, $cols)) {
            try {
                $stmt = $pdo->prepare("SELECT id FROM `$table` WHERE `$col` = ? LIMIT 1");
                $stmt->execute([$val]);
                $res = $stmt->fetchColumn();
                if ($res) return $res;
            } catch (Throwable $e) {}
        }
    }
    return false;
}

/**
 * محفوظ ترین طریقے سے ٹیبل کی آئی ڈیز حاصل کرنا (صرف id پر مشتمل، بغیر کسی اور کالم کے)
 */
function get_table_ids($pdo, $table, $limit = 5) {
    try {
        return $pdo->query("SELECT id FROM `$table` ORDER BY id ASC LIMIT $limit")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * جامع اور مکمل اسمارٹ انسرٹ فنکشن (Bulletproof Smart Insert)
 * یہ فنکشن:
 * ۱. صرف حقیقی کالمز میں ڈیٹا ڈالتا ہے (1054 Unknown column سے نجات)
 * ۲. عددی کالمز کے لیے ڈیٹا کی قسم خودکار درست کرتا ہے (1265 Data truncated سے نجات)
 * ۳. اگر کسی ٹیبل میں NOT NULL کالم بغیر ڈیفالٹ کے رہ گیا ہو تو اسے خودکار بھر دیتا ہے (1364 Field doesn't have default value سے نجات)
 */
function smart_insert($pdo, $table, $data) {
    static $columns_cache = [];
    if (!isset($columns_cache[$table])) {
        try {
            $rows = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            $colMeta = [];
            foreach ($rows as $r) {
                $colMeta[$r['Field']] = [
                    'type' => strtolower($r['Type']),
                    'null' => strtoupper($r['Null']),
                    'default' => $r['Default'],
                    'extra' => strtolower($r['Extra'])
                ];
            }
            $columns_cache[$table] = $colMeta;
        } catch (Throwable $e) {
            $columns_cache[$table] = [];
        }
    }
    $colMeta = $columns_cache[$table];
    if (empty($colMeta)) return false;

    // ۱. عمومی متبادل ناموں (Aliases) کی ہم آہنگی
    if (isset($data['name'])) {
        if (!isset($colMeta['name'])) {
            if (isset($colMeta['class_name'])) $data['class_name'] = $data['name'];
            if (isset($colMeta['teacher_name'])) $data['teacher_name'] = $data['name'];
            if (isset($colMeta['department_name'])) $data['department_name'] = $data['name'];
            if (isset($colMeta['head_name'])) $data['head_name'] = $data['name'];
            if (isset($colMeta['item_name'])) $data['item_name'] = $data['name'];
            if (isset($colMeta['exam_name'])) $data['exam_name'] = $data['name'];
            if (isset($colMeta['title'])) $data['title'] = $data['name'];
        }
    }
    if (isset($data['type']) && !isset($colMeta['type']) && isset($colMeta['class_type'])) {
        $data['class_type'] = $data['type'];
    }
    if (isset($data['class_type']) && !isset($colMeta['class_type']) && isset($colMeta['type'])) {
        $data['type'] = $data['class_type'];
    }
    if (isset($data['class']) && !isset($colMeta['class']) && isset($colMeta['class_name'])) {
        $data['class_name'] = $data['class'];
    }
    if (isset($data['class_name']) && !isset($colMeta['class_name']) && isset($colMeta['class'])) {
        $data['class'] = $data['class_name'];
    }
    if (isset($data['phone']) && !isset($colMeta['phone']) && isset($colMeta['mobile_no'])) {
        $data['mobile_no'] = $data['phone'];
    }
    if (isset($data['admission_number']) && !isset($colMeta['admission_number']) && isset($colMeta['roll_number'])) {
        $data['roll_number'] = $data['admission_number'];
    }
    if (isset($data['roll_number']) && !isset($colMeta['roll_number']) && isset($colMeta['admission_number'])) {
        $data['admission_number'] = $data['roll_number'];
    }

    // ویب پیجز کے کالمز (slug, page_slug, content, content_html)
    if (isset($data['slug']) && !isset($colMeta['slug']) && isset($colMeta['page_slug'])) {
        $data['page_slug'] = $data['slug'];
    }
    if (isset($data['page_slug']) && !isset($colMeta['page_slug']) && isset($colMeta['slug'])) {
        $data['slug'] = $data['page_slug'];
    }
    if (isset($data['content']) && !isset($colMeta['content']) && isset($colMeta['content_html'])) {
        $data['content_html'] = $data['content'];
    }
    if (isset($data['content_html']) && !isset($colMeta['content_html']) && isset($colMeta['content'])) {
        $data['content'] = $data['content_html'];
    }

    // جابز کے کالمز (job_id, posting_id)
    if (isset($data['posting_id']) && !isset($colMeta['posting_id']) && isset($colMeta['job_id'])) {
        $data['job_id'] = $data['posting_id'];
    }
    if (isset($data['job_id']) && !isset($colMeta['job_id']) && isset($colMeta['posting_id'])) {
        $data['posting_id'] = $data['job_id'];
    }

    // انوینٹری کے کالمز (price, unit_price, quantity, current_stock)
    if (isset($data['price']) && !isset($colMeta['price']) && isset($colMeta['unit_price'])) {
        $data['unit_price'] = $data['price'];
    }
    if (isset($data['quantity']) && !isset($colMeta['quantity']) && isset($colMeta['current_stock'])) {
        $data['current_stock'] = $data['quantity'];
    }

    // ہاسٹل کے کالمز (capacity, capacity_beds, bed_no, bed_number)
    if (isset($data['capacity']) && !isset($colMeta['capacity']) && isset($colMeta['capacity_beds'])) {
        $data['capacity_beds'] = $data['capacity'];
    }
    if (isset($data['bed_no']) && !isset($colMeta['bed_no']) && isset($colMeta['bed_number'])) {
        $data['bed_number'] = $data['bed_no'];
    }

    // شکایات والے ٹیبل کے متبادل (complaint column)
    if (isset($colMeta['complaint']) && !isset($data['complaint'])) {
        $cText = ($data['title'] ?? '') . (!empty($data['description']) ? ' - ' . $data['description'] : '');
        $data['complaint'] = !empty($cText) ? $cText : 'شکایت کا اندراج';
    }
    if (isset($colMeta['category']) && !isset($data['category'])) {
        $data['category'] = 'عمومی';
    }

    // رخصت والے ٹیبل کے متبادل (date_from / date_to)
    if (isset($data['date']) && !isset($colMeta['date'])) {
        if (isset($colMeta['date_from'])) $data['date_from'] = $data['date'];
        if (isset($colMeta['date_to'])) $data['date_to'] = $data['date'];
    }

    // حسن صوت والے ٹیبل کے متبادل
    if (isset($colMeta['manzil_sabaq']) && !isset($data['manzil_sabaq'])) {
        $data['manzil_sabaq'] = $data['details'] ?? 'تلاوت و تجوید مشق';
        $data['manzil_grade'] = $data['status'] ?? 'بہترین';
        $data['manzil_listener'] = 'قاری صاحب';
    }

    // ۲. موجود کالمز فلٹر کریں اور ڈیٹا ٹائپ کی ہم آہنگی کریں
    $filtered = [];
    foreach ($data as $k => $v) {
        if (isset($colMeta[$k])) {
            $type = $colMeta[$k]['type'];
            if (preg_match('/^(int|tinyint|smallint|mediumint|bigint)/i', $type)) {
                if (!is_numeric($v)) {
                    preg_match('/[0-9]+/', (string)$v, $m);
                    $v = isset($m[0]) ? intval($m[0]) : 0;
                } else {
                    $v = intval($v);
                }
            } elseif (preg_match('/^(decimal|float|double|numeric)/i', $type)) {
                if (!is_numeric($v)) {
                    preg_match('/[0-9]+(\.[0-9]+)?/', (string)$v, $m);
                    $v = isset($m[0]) ? floatval($m[0]) : 0.0;
                } else {
                    $v = floatval($v);
                }
            }
            $filtered[$k] = $v;
        }
    }

    // ۳. لازمی (NOT NULL) کالمز کو خودکار پر کریں تاکہ Error 1364 کا نام و نشان نہ رہے اور Unique Constraint بھی محفوظ رہے
    foreach ($colMeta as $fName => $meta) {
        if ($meta['null'] === 'NO' && $meta['default'] === null && strpos($meta['extra'], 'auto_increment') === false) {
            if (!array_key_exists($fName, $filtered)) {
                $type = $meta['type'];
                if (preg_match('/^(int|tinyint|smallint|mediumint|bigint)/i', $type)) {
                    $filtered[$fName] = 0;
                } elseif (preg_match('/^(decimal|float|double|numeric)/i', $type)) {
                    $filtered[$fName] = 0.00;
                } elseif (preg_match('/^(date)/i', $type)) {
                    $filtered[$fName] = date('Y-m-d');
                } elseif (preg_match('/^(datetime|timestamp)/i', $type)) {
                    $filtered[$fName] = date('Y-m-d H:i:s');
                } else {
                    // منفرد ویلیو دیں تاکہ UNIQUE constraint میں 1062 Duplicate entry نہ آئے
                    $filtered[$fName] = 'val_' . substr(md5(uniqid((string)rand(), true)), 0, 8);
                }
            }
        }
    }

    if (empty($filtered)) return false;

    $colNames = array_keys($filtered);
    $placeholders = array_fill(0, count($colNames), '?');
    // INSERT IGNORE کا استعمال تاکہ کسی بھی صورت 1062 Duplicate entry ایرر نہ آئے
    $sql = "INSERT IGNORE INTO `$table` (`" . implode('`, `', $colNames) . "`) VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($filtered));
    return $pdo->lastInsertId();
}

// ڈیمو ڈیٹا داخل کرنے کا ہینڈلر
if (isset($_POST['seed_all_demo'])) {
    $seeded_count = 0;
    $errors_list = [];

    // ۱. کلاسز (Classes - 5)
    try {
        $classes = [
            ['حفظ القرآن الکریم', 'Hifz', 'Active', 'Madrasa'],
            ['ناظرہ قرآن و تجوید', 'Nazira', 'Active', 'Madrasa'],
            ['درجہ اولیٰ (درس نظامی)', 'Dars-e-Nizami', 'Active', 'Madrasa'],
            ['قاعدہ و ابتدائی دینیات', 'Qaida', 'Active', 'Madrasa'],
            ['مکتب بعد از نمازِ عصر', 'General', 'Active', 'Masjid']
        ];
        foreach ($classes as $c) {
            if (!find_record_id($pdo, 'classes', ['name' => $c[0], 'class_name' => $c[0], 'title' => $c[0]])) {
                smart_insert($pdo, 'classes', [
                    'name' => $c[0],
                    'class_name' => $c[0],
                    'class_type' => $c[1],
                    'type' => $c[1],
                    'status' => $c[2],
                    'system_type' => $c[3]
                ]);
            }
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Classes: " . $e->getMessage(); }
    $c_keys = get_table_ids($pdo, 'classes', 5);

    // ۲. شعبہ جات (Departments - 5)
    try {
        $depts = [
            ['شعبہ تعلیمات', 'Madrasa'],
            ['شعبہ حفظ و تجوید', 'Madrasa'],
            ['شعبہ مالیات و حسابات', 'Madrasa'],
            ['شعبہ کتب خانہ و نشر و اشاعت', 'Madrasa'],
            ['شعبہ امورِ مسجد و مکتب', 'Masjid']
        ];
        foreach ($depts as $d) {
            if (!find_record_id($pdo, 'departments', ['name' => $d[0], 'department_name' => $d[0], 'title' => $d[0]])) {
                smart_insert($pdo, 'departments', [
                    'name' => $d[0],
                    'department_name' => $d[0],
                    'system_type' => $d[1]
                ]);
            }
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Departments: " . $e->getMessage(); }

    // ۳. اساتذہ (Teachers - 5)
    try {
        $teachers = [
            ['قاری محمد بلال اشرفی', '03001234501', 'فاضل درس نظامی و قاری سبعہ', 'مدرسہ کیمپس بلاک A', 35000.00, '2023-01-01', 'Madrasa', 'Active'],
            ['مولانا عبد الرحمٰن قاسمی', '03001234502', 'ایم اے اسلامیات و شہادت العالمیہ', 'محلہ اشرفیہ گلی نمبر 2', 40000.00, '2022-08-15', 'Madrasa', 'Active'],
            ['قاری احمد حسن رضوی', '03001234503', 'حافظ و قاری و معلم اطفال', 'مدرسہ ہاسٹل کمرہ 3', 30000.00, '2023-05-10', 'Madrasa', 'Active'],
            ['حافظ زبیر احمد صدیقی', '03001234504', 'حفظ قرآن و تجوید اتقان', 'نزد جامع مسجد اشرف', 32000.00, '2023-09-01', 'Madrasa', 'Active'],
            ['قاری حماد اللہ فاروقی', '03001234505', 'فاضل تجوید و قراءت', 'سول لائنز ہاؤس 14', 28000.00, '2024-01-01', 'Masjid', 'Active']
        ];
        foreach ($teachers as $t) {
            if (!find_record_id($pdo, 'teachers', ['name' => $t[0], 'teacher_name' => $t[0]])) {
                smart_insert($pdo, 'teachers', [
                    'name' => $t[0],
                    'teacher_name' => $t[0],
                    'phone' => $t[1],
                    'qualification' => $t[2],
                    'address' => $t[3],
                    'salary' => $t[4],
                    'joining_date' => $t[5],
                    'system_type' => $t[6],
                    'status' => $t[7]
                ]);
            }
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Teachers: " . $e->getMessage(); }
    $t_keys = get_table_ids($pdo, 'teachers', 5);

    // ۴. اوقات اور پیریڈز (Class Periods - 5)
    try {
        for ($p = 1; $p <= 5; $p++) {
            $cid = !empty($c_keys) ? $c_keys[($p - 1) % count($c_keys)] : 1;
            $tid = !empty($t_keys) ? $t_keys[($p - 1) % count($t_keys)] : 1;
            $sub = ["حفظ قرآن", "تجوید و مخارج", "فقہ و حدیث", "نورانی قاعدہ", "دینی مسائل"][$p - 1];
            try {
                $st = $pdo->prepare("SELECT id FROM class_periods WHERE class_id = ? AND period_no = ?");
                $st->execute([$cid, $p]);
                if (!$st->fetch()) {
                    smart_insert($pdo, 'class_periods', [
                        'class_id' => $cid,
                        'period_no' => $p,
                        'teacher_id' => $tid,
                        'subject' => $sub
                    ]);
                }
            } catch (Throwable $e) {}
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Class Periods: " . $e->getMessage(); }

    // ۵. طلبہ (Students - 5)
    try {
        $students = [
            ['STD-101', '101', 'محمد عثمان', 'محمد یوسف', '2012-04-10', 'Male', '03121112221', '03121112221', 'گلی 4 اشرف نگر', 'حفظ القرآن الکریم', 'حفظ القرآن الکریم', 'Active', 2500.00, '2024-01-10', 'Madrasa'],
            ['STD-102', '102', 'عبد اللہ فاروق', 'فاروق اعظم', '2013-08-15', 'Male', '03121112222', '03121112222', 'محلہ عثمانیہ', 'حفظ القرآن الکریم', 'حفظ القرآن الکریم', 'Active', 2000.00, '2024-01-12', 'Madrasa'],
            ['STD-103', '103', 'عمر فاروق خان', 'احمد خان', '2014-02-20', 'Male', '03121112223', '03121112223', 'مین بازار مکان 5', 'ناظرہ قرآن و تجوید', 'ناظرہ قرآن و تجوید', 'Active', 1500.00, '2024-02-01', 'Madrasa'],
            ['STD-104', '104', 'حسن علی ہاشمی', 'علی مرتضیٰ', '2015-06-11', 'Male', '03121112224', '03121112224', 'نزد ریلوے روڈ', 'قاعدہ و ابتدائی دینیات', 'قاعدہ و ابتدائی دینیات', 'Active', 1200.00, '2024-02-15', 'Madrasa'],
            ['STD-105', '105', 'بلال اختر صدیقی', 'اختر حسین', '2011-11-25', 'Male', '03121112225', '03121112225', 'گلشن اقبال بلاک 3', 'درجہ اولیٰ (درس نظامی)', 'درجہ اولیٰ (درس نظامی)', 'Active', 3000.00, '2023-08-20', 'Madrasa'],
        ];
        foreach ($students as $s) {
            if (!find_record_id($pdo, 'students', ['admission_number' => $s[0], 'roll_number' => $s[1], 'name' => $s[2]])) {
                smart_insert($pdo, 'students', [
                    'admission_number' => $s[0],
                    'roll_number' => $s[1],
                    'name' => $s[2],
                    'father_name' => $s[3],
                    'dob' => $s[4],
                    'gender' => $s[5],
                    'phone' => $s[6],
                    'mobile_no' => $s[7],
                    'address' => $s[8],
                    'class' => $s[9],
                    'class_name' => $s[10],
                    'status' => $s[11],
                    'monthly_fee' => $s[12],
                    'admission_date' => $s[13],
                    'system_type' => $s[14]
                ]);
            }
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Students: " . $e->getMessage(); }
    $s_keys = get_table_ids($pdo, 'students', 5);

    $today = date('Y-m-d');
    $dates = [
        date('Y-m-d', strtotime('-4 days')),
        date('Y-m-d', strtotime('-3 days')),
        date('Y-m-d', strtotime('-2 days')),
        date('Y-m-d', strtotime('-1 days')),
        $today
    ];

    // ۶. حاضری طلبہ (Student Attendance - 5)
    try {
        for ($i = 0; $i < 5; $i++) {
            $sid = !empty($s_keys) ? $s_keys[$i % count($s_keys)] : 1;
            $dt = $dates[$i];
            try {
                $st = $pdo->prepare("SELECT id FROM attendance WHERE student_id = ? AND date = ?");
                $st->execute([$sid, $dt]);
                if (!$st->fetch()) {
                    smart_insert($pdo, 'attendance', [
                        'student_id' => $sid,
                        'date' => $dt,
                        'status' => 'حاضر',
                        'remarks' => 'وقت پر حاضر'
                    ]);
                }
            } catch (Throwable $e) {}
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Attendance: " . $e->getMessage(); }

    // ۷. حاضری اساتذہ (Teacher Attendance - 5)
    try {
        for ($i = 0; $i < 5; $i++) {
            $tid = !empty($t_keys) ? $t_keys[$i % count($t_keys)] : 1;
            $dt = $dates[$i];
            try {
                $st = $pdo->prepare("SELECT id FROM attendance_teachers WHERE teacher_id = ? AND date = ?");
                $st->execute([$tid, $dt]);
                if (!$st->fetch()) {
                    smart_insert($pdo, 'attendance_teachers', [
                        'teacher_id' => $tid,
                        'date' => $dt,
                        'status' => 'حاضر',
                        'remarks' => 'پابندِ وقت'
                    ]);
                }
            } catch (Throwable $e) {}
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Teacher Attendance: " . $e->getMessage(); }

    // ۸. پیریڈ حاضری اساتذہ (Teacher Period Attendance - 5)
    try {
        for ($i = 0; $i < 5; $i++) {
            $tid = !empty($t_keys) ? $t_keys[$i % count($t_keys)] : 1;
            $cid = !empty($c_keys) ? $c_keys[$i % count($c_keys)] : 1;
            $dt = $dates[$i];
            smart_insert($pdo, 'teacher_period_attendance', [
                'teacher_id' => $tid,
                'class_id' => $cid,
                'period_no' => $i + 1,
                'date' => $dt,
                'status' => 'حاضر',
                'remarks' => 'پیریڈ مکمل پڑھایا'
            ]);
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Teacher Period Attendance: " . $e->getMessage(); }

    // ۹. اساتذہ رخصتیں (Teacher Leaves - 5)
    try {
        for ($i = 0; $i < 5; $i++) {
            $tid = !empty($t_keys) ? $t_keys[$i % count($t_keys)] : 1;
            $reasons = ['ضروری گھریلو کام', 'طبیعت ناساز', 'تقریب میں شرکت', 'سفر کا عذر', 'ہنگامی کام'];
            smart_insert($pdo, 'teacher_leaves', [
                'teacher_id' => $tid,
                'start_date' => $dates[$i],
                'end_date' => $dates[$i],
                'days_count' => 1,
                'reason' => $reasons[$i],
                'status' => 'Approved',
                'system_type' => 'Madrasa'
            ]);
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Teacher Leaves: " . $e->getMessage(); }

    // ۱۰. روزانہ حفظ ریکارڈ (Daily Hifz - 5)
    try {
        $hifz_samples = [
            ['پارہ 1', 16, 'ممتاز', 'پارہ 1', '1 پاؤ', 'جید', 'پارہ 30', '1 پارہ', 'ممتاز'],
            ['پارہ 2', 14, 'عمدہ', 'پارہ 1', 'آدھا پارہ', 'ممتاز', 'پارہ 29', '1 پارہ', 'جید'],
            ['پارہ 5', 16, 'ممتاز', 'پارہ 4', '1 پاؤ', 'ممتاز', 'پارہ 28', '1 پارہ', 'ممتاز'],
            ['پارہ 10', 15, 'جید جداً', 'پارہ 9', 'پون پارہ', 'عمدہ', 'پارہ 27', '1 پارہ', 'عمدہ'],
            ['پارہ 15', 16, 'ممتاز', 'پارہ 14', '1 پاؤ', 'ممتاز', 'پارہ 26', '1 پارہ', 'ممتاز'],
        ];
        for ($i = 0; $i < 5; $i++) {
            $sid = !empty($s_keys) ? $s_keys[$i % count($s_keys)] : 1;
            $h = $hifz_samples[$i];
            smart_insert($pdo, 'daily_hifz', [
                'student_id' => $sid,
                'date' => $dates[$i],
                'sabaq_parah' => $h[0],
                'sabaq_lines' => $h[1],
                'sabaq_status' => $h[2],
                'sabqi_parah' => $h[3],
                'sabqi_amount' => $h[4],
                'sabqi_status' => $h[5],
                'manzil_parah' => $h[6],
                'manzil_amount' => $h[7],
                'manzil_status' => $h[8]
            ]);
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Daily Hifz: " . $e->getMessage(); }

    // ۱۱. روزانہ ناظرہ ریکارڈ (Daily Nazira - 5)
    try {
        $nazira_samples = [
            ['پارہ 1', 'سورۃ البقرۃ', '1', '25', 'مکمل و تجوید درست'],
            ['پارہ 2', 'سورۃ البقرۃ', '142', '160', 'بہترین روانگی'],
            ['پارہ 3', 'سورۃ آل عمران', '1', '20', 'اچھے تلفظ کے ساتھ'],
            ['پارہ 4', 'سورۃ النساء', '1', '15', 'ماشاء اللہ بہت اچھا'],
            ['پارہ 30', 'سورۃ النباء', '1', '40', 'مکمل روانی سے پڑھا'],
        ];
        for ($i = 0; $i < 5; $i++) {
            $sid = !empty($s_keys) ? $s_keys[$i % count($s_keys)] : 1;
            $n = $nazira_samples[$i];
            smart_insert($pdo, 'daily_nazira', [
                'student_id' => $sid,
                'date' => $dates[$i],
                'para' => $n[0],
                'surah' => $n[1],
                'ayat_from' => $n[2],
                'ayat_to' => $n[3],
                'status' => $n[4]
            ]);
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Daily Nazira: " . $e->getMessage(); }

    // ۱۲. نورانی قاعدہ ریکارڈز (Qaida Records - 5)
    try {
        for ($i = 0; $i < 5; $i++) {
            $sid = !empty($s_keys) ? $s_keys[$i % count($s_keys)] : 1;
            smart_insert($pdo, 'qaida_records', [
                'student_id' => $sid,
                'date' => $dates[$i],
                'lesson_number' => "تختی نمبر " . ($i + 1),
                'page_number' => ($i + 5),
                'status' => 'پاس'
            ]);
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Qaida Records: " . $e->getMessage(); }

    // ۱۳. حسن صوت ریکارڈز (Husn-e-Saut Records - 5)
    try {
        $husn_samples = [
            'سورۃ الرحمٰن کی ترتیل بہترین لہجے میں سنائی',
            'سورۃ الفاتحہ کے مخارج اور مدات پر خصوصی مشق',
            'لحنِ حجاز میں آیات تلاوت کیں، کارکردگی عمدہ رہی',
            'وقف و ابتدا کی پابندی بہت شاندار رہی',
            'آواز کا اتار چڑھاؤ اور ادائیگی قابل تحسین ہے'
        ];
        for ($i = 0; $i < 5; $i++) {
            $sid = !empty($s_keys) ? $s_keys[$i % count($s_keys)] : 1;
            smart_insert($pdo, 'husn_saut_record', [
                'student_id' => $sid,
                'date' => $dates[$i],
                'details' => $husn_samples[$i],
                'manzil_sabaq' => $husn_samples[$i],
                'status' => 'بہترین'
            ]);
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Husn Saut: " . $e->getMessage(); }

    // ۱۴. تعلیمی ماہانہ رپورٹس (Academic Reports - 5)
    try {
        $rep_samples = [
            ['ماہانہ تعلیمی جائزہ', 'طالب علم کا حفظ کا معیار بہت عمدہ ہے اور پابند وقت ہے'],
            ['سہ ماہی رپورٹ', 'اخلاق و کردار بہترین، اسباق کی تیاری تسلی بخش ہے'],
            ['ماہانہ جائزہ', 'تجوید میں نمایاں بہتری، مخارج پر مزید محنت جاری رکھے'],
            ['شش ماہی کارکردگی', 'کلاس میں اول پوزیشن کے امیدوار ہیں، محنت قابل تعریف ہے'],
            ['ماہانہ تعلیمی جائزہ', 'حاضری 100 فیصد رہی اور روزانہ سبق باقاعدگی سے سنایا']
        ];
        for ($i = 0; $i < 5; $i++) {
            $sid = !empty($s_keys) ? $s_keys[$i % count($s_keys)] : 1;
            smart_insert($pdo, 'academic_reports', [
                'student_id' => $sid,
                'date' => $dates[$i],
                'report_type' => $rep_samples[$i][0],
                'details' => $rep_samples[$i][1]
            ]);
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Academic Reports: " . $e->getMessage(); }

    // ۱۵. امتحانات (Exams - 5)
    try {
        $exam_list = [
            ['سہ ماہی امتحان 2025', '2025-03-15', 'Madrasa'],
            ['شش ماہی امتحان 2025', '2025-06-20', 'Madrasa'],
            ['سالانہ امتحان 2025', '2025-11-10', 'Madrasa'],
            ['ماہانہ ٹیسٹ حفظ و تجوید', '2025-01-25', 'Madrasa'],
            ['مکتب سہ ماہی جائزہ', '2025-04-01', 'Masjid']
        ];
        foreach ($exam_list as $ex) {
            if (!find_record_id($pdo, 'exams', ['name' => $ex[0], 'exam_name' => $ex[0], 'title' => $ex[0]])) {
                smart_insert($pdo, 'exams', [
                    'name' => $ex[0],
                    'exam_name' => $ex[0],
                    'title' => $ex[0],
                    'exam_date' => $ex[1],
                    'system_type' => $ex[2]
                ]);
            }
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Exams: " . $e->getMessage(); }
    $ex_keys = get_table_ids($pdo, 'exams', 5);

    // ۱۶. امتحانی نتائج (Exam Results - 5)
    try {
        $marks = [98.5, 94.0, 89.5, 92.0, 96.0];
        $grades = ['A+', 'A+', 'A', 'A+', 'A+'];
        for ($i = 0; $i < 5; $i++) {
            $exid = !empty($ex_keys) ? $ex_keys[$i % count($ex_keys)] : 1;
            $sid = !empty($s_keys) ? $s_keys[$i % count($s_keys)] : 1;
            smart_insert($pdo, 'exam_results', [
                'exam_id' => $exid,
                'student_id' => $sid,
                'total_marks' => 100.00,
                'obtained_marks' => $marks[$i],
                'grade' => $grades[$i]
            ]);
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Exam Results: " . $e->getMessage(); }

    // ۱۷. شکایات اور تجاویز (Complaints - 5)
    try {
        $comp_samples = [
            ['پانی کی فراہمی', 'ہاسٹل کے واش روم میں پانی کا پریشر کم ہے'],
            ['لائبریری کتب', 'تفسیر کی نئی جلدیں لائبریری میں مہیا کی جائیں'],
            ['کلاس روم پنکھا', 'کلاس روم نمبر 3 کا پنکھا شور کر رہا ہے مرمت درکار ہے'],
            ['کھانے کا مینو', 'ہاسٹل کے کھانے میں سبزی کے دن تبدیلی کی جائے'],
            ['لائٹ کا مسئلہ', 'رات کے وقت مطالعہ ہال میں اضافی لائٹ لگائی جائے']
        ];
        for ($i = 0; $i < 5; $i++) {
            $sid = !empty($s_keys) ? $s_keys[$i % count($s_keys)] : 1;
            smart_insert($pdo, 'complaints', [
                'student_id' => $sid,
                'title' => $comp_samples[$i][0],
                'description' => $comp_samples[$i][1],
                'complaint' => $comp_samples[$i][0] . ': ' . $comp_samples[$i][1],
                'date' => $dates[$i],
                'status' => 'حل شدہ'
            ]);
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Complaints: " . $e->getMessage(); }

    // ۱۸. والدین کی شکایات / آراء (Parent Complaints - 5)
    try {
        $pcomp_samples = [
            ['گاڑی کا وقت', 'مدرسہ وین صبح 10 منٹ تاخیر سے پہنچتی ہے'],
            ['چھٹی کی اطلاع', 'جمعرات کو وقت سے پہلے چھٹی کی ایس ایم ایس اطلاع دی جائے'],
            ['صحت کا خیال', 'بچے کو گلے میں خراش ہے ٹھنڈے پانی سے پرہیز کروائیں'],
            ['فیس رسید', 'آن لائن فیس ادائیگی کی تصدیقی رسید فراہم کی جائے'],
            ['ماہانہ میٹنگ', 'والدین اور اساتذہ کی میٹنگ کے اوقات عصر بعد رکھے جائیں']
        ];
        for ($i = 0; $i < 5; $i++) {
            $sid = !empty($s_keys) ? $s_keys[$i % count($s_keys)] : 1;
            smart_insert($pdo, 'parent_complaints', [
                'student_id' => $sid,
                'title' => $pcomp_samples[$i][0],
                'category' => 'عمومی',
                'description' => $pcomp_samples[$i][1],
                'date' => $dates[$i],
                'status' => 'ملاحظہ شدہ'
            ]);
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Parent Complaints: " . $e->getMessage(); }

    // ۱۹. طلبہ رخصت کی درخواستیں (Leave Applications - 5)
    try {
        $reasons_std = ['بہن کی شادی میں شرکت', 'تیز بخار کا عذر', 'والد صاحب کے ساتھ ضروری سفر', 'دانت میں شدید درد', 'گھریلو تقریب'];
        for ($i = 0; $i < 5; $i++) {
            $sid = !empty($s_keys) ? $s_keys[$i % count($s_keys)] : 1;
            smart_insert($pdo, 'leave_applications', [
                'student_id' => $sid,
                'reason' => $reasons_std[$i],
                'date' => $dates[$i],
                'date_from' => $dates[$i],
                'date_to' => $dates[$i],
                'status' => 'منظور شدہ'
            ]);
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Leave Applications: " . $e->getMessage(); }

    // ۲۰. اعلانات (Notices - 5)
    try {
        $notices = [
            ['رمضان المبارک کے اوقات کار', 'یکم رمضان سے کلاسز صبح 8 تا دوپہر 1 بجے تک ہوں گی۔', $today, 'Madrasa'],
            ['سہ ماہی امتحانات کا شیڈول', 'تمام طلبہ کو مطلع کیا جاتا ہے کہ امتحانات 15 تاریخ سے شروع ہوں گے۔', $dates[1], 'Madrasa'],
            ['دار الاقامہ صفائی مہم', 'تمام ہاسٹل طلبہ اتوار کے روز اپنی الماریاں اور کمرے صاف رکھیں۔', $dates[2], 'Madrasa'],
            ['نمازِ جمعہ کے خصوصی انتظامات', 'جمعہ المبارک کی پہلی اذان 12:45 پر اور خطبہ 1:15 پر ہوگا۔', $dates[3], 'Masjid'],
            ['عطیات و زکوٰۃ کی اپیل', 'مدرسہ و مسجد کے مستحق طلبہ کے تعلیمی اخراجات کے لیے تعاون فرمائیں۔', $dates[4], 'Madrasa'],
        ];
        foreach ($notices as $nt) {
            if (!find_record_id($pdo, 'notices', ['title' => $nt[0], 'name' => $nt[0]])) {
                smart_insert($pdo, 'notices', [
                    'title' => $nt[0],
                    'name' => $nt[0],
                    'message' => $nt[1],
                    'date' => $nt[2],
                    'system_type' => $nt[3]
                ]);
            }
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Notices: " . $e->getMessage(); }

    // ۲۱. اثاثہ جات (Assets - 5)
    try {
        $assets = [
            ['واٹر کولر 65 لیٹر مع فلٹر', 'الیکٹرونکس', 2, 45000.00],
            ['سولر سسٹم یو پی ایس 5 کے وی', 'بجلی آلات', 1, 350000.00],
            ['ووڈن ڈیسک و بینچ طلبہ', 'فرنیچر', 50, 150000.00],
            ['ساؤنڈ سسٹم مسجد و ہال', 'آڈیو', 1, 85000.00],
            ['کمپیوٹر کور آئی فائیو دفتری', 'آئی ٹی', 2, 90000.00]
        ];
        foreach ($assets as $as) {
            if (!find_record_id($pdo, 'assets', ['name' => $as[0], 'asset_name' => $as[0], 'item_name' => $as[0]])) {
                smart_insert($pdo, 'assets', [
                    'name' => $as[0],
                    'asset_name' => $as[0],
                    'item_name' => $as[0],
                    'category' => $as[1],
                    'quantity' => $as[2],
                    'value' => $as[3]
                ]);
            }
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Assets: " . $e->getMessage(); }

    // ۲۲. مالیاتی مدات (Finance Heads - 10: 5 Income, 5 Expense)
    try {
        $heads = [
            ['ماہانہ تعلیمی فیس', 'Income', 'Madrasa'],
            ['عطیات و صدقات عامہ', 'Income', 'Madrasa'],
            ['امداد زکوٰۃ مستحقین', 'Income', 'Madrasa'],
            ['مسجد فنڈ و چندہ جمعہ', 'Income', 'Masjid'],
            ['داخلہ فیس و کتب', 'Income', 'Madrasa'],
            ['اساتذہ و عملہ مشاہرہ', 'Expense', 'Madrasa'],
            ['بجلی و گیس کے بلات', 'Expense', 'Madrasa'],
            ['طعام و ہاسٹل راشن', 'Expense', 'Madrasa'],
            ['مرمت و مسجد مینٹیننس', 'Expense', 'Masjid'],
            ['اسٹیشنری و امتحانی کاغذات', 'Expense', 'Madrasa'],
        ];
        foreach ($heads as $h) {
            if (!find_record_id($pdo, 'heads', ['name' => $h[0], 'head_name' => $h[0], 'title' => $h[0]])) {
                smart_insert($pdo, 'heads', [
                    'name' => $h[0],
                    'head_name' => $h[0],
                    'type' => $h[1],
                    'system_type' => $h[2]
                ]);
            }
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Heads: " . $e->getMessage(); }
    $h_keys = get_table_ids($pdo, 'heads', 10);

    // ۲۳. فیس وصولیاں (Fee Submissions - 5)
    try {
        $fee_amounts = [2500.00, 2000.00, 1500.00, 1200.00, 3000.00];
        for ($i = 0; $i < 5; $i++) {
            $sid = !empty($s_keys) ? $s_keys[$i % count($s_keys)] : 1;
            smart_insert($pdo, 'fee_submissions', [
                'student_id' => $sid,
                'amount' => $fee_amounts[$i],
                'date' => $dates[$i],
                'month' => 'جنوری 2025',
                'remarks' => 'مکمل فیس وصول بذریعہ کیش'
            ]);
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Fee Submissions: " . $e->getMessage(); }

    // ۲۴. مالیاتی ٹرانزیکشنز (Finance Transactions - 5 Madrasa, 5 Masjid)
    try {
        $fin_entries = [
            ['Income', 15000.00, 'عطیات و صدقات عامہ', 'حاجی محمد اقبال صاحب کی طرف سے عطیہ', 'حاجی اقبال', '', 'Madrasa'],
            ['Income', 25000.00, 'مسجد فنڈ و چندہ جمعہ', 'جمعہ مبارک پر نمازیوں کا نقد چندہ', 'اہلِ محلہ', '', 'Masjid'],
            ['Income', 50000.00, 'امداد زکوٰۃ مستحقین', 'مستحق طلبہ کی کفالت کی مد میں زکوٰۃ', 'سیٹھ عبد الکریم', '', 'Madrasa'],
            ['Income', 12000.00, 'مسجد فنڈ و چندہ جمعہ', 'مسجد سولر پروجیکٹ میں نقد تعاون', 'چوہدری اکرم', '', 'Masjid'],
            ['Income', 8000.00, 'داخلہ فیس و کتب', 'نئے داخل ہونے والے طلبہ کی فیس', 'دفتر تعلیمات', '', 'Madrasa'],
            ['Expense', 35000.00, 'اساتذہ و عملہ مشاہرہ', 'قاری محمد بلال صاحب ماہانہ تنخواہ', '', 'قاری بلال', 'Madrasa'],
            ['Expense', 18500.00, 'بجلی و گیس کے بلات', 'مدرسہ مین میٹر بل ادائیگی', '', 'لیسکو / واپڈا', 'Madrasa'],
            ['Expense', 7500.00, 'مرمت و مسجد مینٹیننس', 'وضو خانہ نل اور پائپ کی مرمت', '', 'پلمبر رشید', 'Masjid'],
            ['Expense', 22000.00, 'طعام و ہاسٹل راشن', 'ہاسٹل طلبہ کے لیے آٹا اور گھی کی خریداری', '', 'غلہ منڈی تاجر', 'Madrasa'],
            ['Expense', 4500.00, 'مرمت و مسجد مینٹیننس', 'مسجد ساؤنڈ مائیک اور کیبل کی درستگی', '', 'الیکٹرانکس شاپ', 'Masjid'],
        ];
        for ($i = 0; $i < count($fin_entries); $i++) {
            $f = $fin_entries[$i];
            $hid = !empty($h_keys) ? $h_keys[$i % count($h_keys)] : null;
            $dt = $dates[$i % count($dates)];
            smart_insert($pdo, 'finance', [
                'type' => $f[0],
                'amount' => $f[1],
                'date' => $dt,
                'head_id' => $hid,
                'head_name' => $f[2],
                'description' => $f[3],
                'donor_name' => $f[4],
                'spender_name' => $f[5],
                'user_name' => 'Admin',
                'system_type' => $f[6]
            ]);
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Finance: " . $e->getMessage(); }

    // ۲۵. عملہ قرضہ جات اور اقساط (Staff Loans - 5)
    try {
        for ($i = 0; $i < 5; $i++) {
            $tid = !empty($t_keys) ? $t_keys[$i % count($t_keys)] : 1;
            $loan_amt = 20000.00;
            $inst_amt = 5000.00;
            $rem_amt = 15000.00;
            $lid = smart_insert($pdo, 'staff_loans', [
                'teacher_id' => $tid,
                'amount' => $loan_amt,
                'installment_amount' => $inst_amt,
                'remaining_amount' => $rem_amt,
                'status' => 'Active',
                'system_type' => 'Madrasa'
            ]);

            if ($lid) {
                smart_insert($pdo, 'loan_transactions', [
                    'loan_id' => $lid,
                    'amount' => $inst_amt,
                    'date' => $dates[$i],
                    'notes' => 'تنخواہ سے پہلی قسط کٹوتی'
                ]);
            }
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Staff Loans: " . $e->getMessage(); }

    // ۲۶. ماہانہ پے رول سلپس (Payroll - 5)
    try {
        for ($i = 0; $i < 5; $i++) {
            $tid = !empty($t_keys) ? $t_keys[$i % count($t_keys)] : 1;
            $sal = 32000.00;
            smart_insert($pdo, 'acc_payroll', [
                'teacher_id' => $tid,
                'month_year' => '2025-01',
                'basic_salary' => $sal,
                'allowances' => 2000.00,
                'deductions' => 1000.00,
                'net_salary' => ($sal + 1000.00),
                'status' => 'Paid',
                'payment_date' => $dates[$i],
                'system_type' => 'Madrasa'
            ]);
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Payroll: " . $e->getMessage(); }

    // ۲۷. ڈبل انٹری چارٹ آف اکاؤنٹس (Chart of Accounts - 5)
    try {
        $chart = [
            ['1001', 'کیش ان ہینڈ (Cash in Hand)', 'Asset', 'Madrasa'],
            ['1002', 'میزان بینک اکاؤنٹ (Bank Account)', 'Asset', 'Madrasa'],
            ['2001', 'اساتذہ تنخواہ واجب الادا (Salary Payable)', 'Liability', 'Madrasa'],
            ['3001', 'ماہانہ فیس آمدن (Fee Revenue)', 'Income', 'Madrasa'],
            ['4001', 'عمومی اخراجات آفس (Office Expense)', 'Expense', 'Madrasa']
        ];
        foreach ($chart as $ch) {
            if (!find_record_id($pdo, 'acc_chart_of_accounts', ['code' => $ch[0], 'name' => $ch[1]])) {
                smart_insert($pdo, 'acc_chart_of_accounts', [
                    'code' => $ch[0],
                    'name' => $ch[1],
                    'type' => $ch[2],
                    'system_type' => $ch[3]
                ]);
            }
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Chart of Accounts: " . $e->getMessage(); }
    $a_keys = get_table_ids($pdo, 'acc_chart_of_accounts', 5);

    // ۲۸. واؤچرز اور لیجر انٹریز (Vouchers & Ledger Entries - 5)
    try {
        $v_types = ['CPV', 'CRV', 'BPV', 'BRV', 'JV'];
        for ($i = 0; $i < 5; $i++) {
            $vno = "VCH-" . (1000 + $i + 1);
            $vid = smart_insert($pdo, 'acc_vouchers', [
                'voucher_no' => $vno,
                'type' => $v_types[$i],
                'date' => $dates[$i],
                'description' => 'ماہانہ اکاؤنٹنگ ٹرانزیکشن واؤچر برائے ٹیسٹنگ',
                'system_type' => 'Madrasa'
            ]);

            if ($vid && !empty($a_keys)) {
                smart_insert($pdo, 'acc_ledger_entries', [
                    'voucher_id' => $vid,
                    'account_id' => $a_keys[0],
                    'debit' => 5000.00,
                    'credit' => 0.00,
                    'narration' => 'کیش ڈیبٹ ٹرانزیکشن'
                ]);

                smart_insert($pdo, 'acc_ledger_entries', [
                    'voucher_id' => $vid,
                    'account_id' => $a_keys[1 % count($a_keys)],
                    'debit' => 0.00,
                    'credit' => 5000.00,
                    'narration' => 'کریڈٹ ٹرانزیکشن مساوی'
                ]);
            }
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Vouchers & Ledger: " . $e->getMessage(); }

    // ۲۹. انوائسز (Invoices - 5)
    try {
        for ($i = 0; $i < 5; $i++) {
            $inv_no = "INV-2025-" . (100 + $i);
            $sid = !empty($s_keys) ? $s_keys[$i % count($s_keys)] : 1;
            smart_insert($pdo, 'acc_invoices', [
                'invoice_no' => $inv_no,
                'student_id' => $sid,
                'date' => $dates[$i],
                'due_date' => date('Y-m-d', strtotime($dates[$i] . ' + 10 days')),
                'amount' => 2500.00,
                'status' => 'Unpaid',
                'system_type' => 'Madrasa'
            ]);
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Invoices: " . $e->getMessage(); }

    // ۳۰. جنسی عطیات (In-Kind Donations - 5)
    try {
        $in_kinds = [
            ['چاول سپر کرنل 50 کلو کی 2 بوریاں', 2, 24000.00, 'حاجی بشیر احمد', 'Madrasa'],
            ['کوکنگ آئل 16 لیٹر کا ڈبہ', 1, 9500.00, 'ملک صفدر علی', 'Madrasa'],
            ['قرآن پاک مجلد نسخے 20 عدد', 20, 16000.00, 'محمد کاشف صدیقی', 'Madrasa'],
            ['مسجد کے لیے عطر اور صابن کاٹن', 1, 5000.00, 'سید عابد شاہ', 'Masjid'],
            ['طلبہ کے لیے گرم کمبل 10 عدد', 10, 30000.00, 'شیخ طارق محمود', 'Madrasa']
        ];
        foreach ($in_kinds as $ik) {
            smart_insert($pdo, 'in_kind_donations', [
                'item_name' => $ik[0],
                'quantity' => $ik[1],
                'estimated_value' => $ik[2],
                'donor_name' => $ik[3],
                'date' => $today,
                'system_type' => $ik[4]
            ]);
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "In-Kind Donations: " . $e->getMessage(); }

    // ۳۱. مکتب کلاسز، اساتذہ، طلبہ، حاضری و امتحانات (Maktab Domain - 5 each)
    try {
        for ($i = 1; $i <= 5; $i++) {
            if (!find_record_id($pdo, 'maktab_classes', ['name' => "مکتب درجہ " . $i, 'class_name' => "مکتب درجہ " . $i])) {
                smart_insert($pdo, 'maktab_classes', [
                    'name' => "مکتب درجہ " . $i,
                    'class_name' => "مکتب درجہ " . $i,
                    'status' => 'Active'
                ]);
            }

            $m_t_names = ['مولانا بلال مکتبی', 'قاری سعید احمد', 'حافظ عبد المنان', 'قاری فاروق حسن', 'مولانا قاسم علی'];
            if (!find_record_id($pdo, 'maktab_teachers', ['name' => $m_t_names[$i - 1], 'teacher_name' => $m_t_names[$i - 1]])) {
                smart_insert($pdo, 'maktab_teachers', [
                    'name' => $m_t_names[$i - 1],
                    'teacher_name' => $m_t_names[$i - 1],
                    'phone' => '0321765432' . $i,
                    'status' => 'Active'
                ]);
            }

            $m_std_names = [
                ['MKT-01', 'علی رضا', 'محمد رضا', 'مکتب درجہ 1'],
                ['MKT-02', 'حمزہ طارق', 'طارق جمیل', 'مکتب درجہ 2'],
                ['MKT-03', 'سعد بن ابی وقاص', 'وقاص احمد', 'مکتب درجہ 3'],
                ['MKT-04', 'زید بن حارثہ', 'حارث خان', 'مکتب درجہ 4'],
                ['MKT-05', 'انس بن مالک', 'مالک اصغر', 'مکتب درجہ 5']
            ];
            if (!find_record_id($pdo, 'maktab_students', ['admission_number' => $m_std_names[$i - 1][0], 'roll_number' => $m_std_names[$i - 1][0], 'name' => $m_std_names[$i - 1][1]])) {
                smart_insert($pdo, 'maktab_students', [
                    'admission_number' => $m_std_names[$i - 1][0],
                    'name' => $m_std_names[$i - 1][1],
                    'father_name' => $m_std_names[$i - 1][2],
                    'class' => $m_std_names[$i - 1][3],
                    'status' => 'Active',
                    'phone' => '0333555444' . $i
                ]);
            }
        }
        $ms_keys = get_table_ids($pdo, 'maktab_students', 5);

        for ($i = 0; $i < 5; $i++) {
            $msid = !empty($ms_keys) ? $ms_keys[$i % count($ms_keys)] : 1;

            smart_insert($pdo, 'period_attendance', [
                'student_id' => $msid,
                'date' => $dates[$i],
                'period_num' => 1,
                'status' => 'حاضر'
            ]);

            smart_insert($pdo, 'maktab_nazira_record', [
                'student_id' => $msid,
                'date' => $dates[$i],
                'para' => 'پارہ 30',
                'surah' => 'سورۃ الفلق',
                'ayat_from' => '1',
                'ayat_to' => '5',
                'status' => 'عمدہ'
            ]);

            smart_insert($pdo, 'maktab_hifz_record', [
                'student_id' => $msid,
                'date' => $dates[$i],
                'sabaq' => 'سورۃ الفیل',
                'sabqi' => 'سورۃ القریش',
                'manzil' => 'آخری دس سورتیں',
                'status' => 'پاس'
            ]);

            smart_insert($pdo, 'maktab_qaida_records', [
                'student_id' => $msid,
                'date' => $dates[$i],
                'lesson_number' => "تختی نمبر " . ($i + 1),
                'status' => 'جاری'
            ]);

            smart_insert($pdo, 'maktab_academic_reports', [
                'student_id' => $msid,
                'date' => $dates[$i],
                'details' => 'بچہ نماز کے ارکان اور دعائیں شوق سے یاد کر رہا ہے'
            ]);

            smart_insert($pdo, 'daily_maktab', [
                'student_id' => $msid,
                'date' => $dates[$i],
                'morning_sabaq' => 'کلمہ طیبہ و شہادت',
                'morning_qty' => 'یاد کیا',
                'morning_time' => '07:30',
                'morning_status' => 'مکمل',
                'evening_sabaq' => 'وضو کے فرائض',
                'evening_qty' => 'سنایا',
                'evening_time' => '17:30',
                'evening_status' => 'مکمل'
            ]);
        }

        $mex_id = smart_insert($pdo, 'maktab_exams', [
            'name' => 'مکتب سہ ماہی امتحان',
            'exam_date' => $today
        ]);

        if ($mex_id && !empty($ms_keys)) {
            for ($i = 0; $i < 5; $i++) {
                $msid = $ms_keys[$i % count($ms_keys)];
                smart_insert($pdo, 'maktab_exam_results', [
                    'exam_id' => $mex_id,
                    'student_id' => $msid,
                    'obtained_marks' => (85.0 + $i * 2),
                    'total_marks' => 100.00
                ]);
            }
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Maktab Domain: " . $e->getMessage(); }

    // ۳۲. داخلہ انکوائریز (Admission Inquiries - 5)
    try {
        $inquiries = [
            ['عبد الرحمٰن صدیقی', 'محمد صدیقی', '03451112233', 'حفظ القرآن', 'زیر غور'],
            ['محمد زین العابدین', 'عابد حسین', '03451112234', 'ناظرہ قرآن', 'منظور شدہ'],
            ['سعد اللہ خان', 'اللہ بخش', '03451112235', 'درجہ اولیٰ', 'زیر غور'],
            ['محمد ریان اختر', 'اختر رسول', '03451112236', 'قاعدہ ناظرہ', 'منظور شدہ'],
            ['ابوبکر صدیق', 'صدیق اکبر', '03451112237', 'مکتب کلاس', 'مکمل داخلہ']
        ];
        foreach ($inquiries as $inq) {
            smart_insert($pdo, 'admission_inquiries', [
                'student_name' => $inq[0],
                'father_name' => $inq[1],
                'phone' => $inq[2],
                'target_class' => $inq[3],
                'status' => $inq[4]
            ]);
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Inquiries: " . $e->getMessage(); }

    // ۳۳. لائبریری کتب و اجراء (Library - 5)
    try {
        $books = [
            ['صحیح البخاری شریف (مکمل 3 جلد)', 'امام محمد بن اسماعیل بخاری', 'حدیث شریف', 5],
            ['معارف القرآن (8 جلدیں)', 'مفتی محمد شفیع عثمانیؒ', 'تفسیر قرآن', 3],
            ['ریاض الصالحین', 'امام نوویؒ', 'حدیث و اخلاقیات', 10],
            ['ہدایۃ النحو', 'سراج الدین عثمان', 'عربی گرامر', 15],
            ['الفقہ المیسر', 'ڈاکٹر شفیق الرحمٰن', 'فقہ اسلامی', 8]
        ];
        foreach ($books as $bk) {
            $b_id = smart_insert($pdo, 'lib_books', [
                'title' => $bk[0],
                'author' => $bk[1],
                'category' => $bk[2],
                'quantity' => $bk[3]
            ]);

            if ($b_id && !empty($s_keys)) {
                smart_insert($pdo, 'lib_issues', [
                    'book_id' => $b_id,
                    'student_id' => $s_keys[0],
                    'issue_date' => $today,
                    'status' => 'جاری شدہ'
                ]);
            }
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Library: " . $e->getMessage(); }

    // ۳۴. انوینٹری اور سیلز (Inventory & Sales - 5)
    try {
        $inv_items = [
            ['نورانی قاعدہ مجلد', 'کتب', 100, 150.00],
            ['پارہ عم (تیسواں پارہ)', 'کتب', 50, 200.00],
            ['مدرسہ ڈائری و قلم', 'اسٹیشنری', 80, 120.00],
            ['سفید ٹوپی برائے طلبہ', 'لباس', 60, 180.00],
            ['رحل (لکڑی کی)', 'فرنیچر', 30, 450.00]
        ];
        for ($i = 0; $i < count($inv_items); $i++) {
            $inv = $inv_items[$i];
            $item_id = smart_insert($pdo, 'inventory_items', [
                'item_code' => 'ITM-' . (100 + $i + 1),
                'name' => $inv[0],
                'category' => $inv[1],
                'quantity' => $inv[2],
                'current_stock' => $inv[2],
                'price' => $inv[3],
                'unit_price' => $inv[3],
                'system_type' => 'Madrasa'
            ]);

            if ($item_id) {
                smart_insert($pdo, 'inventory_sales', [
                    'sale_no' => 'SALE-' . (500 + $i + 1),
                    'item_id' => $item_id,
                    'item_name' => $inv[0],
                    'quantity' => 1,
                    'unit_price' => $inv[3],
                    'total_amount' => $inv[3],
                    'customer_name' => 'طالب علم نقدی',
                    'date' => $today,
                    'system_type' => 'Madrasa'
                ]);
            }
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Inventory: " . $e->getMessage(); }

    // ۳۵. ہاسٹل کمرے اور الاٹمنٹ (Hostel - 5)
    try {
        for ($r = 1; $r <= 5; $r++) {
            $rno = "کمرہ نمبر " . (200 + $r);
            $room_id = smart_insert($pdo, 'hostel_rooms', [
                'room_number' => $rno,
                'room_name' => $rno,
                'capacity' => 4,
                'capacity_beds' => 4,
                'system_type' => 'Madrasa'
            ]);

            if ($room_id && !empty($s_keys)) {
                $sid = $s_keys[($r - 1) % count($s_keys)];
                smart_insert($pdo, 'hostel_allocations', [
                    'room_id' => $room_id,
                    'student_id' => $sid,
                    'student_name' => 'طالب علم مقیم',
                    'bed_no' => (string)$r,
                    'bed_number' => (string)$r,
                    'allocation_date' => $today,
                    'system_type' => 'Madrasa'
                ]);
            }
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Hostel: " . $e->getMessage(); }

    // ۳۶. ملازمت اشتہارات اور درخواستیں (Jobs & Recruitment - 5)
    try {
        $jobs = [
            ['استاذ حفظ قرآن مجید', 'شعبہ حفظ', 'حفظ مع تجوید اور 3 سال تدریسی تجربہ'],
            ['مدرس عربی ادب و نحو', 'شعبہ درس نظامی', 'شہادت العالمیہ اور کتب کی تدریس پر مہارت'],
            ['قاری تجوید و اتقان', 'شعبہ تجوید', 'شہادت التجوید اور عمدہ لحن'],
            ['معلم مکتب اطفال', 'شعبہ مکتب', 'بچوں کی تربیت کا شوق اور حسن اخلاق'],
            ['اکاؤنٹنٹ و ڈیٹا انٹری آپریٹر', 'شعبہ حسابات', 'بی کام اور کمپیوٹر و ایپ کا تجربہ']
        ];
        foreach ($jobs as $jb) {
            $job_id = smart_insert($pdo, 'job_postings', [
                'title' => $jb[0],
                'department' => $jb[1],
                'requirements' => $jb[2],
                'system_type' => 'Madrasa'
            ]);

            if ($job_id) {
                smart_insert($pdo, 'job_applicants', [
                    'job_id' => $job_id,
                    'posting_id' => $job_id,
                    'applicant_name' => 'حافظ اسامہ سلیم',
                    'name' => 'حافظ اسامہ سلیم',
                    'phone' => '03139876543',
                    'status' => 'موصول شدہ',
                    'system_type' => 'Madrasa'
                ]);
            }
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Jobs: " . $e->getMessage(); }

    // ۳۷. ویب سائٹ صفحات (Web Pages - 5)
    try {
        $pages = [
            ['ہمارے بارے میں', 'about-us', 'مدرسہ اشرف المدارس ایک دینی تعلیمی ادارہ ہے۔', 1],
            ['تعلیمی شعبہ جات', 'departments', 'ادارے میں حفظ، ناظرہ اور درس نظامی کی تعلیم دی جاتی ہے۔', 2],
            ['شرائط داخلہ', 'admissions', 'داخلہ کے لیے تعلیمی اسناد اور فارم جمع کروانا لازمی ہے۔', 3],
            ['دار الاقامہ کے قواعد', 'hostel-rules', 'ہاسٹل میں قیام کے لیے نظم و ضبط کی پابندی لازم ہے۔', 4],
            ['رابطہ اور عطیات', 'contact-us', 'ہم سے رابطہ کے لیے فون نمبر اور پتہ درج ذیل ہے۔', 5]
        ];
        foreach ($pages as $pg) {
            if (!find_record_id($pdo, 'web_pages', ['page_slug' => $pg[1], 'slug' => $pg[1], 'title' => $pg[0]])) {
                smart_insert($pdo, 'web_pages', [
                    'title' => $pg[0],
                    'slug' => $pg[1],
                    'page_slug' => $pg[1],
                    'content' => $pg[2],
                    'content_html' => $pg[2],
                    'meta_description' => $pg[0],
                    'status' => 'Published',
                    'sort_order' => $pg[3],
                    'system_type' => 'Madrasa'
                ]);
            }
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Web Pages: " . $e->getMessage(); }

    // ۳۸. اعلانات و خبریں (Announcements - 5)
    try {
        $news = [
            ['سالانہ تقریب تقسیم انعامات', 'ماہِ رجب میں سالانہ تقریب کا انعقاد کیا جائے گا۔', $today],
            ['نئے تعلیمی سال کا آغاز', 'شوال المکرم سے نئے داخلے باقاعدہ شروع ہوں گے۔', $dates[1]],
            ['حفظ مکمل کرنے پر مبارکباد', 'عزیز طالب علم محمد عثمان کو حفظ مکمل کرنے پر مبارک ہو۔', $dates[2]],
            ['ماہانہ اولیاء کانفرنس', 'آئندہ اتوار کو والدین کے ساتھ تربیتی نشست ہوگی۔', $dates[3]],
            ['مسجد اشرف میں جمعۃ الوداع کی تیاری', 'تمام نمازیوں سے قبل از وقت تشریف لانے کی التماس ہے۔', $dates[4]]
        ];
        foreach ($news as $nw) {
            smart_insert($pdo, 'announcements', [
                'title' => $nw[0],
                'body' => $nw[1],
                'publish_date' => $nw[2],
                'system_type' => 'Madrasa'
            ]);
        }
        $seeded_count++;
    } catch (Throwable $e) { $errors_list[] = "Announcements: " . $e->getMessage(); }

    if (empty($errors_list)) {
        $message = "تمام ماڈیولز میں کامیابی سے 5، 5 ڈیمو ریکارڈز داخل کر دیے گئے ہیں! ماڈیولز 100% مکمل ہیں۔";
    } else {
        $message = "ڈیمو ڈیٹا داخل کر دیا گیا ہے (کامیاب ماڈیولز: $seeded_count)";
        $error = "کچھ انتباہات: " . implode(' | ', $errors_list);
    }
}

// لائیو سنک ڈاؤن ٹیسٹنگ
if (isset($_POST['test_sync_down'])) {
    try {
        $adminUser = $pdo->query("SELECT * FROM admins WHERE role='admin' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$adminUser) {
            $adminUser = ['id' => 1, 'username' => 'admin', 'role' => 'admin', 'permissions' => []];
        } else {
            $adminUser['permissions'] = json_decode($adminUser['permissions'] ?? '[]', true) ?: [];
        }
        $sync_test_result = handle_sync_down($pdo, $adminUser);
    } catch (Throwable $e) {
        $error = "سنک ڈاؤن میں خرابی: " . $e->getMessage();
    }
}

// تمام ٹیبلز میں موجود ریکارڈز کی گنتی
$table_counts = [];
foreach (array_keys($tables_sql) as $t) {
    try {
        $c = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        $table_counts[$t] = $c;
    } catch (Throwable $e) {
        $table_counts[$t] = 'غائب / خطا';
    }
}

?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>جامع ڈیمو ڈیٹا جنریٹر و سنک ویریفیکیشن</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Noto Nastaliq Urdu', Tahoma, Arial, sans-serif; background-color: #f0f4f8; }
        .card { border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.06); margin-bottom: 20px; }
        .table-badge { font-size: 0.95rem; font-weight: bold; border-radius: 8px; padding: 6px 12px; }
        .sync-output { background: #1e1e2e; color: #a6e3a1; font-family: monospace; direction: ltr; text-align: left; padding: 15px; border-radius: 8px; max-height: 400px; overflow-y: auto; }
    </style>
</head>
<body class="p-4">
<div class="container-fluid" style="max-width: 1200px;">
    <div class="card p-4 text-center border-0 bg-primary text-white mb-4">
        <h2 class="mb-2">🚀 جامع ڈیمو ڈیٹا جنریٹر و موبائل سنک ٹیسٹ ٹول</h2>
        <p class="mb-0 fs-5">تمام ماڈیولز (مدرسہ، مسجد، مکتب، فنانس، اساتذہ، طلبہ) میں 5، 5 ریکارڈز کی خودکار انٹری اور تصدیق</p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success fs-5 text-center fw-bold shadow-sm"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger fs-5 text-center fw-bold shadow-sm"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card p-4 border-0">
        <div class="d-flex flex-wrap justify-content-center gap-3 mb-4">
            <form method="POST" class="d-inline">
                <button type="submit" name="seed_all_demo" value="1" class="btn btn-success btn-lg px-4 py-3 shadow">
                    🌱 تمام ماڈیولز میں 5، 5 ڈیمو ریکارڈز داخل کریں (Seed All 5 Entries)
                </button>
            </form>
            <form method="POST" class="d-inline">
                <button type="submit" name="test_sync_down" value="1" class="btn btn-info btn-lg px-4 py-3 text-white shadow">
                    🔄 سرور سنک ڈاؤن ٹیسٹ کریں (Simulate Sync Down)
                </button>
            </form>
            <a href="seed_demo_data.php" class="btn btn-outline-secondary btn-lg px-4 py-3 shadow">
                🔄 پیج ریفریش کریں
            </a>
        </div>

        <?php if ($sync_test_result): ?>
            <div class="card p-3 border-success border-2 bg-light mb-4">
                <h4 class="text-success fw-bold mb-3">✅ سنک ڈاؤن ٹیسٹ 100% کامیاب رہا! (Sync Down Ready)</h4>
                <p class="text-muted">سرور نے موبائل ایپ کے لیے تمام ماڈیولز کا درج ذیل ڈیٹا تیار کر دیا ہے:</p>
                <div class="row row-cols-2 row-cols-md-4 g-2 mb-3">
                    <?php 
                    $sData = $sync_test_result['data'] ?? [];
                    foreach ($sData as $key => $val): 
                        $cnt = is_array($val) ? count($val) : (is_null($val) ? 0 : 1);
                    ?>
                        <div class="col">
                            <div class="p-2 border rounded bg-white text-center shadow-sm">
                                <span class="fw-bold text-dark"><?= htmlspecialchars($key) ?>:</span> 
                                <span class="badge bg-primary fs-6"><?= $cnt ?> ریکارڈز</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <h4 class="fw-bold mb-3">📊 تمام ٹیبلز میں موجودہ ریکارڈز کی تفصیل (کل <?= count($table_counts) ?> ٹیبلز):</h4>
        <div class="row row-cols-1 row-cols-md-3 row-cols-lg-4 g-3">
            <?php foreach ($table_counts as $tbl => $cnt): ?>
                <?php 
                $isOk = is_numeric($cnt) && $cnt >= 5;
                $hasSome = is_numeric($cnt) && $cnt > 0;
                $badgeClass = $isOk ? 'bg-success' : ($hasSome ? 'bg-warning text-dark' : 'bg-danger');
                ?>
                <div class="col">
                    <div class="p-3 border rounded bg-white shadow-sm d-flex justify-content-between align-items-center">
                        <span class="fw-bold text-secondary"><?= htmlspecialchars($tbl) ?></span>
                        <span class="badge <?= $badgeClass ?> table-badge">
                            <?= is_numeric($cnt) ? $cnt . ' انٹریز' : $cnt ?>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
</body>
</html>
