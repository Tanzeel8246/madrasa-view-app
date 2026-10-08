-- 001_create_migration_log.sql
-- ایجاد جدول لاگ مائیگریشنز برائے استحکام و ٹریکنگ

CREATE TABLE IF NOT EXISTS `migration_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `version` VARCHAR(100) NOT NULL UNIQUE,
    `description` VARCHAR(255) NOT NULL,
    `executed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `execution_time_ms` INT DEFAULT 0,
    `status` ENUM('success', 'failed') DEFAULT 'success'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
