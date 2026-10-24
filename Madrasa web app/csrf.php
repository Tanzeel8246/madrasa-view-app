<?php
/**
 * ════════════════════════════════════════════════════════════════════
 *  Anti-CSRF Protection Module (Enterprise Cryptographic Shield)
 * ════════════════════════════════════════════════════════════════════
 */

if (!function_exists('get_csrf_token')) {
    function get_csrf_token() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (function_exists('madrasa_safe_session_start')) {
                madrasa_safe_session_start();
            } else {
                @session_start();
            }
        }
        if (empty($_SESSION['csrf_token']) || strlen($_SESSION['csrf_token']) < 32) {
            try {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } catch (Throwable $e) {
                $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
            }
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field() {
        $token = htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="csrf_token" value="' . $token . '">';
    }
}

if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token($token = null) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (function_exists('madrasa_safe_session_start')) {
                madrasa_safe_session_start();
            } else {
                @session_start();
            }
        }
        if ($token === null) {
            $token = $_POST['csrf_token'] 
                  ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] 
                  ?? ($_SERVER['HTTP_X_XSRF_TOKEN'] ?? ''));
        }
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if (empty($sessionToken) || empty($token)) {
            return false;
        }
        return hash_equals((string)$sessionToken, (string)$token);
    }
}

if (!function_exists('validate_csrf_or_abort')) {
    function validate_csrf_or_abort() {
        if (!verify_csrf_token()) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            die("<!DOCTYPE html><html dir='rtl' lang='ur'><head><meta charset='utf-8'><title>سیکیورٹی رکاوٹ (CSRF Error)</title><link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css' rel='stylesheet'></head><body class='bg-light d-flex align-items-center justify-content-center min-vh-100 p-3'><div class='card shadow-sm border-0 text-center p-4' style='max-width:480px;border-radius:16px;'><div class='mb-3'><span style='font-size:3rem;'>🛡️</span></div><h4 class='fw-bold text-danger mb-2'>غیر مجاز سیکیورٹی تصدیق (403 Forbidden)</h4><p class='text-muted mb-4'>درخواست کا سیکیورٹی ٹوکن (CSRF Token) غائب یا غلط تھا۔ برائے مہربانی صفحہ ریفریش کر کے دوبارہ کوشش کریں۔</p><a href='javascript:history.back()' class='btn btn-outline-secondary px-4 py-2 fw-bold'>پیچھے واپس جائیں</a></div></body></html>");
        }
    }
}
