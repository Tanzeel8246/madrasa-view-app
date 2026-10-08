<?php
require 'Madrasa web app/db.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
$result = $pdo->exec("CREATE TABLE IF NOT EXISTS finance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        date DATE NOT NULL,
        type VARCHAR(50) NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        description TEXT DEFAULT NULL,
        head_id INT DEFAULT NULL,
        manual_receipt_no VARCHAR(100) DEFAULT NULL,
        donor_name VARCHAR(255) DEFAULT NULL,
        donor_address TEXT DEFAULT NULL,
        computer_receipt_no INT DEFAULT NULL,
        user_id INT DEFAULT 1,
        user_name VARCHAR(100) DEFAULT NULL,
        is_deleted TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_finance_date (date),
        INDEX idx_finance_user_receipt (user_id, computer_receipt_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$stmt = $pdo->query('SHOW WARNINGS');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt2 = $pdo->query('SHOW TABLES');
print_r($stmt2->fetchAll(PDO::FETCH_COLUMN));
?>
