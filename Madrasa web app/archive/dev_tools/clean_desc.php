<?php
require_once __DIR__ . '/../../db.php';
try {
    $rows = $pdo->query("SELECT id, description FROM finance WHERE description LIKE '%[PAYROLL_ID:%'")->fetchAll();
    foreach($rows as $r) {
        $new_desc = preg_replace('/ \s*\[PAYROLL_ID:\d+\]/', '', $r['description']);
        $pdo->prepare("UPDATE finance SET description = ? WHERE id = ?")->execute([$new_desc, $r['id']]);
    }
    echo "Cleaned " . count($rows) . " records\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
