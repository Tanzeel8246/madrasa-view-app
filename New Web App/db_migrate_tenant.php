<?php
require 'db.php';

echo "<h1>Starting Database Migration for SaaS (Multi-Tenancy)</h1>";

try {
    // 1. Create madrasas table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS madrasas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255),
            phone VARCHAR(50),
            address TEXT,
            status ENUM('active', 'inactive') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "✔ `madrasas` table created or already exists.<br>\n";

    // Insert default madrasa if empty
    $stmt = $pdo->query("SELECT COUNT(*) FROM madrasas");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO madrasas (id, code, name, email) VALUES (1, 'DEFAULT001', 'Default Madrasa', 'admin@madrasa.com')");
        echo "✔ Default Madrasa inserted (ID: 1).<br>\n";
    }

    // 2. List of tables to add madrasa_id
    $tablesToAlter = [
        'students',
        'teachers',
        'classes',
        'attendance',
        'hifz_records',
        'nazira_records',
        'expenses',
        'income',
        'fee_records',
        'exam_results',
        'settings',
        'complaints',
        'admins'
    ];

    // Get all tables in the database
    $stmt = $pdo->query("SHOW TABLES");
    $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tablesToAlter as $table) {
        if (in_array($table, $existingTables)) {
            // Check if madrasa_id already exists
            $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'madrasa_id'");
            if ($stmt->rowCount() == 0) {
                // If the table doesn't have an `id` column at the beginning, we'll just put it first
                $stmtId = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'id'");
                $after = $stmtId->rowCount() > 0 ? "AFTER `id`" : "FIRST";
                
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `madrasa_id` INT DEFAULT 1 $after");
                echo "✔ Added `madrasa_id` to `$table`.<br>\n";
                
                // Add foreign key constraint if you want, but for flexibility we can just index it
                $pdo->exec("ALTER TABLE `$table` ADD INDEX(`madrasa_id`)");
                echo "  - Added index on `madrasa_id` for `$table`.<br>\n";
            } else {
                echo "ℹ `$table` already has `madrasa_id`.<br>\n";
            }
        } else {
            echo "⚠ Table `$table` does not exist in this database.<br>\n";
        }
    }

    echo "<h3>Migration Completed Successfully!</h3>\n";

} catch (PDOException $e) {
    echo "<h3 style='color:red;'>Migration Failed: " . $e->getMessage() . "</h3>\n";
}
?>
