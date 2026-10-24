<?php
require_once __DIR__ . '/session_init.php';
if (session_status() === PHP_SESSION_NONE) { 
    madrasa_safe_session_start(); 
}
require_once __DIR__ . '/db.php';

// D6 Fix: Revoke and wipe persistent authentication tokens and cookies
if (function_exists('madrasa_clear_auth_cookies')) {
    madrasa_clear_auth_cookies();
}

$past = time() - 86400 * 30;
$isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
           (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
           (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

if (PHP_VERSION_ID >= 70300) {
    $opt = ['expires' => $past, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax'];
    if ($isHttps) $opt['secure'] = true;
    @setcookie(session_name(), '', $opt);
    @setcookie('madrasa_auth_token', '', $opt);
    $optNonHttp = ['expires' => $past, 'path' => '/', 'httponly' => false, 'samesite' => 'Lax'];
    if ($isHttps) $optNonHttp['secure'] = true;
    @setcookie('last_tenant_code', '', $optNonHttp);
    @setcookie('tenant_id', '', $optNonHttp);
} else {
    @setcookie(session_name(), '', $past, '/', '', $isHttps, true);
    @setcookie('madrasa_auth_token', '', $past, '/', '', $isHttps, true);
    @setcookie('last_tenant_code', '', $past, '/', '', $isHttps, false);
    @setcookie('tenant_id', '', $past, '/', '', $isHttps, false);
}

unset($_COOKIE[session_name()]);
unset($_COOKIE['madrasa_auth_token']);
unset($_COOKIE['last_tenant_code']);

$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_unset();
    session_destroy();
}

header("Location: login.php?logged_out=1");
exit;
