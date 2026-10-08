<?php
require __DIR__ . '/../../db.php';
try {
    $stmt = $pdo->query('SHOW COLUMNS FROM finance LIKE "updated_at"');
    if ($stmt->rowCount() > 0) echo "finance HAS updated_at\n";
    else echo "finance MISSING updated_at\n";
    $stmt = $pdo->query('SHOW COLUMNS FROM students LIKE "updated_at"');
    if ($stmt->rowCount() > 0) echo "students HAS updated_at\n";
    else echo "students MISSING updated_at\n";
} catch (Exception $e) {}
?>
