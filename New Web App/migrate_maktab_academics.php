<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db.php';

$queries = [
    // Qaida Records
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
        status ENUM('Active', 'Inactive') DEFAULT 'Active'
    )",
    
    // Hifz Records
    "CREATE TABLE IF NOT EXISTS maktab_hifz_record (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        student_id INT(11),
        class_name VARCHAR(100),
        teacher_id INT(11),
        date DATE,
        sabaq_p VARCHAR(100),
        sabaq_l VARCHAR(100),
        sabaq_s VARCHAR(50),
        sabaq_time VARCHAR(20),
        sabqi_p VARCHAR(100),
        sabqi_amt VARCHAR(100),
        sabqi_s VARCHAR(50),
        sabqi_listener VARCHAR(100),
        sabqi_time VARCHAR(20),
        sabqi_review_details TEXT,
        manzil_p VARCHAR(100),
        manzil_s VARCHAR(50),
        manzil_listener VARCHAR(100),
        manzil_time VARCHAR(20),
        status ENUM('Active', 'Inactive') DEFAULT 'Active'
    )",
    
    // Nazira Records
    "CREATE TABLE IF NOT EXISTS maktab_nazira_record (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        student_id INT(11),
        class_name VARCHAR(100),
        teacher_id INT(11),
        date DATE,
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
        status ENUM('Active', 'Inactive') DEFAULT 'Active'
    )",
    
    // Academic Reports
    "CREATE TABLE IF NOT EXISTS maktab_academic_reports (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        student_id INT(11),
        class_name VARCHAR(100),
        month VARCHAR(20),
        year VARCHAR(4),
        type ENUM('qaida', 'nazira', 'hifz'),
        attendance_days INT(11),
        total_working_days INT(11),
        sabaq_grade VARCHAR(10),
        sabqi_grade VARCHAR(10),
        manzil_grade VARCHAR(10),
        remarks TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",

    // Maktab Exams
    "CREATE TABLE IF NOT EXISTS maktab_exams (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        exam_name VARCHAR(255) NOT NULL,
        exam_date DATE NOT NULL,
        status ENUM('Active', 'Completed') DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",

    // Maktab Exam Results
    "CREATE TABLE IF NOT EXISTS maktab_exam_results (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        exam_id INT(11) NOT NULL,
        student_id INT(11) NOT NULL,
        class_name VARCHAR(100) NOT NULL,
        teacher_id INT(11),
        subject VARCHAR(100),
        total_marks INT(11),
        obtained_marks INT(11),
        remarks TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
];

foreach ($queries as $index => $query) {
    try {
        $pdo->exec($query);
        echo "Table for query #" . ($index + 1) . " created successfully.\n";
    } catch (PDOException $e) {
        echo "Error creating table for query #" . ($index + 1) . ": " . $e->getMessage() . "\n";
    }
}

echo "All Maktab academic and exam tables migrated successfully.";
?>
