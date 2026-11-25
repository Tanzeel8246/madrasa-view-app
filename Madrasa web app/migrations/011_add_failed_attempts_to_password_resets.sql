-- ============================================================================
-- Migration 011: Add failed_attempts, max_attempts to password_resets & login_attempts table
-- Date: 15 November 2026
-- Condition: Idempotent migration for Item 5 (OTP & Login Abuse Protection)
-- ============================================================================

-- 1. Ensure password_resets table exists with phone and columns
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `phone` VARCHAR(50) DEFAULT NULL,
  `user_id` INT DEFAULT NULL,
  `role` VARCHAR(50) DEFAULT 'admin',
  `otp_code` VARCHAR(10) DEFAULT NULL,
  `otp` VARCHAR(10) DEFAULT NULL,
  `token` VARCHAR(255) DEFAULT NULL,
  `expires_at` DATETIME NOT NULL,
  `is_used` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (`phone`),
  INDEX (`user_id`),
  INDEX (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotently add failed_attempts if it does not already exist
SET @dbname = DATABASE();
SET @tablename = "password_resets";
SET @columnname = "failed_attempts";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  "SELECT 1",
  "ALTER TABLE `password_resets` ADD COLUMN `failed_attempts` INT NOT NULL DEFAULT 0 AFTER `is_used`;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Idempotently add max_attempts if it does not already exist
SET @columnname = "max_attempts";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  "SELECT 1",
  "ALTER TABLE `password_resets` ADD COLUMN `max_attempts` INT NOT NULL DEFAULT 3 AFTER `failed_attempts`;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 2. Create login_attempts table for 5-attempt/15-minute brute-force lockout
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ip_address` VARCHAR(50) NOT NULL,
  `username` VARCHAR(100) NOT NULL,
  `attempted_at` DATETIME NOT NULL,
  INDEX idx_ip_user_time (`ip_address`, `username`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
