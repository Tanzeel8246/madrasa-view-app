<?php
/**
 * ════════════════════════════════════════════════════════════════════
 *  سوپر ایڈمن روٹ ری ڈائریکٹ برج (Super Admin Root Redirection Bridge)
 * ════════════════════════════════════════════════════════════════════
 *  اگر صارف یا براؤزر براہ راست /super_admin.php وزٹ کرے تو یہ روٹ
 *  بغیر کسی 404 خرابی کے درست سوپر ایڈمن پورٹل پر ری ڈائریکٹ کرتا ہے۔
 */

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
               (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
               (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

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
    @session_start();
}

$dest = (!empty($_SESSION['is_super_admin'])) ? 'super_admin/dashboard.php' : 'super_admin/login.php';

if (!headers_sent()) {
    header("Location: " . $dest);
}
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="0;url=<?php echo htmlspecialchars($dest); ?>">
    <title>منتقلی برائے سوپر ایڈمن پورٹل...</title>
</head>
<body style="font-family:sans-serif;text-align:center;padding:50px;direction:rtl;background:#090e17;color:#fff;">
    <h3>سوپر ایڈمن پورٹل پر منتقل ہو رہے ہیں...</h3>
    <p>اگر صفحہ خودکار ری ڈائریکٹ نہ ہو تو <a href="<?php echo htmlspecialchars($dest); ?>" style="color:#38bdf8;font-weight:bold;">یہاں کلک کریں</a></p>
    <script>
        window.location.replace("<?php echo addslashes($dest); ?>");
    </script>
</body>
</html>
<?php
exit;
