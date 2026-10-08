<?php
require_once 'db.php';

echo "<h3>Madrasa System Database Hardener (Urdu Support)</h3>";

try {
    // Database charset update skipped due to Hostinger permissions. Table-level updates are sufficient.
    // 2. تمام اہم ٹیبلز کی کولیشن تبدیل کریں
    $tables_to_fix = [
        'students', 'teachers', 'daily_hifz', 'attendance', 'finance', 
        'exams', 'exam_results', 'notices', 'complaints', 
        'parent_complaints', 'leave_applications', 'fee_submissions', 'settings'
    ];

    foreach ($tables_to_fix as $table) {
        try {
            $pdo->exec("ALTER TABLE `$table` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            echo "<p style='color:green;'>SUCCESS: Table `$table` converted to utf8mb4</p>";
        } catch (Exception $e) {
            if ($table == 'exams') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS exams (id INT AUTO_INCREMENT PRIMARY KEY, exam_name VARCHAR(255), exam_date DATE, total_marks INT DEFAULT 100, passing_marks INT DEFAULT 33, status VARCHAR(50) DEFAULT 'Announced', is_deleted TINYINT DEFAULT 0)");
                echo "<p style='color:green;'>SUCCESS: Table `exams` created</p>";
            } elseif ($table == 'exam_results') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS exam_results (id INT AUTO_INCREMENT PRIMARY KEY, exam_id INT, student_id INT, marks_obtained REAL, total_marks REAL, grade VARCHAR(10), status VARCHAR(50), remarks TEXT, pukhtagi INT DEFAULT 0, tajweed INT DEFAULT 0, lahja INT DEFAULT 0, is_synced TINYINT DEFAULT 0)");
                echo "<p style='color:green;'>SUCCESS: Table `exam_results` created</p>";
            } else {
                echo "<p style='color:orange;'>NOTICE: Could not fix table `$table` (Maybe it doesn't exist yet)</p>";
            }
        }
    }

    // Create new tables for Phase 2
    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance_teachers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT NOT NULL,
        date DATE NOT NULL,
        status VARCHAR(20) DEFAULT 'P',
        remarks TEXT,
        is_synced TINYINT DEFAULT 0,
        UNIQUE KEY `unique_attendance` (`teacher_id`, `date`)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS departments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        status ENUM('Active', 'Inactive') DEFAULT 'Active'
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "<p style='color:green;'>SUCCESS: Table `departments` created</p>";

    // Run ERP migration (creates acc_chart_of_accounts, acc_vouchers, acc_ledger_entries etc.)
    if (file_exists(__DIR__ . '/erp_migration.php')) {
        try {
            ob_start();
            include_once __DIR__ . '/erp_migration.php';
            $erp_out = ob_get_clean();
            echo "<p style='color:green;'>SUCCESS: ERP Migration: " . strip_tags($erp_out) . "</p>";
        } catch (Exception $e) {
            echo "<p style='color:red;'>ERROR executing ERP Migration: " . $e->getMessage() . "</p>";
        }
    }

    // Create acc_payroll table
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS acc_payroll (
            id INT AUTO_INCREMENT PRIMARY KEY,
            teacher_id INT NOT NULL,
            month_year VARCHAR(20),
            basic_salary DECIMAL(15,2) DEFAULT 0,
            allowances DECIMAL(15,2) DEFAULT 0,
            deductions DECIMAL(15,2) DEFAULT 0,
            net_salary DECIMAL(15,2) GENERATED ALWAYS AS (basic_salary + allowances - deductions) STORED,
            status ENUM('Unpaid', 'Paid', 'Cancelled') DEFAULT 'Unpaid',
            payment_date DATE DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_by INT
        )");
        echo "<p style='color:green;'>SUCCESS: Table `acc_payroll` created/verified</p>";
    } catch (Exception $e) {
        echo "<p style='color:red;'>ERROR creating acc_payroll: " . $e->getMessage() . "</p>";
    }

    // Create acc_invoices table
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS acc_invoices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            invoice_no VARCHAR(50),
            month_year VARCHAR(20),
            amount DECIMAL(15,2),
            status ENUM('Unpaid', 'Paid', 'Cancelled') DEFAULT 'Unpaid',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_by INT
        )");
        echo "<p style='color:green;'>SUCCESS: Table `acc_invoices` created/verified</p>";
    } catch (Exception $e) {
        echo "<p style='color:red;'>ERROR creating acc_invoices: " . $e->getMessage() . "</p>";
    }

    // Phase 4: Class Periods and Teacher Allotment
    $pdo->exec("CREATE TABLE IF NOT EXISTS class_periods (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_name VARCHAR(255) NOT NULL,
        period_num INT NOT NULL,
        subjects JSON,
        teacher_id INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_class_period` (`class_name`, `period_num`)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "<p style='color:green;'>SUCCESS: Table `class_periods` created for Phase 4</p>";

    // Phase 4: Period Attendance
    $pdo->exec("CREATE TABLE IF NOT EXISTS period_attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        date DATE NOT NULL,
        period_num INT NOT NULL,
        status VARCHAR(20) DEFAULT 'P',
        remarks TEXT,
        is_synced TINYINT DEFAULT 0,
        UNIQUE KEY `unique_period_attendance` (`student_id`, `date`, `period_num`)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "<p style='color:green;'>SUCCESS: Table `period_attendance` created for Phase 4</p>";

    // Phase 4: Teacher late minutes
    $pdo->exec("ALTER TABLE attendance_teachers ADD COLUMN IF NOT EXISTS late_minutes INT DEFAULT 0 AFTER status");
    echo "<p style='color:green;'>SUCCESS: Column `late_minutes` added to attendance_teachers</p>";
    // Phase 5: Unified Attendance & Teacher Appointments
    $pdo->exec("ALTER TABLE teachers ADD COLUMN IF NOT EXISTS appointed_times JSON NULL AFTER status");
    // Drop the old one if it existed
    try { $pdo->exec("ALTER TABLE teachers DROP COLUMN appointment_type"); } catch(Exception $e) {}
    echo "<p style='color:green;'>SUCCESS: Column `appointed_times` added to teachers</p>";

    // Phase 5b: Profile UI overhaul
    try { $pdo->exec("ALTER TABLE teachers DROP COLUMN appointed_times"); } catch(Exception $e) {}
    echo "<p style='color:green;'>SUCCESS: Column `appointed_times` dropped from teachers (moved to classes/class_periods)</p>";

    $pdo->exec("ALTER TABLE classes ADD COLUMN IF NOT EXISTS section VARCHAR(50) DEFAULT 'A' AFTER name");
    echo "<p style='color:green;'>SUCCESS: Column `section` added to classes</p>";

    // Phase 5b: Multi-teacher support
    $pdo->exec("ALTER TABLE classes ADD COLUMN IF NOT EXISTS main_teacher_ids JSON NULL AFTER main_teacher_id");
    echo "<p style='color:green;'>SUCCESS: Column `main_teacher_ids` added to classes</p>";

    $pdo->exec("ALTER TABLE class_periods ADD COLUMN IF NOT EXISTS teacher_ids JSON NULL AFTER teacher_id");
    echo "<p style='color:green;'>SUCCESS: Column `teacher_ids` added to class_periods</p>";

    $pdo->exec("CREATE TABLE IF NOT EXISTS class_periods (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        period_no INT NOT NULL,
        books VARCHAR(255),
        teacher_id INT NULL,
        teacher_ids JSON NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_class_period` (`class_id`, `period_no`)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "<p style='color:green;'>SUCCESS: Table `class_periods` ensured for Phase 5</p>";

    // New unified teacher attendance table
    $pdo->exec("CREATE TABLE IF NOT EXISTS teacher_period_attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT NOT NULL,
        class_id INT NOT NULL,
        date DATE NOT NULL,
        period_no INT NOT NULL,
        status VARCHAR(50) DEFAULT 'حاضر',
        remarks TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_teacher_period_attendance` (`teacher_id`, `class_id`, `date`, `period_no`)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "<p style='color:green;'>SUCCESS: Table `teacher_period_attendance` created for Phase 5</p>";

    $pdo->exec("ALTER TABLE teacher_period_attendance ADD COLUMN IF NOT EXISTS late_minutes INT DEFAULT 0 AFTER status");
    echo "<p style='color:green;'>SUCCESS: Column `late_minutes` added to teacher_period_attendance</p>";

    // 3. کالمز کی فکسنگ (اگر مسنگ ہوں یا غلط ڈیٹا ٹائپ ہو)
    $specific_fixes = [
        "ALTER TABLE leave_applications ADD COLUMN IF NOT EXISTS application_photo VARCHAR(255) AFTER reason",
        "ALTER TABLE parent_complaints ADD COLUMN IF NOT EXISTS evidence VARCHAR(255) AFTER description",
        "ALTER TABLE fee_submissions ADD COLUMN IF NOT EXISTS screenshot VARCHAR(255) AFTER submission_date",
        "ALTER TABLE fee_submissions ADD COLUMN IF NOT EXISTS finance_id INT DEFAULT NULL",
        "ALTER TABLE finance ADD COLUMN IF NOT EXISTS user_id INT DEFAULT 1",
        "ALTER TABLE finance ADD COLUMN IF NOT EXISTS user_name VARCHAR(100) DEFAULT NULL",
        "ALTER TABLE daily_hifz ADD COLUMN IF NOT EXISTS sabaq_grade VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci AFTER sabaq_lines",
        "ALTER TABLE daily_hifz MODIFY COLUMN sabaq_status VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "ALTER TABLE daily_hifz MODIFY COLUMN sabqi_status VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "ALTER TABLE daily_hifz MODIFY COLUMN manzil_status VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "ALTER TABLE attendance MODIFY COLUMN late_1 INT NULL DEFAULT 0",
        "ALTER TABLE attendance MODIFY COLUMN late_2 INT NULL DEFAULT 0",
        "ALTER TABLE attendance MODIFY COLUMN late_3 INT NULL DEFAULT 0",
        "ALTER TABLE attendance MODIFY COLUMN late_4 INT NULL DEFAULT 0",
        "ALTER TABLE teachers MODIFY COLUMN salary INT NULL DEFAULT 0",
        "ALTER TABLE teachers ADD COLUMN IF NOT EXISTS house_rent DECIMAL(15,2) DEFAULT 0",
        "ALTER TABLE teachers ADD COLUMN IF NOT EXISTS medical_allowance DECIMAL(15,2) DEFAULT 0",
        "ALTER TABLE teachers ADD COLUMN IF NOT EXISTS travel_allowance DECIMAL(15,2) DEFAULT 0",
        "ALTER TABLE teachers ADD COLUMN IF NOT EXISTS daily_deduction_rate DECIMAL(15,2) DEFAULT 0",
        "ALTER TABLE acc_vouchers ADD COLUMN IF NOT EXISTS department_id INT DEFAULT NULL",
        "ALTER TABLE acc_vouchers ADD COLUMN IF NOT EXISTS is_deleted TINYINT(1) DEFAULT 0",
        "ALTER TABLE acc_ledger_entries ADD COLUMN IF NOT EXISTS department_id INT DEFAULT NULL",
        "ALTER TABLE students MODIFY COLUMN graduation_date DATE NULL",
        "ALTER TABLE exams ADD COLUMN IF NOT EXISTS exam_name VARCHAR(255) AFTER id",
        "ALTER TABLE exams ADD COLUMN IF NOT EXISTS exam_date DATE AFTER exam_name",
        "ALTER TABLE exams ADD COLUMN IF NOT EXISTS total_marks INT DEFAULT 100",
        "ALTER TABLE exams ADD COLUMN IF NOT EXISTS passing_marks INT DEFAULT 33",
        "ALTER TABLE exams ADD COLUMN IF NOT EXISTS status VARCHAR(50) DEFAULT 'Announced'",
        "ALTER TABLE exam_results ADD COLUMN IF NOT EXISTS exam_id INT",
        "ALTER TABLE exam_results ADD COLUMN IF NOT EXISTS student_id INT",
        "ALTER TABLE exam_results ADD COLUMN IF NOT EXISTS marks_obtained REAL",
        "ALTER TABLE exam_results ADD COLUMN IF NOT EXISTS total_marks REAL",
        "ALTER TABLE exam_results ADD COLUMN IF NOT EXISTS subject VARCHAR(100) DEFAULT 'Quran'",
        "ALTER TABLE exam_results ADD COLUMN IF NOT EXISTS grade VARCHAR(10)",
        "ALTER TABLE exam_results ADD COLUMN IF NOT EXISTS status VARCHAR(50)",
        "ALTER TABLE exam_results ADD COLUMN IF NOT EXISTS remarks TEXT",
        "ALTER TABLE exam_results ADD COLUMN IF NOT EXISTS subjects_marks TEXT DEFAULT NULL",
        "ALTER TABLE classes ADD COLUMN IF NOT EXISTS subjects_list TEXT DEFAULT NULL",
        "ALTER TABLE academic_reports ADD COLUMN IF NOT EXISTS is_synced TINYINT DEFAULT 0",
        "ALTER TABLE exam_results ADD COLUMN IF NOT EXISTS pukhtagi INT DEFAULT 0",
        "ALTER TABLE exam_results ADD COLUMN IF NOT EXISTS tajweed INT DEFAULT 0",
        "ALTER TABLE exam_results ADD COLUMN IF NOT EXISTS lahja INT DEFAULT 0",
        "ALTER TABLE acc_chart_of_accounts ADD COLUMN IF NOT EXISTS is_deleted TINYINT DEFAULT 0"
    ];

    foreach ($specific_fixes as $sql) {
        try {
            $pdo->exec($sql);
            echo "<p style='color:green;'>SUCCESS: $sql</p>";
        } catch (Exception $e) {
            echo "<p style='color:red;'>ERROR: " . $e->getMessage() . "</p>";
        }
    }

} catch (Exception $e) {
    echo "<p style='color:red;'>CRITICAL ERROR: " . $e->getMessage() . "</p>";
}

echo "<h4>Schema hardening completed! Please run Sync again on Mobile.</h4>";
?>
