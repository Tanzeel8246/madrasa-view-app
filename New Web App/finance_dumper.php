<?php
require_once __DIR__ . '/db.php';
try {
    $stmt = $pdo->query('SELECT * FROM finance');
    $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
    file_put_contents(__DIR__ . '/debug_log.txt', date('H:i:s') . ' - FINANCE DUMP: ' . json_encode($res) . PHP_EOL, FILE_APPEND);
} catch (Exception $e) {}
