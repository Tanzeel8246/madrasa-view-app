<?php
$cacheFlag = __DIR__ . '/.masjid_migration_done';
if (file_exists($cacheFlag)) {
    return;
}
require_once 'db.php';

try {
    // Add system_type to exams table if it doesn't exist
    try {
        $pdo->exec("ALTER TABLE exams ADD COLUMN system_type VARCHAR(50) DEFAULT 'Madrasa'");
    } catch(Exception $e) { }

    $pdo->exec("CREATE TABLE IF NOT EXISTS masjid_finance(
        id INT AUTO_INCREMENT PRIMARY KEY,
        date VARCHAR(255),
        type VARCHAR(255),
        amount DOUBLE,
        category VARCHAR(255),
        description TEXT,
        receipt_no VARCHAR(255),
        donor_name VARCHAR(255),
        donor_address VARCHAR(255),
        spender_name VARCHAR(255),
        signature_image TEXT,
        is_synced INT DEFAULT 0,
        is_deleted INT DEFAULT 0
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS masjid_expense_categories(
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255),
        is_synced INT DEFAULT 0,
        is_deleted INT DEFAULT 0
    )");
    
    // Seed initial categories if empty
    $cat_count = $pdo->query("SELECT COUNT(*) FROM masjid_expense_categories")->fetchColumn();
    if ($cat_count == 0) {
        $pdo->exec("INSERT INTO masjid_expense_categories (name) VALUES (''), (''), (''), ('')");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS masjid_staff(
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255),
        role VARCHAR(255),
        phone VARCHAR(255),
        salary DOUBLE,
        is_synced INT DEFAULT 0,
        is_deleted INT DEFAULT 0
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS masjid_nazira_students(
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255),
        father_name VARCHAR(255),
        phone VARCHAR(255),
        admission_date VARCHAR(255),
        status VARCHAR(255),
        is_synced INT DEFAULT 0,
        is_deleted INT DEFAULT 0
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS masjid_assets(
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255),
        category VARCHAR(255),
        quantity INT,
        condition_status VARCHAR(255),
        donor_name VARCHAR(255),
        asset_type VARCHAR(255),
        donor_father VARCHAR(255),
        donor_address VARCHAR(255),
        donor_phone VARCHAR(255),
        is_synced INT DEFAULT 0,
        is_deleted INT DEFAULT 0
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS masjid_nikah(
        id INT AUTO_INCREMENT PRIMARY KEY,
        groom_name VARCHAR(255),
        bride_name VARCHAR(255),
        date VARCHAR(255),
        wali_name VARCHAR(255),
        mahr DOUBLE,
        is_synced INT DEFAULT 0,
        is_deleted INT DEFAULT 0
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS masjid_talaq(
        id INT AUTO_INCREMENT PRIMARY KEY,
        husband_name VARCHAR(255),
        wife_name VARCHAR(255),
        date VARCHAR(255),
        reason TEXT,
        is_synced INT DEFAULT 0,
        is_deleted INT DEFAULT 0
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS masjid_itikaf(
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255),
        phone VARCHAR(255),
        year INT,
        status VARCHAR(255),
        is_synced INT DEFAULT 0,
        is_deleted INT DEFAULT 0
    )");

    try { $pdo->exec("ALTER TABLE masjid_assets ADD COLUMN asset_type VARCHAR(255)"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE masjid_assets ADD COLUMN donor_father VARCHAR(255)"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE masjid_assets ADD COLUMN donor_address VARCHAR(255)"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE masjid_assets ADD COLUMN donor_phone VARCHAR(255)"); } catch (PDOException $e) {}

    @touch($cacheFlag);
} catch (PDOException $e) {
    error_log("Error creating masjid tables: " . $e->getMessage());
}
?>
