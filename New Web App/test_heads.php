<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require 'Madrasa web app/db.php';
try {
    \->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    \->exec("CREATE TABLE IF NOT EXISTS heads (
          id INT AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(255) NOT NULL,
          type VARCHAR(50) NOT NULL,
          is_deleted TINYINT(1) DEFAULT 0,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_heads_type (type)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
      echo "Heads created\n";
} catch (Exception \) {
    echo "Error heads: " . \->getMessage() . "\n";
}
