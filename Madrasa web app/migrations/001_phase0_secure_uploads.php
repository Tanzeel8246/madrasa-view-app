<?php
/**
 * ════════════════════════════════════════════════════════════════════
 *  Migration 001: Phase 0 Secure Uploads & Audit (D-01)
 *  Idempotent & Reversible Migration Script
 * ════════════════════════════════════════════════════════════════════
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once dirname(__DIR__) . '/db.php';

// Verify Admin Access
if (!isset($_SESSION['user_id']) || !in_array(strtolower((string)($_SESSION['role'] ?? '')), ['admin', 'superadmin'], true)) {
    if (php_sapi_name() !== 'cli') {
        http_response_code(403);
        die("403 Unauthorized: Migration runner requires admin privilege.");
    }
}

$results = [
    'step' => '001_phase0_secure_uploads',
    'timestamp' => date('Y-m-d H:i:s'),
    'actions' => [],
    'files_moved' => [],
    'errors' => []
];

// 1. Ensure Private Storage Directory Exists with Restrictive .htaccess
$privateExportDir = dirname(__DIR__) . '/storage/exports';
if (!is_dir($privateExportDir)) {
    if (@mkdir($privateExportDir, 0750, true)) {
        $results['actions'][] = "Created directory: storage/exports";
    }
}
$privateHtaccess = $privateExportDir . '/.htaccess';
if (!file_exists($privateHtaccess)) {
    @file_put_contents($privateHtaccess, "Require all denied\nOptions -Indexes -ExecCGI\n");
    $results['actions'][] = "Created storage/exports/.htaccess";
}

// 2. Ensure uploads/.htaccess Exists with Security Rules
$uploadsDir = dirname(__DIR__) . '/uploads';
$uploadsHtaccess = $uploadsDir . '/.htaccess';
if (!file_exists($uploadsHtaccess)) {
    $rules = "# Security Shield (D-01)\n" .
             "<IfModule mod_autoindex.c>\n    Options -Indexes -ExecCGI\n</IfModule>\n" .
             "<FilesMatch \"\.(csv|sql|xlsx|xls|tsv|json|txt|db|sqlite|bak|log|tar|gz|zip|env)$\">\n" .
             "    <IfModule mod_authz_core.c>\n        Require all denied\n    </IfModule>\n" .
             "</FilesMatch>\n";
    @file_put_contents($uploadsHtaccess, $rules);
    $results['actions'][] = "Created uploads/.htaccess";
}

// 3. Scan uploads/ for Leaked Data Files and Move to Private Storage
$sensitiveExts = ['csv', 'sql', 'xlsx', 'xls', 'tsv', 'json', 'txt', 'db', 'sqlite', 'bak'];
if (is_dir($uploadsDir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isFile()) {
            $ext = strtolower($item->getExtension());
            if (in_array($ext, $sensitiveExts, true)) {
                $sourcePath = $item->getPathname();
                $filename = $item->getFilename();
                $destPath = $privateExportDir . '/' . $filename;
                
                // Avoid overwriting if destination exists
                if (file_exists($destPath)) {
                    $destPath = $privateExportDir . '/' . time() . '_' . $filename;
                }

                if (@rename($sourcePath, $destPath)) {
                    $results['files_moved'][] = [
                        'from' => $sourcePath,
                        'to' => $destPath,
                        'size' => filesize($destPath)
                    ];
                }
            }
        }
    }
}

// Output or Return
if (php_sapi_name() === 'cli') {
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
