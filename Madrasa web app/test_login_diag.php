<?php
require_once __DIR__ . '/db.php';
header('Content-Type: text/plain; charset=utf-8');

echo "=== TENANTS ===\n";
try {
    $tenants = $pdo->query("SELECT id, code, name, slug, system_type, status FROM tenants ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    print_r($tenants);
} catch (Throwable $e) {
    echo "Error tenants: " . $e->getMessage() . "\n";
}

echo "\n=== ADMINS ===\n";
try {
    $admins = $pdo->query("SELECT id, tenant_id, username, name, email, phone, role, system_type, LEFT(password, 20) as pass_prefix, LENGTH(password) as pass_len FROM admins ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    print_r($admins);
} catch (Throwable $e) {
    echo "Error admins: " . $e->getMessage() . "\n";
}

echo "\n=== ADMINS TABLE SCHEMA ===\n";
try {
    $cols = $pdo->query("SHOW FULL COLUMNS FROM admins")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        echo "{$col['Field']}: {$col['Type']} | Null: {$col['Null']} | Key: {$col['Key']}\n";
    }
} catch (Throwable $e) {
    echo "Error cols: " . $e->getMessage() . "\n";
}

echo "\n=== SESSION CONFIG ===\n";
echo "session.save_path: " . session_save_path() . "\n";
echo "session.cookie_path: " . ini_get('session.cookie_path') . "\n";
echo "session.cookie_domain: " . ini_get('session.cookie_domain') . "\n";
echo "session.cookie_secure: " . ini_get('session.cookie_secure') . "\n";
echo "session.cookie_samesite: " . ini_get('session.cookie_samesite') . "\n";
echo "HTTPS: " . ($_SERVER['HTTPS'] ?? 'none') . "\n";
echo "HTTP_X_FORWARDED_PROTO: " . ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'none') . "\n";
echo "SERVER_PORT: " . ($_SERVER['SERVER_PORT'] ?? 'none') . "\n";
