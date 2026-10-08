<?php
if (php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        http_response_code(403);
        die('Forbidden: Admin access required.');
    }
}
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/../../db.php';
try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS finance (
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
    echo "Finance table created successfully\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
