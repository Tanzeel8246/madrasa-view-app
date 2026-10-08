-- 003_maktab_and_multitenant_schema.sql
-- مکتب تعلیمی ٹیبلز اور ملٹی ٹیننٹ انڈیکسز

-- 1. Maktab Classes
CREATE TABLE IF NOT EXISTS `maktab_classes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `section` VARCHAR(50) DEFAULT NULL,
    `main_teacher_id` INT DEFAULT NULL,
    `system_type` VARCHAR(50) DEFAULT 'Masjid',
    `tenant_id` INT NOT NULL DEFAULT 1,
    `is_deleted` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_maktab_class_tenant (`tenant_id`, `system_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Maktab Teachers
CREATE TABLE IF NOT EXISTS `maktab_teachers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `father_name` VARCHAR(150) DEFAULT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `designation` VARCHAR(100) DEFAULT 'استاد',
    `salary` DECIMAL(15,2) DEFAULT 0,
    `status` VARCHAR(50) DEFAULT 'Active',
    `system_type` VARCHAR(50) DEFAULT 'Masjid',
    `tenant_id` INT NOT NULL DEFAULT 1,
    `is_deleted` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_maktab_teacher_tenant (`tenant_id`, `system_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Maktab Students
CREATE TABLE IF NOT EXISTS `maktab_students` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `admission_number` VARCHAR(100) DEFAULT NULL,
    `roll_number` VARCHAR(100) DEFAULT NULL,
    `name` VARCHAR(150) NOT NULL,
    `father_name` VARCHAR(150) DEFAULT NULL,
    `class_name` VARCHAR(100) DEFAULT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `status` VARCHAR(50) DEFAULT 'Active',
    `residence_status` VARCHAR(100) DEFAULT '',
    `system_type` VARCHAR(50) DEFAULT 'Masjid',
    `tenant_id` INT NOT NULL DEFAULT 1,
    `is_deleted` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_maktab_student_tenant (`tenant_id`, `system_type`),
    INDEX idx_maktab_student_class (`class_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Maktab Attendance
CREATE TABLE IF NOT EXISTS `maktab_attendance` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `date` DATE NOT NULL,
    `status` VARCHAR(20) DEFAULT 'P',
    `system_type` VARCHAR(50) DEFAULT 'Masjid',
    `tenant_id` INT NOT NULL DEFAULT 1,
    `is_deleted` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_maktab_student_date (`student_id`, `date`),
    INDEX idx_maktab_att_date (`date`),
    INDEX idx_maktab_att_tenant (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Maktab Nazira Record
CREATE TABLE IF NOT EXISTS `maktab_nazira_record` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `date` DATE NOT NULL,
    `para` VARCHAR(50) DEFAULT NULL,
    `lines` INT DEFAULT 0,
    `grade` VARCHAR(50) DEFAULT 'جید',
    `class_name` VARCHAR(100) DEFAULT NULL,
    `system_type` VARCHAR(50) DEFAULT 'Masjid',
    `tenant_id` INT NOT NULL DEFAULT 1,
    `is_deleted` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_maktab_nazira_student (`student_id`, `date`),
    INDEX idx_maktab_nazira_tenant (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Maktab Hifz Record
CREATE TABLE IF NOT EXISTS `maktab_hifz_record` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `date` DATE NOT NULL,
    `sabaq_para` VARCHAR(50) DEFAULT NULL,
    `sabaq_lines` INT DEFAULT 0,
    `sabaq_grade` VARCHAR(50) DEFAULT NULL,
    `sabqi_para` VARCHAR(50) DEFAULT NULL,
    `sabqi_amount` VARCHAR(50) DEFAULT NULL,
    `manzil_para` VARCHAR(50) DEFAULT NULL,
    `manzil_amount` VARCHAR(50) DEFAULT NULL,
    `class_name` VARCHAR(100) DEFAULT NULL,
    `system_type` VARCHAR(50) DEFAULT 'Masjid',
    `tenant_id` INT NOT NULL DEFAULT 1,
    `is_deleted` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_maktab_hifz_student (`student_id`, `date`),
    INDEX idx_maktab_hifz_tenant (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Maktab Qaida Records
CREATE TABLE IF NOT EXISTS `maktab_qaida_records` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `date` DATE NOT NULL,
    `lesson_no` VARCHAR(50) DEFAULT NULL,
    `grade` VARCHAR(50) DEFAULT 'جید',
    `class_name` VARCHAR(100) DEFAULT NULL,
    `system_type` VARCHAR(50) DEFAULT 'Masjid',
    `tenant_id` INT NOT NULL DEFAULT 1,
    `is_deleted` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_maktab_qaida_student (`student_id`, `date`),
    INDEX idx_maktab_qaida_tenant (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Maktab Academic Reports
CREATE TABLE IF NOT EXISTS `maktab_academic_reports` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `date` DATE NOT NULL,
    `class_name` VARCHAR(100) DEFAULT NULL,
    `subject` VARCHAR(150) DEFAULT NULL,
    `grade` VARCHAR(50) DEFAULT NULL,
    `remarks` TEXT DEFAULT NULL,
    `system_type` VARCHAR(50) DEFAULT 'Masjid',
    `tenant_id` INT NOT NULL DEFAULT 1,
    `is_deleted` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_maktab_academic_student (`student_id`, `date`),
    INDEX idx_maktab_academic_tenant (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Maktab Exam Results
CREATE TABLE IF NOT EXISTS `maktab_exam_results` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `exam_id` INT DEFAULT NULL,
    `student_id` INT NOT NULL,
    `marks` DECIMAL(8,2) DEFAULT 0,
    `total_marks` DECIMAL(8,2) DEFAULT 100,
    `grade` VARCHAR(20) DEFAULT NULL,
    `system_type` VARCHAR(50) DEFAULT 'Masjid',
    `tenant_id` INT NOT NULL DEFAULT 1,
    `is_deleted` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_maktab_exam_student (`student_id`),
    INDEX idx_maktab_exam_tenant (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
