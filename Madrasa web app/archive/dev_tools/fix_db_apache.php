<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../../db.php';

try {
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS `teacher_period_attendance` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `teacher_id` int(11) NOT NULL,
      `class_id` int(11) NOT NULL,
      `date` date NOT NULL,
      `period_no` int(11) NOT NULL,
      `status` varchar(50) DEFAULT 'حاضر',
      `late_minutes` int(11) DEFAULT '0',
      `remarks` text DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "teacher_period_attendance checked/created.\n";
    
    try {
        $pdo->exec("ALTER TABLE period_attendance ADD COLUMN remarks TEXT DEFAULT NULL;");
        echo "Added remarks to period_attendance.\n";
    } catch (Exception $e) {
        echo "Column remarks might already exist: " . $e->getMessage() . "\n";
    }
    
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS `parent_complaints` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `student_id` int(11) NOT NULL,
      `date` date NOT NULL,
      `category` varchar(255) DEFAULT NULL,
      `description` text NOT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "parent_complaints checked/created.\n";
    
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS `ai_settings` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `provider` varchar(50) NOT NULL,
      `api_keys` text NOT NULL COMMENT 'JSON array of keys',
      `active_model` varchar(100) DEFAULT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `provider` (`provider`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "ai_settings checked/created.\n";
    
} catch(Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
