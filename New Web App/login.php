<?php
// سیکیورٹی اور ڈیبگنگ
ini_set('display_errors', 0); // اب ایررز چھپا دیں کیونکہ سسٹم اسٹیبل ہو رہا ہے
error_reporting(0);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';

// اگر پہلے سے لاگ ان ہے تو ڈیش بورڈ پر بھیج دیں
if (isset($_SESSION['user_id'])) {
    echo "<script>window.location.href='dashboard.php';</script>";
    exit;
}

$error = '';

if (isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    try {
        // 1. پہلے ایڈمن ٹیبل میں چیک کریں
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && verify_pass($password, $admin['password'])) {
            $_SESSION['user_id'] = $admin['id'];
            $_SESSION['role'] = !empty($admin['role']) ? $admin['role'] : 'admin';
            $_SESSION['name'] = $admin['name'];
            $_SESSION['permissions'] = json_decode($admin['permissions'] ?? '[]', true) ?: [];
            echo "<script>window.location.href='dashboard.php';</script>";
            exit;
        } 
        
        // 2. اگر ایڈمن نہیں ہے، تو اسٹوڈنٹس/پیرنٹس ٹیبل میں چیک کریں
        $stmt = $pdo->prepare("SELECT * FROM students WHERE admission_number = ? AND is_deleted = 0");
        $stmt->execute([$username]);
        $student = $stmt->fetch();

        if ($student && verify_pass($password, $student['password'] ?? '')) {
            $_SESSION['user_id'] = $student['id'];
            $_SESSION['role'] = 'parent';
            $_SESSION['name'] = $student['name'];
            $_SESSION['adm_no'] = $student['admission_number'];
            echo "<script>window.location.href='dashboard.php';</script>";
            exit;
        }

        // اگر دونوں میں نہیں ملا
        $error = "غلط یوزر نیم یا پاسورڈ!";

    } catch(PDOException $e) {
        $error = "لاگ ان میں دشواری پیش آ رہی ہے۔";
    }
}
?>

<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لاگ ان - مدرسہ اشرف المدارس</title>
    
    <script>
        // Immediate theme load logic to prevent FOUC
        (function() {
            const savedTheme = localStorage.getItem('theme') || 'default';
            if (savedTheme !== 'default') {
                document.documentElement.className = 'theme-' + savedTheme;
            }
        })();
    </script>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;700&display=swap" rel="stylesheet">
    
    <?php include __DIR__ . '/theme_styles.php'; ?>

    <style>
        body {
            font-family: 'Noto Nastaliq Urdu', serif;
            background-color: var(--bg) !important;
            color: var(--text-main) !important;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        .login-box {
            background: var(--card-bg) !important;
            color: var(--text-main) !important;
            padding: 40px;
            border-radius: 15px;
            width: 100%;
            max-width: 400px;
            box-shadow: var(--card-shadow) !important;
            border: 1px solid var(--card-border) !important;
        }
    </style>
</head>
<body>
    <div class="login-box text-center">
        <img src="logo.png" alt="Logo" style="width: 80px; height: 80px; border-radius: 50%; border: 2px solid var(--primary); margin-bottom: 15px; background: white; object-fit: contain;">
        <h3 class="mb-4 text-success fw-bold">مدرسہ اشرف المدارس</h3>
        
        <?php if($error): ?>
            <div class="alert alert-danger p-2 small"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="mb-3 text-start">
                <label class="form-label text-success fw-bold">یوزر نیم / داخلہ نمبر</label>
                <input type="text" name="username" class="form-control" required placeholder="یوزر نیم لکھیں...">
            </div>
            <div class="mb-4 text-start">
                <label class="form-label text-success fw-bold">پاسورڈ</label>
                <input type="password" name="password" class="form-control" required placeholder="پاسورڈ لکھیں...">
            </div>
            <button type="submit" name="login" class="btn btn-success w-100 py-2">لاگ ان کریں</button>
        </form>
        
        <div class="mt-4 pt-2 border-top">
            <a href="forgot_password.php" class="text-danger text-decoration-none small d-block mb-2">پاسورڈ بھول گئے؟ (Forgot Password)</a>
            <a href="index.php" class="small text-muted text-decoration-none">← ہوم پیج پر واپس جائیں</a>
        </div>
    </div>
</body>
</html>