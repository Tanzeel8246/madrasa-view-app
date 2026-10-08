<?php
require_once __DIR__ . '/db.php';
try {
    $sys = 'Masjid';
    $since = '2020-01-01 00:00:00';
    $filter = "WHERE system_type = '$sys' AND updated_at > '$since'";
    $stmt = $pdo->query("SELECT id, name, system_type FROM students $filter LIMIT 1");
    echo "Students Query OK\n";
} catch (Exception $e) {
    echo "Students Error: " . $e->getMessage() . "\n";
}
try {
    $stmt = $pdo->query("SELECT * FROM finance $filter LIMIT 1");
    echo "Finance Query OK\n";
} catch (Exception $e) {
    echo "Finance Error: " . $e->getMessage() . "\n";
}
