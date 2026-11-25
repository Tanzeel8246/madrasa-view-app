<?php
/**
 * ════════════════════════════════════════════════════════════════════
 *  Global Session Storage & Self-Healing Bootstrap
 *  Solves missing cPanel / LiteSpeed session directory (/var/cpanel/php/sessions/ea-php81)
 * ════════════════════════════════════════════════════════════════════
 */

if (!function_exists('madrasa_resolve_valid_session_path')) {
    function madrasa_resolve_valid_session_path() {
        static $resolvedPath = null;
        if ($resolvedPath !== null) {
            return $resolvedPath;
        }

        $current = @session_save_path();
        if (!empty($current) && @is_dir($current) && @is_writable($current)) {
            $resolvedPath = $current;
            return $resolvedPath;
        }

        // Priority 1: Private sessions folder within web app
        $appDir = __DIR__ . '/sessions';
        if (!@is_dir($appDir)) {
            @mkdir($appDir, 0700, true);
            if (@is_dir($appDir)) {
                @file_put_contents($appDir . '/.htaccess', "Order Deny,Allow\nDeny from all\n");
                @file_put_contents($appDir . '/index.html', "");
            }
        }
        if (@is_dir($appDir) && @is_writable($appDir)) {
            $resolvedPath = $appDir;
            return $resolvedPath;
        }

        // Priority 2: System temporary directory
        $tmp = @sys_get_temp_dir();
        if (!empty($tmp) && @is_dir($tmp) && @is_writable($tmp)) {
            $resolvedPath = $tmp;
            return $resolvedPath;
        }

        // Priority 3: Fallback /tmp on Unix/Linux
        if (@is_dir('/tmp') && @is_writable('/tmp')) {
            $resolvedPath = '/tmp';
            return $resolvedPath;
        }

        // Priority 4: Fallback Windows Temp if on Windows
        if (!empty($_ENV['TEMP']) && @is_dir($_ENV['TEMP']) && @is_writable($_ENV['TEMP'])) {
            $resolvedPath = $_ENV['TEMP'];
            return $resolvedPath;
        }

        return '';
    }
}

if (!function_exists('madrasa_safe_session_start')) {
    function madrasa_safe_session_start() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
                   (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
                   (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

        @ini_set('expose_php', 'off');
        if (!headers_sent()) {
            @header_remove('X-Powered-By');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            if ($isHttps) {
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
            }
            if (PHP_VERSION_ID >= 70300) {
                @session_set_cookie_params([
                    'lifetime' => 86400 * 30,
                    'path'     => '/',
                    'domain'   => '',
                    'secure'   => $isHttps,
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
            } else {
                @session_set_cookie_params(86400 * 30, '/', '', $isHttps, true);
            }
        }

        // 1. First, attempt standard session start without overriding system path
        $started = (@session_start() && session_status() === PHP_SESSION_ACTIVE);

        // 2. Fallback only if default session start failed
        if (!$started) {
            $validPath = madrasa_resolve_valid_session_path();
            if (!empty($validPath) && session_status() === PHP_SESSION_NONE) {
                @ini_set('session.save_path', $validPath);
                @session_save_path($validPath);
                $started = (@session_start() && session_status() === PHP_SESSION_ACTIVE);
            }
        }

        if ($started && isset($_SESSION['user_id'])) {
            $timeout_mins = (int)($_SESSION['session_timeout_minutes'] ?? 60);
            if ($timeout_mins <= 0) $timeout_mins = 60;
            $timeout_sec = $timeout_mins * 60;
            if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity'] > $timeout_sec)) {
                @session_unset();
                @session_destroy();
                if (!headers_sent() && php_sapi_name() !== 'cli') {
                    header("Location: login.php?msg=timeout");
                    exit;
                }
            }
            $_SESSION['last_activity'] = time();
        }

        return (session_status() === PHP_SESSION_ACTIVE);
    }
}
