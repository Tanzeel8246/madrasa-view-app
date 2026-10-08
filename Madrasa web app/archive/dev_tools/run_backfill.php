<?php
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../finance_accounting_bridge.php';
try {
    finance_accounting_backfill($pdo);
    echo "Backfill completed successfully!";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
