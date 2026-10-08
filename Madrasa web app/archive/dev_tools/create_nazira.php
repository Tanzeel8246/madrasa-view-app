<?php
require_once __DIR__ . '/../../db.php';
$pdo->exec("CREATE TABLE IF NOT EXISTS daily_nazira (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    date DATE NOT NULL,
    morning_sabaq VARCHAR(255) NULL,
    morning_status VARCHAR(50) NULL,
    evening_sabaq VARCHAR(255) NULL,
    evening_status VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY std_date (student_id, date)
)");
echo "Done";
