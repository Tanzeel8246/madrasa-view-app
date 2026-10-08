<?php
/**
 * One-time Database Migration Script (ARCHIVED)
 * مدرسہ و مسجد مینجمنٹ سسٹم - ڈیٹا بیس مائیگریشن
 */

if (php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        http_response_code(403);
        die('Access Denied: Admin privileges required to run database migrations.');
    }
}

require_once __DIR__ . '/../../db.php';

header('Content-Type: text/plain; charset=UTF-8');
echo "=== Starting Madrasa & Maktab Database Migration ===\n\n";

$tables_to_ensure_system_type = [
    'students', 'teachers', 'classes', 'attendance', 'period_attendance', 
    'exams', 'exam_results', 'finance', 'acc_invoices', 'acc_vouchers', 
    'acc_payroll', 'departments', 'complaints', 'in_kind_donations', 
    'masjid_assets', 'academic_reports', 'qaida_records', 'daily_hifz', 
    'daily_nazira', 'daily_qaida', 'maktab_attendance', 'maktab_exams', 'admins',
    'maktab_students', 'maktab_teachers', 'maktab_classes', 'maktab_nazira_record',
    'maktab_hifz_record', 'maktab_qaida_records', 'maktab_academic_reports', 'maktab_exam_results'
];

foreach ($tables_to_ensure_system_type as $tbl) {
    try {
        $defVal = (strpos($tbl, 'maktab') !== false || strpos($tbl, 'masjid') !== false) ? 'Masjid' : 'Madrasa';
        $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN system_type VARCHAR(50) DEFAULT '$defVal'");
        echo "[SUCCESS] Added system_type column to table: $tbl (default: $defVal)\n";
    } catch (PDOException $e) {
        echo "[INFO] Table $tbl system_type column already exists or skipped.\n";
    }
}

$alterations = [
    "ALTER TABLE admins ADD COLUMN role VARCHAR(50) DEFAULT 'admin', ADD COLUMN permissions TEXT" => "admins (role, permissions)",
    "ALTER TABLE admins ADD COLUMN allowed_classes TEXT NULL" => "admins (allowed_classes)",
    "ALTER TABLE students ADD COLUMN residence_status VARCHAR(50) DEFAULT ''" => "students (residence_status)",
    "ALTER TABLE students ADD COLUMN class_name VARCHAR(100) NULL" => "students (class_name)",
    "ALTER TABLE students ADD COLUMN admission_number VARCHAR(100) NULL" => "students (admission_number)",
    "ALTER TABLE classes ADD COLUMN class_type VARCHAR(100) DEFAULT 'Regular'" => "classes (class_type)",
    "ALTER TABLE classes ADD COLUMN is_deleted TINYINT(1) DEFAULT 0" => "classes (is_deleted)",
    "ALTER TABLE classes ADD COLUMN system_type VARCHAR(50) DEFAULT 'Madrasa'" => "classes (system_type)",
    
    "CREATE TABLE IF NOT EXISTS maktab_qaida_records (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        student_id INT(11),
        class_name VARCHAR(100),
        teacher_id INT(11),
        date DATE,
        sabaq_book VARCHAR(255),
        sabaq_page VARCHAR(100),
        sabaq_status VARCHAR(50),
        sabaq_time VARCHAR(20),
        lesson_type VARCHAR(100) DEFAULT 'نورانی قاعدہ',
        hearing_time VARCHAR(20),
        sabaq_1 VARCHAR(100), qty_1 VARCHAR(100), grade_1 VARCHAR(50),
        sabaq_2 VARCHAR(100), qty_2 VARCHAR(100), grade_2 VARCHAR(50),
        sabaq_3 VARCHAR(100), qty_3 VARCHAR(100), grade_3 VARCHAR(50),
        sabaq_4 VARCHAR(100), qty_4 VARCHAR(100), grade_4 VARCHAR(50),
        status ENUM('Active', 'Inactive') DEFAULT 'Active',
        system_type VARCHAR(50) DEFAULT 'Masjid',
        is_deleted TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )" => "maktab_qaida_records table",

    "CREATE TABLE IF NOT EXISTS maktab_hifz_record (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        student_id INT(11),
        class_name VARCHAR(100),
        teacher_id INT(11),
        date DATE,
        sabaq_para VARCHAR(100),
        sabaq_lines VARCHAR(100),
        sabaq_grade VARCHAR(50),
        sabaq_time VARCHAR(20),
        sabqi_para VARCHAR(100),
        sabqi_qty VARCHAR(100),
        sabqi_grade VARCHAR(50),
        sabqi_listener VARCHAR(100),
        sabqi_time VARCHAR(20),
        sabqi_review_details TEXT,
        manzil_total_qty VARCHAR(100),
        manzil_details VARCHAR(255),
        manzil_grade VARCHAR(50),
        manzil_listener VARCHAR(100),
        manzil_time VARCHAR(20),
        sabaq_p VARCHAR(100),
        sabaq_l VARCHAR(100),
        sabaq_s VARCHAR(50),
        sabqi_p VARCHAR(100),
        sabqi_amt VARCHAR(100),
        sabqi_s VARCHAR(50),
        manzil_p VARCHAR(100),
        manzil_s VARCHAR(50),
        status ENUM('Active', 'Inactive') DEFAULT 'Active',
        system_type VARCHAR(50) DEFAULT 'Masjid',
        is_deleted TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )" => "maktab_hifz_record table",

    "CREATE TABLE IF NOT EXISTS maktab_nazira_record (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        student_id INT(11),
        class_name VARCHAR(100),
        teacher_id INT(11),
        date DATE,
        morning_sabaq VARCHAR(255),
        morning_qty VARCHAR(100),
        morning_time VARCHAR(20),
        morning_status VARCHAR(50),
        evening_sabaq VARCHAR(255),
        evening_qty VARCHAR(100),
        evening_time VARCHAR(20),
        evening_status VARCHAR(50),
        sabaq_p VARCHAR(100),
        sabaq_r VARCHAR(100),
        sabaq_s VARCHAR(50),
        sabaq_time VARCHAR(20),
        sabqi_p VARCHAR(100),
        sabqi_r VARCHAR(100),
        sabqi_s VARCHAR(50),
        sabqi_listener VARCHAR(100),
        sabqi_time VARCHAR(20),
        manzil_p VARCHAR(100),
        manzil_r VARCHAR(100),
        manzil_s VARCHAR(50),
        manzil_listener VARCHAR(100),
        manzil_time VARCHAR(20),
        status ENUM('Active', 'Inactive') DEFAULT 'Active',
        system_type VARCHAR(50) DEFAULT 'Masjid',
        is_deleted TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )" => "maktab_nazira_record table",

    "CREATE TABLE IF NOT EXISTS maktab_academic_reports (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        student_id INT(11),
        class_name VARCHAR(100),
        date DATE NULL,
        month VARCHAR(20),
        year VARCHAR(4),
        type ENUM('qaida', 'nazira', 'hifz') NULL,
        subject VARCHAR(100) NULL,
        lesson_details TEXT,
        grade VARCHAR(50),
        attendance_days INT(11),
        total_working_days INT(11),
        sabaq_grade VARCHAR(10),
        sabqi_grade VARCHAR(10),
        manzil_grade VARCHAR(10),
        remarks TEXT,
        system_type VARCHAR(50) DEFAULT 'Masjid',
        is_deleted TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )" => "maktab_academic_reports table",

    "CREATE TABLE IF NOT EXISTS maktab_exam_results (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        exam_id INT(11) NOT NULL,
        student_id INT(11) NOT NULL,
        class_name VARCHAR(100) NOT NULL,
        teacher_id INT(11),
        subject VARCHAR(100),
        total_marks DOUBLE DEFAULT 100,
        obtained_marks DOUBLE DEFAULT 0,
        percentage DOUBLE DEFAULT 0,
        grade VARCHAR(20),
        status VARCHAR(50) DEFAULT 'Pass',
        remarks TEXT,
        system_type VARCHAR(50) DEFAULT 'Masjid',
        is_deleted TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )" => "maktab_exam_results table",
];

foreach ($alterations as $query => $label) {
    try {
        $pdo->exec($query);
        echo "[SUCCESS] Processed: $label\n";
    } catch (PDOException $e) {
        echo "[INFO] $label: " . $e->getMessage() . "\n";
    }
}

echo "\n=== Migration Completed Successfully ===\n";
?>
