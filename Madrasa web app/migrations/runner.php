<?php
/**
 * One-Time Database Migration Runner
 * محفوظ اور یک طرفہ ڈیٹا بیس مائیگریشن رنر
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    require_once __DIR__ . '/../env_loader.php';
    $apiSecret = function_exists('get_env_val') ? get_env_val('MADRASA_API_SECRET', '') : '';
    $providedKey = $_GET['key'] ?? '';
    $isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    $isSuperAdmin = !empty($_SESSION['is_super_admin']);

    $isAuthorized = $isAdmin || $isSuperAdmin || (!empty($apiSecret) && hash_equals($apiSecret, $providedKey));

    if (!$isAuthorized) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['status' => 'error', 'message' => 'Access Denied: Admin authorization or valid security key required.']);
        exit;
    }
}

require_once __DIR__ . '/../db.php';

// 1. Ensure migration_log table exists
$pdo->exec("
CREATE TABLE IF NOT EXISTS `migration_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `version` VARCHAR(100) NOT NULL UNIQUE,
    `description` VARCHAR(255) NOT NULL,
    `executed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `execution_time_ms` INT DEFAULT 0,
    `status` ENUM('success', 'failed') DEFAULT 'success'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// 2. Fetch applied migrations
$applied = $pdo->query("SELECT version FROM migration_log WHERE status = 'success'")->fetchAll(PDO::FETCH_COLUMN);
$appliedSet = array_flip($applied);

// 3. Scan for migration files
$files = glob(__DIR__ . '/*.sql');
sort($files);

$results = [];

foreach ($files as $filepath) {
    $filename = basename($filepath);
    if (isset($appliedSet[$filename])) {
        $results[] = [
            'file' => $filename,
            'status' => 'SKIPPED',
            'message' => 'Already applied previously'
        ];
        continue;
    }

    $startTime = microtime(true);
    $sqlContent = file_get_contents($filepath);

    // Remove SQL comments
    $lines = explode("\n", $sqlContent);
    $cleanLines = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
            continue;
        }
        $cleanLines[] = $line;
    }
    $cleanSql = implode("\n", $cleanLines);

    // Split statements safely by semicolon
    $statements = array_filter(array_map('trim', explode(';', $cleanSql)));

    try {
        foreach ($statements as $stmt) {
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
        }
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        $elapsedMs = (int)round((microtime(true) - $startTime) * 1000);
        $logStmt = $pdo->prepare("
            INSERT INTO migration_log (version, description, execution_time_ms, status)
            VALUES (?, ?, ?, 'success')
            ON DUPLICATE KEY UPDATE
                description = VALUES(description),
                execution_time_ms = VALUES(execution_time_ms),
                status = 'success',
                executed_at = CURRENT_TIMESTAMP
        ");
        $logStmt->execute([$filename, "Migration from " . $filename, $elapsedMs]);

        $results[] = [
            'file' => $filename,
            'status' => 'SUCCESS',
            'time_ms' => $elapsedMs
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $elapsedMs = (int)round((microtime(true) - $startTime) * 1000);
        $logStmt = $pdo->prepare("
            INSERT INTO migration_log (version, description, execution_time_ms, status)
            VALUES (?, ?, ?, 'failed')
            ON DUPLICATE KEY UPDATE
                description = VALUES(description),
                execution_time_ms = VALUES(execution_time_ms),
                status = 'failed',
                executed_at = CURRENT_TIMESTAMP
        ");
        $logStmt->execute([$filename, "Failed: " . substr($e->getMessage(), 0, 200), $elapsedMs]);

        $results[] = [
            'file' => $filename,
            'status' => 'FAILED',
            'error' => $e->getMessage()
        ];
        break; // Stop on first failure
    }
}

// 4. Output results
if ($isCli) {
    echo "=== Madrasa Management System - Database Migrations ===\n";
    foreach ($results as $r) {
        echo sprintf("[%s] %s %s\n", $r['status'], $r['file'], isset($r['time_ms']) ? "({$r['time_ms']}ms)" : (isset($r['error']) ? ": " . $r['error'] : ''));
    }
} else {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'status' => 'complete',
        'timestamp' => date('Y-m-d H:i:s'),
        'results' => $results
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
