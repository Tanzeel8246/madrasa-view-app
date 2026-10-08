<?php
require_once 'db.php';

$maktab_queries = [
    "CREATE TABLE IF NOT EXISTS maktab_students (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100),
        father_name VARCHAR(100),
        phone VARCHAR(20),
        class_name VARCHAR(50),
        address TEXT,
        admission_date VARCHAR(20),
        status VARCHAR(20),
        user_id INT DEFAULT 1,
        user_name VARCHAR(100),
        fee_amount REAL,
        shift VARCHAR(50),
        cnic VARCHAR(50),
        guardian_name VARCHAR(100),
        dob VARCHAR(20),
        is_synced TINYINT DEFAULT 0,
        is_deleted TINYINT DEFAULT 0
    )",
    "CREATE TABLE IF NOT EXISTS maktab_teachers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100),
        phone VARCHAR(20),
        subject VARCHAR(100),
        salary REAL,
        join_date VARCHAR(20),
        class_name VARCHAR(50),
        address TEXT,
        cnic VARCHAR(50),
        status VARCHAR(20),
        user_id INT DEFAULT 1,
        user_name VARCHAR(100),
        is_synced TINYINT DEFAULT 0,
        is_deleted TINYINT DEFAULT 0
    )",
    "CREATE TABLE IF NOT EXISTS maktab_classes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100),
        teacher_name VARCHAR(100),
        fee REAL,
        user_id INT DEFAULT 1,
        user_name VARCHAR(100),
        is_synced TINYINT DEFAULT 0,
        is_deleted TINYINT DEFAULT 0
    )",
    "CREATE TABLE IF NOT EXISTS maktab_attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT,
        date VARCHAR(20),
        status VARCHAR(20),
        delay_minutes INT DEFAULT 0,
        user_id INT DEFAULT 1,
        user_name VARCHAR(100),
        is_synced TINYINT DEFAULT 0,
        is_deleted TINYINT DEFAULT 0
    )",
    "CREATE TABLE IF NOT EXISTS maktab_academics (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT,
        date VARCHAR(20),
        performance TEXT,
        test_score REAL,
        remarks TEXT,
        subject VARCHAR(100),
        current_sabaq TEXT,
        sabaqi TEXT,
        manzil TEXT,
        user_id INT DEFAULT 1,
        user_name VARCHAR(100),
        is_synced TINYINT DEFAULT 0,
        is_deleted TINYINT DEFAULT 0
    )",
    "CREATE TABLE IF NOT EXISTS maktab_teacher_attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT,
        date VARCHAR(20),
        status VARCHAR(20),
        delay_minutes INT DEFAULT 0,
        user_id INT DEFAULT 1,
        user_name VARCHAR(100),
        is_synced TINYINT DEFAULT 0,
        is_deleted TINYINT DEFAULT 0
    )",
    "CREATE TABLE IF NOT EXISTS masjid_acc_chart_of_accounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(20),
        name VARCHAR(100),
        type VARCHAR(50),
        is_synced TINYINT DEFAULT 0,
        is_deleted TINYINT DEFAULT 0
    )",
    "ALTER TABLE masjid_finance ADD COLUMN head_id INT",
    "ALTER TABLE masjid_finance ADD COLUMN head_name VARCHAR(100)",
    "ALTER TABLE masjid_finance ADD COLUMN manual_receipt_no VARCHAR(50)",
    "ALTER TABLE masjid_finance ADD COLUMN computer_receipt_no INT",
    "ALTER TABLE masjid_finance ADD COLUMN user_id INT DEFAULT 1",
    "ALTER TABLE masjid_finance ADD COLUMN user_name VARCHAR(100)",
    "ALTER TABLE masjid_finance ADD COLUMN spender_name VARCHAR(100)"
];

foreach ($maktab_queries as $q) {
    try {
        $pdo->exec($q);
        echo "<p style='color:green;'>SUCCESS: $q</p>";
    } catch (Exception $e) {
        echo "<p style='color:red;'>ERROR or ALREADY EXISTS: " . $e->getMessage() . "</p>";
    }
}
echo "Migration complete.";
?>