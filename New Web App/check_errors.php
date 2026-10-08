<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$_GET['class'] = 'حفظ';
try {
    include 'hifz_report.php';
} catch (Throwable $e) {
    echo "ERROR CAUGHT: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine();
}
