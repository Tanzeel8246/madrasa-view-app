<?php
if (php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        http_response_code(403);
        die('Forbidden: Admin access required.');
    }
}
/**
 * LIVE SERVER DATABASE DIAGNOSTIC
 * Upload to server and run once to check all issues
 */

$host = 'localhost';
$dbname = 'u349923428_madrasa';
$user = 'u349923428_madrasa';
$pass = 'Abdulrahman*8246';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "=== DB CONNECTION OK ===\n\n";

    // List all tables
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "TABLES (" . count($tables) . "):\n";
    foreach ($tables as $t) {
        $count = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "  $t: $count rows\n";
    }

    echo "\n=== CHECKING KEY ISSUES ===\n";

    // 1. parent_complaints exists?
    echo "\n[parent_complaints] ";
    echo in_array('parent_complaints', $tables) ? "EXISTS" : "MISSING - this causes hifz_report blank!";

    // 2. teacher_period_attendance exists?
    echo "\n[teacher_period_attendance] ";
    echo in_array('teacher_period_attendance', $tables) ? "EXISTS" : "MISSING - this causes attendance blank!";

    // 3. heads table has data?
    if (in_array('heads', $tables)) {
        $hc = $pdo->query("SELECT COUNT(*) FROM heads WHERE is_deleted=0 OR is_deleted IS NULL")->fetchColumn();
        echo "\n[heads] EXISTS - $hc active heads";
        if ($hc == 0) echo " *** EMPTY - finance dropdown will be blank! ***";
    } else {
        echo "\n[heads] MISSING";
    }

    // 4. period_attendance has remarks column?
    $cols = $pdo->query("SHOW COLUMNS FROM period_attendance")->fetchAll(PDO::FETCH_COLUMN);
    echo "\n[period_attendance.remarks] ";
    echo in_array('remarks', $cols) ? "EXISTS" : "MISSING";

    // 5. daily_hifz has time columns?
    if (in_array('daily_hifz', $tables)) {
        $hcols = $pdo->query("SHOW COLUMNS FROM daily_hifz")->fetchAll(PDO::FETCH_COLUMN);
        echo "\n[daily_hifz.sabaq_time] ";
        echo in_array('sabaq_time', $hcols) ? "EXISTS" : "MISSING";
    }

    // 6. acc_chart_of_accounts has is_deleted?
    if (in_array('acc_chart_of_accounts', $tables)) {
        $acols = $pdo->query("SHOW COLUMNS FROM acc_chart_of_accounts")->fetchAll(PDO::FETCH_COLUMN);
        echo "\n[acc_chart_of_accounts.is_deleted] ";
        echo in_array('is_deleted', $acols) ? "EXISTS" : "MISSING - sync will fail!";
    }

    echo "\n\n=== API SECRET CHECK ===\n";
    // Check .env
    $envPath = __DIR__ . '/.env';
    if (file_exists($envPath)) {
        $envContent = file_get_contents($envPath);
        echo ".env file found\n";
        preg_match('/MADRASA_API_SECRET=(.+)/', $envContent, $m);
        echo "API_SECRET: " . (isset($m[1]) ? "SET (" . strlen(trim($m[1])) . " chars)" : "NOT SET") . "\n";
    } else {
        echo ".env file NOT FOUND - this is critical!\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
